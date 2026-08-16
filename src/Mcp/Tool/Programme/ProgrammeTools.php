<?php

declare(strict_types=1);

namespace App\Mcp\Tool\Programme;

use App\Mcp\Completion\ConferenceDayCompletion;
use App\Mcp\Completion\SpeakerSlugCompletion;
use App\Mcp\Completion\TalkSlugCompletion;
use App\Repository\ConferenceRepository;
use App\Repository\SlotRepository;
use App\Repository\SpeakerRepository;
use App\Repository\TalkRepository;
use App\Service\ProgrammePresenter;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\ToolAnnotations;

/**
 * Several tools on one class — the method-based attribute pattern, as opposed to
 * the invokable single-tool class of {@see TalkSearchTool}.
 *
 * Between them they cover the three shapes a tool result can take:
 *
 * - a plain array, which the SDK serialises as JSON text and, when an output
 *   schema is declared, also as `structuredContent`;
 * - a `Content[]` list, which lets a tool mix prose with `resource_link` and
 *   `resource` blocks so the host can follow up without a second round trip;
 * - a thrown {@see ToolCallException}, which becomes a tool-level error result
 *   (`isError: true`) rather than a JSON-RPC protocol error.
 */
final class ProgrammeTools
{
    public function __construct(
        private readonly TalkRepository $talks,
        private readonly SpeakerRepository $speakers,
        private readonly SlotRepository $slots,
        private readonly ConferenceRepository $conferences,
        private readonly ProgrammePresenter $presenter,
    ) {
    }

    /**
     * Fetch one talk in full, with a link to its speaker's resource.
     *
     * @param string $slug the talk slug, e.g. "messenger-at-scale"
     *
     * @return list<TextContent|ResourceLink|EmbeddedResource>
     */
    #[McpTool(
        name: 'get_talk',
        title: 'Get a talk',
        description: 'Return one talk in full, plus links to its speaker and schedule resources.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function getTalk(
        #[Schema(description: 'The talk slug, e.g. "messenger-at-scale".')]
        #[CompletionProvider(provider: TalkSlugCompletion::class)]
        string $slug,
    ): array {
        $talk = $this->talks->findOneBySlug($slug);

        if (null === $talk) {
            // A ToolCallException is reported to the model as an error *result*, not as a
            // JSON-RPC error: the model can read it and correct its own call.
            throw new ToolCallException(\sprintf('No talk with slug "%s". Use search_talks to find one.', $slug));
        }

        $data = $this->presenter->talk($talk);

        $content = [
            new TextContent(json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)),
            new ResourceLink(
                uri: 'speaker://'.$talk->getSpeaker()->getSlug(),
                name: 'speaker_profile',
                title: $talk->getSpeaker()->getName(),
                description: \sprintf('Full profile for %s.', $talk->getSpeaker()->getName()),
                mimeType: 'application/json',
            ),
        ];

        if (null !== $slot = $talk->getSlot()) {
            // An embedded resource inlines the content instead of linking to it — useful when
            // the caller will certainly want it and a second round trip would be wasteful.
            $content[] = new EmbeddedResource(new TextResourceContents(
                uri: 'schedule://'.$slot->getDay(),
                text: $this->presenter->scheduleAsMarkdown(
                    $this->conferences->current(),
                    $this->slots->findForDay($slot->getDay()),
                ),
                mimeType: 'text/markdown',
            ));
        }

        return $content;
    }

    /**
     * Fetch one speaker and everything they present.
     *
     * @param string $slug the speaker slug, e.g. "nadia-fischer"
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_speaker',
        title: 'Get a speaker',
        description: 'Return one speaker profile with the talks they are giving.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function getSpeaker(
        #[CompletionProvider(provider: SpeakerSlugCompletion::class)]
        string $slug,
    ): array {
        $speaker = $this->speakers->findOneBySlug($slug);

        if (null === $speaker) {
            throw new ToolCallException(\sprintf('No speaker with slug "%s".', $slug));
        }

        return $this->presenter->speaker($speaker);
    }

    /**
     * The programme for one day, as a Markdown agenda.
     *
     * @param string $day a conference day in YYYY-MM-DD form
     *
     * @return array{day: string, slots: int, agenda: string}
     */
    #[McpTool(
        name: 'get_schedule',
        title: 'Get the schedule for a day',
        description: 'Return the agenda of a single conference day as Markdown.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function getSchedule(
        #[Schema(description: 'A conference day in YYYY-MM-DD form.', pattern: '^\d{4}-\d{2}-\d{2}$')]
        #[CompletionProvider(provider: ConferenceDayCompletion::class)]
        string $day,
    ): array {
        $conference = $this->conferences->current();

        if (!\in_array($day, $conference->getDays(), true)) {
            throw new ToolCallException(\sprintf(
                'The conference does not run on %s. Its days are: %s.',
                $day,
                implode(', ', $conference->getDays()),
            ));
        }

        $slots = $this->slots->findForDay($day);

        return [
            'day' => $day,
            'slots' => \count($slots),
            'agenda' => $this->presenter->scheduleAsMarkdown($conference, $slots),
        ];
    }

    /**
     * Talks that are accepted but not yet on the schedule.
     *
     * @return array{count: int, talks: list<array{slug: string, title: string, speaker: string, duration_minutes: int}>}
     */
    #[McpTool(
        name: 'list_unscheduled_talks',
        title: 'List unscheduled talks',
        description: 'List accepted talks that still need a room and a time slot.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function listUnscheduled(): array
    {
        $talks = $this->talks->findUnscheduled();

        return [
            'count' => \count($talks),
            'talks' => array_map(static fn ($talk): array => [
                'slug' => $talk->getSlug(),
                'title' => $talk->getTitle(),
                'speaker' => $talk->getSpeaker()->getName(),
                'duration_minutes' => $talk->getDurationMinutes(),
            ], $talks),
        ];
    }
}
