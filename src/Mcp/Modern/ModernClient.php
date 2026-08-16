<?php

declare(strict_types=1);

namespace App\Mcp\Modern;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A client for protocol revision 2026-07-28, written because the SDK does not
 * have one yet.
 *
 * `Mcp\Client\Protocol::initialize()` downgrades to the newest handshake
 * revision when it is configured with a modern one, with a warning — so
 * `mcp.clients.<name>.protocol_version: '2026-07-28'` cannot actually be
 * honoured. See docs/patches.md.
 *
 * There is not much to it, which is the point of the revision: no handshake, no
 * session, no server-initiated requests. Every request carries what the server
 * needs to answer it —
 *
 *  - `_meta["io.modelcontextprotocol/protocolVersion"]` and
 *    `_meta["io.modelcontextprotocol/clientCapabilities"]`, both required;
 *  - `MCP-Protocol-Version` and `Mcp-Method` headers mirroring the body, so an
 *    intermediary can route without parsing it;
 *  - `Mcp-Name` on `tools/call`, `prompts/get` and `resources/read`.
 *
 * A header that disagrees with the body is refused with `-32020`, which is worth
 * knowing when a call fails for no apparent reason.
 */
final class ModernClient
{
    public const PROTOCOL_VERSION = '2026-07-28';

    private const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    private const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    private const META_CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';
    private const META_LOG_LEVEL = 'io.modelcontextprotocol/logLevel';

    /**
     * Methods whose `Mcp-Name` header names the thing being addressed, and the
     * `params` member it has to agree with. Getting the list wrong is a -32020,
     * which reads as a transport problem rather than a missing header.
     */
    private const NAMED_METHODS = [
        'tools/call' => 'name',
        'prompts/get' => 'name',
        'resources/read' => 'uri',
        'tasks/get' => 'taskId',
        'tasks/update' => 'taskId',
        'tasks/cancel' => 'taskId',
    ];

    private int $id = 0;

    /**
     * @var list<array<string, mixed>> every notification received on the last request's stream
     */
    private array $notifications = [];

    /**
     * @param array<string, mixed>  $capabilities what this client declares it can do
     * @param array<string, string> $headers      extra headers, e.g. Authorization
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $endpoint,
        private readonly array $capabilities = [],
        private readonly array $headers = [],
        private readonly float $timeout = 30.0,
    ) {
    }

    /**
     * Replaces `initialize`: what the server is, what it speaks, what it offers.
     *
     * @return array<string, mixed>
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * A client declaring nothing, against the same endpoint — for checking that a
     * server reads capabilities from the request rather than from anywhere else.
     */
    public function withoutCapabilities(): self
    {
        return new self($this->http, $this->endpoint, [], $this->headers, $this->timeout);
    }

    public function discover(): array
    {
        return $this->request('server/discover');
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $meta      extra `_meta` for this call — a progressToken, a logLevel, MRTR answers
     *
     * @return array<string, mixed> the JSON-RPC envelope, result or error
     */
    public function callTool(string $name, array $arguments = [], array $meta = [], array $headerParams = []): array
    {
        return $this->request('tools/call', ['name' => $name, 'arguments' => $arguments], $meta, $headerParams);
    }

    /**
     * The second round of a multi-round-trip call: the same request, carrying the
     * answers and the state the server sealed for itself.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $answers   keyed by the ask's key, e.g. ['speaker' => ['action' => 'accept', 'content' => [...]]]
     *
     * @return array<string, mixed>
     */
    public function answerTool(string $name, array $arguments, array $answers, string $requestState): array
    {
        // These sit in `params`, beside `name` and `arguments` — not in `_meta`,
        // which carries what describes the *request* rather than what answers it.
        return $this->request('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
            'inputResponses' => $answers,
            'requestState' => $requestState,
        ]);
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, mixed>  $meta         extra `_meta` members
     * @param array<string, scalar> $headerParams arguments annotated `x-mcp-header`, mirrored into `Mcp-Param-*`
     *
     * @return array<string, mixed>
     */
    public function request(string $method, array $params = [], array $meta = [], array $headerParams = []): array
    {
        $this->notifications = [];

        $params['_meta'] = [
            self::META_VERSION => self::PROTOCOL_VERSION,
            self::META_CAPABILITIES => (object) $this->capabilities,
            self::META_CLIENT_INFO => ['name' => 'mcp-demo-modern', 'version' => '1.0.0'],
            ...$meta,
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            'Mcp-Method' => $method,
            ...$this->headers,
        ];

        if (isset(self::NAMED_METHODS[$method])) {
            $key = self::NAMED_METHODS[$method];
            $headers['Mcp-Name'] = self::encodeHeader((string) ($params[$key] ?? ''));
        }

        // A parameter annotated `x-mcp-header` has to be repeated as a header, and
        // the server checks the two agree.
        foreach ($headerParams as $name => $value) {
            $headers['Mcp-Param-'.$name] = self::encodeHeader((string) $value);
        }

        $response = $this->http->request('POST', $this->endpoint, [
            'headers' => $headers,
            'json' => ['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method, 'params' => $params],
            'timeout' => $this->timeout,
        ]);

        $body = $response->getContent(throw: false);
        $contentType = $response->getHeaders(throw: false)['content-type'][0] ?? '';

        return str_contains($contentType, 'text/event-stream')
            ? $this->readStream($body)
            : (json_decode($body, true) ?: ['error' => ['code' => $response->getStatusCode(), 'message' => substr($body, 0, 300)]]);
    }

    /**
     * Every notification the last request's stream carried, oldest first.
     *
     * Progress and logging travel on the request's own response stream here —
     * there is no separate channel, and both are opt-in: the client asks for
     * progress with `_meta.progressToken` and for logging with
     * `_meta["io.modelcontextprotocol/logLevel"]`.
     *
     * @return list<array<string, mixed>>
     */
    public function getNotifications(): array
    {
        return $this->notifications;
    }

    /**
     * @return array<string, mixed> the `_meta` a request needs to receive progress and logs
     */
    public static function observing(string $progressToken = 'demo-progress', string $logLevel = 'info'): array
    {
        return ['progressToken' => $progressToken, self::META_LOG_LEVEL => $logLevel];
    }

    /**
     * A value the spec allows to be non-ASCII travels Base64-wrapped, because an
     * HTTP field value cannot carry it otherwise.
     */
    public static function encodeHeader(string $value): string
    {
        if (1 === preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }

        return '=?base64?'.base64_encode($value).'?=';
    }

    /**
     * Pulls the answer out of an SSE body, keeping the notifications that preceded it.
     *
     * @return array<string, mixed>
     */
    private function readStream(string $body): array
    {
        $answer = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }

            $decoded = json_decode(trim(substr($line, 5)), true);
            if (!\is_array($decoded)) {
                continue;
            }

            // A message with no id is a notification; the one with an id is the answer.
            if (isset($decoded['id'])) {
                $answer = $decoded;
                continue;
            }

            $this->notifications[] = $decoded;
        }

        return $answer;
    }
}
