<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SlotRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One talk, in one room, at one time.
 */
#[ORM\Entity(repositoryClass: SlotRepository::class)]
#[ORM\UniqueConstraint(name: 'room_start', columns: ['room_id', 'starts_at'])]
class Slot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\OneToOne(targetEntity: Talk::class, inversedBy: 'slot')]
        #[ORM\JoinColumn(nullable: false)]
        private Talk $talk,
        #[ORM\ManyToOne(targetEntity: Room::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Room $room,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $startsAt,
    ) {
        $talk->setSlot($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTalk(): Talk
    {
        return $this->talk;
    }

    public function getRoom(): Room
    {
        return $this->room;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->startsAt->modify(\sprintf('+%d minutes', $this->talk->getDurationMinutes()));
    }

    public function getDay(): string
    {
        return $this->startsAt->format('Y-m-d');
    }

    public function overlaps(self $other): bool
    {
        return $this->startsAt < $other->getEndsAt() && $other->getStartsAt() < $this->getEndsAt();
    }
}
