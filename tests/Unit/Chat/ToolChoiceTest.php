<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Platform\ToolChoice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * The rules the scripted model picks a tool by.
 *
 * {@see \App\Tests\Functional\ChatTurnTest} covers the same code against the real
 * servers, which is what proves the choice is usable; this covers the edges that
 * a live server makes expensive to reach — a tool whose required argument is not
 * in the sentence, two tools that are equally about the subject, and a sentence
 * that is about nothing the servers offer.
 *
 * The tools here are written as an MCP server would publish them, because that
 * is all the chooser ever sees: a name, a description and a JSON schema.
 */
final class ToolChoiceTest extends TestCase
{
    public function testTheToolThatCanTakeTheSubjectWins(): void
    {
        $call = (new ToolChoice())->choose('Which talks are about doctrine?', [
            $this->tool('conference_list_unscheduled_talks', 'List talks that are accepted but not scheduled yet.'),
            $this->tool('conference_search_talks', 'Search the conference programme by free text, track, level, day or speaker.', [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string'], 'limit' => ['type' => 'integer']],
            ]),
        ]);

        self::assertNotNull($call);
        self::assertSame('conference_search_talks', $call->getName());
        self::assertSame(['query' => 'doctrine'], $call->getArguments());
    }

    public function testTheReadOnlyServerWinsWhenTwoConnectionsOfferTheSameTool(): void
    {
        $tools = [
            $this->tool('organizer_search_talks', 'Search the conference programme.', $this->querySchema()),
            $this->tool('conference_search_talks', 'Search the conference programme.', $this->querySchema()),
        ];

        $call = (new ToolChoice())->choose('search the talks for testing', $tools);

        self::assertNotNull($call);
        self::assertSame('conference_search_talks', $call->getName());
    }

    public function testAToolIsSkippedWhenItsRequiredArgumentIsNotInTheSentence(): void
    {
        $choice = new ToolChoice();

        $tools = [
            $this->tool('organizer_schedule_talk', 'Schedule a talk into a room and a time slot.', [
                'type' => 'object',
                'properties' => [
                    'talk' => ['type' => 'string', 'description' => 'the talk slug'],
                    'room' => ['type' => 'string'],
                ],
                'required' => ['talk', 'room'],
            ]),
        ];

        // Nothing here can become a slug, and inventing one would be a protocol
        // error dressed up as an answer.
        self::assertNull($choice->choose('please schedule a talk somewhere nice', $tools));

        $call = $choice->choose('schedule "Studio A" for talk doctrine-without-tears', $tools);

        self::assertNotNull($call);
        self::assertSame(['talk' => 'doctrine-without-tears', 'room' => 'Studio A'], $call->getArguments());
    }

    public function testAnEnumArgumentIsOnlyFilledFromItsOwnValues(): void
    {
        $tool = $this->tool('conference_search_talks', 'Search talks.', [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'track' => ['type' => 'string', 'enum' => ['ai', 'backend', 'frontend']],
            ],
        ]);

        $call = (new ToolChoice())->choose('search the talks in the backend track', [$tool]);

        self::assertNotNull($call);
        self::assertSame('backend', $call->getArguments()['track']);
    }

    public function testADayIsRecognisedByItsFormat(): void
    {
        $tool = $this->tool('conference_get_schedule', 'Get the agenda of one conference day.', [
            'type' => 'object',
            'properties' => ['day' => ['type' => 'string']],
            'required' => ['day'],
        ]);

        $call = (new ToolChoice())->choose('what is the schedule on 2026-11-19?', [$tool]);

        self::assertNotNull($call);
        self::assertSame(['day' => '2026-11-19'], $call->getArguments());
    }

    #[DataProvider('unrelatedProvider')]
    public function testASentenceAboutNothingOnOfferChoosesNothing(string $sentence): void
    {
        $tools = [$this->tool('conference_search_talks', 'Search the conference programme.', $this->querySchema())];

        self::assertNull((new ToolChoice())->choose($sentence, $tools));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unrelatedProvider(): iterable
    {
        yield 'a greeting' => ['hello'];
        yield 'nothing at all' => ['   '];
        yield 'another subject entirely' => ['what is the weather in Berlin'];
    }

    public function testTheSameSentenceAlwaysProducesTheSameCall(): void
    {
        $tools = [$this->tool('conference_search_talks', 'Search the conference programme.', $this->querySchema())];

        $first = (new ToolChoice())->choose('search talks about doctrine', $tools);
        $second = (new ToolChoice())->choose('search talks about doctrine', $tools);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->getId(), $second->getId());
        self::assertSame($first->getArguments(), $second->getArguments());
    }

    /**
     * @return array<string, mixed>
     */
    private function querySchema(): array
    {
        return ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]];
    }

    /**
     * @param array<string, mixed>|null $schema
     */
    private function tool(string $name, string $description, ?array $schema = null): Tool
    {
        return new Tool(new ExecutionReference('toolbox', $name), $name, $description, $schema);
    }
}
