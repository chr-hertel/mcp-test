<?php

declare(strict_types=1);

namespace App\Mcp\App;

use App\Enum\Track;
use App\Repository\ConferenceRepository;
use App\Repository\SlotRepository;
use App\Repository\TalkRepository;
use App\Service\ProgrammePresenter;
use Symfony\AI\McpBundle\Attribute\AsMcpApp;
use Symfony\AI\McpBundle\Attribute\AsMcpAppTool;

/**
 * An **MCP App**: a tool whose result is a rendered screen the host shows in a
 * sandboxed iframe, rather than text for the model to read out.
 *
 * The class is the whole app. `#[AsMcpApp]` carries the linked tool's identity
 * and the static HTML shell; the handler method produces the tool result; and
 * `toolTemplate` puts the rendering on the server — the method returns a Twig
 * context, the bundle renders it into the result's `html` field, and the base
 * template drops that into `#root`. No client-side rendering code at all.
 *
 * Follow-up interactions are `#[AsMcpAppTool]` methods reached declaratively
 * from the markup: `data-call="open_talk" data-arg-slug="…"` on a button is the
 * whole wiring. See templates/mcp/_schedule.html.twig.
 */
#[AsMcpApp(
    uri: 'ui://schedule',
    name: 'browse_schedule',
    title: 'Conference schedule',
    description: 'Show the conference schedule as an interactive, filterable screen.',
    template: 'mcp/schedule.html.twig',
    toolTemplate: 'mcp/_schedule.html.twig',
    prefersBorder: true,
)]
final class ScheduleApp
{
    public function __construct(
        private readonly ConferenceRepository $conferences,
        private readonly SlotRepository $slots,
        private readonly TalkRepository $talks,
        private readonly ProgrammePresenter $presenter,
    ) {
    }

    /**
     * The app's primary tool: render the schedule for one day, optionally
     * narrowed to one track.
     *
     * The returned array is the Twig context for `mcp/_schedule.html.twig`, not
     * HTML — and its scalar entries also refill the shell's form controls, so
     * the day and track selectors stay in sync with what is on screen.
     *
     * @param string|null $day   the conference day (YYYY-MM-DD); defaults to the first day
     * @param string|null $track a track to filter by, or null for all of them
     *
     * @return array<string, mixed>
     */
    public function render(?string $day = null, ?string $track = null): array
    {
        $conference = $this->conferences->current();
        $days = $conference->getDays();
        $day = null !== $day && \in_array($day, $days, true) ? $day : $days[0];
        $trackEnum = null === $track || '' === $track ? null : Track::tryFrom($track);

        $slots = $this->slots->findForDay($day);

        if (null !== $trackEnum) {
            $slots = array_values(array_filter(
                $slots,
                static fn ($slot): bool => $slot->getTalk()->getTrack() === $trackEnum,
            ));
        }

        return [
            'view' => 'schedule',
            'conference' => $conference->getTitle(),
            'website' => $conference->getWebsite(),
            'day' => $day,
            'days' => $days,
            'track' => $trackEnum?->value ?? '',
            'tracks' => Track::cases(),
            'slots' => array_map($this->presenter->slot(...), $slots),
        ];
    }

    /**
     * Open one talk inside the app.
     *
     * Reachable from the model as well as from the app, so a host can jump
     * straight to a talk without going through the schedule first.
     *
     * @param string $slug the talk slug
     *
     * @return array<string, mixed>
     */
    #[AsMcpAppTool(
        name: 'open_talk',
        title: 'Open a talk',
        description: 'Show one talk in the schedule app.',
        template: 'mcp/_talk_detail.html.twig',
    )]
    public function openTalk(string $slug): array
    {
        $talk = $this->talks->findOneBySlug($slug);
        $conference = $this->conferences->current();

        if (null === $talk) {
            return [
                'view' => 'error',
                'conference' => $conference->getTitle(),
                'message' => \sprintf('No talk with slug "%s".', $slug),
                'day' => $conference->getDays()[0],
                'days' => $conference->getDays(),
            ];
        }

        return [
            'view' => 'talk',
            'conference' => $conference->getTitle(),
            'website' => $conference->getWebsite(),
            'talk' => $this->presenter->talk($talk),
            'day' => $talk->getSlot()?->getDay() ?? $conference->getDays()[0],
            'days' => $conference->getDays(),
        ];
    }

    /**
     * Jump back to the schedule from a talk detail view.
     *
     * `appOnly: true` keeps it out of `tools/list` for the model: it is a
     * navigation control of the screen, not something worth calling on its own.
     *
     * @param string|null $day
     *
     * @return array<string, mixed>
     */
    #[AsMcpAppTool(
        name: 'back_to_schedule',
        title: 'Back to the schedule',
        description: 'Return to the schedule view.',
        template: 'mcp/_schedule.html.twig',
        appOnly: true,
    )]
    public function backToSchedule(?string $day = null): array
    {
        return $this->render($day);
    }
}
