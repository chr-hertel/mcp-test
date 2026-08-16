<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Level;
use App\Enum\TalkStatus;
use App\Enum\Track;
use App\Repository\TalkRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TalkRepository::class)]
class Talk
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: TalkStatus::class, length: 20)]
    private TalkStatus $status = TalkStatus::Accepted;

    #[ORM\OneToOne(targetEntity: Slot::class, mappedBy: 'talk')]
    private ?Slot $slot = null;

    /**
     * @param list<string> $tags
     */
    public function __construct(
        #[ORM\Column(length: 100, unique: true)]
        private string $slug,
        #[ORM\Column(length: 200)]
        private string $title,
        #[ORM\Column(type: 'text')]
        private string $abstract,
        #[ORM\ManyToOne(targetEntity: Speaker::class, inversedBy: 'talks')]
        #[ORM\JoinColumn(nullable: false)]
        private Speaker $speaker,
        #[ORM\Column(enumType: Track::class, length: 20)]
        private Track $track,
        #[ORM\Column(enumType: Level::class, length: 20)]
        private Level $level,
        #[ORM\Column]
        private int $durationMinutes = 45,
        #[ORM\Column(length: 5)]
        private string $language = 'en',
        #[ORM\Column(type: 'json')]
        private array $tags = [],
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getAbstract(): string
    {
        return $this->abstract;
    }

    public function getSpeaker(): Speaker
    {
        return $this->speaker;
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function getLevel(): Level
    {
        return $this->level;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getStatus(): TalkStatus
    {
        return $this->status;
    }

    public function getSlot(): ?Slot
    {
        return $this->slot;
    }

    public function setSlot(?Slot $slot): void
    {
        $this->slot = $slot;
        $this->status = null === $slot ? TalkStatus::Accepted : TalkStatus::Scheduled;
    }

    public function cancel(): void
    {
        $this->status = TalkStatus::Cancelled;
    }

    public function reinstate(): void
    {
        $this->status = null === $this->slot ? TalkStatus::Accepted : TalkStatus::Scheduled;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
