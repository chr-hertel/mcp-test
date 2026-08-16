<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the `claude_desktop_config.json` fragment that connects Claude Desktop
 * to this application's STDIO servers.
 *
 * Generated rather than documented because the paths have to be absolute — a
 * desktop app launches the server from its own working directory, with its own
 * environment, and a relative path in that file fails silently.
 */
#[AsCommand(
    name: 'app:claude-desktop:config',
    description: 'Print the Claude Desktop configuration for this application\'s MCP servers.',
)]
final class ClaudeDesktopConfigCommand extends Command
{
    /**
     * The servers that make sense in a general-purpose host. `diagnostics` is
     * left out: it is HTTP-only, and its tools exist to fail on purpose.
     */
    private const SERVERS = [
        'symfonycon-programme' => 'conference',
        'symfonycon-organizer' => 'organizer',
    ];

    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('app-env', null, InputOption::VALUE_REQUIRED, 'The APP_ENV the generated servers run in.', 'prod')
            // PHP_BINARY names the interpreter this command runs under, which is
            // almost always the right one — a desktop app has a different PATH.
            ->addOption('php', null, InputOption::VALUE_REQUIRED, 'The PHP binary Claude Desktop should launch.', \PHP_BINARY)
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Emit only one server ("conference" or "organizer").')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print only the JSON, with no surrounding prose.')
            ->setHelp(<<<'HELP'
                Prints the fragment to merge into Claude Desktop's configuration file:

                    Linux    ~/.config/Claude/claude_desktop_config.json
                    macOS    ~/Library/Application Support/Claude/claude_desktop_config.json
                    Windows  %APPDATA%\Claude\claude_desktop_config.json

                Merge it into the existing "mcpServers" object rather than replacing the file,
                then restart Claude Desktop completely — it only reads the file at startup.

                    <info>php %command.full_name% --json</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $env = (string) $input->getOption('app-env');
        $only = $input->getOption('only');

        $servers = [];

        foreach (self::SERVERS as $label => $name) {
            if (null !== $only && $only !== $name) {
                continue;
            }

            $servers[$label] = [
                'command' => (string) $input->getOption('php'),
                'args' => [$this->projectDir.'/bin/console', 'mcp:server', $name],
                'env' => [
                    'APP_ENV' => $env,
                    // Claude Desktop launches the server from its own working
                    // directory; a relative DATABASE_URL would resolve elsewhere.
                    'DATABASE_URL' => \sprintf('sqlite:///%s/var/data_%s.db', $this->projectDir, $env),
                ],
            ];
        }

        if ([] === $servers) {
            $io->error(\sprintf('Unknown server "%s". Choose one of: %s.', $only, implode(', ', self::SERVERS)));

            return Command::INVALID;
        }

        $json = json_encode(
            ['mcpServers' => $servers],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );

        if ($input->getOption('json')) {
            $output->writeln((string) $json);

            return Command::SUCCESS;
        }

        $io->title('Claude Desktop configuration');
        $io->text([
            'Merge this into the "mcpServers" object of your Claude Desktop configuration file,',
            'then quit and reopen Claude Desktop — it reads the file only at startup.',
        ]);
        $io->newLine();
        $io->writeln((string) $json);
        $io->newLine();

        $io->section('Where the file lives');
        $io->listing([
            'Linux:   ~/.config/Claude/claude_desktop_config.json',
            'macOS:   ~/Library/Application Support/Claude/claude_desktop_config.json',
            'Windows: %APPDATA%\\Claude\\claude_desktop_config.json',
        ]);

        $io->section('Before you connect');
        $io->listing([
            \sprintf('Build the "%s" database: <info>APP_ENV=%s php bin/console doctrine:schema:create && APP_ENV=%s php bin/console doctrine:fixtures:load -n</info>', $env, $env, $env),
            \sprintf('Warm the cache once, so the first tool call is not a compile: <info>APP_ENV=%s php bin/console cache:warmup</info>', $env),
            'Check the server starts and speaks: <info>php bin/console mcp:client:debug regression conference_stdio</info>',
        ]);

        $io->note('The "symfonycon-organizer" server can change the programme. It is unauthenticated over STDIO — the token only guards the HTTP endpoint — so leave it out if you would rather Claude could not reschedule anything.');

        return Command::SUCCESS;
    }
}
