<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use Mcp\Client\Handler\Request\RootsCallbackInterface;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\ListRootsResult;
use Mcp\Schema\Root;

/**
 * Answers `roots/list` when a remote server asks this application's MCP client
 * which directories it may look at.
 *
 * This is the client half of {@see \App\Mcp\Tool\Organizer\WorkspaceTool}: the
 * demo talks to itself, so the same process plays both sides of the round trip.
 *
 * Wired through `mcp.clients.regression.roots`, an option the upstream bundle
 * did not have — see patches/mcp-bundle/0001-client-roots-option.patch.
 */
final class WorkspaceRootsProvider implements RootsCallbackInterface
{
    /**
     * @param string $projectDir   the application root, exposed read-only
     * @param string $workspaceDir a scratch directory the import tool reads proposals from
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly string $workspaceDir,
    ) {
    }

    public function __invoke(ListRootsRequest $request): ListRootsResult
    {
        $roots = [new Root(uri: 'file://'.$this->projectDir, name: 'mcp-demo')];

        // Only advertise the inbox once it exists; a root pointing at nothing is
        // worse than no root at all, since the server cannot tell the difference.
        if (is_dir($this->workspaceDir)) {
            $roots[] = new Root(uri: 'file://'.$this->workspaceDir, name: 'cfp-inbox');
        }

        return new ListRootsResult($roots);
    }
}
