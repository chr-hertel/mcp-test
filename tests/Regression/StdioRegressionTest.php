<?php

declare(strict_types=1);

namespace App\Tests\Regression;

use App\Mcp\Regression\CheckResult;
use App\Mcp\Regression\MatrixCell;
use App\Mcp\Regression\ProtocolMatrix;
use App\Mcp\Regression\RegressionRunner;
use App\Tests\Support\McpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\AI\McpBundle\Exception\ConnectionException;

/**
 * The regression suite as a test: the same checks `app:mcp:regression` runs,
 * asserted instead of printed.
 *
 * One test case per STDIO cell of the protocol matrix — both eras against every
 * STDIO server, plus the negotiation cells for the older revisions. The STDIO
 * transport is what makes this runnable in CI without a web server: the client
 * spawns `bin/console mcp:server <name>` itself, so one `phpunit` invocation
 * covers the whole stack from the SDK's client down to the bundle's server
 * wiring and back.
 *
 * @see \App\Mcp\Regression\ProtocolMatrix for the cells
 * @see \App\Mcp\Regression\RegressionRunner for what each check does
 */
#[Group('regression')]
final class StdioRegressionTest extends McpTestCase
{
    /**
     * @return iterable<string, array{MatrixCell}>
     */
    public static function cellProvider(): iterable
    {
        foreach (ProtocolMatrix::cells() as $cell) {
            if ('stdio' === $cell->transport()) {
                yield $cell->label() => [$cell];
            }
        }
    }

    #[DataProvider('cellProvider')]
    public function testEveryCheckPasses(MatrixCell $cell): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $matrix = $container->get(ProtocolMatrix::class);
        \assert($matrix instanceof ProtocolMatrix);

        $runner = $container->get(RegressionRunner::class);
        \assert($runner instanceof RegressionRunner);

        $connection = $matrix->connect($cell);

        try {
            $results = $cell->full
                ? $runner->run($connection, $cell->expected)
                : $runner->negotiate($connection, $cell->expected);
        } finally {
            $connection->disconnect();
        }

        $this->assertNotEmpty($results, 'The runner produced no checks at all.');

        $failures = array_values(array_filter($results, static fn (CheckResult $r): bool => $r->isFailure()));

        $this->assertSame([], array_map(
            static fn (CheckResult $r): string => \sprintf('[%s] %s: %s', $r->group, $r->name, $r->detail),
            $failures,
        ), \sprintf('%d of %d checks failed in "%s".', \count($failures), \count($results), $cell->label()));

        if (!$cell->full) {
            return;
        }

        // A suite that skips everything would also report zero failures.
        $passed = \count(array_filter($results, static fn (CheckResult $r): bool => $r->isPass()));
        $this->assertGreaterThanOrEqual(25, $passed, 'Too few checks actually ran; something is being skipped that should not be.');

        // The point of the matrix: whatever a server can ask a client, it can
        // ask in either era once it returns the ask. Skipping counts as losing
        // it, so these have to pass, in both columns alike.
        if ('organizer_stdio' === $cell->server) {
            $this->assertSame([
                'roots/list round trip',
                'elicitation round trip',
                'a declined elicitation is handled',
                'sampling round trip',
            ], array_values(array_map(
                static fn (CheckResult $r): string => $r->name,
                array_filter($results, static fn (CheckResult $r): bool => 'asks: returned' === $r->group && $r->isPass()),
            )));
        }
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

    public function testADeadServerSaysWhyItDied(): void
    {
        self::bootKernel();

        $client = self::getContainer()->get('mcp.client.minimal');
        \assert($client instanceof McpClientInterface);

        $connection = $client->get('dead_stdio');
        $started = microtime(true);

        try {
            $connection->listTools();
            $this->fail('A server that exits before answering was reported as connected.');
        } catch (ConnectionException $e) {
            // The SDK's client reports the exit code and the end of stderr, and
            // fails at once instead of waiting out init_timeout.
            $reason = (string) $e->getPrevious()?->getMessage();
            $this->assertStringContainsString('code 3', $reason, $reason);
            $this->assertStringContainsString('no licence key', $reason, $reason);
            $this->assertLessThan(5.0, microtime(true) - $started, 'The client waited out the timeout for a process that was already gone.');
        }

        $this->assertFalse($connection->isConnected());
    }
}
