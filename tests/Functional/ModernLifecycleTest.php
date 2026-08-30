<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mcp\Modern\ModernClient;
use App\Tests\Support\McpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Protocol revision 2026-07-28 over Symfony's test browser.
 *
 * The `modern` server is one server object carrying both eras, served by the same
 * `McpController` as every other: the SDK classifies each request and routes it to
 * the leg it belongs to. So the things worth pinning are the ones the revision
 * changed — what a request has to carry, what a server may answer with, and what is
 * simply gone — plus where the shared endpoint shows through.
 *
 * The wire-level round trips against a running server live in
 * {@see \App\Mcp\Regression\ModernRegressionRunner}; these are the ones that
 * need no web server, so CI gets them for free.
 */
final class ModernLifecycleTest extends McpTestCase
{
    private const ENDPOINT = '/mcp/2026';
    private const META = [
        'io.modelcontextprotocol/protocolVersion' => ModernClient::PROTOCOL_VERSION,
        'io.modelcontextprotocol/clientCapabilities' => [],
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->catchExceptions(false);
    }

    public function testDiscoveryReplacesTheHandshake(): void
    {
        $result = $this->rpc('server/discover')['result'];

        $this->assertSame([ModernClient::PROTOCOL_VERSION], $result['supportedVersions']);
        $this->assertSame('symfonycon-2026', $result['_meta']['io.modelcontextprotocol/serverInfo']['name']);

        // MCP Apps is advertised because the server lists `apps:`; nothing in the
        // code asks for it. Tasks (SEP-2663) is not — see docs/patches.md.
        $this->assertArrayHasKey('io.modelcontextprotocol/ui', $result['capabilities']['extensions']);
    }

    public function testNoSessionIsEverMinted(): void
    {
        $this->rpc('tools/list');

        // The header the handshake era uses to carry a session forward.
        $this->assertFalse($this->client->getResponse()->headers->has('Mcp-Session-Id'));
    }

    public function testEveryRequestCarriesItsOwnVersionAndCapabilities(): void
    {
        $envelope = $this->rpc('tools/call', ['name' => 'describe_request', 'arguments' => []]);
        $data = $envelope['result']['structuredContent'];

        $this->assertSame(ModernClient::PROTOCOL_VERSION, $data['protocol_version']);
        $this->assertSame('modern', $data['era']);
        $this->assertTrue($data['client']['declared_capabilities']);
    }

    public function testAMissingProtocolVersionIsRefused(): void
    {
        $envelope = $this->rpc('tools/list', meta: []);

        $this->assertArrayHasKey('error', $envelope);
        $this->assertSame(-32602, $envelope['error']['code']);
    }

    public function testAnUnsupportedVersionIsRefusedWithTheSupportedSet(): void
    {
        // A revision the SDK does not know cannot have been negotiated through a
        // handshake, so it is the modern leg that answers — and -32022 carries what
        // the server does speak, so a client can retry.
        $envelope = $this->rpc('tools/list', meta: [
            'io.modelcontextprotocol/protocolVersion' => '2027-01-01',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ], protocolHeader: '2027-01-01');

        $this->assertArrayHasKey('error', $envelope);
        $this->assertSame(-32022, $envelope['error']['code']);
        $this->assertSame(['2026-07-28'], $envelope['error']['data']['supported']);
    }

    public function testAHandshakeRevisionReachesTheOtherLegOfTheSameEndpoint(): void
    {
        // `protocol_versions: ['2026-07-28']` narrows the modern leg to that one
        // revision; it does not close the handshake leg, which the SDK gives no way
        // to narrow at all. So a client naming a handshake revision is not told the
        // version is unsupported — it is served by the era that owns it, and told it
        // has no session. See docs/patches.md.
        $envelope = $this->rpc('tools/list', meta: [
            'io.modelcontextprotocol/protocolVersion' => '2025-11-25',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ], protocolHeader: '2025-11-25');

        $this->assertArrayHasKey('error', $envelope);
        $this->assertSame(-32600, $envelope['error']['code']);
        $this->assertStringContainsString('session id', $envelope['error']['message']);

        // And the starkest form of it: `initialize` is gone from this revision, but a
        // request that makes no modern claim never reaches the leg it is gone from. The
        // endpoint hands back a handshake-era session on a revision it does not list.
        $this->client->request('POST', self::ENDPOINT, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'handshake-probe', 'version' => '1.0.0'],
            ],
        ], \JSON_THROW_ON_ERROR));

        $handshake = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('2025-06-18', $handshake['result']['protocolVersion']);
    }

    public function testTheRequiredCacheHintsArePresent(): void
    {
        foreach (['server/discover', 'tools/list', 'prompts/list'] as $method) {
            $result = $this->rpc($method)['result'];

            $this->assertArrayHasKey('ttlMs', $result, $method);
            $this->assertArrayHasKey('cacheScope', $result, $method);
        }

        // config/packages/mcp.yaml makes the lists public and long-lived, and
        // leaves resources/read on the private default.
        $this->assertSame('public', $this->rpc('tools/list')['result']['cacheScope']);
        $this->assertSame(3600000, $this->rpc('tools/list')['result']['ttlMs']);

        $read = $this->rpc('resources/read', ['uri' => 'conference://current'])['result'];
        $this->assertSame('private', $read['cacheScope']);
        $this->assertSame(30000, $read['ttlMs']);
    }

    public function testAServerReturnsTheAskInsteadOfSendingIt(): void
    {
        $envelope = $this->rpc('tools/call', [
            'name' => 'submit_proposal',
            'arguments' => [
                'title' => 'A Proposal Over Two Round Trips',
                'abstract' => 'There are no server-initiated requests in this revision, so the ask comes back as the result.',
            ],
        ], capabilities: ['elicitation' => []]);

        $result = $envelope['result'];

        $this->assertSame('input_required', $result['resultType']);
        $this->assertArrayHasKey('speaker', $result['inputRequests']);
        $this->assertSame('elicitation/create', $result['inputRequests']['speaker']['method']);
        $this->assertIsString($result['requestState']);

        // Round two: the same call, carrying the answer and the sealed state.
        $answered = $this->rpc('tools/call', [
            'name' => 'submit_proposal',
            'arguments' => [],
            'inputResponses' => ['speaker' => ['action' => 'accept', 'content' => [
                'name' => 'Round Two', 'email' => 'round-two@example.com', 'consent' => 'accepted',
            ]]],
            'requestState' => $result['requestState'],
        ], capabilities: ['elicitation' => []])['result'];

        $this->assertFalse($answered['isError'] ?? false);
        $this->assertSame('submitted', $answered['structuredContent']['status']);
        // The title came from the sealed state, not from the second call's arguments.
        $this->assertSame('A Proposal Over Two Round Trips', $answered['structuredContent']['proposal']['title']);
        $this->assertSame('Round Two', $answered['structuredContent']['proposal']['speaker_name']);
    }

    public function testAskingAClientThatCannotAnswerIsRefused(): void
    {
        $envelope = $this->rpc('tools/call', [
            'name' => 'submit_proposal',
            'arguments' => [
                'title' => 'A Proposal Nobody Can Answer For',
                'abstract' => 'This client declares no elicitation capability, so the server must refuse to ask rather than ask.',
            ],
        ]);

        $this->assertArrayHasKey('error', $envelope);
        $this->assertSame(-32021, $envelope['error']['code']);
        $this->assertArrayHasKey('requiredCapabilities', $envelope['error']['data']);
    }

    public function testAHeaderThatDisagreesWithTheBodyIsRefused(): void
    {
        $agreeing = $this->rpc(
            'tools/call',
            ['name' => 'search_track', 'arguments' => ['track' => 'ai']],
            headers: ['HTTP_MCP_PARAM_TRACK' => 'ai'],
        );
        $this->assertSame('ai', $agreeing['result']['structuredContent']['track']);

        $disagreeing = $this->rpc(
            'tools/call',
            ['name' => 'search_track', 'arguments' => ['track' => 'ai']],
            headers: ['HTTP_MCP_PARAM_TRACK' => 'devops'],
        );
        $this->assertArrayHasKey('error', $disagreeing);
        $this->assertSame(-32020, $disagreeing['error']['code']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function removedMethodProvider(): iterable
    {
        yield 'initialize' => ['initialize'];
        yield 'ping' => ['ping'];
        yield 'logging/setLevel' => ['logging/setLevel'];
        yield 'resources/subscribe' => ['resources/subscribe'];
    }

    #[DataProvider('removedMethodProvider')]
    public function testTheRemovedMethodsAreGone(string $method): void
    {
        $envelope = $this->rpc($method, ['uri' => 'conference://current']);

        $this->assertArrayHasKey('error', $envelope, $method.' was answered');
        $this->assertSame(-32601, $envelope['error']['code']);
    }

    public function testTheModernEraIsPostOnly(): void
    {
        // The classifier routes every GET and DELETE to the handshake leg without
        // looking further: they are that era's session operations, and the modern
        // era has neither. So what answers here is the handshake leg failing to find
        // a session, not the modern one refusing a method — which is exactly the
        // shape of a shared endpoint, and the reason a modern client only ever POSTs.
        $this->client->request('GET', self::ENDPOINT);
        $this->assertSame(405, $this->client->getResponse()->getStatusCode());

        $this->client->request('DELETE', self::ENDPOINT);
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Mcp-Session-Id', (string) $this->client->getResponse()->getContent());
    }


    /**
     * @param array<string, mixed>  $params
     * @param array<string, mixed>  $capabilities
     * @param array<string, mixed>|null $meta         null uses the standard members; [] omits them
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function rpc(
        string $method,
        array $params = [],
        array $capabilities = [],
        ?array $meta = null,
        array $headers = [],
        string $protocolHeader = ModernClient::PROTOCOL_VERSION,
    ): array {
        $params['_meta'] = $meta ?? [
            ...self::META,
            'io.modelcontextprotocol/clientCapabilities' => $capabilities,
        ];

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => $protocolHeader,
            'HTTP_MCP_METHOD' => $method,
            ...$headers,
        ];

        $named = ['tools/call' => 'name', 'prompts/get' => 'name', 'resources/read' => 'uri'];
        if (isset($named[$method], $params[$named[$method]])) {
            $server['HTTP_MCP_NAME'] = (string) $params[$named[$method]];
        }

        $this->client->request(
            'POST',
            self::ENDPOINT,
            server: $server,
            content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR),
        );

        $body = (string) $this->client->getResponse()->getContent();

        if (str_contains((string) $this->client->getResponse()->headers->get('Content-Type'), 'text/event-stream')) {
            foreach (preg_split('/\R/', $body) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $decoded = json_decode(trim(substr($line, 5)), true);
                    if (\is_array($decoded) && isset($decoded['id'])) {
                        return $decoded;
                    }
                }
            }
        }

        return json_decode($body, true) ?: [];
    }
}
