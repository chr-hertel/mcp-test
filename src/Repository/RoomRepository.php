<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Room;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Room>
 */
class RoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Room::class);
    }

    public function findOneByName(string $name): ?Room
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * @return list<Room>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * @return list<string>
     */
    public function findNames(): array
    {
        return array_column(
            $this->createQueryBuilder('r')->select('r.name')->orderBy('r.name', 'ASC')->getQuery()->getScalarResult(),
            'name',
        );
    }
}
