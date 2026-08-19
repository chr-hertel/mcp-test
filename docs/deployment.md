# What MCP asks of a PHP process

MCP was not designed around the request/response lifecycle PHP is built for.
Most of it fits anyway, but five things do not fit by default, and each of them
fails in a way that is hard to read from the outside. All five were hit while
building this demo.

## 1. A server that talks back needs two workers

The protocol lets a server send *requests to the client* in the middle of
handling a tool call — `roots/list`, `sampling/createMessage`,
`elicitation/create`. Over Streamable HTTP that becomes:

```
client  --POST tools/call------------------->  worker A
                                               (suspends the fiber, opens SSE)
client  <-SSE: roots/list--------------------  worker A   ← still holding the stream
client  --POST result of roots/list--------->  worker B   ← needs a second worker
                                               (worker A resumes, answers)
client  <-SSE: tools/call result-------------  worker A
```

With one worker, worker A is still holding the SSE stream when the client's
answer arrives, and the request queues behind it forever. Nothing errors: the
tool call simply never returns.

PHP's built-in web server is single-worker unless told otherwise:

```console
$ PHP_CLI_SERVER_WORKERS=6 symfony server:start -d --no-tls --port=8099   # what `make serve` does
```

Under PHP-FPM this means the pool needs headroom for both halves — budget at
least two workers per concurrent MCP session that uses these features, not one.

If your server only ever answers requests (no roots, no sampling, no
elicitation, no progress), one worker per session is enough. The `conference`
server here is in that category; `organizer` is not.

## 2. Session state must be shared between workers

For the same reason, the two halves of that exchange land in different
processes, and they have to find the same session. The bundle's default (a file
store under `%kernel.cache_dir%/mcp-sessions/<name>`) already works across
workers on one machine. Across machines it needs `store: cache` with a Redis or
Memcached pool.

`store: memory` is only correct for STDIO, where the process *is* the session.

Each server needs its **own** store. Session ids are not namespaced by server,
so two servers sharing a store would accept each other's sessions — across a
firewall boundary that is privilege escalation. The bundle rejects a shared
store at compile time; this demo gives `conference` and `organizer` separate
file stores and `diagnostics` a cache-backed one.

[`HttpTransportTest::testASessionFromOneServerIsNotAcceptedByAnother()`](../tests/Functional/HttpTransportTest.php)
pins that a session minted on `/mcp` is refused by `/mcp/diagnostics`.

> `store: cache` needs `psr/simple-cache`, which `symfony/cache` only suggests.
> Without it the container fails at runtime with *Attempted to load interface
> "CacheInterface" from namespace "Psr\SimpleCache"* — see
> [`patches.md`](patches.md).

## 3. The built-in web server cannot stream

`php -S` — and therefore `symfony server:start`, and therefore `make serve` —
hands a response body to the client when the response *closes*, no matter what
the application does in between. `echo` plus `flush()` per frame changes
nothing; a five-line SSE script that ticks once a second delivers all five lines
at the end, in one write.

For a tool call that streams progress this is invisible: the response ends when
the call does, and the notifications arrive with the result a moment later than
they were sent. For `subscriptions/listen` it is not invisible at all — that
stream is *meant* to outlive the thing it reports on. Held for its configured
lifetime, it delivers everything at close:

```console
$ curl -N --trace-time -X POST .../mcp/2026 -H 'Mcp-Method: subscriptions/listen' …
01:28:53.6 <= Recv header, 47 bytes      ← headers immediately
01:29:09.5 <= Recv data, 1422 bytes      ← the acknowledgment, every keep-alive
                                           and the closing frame, sixteen seconds
                                           later, together
```

So a subscription cannot be *observed* working under the built-in server, only
verified after the fact — which is why `subscriptions.lifetime` is five seconds
in `config/packages/mcp.yaml` and why the regression suite waits each stream out
rather than reading frames as they arrive. Under PHP-FPM, FrankenPHP or any SAPI
that honours `flush()`, frames leave as they are written.

Worth knowing before concluding that a stream is broken: headers arriving
promptly and then nothing for the whole lifetime is what *working* looks like
here.

## 4. On STDIO, stdout belongs to the protocol

`bin/console mcp:server <name>` reads JSON-RPC from stdin and writes it to
stdout. Anything else on that stream corrupts the session, and the host reports
it as a parse error with no hint about where the extra bytes came from.

Symfony makes this easy to get wrong. Monolog's `console` handler writes to the
console output — the same file descriptor — and so does a stray `echo`, a
`dump()`, or a deprecation notice with `display_errors` on.

This demo's `prod` and `dev` configurations both keep stdout clean, and
[`StdioStreamPurityTest`](../tests/Functional/StdioStreamPurityTest.php) asserts
it: every line the server writes has to be a JSON-RPC message, including at
`-vvv` while a tool is logging and reporting progress. If you add logging, add a
case there.

Server-side logging belongs on **stderr**, which the host captures and shows in
its own logs. `LoggerInterface` in a tool does the right thing already; the
`mcp` Monolog channel is where the SDK's own output goes.

## 5. A long-running client must not carry a child process between messages

An MCP client with a STDIO transport spawns a child process on connect. In a
Messenger worker or any long-running process, holding that child across messages
means a stale, possibly dead, process serving the next one.

The bundle handles this: connections open on first use and close on
`kernel.reset`, so a worker starts a fresh child per message. Application code
never calls `connect()`. Calling `disconnect()` early is safe — the next request
reconnects transparently.

The regression command relies on this: it disconnects between servers, in a
`finally`, so one unreachable connection cannot leave a process behind for the
next.

---

## Notes on the rest

**Timeouts.** A tool that samples or elicits is waiting on a human or a model,
so its request timeout is a human-scale number, not a web-scale one. The
`regression` client uses 30 seconds by default and the SDK's gateway defaults to
120 for elicitation. PHP's own `max_execution_time` and the web server's timeout
have to be at least as generous, or the process dies mid-fiber.

**DNS rebinding.** The SDK's HTTP transport only accepts requests whose
`Origin`/`Host` names localhost, which is right for a desktop host on the same
machine and wrong the moment the endpoint is public. Set
`http.allowed_hosts: ['mcp.example.com']` per server, or `false` behind a proxy
that already validates `Host`.

**Authentication.** An MCP endpoint is an ordinary Symfony route, so a firewall
targets it directly — that is all this demo does for `organizer`. Real
deployments should use the MCP authorization spec (OAuth 2.1 with
protected-resource metadata); the SDK ships worked examples under
`upstream/php-sdk/examples/server/oauth-keycloak` and `oauth-microsoft`.

**Cold start.** Every STDIO tool call in `dev` pays for a container check.
Configure hosts with `APP_ENV=prod` and a warmed cache; the difference is the
gap between a host that feels instant and one that times out on first use.
