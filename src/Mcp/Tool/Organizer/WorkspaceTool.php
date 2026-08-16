<?php

declare(strict_types=1);

namespace App\Mcp\Tool\Organizer;

use App\Entity\Proposal;
use App\Enum\Level;
use App\Enum\Track;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;

/**
 * The **roots** client capability: the server asks the client which directories
 * it is allowed to look at, instead of guessing or being configured with paths.
 *
 * Roots were deprecated in protocol revision 2026-07-28 (SEP-2577) in favour of
 * passing paths through tool arguments; hosts including Claude Desktop still
 * advertise them, so the round trip is worth showing.
 *
 * On the Symfony side this needs a client that actually answers `roots/list`.
 * The bundle grew a `clients.<name>.roots` option for that in
 * patches/mcp-bundle/0001-client-roots-option.patch — see docs/patches.md.
 */
final class WorkspaceTool
{
    private const MAX_FILES = 25;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Ask the client which workspace folders it exposes.
     *
     * @return array{status: string, message: string, roots: list<array{uri: string, name: string|null}>}
     */
    #[McpTool(
        name: 'list_client_roots',
        title: 'List the client workspace roots',
        description: 'Ask the connected MCP client which filesystem roots it exposes to this server.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: true),
    )]
    public function listRoots(RequestContext $context): array
    {
        $client = $context->getClientGateway();

        if (!$client->supportsRoots()) {
            return [
                'status' => 'unsupported',
                'message' => 'This client does not advertise the "roots" capability, so the server cannot discover its workspace folders. Pass paths as tool arguments instead.',
                'roots' => [],
            ];
        }

        $roots = [];
        foreach ($client->listRoots()->roots as $root) {
            $roots[] = ['uri' => $root->uri, 'name' => $root->name];
        }

        $client->log(LoggingLevel::Info, \sprintf('Client exposed %d root(s).', \count($roots)));

        return [
            'status' => 'ok',
            'message' => \sprintf('The client exposes %d root(s).', \count($roots)),
            'roots' => $roots,
        ];
    }

    /**
     * Import proposals from `*.proposal.md` files found in the client's roots.
     *
     * Each file's first Markdown heading becomes the title, the rest the
     * abstract. Files whose title already exists are skipped, which keeps the
     * tool safe to re-run.
     *
     * @return array{status: string, message: string, scanned: int, imported: list<string>, skipped: list<string>}
     */
    #[McpTool(
        name: 'import_proposals_from_roots',
        title: 'Import proposals from the workspace',
        description: 'Scan the client workspace roots for "*.proposal.md" files and import each as a CFP proposal.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true),
    )]
    public function importFromRoots(RequestContext $context): array
    {
        $client = $context->getClientGateway();

        if (!$client->supportsRoots()) {
            return [
                'status' => 'unsupported',
                'message' => 'This client does not expose workspace roots, so there is nothing to scan.',
                'scanned' => 0,
                'imported' => [],
                'skipped' => [],
            ];
        }

        $files = [];
        foreach ($client->listRoots()->roots as $root) {
            $path = $this->localPath($root->uri);

            if (null === $path || !is_dir($path)) {
                continue;
            }

            foreach ($this->findProposalFiles($path) as $file) {
                $files[] = $file;

                if (\count($files) >= self::MAX_FILES) {
                    break 2;
                }
            }
        }

        $imported = [];
        $skipped = [];
        $repository = $this->entityManager->getRepository(Proposal::class);

        foreach ($files as $index => $file) {
            $client->progress(($index + 1) / max(1, \count($files)), 1.0, basename($file));

            $contents = @file_get_contents($file);
            if (false === $contents) {
                $skipped[] = basename($file).' (unreadable)';
                continue;
            }

            [$title, $abstract] = $this->parse($contents, basename($file));

            if (null !== $repository->findOneBy(['title' => $title])) {
                $skipped[] = $title.' (already submitted)';
                continue;
            }

            $this->entityManager->persist(new Proposal(
                title: $title,
                abstract: $abstract,
                speakerName: 'Imported from workspace',
                speakerEmail: 'cfp@example.com',
                track: Track::Backend,
                level: Level::Intermediate,
            ));
            $imported[] = $title;
        }

        $this->entityManager->flush();

        return [
            'status' => 'ok',
            'message' => \sprintf('Scanned %d file(s), imported %d.', \count($files), \count($imported)),
            'scanned' => \count($files),
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    /**
     * Roots are URIs; only `file://` ones can be read by a server on this host.
     */
    private function localPath(string $uri): ?string
    {
        if (!str_starts_with($uri, 'file://')) {
            return null;
        }

        return rawurldecode(substr($uri, 7));
    }

    /**
     * @return list<string>
     */
    private function findProposalFiles(string $directory): array
    {
        $found = glob(rtrim($directory, '/').'/*.proposal.md') ?: [];
        sort($found);

        return array_values($found);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parse(string $contents, string $fallbackTitle): array
    {
        $lines = preg_split('/\R/', trim($contents)) ?: [];
        $title = $fallbackTitle;

        if ([] !== $lines && str_starts_with($lines[0], '#')) {
            $title = trim(ltrim(array_shift($lines), '# '));
        }

        $abstract = trim(implode("\n", $lines));

        return [$title, '' === $abstract ? 'No abstract supplied.' : $abstract];
    }
}
