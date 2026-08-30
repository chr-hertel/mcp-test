<?php

declare(strict_types=1);

namespace App\Mcp\Tool\Modern;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;

/**
 * A tool that changes the server's tool list, so `subscriptions/listen` has
 * something real to carry.
 *
 * The notification is the point, not the tool it announces: it goes to the bus,
 * which outlives the request, so a stream held open by *another* worker sees it.
 * The registered tool does not — the registry is rebuilt per build, and
 * 2026-07-28 keeps no session to remember it in.
 *
 * Exposed only by the `modern` server — see config/packages/mcp.yaml.
 */
final class RegistryChangeTool
{
    public function __construct(private readonly RegistryInterface $registry)
    {
    }

    /**
     * Register a throwaway tool, announcing a `notifications/tools/list_changed`.
     *
     * @param string $name echoed back, so a caller can match the notification to its cause
     *
     * @return array{registered: string, announced: string}
     */
    #[McpTool(
        name: 'announce_tool',
        description: 'Register a throwaway tool on this server, so subscribers receive a tools/list_changed notification.',
        annotations: new ToolAnnotations(title: 'Announce a tool', readOnlyHint: false, idempotentHint: false),
    )]
    public function announceTool(string $name = 'ephemeral_probe'): array
    {
        $this->registry->registerTool(
            new Tool(
                $name,
                'Ephemeral probe',
                ['type' => 'object', 'properties' => new \stdClass()],
                'Registered at runtime to provoke a list-changed notification. Gone with the request.',
                null,
            ),
            static fn (): string => 'ok',
        );

        return [
            'registered' => $name,
            'announced' => 'notifications/tools/list_changed',
        ];
    }
}
