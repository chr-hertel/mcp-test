<?php

declare(strict_types=1);

namespace App\Mcp\Resource;

use App\Mcp\Completion\ConferenceDayCompletion;
use App\Mcp\Completion\SpeakerSlugCompletion;
use App\Mcp\Completion\TalkSlugCompletion;
use App\Repository\ConferenceRepository;
use App\Repository\SlotRepository;
use App\Repository\SpeakerRepository;
use App\Repository\TalkRepository;
use App\Service\ProgrammePresenter;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\ResourceReadException;
use App\Enum\Track;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;

/**
 * Resource templates: one RFC 6570 URI pattern standing in for a whole family of
 * resources, so `talk://messenger-at-scale` is readable without the server
 * having to list all fourteen talks in `resources/list`.
 *
 * Each variable in the template becomes a method parameter, and a
 * `#[CompletionProvider]` on that parameter is what lets a host complete the URI
 * as the user types it.
 */
final class ProgrammeResourceTemplates
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
     * One talk, by slug.
     *
     * @return array<string, mixed>
     */
    #[McpResourceTemplate(
        uriTemplate: 'talk://{slug}',
        name: 'talk',
        title: 'A single talk',
        description: 'Full details of one talk, including its speaker and schedule.',
        mimeType: 'application/json',
        annotations: new Annotations(audience: [Role::Assistant], priority: 0.8),
    )]
    public function talk(
        #[CompletionProvider(provider: TalkSlugCompletion::class)]
        string $slug,
    ): array {
        $talk = $this->talks->findOneBySlug($slug);

        if (null === $talk) {
            throw new ResourceReadException(\sprintf('No talk with slug "%s".', $slug));
        }

        return $this->presenter->talk($talk);
    }

    /**
     * One speaker, by slug.
     *
     * @return array<string, mixed>
     */
    #[McpResourceTemplate(
        uriTemplate: 'speaker://{slug}',
        name: 'speaker',
        title: 'A single speaker',
        description: 'Profile of one speaker with the talks they are giving.',
        mimeType: 'application/json',
    )]
    public function speaker(
        #[CompletionProvider(provider: SpeakerSlugCompletion::class)]
        string $slug,
    ): array {
        $speaker = $this->speakers->findOneBySlug($slug);

        if (null === $speaker) {
            throw new ResourceReadException(\sprintf('No speaker with slug "%s".', $slug));
        }

        return $this->presenter->speaker($speaker);
    }

    /**
     * The agenda of one conference day, as Markdown.
     */
    #[McpResourceTemplate(
        uriTemplate: 'schedule://{day}',
        name: 'schedule_day',
        title: 'The agenda of one day',
        description: 'Every talk scheduled on one conference day, as Markdown. The day is YYYY-MM-DD.',
        mimeType: 'text/markdown',
    )]
    public function scheduleDay(
        #[CompletionProvider(provider: ConferenceDayCompletion::class)]
        string $day,
    ): string {
        $conference = $this->conferences->current();

        if (!\in_array($day, $conference->getDays(), true)) {
            throw new ResourceReadException(\sprintf(
                'The conference does not run on %s. Its days are: %s.',
                $day,
                implode(', ', $conference->getDays()),
            ));
        }

        return $this->presenter->scheduleAsMarkdown($conference, $this->slots->findForDay($day));
    }

    /**
     * Every talk in one track.
     *
     * @return array{track: string, count: int, talks: list<array<string, mixed>>}
     */
    #[McpResourceTemplate(
        uriTemplate: 'track://{track}',
        name: 'track',
        title: 'A whole track',
        description: 'Every talk in one conference track.',
        mimeType: 'application/json',
    )]
    public function track(
        #[CompletionProvider(enum: Track::class)]
        string $track,
    ): array {
        $enum = Track::tryFrom($track);

        if (null === $enum) {
            throw new ResourceReadException(\sprintf(
                'Unknown track "%s". Known tracks: %s.',
                $track,
                implode(', ', Track::values()),
            ));
        }

        $talks = $this->talks->search(track: $enum, limit: 50);

        return [
            'track' => $enum->value,
            'count' => \count($talks),
            'talks' => array_map($this->presenter->talk(...), $talks),
        ];
    }
}
