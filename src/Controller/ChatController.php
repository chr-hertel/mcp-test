<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\ChatHost;
use App\Chat\Mcp\HostSurface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The chat host: this application driving a model against its own MCP servers.
 *
 * The conversation itself is a Live Component ({@see \App\Twig\Components\Chat});
 * what is left for a controller is the page around it and the one thing a Live
 * Component is the wrong tool for — running a server-defined prompt, whose
 * arguments are different for every prompt and are best filled with the values
 * the server itself suggests through `completion/complete`.
 */
final class ChatController extends AbstractController
{
    public function __construct(
        private readonly ChatHost $chat,
        private readonly HostSurface $surface,
    ) {
    }

    #[Route('/chat', name: 'chat')]
    public function index(): Response
    {
        return $this->render('chat/index.html.twig');
    }

    /**
     * The form for one prompt, and the run of it.
     *
     * Every argument gets a `<datalist>` of what the server suggests for it,
     * which is what `completion/complete` is for: a prompt argument is a string
     * on the wire, and without completion the user would have to know the slugs.
     */
    #[Route('/chat/prompt/{server}/{name}', name: 'chat_prompt', requirements: ['server' => '[a-z0-9_-]+', 'name' => '[a-z0-9_-]+'], methods: ['GET', 'POST'])]
    public function prompt(Request $request, string $server, string $name): Response
    {
        $definition = $this->definition($server, $name);

        if (null === $definition) {
            throw $this->createNotFoundException(\sprintf('No prompt "%s" on server "%s".', $name, $server));
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('chat-prompt', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $arguments = [];
            foreach ($definition['arguments'] as $argument) {
                $arguments[$argument['name']] = trim((string) $request->request->get('argument_'.$argument['name'], ''));
            }

            $this->chat->runPrompt($server, $name, $arguments);

            return $this->redirectToRoute('chat');
        }

        $completions = [];
        foreach ($definition['arguments'] as $argument) {
            $completions[$argument['name']] = $this->surface->complete($server, $name, $argument['name']);
        }

        return $this->render('chat/prompt.html.twig', [
            'server' => $server,
            'prompt' => $definition,
            'completions' => $completions,
        ]);
    }

    /**
     * @return array{name: string, title: string|null, description: string|null, arguments: list<array{name: string, description: string|null, required: bool}>}|null
     */
    private function definition(string $server, string $name): ?array
    {
        foreach ($this->surface->servers() as $connected) {
            if ($connected['name'] !== $server) {
                continue;
            }

            foreach ($connected['prompts'] as $prompt) {
                if ($prompt['name'] === $name) {
                    return $prompt;
                }
            }
        }

        return null;
    }
}
