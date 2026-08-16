<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DataFixtures\ConferenceFixtures;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Boots the kernel with a freshly seeded SQLite database.
 *
 * The MCP surface reads and writes the programme, so every test starts from the
 * same fixture set — and the STDIO tests spawn a child process against the very
 * same file, which is why the schema is created eagerly rather than lazily.
 */
abstract class McpTestCase extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $this->seedDatabase();
        self::ensureKernelShutdown();
    }

    protected function seedDatabase(): void
    {
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $loader = new SymfonyFixturesLoader();
        $loader->addFixture(new ConferenceFixtures());

        (new ORMExecutor($entityManager, new ORMPurger()))->execute($loader->getFixtures(), append: true);
        $entityManager->clear();
    }
}
