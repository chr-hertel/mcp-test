# Upstream findings

Everything in this file was found by building the demo and then pointing its own
MCP client at its own MCP servers — `make regression`. Nine problems needed a
code change — one of which turned out not to be a problem at all, and is kept
as a write-up of the wrong diagnosis. Fifteen more are behaviours worth knowing
about, or upstream moves worth recording, but not bugs.

Five of the nine only surfaced once the demo actually spoke protocol revision
2026-07-28: the bundle could not serve that revision at all, `x-mcp-header` — the
annotation the revision adds — could not survive schema generation, the
notification bus behind `subscriptions/listen` was read by every stream and
written to by nothing, and the HMAC key signing a multi-round-trip answer could
not come from an environment variable, and fixing the bus turned it from silent
into noisy.

All nine have since been answered upstream, and **`patches/` is empty** as of
30 August 2026. Their write-ups stay below, marked, because the finding is the
artefact and the diff was only ever how it travelled — and in one case upstream
solved the problem a different way than the patch did, which is worth more than
the patch was.

An empty series is the goal state, not the finished one: the demo exists to keep
finding these, and the machinery below stays wired up for the next one.

```console
$ make upstream          # clone symfony/ai and apply every patch
$ make apply-patches     # re-apply after re-cloning; skips what is already in
$ make export-patches    # dump the clone's working tree back out to patches/
```

With nothing to split, `bin/export-patches` checks the one thing still worth
checking — that the clone carries no changes the empty series would drop — and
says so.

The series is re-split and re-verified by `make export-patches`, which applies it
to a pristine clone of the branch tip, checks it reproduces the working tree, and
then **runs that repository's own test suite there**. The last step is not
ceremony: an earlier version verified only that the patches reproduced the tree,
and happily exported a series missing a `use` statement — it applied, it
compiled, and the failure surfaced two phases later in the demo's own regression
suite.

The same check had a blind spot of its own until 23 August 2026. `git diff` does
not report untracked files, so a patch that *creates* a file exported without it
— and the verification could not see the gap, because it compared two `git diff`
outputs and both omitted the same two files. The series applied, reproduced the
tree and passed a suite that was four tests shorter than the one it was compared
against. `bin/export-patches` now marks untracked files intent-to-add before
reading the tree, which is what the verification step had been doing on its own
side all along.

Where upstream comes from:

| Package | Source | Constraint |
|---|---|---|
| `mcp/sdk` | [modelcontextprotocol/php-sdk](https://github.com/modelcontextprotocol/php-sdk) | Packagist `dev-main as 0.8.99` |
| `symfony/mcp-bundle` | [symfony/ai](https://github.com/symfony/ai), `main` | path repository on `upstream/symfony-ai` |

The SDK is no longer cloned. Every patch we carried against it has landed on its
`main`, so a clone would be a slower copy of what composer installs anyway — and
`dev-main` re-resolves the tip on every install, which is the drift detection a
clone was giving us. The alias is needed because the bundle requires a release
(`mcp/sdk: ^0.8.1` since 30 August 2026) and no dev branch satisfies a release
constraint on its own; `bin/link-sdk` does the same thing for the bundle's own
suite, so the two suites cannot resolve two different SDKs.

The bundle stays a clone even with no patches to apply, because protocol revision
2026-07-28 is only on `main` and not in any release. `make upstream-check` is what
tells you whether that clone still matches the tip, and the sign-off table in
[`release-checklist.md`](release-checklist.md) is where the SHA you tested
against belongs.

---

## Patched

### 1. A client could not answer `roots/list`

**Landed upstream** in [symfony/ai#2451](https://github.com/symfony/ai/pull/2451),
29 August 2026 — no patch is carried for it any more

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

Upstream took it as written, `capabilities.roots` removal included, and carried
it in the same pull request as the 2026-07-28 support — which is why a BC break
this small has an `UPGRADE.md` entry alongside a much larger one.

### 2. Completion providers with dependencies always failed

**Withdrawn**, 24 August 2026 — the patch is gone and no fix was needed

This was a real failure, and the diagnosis of it was wrong. Recorded because the
mistake is more instructive than the finding would have been.

`#[CompletionProvider(provider: TalkSlugCompletion::class)]` is resolved when a
`completion/complete` request arrives, out of the PSR-11 container the server was
built with:

```php
$provider = $this->container?->has($provider) ? $this->container->get($provider) : new $provider();
```

The completions never arrived, and the conclusion drawn was that the bundle's
service locator holds only element handlers, so `has()` returns false, the SDK
falls back to `new TalkSlugCompletion()`, and that dies with an
`ArgumentCountError` behind an opaque `-32603`. A patch autoconfigured every
`ProviderInterface` implementation into every server's locator, and the
completions worked, which looked like confirmation.

It was not. `McpPass` already puts a named provider into the locator:

```php
if (\in_array($tag, ['mcp.prompt', 'mcp.resource_template'], true)) {
    foreach ($this->completionProviderClasses($class, $method) as $providerClass) {
        $serviceReferences[$server][$providerClass] ??= new Reference($providerClass);
    }
}
```

The guard is the whole story. `completion/complete` carries a `ref/prompt` or a
`ref/resource` — [`CompletionCompleteRequest`](https://github.com/modelcontextprotocol/php-sdk/blob/main/src/Schema/Request/CompletionCompleteRequest.php)
accepts nothing else — so a provider named on a **tool** argument can never be
asked for anything. Excluding `mcp.tool` there is correct, not a gap.

What was actually broken was this demo: `ScheduleTool` and `TalkSearchTool`
annotate tool arguments with `#[CompletionProvider]`, and those annotations are
decoration. The completions that never arrived were ones the protocol has no way
to request.

The patch was retired after the series was rebuilt without it and every check in
the *completion* group passed unchanged — on prompt arguments and resource
template variables, with providers that each take a repository. See *A
`#[CompletionProvider]` on a tool argument is never called* below.

### 3. `ServerConnection` was missing three methods

**Landed upstream**, 24 and 29 August 2026 — no patch is carried for it any more

`ServerConnectionInterface` is the bundle's forwarding surface over the SDK's
`Mcp\Client`, and three requests had no forwarder: `complete()`,
`getProtocolVersion()` and `sendRootsListChanged()`. An application could not
reach them without reaching past the bundle to the raw client — and the first of
those is the client-side half of the completion providers the bundle spends
effort registering on the server side.

It went upstream in two pieces because only one of them could be shipped at the
time. `complete()` went as [symfony/ai#2441](https://github.com/symfony/ai/pull/2441),
against the released SDK; the other two name surface that existed only on the
SDK's branch, so they waited for [#2451](https://github.com/symfony/ai/pull/2451)
and the `mcp/sdk: ^0.8` bump that came with it. Splitting a patch by what its
dependencies can carry is the general lesson — the tests for the other two passed
here the whole time, because a DI test asserting on a class-name string never
loads the class.

### 4. The bundle could not serve 2026-07-28 at all

**Solved upstream differently** in [symfony/ai#2451](https://github.com/symfony/ai/pull/2451),
29 August 2026 — the patch is gone and the demo moved to upstream's design

Protocol revision 2026-07-28 is what the SDK now serves, and no Symfony
application could reach it. The bundle built `Builder::build()` onto
`StreamableHttpTransport` and nothing else; the modern lifecycle needed
`buildStateless()` onto `StatelessHttpTransport`, and there was no configuration
that got there.

Three builder calls were unreachable for the same reason — `setRequestState()`
(without which a multi-round-trip handler cannot remember anything between
rounds), `setCachePolicy()` (hints the revision *requires* on `server/discover`,
the list methods and `resources/read`) and `setNotificationBus()`.

The patch carried here added `lifecycle: handshake|stateless` per server, a
`StatelessMcpController` to mount the modern leg on, and compile-time refusal of
the combinations the revision forbids — a stateless server with STDIO, or with a
session store it has no use for. A server was in one era or the other, and you
picked.

**That premise is what upstream discarded, and it was the wrong one.** `mcp/sdk`
0.8 builds a dispatcher per era and has the HTTP transport classify every request
before anything else looks at it, so `Builder::build()` already carries both legs
and `McpController` already runs a transport that can route between them. There
is nothing for a second controller or a per-server mode to do. What upstream kept
is only the configuration the modern leg actually needs — `protocol_versions`,
`request_state`, `cache`, `subscriptions` — with the same option names and shapes
this demo had been using, so `config/packages/mcp.yaml` lost one line and changed
nothing else.

Two things followed from the change of design, and both are recorded rather than
patched:

- The `modern` server now answers handshake-era traffic too, because there is no
  way to narrow that leg. See *One endpoint serves both eras, and only one of them
  can be narrowed* below.
- `tasks:` did not survive into upstream's version, having already been carved out
  of the SDK. See *Tasks left the SDK* below.

The lesson worth keeping is about the shape of the finding rather than its
content. "The bundle cannot serve this revision" was right; "so it needs a second
lifecycle you configure per server" was a solution smuggled in alongside it, and
the demo asserted on it in two functional tests that had to be rewritten when the
better answer arrived.

### 5. A parameter's complete schema definition was nested instead of applied

**Landed upstream** on `mcp/sdk` `main`, 17 August 2026 — no patch is carried for
it any more

The only finding here that was the SDK's rather than the bundle's, and the only
one whose *patch* was taken upstream. `patches/php-sdk/` went with it, and so did
the SDK clone: the demo installs `dev-main`, and `main` has the fix. Everything
below is what it was for.

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

**Landed upstream** in [symfony/ai#2439](https://github.com/symfony/ai/pull/2439),
24 August 2026 — no patch is carried for it any more

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

The fix is one line: register the alias under the bare client name.

It was adjusted before merging to match the surrounding conventions — the first
version registered *both* spellings to avoid breaking anyone on `$researchClient`,
and that caution was unwarranted: `<name>Client` is not the form the rest of
Symfony uses, and the `clients:` configuration it belongs to is unreleased, so
there is nobody to break. The merged version replaces the spelling rather than
adding to it, with an `UPGRADE.md` entry and the `BC Break` label, and the direct
migration is renaming the argument:

```diff
 public function __construct(
-    private McpClientInterface $researchClient,
+    private McpClientInterface $research,
 ) {
 }
```

`#[Target('research')]` is the same alias under its other spelling, and the one to
reach for when the argument is named for its role rather than for the client.

One asymmetry survived: servers still autowire as `Mcp\Server $<name>Server`, so
after this the two sides of the bundle spell their aliases differently.

### 7. A configured notification bus was never written to

**Landed upstream** in [symfony/ai#2458](https://github.com/symfony/ai/pull/2458),
30 August 2026 — no patch is carried for it any more

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

Submitted as [symfony/ai#2458](https://github.com/symfony/ai/pull/2458). The
comment upstream carried next to the bus — *"The SDK publishes registry changes
itself"* — is the belief that makes this invisible, and the patch rewrites it
along with the code.

It could not land on its own, though: making the registry publish also made it
publish its own load. That is finding 9 below, and the reason this patch requires
`mcp/sdk: ^0.8.1`.

### 8. `request_state.key` could not come from the environment

**Landed upstream** in [symfony/ai#2459](https://github.com/symfony/ai/pull/2459),
30 August 2026 — no patch is carried for it any more

The key that signs what a multi-round-trip answer carries through the client is an
HMAC secret, and the bundle's own documentation shows it arriving the way a
Symfony secret does:

```yaml
request_state:
    key: '%env(MCP_REQUEST_STATE_KEY)%'
```

That configuration cannot be compiled. `cache:clear` fails with:

> The path `mcp.servers.modern.request_state.key` cannot contain an environment
> variable when empty values are not allowed by definition and are validated.

`VariableNode::finalizeValue()` refuses a registered env placeholder outright on
any node that is both `cannotBeEmpty()` and carries a `->validate()`, before the
validator runs at all. So the only expressible way to set the key was to inline
the secret in a committed file — for the one option in the bundle that is
explicitly a secret.

What makes it worth a write-up rather than a one-word fix is the second half.
Dropping `cannotBeEmpty()` is not enough: `ValidateEnvPlaceholdersPass`
re-processes the whole configuration with the placeholder *substituted for a
sample value*, so the length check then measures `''` and refuses that instead.
The guard the check already carried — a regex for `%…%` — cannot match at that
point, because the placeholder is gone by the time the validator sees it. A
config-tree validator is structurally unable to tell a short literal from an env
reference, which is exactly the distinction this check exists to make.

So the check moves out of the config tree and into
`McpBundle::configureModernEra()`, where `resolveEnvPlaceholders()` turns a
registered placeholder back into `%env(NAME)%` and a reference written in the
configuration is still in that form anyway. What is left to measure is exactly the
literals, which is what the documentation promises.

Not a BC break: every configuration that compiled before compiles now, and a short
literal key is still refused when the container is built — as the bundle's own
`LogicException` rather than an `InvalidConfigurationException`, in line with the
other compile-time refusals in `McpBundle`.

Upstream has a test for this already, `testAnEnvPlaceholderKeyIsNotMeasured`, and
it passes — because it builds the container by hand, and a container built by hand
never registers the placeholder. The patch adds the sibling that runs
`MergeExtensionConfigurationPass` and `ValidateEnvPlaceholdersPass`, which is the
path a kernel takes and the only one where the bug exists.

### 9. Loading a supplied registry announced every element as a change

**Landed upstream** in [modelcontextprotocol/php-sdk#490](https://github.com/modelcontextprotocol/php-sdk/pull/490),
released as `mcp/sdk` 0.8.1 — no patch is carried for it

The only finding here that was neither in the bundle nor visible until another
finding was fixed. Finding 7 made the bundle's registry publish its changes; this
is what that turned out to include.

Making the registry publish also made it publish its own *load*. `Registry`
suppresses list-changed events while `loading` is set, but that flag is only set
by `Registry::load()` — and `Builder::resolve()` loads a supplied registry by
calling `$chainLoader->load($registry)` directly, around the guard. So with the
patch applied and nothing else, every server build announced its whole element
list: 28 notifications per build on this demo's `modern` server, cross-process and
persisted on the `cache` bus, exhausting `Psr16NotificationBus`'s 256-entry
backlog in about nine requests — past which a lagging reader silently skips real
notifications. A fix for a bus that carried nothing had turned it into one that
carried noise.

The guard was the SDK's and so was the fix: split the guarded body of `load()`
into `loadFrom(LoaderInterface $loader)` and route the custom-registry branch
through it. Finding 7's patch therefore also moves the bundle's constraint to
`mcp/sdk: ^0.8.1`; below that it makes things worse rather than better.

That is also why *a registry change reaches the stream* is provoked by
[`announce_tool`](../src/Mcp/Tool/Modern/RegistryChangeTool.php) rather than by an
ordinary request. Before 0.8.1 the check passed on the boot burst — it was
asserting on the bug, and its own comment said so. A tool that registers a tool
is the honest provocation, and the notification outliving the request that caused
it is the demonstration: the registered tool is gone with the build, the
notification reaches a stream held open by another worker.

The shape of it is worth more than the fix. A patch that makes a broken feature
work is not finished when the feature works, and the demo's own regression suite
could not tell the difference — it had been written to provoke on the burst, so it
passed throughout. Review caught the rest: the first version of the fix reused the
once-only `loaded` flag for a foreign loader, which silently dropped a constructor
loader's elements and made a second load a no-op. Neither shows up in a test suite
that nobody thought to write.

---

## Not patched, but worth knowing

### One endpoint serves both eras, and only one of them can be narrowed

`protocol_versions: ['2026-07-28']` reads like "this server speaks only the modern
revision". It does not. It narrows the *modern* leg to that revision and leaves
the handshake leg negotiating over everything the SDK knows, because the SDK
offers no way to narrow it — `setProtocolVersion()` pins the handshake to exactly
one revision or leaves it open, with nothing in between. The option's own
description says so.

So the demo's `modern` server, which exists to be a clean subject for the
2026-07-28 suite, also answers handshake-era clients:

```console
$ # a request naming 2025-11-25 is not "unsupported version" — it is routed to the
$ # era that owns it, and told it has no session
-32600  A valid session id is REQUIRED for non-initialize requests.

$ # only a revision the SDK does not know at all reaches the modern leg's refusal
-32022  Unsupported protocol version  {"requested":"2027-01-01","supported":["2026-07-28"]}
```

The starkest form of it is `initialize`, which the revision removed:

```console
$ curl -sX POST localhost:8099/mcp/2026 -d '{"jsonrpc":"2.0","id":1,"method":"initialize", …}'
{"result":{"protocolVersion":"2025-06-18", … "serverInfo":{"name":"symfonycon-2026"} …}}
```

A request that makes no modern claim never reaches the leg the method is gone
from, so the server named for the revision hands back a handshake session on a
revision it does not list. The same shows through on the HTTP verbs: `GET` and
`DELETE` are classified as handshake-era session operations before anything looks
at the body, so `DELETE` answers `400 Mcp-Session-Id header is required` rather
than the `405` a modern-only endpoint would give.

It is also the one place this bites a human rather than a suite: the MCP
Inspector defaults to *Legacy* negotiation, so connecting to `/mcp/2026` with the
default now succeeds — against the wrong era, quietly. See
[`release-checklist.md`](release-checklist.md).

This is arguable rather than broken — one endpoint serving both eras is the design,
and it is what lets a single application serve clients of either era without
configuration. It is recorded because "narrow to one revision" is the natural
reading of the option and it is not what happens. Pinned by
[`ModernLifecycleTest`](../tests/Functional/ModernLifecycleTest.php), which asserts
both refusals so a future change to the classification is visible here.

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
whose `registry:` lists name them. Loader-registered elements go through nothing.
So this configuration —

```yaml
diagnostics:
    registry:
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

### What of the SDK's server builder the bundle still does not expose

`Mcp\Server\Builder` grew a lot with the 2026-07-28 work, and for a while the
bundle reached about half of it. Most of the gap closed on 29 August 2026:
`setProtocolVersion()`, `setCachePolicy()`, `setRequestState()`,
`setNotificationBus()` and `setSubscriptionLifetime()` all have configuration now,
and `buildStateless()` stopped being something to expose at all once one server
object started carrying both eras.

What is left with no configuration option and no other way in:

| Builder method | What it gates |
|---|---|
| `setLazyLoading()` | deferring element registration |
| `setResourceSubscriptionManager()` | the store behind `resources/subscribe`, as opposed to its lifetime |
| `enableExtension()` | any extension other than MCP Apps, which `McpAppPass` enables implicitly — Tasks is the one that will want it |

`protocol_versions` covers the handshake era only in the sense that it can pin it
to a single revision; there is no way to offer a subset, which is the SDK's
limitation rather than the bundle's. See *One endpoint serves both eras* above.

### A `#[CompletionProvider]` on a tool argument is never called

The attribute is accepted on any parameter, and on a `#[McpTool]` argument it
does nothing at all. `completion/complete` takes a `ref/prompt` or a
`ref/resource`; there is no tool reference in the protocol, so nothing can ever
ask for those completions.

Nothing warns about it. The bundle registers the tool, the SDK reads the
attribute, and the suggestions simply never appear — which reads as a broken
provider rather than an unreachable one, and cost this repository a wrong
diagnosis and a withdrawn upstream pull request (finding 2 above).

This demo keeps the annotations on [`ScheduleTool`](../src/Mcp/Tool/Organizer/ScheduleTool.php)
and [`TalkSearchTool`](../src/Mcp/Tool/Programme/TalkSearchTool.php) deliberately,
as the demonstration of exactly that: they are the same providers that work on
prompts and templates, on parameters the protocol cannot reach.

### The bundle's servers and clients landed on `main` — 22 August 2026

The demo used to build on two forks: `chr-hertel/php-sdk@2026spec-findings` for
the SDK and `chr-hertel/ai@mcp-bundle-servers-and-clients` for the bundle. Both
are now merged. `mcp/sdk` is installed from Packagist as `dev-main`, and the
bundle clone tracks `symfony/ai` `main`.

Two things are worth knowing about the move, because neither is visible in a
diffstat.

**The capability lists moved under `registry:`.** What the demo wrote as five
keys on a server —

```yaml
tools: ['App\Mcp\Tool\Programme\']
prompts: ['*']
```

— upstream nests, and in exchange accepts one list covering every kind:

```yaml
registry:
    tools: ['App\Mcp\Tool\Programme\']
    prompts: ['*']

registry: ['App\Mcp\']     # equivalent to naming that prefix for all five
registry: '*'                # everything
```

`config/packages/mcp.yaml` moved with it, and so did the fixtures in the two test
files `patches/` adds. Nothing about the meaning changed: they are still service
ids, FQCNs, namespace prefixes or `*`.

**Four of the then-six patches applied untouched; two did not.** `0005` rejected
exactly one hunk, because the validation block it anchored on had moved into the
new `registry` node — the two stateless transport refusals were re-placed on the
server prototype by hand. `0006` then applied cleanly on top of it. `0003` and
`0004` still applied as they were. `0004` has since been withdrawn — reading
`CompletionProvider` on `main` and concluding it had *not* landed was the same
mistake in the other direction, and finding 2 above is the write-up. `0003` was
real: `main` carried only the `<name>Client` spelling of the alias, until #2439.

The numbering in this paragraph is the series as it stood on 22 August. Four of
those patches have since landed and the two that remain were renumbered `0001` and
`0002` — patch numbers are positions in a series, not identities, and the findings
above are what to cite.

### The bundle serves 2026-07-28 on `main` — 29 August 2026

The largest of the patches this demo carried is gone, replaced by
[symfony/ai#2451](https://github.com/symfony/ai/pull/2451), which solves the same
problem with a different shape — see finding 4 above for the design and what it
cost the demo. Three things about the move are worth recording on their own.

**Four of the five patches went in the same window.** `0001` (client roots) and
the remaining two thirds of `0002` (`getProtocolVersion()`,
`sendRootsListChanged()`) travelled inside #2451, because they name SDK surface
that had no release until `mcp/sdk` 0.8; `0003` went as #2439 and the `complete()`
third of `0002` as #2441, four days earlier, because those did not. The series went
from five patches to two in five days; the note below is the day it went to none.

**The SDK constraint moved with it.** `src/mcp-bundle/composer.json` now requires
`mcp/sdk: ^0.8`, so the demo's stability alias moved from `dev-main as 0.7.99` to
`dev-main as 0.8.99`, and `bin/link-sdk` with it. A version alias is a claim about
which release a branch stands in for, and it goes stale exactly when the branch
tags a new one.

**One config line changed and one did not.** `lifecycle: stateless` is gone,
because there is no longer such a thing. Everything else on the `modern` server —
`protocol_versions`, `request_state`, `cache`, `subscriptions` — upstream took with
the same names and shapes, which is the part of a patch that survives a redesign.

### `patches/` went empty — 30 August 2026

The last two patches landed as [symfony/ai#2458](https://github.com/symfony/ai/pull/2458)
and [#2459](https://github.com/symfony/ai/pull/2459), both merged within two minutes
of each other, and both applied to `main` byte for byte as they had been carried
here. Findings 7 and 8 above are the write-ups; the diffs are gone.

**One of the two arrived one layer down.** #2458 made the notification bus work,
and a working bus is what made the SDK's unguarded registry load visible — every
`build()` put one `list_changed` on the bus per registered element, and under
`bus: cache` that is cross-process, so a handful of boots exhausted the 256-entry
backlog for every open stream. That is finding 9, fixed in
[php-sdk#490](https://github.com/modelcontextprotocol/php-sdk/pull/490) and
released as `mcp/sdk` 0.8.1. #2458 requires it: without it the patch turns an
inert bus into a noisy one. A fix that only becomes reachable once another fix
lands is the argument for a demo that runs the whole surface at once.

**What the demo lost and had to replace.** `ModernRegressionRunner`'s
`subscriptions/listen` check was passing on the bug — the boot burst was its
provocation, so it would have gone on passing with the feature entirely broken.
It now calls `announce_tool` (`App\Mcp\Tool\Modern\RegistryChangeTool`), a
runtime registration on a second connection, which since 0.8.1 is the only thing
on that server that writes to the bus. A check whose provocation is a defect is
worse than no check.

**The clone stays.** Nothing to apply is not nothing to track: 2026-07-28 is on
`main` and in no release, so `upstream/` and `make upstream-check` keep doing the
job they did before. `bin/export-patches` holds an empty `PATCHES`, which makes any
change in the clone an error rather than a silent no-op export.

### A nullable parameter generates `type: [...]`, which clients may not read

Found on 30 August 2026 by the Inspector, which had started saying so:

```
Schema portability: 0 errors, 9 warnings across 3 tools.
```

`?string $query` on a tool method generates

```json
{"type": ["null", "string"]}
```

and the Inspector's advice is to split it:

> The array form is legal JSON Schema, but several MCP clients read `type` as a
> single string and either reject the tool or drop the constraint.

Nine warnings across `search_talks`, `browse_schedule` and `back_to_schedule` —
every nullable argument, plus one nullable `object` in an output schema. It is
deliberate in `SchemaGenerator`, which has a dozen sites maintaining the array
form for the nullable case, so this is a design decision and not a slip.

**Not patched, because it is arguable rather than broken.** The array form is
valid, the Inspector grades it a warning and not an error, and `anyOf` is a
different shape for the same contract rather than a fix. It is also the SDK's,
and the SDK carries no patches here. Pinned instead: `a nullable parameter keeps
the array type form` in `RegressionRunner` asserts the spelling on `search_talks`,
so a move to `anyOf` shows up as a failing check rather than as a silent change in
what hosts receive.

Worth knowing if a host ever drops one of this demo's optional arguments: that is
what it would look like, and it is the schema and not the bundle.

**The Inspector is unpinned, and it moved.** `npx -y` fetches the newest version
every run, and the version that added this summary broke `make inspector-tour`:
the line is printed after the JSON result, so `json.load()` over the whole stream
failed on three of thirteen steps — every `tools/list`. The tour's error path
already used `raw_decode` for exactly this reason, having been bitten by the CLI
appending its own error object; the success path now does too. A tour driven by an
unpinned reference client is worth the maintenance, because a client changing
under the demo is a thing the demo exists to notice — but only if the failure is
legible, and "not JSON" was not.

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

**Resolved upstream** on `mcp/sdk` `main`, 18 August 2026

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

### Tasks left the SDK on 19 August 2026

The SDK's 2026-07-28 work carried the Tasks extension (SEP-2663) — `tasks/get`, a durable
handle instead of a held-open connection, two stores — and this demo
demonstrated it: `tasks: { store: cache }` on the `modern` server, an
`audit_schedule` tool, two regression checks and two functional tests.

It is gone from `main`, carved out into PR #428. The SDK's own
`spec-report.md` says so in three places; nothing was lost, it just travels
separately now. `Mcp\Server\Task\*`, `Mcp\Schema\Task`, `TaskStatus` and
`RequestContext::supportsTasks()` all went with it, which is a compile-time break
rather than a behavioural one: the `modern` server could not be built at all, and
sixteen tests failed with *Class "Mcp\Server\Task\TasksExtension" not found*.

So the demo's tasks surface is parked, not deleted-and-forgotten. Restoring it
means putting back, in this order:

| Where | What |
|---|---|
| `symfony/mcp-bundle` | a `tasks:` config node, `taskStore()`, `enableExtension(new TasksExtension(...))` — upstream's 2026-07-28 support has no tasks surface either, so this is a pull request now, not a patch |
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
