# MCP Demo — the PHP MCP SDK and Symfony, end to end

A Symfony application that is an MCP **server** and an MCP **client** at the same
time, built on the upstream it is meant to exercise:

| Package | Source | Why |
|---|---|---|
| [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk) | Packagist, `dev-main` | The 2026-07-28 surface is on `main` and we carry no patches against it. |
| [`symfony/mcp-bundle`](https://github.com/symfony/ai) | clone of `main` | Everything this demo needed has landed; `patches/` is empty. |

Both track a moving tip on purpose: `dev-main` re-resolves on every install, and
`make upstream-check` fails the moment the bundle's `main` moves past the clone.

The domain is a conference programme — talks, speakers, rooms, a schedule and a
CFP inbox. It is deliberately ordinary, so that everything interesting on the
page is MCP.

The demo points its own client at its own servers. That is what makes
`make regression` a real end-to-end check of both libraries rather than a set of
mocks agreeing with each other, and it is how the upstream problems in
[`docs/patches.md`](docs/patches.md) were found.

```console
$ make upstream      # clone symfony/ai into upstream/, apply patches/
$ make setup         # composer install + seed dev, test and prod databases
$ make check         # PHPUnit + the regression suite over STDIO — no web server needed
$ make serve         # http://127.0.0.1:8099
```

---

## What is demonstrated, and where

### Server

| Concept | Where |
|---|---|
| Tool from an invokable class | [`TalkSearchTool`](src/Mcp/Tool/Programme/TalkSearchTool.php) |
| Several tools on one class | [`ProgrammeTools`](src/Mcp/Tool/Programme/ProgrammeTools.php) |
| Input schema from a native enum, `#[Schema]` constraints, declared `outputSchema` | [`TalkSearchTool`](src/Mcp/Tool/Programme/TalkSearchTool.php) |
| `ToolAnnotations` (read-only, destructive, idempotent, open-world) and icons | [`TalkSearchTool`](src/Mcp/Tool/Programme/TalkSearchTool.php), [`ScheduleTool`](src/Mcp/Tool/Organizer/ScheduleTool.php) |
| `Content[]` results: text, `resource_link`, embedded resource, image | [`ProgrammeTools`](src/Mcp/Tool/Programme/ProgrammeTools.php), [`DiagnosticsTool`](src/Mcp/Tool/Diagnostics/DiagnosticsTool.php) |
| Tool errors the model can act on (`ToolCallException`) | everywhere; see also [`docs/patches.md`](docs/patches.md) |
| Progress notifications and per-request logging via `RequestContext` | [`ScheduleTool`](src/Mcp/Tool/Organizer/ScheduleTool.php), [`DiagnosticsTool`](src/Mcp/Tool/Diagnostics/DiagnosticsTool.php) |
| Prompts — three return shapes, with an embedded resource | [`ConferencePrompts`](src/Mcp/Prompt/ConferencePrompts.php) |
| Resources — JSON, Markdown, binary blob | [`ConferenceResources`](src/Mcp/Resource/ConferenceResources.php) |
| Resource templates — `talk://{slug}`, `speaker://{slug}`, `schedule://{day}`, `track://{track}` | [`ProgrammeResourceTemplates`](src/Mcp/Resource/ProgrammeResourceTemplates.php) |
| Argument completion from the database | [`src/Mcp/Completion/`](src/Mcp/Completion) |
| MCP Apps — a rendered screen, HTML over the wire, follow-up tools | [`ScheduleApp`](src/Mcp/App/ScheduleApp.php), [`templates/mcp/`](templates/mcp) |
| Registering elements at runtime with a `LoaderInterface` | [`HouseKeepingLoader`](src/Mcp/Loader/HouseKeepingLoader.php) |
| Listening to the SDK's capability-changed events | [`CapabilityAuditListener`](src/Mcp/Event/CapabilityAuditListener.php) |
| Pagination — `conference` is configured below its own tool count on purpose | [`config/packages/mcp.yaml`](config/packages/mcp.yaml) |
| Several servers with different capability sets, sessions and routes | [`config/packages/mcp.yaml`](config/packages/mcp.yaml) |
| Access control on an MCP endpoint | [`OrganizerTokenAuthenticator`](src/Security/OrganizerTokenAuthenticator.php), [`security.yaml`](config/packages/security.yaml) |

### Client

| Concept | Where |
|---|---|
| Connecting over HTTP and over STDIO | [`config/packages/mcp.yaml`](config/packages/mcp.yaml) |
| Answering `roots/list` | [`WorkspaceRootsProvider`](src/Mcp/Client/WorkspaceRootsProvider.php) |
| Answering `sampling/createMessage` | [`ScriptedSamplingHandler`](src/Mcp/Client/ScriptedSamplingHandler.php) |
| Answering `elicitation/create`, including decline and cancel | [`ScriptedElicitationHandler`](src/Mcp/Client/ScriptedElicitationHandler.php) |
| Three clients: different capabilities, and different protocol revisions | `regression`, `modern` and `minimal` in [`mcp.yaml`](config/packages/mcp.yaml) |
| Driving a server from application code | [`RegressionRunner`](src/Mcp/Regression/RegressionRunner.php) |

### Protocol revision 2026-07-28

No `initialize`, no session, and — the part that reshapes handler code — no
server-initiated requests. Not a mode a server is in: the SDK classifies each
request and routes it to the era it belongs to, so one endpoint serves both.

| Concept | Where |
|---|---|
| A server narrowed to the revision, on the same controller as the rest | `protocol_versions` on `modern` in [`mcp.yaml`](config/packages/mcp.yaml) |
| Per-request protocol version, capabilities and trace context | [`ModernLifecycleTool::describeRequest()`](src/Mcp/Tool/Modern/ModernLifecycleTool.php) |
| **MRTR** — returning the ask instead of sending it, with signed `requestState` | [`ModernLifecycleTool::submitProposal()`](src/Mcp/Tool/Modern/ModernLifecycleTool.php) |
| Cache hints (`ttlMs` / `cacheScope`), which the revision requires | `cache:` in [`mcp.yaml`](config/packages/mcp.yaml) |
| `x-mcp-header` — an argument mirrored into `Mcp-Param-*` and checked | [`ModernLifecycleTool::searchTrack()`](src/Mcp/Tool/Modern/ModernLifecycleTool.php) |
| Opt-in progress and logging on the request's own stream | [`ModernLifecycleTool::reindexProgramme()`](src/Mcp/Tool/Modern/ModernLifecycleTool.php) |
| MCP Apps over the modern lifecycle | `apps:` on the `modern` server in [`mcp.yaml`](config/packages/mcp.yaml) |
| The SDK's client on the modern wire — one option, same API | `protocol_version` on the `modern` client in [`mcp.yaml`](config/packages/mcp.yaml) |
| Subscriptions (SEP-2575) and the refusals a conforming client never triggers | [`ModernClient`](src/Mcp/Modern/ModernClient.php) |
| A registry change announced to a stream another worker is holding open | [`RegistryChangeTool`](src/Mcp/Tool/Modern/RegistryChangeTool.php) |

> **Tasks** (SEP-2663) used to be here too. The SDK carved it out into a PR of its
> own, so the demo's tasks surface is parked until it lands —
> [`docs/patches.md`](docs/patches.md) lists what to put back.
>
> The `Lifecycle` column above is what a server *answers for*, not a mode it is in.
> `modern` narrows the modern leg to one revision; the handshake leg cannot be
> narrowed at all, so it answers that era too — see [`docs/patches.md`](docs/patches.md).

Compare `submit_proposal` on the two servers: the handshake-era one calls
`$gateway->elicit()` and blocks; the modern one returns an `InputRequiredResult`
and is called twice. Same feature, opposite direction.

```console
$ make regression-2026     # 25 checks, over real HTTP
```

### Both ends of the same round trip

The three server-initiated requests are the hardest part of MCP to see working,
because they need a server and a client that both implement them. Here they run
against each other in one repository:

| Round trip | Server side | Client side |
|---|---|---|
| `roots/list` | [`WorkspaceTool`](src/Mcp/Tool/Organizer/WorkspaceTool.php) | [`WorkspaceRootsProvider`](src/Mcp/Client/WorkspaceRootsProvider.php) |
| `elicitation/create` | [`ProposalTool::submitProposal()`](src/Mcp/Tool/Organizer/ProposalTool.php) | [`ScriptedElicitationHandler`](src/Mcp/Client/ScriptedElicitationHandler.php) |
| `sampling/createMessage` | [`ProposalTool::reviewProposal()`](src/Mcp/Tool/Organizer/ProposalTool.php) | [`ScriptedSamplingHandler`](src/Mcp/Client/ScriptedSamplingHandler.php) |

---

## The three servers

Every server is built from the same attributed services. Which of them a server
exposes is configuration, not code — see each server's `registry:` block in
`config/packages/mcp.yaml`.

| Server | Endpoint | Lifecycle | STDIO | Exposes |
|---|---|---|---|---|
| `conference` | `/mcp` | handshake | yes | everything read-only, plus the MCP App |
| `organizer` | `/mcp/organizer` | handshake | yes | the above plus scheduling, the CFP inbox and workspace imports |
| `diagnostics` | `/mcp/diagnostics` | handshake | no | protocol probes, progress streams and tools that fail on purpose |
| `modern` | `/mcp/2026` | **2026-07-28**, and handshake | no | the same programme over protocol revision **2026-07-28** |

`organizer` sits behind a bearer-token firewall over HTTP. Over STDIO it is
unauthenticated: the process boundary *is* the boundary there, and whoever
launched the process already had the shell.

```console
$ php bin/console debug:mcp                 # what each server exposes
$ php bin/console debug:mcp --server=organizer
$ php bin/console debug:mcp search_talks    # one element, with its input schema
```

---

## Regression testing the upstream libraries

This is the part to steal. `app:mcp:regression` drives the bundle's MCP client
against this application's own MCP servers, over a real transport, and reports
what still works:

```console
$ make regression-stdio        # spawns bin/console mcp:server itself; no web server
$ make serve && make regression # adds the four HTTP connections and the raw 2026-07-28 probes
$ php bin/console app:mcp:regression organizer_stdio   # one connection, verbosely
```

```
 ------ --------------------- ---------------------------------------- --------------------------------------------
  pass   tools                 schema constraint is enforced            rejected as a protocol error
  pass   completion            completion/complete (prompt argument)    3 suggestion(s) for "messenger"
  pass   client capabilities   roots/list round trip                    2 root(s): mcp-demo, cfp-inbox
  pass   client capabilities   elicitation round trip                   the scripted answer reached the handler
  pass   apps                  the app tool renders HTML on the server  5671 characters of server-rendered HTML
```

Every check is one round trip through the whole stack: the bundle's client, the
SDK's client, a transport, the bundle's server wiring, the SDK's protocol
handler. A check whose subject a server does not expose is skipped rather than
failed, so the same suite runs against all three servers.

The same checks run under PHPUnit, alongside three other angles:

| Suite | What it covers |
|---|---|
| [`StdioRegressionTest`](tests/Regression/StdioRegressionTest.php) | the regression runner over a spawned process |
| [`HttpTransportTest`](tests/Functional/HttpTransportTest.php) | raw JSON-RPC: handshake, session ids, error codes, cross-server session replay |
| [`GeneratedSchemaTest`](tests/Functional/GeneratedSchemaTest.php) | what the SDK makes of a PHP method signature |
| [`StdioStreamPurityTest`](tests/Functional/StdioStreamPurityTest.php) | that nothing but JSON-RPC reaches stdout, even at `-vvv` |
| [`ModernLifecycleTest`](tests/Functional/ModernLifecycleTest.php) | protocol revision 2026-07-28: discovery, MRTR, cache hints, what was removed |

```console
$ make test          # 25 tests, no web server, no network
$ make upstream-test # the upstream libraries' own suites, against the patched clones
```

---

## Try it

### With the MCP Inspector

The [MCP Inspector](https://github.com/modelcontextprotocol/inspector) is the
reference client. `npx` fetches it; nothing to install.

```console
$ make serve
$ make inspector-tour   # thirteen CLI calls, narrated, over the whole surface
$ make inspector        # the UI — and the only way to see the MCP App render
```

The tour walks the surface with a client that knows nothing about this
application, so what you read is the protocol rather than a chat window:

```
 3. Calling a tool, with structured output
    The declared outputSchema is why the result carries structuredContent as well as text.
    → 1 hit(s): Messenger at Scale

 5. A result mixing three content types
    Text, a resource_link to the speaker, and an embedded schedule resource.
    → text, resource_link, resource

10. The MCP App, without invoking it
    --app-info reads the UI metadata #[AsMcpApp] generated.
    → {"hasApp":true,"resourceUri":"ui://schedule","visibility":["model","app"],"prefersBorder":true}
```

Individual calls, against any of the three servers:

```console
$ make inspector-cli ARGS='--method tools/call --tool-name search_talks --tool-arg query=messenger'
$ make inspector-cli SERVER=organizer ARGS='--method tools/list'   # adds the bearer token
$ make inspector-stdio SERVER=conference                            # no web server at all
```

In the UI, open `browse_schedule` on the `conference` server: the Inspector runs
a sandbox origin and renders the MCP App in a real iframe, buttons and all.
Everything else worth clicking is in [`docs/inspector.md`](docs/inspector.md).

### From the console

```console
$ php bin/console debug:mcp                                # what each server exposes
$ php bin/console mcp:client:debug regression conference_stdio   # connect and list
$ php bin/console app:mcp:regression                       # exercise everything
```

### In a browser

`make serve`, then <http://127.0.0.1:8099> — the same programme rendered for
people, with the MCP surface of every server introspected out of the running
container.

### All of it, in order

[`docs/release-checklist.md`](docs/release-checklist.md) walks every scenario the
demo is built to show, with the expected output at each step — the automated
gates, the console, both halves of the Inspector, a real host, the browser, and a
from-scratch reproduction. It is written to be re-run before tagging, and it is
the only place the elicitation, sampling and roots round trips are exercised
against a real model rather than a scripted handler.

---

## Using it from Claude Desktop

```console
$ php bin/console app:claude-desktop:config
```

prints the `mcpServers` fragment with absolute paths, plus what to do before
connecting. Full walkthrough in [`docs/claude-desktop.md`](docs/claude-desktop.md).

---

## Documentation

| | |
|---|---|
| [`docs/patches.md`](docs/patches.md) | what building this found in the upstream libraries, and the patches |
| [`docs/claude-desktop.md`](docs/claude-desktop.md) | connecting a host, and what to ask it |
| [`docs/release-checklist.md`](docs/release-checklist.md) | the manual walkthrough of every scenario, for before you tag |
| [`docs/inspector.md`](docs/inspector.md) | driving the servers with the MCP Inspector, UI and CLI |
| [`docs/deployment.md`](docs/deployment.md) | what MCP asks of a PHP process: workers, sessions, stdout |
| [`docs/architecture.md`](docs/architecture.md) | how the pieces fit, and why they are arranged this way |

---

## Layout

```
config/packages/mcp.yaml     four servers, three clients — the centre of the demo
src/Entity, src/Repository   the conference domain: ordinary Doctrine
src/Mcp/Tool/                tools, split by which server exposes them
src/Mcp/Prompt/              prompts
src/Mcp/Resource/            resources and resource templates
src/Mcp/App/                 MCP Apps
src/Mcp/Completion/          argument completion providers
src/Mcp/Client/              the client-side handlers: roots, sampling, elicitation
src/Mcp/Regression/          the regression runner
templates/mcp/               the MCP App shell and its fragments
patches/                     what had to change upstream (empty; all of it landed)
upstream/                    the symfony/ai clone (gitignored; `make upstream`)
workspace/                   the CFP inbox the client advertises as a root
```

## Requirements

PHP 8.2+ with `ext-fileinfo`, Composer, git. SQLite — no database server. The
`symfony` CLI for `make serve`; anything that runs `public/index.php` will do
otherwise, subject to [`docs/deployment.md`](docs/deployment.md).
