<?php

declare(strict_types=1);

namespace App\Mcp\Modern;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A deliberately impolite client for protocol revision 2026-07-28.
 *
 * The SDK has a well-behaved one on the branch now, and the demo uses it: `mcp.clients.modern` in config/packages/mcp.yaml sets
 * `protocol_version: '2026-07-28'` and the whole regression suite runs over it
 * unchanged. This is what remains once that is true — the requests a conforming
 * client will not make.
 *
 * A missing protocol version, a header that contradicts the body, a tampered
 * `requestState`, a capability the request never declared: every one of those
 * is something the SDK's client is careful to get right, so a suite driven by it
 * can only ever prove the happy path. This one sends exactly the bytes it is
 * told to, the same argument `tests/Support/JsonRpcBrowser.php` makes for the
 * handshake era. It also carries `subscriptions/listen`, which the SDK's client
 * does not implement.
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
     * Opens a `subscriptions/listen` stream and reads the frames it carries.
     *
     * The revision has no GET stream. A client that wants server-initiated
     * notifications asks for them by name — an allow-list, not a hint — and the
     * server answers by holding *this* response open. The subscription's id is
     * this request's own JSON-RPC id, which is why the caller supplies one
     * rather than the client minting it.
     *
     * `$whileOpen` runs as soon as the response headers are in, so a caller can
     * provoke something to listen to. It travels on a second connection while
     * this one is held, so the server needs a second worker — see
     * docs/deployment.md, which also explains why this reads to the end of the
     * stream rather than stopping at the frame it wanted: under a SAPI that
     * buffers, no frame arrives until the stream closes, so waiting for the
     * acknowledgment before provoking would wait forever.
     *
     * @param array<string, mixed>    $notifications the filter, as `params.notifications`
     * @param (callable(): void)|null $whileOpen     provokes a notification, once the stream is open
     *
     * @return list<array<string, mixed>> every frame the stream carried, oldest first
     */
    public function listen(string $subscriptionId, array $notifications, ?callable $whileOpen = null, float $readFor = 20.0): array
    {
        $response = $this->http->request('POST', $this->endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => 'subscriptions/listen',
                ...$this->headers,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => $subscriptionId,
                'method' => 'subscriptions/listen',
                'params' => [
                    'notifications' => (object) $notifications,
                    '_meta' => [
                        self::META_VERSION => self::PROTOCOL_VERSION,
                        self::META_CAPABILITIES => (object) $this->capabilities,
                        self::META_CLIENT_INFO => ['name' => 'mcp-demo-modern', 'version' => '1.0.0'],
                    ],
                ],
            ],
            'timeout' => $readFor,
        ]);

        $frames = [];
        $buffer = '';
        $provoked = false;

        try {
            // No per-chunk timeout: given one, the client raises the idle
            // timeout as an exception rather than yielding a timeout chunk, and
            // a stream that is quiet by design ends before it has said anything.
            // The request's own timeout is what bounds this.
            foreach ($this->http->stream($response) as $chunk) {
                // The headers are in, so the server is holding the stream open:
                // whatever is provoked now happens after it took its place on
                // the notification bus, and therefore reaches it.
                if (!$provoked && null !== $whileOpen) {
                    $provoked = true;
                    $whileOpen();
                }

                $buffer .= $chunk->getContent();

                while (false !== $break = strpos($buffer, "\n")) {
                    $line = rtrim(substr($buffer, 0, $break), "\r");
                    $buffer = substr($buffer, $break + 1);

                    // Anything else is a keep-alive comment or the blank line
                    // between frames.
                    if (!str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $decoded = json_decode(trim(substr($line, 5)), true);
                    if (\is_array($decoded)) {
                        $frames[] = $decoded;
                    }
                }

                if ($chunk->isLast()) {
                    break;
                }
            }
        } catch (TransportExceptionInterface) {
            // A server that never closes the stream ends up here, after
            // `$readFor`; the frames already read are the answer.
        } finally {
            // Otherwise the server holds it for the configured lifetime, and a
            // worker with it.
            $response->cancel();
        }

        return $frames;
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
