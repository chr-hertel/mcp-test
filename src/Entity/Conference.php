<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConferenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The conference edition this application manages. A single row.
 */
#[ORM\Entity(repositoryClass: ConferenceRepository::class)]
class Conference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 120)]
        private string $name,
        #[ORM\Column(length: 60)]
        private string $edition,
        #[ORM\Column(length: 120)]
        private string $city,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $startsOn,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $endsOn,
        #[ORM\Column(length: 255)]
        private string $website,
        #[ORM\Column(length: 64)]
        private string $timezone = 'Europe/Berlin',
        #[ORM\Column(type: 'boolean')]
        private bool $cfpOpen = true,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEdition(): string
    {
        return $this->edition;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function getWebsite(): string
    {
        return $this->website;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function isCfpOpen(): bool
    {
        return $this->cfpOpen;
    }

    public function closeCfp(): void
    {
        $this->cfpOpen = false;
    }

    /**
     * The conference days, as `Y-m-d` strings.
     *
     * @return list<string>
     */
    public function getDays(): array
    {
        $days = [];
        $cursor = $this->startsOn;

        while ($cursor <= $this->endsOn) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    public function getTitle(): string
    {
        return \sprintf('%s %s', $this->name, $this->edition);
    }
}
