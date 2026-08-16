<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Service\ProgrammeSeeder;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Makes the programme loadable through `doctrine:fixtures:load` as well.
 *
 * The data itself lives in {@see ProgrammeSeeder}, so it is also reachable from
 * the `prod` environment, where DoctrineFixturesBundle is not installed.
 */
final class ConferenceFixtures extends Fixture
{
    public function __construct(private readonly ProgrammeSeeder $seeder = new ProgrammeSeeder())
    {
    }

    public function load(ObjectManager $manager): void
    {
        $this->seeder->seed($manager);
    }
}
