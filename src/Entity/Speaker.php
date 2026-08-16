<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SpeakerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpeakerRepository::class)]
class Speaker
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @var Collection<int, Talk>
     */
    #[ORM\OneToMany(targetEntity: Talk::class, mappedBy: 'speaker')]
    private Collection $talks;

    public function __construct(
        #[ORM\Column(length: 80, unique: true)]
        private string $slug,
        #[ORM\Column(length: 120)]
        private string $name,
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\Column(type: 'text')]
        private string $bio,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $company = null,
        #[ORM\Column(length: 2, nullable: true)]
        private ?string $country = null,
        #[ORM\Column(length: 80, nullable: true)]
        private ?string $mastodon = null,
    ) {
        $this->talks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getBio(): string
    {
        return $this->bio;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function getMastodon(): ?string
    {
        return $this->mastodon;
    }

    /**
     * @return Collection<int, Talk>
     */
    public function getTalks(): Collection
    {
        return $this->talks;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
