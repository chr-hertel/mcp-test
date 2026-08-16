<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use Mcp\Client\Handler\Request\SamplingCallbackInterface;
use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use Psr\Log\LoggerInterface;

/**
 * The client side of **sampling**: a remote server asks this application's MCP
 * client to run a completion on its behalf.
 *
 * In a host like Claude Desktop the user's model answers this. Here the answer
 * is generated from the request itself, deterministically, for two reasons:
 * the demo must run without an API key, and the regression suite needs the same
 * bytes back on every run.
 *
 * Swapping in a real model is a one-class change — implement the same interface
 * over Symfony AI's `Agent`/`Platform` and point
 * `mcp.clients.regression.sampling` at it.
 */
final class ScriptedSamplingHandler implements SamplingCallbackInterface
{
    public const MODEL = 'scripted-reviewer-1';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(CreateSamplingMessageRequest $request): CreateSamplingMessageResult
    {
        $prompt = $this->flatten($request->messages);

        $this->logger->info('Answering a sampling request from a remote MCP server.', [
            'max_tokens' => $request->maxTokens,
            'system_prompt' => $request->systemPrompt,
            'prompt_length' => \strlen($prompt),
        ]);

        return new CreateSamplingMessageResult(
            role: Role::Assistant,
            content: new TextContent($this->answer($prompt)),
            model: self::MODEL,
            stopReason: 'endTurn',
        );
    }

    /**
     * @param list<SamplingMessage> $messages
     */
    private function flatten(array $messages): string
    {
        $parts = [];

        foreach ($messages as $message) {
            if ($message->content instanceof TextContent) {
                $parts[] = (string) $message->content->text;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * A review whose score is a pure function of the prompt, so a test can assert on it.
     */
    private function answer(string $prompt): string
    {
        $title = preg_match('/^Title:\s*(.+)$/m', $prompt, $matches) ? trim($matches[1]) : 'the proposal';
        $track = preg_match('/^Track:\s*(.+)$/m', $prompt, $matches) ? trim($matches[1]) : 'unknown';

        // 3, 4 or 5 — stable for a given title, and never a rejection, which would
        // make the demo's follow-up steps untestable.
        $score = 3 + (crc32($title) % 3);

        return \sprintf(
            "The proposal is well scoped for the %s track and the abstract states what the audience will leave with. ".
            "The framing is concrete rather than aspirational, which is what this track needs. ".
            "The speaker's experience is relevant to the material.\n".
            "Two reservations: the abstract does not say how much of the talk is live demo, and the title promises a breadth ".
            "the abstract narrows considerably. Neither is disqualifying.\n".
            "Recommend acceptance, with a note to the speaker to align the title with the scope.\n\n".
            'Score: %d',
            $track,
            $score,
        );
    }
}
