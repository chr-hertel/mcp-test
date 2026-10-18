<?php

declare(strict_types=1);

namespace App\Command;

use App\Mcp\Regression\CheckResult;
use App\Mcp\Regression\MatrixCell;
use App\Mcp\Regression\ModernRegressionRunner;
use App\Mcp\Regression\ProtocolMatrix;
use App\Mcp\Regression\RegressionRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
        private readonly ProtocolMatrix $matrix,
        private readonly RegressionRunner $runner,
        private readonly ModernRegressionRunner $modernRunner,
        private readonly string $modernEndpoint,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::OPTIONAL, 'One server connection, in every column; all of them when omitted.')
            ->addOption('client', 'c', InputOption::VALUE_REQUIRED, 'Only run the cells of this client ("handshake", "modern" or "legacy").')
            ->addOption('transport', 't', InputOption::VALUE_REQUIRED, 'Only run connections of this transport ("stdio" or "http").')
            ->addOption('quiet-passes', null, InputOption::VALUE_NONE, 'Only print checks that did not pass.')
            ->addOption('skip-modern', null, InputOption::VALUE_NONE, 'Skip the raw 2026-07-28 probes, which need the web server.')
            ->setHelp(<<<'HELP'
                Runs every cell of the protocol matrix — each server connection once per
                protocol era, plus the negotiation cells for older revisions:

                    <info>php %command.full_name%</info>

                The STDIO connections need nothing running — they spawn <info>bin/console mcp:server</info>
                themselves. The HTTP ones need a web server on <info>MCP_DEMO_BASE_URL</info>:

                    <info>symfony server:start -d</info>
                    <info>php %command.full_name% --transport=http</info>

                One server in both eras, verbosely:

                    <info>php %command.full_name% organizer_stdio</info>

                One era, with the raw protocol probes underneath it:

                    <info>php %command.full_name% --client=modern</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('MCP regression suite');

        $cells = $this->cells($input);

        if ([] === $cells && !$this->shouldRunModern($input)) {
            $io->warning('No cell matched.');

            return Command::INVALID;
        }

        $failed = 0;
        $grid = [];
        $negotiated = [];

        foreach ($cells as $cell) {
            $io->section($cell->label());

            $connection = $this->matrix->connect($cell);

            try {
                $results = $cell->full
                    ? $this->runner->run($connection, $cell->expected)
                    : $this->runner->negotiate($connection, $cell->expected);
            } catch (\Throwable $e) {
                $io->error(\sprintf('%s could not be reached: %s', $cell->label(), $e->getMessage()));
                $results = [CheckResult::fail('lifecycle', 'connect', $e->getMessage())];
            } finally {
                // STDIO connections own a child process; do not carry it into the next cell.
                $connection->disconnect();
            }

            $this->render($io, $results, (bool) $input->getOption('quiet-passes'));

            $counts = array_count_values(array_map(static fn (CheckResult $r): string => $r->status, $results));
            $failedHere = $counts[CheckResult::FAIL] ?? 0;
            $failed += $failedHere;

            if ($cell->full) {
                $grid[$cell->server][$cell->client] = \sprintf(
                    '%s %d / %d / %d',
                    $failedHere > 0 ? '<fg=red>FAIL</>' : '<fg=green>ok</>',
                    $counts[CheckResult::PASS] ?? 0,
                    $counts[CheckResult::SKIP] ?? 0,
                    $failedHere,
                );
            } else {
                $negotiated[] = [
                    $cell->client,
                    $cell->server,
                    $cell->expected->value,
                    $failedHere > 0 ? '<fg=red>FAIL</>' : '<fg=green>ok</>',
                ];
            }
        }

        $raw = null;

        if ($this->shouldRunModern($input)) {
            $io->section('2026-07-28 protocol probes');

            // Not a cell of the matrix: what is checked here is what a conforming
            // client never does — a missing version, a header that disagrees with
            // the body, a tampered requestState. See
            // App\Mcp\Regression\ModernRegressionRunner.
            $results = $this->modernRunner->run($this->modernEndpoint);
            $this->render($io, $results, (bool) $input->getOption('quiet-passes'));

            $counts = array_count_values(array_map(static fn (CheckResult $r): string => $r->status, $results));
            $failedHere = $counts[CheckResult::FAIL] ?? 0;
            $failed += $failedHere;
            $raw = \sprintf('%s %d / %d / %d', $failedHere > 0 ? '<fg=red>FAIL</>' : '<fg=green>ok</>', $counts[CheckResult::PASS] ?? 0, $counts[CheckResult::SKIP] ?? 0, $failedHere);
        }

        $io->section('Summary');

        if ([] !== $grid) {
            $columns = array_keys(ProtocolMatrix::COLUMNS);
            $io->writeln('Passed / skipped / failed, per server and protocol era:');
            $io->table(
                ['Server', ...array_map(static fn (string $c): string => \sprintf('%s (%s)', $c, ProtocolMatrix::COLUMNS[$c]->value), $columns)],
                array_map(
                    static fn (string $server): array => [$server, ...array_map(static fn (string $c): string => $grid[$server][$c] ?? '—', $columns)],
                    array_keys($grid),
                ),
            );
        }

        if ([] !== $negotiated) {
            $io->writeln('Negotiation only, for revisions older than either column:');
            $io->table(['Client', 'Server', 'Settles on', 'Result'], $negotiated);
        }

        if (null !== $raw) {
            $io->writeln(\sprintf('Raw 2026-07-28 probes against %s: %s', $this->modernEndpoint, $raw));
            $io->newLine();
        }

        if ($failed > 0) {
            $io->error(\sprintf('%d check(s) failed.', $failed));

            return Command::FAILURE;
        }

        $io->success('Every check passed.');

        return Command::SUCCESS;
    }

    /**
     * The raw probes are HTTP-only, so they run unless the filters rule out HTTP,
     * the modern era, or name a single server.
     */
    private function shouldRunModern(InputInterface $input): bool
    {
        if ($input->getOption('skip-modern') || 'stdio' === $input->getOption('transport')) {
            return false;
        }

        return null === $input->getArgument('server')
            && \in_array($input->getOption('client'), [null, 'modern'], true);
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
     * @return list<MatrixCell>
     */
    private function cells(InputInterface $input): array
    {
        $server = $input->getArgument('server');
        $client = $input->getOption('client');
        $transport = $input->getOption('transport');

        return array_values(array_filter(
            ProtocolMatrix::cells(),
            static fn (MatrixCell $cell): bool => (null === $server || $server === $cell->server)
                && (null === $client || $client === $cell->client)
                && (null === $transport || $transport === $cell->transport()),
        ));
    }
}
