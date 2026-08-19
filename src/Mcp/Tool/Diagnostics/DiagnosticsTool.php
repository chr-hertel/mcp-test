<?php

declare(strict_types=1);

namespace App\Mcp\Tool\Diagnostics;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;

/**
 * Tools that exist to be poked at by the regression suite and the MCP Inspector.
 *
 * They are exposed only by the `diagnostics` server, so a host connected to the
 * conference server never sees a tool whose whole purpose is to fail.
 */
final class DiagnosticsTool
{
    /**
     * Return the arguments as given, which makes argument coercion visible.
     *
     * @param string    $text   any string
     * @param int       $number any integer
     * @param bool      $flag   any boolean
     * @param list<string> $items a list of strings
     *
     * @return array{text: string, number: int, flag: bool, items: list<string>, types: array<string, string>}
     */
    #[McpTool(
        name: 'echo_arguments',
        title: 'Echo arguments',
        description: 'Return the arguments exactly as the server received them, with their PHP types.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function echoArguments(
        string $text = 'hello',
        int $number = 42,
        bool $flag = false,
        #[Schema(type: 'array', items: ['type' => 'string'])]
        array $items = [],
    ): array {
        return [
            'text' => $text,
            'number' => $number,
            'flag' => $flag,
            'items' => array_values($items),
            'types' => [
                'text' => get_debug_type($text),
                'number' => get_debug_type($number),
                'flag' => get_debug_type($flag),
                'items' => get_debug_type($items),
            ],
        ];
    }

    /**
     * Report what the connected client says it can do.
     *
     * @return array{protocol_version: string, roots: bool, sampling: bool, sampling_tools: bool, sampling_context: bool, elicitation: bool, elicitation_url: bool}
     */
    #[McpTool(
        name: 'probe_client',
        title: 'Probe the client',
        description: 'Report the protocol revision in use and which optional client capabilities are available.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function probeClient(RequestContext $context): array
    {
        $client = $context->getClientGateway();

        return [
            'protocol_version' => $context->getProtocolVersion()->value,
            'roots' => $client->supportsRoots(),
            'sampling' => $client->supportsSampling(),
            'sampling_tools' => $client->supportsSamplingTools(),
            'sampling_context' => $client->supportsSamplingContext(),
            'elicitation' => $client->supportsElicitation(),
            'elicitation_url' => $client->supportsElicitationUrl(),
        ];
    }

    /**
     * Emit a fixed number of progress notifications, then finish.
     *
     * @param int $steps how many progress notifications to send
     *
     * @return array{steps: int, message: string}
     */
    #[McpTool(
        name: 'emit_progress',
        title: 'Emit progress notifications',
        description: 'Send N progress notifications and a log line at each step. Useful to verify a client renders streaming updates.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function emitProgress(
        RequestContext $context,
        #[Schema(minimum: 1, maximum: 20)]
        int $steps = 5,
    ): array {
        $client = $context->getClientGateway();

        for ($step = 1; $step <= $steps; ++$step) {
            $client->progress($step / $steps, 1.0, \sprintf('Step %d of %d', $step, $steps));
            $client->log(LoggingLevel::Debug, \sprintf('Completed step %d of %d.', $step, $steps));
        }

        return ['steps' => $steps, 'message' => \sprintf('Emitted %d progress notifications.', $steps)];
    }

    /**
     * Fail on purpose, in one of two distinguishable ways.
     *
     * `tool_error` produces a `CallToolResult` with `isError: true` — a failure
     * the model is meant to read and react to. `exception` lets an unhandled
     * throwable escape, which the SDK converts into the same shape but with the
     * message replaced, so nothing internal leaks to the client.
     *
     * @param string $mode either "tool_error" or "exception"
     *
     * @return array{never: string}
     */
    #[McpTool(
        name: 'fail_on_purpose',
        title: 'Fail on purpose',
        description: 'Always fails. Use "tool_error" for a reported tool error, "exception" for an unhandled throwable.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function failOnPurpose(
        #[Schema(enum: ['tool_error', 'exception'])]
        string $mode = 'tool_error',
    ): array {
        if ('exception' === $mode) {
            throw new \LogicException('Unhandled throwable from fail_on_purpose (this is expected).');
        }

        throw new ToolCallException('Reported tool error from fail_on_purpose (this is expected).');
    }

    /**
     * Return a tiny PNG, to check that binary content survives the transport.
     *
     * @return list<TextContent|ImageContent>
     */
    #[McpTool(
        name: 'get_test_image',
        title: 'Get a test image',
        description: 'Return a 1x1 PNG as image content, to verify base64 content handling end to end.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function getTestImage(): array
    {
        return [
            new TextContent('A 1x1 transparent PNG follows.'),
            new ImageContent(data: self::ONE_PIXEL_PNG, mimeType: 'image/png'),
        ];
    }

    /**
     * A 1x1 transparent PNG, base64-encoded.
     */
    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
}
