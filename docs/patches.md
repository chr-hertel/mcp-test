# Upstream findings

Everything in this file was found by building the demo and then pointing its own
MCP client at its own MCP servers — `make regression`. Seven problems needed a
code change; six more are behaviours worth knowing about, or upstream moves
worth recording, but not bugs.

Three of the seven only surfaced once the demo actually spoke protocol revision
2026-07-28, which is what the SDK branch exists for: the bundle could not serve
that revision at all, `x-mcp-header` — the annotation the revision adds — could
not survive schema generation, and the notification bus behind
`subscriptions/listen` was read by every stream and written to by nothing.

Two have since been answered on the SDK branch itself — the schema one by the
patch this demo carried, and the client's inability to speak 2026-07-28 by an
implementation of it. Both write-ups stay below, marked, because the finding is
the artefact and the diff was only ever how it travelled. Nothing here carries
an SDK patch any more; `patches/` is the bundle's.

The six remaining patches live in [`patches/`](../patches) and are applied to the
bundle clone under `upstream/` by `make apply-patches`. Each one applies cleanly
to a pristine checkout of the branch it targets, in file-name order:

```console
$ make upstream          # clone both branches and apply every patch
$ make apply-patches     # re-apply after re-cloning; skips what is already in
$ make export-patches    # dump the clones' working trees back out to patches/
```

The series is re-split and re-verified by `make export-patches`, which applies it
to a pristine clone of the branch tip, checks it reproduces the working tree, and
then **runs that repository's own test suite there**. The last step is not
ceremony: an earlier version verified only that the patches reproduced the tree,
and happily exported a series missing a `use` statement — it applied, it
compiled, and the failure surfaced two phases later in the demo's own regression
suite.

Base branches (record the SHA when you tag — these are branches, not releases):

| Package | Repository | Branch |
|---|---|---|
| `mcp/sdk` | [chr-hertel/php-sdk](https://github.com/chr-hertel/php-sdk) | `2026spec-findings` ([PR #3](https://github.com/chr-hertel/php-sdk/pull/3)) |
| `symfony/mcp-bundle` | [chr-hertel/ai](https://github.com/chr-hertel/ai) | `mcp-bundle-servers-and-clients` ([PR #44](https://github.com/chr-hertel/ai/pull/44)) |

Nothing below cites a commit, on purpose: both branches are rewritten in place —
`2026spec-findings` currently squashes to one WIP commit, to be decomposed before
review — so a SHA in prose is a dead link within the week. `make upstream-check`
is what tells you whether the clone still matches the tip, and the sign-off table
in [`release-checklist.md`](release-checklist.md) is where the SHA you tested
against belongs.

---

## Patched

### 1. A client could not answer `roots/list`

**`patches/mcp-bundle/0001-client-roots-option.patch`** · BC break

`mcp.clients.<name>.capabilities.roots` was a boolean, and setting it advertised
the `roots` capability during the handshake. Nothing registered the SDK's
`ListRootsRequestHandler`, though, so when a server then sent `roots/list` the
client answered *method not found*. From the tool's side that is a protocol
error mid-call, which is the worst of the three possible outcomes — worse than
the client simply not supporting roots, because the server's `supportsRoots()`
probe said it did.

The fix gives roots the same shape `sampling` and `elicitation` already have: a
`roots:` option naming a service, and a capability derived from whether that
service is there.

```yaml
mcp:
    clients:
        regression:
            roots: 'App\Mcp\Client\WorkspaceRootsProvider'   # RootsCallbackInterface
```

`capabilities.roots` is removed rather than kept and ignored — a configuration
that advertises a capability nothing implements should not be expressible.

Demonstrated by [`WorkspaceRootsProvider`](../src/Mcp/Client/WorkspaceRootsProvider.php)
answering the [`WorkspaceTool`](../src/Mcp/Tool/Organizer/WorkspaceTool.php) in
the same process.

### 2. Completion providers with dependencies always failed

**`patches/mcp-bundle/0004-container-backed-completion-providers.patch`**

`#[CompletionProvider(provider: TalkSlugCompletion::class)]` is resolved when a
`completion/complete` request arrives, out of the PSR-11 container the server
was built with:

```php
$provider = $this->container?->has($provider) ? $this->container->get($provider) : new $provider();
```

The bundle *does* give the server a container — but it is a service locator
built from the element handlers (tools, prompts, resources, apps). A completion
provider is none of those, so `has()` returned false and the SDK fell back to
`new TalkSlugCompletion()`, which needs a repository, and died with an
`ArgumentCountError`. The handler catches `\Throwable` and answers
`-32603 Error while handling completion request`, so the actual cause never
reaches anyone.

This is why the static forms (`values:`, `enum:`) worked and the interesting one
did not. The fix autoconfigures every `Mcp\Capability\Completion\ProviderInterface`
implementation with an `mcp.completion_provider` tag and adds them to each
server's locator.

Reproduced by the *completion* group of the regression suite and by
`GeneratedSchemaTest`.

### 3. `ServerConnection` was missing three methods

**`patches/mcp-bundle/0002-connection-complete-and-protocol-version.patch`**

`ServerConnectionInterface` is the bundle's forwarding surface over the SDK's
`Mcp\Client`, and three requests had no forwarder: `complete()`,
`getProtocolVersion()` and `sendRootsListChanged()`. An application could not
reach them without reaching past the bundle to the raw client — and the first of
those is the client-side half of the completion providers the bundle spends
effort registering on the server side.

### 4. The bundle could not serve 2026-07-28 at all

**`patches/mcp-bundle/0005-stateless-lifecycle.patch`**

The whole point of the SDK branch is protocol revision 2026-07-28, and no Symfony
application could reach it. The bundle built `Builder::build()` onto
`StreamableHttpTransport` and nothing else; the modern lifecycle needs
`buildStateless()` onto `StatelessHttpTransport`, and there was no configuration
that got there.

Three builder calls were unreachable for the same reason — `setRequestState()`
(without which a multi-round-trip handler cannot remember anything between
rounds), `setCachePolicy()` (hints the revision *requires* on `server/discover`,
the list methods and `resources/read`) and `setNotificationBus()`.

The patch also wired `enableExtension(new TasksExtension(...))` until the SDK
branch carved Tasks out; see *Tasks left the branch* below.

The patch adds `lifecycle: handshake|stateless` per server plus the configuration
for those three, a `StatelessMcpController`, and compile-time refusal of the
combinations the revision forbids — a stateless server with STDIO, or with a
session store it has no use for.

It depends on SDK surface that only exists on the branch, so it lands after that
does; the bundle's `"mcp/sdk": "^0.7"` has to move with it. `bin/link-sdk` is how
the bundle's own suite is run against the branch in the meantime.

### 5. A parameter's complete schema definition was nested instead of applied

**Landed upstream** on `2026spec-findings`, 17 August 2026 — no patch is carried
for it any more

The only finding here that was the SDK's rather than the bundle's, and the only
one whose *patch* was taken upstream. `patches/php-sdk/` went with it: the demo
tracks the branch tip, and the tip has the fix. Everything below is what it was
for.

`#[Schema(definition: [...])]` is documented as "the complete JSON schema array…
takes precedence over individual properties", and at the *method* level
`SchemaGenerator` unwraps it exactly so. At the *parameter* level it merged the
attribute as an ordinary key — in **two** places, the ordinary path and the
variadic one — so the property reached clients as:

```json
"track": { "type": "string", "default": "backend",
           "definition": { "type": "string", "x-mcp-header": "Track" } }
```

A nested `definition` is not JSON Schema. Nothing reads it, and everything inside
it is lost.

On this branch that is more than cosmetic: `x-mcp-header` — which mirrors an
argument into `Mcp-Param-*` so an intermediary can route without parsing the body
— has no representation on the attribute other than `definition`. Nested, the
server never learns the argument is header-mirrored, never checks the header
against the body, and never emits the `-32020` the revision requires on a
mismatch.

Found by the *headers* group of the 2026-07-28 regression suite, whose "a header
that disagrees with the body is refused" check sent a contradictory
`Mcp-Param-Track` and got a perfectly normal answer.

#### Siblings

The variadic case turned up by probing the generator across seven shapes rather
than by assuming the first one was alone. The rest came out clean:

| Shape | |
|---|---|
| variadic parameter | **the same bug**, fixed alongside |
| definition declaring `type: array` with no `items` | gains `items: {}`, like any array schema |
| definition reshaping the parameter's type | signature default kept — see below |
| definition over an enum-typed parameter | definition wins, as documented |
| method-level *and* parameter-level definition | method-level replaces everything, as documented |
| `outputSchema` | passed through raw; no `definition` involved |
| prompt arguments, resource-template variables | never reach the generator — `PromptArgument` is name/description/required, per spec |

Two behaviours were judgement calls rather than accidents, so the fix pins both
with tests:

- **The signature default survives.** It is what the handler receives when the
  argument is omitted, so it is kept even where the definition describes a
  different shape. `default` is an annotation in JSON Schema, not a constraint,
  so an inconsistent one is still valid.
- **The `items` invariant still applies**, so a "complete" definition of an array
  still gains `items: {}` when it declares none.

One consequence worth flagging in a changelog: an `x-mcp-header` that was nested
was also invisible to `Tool`'s validation, which refuses annotations on non-scalar
properties, invalid field names and case-insensitive duplicates. Now that the
annotation reaches the schema, one that was silently ignored can become a hard
failure at registration — most likely on a variadic parameter, which is always
`type: array` and therefore cannot be mirrored into a header. That is the right
answer, but it turns a quietly broken tool into a loud one.

### 6. `#[Target('<client>')]` did not resolve

**`patches/mcp-bundle/0003-client-target-alias.patch`**

The bundle documentation shows:

```php
public function __construct(
    #[Target('research')] private McpClientInterface $client,
) {
}
```

but the alias was only registered for the argument name `$researchClient`, so
that exact example failed at compile time:

> Cannot autowire service …: argument `$client` … has `#[Target('regression')]`
> but no such target exists. Did you mean to target one of "regression client"…

Registering both spellings costs one line and makes the documented form work.

### 7. A configured notification bus was never written to

**`patches/mcp-bundle/0006-registry-publishes-to-the-bus.patch`**

`subscriptions:` configures a notification bus, and the protocol reads it: every
`subscriptions/listen` stream polls it for the list-changed notifications it
agreed to carry. Nothing ever wrote to it.

The SDK wires the publishing half itself — `Builder::build()` wraps the event
dispatcher in a `PublishingEventDispatcher` when a bus is configured, so that a
runtime `registerTool()` reaches a listening client without the caller knowing a
bus exists. But it can only wrap a registry it constructs, and a registry handed
in through `setRegistry()` is already built:

```php
if ($this->hasCustomRegistry) {
    // Builder can't inject the loader into an already-constructed instance, so load it eagerly.
    $registry = $this->registry;
    $chainLoader->load($registry);
```

The bundle always supplies one — `mcp.server.<name>.registry`, constructed with
Symfony's `event_dispatcher` — so the wrapping never happened. The two halves
were each individually right and never met.

What that looks like from a client is worse than an error: the stream opens, the
acknowledgment names the types the server agreed to carry, keep-alives arrive
for the configured lifetime, and it closes gracefully having carried nothing —
no matter what changed on the server. Every observable part of the mechanism
works except the one that matters.

The fix gives the registry the publishing dispatcher where it is registered,
which is the only place the bundle knows both it and the bus. Per server rather
than one publisher on the shared dispatcher: the registries are per server, and
a tool appearing on one is not news to a client subscribed to another.

Found by the *subscriptions* group of the 2026-07-28 regression suite, which was
written to pin the acknowledgment contract and found this on its first run.

---

## Not patched, but worth knowing

### Every registration is announced, and the registry is rebuilt per request

`Registry::registerTool()` and friends dispatch a list-changed event on **every**
call, not on a change:

```php
$this->tools[$tool->name] = $reference;

$this->eventDispatcher?->dispatch(new ToolListChangedEvent());
```

Under a persistent runtime that is exactly right — a registration is news. Under
PHP-FPM it is not: the registry is built from scratch on every request, so every
request re-registers everything it has, and each of those registrations is
published to the bus as a change. One `tools/list` against the `modern` server
puts around sixty list-changed notifications on it.

A client subscribed to all three list-changed types therefore learns that every
list changed, several times over, every time anybody touches the server. The
lists it would refetch are identical to the ones it has.

Not patched, because the fix is a design decision rather than a bug fix, and
there are at least three reasonable ones: publish only when a registration
actually changes something, suppress publishing while a registry loads its
declared elements, or make the demo's own [`HouseKeepingLoader`](../src/Mcp/Loader/HouseKeepingLoader.php)
idempotent and leave the SDK alone. The first two are the SDK maintainer's call.

The regression suite pins the delivery rather than the volume — "a registry
change reaches the stream, tagged with it" — so a fix in any of those directions
keeps it green, while delivery breaking again does not.

### An unhandled exception in a tool is a protocol error, not a tool error

`CallToolHandler` turns `ToolCallException` into a `CallToolResult` with
`isError: true`, and *any other* throwable into `-32603 Error while executing
tool` with the original message dropped.

Dropping the message is right — it stops internals leaking to a model. Choosing
a protocol error is a departure from the specification's guidance, which says
errors that originate from the tool should be reported inside the result so the
model can see them and correct itself. The practical consequence is that a
handler which forgets to wrap its failures produces something the model cannot
act on at all.

This demo does not patch it: the behaviour is deliberate, and changing it would
be a BC break for anyone relying on the current codes. Instead the regression
suite pins both halves (`errors → ToolCallException becomes an isError result` /
`other throwables stay a protocol error`), so a future upstream change is visible
rather than silent. The application-side lesson is in
[`ProgrammeTools`](../src/Mcp/Tool/Programme/ProgrammeTools.php): throw
`ToolCallException` for anything the model could plausibly fix.

### `appOnly` is a hint to the host, not server-side filtering

The bundle's documentation says `#[AsMcpAppTool(appOnly: true)]` keeps a tool
"hidden from the model's `tools/list`". It does not: the SDK advertises the tool
and sets `_meta.ui.visibility` to `["app"]`, which the *host* is expected to
honour. That matches the MCP Apps extension, so the code is right and the
sentence is wrong — a documentation fix rather than a patch.

Anything that must not be reachable by a model needs a separate server (as
`organizer` is here), not a visibility hint.

### A `LoaderInterface` bypasses every server's capability lists

Services implementing `Mcp\Capability\Registry\Loader\LoaderInterface` are
autoconfigured with `mcp.loader` and handed to **every** server builder:

```php
->addMethodCall('addLoaders', [new TaggedIteratorArgument('mcp.loader')])
```

Attributed elements go through `ElementMatcher` and appear only on the servers
whose `tools:` / `resources:` / … lists name them. Loader-registered elements go
through nothing. So this configuration —

```yaml
diagnostics:
    tools: ['App\Mcp\Tool\Diagnostics\']
    # resources: not listed, so: none
```

— serves two resources anyway, because
[`HouseKeepingLoader`](../src/Mcp/Loader/HouseKeepingLoader.php) registered them.
The regression suite found this the moment the loader was added: the
`diagnostics` server started answering `resources/list` with entries its own
configuration never mentions.

For read-only housekeeping resources that is harmless. For a loader that reads a
database table, or one shipped by a third-party bundle, it means an element can
reach a server the application deliberately kept narrow — including one on the
other side of a firewall. The whole point of the capability lists is that a
server exposes only what it names.

Not patched here, because the fix is an API decision rather than a bug fix: it
needs either a `loaders:` capability list per server (matching by service id,
symmetrical with the other five), or loaders filtered by the same
`ElementMatcher` after they run. Both are reasonable; picking one is the
maintainer's call.

Until then, treat a loader as global, and put anything server-specific behind an
attribute.

### The bundle exposes about half of the SDK's server builder

`Mcp\Server\Builder` grew a lot on the `2026spec-findings` branch. The bundle
calls eleven of its methods; these have no configuration option and no other way
in, so an application using the bundle cannot reach them at all:

| Builder method | What it gates |
|---|---|
| `setProtocolVersion()` | pinning a revision — **and with it everything below**, since Tasks and the stateless lifecycle are `2026-07-28` features |
| `buildStateless()` | the modern lifecycle: no `initialize`, no session, `server/discover` |
| `setNotificationBus()` | `subscriptions/listen` delivery; PHP-FPM needs the PSR-16 bus, since publisher and stream are different workers |
| `setResourceSubscriptionManager()`, `setSubscriptionLifetime()` | `resources/subscribe` |
| `setCachePolicy()` | response caching |
| `setLazyLoading()` | deferring element registration |
| `enableExtension()` | any extension other than MCP Apps, which `McpAppPass` enables implicitly |

Patch 0005 opened most of that up — `buildStateless()`, `setCachePolicy()`,
`setNotificationBus()`, `setSubscriptionLifetime()` and `enableExtension()` all
have configuration now. What is still unreachable is `setProtocolVersion()` on a
*handshake* server and `setLazyLoading()`, and the smallest useful addition would
be `mcp.servers.<name>.protocol_version`, mirroring the option the client side
already has.

### The Inspector reaches 2026-07-28, but not by default

Worth recording because it is easy to conclude the opposite. Inspector 2.2.0
bundles `@modelcontextprotocol/client@2.0.0`, which knows the revision and speaks
`server/discover` — but all three of its clients hardcode the same default:

```js
versionNegotiation = options.versionNegotiation ?? { mode: "legacy" }
```

Only the web UI exposes a control for it (*Auto* / *Legacy* / *Modern*). The CLI
has no flag and its config file carries no such key, so `--cli` opens with
`initialize` and a stateless server refuses it with `-32602` — which reads as "no
support" and is not.

### The SDK's client speaks 2026-07-28 — since 18 August 2026

**Resolved upstream** on `2026spec-findings`, 18 August 2026

This used to read *cannot*. `Mcp\Client\Protocol::initialize()` fell back to the
newest handshake revision when configured with a modern one and logged a
warning, so `mcp.clients.<name>.protocol_version: '2026-07-28'` was accepted by
the bundle and then silently downgraded — the configuration was expressible and
inert, which is the same shape of bug as [the bus nothing published to](#7-a-configured-notification-bus-was-never-written-to).

Setting a modern revision now selects the modern wire instead: no `initialize`,
a `_meta` envelope on every request, the SEP-2243 headers derived from the
message, and a multi round-trip call answered and retried by the client so the
caller sees one call and one result. Nothing in the bundle had to change — it
already passed `protocol_version` to `setProtocolVersion()`.

So the demo adopted it. `mcp.clients.modern` drives the `modern` server through
the bundle like any other connection, and the *same* `RegressionRunner` runs
over both eras: 35 of its checks pass unchanged over the modern wire, two are
skipped because the revision removed the method (`ping`, `logging/setLevel`),
and the elicitation round trip passes without knowing that the question now
travels in the opposite direction.

[`ModernClient`](../src/Mcp/Modern/ModernClient.php) stayed, with a smaller job:
the requests a conforming client will not make. A missing protocol version, a
header that contradicts the body, a tampered `requestState` — the refusals the
revision requires can only be tested by a client willing to be wrong. It also
still carries `subscriptions/listen`, which the SDK's client does not implement.

### Tasks left the branch on 19 August 2026

The SDK branch carried the Tasks extension (SEP-2663) — `tasks/get`, a durable
handle instead of a held-open connection, two stores — and this demo
demonstrated it: `tasks: { store: cache }` on the `modern` server, an
`audit_schedule` tool, two regression checks and two functional tests.

It is gone from `2026spec-findings`, carved out into PR #428. The branch's own
`spec-report.md` says so in three places; nothing was lost, it just travels
separately now. `Mcp\Server\Task\*`, `Mcp\Schema\Task`, `TaskStatus` and
`RequestContext::supportsTasks()` all went with it, which is a compile-time break
rather than a behavioural one: the `modern` server could not be built at all, and
sixteen tests failed with *Class "Mcp\Server\Task\TasksExtension" not found*.

So the demo's tasks surface is parked, not deleted-and-forgotten. Restoring it
means putting back, in this order:

| Where | What |
|---|---|
| `patches/mcp-bundle/0005-stateless-lifecycle.patch` | the `tasks:` config node, `taskStore()`, `enableExtension(new TasksExtension(...))` and their two bundle tests |
| `config/packages/mcp.yaml` | `tasks: { store: cache }` on `modern`, and the `audit_schedule` sentence in its instructions |
| `ModernLifecycleTool` | `auditSchedule()` and the `audit()` helper it used |
| `DiagnosticsTool`, `RegressionRunner` | the `tasks` key in `probe_client` and in the capability probe |
| `ModernRegressionRunner` | the `tasks` group and the discovery check for the advertised extension |
| `ModernClient` | `tasks/get`, `tasks/update` and `tasks/cancel` in `NAMED_METHODS` |
| `ModernLifecycleTest` | the two task tests and the extension assertion |

The alternative was tracking two branches at once — `feat-ext-tasks` has Tasks
but not the stateless lifecycle, so the clone would have to merge them. A
test-merge conflicts in 26 files, including `SchemaGenerator` and the MCP Apps
example, and both branches are still moving. Parking is cheaper than carrying
that.

### `session.store: cache` needs `psr/simple-cache`

Choosing the cache-backed session store makes the bundle register
`Symfony\Component\Cache\Psr16Cache`, which implements `Psr\SimpleCache\CacheInterface`.
`symfony/cache` only *suggests* `psr/simple-cache`, so on an application that has
not installed it the container blows up at runtime with:

> Attempted to load interface "CacheInterface" from namespace "Psr\SimpleCache".

Nothing in the bundle's configuration reference mentions the dependency. Either
`symfony/mcp-bundle` should require `psr/simple-cache`, or the docs should say
so next to the `cache` store. This demo simply requires it.
