<?php

declare(strict_types=1);

namespace App\Chat\Platform;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Tool\Tool;

/**
 * A model that is not a model: it answers from the MCP servers alone.
 *
 * The chat has to work with no account anywhere — that is what keeps the whole
 * client path inside `make check`, and it is the same reason
 * {@see \App\Mcp\Client\ScriptedSamplingHandler} exists on the server side. So
 * this stands in for one, over two rounds of the agent's tool loop:
 *
 * 1. asked a question, it picks one tool from what the servers advertised
 *    ({@see ToolChoice}) and calls it;
 * 2. handed the result, it reports what came back, verbatim.
 *
 * It invents nothing, which is the honest part: what you read in the chat is
 * what the server sent. It also understands nothing, which is the limit — set
 * `MCP_DEMO_CHAT_PLATFORM` to `anthropic` or `openai` for a model that does.
 *
 * What it does prove is everything between the two: that the agent's tool loop,
 * the MCP toolbox, the bundle's client, a spawned STDIO server and this
 * application's own tools all still fit together.
 */
final class ScriptedPlatform implements PlatformInterface
{
    public const MODEL = 'scripted-host-1';

    /**
     * Enough of a tool result to read in a chat bubble; the whole of it stays in
     * the message bag either way.
     */
    private const RESULT_LIMIT = 1400;

    private readonly ModelCatalogInterface $modelCatalog;

    public function __construct(
        private readonly ToolChoice $choice = new ToolChoice(),
    ) {
        // Any model name is accepted: the name is decorative here, and the chat
        // shows it as such.
        $this->modelCatalog = new FallbackModelCatalog();
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $messages = $input instanceof MessageBag ? $input : new MessageBag(Message::ofUser(\is_string($input) ? $input : ''));

        $result = $this->turn($messages, $this->tools($options));

        return new DeferredResult(
            new PlainConverter($result),
            // Nothing was sent anywhere, so there is no raw response to carry;
            // the marker keeps the profiler honest about where this came from.
            new InMemoryRawResult(['model' => self::MODEL]),
            $options,
        );
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->modelCatalog;
    }

    /**
     * @param list<Tool> $tools
     */
    private function turn(MessageBag $messages, array $tools): ResultInterface
    {
        $calls = $this->callsOfTurn($messages);

        // The tools of this turn already ran: this round is the answer, and the
        // loop ends because it asks for no further call.
        if ([] !== $calls) {
            return new TextResult($this->report($calls));
        }

        $question = $this->question($messages);

        if (null === $question) {
            return new TextResult('Ask me something about the programme.');
        }

        $call = $this->choice->choose($question, $tools);

        return null === $call ? new TextResult($this->offer($tools)) : new ToolCallResult([$call]);
    }

    /**
     * The tool calls made since the last thing the user said.
     *
     * @return list<ToolCallMessage>
     */
    private function callsOfTurn(MessageBag $messages): array
    {
        $calls = [];

        foreach ($messages->getMessages() as $message) {
            if ($message instanceof UserMessage) {
                $calls = [];

                continue;
            }

            if ($message instanceof ToolCallMessage) {
                $calls[] = $message;
            }
        }

        return $calls;
    }

    private function question(MessageBag $messages): ?string
    {
        $messages = $messages->getMessages();

        foreach (array_reverse($messages) as $message) {
            \assert($message instanceof MessageInterface);

            if ($message instanceof UserMessage) {
                $text = $message->asText();

                return null !== $text && '' !== trim($text) ? trim($text) : null;
            }
        }

        return null;
    }

    /**
     * @param list<ToolCallMessage> $calls
     */
    private function report(array $calls): string
    {
        $parts = [];

        foreach ($calls as $call) {
            $toolCall = $call->getToolCall();
            [$server, $tool] = $this->split($toolCall->getName());

            $parts[] = \sprintf(
                "`%s` on the **%s** server answered%s:\n\n%s",
                $tool,
                $server,
                [] === $toolCall->getArguments() ? '' : ' to '.$this->arguments($toolCall->getArguments()),
                $this->body($call->asText() ?? ''),
            );
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function arguments(array $arguments): string
    {
        $pairs = [];

        foreach ($arguments as $name => $value) {
            $pairs[] = \sprintf('`%s: %s`', $name, \is_scalar($value) ? (string) $value : json_encode($value, \JSON_UNESCAPED_SLASHES));
        }

        return implode(', ', $pairs);
    }

    /**
     * Structured content arrives as JSON, so it is shown as JSON — indented,
     * because a chat bubble is not a log line. A server that answered in prose
     * is left as prose.
     *
     * Nothing is summarised away: the trail under the answer holds the bytes as
     * they arrived, and this is the same payload with whitespace in it.
     */
    private function body(string $result): string
    {
        $result = trim($result);

        if ('' === $result) {
            return '_The server answered with nothing._';
        }

        $decoded = json_decode($result, true);
        $json = \is_array($decoded);

        if ($json) {
            $encoded = json_encode($decoded, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $result = false === $encoded ? $result : $encoded;
        }

        if (mb_strlen($result) > self::RESULT_LIMIT) {
            $result = mb_substr($result, 0, self::RESULT_LIMIT).\sprintf('… (%d more characters)', mb_strlen($result) - self::RESULT_LIMIT);
        }

        return $json ? "```json\n".$result."\n```" : $result;
    }

    /**
     * What to say when nothing matched: the tools themselves, which is more
     * useful than an apology and keeps the servers' vocabulary in front of the
     * user.
     *
     * @param list<Tool> $tools
     */
    private function offer(array $tools): string
    {
        if ([] === $tools) {
            return 'No MCP server is answering right now, so I have nothing to work with. '
                .'Check `bin/console debug:mcp --client=host`.';
        }

        $byServer = [];
        foreach ($tools as $tool) {
            [$server, $name] = $this->split($tool->getName());
            $byServer[$server][$name] = true;
        }

        $lines = [];
        foreach ($byServer as $server => $names) {
            $lines[] = \sprintf('- **%s**: %s', $server, implode(', ', array_map(
                static fn (string $name): string => '`'.$name.'`',
                array_keys($names),
            )));
        }

        return "I could not match that to a tool, and I only know what the servers tell me. "
            ."These are the tools I can reach:\n\n".implode("\n", $lines);
    }

    /**
     * The toolbox prefixes every remote tool with the connection it came from.
     *
     * @return array{string, string}
     */
    private function split(string $name): array
    {
        $position = strpos($name, '_');

        return false === $position ? ['unknown', $name] : [substr($name, 0, $position), substr($name, $position + 1)];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<Tool>
     */
    private function tools(array $options): array
    {
        return array_values(array_filter(
            \is_array($options['tools'] ?? null) ? $options['tools'] : [],
            static fn (mixed $tool): bool => $tool instanceof Tool,
        ));
    }
}
