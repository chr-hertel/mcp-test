<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * A raw JSON-RPC client that speaks to an MCP endpoint through Symfony's test
 * browser, so the HTTP transport can be exercised without a running web server.
 *
 * The bundle's MCP client is the comfortable way to talk to a server, and the
 * regression runner uses it. This is the uncomfortable way, on purpose: it sends
 * exactly the bytes it is told to, which is what a test of the *transport*
 * needs — a missing header, a stale session id, a notification that must not be
 * answered.
 */
final class JsonRpcBrowser
{
    public const PROTOCOL_VERSION = '2025-11-25';

    private int $id = 0;
    private ?string $sessionId = null;
    private ?int $initializeStatus = null;

    /**
     * @param array<string, string> $extraHeaders
     */
    public function __construct(
        private readonly KernelBrowser $browser,
        private readonly string $endpoint,
        private readonly array $extraHeaders = [],
    ) {
        $this->browser->catchExceptions(false);
    }

    /**
     * Runs the `initialize` / `notifications/initialized` handshake and keeps
     * the session id for every later request.
     *
     * @param array<string, mixed> $capabilities
     *
     * @return array<string, mixed> the initialize result
     */
    public function initialize(array $capabilities = []): array
    {
        $response = $this->request('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => $capabilities,
            'clientInfo' => ['name' => 'mcp-demo-tests', 'version' => '1.0.0'],
        ]);

        $this->sessionId = $this->lastResponse()->headers->get('Mcp-Session-Id');
        $this->initializeStatus = $this->lastResponse()->getStatusCode();

        $this->notify('notifications/initialized');

        return $response['result'] ?? [];
    }

    /**
     * The status code the `initialize` request itself answered with, before the
     * `initialized` notification overwrote the browser's last response.
     */
    public function getInitializeStatus(): ?int
    {
        return $this->initializeStatus;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> the decoded JSON-RPC envelope (result or error)
     */
    public function request(string $method, array $params = []): array
    {
        $payload = ['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method];

        if ([] !== $params) {
            $payload['params'] = $params;
        }

        $this->send($payload);

        return $this->decode();
    }

    /**
     * A notification has no id, and the server must answer 202 with no body.
     *
     * @param array<string, mixed> $params
     */
    public function notify(string $method, array $params = []): Response
    {
        $payload = ['jsonrpc' => '2.0', 'method' => $method];

        if ([] !== $params) {
            $payload['params'] = $params;
        }

        $this->send($payload);

        return $this->lastResponse();
    }

    /**
     * Sends an already-built payload, so a test can send something malformed.
     *
     * @param array<string, mixed>|string $payload
     * @param array<string, string>       $headerOverrides a null value removes a default header
     */
    public function send(array|string $payload, array $headerOverrides = []): Response
    {
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION,
        ];

        if (null !== $this->sessionId) {
            $headers['HTTP_MCP_SESSION_ID'] = $this->sessionId;
        }

        foreach ($this->extraHeaders as $name => $value) {
            $headers[$name] = $value;
        }

        foreach ($headerOverrides as $name => $value) {
            if (null === $value) {
                unset($headers[$name]);
                continue;
            }

            $headers[$name] = $value;
        }

        $this->browser->request(
            'POST',
            $this->endpoint,
            server: $headers,
            content: \is_string($payload) ? $payload : json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        return $this->lastResponse();
    }

    public function lastResponse(): Response
    {
        return $this->browser->getResponse();
    }

    /**
     * The decoded body, whether the server answered with JSON or with an SSE
     * stream carrying a single `message` event.
     *
     * @return array<string, mixed>
     */
    public function decode(): array
    {
        $response = $this->lastResponse();
        $body = $response->getContent();

        if (false === $body || '' === $body) {
            return [];
        }

        if (str_contains((string) $response->headers->get('Content-Type'), 'text/event-stream')) {
            $body = $this->firstSseData($body);
        }

        $decoded = json_decode($body, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * The payload of the first `data:` line of an SSE body.
     */
    private function firstSseData(string $body): string
    {
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                return trim(substr($line, 5));
            }
        }

        return '';
    }
}
