<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Talk;
use App\Enum\Level;
use App\Enum\TalkStatus;
use App\Enum\Track;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Talk>
 */
class TalkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Talk::class);
    }

    /**
     * @return list<Talk>
     */
    public function search(
        ?string $query = null,
        ?Track $track = null,
        ?Level $level = null,
        ?string $day = null,
        ?string $speakerSlug = null,
        int $limit = 20,
        int $offset = 0,
    ): array {
        $qb = $this->createQueryBuilder('t')
            ->join('t.speaker', 's')->addSelect('s')
            ->leftJoin('t.slot', 'sl')->addSelect('sl')
            ->leftJoin('sl.room', 'r')->addSelect('r')
            ->orderBy('t.title', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('LOWER(t.title) LIKE :q OR LOWER(t.abstract) LIKE :q OR LOWER(s.name) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($query)).'%');
        }

        if (null !== $track) {
            $qb->andWhere('t.track = :track')->setParameter('track', $track);
        }

        if (null !== $level) {
            $qb->andWhere('t.level = :level')->setParameter('level', $level);
        }

        if (null !== $speakerSlug) {
            $qb->andWhere('s.slug = :speaker')->setParameter('speaker', $speakerSlug);
        }

        if (null !== $day) {
            $qb->andWhere('sl.startsAt >= :from AND sl.startsAt < :to')
                ->setParameter('from', new \DateTimeImmutable($day.' 00:00:00'))
                ->setParameter('to', new \DateTimeImmutable($day.' 00:00:00 +1 day'));
        }

        return $qb->getQuery()->getResult();
    }

    public function countMatching(?string $query = null, ?Track $track = null, ?Level $level = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.speaker', 's');

        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('LOWER(t.title) LIKE :q OR LOWER(t.abstract) LIKE :q OR LOWER(s.name) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($query)).'%');
        }

        if (null !== $track) {
            $qb->andWhere('t.track = :track')->setParameter('track', $track);
        }

        if (null !== $level) {
            $qb->andWhere('t.level = :level')->setParameter('level', $level);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findOneBySlug(string $slug): ?Talk
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return list<Talk>
     */
    public function findUnscheduled(): array
    {
        // "t.slot IS NULL" would be a path expression on the inverse side of the
        // association, which DQL does not allow; the join has to be explicit.
        return $this->createQueryBuilder('t')
            ->join('t.speaker', 's')->addSelect('s')
            ->leftJoin('t.slot', 'sl')
            ->where('sl.id IS NULL')
            ->andWhere('t.status = :status')
            ->setParameter('status', TalkStatus::Accepted)
            ->orderBy('t.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<string>
     */
    public function findSlugs(): array
    {
        return array_column(
            $this->createQueryBuilder('t')->select('t.slug')->orderBy('t.slug', 'ASC')->getQuery()->getScalarResult(),
            'slug',
        );
    }
}
