<?php

declare(strict_types=1);

namespace App\Mcp\Tool\Organizer;

use App\Entity\Slot;
use App\Mcp\Completion\ConferenceDayCompletion;
use App\Mcp\Completion\RoomNameCompletion;
use App\Mcp\Completion\TalkSlugCompletion;
use App\Repository\ConferenceRepository;
use App\Repository\RoomRepository;
use App\Repository\SlotRepository;
use App\Repository\TalkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;

/**
 * A writing tool that talks back to the client while it works.
 *
 * Declaring a {@see RequestContext} parameter is how a handler reaches the
 * client: the SDK injects it and keeps it out of the generated input schema.
 * From there `progress()` and `log()` produce server-to-client notifications,
 * which on the Streamable HTTP transport open a request-scoped SSE stream.
 */
final class ScheduleTool
{
    public function __construct(
        private readonly TalkRepository $talks,
        private readonly RoomRepository $rooms,
        private readonly SlotRepository $slots,
        private readonly ConferenceRepository $conferences,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Put an accepted talk into a room at a given time.
     *
     * @param string $slug      the talk to schedule
     * @param string $room      the room name, e.g. "Studio A"
     * @param string $day       the conference day, YYYY-MM-DD
     * @param string $startTime the start time, HH:MM in the conference timezone
     *
     * @return array{status: string, talk: string, room: string, starts_at: string, ends_at: string}
     */
    #[McpTool(
        name: 'schedule_talk',
        title: 'Schedule a talk',
        description: 'Assign an accepted talk to a room and a start time. Fails if the room is already busy.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            // Additive: it fills an empty slot rather than overwriting an existing programme.
            destructiveHint: false,
            // Re-running with the same arguments lands on the same schedule.
            idempotentHint: true,
            openWorldHint: false,
        ),
    )]
    public function scheduleTalk(
        RequestContext $context,
        #[CompletionProvider(provider: TalkSlugCompletion::class)]
        string $slug,
        #[CompletionProvider(provider: RoomNameCompletion::class)]
        string $room,
        #[Schema(pattern: '^\d{4}-\d{2}-\d{2}$')]
        #[CompletionProvider(provider: ConferenceDayCompletion::class)]
        string $day,
        #[Schema(description: 'Start time as HH:MM, in the conference timezone.', pattern: '^\d{2}:\d{2}$')]
        string $startTime = '09:30',
    ): array {
        $client = $context->getClientGateway();
        $client->log(LoggingLevel::Info, \sprintf('Scheduling "%s" in %s on %s at %s.', $slug, $room, $day, $startTime));

        $client->progress(0.1, 1.0, 'Looking up the talk');
        $talk = $this->talks->findOneBySlug($slug);
        if (null === $talk) {
            throw new ToolCallException(\sprintf('No talk with slug "%s".', $slug));
        }
        if (null !== $talk->getSlot()) {
            throw new ToolCallException(\sprintf(
                'Talk "%s" is already scheduled in %s at %s. Unschedule it first.',
                $slug,
                $talk->getSlot()->getRoom()->getName(),
                $talk->getSlot()->getStartsAt()->format('Y-m-d H:i'),
            ));
        }

        $client->progress(0.3, 1.0, 'Checking the room');
        $roomEntity = $this->rooms->findOneByName($room);
        if (null === $roomEntity) {
            throw new ToolCallException(\sprintf(
                'No room named "%s". Available rooms: %s.',
                $room,
                implode(', ', $this->rooms->findNames()),
            ));
        }

        $conference = $this->conferences->current();
        if (!\in_array($day, $conference->getDays(), true)) {
            throw new ToolCallException(\sprintf(
                'The conference does not run on %s. Its days are: %s.',
                $day,
                implode(', ', $conference->getDays()),
            ));
        }

        $client->progress(0.6, 1.0, 'Checking for collisions');
        $startsAt = new \DateTimeImmutable(\sprintf('%s %s:00', $day, $startTime));
        $candidate = new Slot($talk, $roomEntity, $startsAt);

        foreach ($this->slots->findForRoomOnDay($roomEntity, $day) as $existing) {
            if ($existing->overlaps($candidate)) {
                // Undo the association the Slot constructor made before bailing out.
                $talk->setSlot(null);

                throw new ToolCallException(\sprintf(
                    '%s is busy from %s to %s with "%s".',
                    $room,
                    $existing->getStartsAt()->format('H:i'),
                    $existing->getEndsAt()->format('H:i'),
                    $existing->getTalk()->getTitle(),
                ));
            }
        }

        $client->progress(0.9, 1.0, 'Saving');
        $this->entityManager->persist($candidate);
        $this->entityManager->flush();

        $client->progress(1.0, 1.0, 'Done');
        $client->log(LoggingLevel::Notice, \sprintf('Scheduled "%s" in %s.', $talk->getTitle(), $room));

        return [
            'status' => 'scheduled',
            'talk' => $talk->getTitle(),
            'room' => $room,
            'starts_at' => $candidate->getStartsAt()->format(\DATE_ATOM),
            'ends_at' => $candidate->getEndsAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * Take a talk off the schedule, freeing its slot.
     *
     * @param string $slug the talk to unschedule
     *
     * @return array{status: string, talk: string, freed: string|null}
     */
    #[McpTool(
        name: 'unschedule_talk',
        title: 'Unschedule a talk',
        description: 'Remove a talk from the schedule, freeing its room and time slot.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            // This one really does throw away programme state.
            destructiveHint: true,
            idempotentHint: true,
            openWorldHint: false,
        ),
    )]
    public function unscheduleTalk(
        #[CompletionProvider(provider: TalkSlugCompletion::class)]
        string $slug,
    ): array {
        $talk = $this->talks->findOneBySlug($slug);

        if (null === $talk) {
            throw new ToolCallException(\sprintf('No talk with slug "%s".', $slug));
        }

        $slot = $talk->getSlot();
        if (null === $slot) {
            return ['status' => 'noop', 'talk' => $talk->getTitle(), 'freed' => null];
        }

        $freed = \sprintf('%s %s', $slot->getRoom()->getName(), $slot->getStartsAt()->format('Y-m-d H:i'));

        $talk->setSlot(null);
        $this->entityManager->remove($slot);
        $this->entityManager->flush();

        return ['status' => 'unscheduled', 'talk' => $talk->getTitle(), 'freed' => $freed];
    }
}
