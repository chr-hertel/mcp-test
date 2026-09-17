<?php

declare(strict_types=1);

namespace App\Chat;

use App\Chat\Platform\HostPlatform;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Enum\Role;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Chat\ManagedStoreInterface;
use Symfony\AI\Chat\MessageStoreInterface;
use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * One conversation, and the three ways an MCP host feeds it.
 *
 * A host is not only an agent with tools. The protocol splits what reaches a
 * model by who decided it should: **tools** are the model's to call, **prompts**
 * are the user's to pick, and **resources** are the user's to attach. All three
 * end up in the same message bag, and this class is where each of them enters:
 *
 * - {@see ask()}       the user types, the model decides which tools to call;
 * - {@see runPrompt()} the user picks a server-defined prompt, the server fills
 *                      it with live data, and the result becomes the turn;
 * - {@see attach()}    the user attaches a resource, which is context for the
 *                      next question rather than a question of its own.
 *
 * The bag it keeps is the agent's own: the tool call messages the agent appends
 * while working stay in it, which is what the chat renders as the trail of MCP
 * calls under each answer, and what the model sees as history on the next turn.
 */
final class ChatHost
{
    /**
     * Enough of a resource to be useful as context without flooding the window.
     */
    private const ATTACHMENT_LIMIT = 4000;

    public function __construct(
        private readonly AgentInterface $agent,
        private readonly MessageStoreInterface&ManagedStoreInterface $store,
        private readonly McpClientInterface $servers,
        private readonly HostPlatform $platform,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The conversation, flattened into what the page shows.
     *
     * @return list<array{kind: string, text: string, server: string|null, tool: string|null, arguments: array<string, mixed>}>
     */
    public function transcript(): array
    {
        $entries = [];

        foreach ($this->store->load()->getMessages() as $message) {
            if ($message instanceof UserMessage) {
                $entries[] = $this->entry('user', $message->asText() ?? '');

                continue;
            }

            if ($message instanceof ToolCallMessage) {
                $call = $message->getToolCall();
                [$server, $tool] = $this->split($call->getName());

                $entries[] = $this->entry('tool', $message->asText() ?? '', $server, $tool, $call->getArguments());

                continue;
            }

            if ($message instanceof AssistantMessage) {
                $text = $message->asText();

                // The message that only *requests* tools carries no prose; the
                // requests themselves are shown as the tool entries above.
                if (null !== $text && '' !== trim($text)) {
                    $entries[] = $this->entry('assistant', trim($text));
                }
            }
        }

        return $entries;
    }

    public function ask(string $question): void
    {
        $question = trim($question);

        if ('' === $question) {
            return;
        }

        $messages = $this->store->load();
        $messages->add(Message::ofUser($question));

        $this->run($messages);
    }

    /**
     * Runs a prompt the server defines, with the arguments the user filled in.
     *
     * `prompts/get` is a server-side render: the server reads its own data and
     * hands back the messages to start from. That is the whole point of prompts
     * over a canned string in this application — the abstracts the model is
     * calibrated against are the ones currently in the database.
     *
     * @param array<string, string> $arguments
     */
    public function runPrompt(string $server, string $prompt, array $arguments = []): void
    {
        if (!$this->servers->has($server)) {
            return;
        }

        $messages = $this->store->load();

        try {
            $result = $this->servers->get($server)->getPrompt($prompt, array_filter($arguments, static fn (string $value): bool => '' !== trim($value)));
        } catch (\Throwable $e) {
            $this->logger->error('Getting a prompt from an MCP server failed.', ['server' => $server, 'prompt' => $prompt, 'exception' => $e]);
            $messages->add(Message::ofAssistant(\sprintf('The **%s** server could not render `%s`: %s', $server, $prompt, $e->getMessage())));
            $this->store->save($messages);

            return;
        }

        foreach ($result->messages as $message) {
            $text = $this->textOf($message->content);

            if (null === $text) {
                continue;
            }

            $messages->add(Role::Assistant === $message->role ? Message::ofAssistant($text) : Message::ofUser($text));
        }

        $this->run($messages);
    }

    /**
     * Attaches a resource as context, without asking anything.
     *
     * A resource is the user's to hand over — the model never reaches for one on
     * its own here, which is exactly the difference between a resource and a
     * tool. It lands in the bag and colours every following turn.
     */
    public function attach(string $server, string $uri): void
    {
        if (!$this->servers->has($server)) {
            return;
        }

        $messages = $this->store->load();

        try {
            $result = $this->servers->get($server)->readResource($uri);
        } catch (\Throwable $e) {
            $this->logger->error('Reading a resource from an MCP server failed.', ['server' => $server, 'uri' => $uri, 'exception' => $e]);
            $messages->add(Message::ofAssistant(\sprintf('The **%s** server could not read `%s`: %s', $server, $uri, $e->getMessage())));
            $this->store->save($messages);

            return;
        }

        $parts = [];
        foreach ($result->contents as $contents) {
            if ($contents instanceof TextResourceContents) {
                $parts[] = $contents->text;
            }
        }

        $text = trim(implode("\n\n", $parts));

        if ('' === $text) {
            $text = '(the resource carries no text)';
        }

        if (mb_strlen($text) > self::ATTACHMENT_LIMIT) {
            $text = mb_substr($text, 0, self::ATTACHMENT_LIMIT)."\n…";
        }

        $messages->add(Message::ofUser(\sprintf("Context from the MCP resource `%s` on the %s server:\n\n%s", $uri, $server, $text)));

        $this->store->save($messages);
    }

    public function reset(): void
    {
        $this->store->drop();
    }

    /**
     * Which model is answering — the chat says so, because with the stand-in the
     * answers mean something different.
     *
     * @return array{platform: string, model: string, scripted: bool}
     */
    public function model(): array
    {
        return [
            'platform' => $this->platform->getName(),
            'model' => $this->platform->getModel(),
            'scripted' => $this->platform->isScripted(),
        ];
    }

    /**
     * One turn: the agent runs, appending its tool calls to the same bag, and
     * whatever it ends up saying is appended as the answer.
     */
    private function run(MessageBag $messages): void
    {
        try {
            $result = $this->agent->call($messages)->getResult();
            $messages->add(Message::ofAssistant($result));
        } catch (\Throwable $e) {
            // A tool that failed, a server that is down, a model that refused:
            // the conversation says so and stays usable.
            $this->logger->error('A chat turn failed.', ['exception' => $e]);
            $messages->add(Message::ofAssistant(\sprintf('That turn did not finish: %s', $e->getMessage())));
        } finally {
            $this->store->save($messages);

            // The connections are child processes. A turn is over, so they can
            // go; the next one reopens them transparently.
            $this->servers->disconnect();
        }
    }

    private function textOf(object $content): ?string
    {
        if ($content instanceof TextContent) {
            // The SDK types `text` as mixed: a server may put structured data there.
            if (\is_string($content->text)) {
                return $content->text;
            }

            $encoded = json_encode($content->text, \JSON_UNESCAPED_SLASHES);

            return false === $encoded ? null : $encoded;
        }

        if ($content instanceof EmbeddedResource && $content->resource instanceof TextResourceContents) {
            return $content->resource->text;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{kind: string, text: string, server: string|null, tool: string|null, arguments: array<string, mixed>}
     */
    private function entry(string $kind, string $text, ?string $server = null, ?string $tool = null, array $arguments = []): array
    {
        return ['kind' => $kind, 'text' => $text, 'server' => $server, 'tool' => $tool, 'arguments' => $arguments];
    }

    /**
     * @return array{string, string}
     */
    private function split(string $name): array
    {
        $position = strpos($name, '_');

        return false === $position ? ['unknown', $name] : [substr($name, 0, $position), substr($name, $position + 1)];
    }
}
