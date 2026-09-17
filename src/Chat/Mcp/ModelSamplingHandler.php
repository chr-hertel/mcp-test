<?php

declare(strict_types=1);

namespace App\Chat\Mcp;

use App\Chat\Platform\HostPlatform;
use App\Mcp\Client\ScriptedSamplingHandler;
use Mcp\Client\Handler\Request\SamplingCallbackInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * The client half of **sampling**, answered by the host's own model.
 *
 * This is the round trip a host exists for. A server needs a completion —
 * `review_proposal` needs a review written — and rather than holding an API key
 * of its own it asks the client, which already has one. The user's model, the
 * user's account, the user's control.
 *
 * With `MCP_DEMO_CHAT_PLATFORM=scripted` there is no model to ask, so this falls
 * back to {@see ScriptedSamplingHandler}, the same deterministic reviewer the
 * regression client uses — the demo then behaves identically whether the answer
 * came from a model or from a CRC of the title.
 *
 * Note what is *not* here: no tools. A sampling request is a completion, not an
 * agent turn, and handing the servers' tools back to a model the servers asked
 * for would be a loop with no floor.
 */
final class ModelSamplingHandler implements SamplingCallbackInterface
{
    public function __construct(
        private readonly HostPlatform $platform,
        private readonly ScriptedSamplingHandler $scripted,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(CreateSamplingMessageRequest $request): CreateSamplingMessageResult
    {
        if ($this->platform->isScripted()) {
            return ($this->scripted)($request);
        }

        $messages = new MessageBag();

        if (null !== $request->systemPrompt) {
            $messages->add(Message::forSystem($request->systemPrompt));
        }

        foreach ($request->messages as $message) {
            if (!$message->content instanceof TextContent || !\is_string($message->content->text)) {
                // Images and audio would need the platform's content types; a
                // server that sends them gets an answer from the text it sent.
                continue;
            }

            $messages->add(Role::Assistant === $message->role
                ? Message::ofAssistant($message->content->text)
                : Message::ofUser($message->content->text));
        }

        $options = [];
        if ($request->maxTokens > 0) {
            $options['max_tokens'] = $request->maxTokens;
        }

        $this->logger->info('Answering a sampling request with the host model.', [
            'model' => $this->platform->getModel(),
            'messages' => \count($messages),
        ]);

        $result = $this->platform->invoke($this->platform->getModel(), $messages, $options)->asText();

        return new CreateSamplingMessageResult(
            role: Role::Assistant,
            content: new TextContent($result),
            model: $this->platform->getModel(),
            stopReason: 'endTurn',
        );
    }
}
