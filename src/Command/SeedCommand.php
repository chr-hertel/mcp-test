<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ProgrammeSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drops, recreates and re-seeds the demo database in whichever environment it runs in.
 *
 * `doctrine:fixtures:load` would do the same, but DoctrineFixturesBundle is a dev
 * dependency and the STDIO servers Claude Desktop launches run in `prod`.
 */
#[AsCommand(
    name: 'app:seed',
    description: 'Recreate the demo database and load the conference programme.',
)]
final class SeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgrammeSeeder $seeder,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('keep-schema', null, InputOption::VALUE_NONE, 'Only re-seed; do not drop and recreate the tables.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        if ([] === $metadata) {
            $io->error('No entity metadata found.');

            return Command::FAILURE;
        }

        $schemaTool = new SchemaTool($this->entityManager);

        if (!$input->getOption('keep-schema')) {
            $schemaTool->dropSchema($metadata);
            $schemaTool->createSchema($metadata);
            $io->text('Schema recreated.');
        }

        $this->seeder->seed($this->entityManager);

        $io->success(\sprintf('Loaded the programme into the "%s" environment.', $this->environment));

        return Command::SUCCESS;
    }
}
