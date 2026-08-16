<?php

declare(strict_types=1);

namespace App\Mcp\Regression;

use App\Mcp\Modern\ModernClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The regression suite for protocol revision 2026-07-28.
 *
 * A separate runner from {@see RegressionRunner} because almost nothing is
 * shared: there is no handshake to perform, no session to keep, no
 * `ServerConnectionInterface` to drive — the bundle's client cannot reach this
 * revision at all, since the SDK's client downgrades to the newest handshake
 * version when configured with a modern one.
 *
 * So this drives {@see ModernClient} over real HTTP and checks the things the
 * revision actually changed, rather than re-running the handshake-era checks
 * against a different endpoint.
 */
final class ModernRegressionRunner
{
    /**
     * @var list<CheckResult>
     */
    private array $results = [];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /**
     * @return list<CheckResult>
     */
    public function run(string $endpoint): array
    {
        $this->results = [];

        $client = new ModernClient(
            $this->http,
            $endpoint,
            // Declaring elicitation is what allows the server to ask for input;
            // without it an InputRequiredResult would be -32021 instead.
            capabilities: ['elicitation' => (object) [], 'extensions' => ['io.modelcontextprotocol/tasks' => (object) []]],
        );

        $this->checkDiscovery($client);
        $this->checkLifecycle($client);
        $this->checkCaching($client);
        $this->checkNotifications($client);
        $this->checkMultiRoundTrip($client, $endpoint);
        $this->checkTasks($client);
        $this->checkHeaders($client, $endpoint);
        $this->checkRemovals($client);

        return $this->results;
    }

    // -- discovery -----------------------------------------------------------

    private function checkDiscovery(ModernClient $client): void
    {
        $this->check('discovery', 'server/discover replaces initialize', function () use ($client): string {
            $result = $this->result($client->discover());

            if (!\in_array(ModernClient::PROTOCOL_VERSION, $result['supportedVersions'] ?? [], true)) {
                throw new \RuntimeException('The server does not advertise 2026-07-28.');
            }

            $info = $result['_meta']['io.modelcontextprotocol/serverInfo'] ?? [];

            return \sprintf('%s %s, speaking %s', $info['name'] ?? '?', $info['version'] ?? '?', implode(', ', $result['supportedVersions']));
        });

        $this->check('discovery', 'the tasks extension is advertised', function () use ($client): string {
            $extensions = $this->result($client->discover())['capabilities']['extensions'] ?? [];

            if (!\array_key_exists('io.modelcontextprotocol/tasks', $extensions)) {
                throw new \RuntimeException('The tasks extension is not advertised; is "tasks.store" configured?');
            }

            return implode(', ', array_keys($extensions));
        });

        $this->check('discovery', 'tools/list is served without a handshake', function () use ($client): string {
            $tools = $this->result($client->request('tools/list'))['tools'] ?? [];

            if ([] === $tools) {
                throw new \RuntimeException('The server advertised no tools.');
            }

            return \sprintf('%d tool(s), no initialize and no session id', \count($tools));
        });
    }

    // -- per-request metadata ------------------------------------------------

    private function checkLifecycle(ModernClient $client): void
    {
        $this->check('lifecycle', 'the request describes itself', function () use ($client): string {
            $data = $this->structured($client->callTool('describe_request'));

            if (ModernClient::PROTOCOL_VERSION !== ($data['protocol_version'] ?? null)) {
                throw new \RuntimeException('The handler saw a different revision than the one sent.');
            }
            if ('modern' !== ($data['era'] ?? null)) {
                throw new \RuntimeException('The handler did not recognise the revision as modern.');
            }
            if (true !== ($data['client']['declared_capabilities'] ?? null)) {
                throw new \RuntimeException('The per-request client capabilities did not reach the handler.');
            }

            return \sprintf('%s, capabilities declared per request', $data['protocol_version']);
        });

        $this->check('lifecycle', 'capability probes read the request, not a session', function () use ($client): string {
            // The same server, asked by a client that declares nothing, must report
            // no elicitation — the probes have no session to read in this era.
            $bare = $client->withoutCapabilities();

            $declared = $this->structured($client->callTool('describe_request'))['capabilities'] ?? [];
            $undeclared = $this->structured($bare->callTool('describe_request'))['capabilities'] ?? [];

            if (true !== ($declared['elicitation'] ?? null)) {
                throw new \RuntimeException('A declaring client was reported as not supporting elicitation.');
            }
            if (false !== ($undeclared['elicitation'] ?? null)) {
                throw new \RuntimeException('A client that declared nothing was reported as supporting elicitation.');
            }

            return 'declared: yes, undeclared: no';
        });

        $this->check('lifecycle', 'a missing protocol version is refused', function () use ($client): string {
            $raw = $this->http->request('POST', $client->getEndpoint(), [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'],
                'json' => ['jsonrpc' => '2.0', 'id' => 99, 'method' => 'tools/list', 'params' => ['_meta' => []]],
                'timeout' => 15,
            ]);

            $envelope = json_decode($raw->getContent(throw: false), true) ?: [];
            $code = $envelope['error']['code'] ?? null;

            if (null === $code) {
                throw new \RuntimeException('A request with no protocol version in _meta was accepted.');
            }

            return \sprintf('refused with %d', $code);
        });
    }

    // -- caching -------------------------------------------------------------

    private function checkCaching(ModernClient $client): void
    {
        $this->check('caching', 'the required cache hints are present', function () use ($client): string {
            $discover = $this->result($client->discover());
            $tools = $this->result($client->request('tools/list'));

            foreach (['server/discover' => $discover, 'tools/list' => $tools] as $method => $result) {
                if (!\array_key_exists('ttlMs', $result) || !\array_key_exists('cacheScope', $result)) {
                    throw new \RuntimeException(\sprintf('%s carries no ttlMs/cacheScope, which this revision requires.', $method));
                }
            }

            return \sprintf(
                'server/discover %dms/%s, tools/list %dms/%s',
                $discover['ttlMs'], $discover['cacheScope'], $tools['ttlMs'], $tools['cacheScope'],
            );
        });

        $this->check('caching', 'the configured policy reaches the wire', function () use ($client): string {
            // config/packages/mcp.yaml makes the list methods public and long-lived,
            // and leaves everything else on the private 30s default.
            $tools = $this->result($client->request('tools/list'));
            $read = $this->result($client->request('resources/read', ['uri' => 'conference://current']));

            if ('public' !== ($tools['cacheScope'] ?? null)) {
                throw new \RuntimeException('tools/list was not public, though the policy says it is.');
            }
            if ('private' !== ($read['cacheScope'] ?? null)) {
                throw new \RuntimeException('resources/read was not private, though the default says it is.');
            }

            return \sprintf('lists public, resources/read private (%dms)', $read['ttlMs']);
        });
    }

    // -- notifications -------------------------------------------------------

    private function checkNotifications(ModernClient $client): void
    {
        $this->check('notifications', 'progress and logging travel on the request stream', function () use ($client): string {
            $client->callTool('reindex_programme', ['steps' => 4], ModernClient::observing());

            $methods = array_count_values(array_column($client->getNotifications(), 'method'));

            if (($methods['notifications/progress'] ?? 0) < 4) {
                throw new \RuntimeException('Fewer progress notifications arrived than the tool sent.');
            }
            if (($methods['notifications/message'] ?? 0) < 4) {
                throw new \RuntimeException('The log lines did not arrive.');
            }

            return \sprintf('%d progress, %d log', $methods['notifications/progress'], $methods['notifications/message']);
        });

        $this->check('notifications', 'both are silent unless the request asks', function () use ($client): string {
            // No progressToken and no logLevel: this revision requires the server
            // to stay silent rather than guess.
            $client->callTool('reindex_programme', ['steps' => 4]);

            if ([] !== $client->getNotifications()) {
                throw new \RuntimeException(\sprintf(
                    'The server sent %d notification(s) the request never asked for.',
                    \count($client->getNotifications()),
                ));
            }

            return 'nothing sent, as required';
        });
    }

    // -- multi round trip ----------------------------------------------------

    private function checkMultiRoundTrip(ModernClient $client, string $endpoint): void
    {
        $this->check('MRTR', 'the server returns the ask instead of sending it', function () use ($client): string {
            $title = 'A Proposal Over Two Round Trips '.bin2hex(random_bytes(3));
            $first = $this->result($client->callTool('submit_proposal', [
                'title' => $title,
                'abstract' => 'Submitted by the modern regression runner, to prove that a server can ask for input without ever initiating a request.',
            ]));

            if ('input_required' !== ($first['resultType'] ?? null)) {
                throw new \RuntimeException(\sprintf('Expected an input_required result, got "%s".', $first['resultType'] ?? 'none'));
            }
            if (!isset($first['inputRequests']['speaker'])) {
                throw new \RuntimeException('The ask carried no "speaker" input request.');
            }
            if (!\is_string($first['requestState'] ?? null)) {
                throw new \RuntimeException('The ask carried no signed requestState.');
            }

            $second = $this->result($client->answerTool(
                'submit_proposal',
                [],
                ['speaker' => ['action' => 'accept', 'content' => [
                    'name' => 'Modern Speaker',
                    'email' => 'modern@example.com',
                    'consent' => 'accepted',
                ]]],
                $first['requestState'],
            ));

            if (true === ($second['isError'] ?? false)) {
                throw new \RuntimeException('The second round failed: '.($second['content'][0]['text'] ?? ''));
            }
            if ($title !== ($second['structuredContent']['title'] ?? null)) {
                throw new \RuntimeException('The title did not survive in the sealed requestState.');
            }

            return 'asked, answered, and the sealed state came back intact';
        });

        $this->check('MRTR', 'a tampered requestState is refused', function () use ($client, $endpoint): string {
            $first = $this->result($client->callTool('submit_proposal', [
                'title' => 'A Proposal Whose State Will Be Tampered With',
                'abstract' => 'The runner flips a character of the signed state, which must fail verification rather than reach a handler.',
            ]));

            $state = (string) ($first['requestState'] ?? '');
            $tampered = strtr(substr($state, 0, 1), ['a' => 'b', 'A' => 'B']).substr($state, 1);
            if ($tampered === $state) {
                $tampered = 'x'.substr($state, 1);
            }

            $envelope = $client->answerTool('submit_proposal', [], ['speaker' => ['action' => 'accept', 'content' => [
                'name' => 'Impostor', 'email' => 'impostor@example.com', 'consent' => 'accepted',
            ]]], $tampered);

            if (!isset($envelope['error'])) {
                throw new \RuntimeException('A requestState with a flipped byte was accepted.');
            }

            return \sprintf('refused with %d: %s', $envelope['error']['code'], $envelope['error']['message']);
        });

        $this->check('MRTR', 'an undeclared capability is refused, not asked for', function () use ($endpoint): string {
            // A client that never declared elicitation cannot answer an ask, so the
            // SDK answers -32021 rather than sending one that could not be answered.
            $bare = new ModernClient($this->http, $endpoint, capabilities: []);

            $envelope = $bare->callTool('submit_proposal', [
                'title' => 'A Proposal From A Client That Cannot Answer',
                'abstract' => 'The server must refuse to ask, because this client declared no elicitation capability at all.',
            ]);

            if (!isset($envelope['error'])) {
                throw new \RuntimeException('The server asked a client that cannot answer.');
            }

            return \sprintf('refused with %d', $envelope['error']['code']);
        });
    }

    // -- tasks ---------------------------------------------------------------

    private function checkTasks(ModernClient $client): void
    {
        $this->check('tasks', 'a tool hands back a durable handle', function () use ($client): string {
            $created = $this->result($client->callTool('audit_schedule', ['depth' => 2]));

            // A task result is flat — `resultType: "task"` beside the envelope —
            // not a `task` member, which is what makes it distinguishable from a
            // CallToolResult without looking at the tool.
            if ('task' !== ($created['resultType'] ?? null)) {
                throw new \RuntimeException(\sprintf('audit_schedule answered with "%s", not a task.', $created['resultType'] ?? 'no resultType'));
            }

            $taskId = $created['taskId'] ?? null;
            if (null === $taskId) {
                throw new \RuntimeException('The task result carried no taskId.');
            }
            if ('working' !== ($created['status'] ?? null)) {
                throw new \RuntimeException('A freshly created task should be "working".');
            }

            $fetched = $this->result($client->request('tasks/get', ['taskId' => $taskId]));

            if ($taskId !== ($fetched['taskId'] ?? null)) {
                throw new \RuntimeException('tasks/get returned a different task.');
            }
            if ('completed' !== ($fetched['status'] ?? null)) {
                throw new \RuntimeException(\sprintf('The task is "%s", not completed.', $fetched['status'] ?? 'unknown'));
            }

            return \sprintf('%s → %s', substr($taskId, 0, 8), $fetched['status']);
        });

        $this->check('tasks', 'the work runs inline for a client without the extension', function () use ($client): string {
            $bare = $client->withoutCapabilities();
            $envelope = $bare->callTool('audit_schedule', ['depth' => 1]);

            if (isset($envelope['error'])) {
                throw new \RuntimeException('A client without the tasks extension got an error instead of an answer.');
            }

            $result = $envelope['result'] ?? [];
            if ('task' === ($result['resultType'] ?? null)) {
                throw new \RuntimeException('A handle was sent to a client with no polling loop.');
            }

            return 'answered synchronously, as it must';
        });
    }

    // -- headers -------------------------------------------------------------

    private function checkHeaders(ModernClient $client, string $endpoint): void
    {
        $this->check('headers', 'an x-mcp-header argument is mirrored and checked', function () use ($client): string {
            $data = $this->structured($client->callTool('search_track', ['track' => 'ai'], headerParams: ['Track' => 'ai']));

            if ('ai' !== ($data['track'] ?? null)) {
                throw new \RuntimeException('The tool did not receive the track it was called with.');
            }

            return \sprintf('Mcp-Param-Track agreed with the body, %d talk(s)', $data['count']);
        });

        $this->check('headers', 'a header that disagrees with the body is refused', function () use ($client): string {
            $envelope = $client->callTool('search_track', ['track' => 'ai'], headerParams: ['Track' => 'devops']);

            if (!isset($envelope['error'])) {
                throw new \RuntimeException('A Mcp-Param-Track that contradicted the body was accepted.');
            }

            return \sprintf('refused with %d', $envelope['error']['code']);
        });

        $this->check('headers', 'a wrong Mcp-Name is refused', function () use ($endpoint): string {
            $raw = $this->http->request('POST', $endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json, text/event-stream',
                    'MCP-Protocol-Version' => ModernClient::PROTOCOL_VERSION,
                    'Mcp-Method' => 'tools/call',
                    'Mcp-Name' => 'a_different_tool',
                ],
                'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
                    'name' => 'describe_request',
                    'arguments' => (object) [],
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => ModernClient::PROTOCOL_VERSION,
                        'io.modelcontextprotocol/clientCapabilities' => (object) [],
                    ],
                ]],
                'timeout' => 15,
            ]);

            $envelope = json_decode($raw->getContent(throw: false), true) ?: [];

            if (!isset($envelope['error'])) {
                throw new \RuntimeException('A Mcp-Name naming a different tool was accepted.');
            }

            return \sprintf('refused with %d', $envelope['error']['code']);
        });
    }

    // -- what the revision removed -------------------------------------------

    private function checkRemovals(ModernClient $client): void
    {
        foreach (['initialize', 'ping', 'logging/setLevel', 'resources/subscribe'] as $method) {
            $this->check('removals', \sprintf('"%s" is gone', $method), function () use ($client, $method): string {
                $envelope = $client->request($method, 'resources/subscribe' === $method ? ['uri' => 'conference://current'] : []);

                if (!isset($envelope['error'])) {
                    throw new \RuntimeException(\sprintf('"%s" was answered, though this revision removed it.', $method));
                }

                return \sprintf('refused with %d', $envelope['error']['code']);
            });
        }
    }

    // -- plumbing ------------------------------------------------------------

    /**
     * @param callable(): string $check
     */
    private function check(string $group, string $name, callable $check): void
    {
        try {
            $this->results[] = CheckResult::pass($group, $name, $check());
        } catch (\Throwable $e) {
            $this->results[] = CheckResult::fail($group, $name, \sprintf('%s: %s', $e::class, strtok(trim($e->getMessage()), "\n")));
        }
    }

    /**
     * @param array<string, mixed> $envelope
     *
     * @return array<string, mixed>
     */
    private function result(array $envelope): array
    {
        if (isset($envelope['error'])) {
            throw new \RuntimeException(\sprintf('%d %s', $envelope['error']['code'], $envelope['error']['message']));
        }

        $result = $envelope['result'] ?? null;

        if (!\is_array($result)) {
            throw new \RuntimeException('The server answered without a result.');
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $envelope
     *
     * @return array<string, mixed>
     */
    private function structured(array $envelope): array
    {
        $result = $this->result($envelope);

        if (isset($result['structuredContent']) && \is_array($result['structuredContent'])) {
            return $result['structuredContent'];
        }

        $decoded = json_decode($result['content'][0]['text'] ?? '', true);

        if (!\is_array($decoded)) {
            throw new \RuntimeException('The tool result carried neither structured content nor JSON text.');
        }

        return $decoded;
    }
}
