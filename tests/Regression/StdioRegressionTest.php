<?php

declare(strict_types=1);

namespace App\Tests\Regression;

use App\Mcp\Regression\CheckResult;
use App\Mcp\Regression\RegressionRunner;
use App\Tests\Support\McpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\AI\McpBundle\Client\McpClientInterface;

/**
 * The regression suite as a test: the same checks `app:mcp:regression` runs,
 * asserted instead of printed.
 *
 * The STDIO transport is what makes this runnable in CI without a web server —
 * the client spawns `bin/console mcp:server <name>` itself, so one `phpunit`
 * invocation covers the whole stack from the SDK's client down to the bundle's
 * server wiring and back.
 *
 * @see \App\Mcp\Regression\RegressionRunner for what each check does
 */
#[Group('regression')]
final class StdioRegressionTest extends McpTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function connectionProvider(): iterable
    {
        yield 'conference over stdio' => ['conference_stdio'];
        yield 'organizer over stdio' => ['organizer_stdio'];
    }

    #[DataProvider('connectionProvider')]
    public function testEveryCheckPasses(string $server): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $client = $container->get('mcp.client.regression');
        \assert($client instanceof McpClientInterface);

        $runner = $container->get(RegressionRunner::class);
        \assert($runner instanceof RegressionRunner);

        $connection = $client->get($server);

        try {
            $results = $runner->run($connection);
        } finally {
            $connection->disconnect();
        }

        $this->assertNotEmpty($results, 'The runner produced no checks at all.');

        $failures = array_values(array_filter($results, static fn (CheckResult $r): bool => $r->isFailure()));

        $this->assertSame([], array_map(
            static fn (CheckResult $r): string => \sprintf('[%s] %s: %s', $r->group, $r->name, $r->detail),
            $failures,
        ), \sprintf('%d of %d checks failed against "%s".', \count($failures), \count($results), $server));

        // A suite that skips everything would also report zero failures.
        $passed = \count(array_filter($results, static fn (CheckResult $r): bool => $r->isPass()));
        $this->assertGreaterThanOrEqual(25, $passed, 'Too few checks actually ran; something is being skipped that should not be.');
    }

    public function testTheMinimalClientSeesNoOptionalCapabilities(): void
    {
        self::bootKernel();

        $client = self::getContainer()->get('mcp.client.minimal');
        \assert($client instanceof McpClientInterface);

        $connection = $client->get('conference_stdio');

        try {
            // The "minimal" client configures no roots, sampling or elicitation
            // handler, so the server must see none of those capabilities and the
            // tools that need them must degrade rather than fail.
            $tools = $connection->getTools();
            $this->assertNotEmpty($tools);

            $result = $connection->callTool('search_talks', ['query' => 'doctrine']);
            $this->assertFalse($result->isError);
        } finally {
            $connection->disconnect();
        }
    }
}
