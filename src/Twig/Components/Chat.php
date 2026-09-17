<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Chat\ChatHost;
use App\Chat\Mcp\HostSurface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The chat itself, as a Live Component.
 *
 * A turn is one blocking round trip — the agent calls a tool, the tool is an MCP
 * server this application spawns as a child process, and the page waits for all
 * of it. Live Components are what make that bearable without a line of custom
 * JavaScript: the form posts to {@see submit()}, the transcript re-renders from
 * the session, and `data-loading` turns the composer into a progress indicator
 * while the round trip is in flight.
 *
 * Prompts are deliberately *not* driven from here. Their arguments differ per
 * prompt and want the server's own completions, so they get a plain form of
 * their own — see {@see \App\Controller\ChatController::prompt()}.
 */
#[AsLiveComponent('chat')]
final class Chat
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $message = '';

    public function __construct(
        private readonly ChatHost $chat,
        private readonly HostSurface $surface,
    ) {
    }

    /**
     * @return list<array{kind: string, text: string, server: string|null, tool: string|null, arguments: array<string, mixed>}>
     */
    public function getTranscript(): array
    {
        return $this->chat->transcript();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getServers(): array
    {
        return $this->surface->servers();
    }

    /**
     * @return array{platform: string, model: string, scripted: bool}
     */
    public function getModel(): array
    {
        return $this->chat->model();
    }

    #[LiveAction]
    public function submit(): void
    {
        $question = $this->message;
        $this->message = '';

        $this->chat->ask($question);
    }

    /**
     * Attaching a resource asks nothing: it puts the server's own document into
     * the conversation, for the next question to build on.
     */
    #[LiveAction]
    public function attach(#[LiveArg] string $server, #[LiveArg] string $uri): void
    {
        $this->chat->attach($server, $uri);
    }

    #[LiveAction]
    public function reset(): void
    {
        $this->chat->reset();
        $this->message = '';
    }
}
