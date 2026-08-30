<?php

declare(strict_types=1);

namespace App\Mcp\Regression;

use App\Mcp\Modern\ModernClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The protocol-level probes for revision 2026-07-28.
 *
 * {@see RegressionRunner} covers this revision too now: the `modern` client sets
 * `protocol_version: '2026-07-28'` and the SDK's own client speaks it, so the
 * same checks run over both eras and the suite proves the two agree.
 *
 * What that cannot prove is how the server answers a client that is *wrong*. A
 * conforming client never omits the protocol version, never contradicts its own
 * body in a header, never replays a `requestState` it edited — so the refusals
 * the revision requires would go untested, and a server that stopped enforcing
 * them would look perfectly healthy. This drives {@see ModernClient} instead,
 * which sends whatever it is told to, and covers what only the wire shows:
 * cache hints, the closing frames of a subscription, and the four methods the
 * revision removed.
 */
final class ModernRegressionRunner
{
    private const ACKNOWLEDGED_NOTIFICATION = 'notifications/subscriptions/acknowledged';
    private const META_SUBSCRIPTION_ID = 'io.modelcontextprotocol/subscriptionId';

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
            capabilities: ['elicitation' => (object) []],
        );

        $this->checkDiscovery($client);
        $this->checkLifecycle($client);
        $this->checkCaching($client);
        $this->checkNotifications($client);
        $this->checkSubscriptions($client);
        $this->checkMultiRoundTrip($client, $endpoint);
        $this->checkHeaders($client, $endpoint);
        $this->checkApps($client);
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

    // -- subscriptions -------------------------------------------------------

    /**
     * SEP-2575. `resources/subscribe` and the HTTP GET stream are both gone; a
     * client that wants server-initiated notifications opens one stream of its
     * own and names the types it will accept.
     *
     * The allow-list is the part worth pinning: a server MUST NOT send a type
     * the client did not ask for, and an omitted field declines exactly as
     * `false` does — so a filter that is read as a hint rather than as a
     * contract looks like a working subscription right up until it delivers
     * something the client cannot handle.
     *
     * Two streams for four checks, because a stream costs its whole configured
     * lifetime here: the built-in web server hands the body over at close, so
     * nothing can be asserted until the server lets go. See docs/deployment.md.
     */
    private function checkSubscriptions(ModernClient $client): void
    {
        $everything = [
            'toolsListChanged' => true,
            'promptsListChanged' => true,
            'resourcesListChanged' => true,
            'resourceSubscriptions' => ['conference://current'],
        ];

        $carried = [];

        $this->check('subscriptions', 'the acknowledgment opens the stream, on the request\'s own id', function () use ($client, $everything, &$carried): string {
            // `announce_tool` on a second connection is a real registry change, and
            // since mcp/sdk 0.8.1 nothing else on this server writes to the bus.
            $carried = $client->listen('sub-demo', $everything, whileOpen: fn () => $client->callTool('announce_tool'));

            $this->acknowledgment($carried, 'sub-demo');

            return \sprintf('%d frame(s), the acknowledgment first', \count($carried));
        });

        $this->check('subscriptions', 'the agreed set is everything the server advertises', function () use ($everything, &$carried): string {
            $agreed = (array) ($this->acknowledgment($carried, 'sub-demo')['params']['notifications'] ?? []);
            $declined = array_diff(array_keys($everything), array_keys($agreed));

            // Every type asked for is advertised by this server, so every type
            // asked for has to come back agreed.
            if ([] !== $declined) {
                throw new \RuntimeException(\sprintf(
                    'The server declined %s, though server/discover advertises it.',
                    implode(', ', $declined),
                ));
            }

            return implode(', ', array_keys($agreed));
        });

        $this->check('subscriptions', 'a registry change reaches the stream, tagged with it', function () use (&$carried): string {
            $delivered = array_values(array_filter(
                \array_slice($carried, 1),
                static fn (array $frame): bool => str_starts_with((string) ($frame['method'] ?? ''), 'notifications/'),
            ));

            if ([] === $delivered) {
                throw new \RuntimeException('Nothing reached the stream, though the registry changed while it was open.');
            }

            foreach ($delivered as $frame) {
                // Every notification on a subscription carries its id, so a
                // client holding several of them knows which one spoke.
                if ('sub-demo' !== ($frame['params']['_meta'][self::META_SUBSCRIPTION_ID] ?? null)) {
                    throw new \RuntimeException(\sprintf(
                        'A "%s" notification arrived untagged with the subscription it belongs to.',
                        $frame['method'] ?? '(no method)',
                    ));
                }
            }

            return implode(', ', array_unique(array_column($delivered, 'method')));
        });

        $this->check('subscriptions', 'a type the client did not ask for is declined', function () use ($client): string {
            $frames = $client->listen('sub-narrow', ['toolsListChanged' => true]);
            $agreed = array_keys((array) ($this->acknowledgment($frames, 'sub-narrow')['params']['notifications'] ?? []));

            if (['toolsListChanged'] !== $agreed) {
                throw new \RuntimeException(\sprintf(
                    'Asked for toolsListChanged alone and the server agreed to %s.',
                    [] === $agreed ? 'nothing' : implode(', ', $agreed),
                ));
            }

            return 'only toolsListChanged; the rest declined by omission';
        });
    }

    /**
     * The first frame of a stream, which the revision requires to be the
     * acknowledgment and requires to precede every notification on it.
     *
     * @param list<array<string, mixed>> $frames
     *
     * @return array<string, mixed>
     */
    private function acknowledgment(array $frames, string $subscriptionId): array
    {
        $first = $frames[0] ?? throw new \RuntimeException('The stream carried nothing at all.');

        if (self::ACKNOWLEDGED_NOTIFICATION !== ($first['method'] ?? null)) {
            throw new \RuntimeException(\sprintf(
                'The stream opened with "%s" rather than the acknowledgment.',
                $first['method'] ?? '(no method)',
            ));
        }

        $id = $first['params']['_meta'][self::META_SUBSCRIPTION_ID] ?? null;

        // The subscription has no id of its own: it is the request's, so that a
        // client can correlate without waiting to be told.
        if ($subscriptionId !== $id) {
            throw new \RuntimeException(\sprintf(
                'The acknowledgment named subscription "%s", not the request\'s own id "%s".',
                \is_scalar($id) ? $id : \gettype($id),
                $subscriptionId,
            ));
        }

        return $first;
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
            // Not from the arguments — the second round sent none. The title can
            // only be here if it travelled out and back in the sealed state.
            if ($title !== ($second['structuredContent']['proposal']['title'] ?? null)) {
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

    // -- MCP Apps ------------------------------------------------------------

    private function checkApps(ModernClient $client): void
    {
        $this->check('apps', 'the UI extension is advertised', function () use ($client): string {
            $extensions = array_keys($this->result($client->discover())['capabilities']['extensions'] ?? []);

            if (!\in_array('io.modelcontextprotocol/ui', $extensions, true)) {
                throw new \RuntimeException('The MCP Apps extension is not advertised; does the server list "apps"?');
            }

            return implode(', ', $extensions);
        });

        $this->check('apps', 'the UI resource is served', function () use ($client): string {
            $contents = $this->result($client->request('resources/read', ['uri' => 'ui://schedule']))['contents'][0] ?? [];

            if (!str_contains((string) ($contents['mimeType'] ?? ''), 'mcp-app')) {
                throw new \RuntimeException(\sprintf('ui://schedule came back as "%s".', $contents['mimeType'] ?? 'nothing'));
            }

            if (!isset(($contents['_meta'] ?? [])['ui'])) {
                throw new \RuntimeException('The resource carries no _meta.ui marker, so a host will not treat it as an app.');
            }

            return \sprintf('%s, %d characters', $contents['mimeType'], \strlen($contents['text'] ?? ''));
        });

        $this->check('apps', 'the app tool renders on the server', function () use ($client): string {
            $data = $this->structured($client->callTool('browse_schedule', ['day' => '2026-11-19']));

            if (!isset($data['html']) || !str_contains($data['html'], 'data-call=')) {
                throw new \RuntimeException('The tool result carries no rendered HTML with tool triggers.');
            }

            return \sprintf('%d characters, %d trigger(s)', \strlen($data['html']), substr_count($data['html'], 'data-call='));
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
