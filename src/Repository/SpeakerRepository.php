<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Speaker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Speaker>
 */
class SpeakerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Speaker::class);
    }

    public function findOneBySlug(string $slug): ?Speaker
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return list<Speaker>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * @return list<string>
     */
    public function findSlugs(): array
    {
        return array_column(
            $this->createQueryBuilder('s')->select('s.slug')->orderBy('s.slug', 'ASC')->getQuery()->getScalarResult(),
            'slug',
        );
    }
}
