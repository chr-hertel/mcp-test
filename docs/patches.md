# Upstream findings

Everything in this file was found by building the demo and then pointing its own
MCP client at its own MCP servers — `make regression`. Four problems needed a
code change; three more are behaviours worth knowing about but not obviously
wrong.

The patches live in [`patches/`](../patches) and are applied to the clones under
`upstream/` by `make apply-patches`. Each one applies cleanly to a pristine
checkout of the branch it targets, in file-name order:

```console
$ make upstream          # clone both branches and apply every patch
$ make apply-patches     # re-apply after re-cloning; skips what is already in
$ make export-patches    # dump the clones' working trees back out to patches/
```

Base branches:

| Package | Repository | Branch |
|---|---|---|
| `mcp/sdk` | [chr-hertel/php-sdk](https://github.com/chr-hertel/php-sdk) | `2026spec-findings` ([PR #3](https://github.com/chr-hertel/php-sdk/pull/3)) |
| `symfony/mcp-bundle` | [chr-hertel/ai](https://github.com/chr-hertel/ai) | `mcp-bundle-servers-and-clients` ([PR #44](https://github.com/chr-hertel/ai/pull/44)) |

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

### 4. `#[Target('<client>')]` did not resolve

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

---

## Not patched, but worth knowing

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

### `session.store: cache` needs `psr/simple-cache`

Choosing the cache-backed session store makes the bundle register
`Symfony\Component\Cache\Psr16Cache`, which implements `Psr\SimpleCache\CacheInterface`.
`symfony/cache` only *suggests* `psr/simple-cache`, so on an application that has
not installed it the container blows up at runtime with:

> Attempted to load interface "CacheInterface" from namespace "Psr\SimpleCache".

Nothing in the bundle's configuration reference mentions the dependency. Either
`symfony/mcp-bundle` should require `psr/simple-cache`, or the docs should say
so next to the `cache` store. This demo simply requires it.
