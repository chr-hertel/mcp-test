<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Platform\HostPlatform;
use App\Chat\Platform\ScriptedPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Which model the chat talks to, and what happens when the two environment
 * variables behind that disagree.
 */
final class HostPlatformTest extends TestCase
{
    public function testTheConfiguredPlatformAnswersAndTheOthersAreNeverBuilt(): void
    {
        $built = [];
        $platform = new HostPlatform($this->locator($built), 'anthropic', 'claude-sonnet-4-5');

        $result = $platform->invoke('ignored-by-the-agent', new MessageBag(Message::ofUser('hello')))->asText();

        self::assertSame('answered by the remote platform', $result);
        self::assertSame(['anthropic'], $built, 'Only the selected platform is instantiated.');
        self::assertFalse($platform->isScripted());
        self::assertSame('claude-sonnet-4-5', $platform->getModel());
    }

    public function testTheScriptedStandInIsTheDefault(): void
    {
        $built = [];
        $platform = new HostPlatform($this->locator($built), HostPlatform::SCRIPTED, ScriptedPlatform::MODEL);

        self::assertTrue($platform->isScripted());
        self::assertSame(ScriptedPlatform::MODEL, $platform->getModel());
        self::assertStringContainsString('No MCP server is answering', $platform->invoke('x', new MessageBag(Message::ofUser('hello')))->asText());
    }

    public function testAModelThatBelongsToAnotherPlatformIsRefusedWithAnExplanation(): void
    {
        $built = [];
        // The mistake this catches: switching MCP_DEMO_CHAT_PLATFORM and
        // forgetting MCP_DEMO_CHAT_MODEL, which a bridge would otherwise report
        // as an unknown model from three layers down.
        $platform = new HostPlatform($this->locator($built), 'openai', ScriptedPlatform::MODEL);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MCP_DEMO_CHAT_MODEL still names the scripted stand-in');

        $platform->invoke('x', new MessageBag(Message::ofUser('hello')));
    }

    public function testAnUnknownPlatformNamesTheOnesThatExist(): void
    {
        $built = [];
        $platform = new HostPlatform($this->locator($built), 'gemini', 'gemini-3-pro');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown chat platform "gemini"');

        $platform->invoke('x', new MessageBag(Message::ofUser('hello')));
    }

    /**
     * @param list<string> $built records which platforms were actually instantiated
     */
    private function locator(array &$built): ServiceLocator
    {
        return new ServiceLocator([
            HostPlatform::SCRIPTED => static function () use (&$built): ScriptedPlatform {
                $built[] = HostPlatform::SCRIPTED;

                return new ScriptedPlatform();
            },
            'anthropic' => static function () use (&$built): InMemoryPlatform {
                $built[] = 'anthropic';

                return new InMemoryPlatform('answered by the remote platform');
            },
            'openai' => static function () use (&$built): InMemoryPlatform {
                $built[] = 'openai';

                return new InMemoryPlatform('answered by the remote platform');
            },
        ]);
    }
}
