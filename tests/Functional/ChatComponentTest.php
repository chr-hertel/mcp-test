<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\McpTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The chat component's actions, without a browser.
 *
 * Live Components are server-side: an action is an HTTP request that re-renders
 * the component, so the interesting half — a question turning into an MCP call
 * and an answer turning into markup — is testable exactly like a controller.
 */
#[Group('regression')]
final class ChatComponentTest extends McpTestCase
{
    use InteractsWithLiveComponents;

    public function testAskingRendersTheAnswerAndTheToolTrail(): void
    {
        self::createClient();

        $component = $this->createLiveComponent('chat');

        $rendered = $component
            ->set('message', 'Which talks are about doctrine?')
            ->call('submit')
            ->render()
            ->toString();

        self::assertStringContainsString('called <b>search_talks</b>', $rendered);
        self::assertStringContainsString('Doctrine Without Tears', $rendered);
    }

    public function testAttachingAResourceAndResetting(): void
    {
        self::createClient();

        $component = $this->createLiveComponent('chat');

        $rendered = $component
            ->call('attach', ['server' => 'conference', 'uri' => 'conference://current'])
            ->render()
            ->toString();

        self::assertStringContainsString('conference://current', $rendered);

        $rendered = $component->call('reset')->render()->toString();

        // Back to the empty state: the attachment is gone from the transcript,
        // while the resource itself is of course still on offer in the sidebar.
        self::assertStringContainsString('Ask about the programme', $rendered);
        self::assertStringNotContainsString('Context from the MCP resource', $rendered);
    }
}
