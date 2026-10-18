<?php

declare(strict_types=1);

namespace App\Chat\Mcp;

use Mcp\Schema\PromptReference;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;

/**
 * What the chat host knows about the servers it is connected to.
 *
 * An agent only ever draws *tools* from an MCP server, but a host is more than
 * its agent: prompts are the user-initiated half of the protocol, and resources
 * are context a user attaches deliberately rather than a model fetching it. The
 * chat shows all three, and this is where they are read.
 *
 * Every listing is cached, because the connections are STDIO: asking four
 * questions of two servers means spawning two PHP processes and booting this
 * application twice, and a chat that did that on every re-render would be a
 * demonstration of the wrong thing. The cache is short — a server that gains a
 * tool is visible within the minute.
 */
final class HostSurface
{
    private const CACHE_KEY = 'app.chat.mcp_surface';
    private const CACHE_TTL = 60;

    public function __construct(
        private readonly McpClientInterface $host,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The connected servers, as the chat renders them.
     *
     * @return list<array{
     *     name: string, title: string|null, instructions: string|null, error: string|null,
     *     tools: list<array{name: string, title: string|null, description: string|null}>,
     *     prompts: list<array{name: string, title: string|null, description: string|null, arguments: list<array{name: string, description: string|null, required: bool}>}>,
     *     resources: list<array{uri: string, name: string, description: string|null, mime_type: string|null, text: bool}>
     * }>
     */
    public function servers(): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);

        if ($item->isHit()) {
            /** @var list<array<string, mixed>> $cached */
            $cached = $item->get();

            // @phpstan-ignore return.type
            return $cached;
        }

        $servers = [];
        foreach ($this->host as $connection) {
            $servers[] = $this->describe($connection);
        }

        $this->cache->save($item->set($servers)->expiresAfter(self::CACHE_TTL));

        return $servers;
    }

    /**
     * The values the server suggests for one prompt argument — `completion/complete`,
     * which is what makes a prompt with a slug argument usable by a human.
     *
     * @return list<string>
     */
    public function complete(string $server, string $prompt, string $argument, string $value = ''): array
    {
        if (!$this->host->has($server)) {
            return [];
        }

        try {
            $result = $this->host->get($server)->complete(
                new PromptReference($prompt),
                ['name' => $argument, 'value' => $value],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Completing a prompt argument failed.', ['server' => $server, 'prompt' => $prompt, 'exception' => $e]);

            return [];
        }

        return array_values($result->values);
    }

    /**
     * @return array{
     *     name: string, title: string|null, instructions: string|null, error: string|null,
     *     tools: list<array{name: string, title: string|null, description: string|null}>,
     *     prompts: list<array{name: string, title: string|null, description: string|null, arguments: list<array{name: string, description: string|null, required: bool}>}>,
     *     resources: list<array{uri: string, name: string, description: string|null, mime_type: string|null, text: bool}>
     * }
     */
    private function describe(ServerConnectionInterface $connection): array
    {
        $server = [
            'name' => $connection->getName(),
            'title' => null,
            'instructions' => null,
            'error' => null,
            'tools' => [],
            'prompts' => [],
            'resources' => [],
        ];

        try {
            foreach ($connection->getTools() as $tool) {
                $server['tools'][] = [
                    'name' => $tool->name,
                    'title' => $tool->title,
                    'description' => $tool->description,
                ];
            }

            foreach ($connection->getPrompts() as $prompt) {
                $arguments = [];
                foreach ($prompt->arguments ?? [] as $argument) {
                    $arguments[] = [
                        'name' => $argument->name,
                        'description' => $argument->description,
                        'required' => true === $argument->required,
                    ];
                }

                $server['prompts'][] = [
                    'name' => $prompt->name,
                    'title' => $prompt->title,
                    'description' => $prompt->description,
                    'arguments' => $arguments,
                ];
            }

            foreach ($connection->getResources() as $resource) {
                $server['resources'][] = [
                    'uri' => $resource->uri,
                    'name' => $resource->name,
                    'description' => $resource->description,
                    'mime_type' => $resource->mimeType,
                    // A badge PNG is a resource like any other; it is just not
                    // something to paste into a conversation.
                    'text' => null === $resource->mimeType || str_starts_with($resource->mimeType, 'text/') || str_contains($resource->mimeType, 'json'),
                ];
            }

            $server['title'] = $connection->getServerInfo()?->title ?? $connection->getServerInfo()?->name;
            $server['instructions'] = $connection->getInstructions();
        } catch (\Throwable $e) {
            // One unreachable server must not take the page down with it — the
            // same rule the toolbox applies to the model's side of the same
            // connection.
            $this->logger->error('Reading the surface of an MCP server failed.', ['server' => $connection->getName(), 'exception' => $e]);
            $server['error'] = $e->getMessage();
        }

        return $server;
    }
}
