<?php

declare(strict_types=1);

namespace App\Command;

use App\Mcp\Regression\CheckResult;
use App\Mcp\Regression\ModernRegressionRunner;
use App\Mcp\Regression\RegressionRunner;
use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Runs the regression suite against this application's own MCP servers.
 *
 * The interesting part is that nothing here is a mock: the command drives the
 * bundle's MCP client over a real transport against a real server, so it
 * exercises the same code path a host would. Point it at an upstream branch
 * you are working on and it tells you whether the two libraries still agree.
 */
#[AsCommand(
    name: 'app:mcp:regression',
    description: 'Drive this application\'s MCP client against its own MCP servers and report what still works.',
)]
final class McpRegressionCommand extends Command
{
    public function __construct(
        #[Target('regression')]
        private readonly McpClientInterface $client,
        private readonly RegressionRunner $runner,
        private readonly ModernRegressionRunner $modernRunner,
        private readonly string $modernEndpoint,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::OPTIONAL, 'One configured server connection; all of them when omitted.')
            ->addOption('transport', 't', InputOption::VALUE_REQUIRED, 'Only run connections of this transport ("stdio" or "http").')
            ->addOption('quiet-passes', null, InputOption::VALUE_NONE, 'Only print checks that did not pass.')
            ->addOption('skip-modern', null, InputOption::VALUE_NONE, 'Skip the 2026-07-28 checks, which need the web server.')
            ->setHelp(<<<'HELP'
                Runs every check against every configured server of the "regression" client:

                    <info>php %command.full_name%</info>

                The STDIO connections need nothing running — they spawn <info>bin/console mcp:server</info>
                themselves. The HTTP ones need a web server on <info>MCP_DEMO_BASE_URL</info>:

                    <info>symfony server:start -d</info>
                    <info>php %command.full_name% --transport=http</info>

                A single connection, verbosely:

                    <info>php %command.full_name% conference_stdio</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('MCP regression suite');

        $connections = 'modern' === $input->getArgument('server') ? [] : $this->connections($input, $io);

        if ([] === $connections && !$this->shouldRunModern($input)) {
            $io->warning('No connection matched.');

            return Command::INVALID;
        }

        $failed = 0;
        $summary = [];

        foreach ($connections as $name => $connection) {
            $io->section($name);

            try {
                $results = $this->runner->run($connection);
            } catch (\Throwable $e) {
                $io->error(\sprintf('%s could not be reached: %s', $name, $e->getMessage()));
                $summary[] = [$name, '—', '—', '—', '<fg=red>unreachable</>'];
                ++$failed;

                continue;
            } finally {
                // STDIO connections own a child process; do not carry it into the next server.
                $connection->disconnect();
            }

            $this->render($io, $results, (bool) $input->getOption('quiet-passes'));

            $counts = array_count_values(array_map(static fn (CheckResult $r): string => $r->status, $results));
            $failedHere = $counts[CheckResult::FAIL] ?? 0;
            $failed += $failedHere;

            $summary[] = [
                $name,
                (string) ($counts[CheckResult::PASS] ?? 0),
                (string) ($counts[CheckResult::SKIP] ?? 0),
                (string) $failedHere,
                $failedHere > 0 ? '<fg=red>fail</>' : '<fg=green>ok</>',
            ];
        }

        if ($this->shouldRunModern($input)) {
            $io->section('modern (2026-07-28)');

            // Not a connection of the "regression" client: the SDK's client cannot
            // speak this revision, so the demo brings its own. See
            // App\Mcp\Regression\ModernRegressionRunner.
            $results = $this->modernRunner->run($this->modernEndpoint);
            $this->render($io, $results, (bool) $input->getOption('quiet-passes'));

            $counts = array_count_values(array_map(static fn (CheckResult $r): string => $r->status, $results));
            $failedHere = $counts[CheckResult::FAIL] ?? 0;
            $failed += $failedHere;

            $summary[] = [
                'modern_http',
                (string) ($counts[CheckResult::PASS] ?? 0),
                (string) ($counts[CheckResult::SKIP] ?? 0),
                (string) $failedHere,
                $failedHere > 0 ? '<fg=red>fail</>' : '<fg=green>ok</>',
            ];
        }

        $io->section('Summary');
        $io->table(['Connection', 'Passed', 'Skipped', 'Failed', 'Result'], $summary);

        if ($failed > 0) {
            $io->error(\sprintf('%d check(s) failed.', $failed));

            return Command::FAILURE;
        }

        $io->success('Every check passed.');

        return Command::SUCCESS;
    }

    /**
     * The modern endpoint is HTTP-only, so it runs unless the transport filter
     * excludes HTTP or a single STDIO connection was named.
     */
    private function shouldRunModern(InputInterface $input): bool
    {
        if ($input->getOption('skip-modern')) {
            return false;
        }

        if ('stdio' === $input->getOption('transport')) {
            return false;
        }

        $only = $input->getArgument('server');

        return null === $only || 'modern' === $only;
    }

    /**
     * @param list<CheckResult> $results
     */
    private function render(SymfonyStyle $io, array $results, bool $quietPasses): void
    {
        $rows = [];
        $group = null;

        foreach ($results as $result) {
            if ($quietPasses && $result->isPass()) {
                continue;
            }

            if ($group !== $result->group && [] !== $rows) {
                $rows[] = new \Symfony\Component\Console\Helper\TableSeparator();
            }
            $group = $result->group;

            $rows[] = [
                match ($result->status) {
                    CheckResult::PASS => '<fg=green>pass</>',
                    CheckResult::FAIL => '<fg=red>FAIL</>',
                    default => '<fg=yellow>skip</>',
                },
                $result->group,
                $result->name,
                $result->detail,
            ];
        }

        if ([] === $rows) {
            $io->writeln('<fg=green>Everything passed.</>');

            return;
        }

        $io->table(['', 'Group', 'Check', 'Detail'], $rows);
    }

    /**
     * @return array<string, ServerConnectionInterface>
     */
    private function connections(InputInterface $input, SymfonyStyle $io): array
    {
        $only = $input->getArgument('server');
        $transport = $input->getOption('transport');

        if (null !== $only) {
            if (!$this->client->has($only)) {
                $io->error(\sprintf('Unknown server "%s". Configured: %s.', $only, implode(', ', $this->client->getServerNames())));

                return [];
            }

            return [$only => $this->client->get($only)];
        }

        $connections = [];

        foreach ($this->client as $name => $connection) {
            // The transport is not introspectable from the connection, so the
            // naming convention in config/packages/mcp.yaml is what we filter on.
            if (null !== $transport && !str_ends_with($name, '_'.$transport)) {
                continue;
            }

            $connections[$name] = $connection;
        }

        return $connections;
    }
}
