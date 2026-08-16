<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conference>
 */
class ConferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conference::class);
    }

    /**
     * The single conference edition this application manages.
     */
    public function current(): Conference
    {
        $conference = $this->findOneBy([]);

        if (null === $conference) {
            throw new \RuntimeException('No conference in the database. Run "bin/console app:load-fixtures" first.');
        }

        return $conference;
    }
}
