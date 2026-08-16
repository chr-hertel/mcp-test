<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\McpTestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * On STDIO the protocol *is* the process' standard output, so anything else
 * written there corrupts the session — and a host reports it as an opaque parse
 * error rather than as "your logger printed something".
 *
 * Symfony makes this easy to get wrong: Monolog's console handler writes to the
 * same stream, and so does any stray `echo`. These tests pin that the stream
 * stays clean, including at maximum verbosity and while the server is answering
 * a request that logs.
 */
final class StdioStreamPurityTest extends McpTestCase
{
    public function testEveryLineOnStdoutIsAJsonRpcMessage(): void
    {
        $lines = $this->runServer('conference', [
            $this->message(1, 'initialize', [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'purity-test', 'version' => '1.0.0'],
            ]),
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $this->message(2, 'tools/list'),
            $this->message(3, 'tools/call', ['name' => 'get_talk', 'arguments' => ['slug' => 'messenger-at-scale']]),
            $this->message(4, 'resources/read', ['uri' => 'conference://badge.png']),
            $this->message(5, 'ping'),
        ], verbose: true);

        $this->assertNotEmpty($lines, 'The server wrote nothing at all.');

        foreach ($lines as $index => $line) {
            $decoded = json_decode($line, true);

            $this->assertIsArray($decoded, \sprintf(
                "Line %d of stdout is not JSON, so the protocol stream is corrupted:\n%s",
                $index + 1,
                substr($line, 0, 400),
            ));
            $this->assertSame('2.0', $decoded['jsonrpc'] ?? null, \sprintf('Line %d is JSON but not a JSON-RPC message.', $index + 1));
        }

        // Five requests, one notification — notifications get no answer.
        $this->assertCount(5, $lines);
    }

    public function testAToolThatLogsDoesNotWriteToStdout(): void
    {
        // schedule_talk logs and emits progress through the client gateway. On
        // STDIO progress is a legitimate JSON-RPC notification; a logger writing
        // to the console would not be.
        $lines = $this->runServer('organizer', [
            $this->message(1, 'initialize', [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'purity-test', 'version' => '1.0.0'],
            ]),
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $this->message(2, 'tools/call', [
                'name' => 'schedule_talk',
                'arguments' => ['slug' => 'testing-what-matters', 'room' => 'Studio A', 'day' => '2026-11-20', 'startTime' => '14:00'],
                // Progress notifications are opt-in: a server only sends them when
                // the request carried a token to correlate them with.
                '_meta' => ['progressToken' => 'purity-test-1'],
            ]),
        ], verbose: true);

        $messages = [];

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, "Not JSON:\n".substr($line, 0, 400));
            $messages[] = $decoded;
        }

        // The tool's own log lines and progress updates travel on the same
        // stream, interleaved with the answer — as notifications, not as text.
        $notifications = array_values(array_filter($messages, static fn (array $m): bool => !isset($m['id'])));
        $this->assertNotEmpty($notifications, 'The tool logged and reported progress, but nothing reached the client.');

        $methods = array_column($notifications, 'method');
        $this->assertContains('notifications/progress', $methods);
        $this->assertContains('notifications/message', $methods);

        $answers = array_values(array_filter($messages, static fn (array $m): bool => 2 === ($m['id'] ?? null)));
        $this->assertCount(1, $answers, 'The tools/call request was answered exactly once.');
        $this->assertFalse($answers[0]['result']['isError'] ?? true, 'Scheduling an unscheduled talk into a free slot should succeed.');
        $this->assertSame('scheduled', $answers[0]['result']['structuredContent']['status'] ?? null);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function message(int $id, string $method, array $params = []): array
    {
        $message = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];

        if ([] !== $params) {
            $message['params'] = $params;
        }

        return $message;
    }

    /**
     * Runs `bin/console mcp:server <name>` as a child process and speaks to it
     * the way a host does — over a pipe that stays open.
     *
     * Keeping it open matters: a tool that suspends its fiber (to send progress,
     * or to ask the client something) is resumed by the transport's read loop, so
     * closing stdin after the last request would end the process mid-call.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<string> the non-empty lines the server wrote to stdout
     */
    private function runServer(string $server, array $messages, bool $verbose = false): array
    {
        $command = [\PHP_BINARY, 'bin/console', 'mcp:server', $server];

        if ($verbose) {
            $command[] = '-vvv';
        }

        $lastId = 0;
        foreach ($messages as $message) {
            $lastId = max($lastId, (int) ($message['id'] ?? 0));
        }

        $input = new InputStream();
        $process = new Process($command, self::$kernel?->getProjectDir() ?? \dirname(__DIR__, 2));
        $process->setEnv(['APP_ENV' => 'test']);
        $process->setInput($input);
        $process->setTimeout(60);
        $process->start();

        foreach ($messages as $message) {
            $input->write(json_encode($message, \JSON_THROW_ON_ERROR)."\n");
        }

        $stdout = '';
        $process->waitUntil(static function (string $type, string $buffer) use (&$stdout, $lastId): bool {
            if (Process::OUT !== $type) {
                return false;
            }

            $stdout .= $buffer;

            foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
                $decoded = json_decode(trim($line), true);

                if (\is_array($decoded) && $lastId === ($decoded['id'] ?? null)) {
                    return true; // the last request has been answered
                }
            }

            return false;
        });

        $input->close();
        $process->stop(5);

        return array_values(array_filter(
            preg_split('/\R/', $process->getOutput()) ?: [],
            static fn (string $line): bool => '' !== trim($line),
        ));
    }
}
