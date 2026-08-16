<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Conference;
use App\Entity\Proposal;
use App\Entity\Slot;
use App\Entity\Speaker;
use App\Entity\Talk;

/**
 * Turns entities into the plain arrays the MCP layer hands to clients.
 *
 * Kept in one place because the same shapes are used by tools (as structured
 * content), by resources (as JSON bodies) and by the MCP Apps templates, and a
 * regression suite that asserts on them wants exactly one definition to track.
 */
final class ProgrammePresenter
{
    /**
     * @return array{
     *     slug: string, title: string, abstract: string, track: string, level: string,
     *     language: string, duration_minutes: int, status: string, tags: list<string>,
     *     speaker: array{slug: string, name: string, company: string|null},
     *     schedule: array{day: string, starts_at: string, ends_at: string, room: string}|null,
     *     uri: string
     * }
     */
    public function talk(Talk $talk): array
    {
        $slot = $talk->getSlot();

        return [
            'slug' => $talk->getSlug(),
            'title' => $talk->getTitle(),
            'abstract' => $talk->getAbstract(),
            'track' => $talk->getTrack()->value,
            'level' => $talk->getLevel()->value,
            'language' => $talk->getLanguage(),
            'duration_minutes' => $talk->getDurationMinutes(),
            'status' => $talk->getStatus()->value,
            'tags' => $talk->getTags(),
            'speaker' => [
                'slug' => $talk->getSpeaker()->getSlug(),
                'name' => $talk->getSpeaker()->getName(),
                'company' => $talk->getSpeaker()->getCompany(),
            ],
            'schedule' => null === $slot ? null : [
                'day' => $slot->getDay(),
                'starts_at' => $slot->getStartsAt()->format(\DATE_ATOM),
                'ends_at' => $slot->getEndsAt()->format(\DATE_ATOM),
                'room' => $slot->getRoom()->getName(),
            ],
            'uri' => 'talk://'.$talk->getSlug(),
        ];
    }

    /**
     * @return array{
     *     slug: string, name: string, bio: string, company: string|null, country: string|null,
     *     mastodon: string|null, talks: list<array{slug: string, title: string}>, uri: string
     * }
     */
    public function speaker(Speaker $speaker): array
    {
        return [
            'slug' => $speaker->getSlug(),
            'name' => $speaker->getName(),
            'bio' => $speaker->getBio(),
            'company' => $speaker->getCompany(),
            'country' => $speaker->getCountry(),
            'mastodon' => $speaker->getMastodon(),
            'talks' => array_values(array_map(
                static fn (Talk $talk): array => ['slug' => $talk->getSlug(), 'title' => $talk->getTitle()],
                $speaker->getTalks()->toArray(),
            )),
            'uri' => 'speaker://'.$speaker->getSlug(),
        ];
    }

    /**
     * @return array{
     *     starts_at: string, ends_at: string, room: string, capacity: int,
     *     talk: array{slug: string, title: string, track: string, level: string, speaker: string}
     * }
     */
    public function slot(Slot $slot): array
    {
        $talk = $slot->getTalk();

        return [
            'starts_at' => $slot->getStartsAt()->format('H:i'),
            'ends_at' => $slot->getEndsAt()->format('H:i'),
            'room' => $slot->getRoom()->getName(),
            'capacity' => $slot->getRoom()->getCapacity(),
            'talk' => [
                'slug' => $talk->getSlug(),
                'title' => $talk->getTitle(),
                'track' => $talk->getTrack()->value,
                'level' => $talk->getLevel()->value,
                'speaker' => $talk->getSpeaker()->getName(),
            ],
        ];
    }

    /**
     * @return array{
     *     id: int|null, title: string, abstract: string, track: string, level: string,
     *     speaker_name: string, speaker_email: string, status: string, submitted_at: string,
     *     review_score: int|null, review_notes: string|null
     * }
     */
    public function proposal(Proposal $proposal): array
    {
        return [
            'id' => $proposal->getId(),
            'title' => $proposal->getTitle(),
            'abstract' => $proposal->getAbstract(),
            'track' => $proposal->getTrack()->value,
            'level' => $proposal->getLevel()->value,
            'speaker_name' => $proposal->getSpeakerName(),
            'speaker_email' => $proposal->getSpeakerEmail(),
            'status' => $proposal->getStatus()->value,
            'submitted_at' => $proposal->getSubmittedAt()->format(\DATE_ATOM),
            'review_score' => $proposal->getReviewScore(),
            'review_notes' => $proposal->getReviewNotes(),
        ];
    }

    /**
     * @return array{
     *     name: string, edition: string, city: string, website: string, timezone: string,
     *     starts_on: string, ends_on: string, days: list<string>, cfp_open: bool
     * }
     */
    public function conference(Conference $conference): array
    {
        return [
            'name' => $conference->getName(),
            'edition' => $conference->getEdition(),
            'city' => $conference->getCity(),
            'website' => $conference->getWebsite(),
            'timezone' => $conference->getTimezone(),
            'starts_on' => $conference->getStartsOn()->format('Y-m-d'),
            'ends_on' => $conference->getEndsOn()->format('Y-m-d'),
            'days' => $conference->getDays(),
            'cfp_open' => $conference->isCfpOpen(),
        ];
    }

    /**
     * The whole programme as Markdown — what a model reads best.
     *
     * @param list<Slot> $slots
     */
    public function scheduleAsMarkdown(Conference $conference, array $slots): string
    {
        $lines = ['# '.$conference->getTitle().' — Schedule', ''];
        $currentDay = null;
        $currentTime = null;

        foreach ($slots as $slot) {
            if ($currentDay !== $slot->getDay()) {
                $currentDay = $slot->getDay();
                $currentTime = null;
                $lines[] = '';
                $lines[] = '## '.(new \DateTimeImmutable($currentDay))->format('l, j F Y');
            }

            $time = $slot->getStartsAt()->format('H:i');
            if ($currentTime !== $time) {
                $currentTime = $time;
                $lines[] = '';
                $lines[] = \sprintf('### %s–%s', $time, $slot->getEndsAt()->format('H:i'));
            }

            $talk = $slot->getTalk();
            $lines[] = \sprintf(
                '- **%s** — %s (%s, %s) · %s · `talk://%s`',
                $slot->getRoom()->getName(),
                $talk->getTitle(),
                $talk->getTrack()->value,
                $talk->getLevel()->value,
                $talk->getSpeaker()->getName(),
                $talk->getSlug(),
            );
        }

        if (1 === \count($lines) + 1) {
            $lines[] = '_Nothing scheduled yet._';
        }

        return implode("\n", $lines)."\n";
    }
}
