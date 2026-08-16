<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Proposal;
use App\Enum\ProposalStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Proposal>
 */
class ProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Proposal::class);
    }

    /**
     * @return list<Proposal>
     */
    public function findByStatus(?ProposalStatus $status = null): array
    {
        return $this->findBy(
            null === $status ? [] : ['status' => $status],
            ['submittedAt' => 'DESC'],
        );
    }
}
