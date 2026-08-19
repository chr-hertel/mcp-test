<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Modern;

use App\Mcp\Modern\ModernClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What the demo's own 2026-07-28 client puts on the wire, and what it makes of
 * what comes back.
 *
 * Everywhere else the client is exercised against a real server, which proves
 * the two agree but says nothing about *why* — a header the server ignores and
 * a header the client never sent look identical from there. This asserts the
 * request instead of the answer, so a mistake in either direction has a name.
 *
 * No kernel and no web server: the transport is a {@see MockHttpClient}, which
 * is also the only way to reach the frame parsing at all. `make regression`
 * needs a server running, and under the built-in one a stream says nothing
 * until it closes — see docs/deployment.md.
 *
 * What a mock cannot reach is *timing*. It answers instantly, so a stream is
 * never quiet, and how the client behaves while waiting — the thing that made
 * `stream($response, $timeout)` raise the idle timeout as an exception instead
 * of yielding a timeout chunk, and cost this client every frame of every
 * subscription — is invisible here and stays the regression suite's to catch.
 *
 * @see \App\Mcp\Regression\ModernRegressionRunner for the same client against a real server
 */
final class ModernClientTest extends TestCase
{
    private const ENDPOINT = 'http://mcp.test/mcp/2026';

    private const CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';
    private const CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    private const VERSION = 'io.modelcontextprotocol/protocolVersion';

    /**
     * @var list<array{method: string, url: string, options: array<string, mixed>}>
     */
    private array $sent = [];

    // -- what every request carries ------------------------------------------

    public function testEveryRequestDescribesItselfInMeta(): void
    {
        $this->client()->request('tools/list');

        $meta = $this->body()['params']['_meta'];

        // The revision has no handshake, so a request that does not say what it
        // speaks and what it can do cannot be answered at all.
        $this->assertSame(ModernClient::PROTOCOL_VERSION, $meta[self::VERSION]);
        $this->assertSame(['name' => 'mcp-demo-modern', 'version' => '1.0.0'], $meta[self::CLIENT_INFO]);
        $this->assertSame(['elicitation' => []], $meta[self::CAPABILITIES]);
    }

    public function testTheBodyIsMirroredIntoHeadersForAnIntermediaryToRouteOn(): void
    {
        $this->client()->request('tools/list');

        $this->assertSame(ModernClient::PROTOCOL_VERSION, $this->header('mcp-protocol-version'));
        $this->assertSame('tools/list', $this->header('mcp-method'));
        $this->assertSame('tools/list', $this->body()['method']);
    }

    public function testAnEventStreamIsAcceptedAlongsideJson(): void
    {
        $this->client()->request('tools/list');

        // Either is a valid answer to the same request: the server streams only
        // when it has something to send before the result.
        $this->assertStringContainsString('application/json', (string) $this->header('accept'));
        $this->assertStringContainsString('text/event-stream', (string) $this->header('accept'));
    }

    public function testEachRequestGetsItsOwnId(): void
    {
        $client = $this->client();
        $client->request('tools/list');
        $client->request('prompts/list');

        $this->assertSame(1, $this->body(0)['id']);
        $this->assertSame(2, $this->body(1)['id']);
    }

    public function testConfiguredHeadersTravelWithEveryRequest(): void
    {
        $client = new ModernClient($this->http(), self::ENDPOINT, headers: ['Authorization' => 'Bearer s3cret']);
        $client->request('tools/list');

        $this->assertSame('Bearer s3cret', $this->header('authorization'));
    }

    public function testWithoutCapabilitiesDeclaresNothing(): void
    {
        $this->client()->withoutCapabilities()->request('tools/list');

        // An empty *object*, not an empty array: `"capabilities": []` is not the
        // same statement, and this is how a server is asked to refuse rather
        // than to ask.
        $this->assertSame('{}', $this->rawMember('"'.self::CAPABILITIES.'":'));
    }

    // -- Mcp-Name ------------------------------------------------------------

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function namedMethodProvider(): iterable
    {
        yield 'tools/call names the tool' => ['tools/call', ['name' => 'search_talks'], 'search_talks'];
        yield 'prompts/get names the prompt' => ['prompts/get', ['name' => 'review_cfp'], 'review_cfp'];
        yield 'resources/read names the uri' => ['resources/read', ['uri' => 'talk://ai-in-php'], 'talk://ai-in-php'];
    }

    #[DataProvider('namedMethodProvider')]
    public function testANamedMethodCarriesTheThingItAddresses(string $method, array $params, string $expected): void
    {
        $this->client()->request($method, $params);

        $this->assertSame($expected, $this->header('mcp-name'));
    }

    public function testAnUnnamedMethodCarriesNoName(): void
    {
        $this->client()->request('tools/list');

        // Sending one where the revision does not ask for it is a -32020, which
        // reads as a transport problem rather than an extra header.
        $this->assertNull($this->header('mcp-name'));
    }

    public function testANameTheFieldCannotCarryTravelsWrapped(): void
    {
        $this->client()->request('resources/read', ['uri' => 'talk://köln-2026']);

        // An HTTP field value is ASCII; the spec's escape hatch is base64.
        $this->assertSame('=?base64?'.base64_encode('talk://köln-2026').'?=', $this->header('mcp-name'));
    }

    public function testAnAsciiNameTravelsAsItIs(): void
    {
        $this->assertSame('search_talks', ModernClient::encodeHeader('search_talks'));
    }

    // -- x-mcp-header arguments ----------------------------------------------

    public function testAHeaderMirroredArgumentIsSentBesideTheBody(): void
    {
        $this->client()->callTool('search_talks', ['track' => 'backend'], headerParams: ['Track' => 'backend']);

        // The server checks the two agree and answers -32020 when they do not,
        // which is the whole point of the annotation.
        $this->assertSame('backend', $this->header('mcp-param-track'));
        $this->assertSame('backend', $this->body()['params']['arguments']['track']);
    }

    // -- multi round trip ----------------------------------------------------

    public function testAnAnswerTravelsBesideTheArgumentsRatherThanInMeta(): void
    {
        $this->client()->answerTool(
            'submit_proposal',
            ['title' => 'A Talk'],
            ['speaker' => ['action' => 'accept', 'content' => ['name' => 'Ada']]],
            'sealed-state',
        );

        $params = $this->body()['params'];

        // `_meta` describes the request; these answer it.
        $this->assertSame('sealed-state', $params['requestState']);
        $this->assertSame(['speaker' => ['action' => 'accept', 'content' => ['name' => 'Ada']]], $params['inputResponses']);
        $this->assertArrayNotHasKey('inputResponses', $params['_meta']);
    }

    public function testObservingAsksForProgressAndLogs(): void
    {
        $this->client()->callTool('reindex_programme', ['steps' => 2], ModernClient::observing('token-1', 'debug'));

        $meta = $this->body()['params']['_meta'];

        // Both are opt-in here: a server that guessed would be wrong.
        $this->assertSame('token-1', $meta['progressToken']);
        $this->assertSame('debug', $meta['io.modelcontextprotocol/logLevel']);
    }

    // -- reading the answer --------------------------------------------------

    public function testAPlainJsonAnswerIsReturnedWhole(): void
    {
        $client = $this->client(new MockResponse(
            '{"jsonrpc":"2.0","id":1,"result":{"tools":[]}}',
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $this->assertSame(['tools' => []], $client->request('tools/list')['result']);
    }

    public function testTheAnswerIsPickedOutOfAStreamAndTheNotificationsKept(): void
    {
        $client = $this->client($this->eventStream(
            '{"jsonrpc":"2.0","method":"notifications/message","params":{"level":"info","data":"first"}}',
            '{"jsonrpc":"2.0","method":"notifications/progress","params":{"progressToken":"t","progress":1}}',
            '{"jsonrpc":"2.0","id":1,"result":{"content":[]}}',
        ));

        $answer = $client->callTool('reindex_programme');

        // The frame with an id is the answer; every other one preceded it.
        $this->assertSame(['content' => []], $answer['result']);
        $this->assertSame(
            ['notifications/message', 'notifications/progress'],
            array_column($client->getNotifications(), 'method'),
        );
    }

    public function testNotificationsBelongToTheRequestThatAskedForThem(): void
    {
        $client = $this->client(
            $this->eventStream(
                '{"jsonrpc":"2.0","method":"notifications/progress","params":{"progress":1}}',
                '{"jsonrpc":"2.0","id":1,"result":{}}',
            ),
            new MockResponse('{"jsonrpc":"2.0","id":2,"result":{}}', ['response_headers' => ['content-type' => 'application/json']]),
        );

        $client->callTool('reindex_programme', [], ModernClient::observing());
        $this->assertCount(1, $client->getNotifications());

        $client->request('tools/list');
        $this->assertSame([], $client->getNotifications(), 'The previous request\'s notifications outlived it.');
    }

    public function testAnAnswerThatIsNotJsonBecomesAnError(): void
    {
        $client = $this->client(new MockResponse('<html>Gateway Timeout</html>', [
            'http_code' => 504,
            'response_headers' => ['content-type' => 'text/html'],
        ]));

        $answer = $client->request('tools/list');

        // A proxy answering instead of the server is the common cause, and it is
        // worth reading as an error rather than as an empty result.
        $this->assertSame(504, $answer['error']['code']);
        $this->assertStringContainsString('Gateway Timeout', $answer['error']['message']);
    }

    // -- subscriptions/listen ------------------------------------------------

    public function testListenAsksForTheFilterOnTheRequestsOwnId(): void
    {
        $this->client($this->eventStream())->listen('sub-1', ['toolsListChanged' => true]);

        $body = $this->body();

        // The subscription has no id of its own: it is this request's, which is
        // what lets a client correlate without waiting to be told.
        $this->assertSame('sub-1', $body['id']);
        $this->assertSame('subscriptions/listen', $body['method']);
        $this->assertSame('subscriptions/listen', $this->header('mcp-method'));
        $this->assertSame(['toolsListChanged' => true], $body['params']['notifications']);
    }

    public function testAnEmptyFilterIsSentAsAnObject(): void
    {
        $this->client($this->eventStream())->listen('sub-1', []);

        // `"notifications": []` would decline by malformation rather than by
        // omission, and the two are not the same conversation.
        $this->assertSame('{}', $this->rawMember('"notifications":'));
    }

    public function testListenReturnsEveryFrameOldestFirst(): void
    {
        $frames = $this->client($this->eventStream(
            '{"jsonrpc":"2.0","method":"notifications/subscriptions/acknowledged","params":{"notifications":{}}}',
            '{"jsonrpc":"2.0","method":"notifications/tools/list_changed","params":{}}',
            '{"jsonrpc":"2.0","id":"sub-1","result":{"resultType":"complete"}}',
        ))->listen('sub-1', ['toolsListChanged' => true]);

        $this->assertSame([
            'notifications/subscriptions/acknowledged',
            'notifications/tools/list_changed',
            null,
        ], array_map(static fn (array $frame): ?string => $frame['method'] ?? null, $frames));
    }

    public function testKeepAliveCommentsAreNotFrames(): void
    {
        $frames = $this->client(new MockResponse(
            ": keep-alive\n\n: keep-alive\n\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/tools/list_changed\"}\n\n",
            ['response_headers' => ['content-type' => 'text/event-stream']],
        ))->listen('sub-1', []);

        // They exist so the server notices a dropped peer, and mean nothing here.
        $this->assertCount(1, $frames);
    }

    public function testListenProvokesOnceTheStreamIsOpen(): void
    {
        $provoked = 0;

        $frames = $this->client($this->eventStream(
            '{"jsonrpc":"2.0","method":"notifications/subscriptions/acknowledged","params":{}}',
            '{"jsonrpc":"2.0","method":"notifications/tools/list_changed","params":{}}',
        ))->listen('sub-1', [], whileOpen: static function () use (&$provoked): void { ++$provoked; });

        // Once, and only after the response has started: the stream takes its
        // place on the notification bus as it opens, so anything published
        // before that is not "what happens next" and never arrives.
        $this->assertSame(1, $provoked);
        $this->assertCount(2, $frames);
    }

    public function testListenKeepsWhatItReadWhenTheStreamBreaks(): void
    {
        $frames = $this->client(new MockResponse(
            (static function (): \Generator {
                yield "data: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/subscriptions/acknowledged\"}\n\n";
                yield new TransportException('The peer went away.');
            })(),
            ['response_headers' => ['content-type' => 'text/event-stream']],
        ))->listen('sub-1', []);

        // A subscription is long-lived by design, so it ends in a transport
        // error more often than not. What it carried before that still counts.
        $this->assertCount(1, $frames);
        $this->assertSame('notifications/subscriptions/acknowledged', $frames[0]['method']);
    }

    public function testListenTolerantOfAFrameSplitAcrossChunks(): void
    {
        $frames = $this->client(new MockResponse(
            (static function (): \Generator {
                yield 'data: {"jsonrpc":"2.0","method":"notifications/';
                yield "tools/list_changed\",\"params\":{}}\n\n";
            })(),
            ['response_headers' => ['content-type' => 'text/event-stream']],
        ))->listen('sub-1', []);

        // Nothing promises a chunk is a frame; only the newline says so.
        $this->assertCount(1, $frames);
        $this->assertSame('notifications/tools/list_changed', $frames[0]['method']);
    }

    // -- plumbing ------------------------------------------------------------

    private function client(MockResponse ...$responses): ModernClient
    {
        return new ModernClient(
            $this->http(...$responses),
            self::ENDPOINT,
            capabilities: ['elicitation' => (object) []],
        );
    }

    private function http(MockResponse ...$responses): MockHttpClient
    {
        $queue = [] === $responses ? [new MockResponse('{"jsonrpc":"2.0","id":1,"result":{}}', [
            'response_headers' => ['content-type' => 'application/json'],
        ])] : $responses;

        return new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): MockResponse {
            $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($queue) ?? new MockResponse('{"jsonrpc":"2.0","id":0,"result":{}}', [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });
    }

    private function eventStream(string ...$frames): MockResponse
    {
        $body = '';
        foreach ($frames as $frame) {
            $body .= 'data: '.$frame."\n\n";
        }

        return new MockResponse($body, ['response_headers' => ['content-type' => 'text/event-stream']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(int $request = 0): array
    {
        $this->assertArrayHasKey($request, $this->sent, 'No such request was sent.');

        return json_decode($this->sent[$request]['options']['body'], true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * The value that follows a member in the *raw* body, for the cases where
     * decoding would erase the distinction being asserted — `{}` and `[]` both
     * decode to an empty array.
     */
    private function rawMember(string $member, int $request = 0): string
    {
        $body = $this->sent[$request]['options']['body'];

        // json_encode escapes forward slashes, and every member name the
        // revision adds is a reverse-DNS key full of them.
        foreach ([$member, str_replace('/', '\\/', $member)] as $spelling) {
            $at = strpos($body, $spelling);

            if (false !== $at) {
                return substr($body, $at + \strlen($spelling), 2);
            }
        }

        $this->fail(\sprintf('The body carries no %s member.', $member));
    }

    private function header(string $name, int $request = 0): ?string
    {
        $this->assertArrayHasKey($request, $this->sent, 'No such request was sent.');

        $header = $this->sent[$request]['options']['normalized_headers'][$name] ?? null;

        return null === $header ? null : substr($header[0], \strlen($name) + 2);
    }
}
