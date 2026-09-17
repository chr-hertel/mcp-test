<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Platform\ScriptedPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * The two rounds the stand-in model plays, asserted as the agent sees them.
 *
 * The loop has to terminate: round one asks for a tool, round two — handed the
 * result — must answer without asking for another, or the agent would keep
 * calling tools until `max_tool_calls` stops it. That is the property worth
 * pinning here, and it is invisible from the chat page, where a runaway only
 * looks slow.
 */
final class ScriptedPlatformTest extends TestCase
{
    public function testTheFirstRoundAsksForATool(): void
    {
        $result = $this->invoke(new MessageBag(Message::ofUser('search the talks for doctrine')));

        self::assertInstanceOf(ToolCallResult::class, $result);

        $calls = $result->getContent();
        self::assertCount(1, $calls, 'One tool per turn, always.');
        self::assertSame('conference_search_talks', $calls[0]->getName());
    }

    public function testTheSecondRoundReportsWhatCameBackAndStops(): void
    {
        $call = new ToolCall('call-1', 'conference_search_talks', ['query' => 'doctrine']);

        $messages = new MessageBag(
            Message::ofUser('search the talks for doctrine'),
            Message::ofAssistant($call),
            Message::ofToolCall($call, '{"total":1,"talks":[{"title":"Doctrine Without Tears"}]}'),
        );

        $result = $this->invoke($messages);

        self::assertInstanceOf(TextResult::class, $result);

        $text = $result->getContent();
        self::assertStringContainsString('`search_talks` on the **conference** server', $text);
        self::assertStringContainsString('`query: doctrine`', $text);
        // JSON came back, so JSON is shown — the stand-in summarises nothing away.
        self::assertStringContainsString('Doctrine Without Tears', $text);
        self::assertStringContainsString('```json', $text);
    }

    public function testAQuestionWithoutAMatchOffersTheToolsInstead(): void
    {
        $result = $this->invoke(new MessageBag(Message::ofUser('good morning')));

        self::assertInstanceOf(TextResult::class, $result);
        self::assertStringContainsString('`search_talks`', $result->getContent());
        self::assertStringContainsString('conference', $result->getContent());
    }

    public function testWithoutAnyServerItSaysSoRatherThanAnsweringFromMemory(): void
    {
        $platform = new ScriptedPlatform();

        $result = $platform->invoke(ScriptedPlatform::MODEL, new MessageBag(Message::ofUser('which talks are about doctrine?')))->getResult();

        self::assertInstanceOf(TextResult::class, $result);
        self::assertStringContainsString('No MCP server is answering', $result->getContent());
    }

    private function invoke(MessageBag $messages): object
    {
        $platform = new ScriptedPlatform();

        $tools = [
            new Tool(
                new ExecutionReference('toolbox', 'search_talks'),
                'conference_search_talks',
                'Search the conference programme by free text.',
                ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
            ),
        ];

        return $platform->invoke(ScriptedPlatform::MODEL, $messages, ['tools' => $tools])->getResult();
    }
}
