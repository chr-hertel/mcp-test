<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\JsonRpcBrowser;
use App\Tests\Support\McpTestCase;

/**
 * What the SDK's schema generator makes of a plain PHP method signature.
 *
 * These assertions are the contract between "how the handler is written" and
 * "what the model sees", which is the part of MCP a developer has the least
 * direct control over — and the part most likely to move underneath an
 * application when the SDK changes.
 */
final class GeneratedSchemaTest extends McpTestCase
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $tools = [];

    protected function setUp(): void
    {
        parent::setUp();

        $browser = static::createClient();
        $browser->catchExceptions(false);

        $rpc = new JsonRpcBrowser($browser, '/mcp/organizer', [
            'HTTP_AUTHORIZATION' => 'Bearer '.$_ENV['MCP_DEMO_ORGANIZER_TOKEN'],
        ]);
        $rpc->initialize();

        foreach ($rpc->request('tools/list')['result']['tools'] as $tool) {
            $this->tools[$tool['name']] = $tool;
        }
    }

    public function testANativeEnumBecomesAJsonSchemaEnum(): void
    {
        $track = $this->tools['search_talks']['inputSchema']['properties']['track'];

        // App\Enum\Track is a backed enum; nothing in the attribute says so. The
        // trailing null comes from the parameter being `?Track $track = null`.
        $this->assertSame(
            ['backend', 'frontend', 'devops', 'architecture', 'community', 'ai', null],
            $track['enum'] ?? $track['anyOf'][0]['enum'] ?? null,
        );

        // A non-nullable enum parameter carries no null case.
        $status = $this->tools['list_proposals']['inputSchema']['properties']['status'];
        $this->assertContains('under_review', $status['enum'] ?? []);
    }

    public function testSchemaAttributeConstraintsReachTheClient(): void
    {
        $properties = $this->tools['search_talks']['inputSchema']['properties'];

        $this->assertSame(1, $properties['limit']['minimum']);
        $this->assertSame(50, $properties['limit']['maximum']);
        $this->assertSame(20, $properties['limit']['default']);

        $this->assertStringContainsString('Free-text search', $properties['query']['description']);
        $this->assertSame(2, $properties['query']['minLength'] ?? $properties['query']['anyOf'][0]['minLength'] ?? null);

        $day = $properties['day'];
        $this->assertSame('^\d{4}-\d{2}-\d{2}$', $day['pattern'] ?? $day['anyOf'][0]['pattern'] ?? null);
    }

    public function testOnlyGenuinelyRequiredParametersAreRequired(): void
    {
        // Every parameter of search_talks has a default, so nothing is required.
        $this->assertSame([], $this->tools['search_talks']['inputSchema']['required'] ?? []);

        // get_talk's $slug has none.
        $this->assertSame(['slug'], $this->tools['get_talk']['inputSchema']['required']);
    }

    public function testTheRequestContextParameterIsNotPartOfTheInputSchema(): void
    {
        // schedule_talk(RequestContext $context, string $slug, ...) — the context
        // is injected by the SDK and must never be asked of the model.
        $properties = $this->tools['schedule_talk']['inputSchema']['properties'];

        $this->assertArrayNotHasKey('context', $properties);
        $this->assertSame(['slug', 'room', 'day'], $this->tools['schedule_talk']['inputSchema']['required']);
    }

    public function testDocBlocksBecomeDescriptionsWhenTheAttributeIsSilent(): void
    {
        // ProgrammeTools::getTalk() has no `description` on its parameters; the
        // @param line of its DocBlock is what the model reads.
        $slug = $this->tools['get_talk']['inputSchema']['properties']['slug'];

        $this->assertStringContainsString('talk slug', $slug['description']);
    }

    public function testToolAnnotationsAndIconsSurvive(): void
    {
        $search = $this->tools['search_talks'];

        $this->assertTrue($search['annotations']['readOnlyHint']);
        $this->assertTrue($search['annotations']['idempotentHint']);
        $this->assertFalse($search['annotations']['openWorldHint']);
        $this->assertSame('image/x-icon', $search['icons'][0]['mimeType']);
        $this->assertSame('Search talks', $search['title']);

        // The destructive one is marked as such, so a host can warn before running it.
        $this->assertTrue($this->tools['unschedule_talk']['annotations']['destructiveHint']);
        $this->assertFalse($this->tools['schedule_talk']['annotations']['destructiveHint']);
    }

    public function testTheDeclaredOutputSchemaIsAdvertised(): void
    {
        $output = $this->tools['search_talks']['outputSchema'];

        $this->assertSame('object', $output['type']);
        $this->assertSame(['query', 'total', 'count', 'talks'], $output['required']);
        $this->assertSame('array', $output['properties']['talks']['type']);
    }
}
