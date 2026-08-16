# How this is put together

## The shape

```
                       ┌──────────────────────────────────────────┐
                       │            src/Entity, src/Repository     │
                       │        Conference · Talk · Speaker ·      │
                       │        Room · Slot · Proposal             │
                       └───────────────┬──────────────────────────┘
                                       │  ordinary Doctrine, no MCP anywhere
                    ┌──────────────────┴───────────────────┐
                    │                                      │
          ┌─────────▼──────────┐                ┌──────────▼─────────┐
          │  src/Controller    │                │     src/Mcp        │
          │  Twig, for people  │                │  attributes, for   │
          └────────────────────┘                │  models            │
                                                └──────────┬─────────┘
                                                           │
                          config/packages/mcp.yaml decides which server exposes what
                    ┌──────────────────────┬───────────────┴──────────┐
              ┌─────▼─────┐          ┌─────▼──────┐            ┌──────▼──────┐
              │ conference│          │ organizer  │            │ diagnostics │
              │  /mcp     │          │/mcp/organizer            │/mcp/diagnostics
              │  + stdio  │          │  + stdio   │            │  http only  │
              └─────┬─────┘          └─────┬──────┘            └──────┬──────┘
                    └────────────────┬─────┴──────────────────────────┘
                                     │
                       ┌─────────────▼──────────────┐
                       │   mcp.clients.regression   │
                       │   five connections, back   │
                       │   at those same servers    │
                       └─────────────┬──────────────┘
                                     │
                       ┌─────────────▼──────────────┐
                       │  src/Mcp/Regression        │
                       │  app:mcp:regression        │
                       │  tests/Regression          │
                       └────────────────────────────┘
```

## Why the layers are where they are

**No MCP in the domain.** `src/Entity` and `src/Repository` do not know MCP
exists. Everything protocol-shaped lives in `src/Mcp`, and the one place both
meet is [`ProgrammePresenter`](../src/Service/ProgrammePresenter.php), which
turns entities into the arrays the tools, the resources and the app templates all
return. Having exactly one definition of those shapes is what makes the
regression suite able to assert on them.

**Tools split by exposure, not by kind.** `src/Mcp/Tool/Programme`,
`.../Organizer` and `.../Diagnostics` exist so the capability lists in
`mcp.yaml` can be namespace prefixes:

```yaml
tools:
    - 'App\Mcp\Tool\Programme\'
    - 'App\Mcp\Tool\Organizer\'
```

A tool moves between servers by moving between namespaces, and the compiler pass
fails the build if a prefix matches nothing. The alternative — listing classes
one by one — drifts the first time someone adds a tool.

**Three servers rather than one.** A single server with everything on it would
demonstrate less and be less safe. Three shows that identity, transports,
session storage, instructions and capability set are per server; that access
control is a plain firewall against a stable path; and that a tool whose whole
purpose is to fail (`fail_on_purpose`) can exist without a general-purpose host
ever seeing it.

**The client points back at the server.** Five connections, all to this same
application: three over HTTP, two by spawning `bin/console mcp:server`. That is
what makes the regression suite an end-to-end check of both upstream libraries
instead of a set of mocks agreeing with each other — and it is how the four
problems in [`patches.md`](patches.md) surfaced.

The `minimal` client exists to prove the negative: it configures no roots,
sampling or elicitation handler, so the same servers see a client that cannot do
any of those, and the tools that need them have to degrade rather than fail.

## The two ways to reach a server, and when to use which

| | STDIO | Streamable HTTP |
|---|---|---|
| Who launches it | the host, as a child process | already running |
| Sessions | the process is the session | a session id in a shared store |
| Concurrency | one client, one process | many, needs ≥2 workers for round trips |
| Auth | the process boundary | a Symfony firewall |
| Used by | Claude Desktop, CI | MCP Inspector, remote hosts |

The regression suite runs over both, because they fail differently: STDIO
catches anything that writes to stdout or that assumes a warm container, HTTP
catches session handling, headers, status codes and the fiber/SSE machinery.

## Three ways to drive a server, and why all three exist

1. **The bundle's client** ([`RegressionRunner`](../src/Mcp/Regression/RegressionRunner.php)) —
   what an application would actually use. Reads like application code, and
   covers the client half of the stack as a side effect.
2. **Raw JSON-RPC over the test browser**
   ([`JsonRpcBrowser`](../tests/Support/JsonRpcBrowser.php)) — sends exactly the
   bytes it is told to, which is what a test of the *transport* needs: a missing
   header, a stale session id, a notification that must not be answered.
3. **A spawned process over a pipe**
   ([`StdioStreamPurityTest`](../tests/Functional/StdioStreamPurityTest.php)) —
   the only way to see what actually reaches stdout.

Each catches things the others cannot. The completion-provider bug was invisible
to (2) and (3), because a raw request never resolves a provider from the
container; the stdout question is invisible to (1) and (2), because both read a
parsed message rather than a stream.

## The regression runner's one rule

A check whose subject the server does not expose is **skipped**, never failed.
What a server exposes is a configuration decision, so a suite that failed on
absence would only be asserting that this particular `mcp.yaml` has not changed.
Skipping means the same 40-odd checks can be pointed at any of the three servers
— and at a fourth one you add — and still say something true.

The counterweight is in
[`StdioRegressionTest`](../tests/Regression/StdioRegressionTest.php): it also
asserts a *minimum number of passes*, so a configuration mistake that skips
everything cannot pass as green.

## Determinism

The scripted sampling and elicitation handlers exist because the demo has to run
with no API key and produce the same bytes on every run. `ScriptedSamplingHandler`
derives its score from a CRC of the proposal title; `ScriptedElicitationHandler`
answers from a script a test sets, falling back to the requested schema's own
defaults so the demo also works when nobody scripted anything.

Swapping in a real model is a one-class change: implement the same SDK interface
over Symfony AI's `Agent`/`Platform` and point `mcp.clients.regression.sampling`
at it.

Likewise the fixture programme in
[`ProgrammeSeeder`](../src/Service/ProgrammeSeeder.php) is fixed data with fixed
dates — the regression suite asserts on those exact rows, so changing them means
changing tests. It is a plain service rather than a Doctrine fixture because
DoctrineFixturesBundle is a dev dependency and the STDIO servers Claude Desktop
launches run in `prod`.
