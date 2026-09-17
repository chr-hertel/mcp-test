<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Chat\ChatHost;
use App\Chat\Platform\HostPlatform;
use App\Service\ProgrammeSeeder;
use App\Tests\Support\McpTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Chat\InMemory\Store;
use Symfony\AI\McpBundle\Client\McpClientInterface;

/**
 * One turn of the chat, all the way down.
 *
 * A question goes in; the agent picks a tool the `conference` MCP server
 * advertised, the bundle's client spawns `bin/console mcp:server conference` and
 * calls it, and the answer that comes back carries the actual programme. Six
 * layers — agent, toolbox, MCP client, STDIO transport, MCP server, this
 * application's own tool — and no web server and no API key involved in any of
 * them.
 *
 * That is the point of the scripted platform: without it this path could only be
 * covered by mocking the model, which would leave the tool loop itself untested.
 *
 * The store is the in-memory one rather than the session the web page uses, so
 * the test says nothing about HTTP.
 */
#[Group('regression')]
final class ChatTurnTest extends McpTestCase
{
    public function testAQuestionIsAnsweredFromTheProgrammeServer(): void
    {
        $chat = $this->chat();

        $chat->ask('Which talks are about doctrine?');

        $transcript = $chat->transcript();

        $this->assertSame('user', $transcript[0]['kind']);

        $tools = array_values(array_filter($transcript, static fn (array $entry): bool => 'tool' === $entry['kind']));
        $this->assertCount(1, $tools, 'The turn should have called exactly one tool.');
        $this->assertSame('conference', $tools[0]['server'], 'The read-only server wins over the privileged one.');
        $this->assertSame('search_talks', $tools[0]['tool']);
        $this->assertSame(['query' => 'doctrine'], $tools[0]['arguments']);
        $this->assertStringContainsString('Doctrine Without Tears', $tools[0]['text']);

        $answer = end($transcript);
        $this->assertSame('assistant', $answer['kind']);
        $this->assertStringContainsString('Doctrine Without Tears', $answer['text']);
    }

    public function testAResourceIsAttachedWithoutAskingTheModel(): void
    {
        $chat = $this->chat();

        $chat->attach('conference', 'conference://current');

        $transcript = $chat->transcript();

        $this->assertCount(1, $transcript, 'Attaching a resource is context, not a turn.');
        $this->assertSame('user', $transcript[0]['kind']);
        $this->assertStringContainsString('conference://current', $transcript[0]['text']);
        $this->assertStringContainsString('SymfonyCon', $transcript[0]['text']);
    }

    public function testAServerPromptBecomesTheTurn(): void
    {
        $chat = $this->chat();

        $chat->runPrompt('conference', 'review_schedule_day', ['day' => ProgrammeSeeder::DAY_ONE]);

        $transcript = $chat->transcript();

        // The server rendered the prompt from its own data before the model saw it.
        $this->assertSame('user', $transcript[0]['kind']);
        $this->assertStringContainsString('Doctrine Without Tears', $transcript[1]['text'], 'The agenda is embedded in the prompt, not fetched separately.');
        $this->assertSame('assistant', end($transcript)['kind']);
    }

    public function testAQuestionWithoutAMatchingToolListsWhatTheServersOffer(): void
    {
        $chat = $this->chat();

        $chat->ask('hello');

        $transcript = $chat->transcript();

        $this->assertSame([], array_filter($transcript, static fn (array $entry): bool => 'tool' === $entry['kind']));
        $this->assertStringContainsString('search_talks', end($transcript)['text']);
    }

    private function chat(): ChatHost
    {
        self::bootKernel();
        $container = self::getContainer();

        $agent = $container->get('ai.agent.host');
        \assert($agent instanceof AgentInterface);

        $servers = $container->get('mcp.client.host');
        \assert($servers instanceof McpClientInterface);

        $platform = $container->get(HostPlatform::class);
        \assert($platform instanceof HostPlatform);

        return new ChatHost($agent, new Store(), $servers, $platform, new NullLogger());
    }
}
