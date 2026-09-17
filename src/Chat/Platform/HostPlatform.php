<?php

declare(strict_types=1);

namespace App\Chat\Platform;

use Psr\Container\ContainerInterface;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
/**
 * The one platform the chat agent talks to, in front of the three it can be.
 *
 * `ai.agent.host` is configured against this class rather than against
 * `ai.platform.anthropic` directly, because which model answers is a deployment
 * question here, not a wiring one: the demo has to run with no API key at all,
 * and a page that dies on a missing environment variable would make that the
 * first thing a reader debugs.
 *
 * `MCP_DEMO_CHAT_PLATFORM` decides, and the locator keeps the other two
 * uninstantiated — a platform is only built once it is the one being asked.
 */
final class HostPlatform implements PlatformInterface
{
    public const SCRIPTED = 'scripted';

    private const PLATFORMS = [self::SCRIPTED, 'anthropic', 'openai'];

    /**
     * @param ContainerInterface $platforms the three candidates, lazily — the
     *                                      locator is wired in config/services.yaml,
     *                                      because two of the three are the AI
     *                                      bundle's own service ids
     */
    public function __construct(
        private readonly ContainerInterface $platforms,
        private readonly string $platform = self::SCRIPTED,
        private readonly string $model = ScriptedPlatform::MODEL,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        // The agent hands over the model it was configured with; the selector
        // overrules it, because the configured one belongs to whichever platform
        // was picked and the agent cannot know which that is.
        return $this->platform()->invoke($this->getModel(), $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->platform()->getModelCatalog();
    }

    /**
     * Which of the three is answering, for the badge in the chat header.
     */
    public function getName(): string
    {
        return $this->platform;
    }

    public function getModel(): string
    {
        return trim($this->model);
    }

    /**
     * Whether answers come from a real model, which is the one thing a reader of
     * the page needs to be told without reading the configuration.
     */
    public function isScripted(): bool
    {
        return self::SCRIPTED === $this->platform;
    }

    private function platform(): PlatformInterface
    {
        if (!$this->platforms->has($this->platform)) {
            throw new RuntimeException(\sprintf('Unknown chat platform "%s", set MCP_DEMO_CHAT_PLATFORM to one of: %s.', $this->platform, implode(', ', self::PLATFORMS)));
        }

        // The two variables move together, and forgetting the second one would
        // otherwise surface as a model catalog error from deep inside a bridge.
        if (!$this->isScripted() && ScriptedPlatform::MODEL === $this->getModel()) {
            throw new RuntimeException(\sprintf('The chat platform is "%s", but MCP_DEMO_CHAT_MODEL still names the scripted stand-in. Set it to a model of that platform.', $this->platform));
        }

        $platform = $this->platforms->get($this->platform);
        \assert($platform instanceof PlatformInterface);

        return $platform;
    }
}
