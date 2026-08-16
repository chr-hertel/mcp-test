<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\JsonRpcBrowser;
use App\Tests\Support\McpTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Streamable HTTP transport, exercised as raw JSON-RPC over Symfony's test
 * browser — no web server, no MCP client, just the bytes.
 *
 * These are the things the SDK's client hides: the handshake and its session
 * id, the `MCP-Protocol-Version` header, the difference between a request and a
 * notification, and what happens when a header is missing or a session is not
 * the one that was minted here.
 */
final class HttpTransportTest extends McpTestCase
{
    public function testTheHandshakeMintsASessionAndAdvertisesTheServer(): void
    {
        $rpc = $this->rpc('/mcp');
        $result = $rpc->initialize();

        $this->assertSame(Response::HTTP_OK, $rpc->getInitializeStatus());
        $this->assertNotNull($rpc->getSessionId(), 'The server minted no Mcp-Session-Id.');

        $this->assertSame('symfonycon-programme', $result['serverInfo']['name']);
        $this->assertSame('1.0.0', $result['serverInfo']['version']);
        $this->assertStringContainsString('SymfonyCon Berlin 2026', $result['instructions']);

        // Capability advertisement follows from the configuration: the conference
        // server exposes tools, prompts, resources and templates.
        $this->assertArrayHasKey('tools', $result['capabilities']);
        $this->assertArrayHasKey('prompts', $result['capabilities']);
        $this->assertArrayHasKey('resources', $result['capabilities']);
        $this->assertArrayHasKey('completions', $result['capabilities']);

        // ...and the MCP Apps extension, because it exposes an #[AsMcpApp] class.
        $this->assertArrayHasKey('io.modelcontextprotocol/ui', $result['capabilities']['extensions'] ?? []);
    }

    public function testANotificationIsAcceptedWithoutAnAnswer(): void
    {
        $rpc = $this->rpc('/mcp');
        $rpc->initialize();

        $response = $rpc->notify('notifications/cancelled', ['requestId' => 99, 'reason' => 'testing']);

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode(), 'A notification must be answered with 202 and no body.');
        $this->assertSame('', trim((string) $response->getContent()));
    }

    public function testToolsListPaginatesAndTheCursorReachesEverything(): void
    {
        $rpc = $this->rpc('/mcp');
        $rpc->initialize();

        $envelope = $rpc->request('tools/list');
        $this->assertArrayNotHasKey('error', $envelope, json_encode($envelope['error'] ?? []));

        // The conference server is configured with pagination_limit: 5 and has
        // more tools than that, so the first page must be short and carry a cursor.
        $this->assertCount(5, $envelope['result']['tools']);
        $this->assertArrayHasKey('nextCursor', $envelope['result']);

        $names = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $rpc->request('tools/list', null === $cursor ? [] : ['cursor' => $cursor]);
            $this->assertArrayNotHasKey('error', $page, json_encode($page['error'] ?? []));

            foreach ($page['result']['tools'] as $tool) {
                $names[] = $tool['name'];
                $this->assertSame('object', $tool['inputSchema']['type'], \sprintf('Tool "%s" has a non-object input schema.', $tool['name']));
            }

            $cursor = $page['result']['nextCursor'] ?? null;
            ++$pages;
        } while (null !== $cursor && $pages < 10);

        $this->assertGreaterThan(1, $pages, 'The cursor was never followed.');
        $this->assertSame($names, array_unique($names), 'A tool came back on two pages.');

        $this->assertContains('search_talks', $names);
        $this->assertContains('browse_schedule', $names);
        // Registered at runtime by App\Mcp\Loader\HouseKeepingLoader.
        $this->assertContains('get_venue_information', $names);
    }

    public function testAnInvalidCursorIsRefused(): void
    {
        $rpc = $this->rpc('/mcp');
        $rpc->initialize();

        $envelope = $rpc->request('tools/list', ['cursor' => 'not-a-cursor']);

        $this->assertArrayHasKey('error', $envelope, 'A made-up cursor was accepted.');
    }

    public function testAToolCallCarriesBothTextAndStructuredContent(): void
    {
        $rpc = $this->rpc('/mcp');
        $rpc->initialize();

        $envelope = $rpc->request('tools/call', ['name' => 'search_talks', 'arguments' => ['query' => 'doctrine']]);
        $result = $envelope['result'] ?? [];

        $this->assertArrayHasKey('structuredContent', $result, 'A tool with an outputSchema must answer with structuredContent.');
        $this->assertSame('doctrine-without-tears', $result['structuredContent']['talks'][0]['slug']);

        // The same payload is repeated as text, for clients that ignore structured content.
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertStringContainsString('doctrine-without-tears', $result['content'][0]['text']);
    }

    public function testAnUnknownToolIsAProtocolErrorAndAFailingToolIsNot(): void
    {
        $rpc = $this->rpc('/mcp');
        $rpc->initialize();

        $unknown = $rpc->request('tools/call', ['name' => 'no_such_tool', 'arguments' => []]);
        $this->assertArrayHasKey('error', $unknown);
        $this->assertSame(-32602, $unknown['error']['code'], 'An unknown tool name is a bad parameter, not an unknown method.');

        $failing = $rpc->request('tools/call', ['name' => 'get_talk', 'arguments' => ['slug' => 'nope']]);
        $this->assertArrayNotHasKey('error', $failing);
        $this->assertTrue($failing['result']['isError'], 'A tool that reports a failure must do so in its result.');
    }

    public function testArgumentsAreValidatedAgainstTheGeneratedSchema(): void
    {
        $rpc = $this->rpc('/mcp');
        $rpc->initialize();

        // "limit" is declared with maximum: 50 through #[Schema].
        $envelope = $rpc->request('tools/call', ['name' => 'search_talks', 'arguments' => ['limit' => 5000]]);

        $this->assertArrayHasKey('error', $envelope);
        $this->assertSame(-32602, $envelope['error']['code']);
        $this->assertStringContainsString('Invalid parameters', $envelope['error']['message']);
    }

    public function testResourcesAndTemplatesAreReadable(): void
    {
        $rpc = $this->rpc('/mcp');
        $rpc->initialize();

        $static = $rpc->request('resources/read', ['uri' => 'conference://current']);
        $payload = json_decode($static['result']['contents'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('SymfonyCon', $payload['name']);

        $templated = $rpc->request('resources/read', ['uri' => 'speaker://nadia-fischer']);
        $speaker = json_decode($templated['result']['contents'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('Nadia Fischer', $speaker['name']);

        $binary = $rpc->request('resources/read', ['uri' => 'conference://badge.png']);
        $this->assertArrayHasKey('blob', $binary['result']['contents'][0]);
        $this->assertStringStartsWith("\x89PNG", base64_decode($binary['result']['contents'][0]['blob'], true));
    }

    public function testCompletionIsServedFromTheDatabase(): void
    {
        $rpc = $this->rpc('/mcp');
        $rpc->initialize();

        $envelope = $rpc->request('completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'promote_talk'],
            'argument' => ['name' => 'slug', 'value' => 'rag'],
        ]);

        $this->assertArrayNotHasKey('error', $envelope, json_encode($envelope['error'] ?? []));
        $this->assertContains('rag-pipelines-in-php', $envelope['result']['completion']['values']);
    }

    public function testTheAppResourceIsServedAsAnMcpApp(): void
    {
        $rpc = $this->rpc('/mcp');
        $rpc->initialize();

        $envelope = $rpc->request('resources/read', ['uri' => 'ui://schedule']);
        $contents = $envelope['result']['contents'][0];

        $this->assertStringContainsString('mcp-app', $contents['mimeType']);
        $this->assertStringContainsString('ui/notifications/initialized', $contents['text']);
    }

    public function testTheAppToolShipsServerRenderedHtml(): void
    {
        $rpc = $this->rpc('/mcp');
        $rpc->initialize();

        $envelope = $rpc->request('tools/call', [
            'name' => 'browse_schedule',
            'arguments' => ['day' => '2026-11-19', 'track' => 'ai'],
        ]);

        $html = $envelope['result']['structuredContent']['html'] ?? '';

        $this->assertStringContainsString('Model Context Protocol in Symfony', $html);
        $this->assertStringContainsString('data-call="open_talk"', $html);
        // The day and track come back as scalars so the shell's form controls refill themselves.
        $this->assertSame('2026-11-19', $envelope['result']['structuredContent']['day']);
        $this->assertSame('ai', $envelope['result']['structuredContent']['track']);
    }

    public function testASessionFromOneServerIsNotAcceptedByAnother(): void
    {
        $browser = static::createClient();
        $browser->catchExceptions(false);

        $conference = new JsonRpcBrowser($browser, '/mcp');
        $conference->initialize();
        $stolen = $conference->getSessionId();
        $this->assertNotNull($stolen);

        // Each server has its own session store, so replaying a session id across
        // the firewall boundary must not work. This is why the bundle refuses a
        // shared store at compile time.
        $diagnostics = new JsonRpcBrowser($browser, '/mcp/diagnostics');
        $diagnostics->setSessionId($stolen);

        $envelope = $diagnostics->request('tools/list');

        $this->assertArrayHasKey('error', $envelope, 'A session minted on another server was accepted.');
    }

    public function testTheOrganizerServerRequiresATokenAndAcceptsTheRightOne(): void
    {
        $browser = static::createClient();
        $browser->catchExceptions(false);

        $anonymous = new JsonRpcBrowser($browser, '/mcp/organizer');
        $anonymous->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $anonymous->lastResponse()->getStatusCode());

        $authenticated = new JsonRpcBrowser($browser, '/mcp/organizer', [
            'HTTP_AUTHORIZATION' => 'Bearer '.$_ENV['MCP_DEMO_ORGANIZER_TOKEN'],
        ]);
        $authenticated->initialize();

        $envelope = $authenticated->request('tools/list');
        $names = array_column($envelope['result']['tools'], 'name');

        $this->assertContains('schedule_talk', $names, 'The organizer server must expose the writing tools.');
    }

    public function testTheConferenceServerDoesNotExposeTheWritingTools(): void
    {
        $rpc = $this->rpc('/mcp');
        $rpc->initialize();

        $names = array_column($rpc->request('tools/list')['result']['tools'], 'name');

        $this->assertNotContains('schedule_talk', $names);
        $this->assertNotContains('submit_proposal', $names);
        $this->assertNotContains('fail_on_purpose', $names);
    }

    private function rpc(string $endpoint): JsonRpcBrowser
    {
        $browser = static::createClient();
        $browser->catchExceptions(false);

        return new JsonRpcBrowser($browser, $endpoint);
    }
}
