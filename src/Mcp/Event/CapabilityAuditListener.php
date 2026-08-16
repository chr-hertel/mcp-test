<?php

declare(strict_types=1);

namespace App\Mcp\Event;

use Mcp\Event\PromptListChangedEvent;
use Mcp\Event\ResourceListChangedEvent;
use Mcp\Event\ResourceTemplateListChangedEvent;
use Mcp\Event\ToolListChangedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The SDK dispatches a marker event whenever a capability list changes.
 *
 * The bundle wires Symfony's dispatcher into the server for you, so listening is
 * a plain `#[AsEventListener]`. The events carry no payload — they say *that*
 * the tool list changed, not what changed — which is exactly what a server needs
 * to decide whether to send `notifications/tools/list_changed` to its clients.
 *
 * Here they are only counted and logged, which makes the ordering visible:
 * every registration fires one, including the ones the compiler pass generated
 * and the ones {@see \App\Mcp\Loader\HouseKeepingLoader} adds at runtime.
 */
final class CapabilityAuditListener
{
    /**
     * @var array<string, int>
     */
    private array $counts = ['tools' => 0, 'prompts' => 0, 'resources' => 0, 'resource_templates' => 0];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    #[AsEventListener]
    public function onToolListChanged(ToolListChangedEvent $event): void
    {
        ++$this->counts['tools'];
    }

    #[AsEventListener]
    public function onPromptListChanged(PromptListChangedEvent $event): void
    {
        ++$this->counts['prompts'];
    }

    #[AsEventListener]
    public function onResourceListChanged(ResourceListChangedEvent $event): void
    {
        ++$this->counts['resources'];
    }

    #[AsEventListener]
    public function onResourceTemplateListChanged(ResourceTemplateListChangedEvent $event): void
    {
        ++$this->counts['resource_templates'];
        $this->logger->debug('MCP capability lists changed.', $this->counts);
    }

    /**
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        return $this->counts;
    }
}
