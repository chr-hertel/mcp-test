<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Level;
use App\Enum\ProposalStatus;
use App\Enum\Track;
use App\Repository\ProposalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Call-for-Papers submission, before it becomes a {@see Talk}.
 *
 * Written by the `submit_proposal` MCP tool, which uses elicitation to collect
 * the fields the model did not supply.
 */
#[ORM\Entity(repositoryClass: ProposalRepository::class)]
class Proposal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: ProposalStatus::class, length: 20)]
    private ProposalStatus $status = ProposalStatus::Submitted;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $submittedAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reviewNotes = null;

    #[ORM\Column(nullable: true)]
    private ?int $reviewScore = null;

    public function __construct(
        #[ORM\Column(length: 200)]
        private string $title,
        #[ORM\Column(type: 'text')]
        private string $abstract,
        #[ORM\Column(length: 120)]
        private string $speakerName,
        #[ORM\Column(length: 180)]
        private string $speakerEmail,
        #[ORM\Column(enumType: Track::class, length: 20)]
        private Track $track,
        #[ORM\Column(enumType: Level::class, length: 20)]
        private Level $level,
        ?\DateTimeImmutable $submittedAt = null,
    ) {
        $this->submittedAt = $submittedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getAbstract(): string
    {
        return $this->abstract;
    }

    public function getSpeakerName(): string
    {
        return $this->speakerName;
    }

    public function getSpeakerEmail(): string
    {
        return $this->speakerEmail;
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function getLevel(): Level
    {
        return $this->level;
    }

    public function getStatus(): ProposalStatus
    {
        return $this->status;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getReviewNotes(): ?string
    {
        return $this->reviewNotes;
    }

    public function getReviewScore(): ?int
    {
        return $this->reviewScore;
    }

    public function review(string $notes, int $score): void
    {
        $this->reviewNotes = $notes;
        $this->reviewScore = $score;
        $this->status = ProposalStatus::UnderReview;
    }

    public function decide(ProposalStatus $status): void
    {
        $this->status = $status;
    }
}
