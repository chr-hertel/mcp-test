<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Room;
use App\Entity\Slot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Slot>
 */
class SlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Slot::class);
    }

    /**
     * @return list<Slot>
     */
    public function findForDay(string $day): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.talk', 't')->addSelect('t')
            ->join('t.speaker', 'sp')->addSelect('sp')
            ->join('s.room', 'r')->addSelect('r')
            ->where('s.startsAt >= :from AND s.startsAt < :to')
            ->setParameter('from', new \DateTimeImmutable($day.' 00:00:00'))
            ->setParameter('to', new \DateTimeImmutable($day.' 00:00:00 +1 day'))
            ->orderBy('s.startsAt', 'ASC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Slot>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.talk', 't')->addSelect('t')
            ->join('t.speaker', 'sp')->addSelect('sp')
            ->join('s.room', 'r')->addSelect('r')
            ->orderBy('s.startsAt', 'ASC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Slot>
     */
    public function findForRoomOnDay(Room $room, string $day): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.talk', 't')->addSelect('t')
            ->where('s.room = :room')
            ->andWhere('s.startsAt >= :from AND s.startsAt < :to')
            ->setParameter('room', $room)
            ->setParameter('from', new \DateTimeImmutable($day.' 00:00:00'))
            ->setParameter('to', new \DateTimeImmutable($day.' 00:00:00 +1 day'))
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
