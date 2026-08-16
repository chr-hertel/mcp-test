# Using the MCP Inspector

The [MCP Inspector](https://github.com/modelcontextprotocol/inspector) is the
reference client for poking at a server by hand. It is the fastest way to see
what this application actually advertises, and — unlike Claude Desktop — it
shows you the protocol rather than a chat window.

It comes in two shapes, and both are worth knowing:

| | |
|---|---|
| **UI** | a browser app; click through tools, prompts, resources, and *render* MCP Apps |
| **CLI** | one method per invocation, JSON on stdout; scriptable, and what to reach for when something is wrong |

Nothing needs installing — `npx` fetches it. Node 18+ is the only requirement.

---

## The UI

```console
$ make serve       # the HTTP endpoints, with enough workers (see docs/deployment.md)
$ make inspector   # npx @modelcontextprotocol/inspector
```

It prints something like:

```
MCP Inspector Web is up and running at:
   http://localhost:6274?MCP_INSPECTOR_API_TOKEN=…

   Sandbox (MCP Apps): http://localhost:40749/sandbox

   Auth token: …
```

Open the printed URL — the token is part of it, and the plain
`http://localhost:6274` will refuse to talk to the proxy without it.

### Connecting

**Over HTTP.** In the left-hand panel choose *Streamable HTTP* and enter:

| Server | URL | Extra |
|---|---|---|
| `conference` | `http://127.0.0.1:8099/mcp` | — |
| `organizer` | `http://127.0.0.1:8099/mcp/organizer` | header `Authorization: Bearer organizer-demo-token` |
| `diagnostics` | `http://127.0.0.1:8099/mcp/diagnostics` | — |

The token is `MCP_DEMO_ORGANIZER_TOKEN` from `.env`; add it under
*Authentication → Header Name / Bearer Token*, or as a custom header.

**Over STDIO.** No web server needed. Choose *STDIO* and set:

- Command: the absolute path to your PHP binary (`php bin/console app:claude-desktop:config` prints it)
- Arguments: `<project>/bin/console mcp:server conference`

`make inspector-stdio` starts the Inspector already pointed at that.

### What to look at first

1. **Server info and instructions**, right after connecting. The `instructions`
   block is what a model reads before it does anything — worth seeing rendered.
2. **Tools → `search_talks`**. The Inspector builds a form from the input schema,
   so this is where you see the generated schema as a *user* would: `track` and
   `level` as dropdowns (from PHP enums), `limit` with its 1–50 bounds, `day`
   with its pattern. Then look at *Output Schema* and at the result's
   `structuredContent`.
3. **Tools → `get_talk`**. The result mixes three content types: text, a
   `resource_link` to the speaker, and an embedded `schedule://…` resource. Give
   it `slug: nope` to see a tool error — `isError: true` with a message the model
   can act on, *not* a red protocol failure.
4. **Prompts → `promote_talk`**. Type `mess` into the `slug` field: the
   suggestions come from `completion/complete`, answered from the database by
   [`TalkSlugCompletion`](../src/Mcp/Completion/TalkSlugCompletion.php).
5. **Resources**. `conference://badge.png` comes back as a `blob` and is rendered
   as an image; `schedule://full` as Markdown. Under *Resource Templates*, try
   `talk://{slug}` — the variable completes too.
6. **Tools → `browse_schedule`** (see below).

On the `diagnostics` server:

- **`probe_client`** tells you what the Inspector itself advertises. As of
  writing the CLI reports `roots: true`, everything else `false`; the UI adds
  more once you configure it.
- **`emit_progress`** with `steps: 10` — watch the progress notifications arrive
  one at a time over SSE.
- **`fail_on_purpose`** with each of its two modes, side by side. `tool_error`
  gives a result with `isError: true`; `exception` gives JSON-RPC `-32603` with
  the original message deliberately withheld. The difference is explained in
  [`patches.md`](patches.md).

### Seeing the MCP App

This is the thing the Inspector does that nothing else here can. It runs a
sandbox origin (the second URL it prints) and renders `text/html;profile=mcp-app`
resources in a real iframe.

Call **`browse_schedule`** on the `conference` server with `day: 2026-11-19`.
Instead of text you get the rendered schedule: a table, a day/track filter, and
an *Open* button per talk. Clicking *Open* calls the `open_talk` tool from inside
the iframe and swaps in the detail view; *Back to the schedule* calls
`back_to_schedule`, which is `appOnly` and therefore not in `tools/list`.

All of that markup is rendered by Twig **on the server** and shipped in the tool
result's `html` field — see
[`ScheduleApp`](../src/Mcp/App/ScheduleApp.php) and
[`templates/mcp/`](../templates/mcp). There is no application JavaScript; the
`data-call` / `data-arg-*` attributes are wired by the bundle's base template.

---

## The CLI

One method per invocation, JSON on stdout. Everything below is copy-pasteable
against a running `make serve`.

```console
$ npx @modelcontextprotocol/inspector --cli http://127.0.0.1:8099/mcp \
    --transport http --method tools/list
```

`make inspector-cli` wraps that, so:

```console
$ make inspector-cli ARGS='--method tools/list'
$ make inspector-cli SERVER=organizer ARGS='--method tools/list'
```

### The guided tour

`make inspector-tour` runs thirteen of these calls in order, each with a line
saying what it demonstrates. It is the fastest way to see the whole surface
through a client that knows nothing about this application:

```console
$ make serve && make inspector-tour
```

```
 1. What the conference server advertises
    tools/list — nine tools, across two pages of five. The Inspector follows the cursor.
    → 9 tools: get_venue_information, browse_schedule, open_talk, …

 2. A schema generated from a PHP method signature
    search_talks: track and level are native enums, limit carries #[Schema] bounds.
    → track=['backend', 'frontend', 'devops', 'architecture', 'community', 'ai', None]  limit in [1, 50]

 …

12. What the Inspector itself advertises
    probe_client, on the diagnostics server. Sampling and elicitation need an interactive client.
    → protocol 2025-11-25 — roots=true, sampling=false, elicitation=false, tasks=false

✓ 13 steps, all answered.
```

It is a demonstration, not a test — it prints rather than asserts. `make regression`
is the one that fails a build.

### The methods, with this demo's arguments

```console
# list everything
--method tools/list
--method prompts/list
--method resources/list
--method resources/templates/list

# call a tool
--method tools/call --tool-name search_talks --tool-arg query=messenger
--method tools/call --tool-name search_talks --tool-arg track=ai --tool-arg limit=5
--method tools/call --tool-name get_talk --tool-arg slug=doctrine-without-tears

# arguments that are not strings need the JSON form
--method tools/call --tool-name emit_progress --tool-args-json '{"steps":3}'

# read a resource, static or through a template
--method resources/read --uri conference://current
--method resources/read --uri talk://rag-pipelines-in-php
--method resources/read --uri schedule://2026-11-19

# render a prompt
--method prompts/get --prompt-name promote_talk \
    --prompt-args slug=messenger-at-scale platform=bluesky

# what the server logs at a given level
--method logging/setLevel --log-level debug
```

Against STDIO the target is a command rather than a URL, and the transport is
auto-detected:

```console
$ npx @modelcontextprotocol/inspector --cli php bin/console mcp:server conference \
    --method tools/list
```

Against the privileged server, add the header:

```console
$ npx @modelcontextprotocol/inspector --cli http://127.0.0.1:8099/mcp/organizer \
    --transport http --header "Authorization: Bearer organizer-demo-token" \
    --method tools/list
```

### Probing an MCP App without invoking it

`--app-info` reads a tool's UI metadata and stops there:

```console
$ npx @modelcontextprotocol/inspector --cli http://127.0.0.1:8099/mcp \
    --transport http --method tools/call --tool-name browse_schedule --app-info
```

```json
{"hasApp":true,"toolName":"browse_schedule","resourceUri":"ui://schedule",
 "visibility":["model","app"],"prefersBorder":true,
 "resourceMimeType":"text/html;profile=mcp-app"}
```

That is an independent confirmation of what `#[AsMcpApp]` generated: the linked
resource URI, the visibility the host is expected to honour, and the
`prefersBorder` content hint. Run it against `back_to_schedule` and
`visibility` is `["app"]` instead.

`--method tools/list --app-info` emits one line per tool, which makes it a
one-liner for "which of these are apps".

---

## Gotchas

**DNS rebinding protection.** The SDK only accepts requests whose `Origin` or
`Host` names `localhost`, `127.0.0.1` or `[::1]`. The Inspector's proxy connects
server-side, so a local endpoint just works — but pointing it at a remote
deployment needs `http.allowed_hosts` on that server (see
[`deployment.md`](deployment.md)), otherwise every request is a bare `403
Forbidden: Invalid Host header` with no JSON-RPC envelope.

**Pagination.** `conference` is configured with `pagination_limit: 5` on purpose
and has nine tools. The Inspector follows the cursor and shows all nine, so the
paging is invisible — which is the point. To see the pages themselves, use
`curl` or read
[`HttpTransportTest::testToolsListPaginatesAndTheCursorReachesEverything()`](../tests/Functional/HttpTransportTest.php).

**Server-to-client round trips need a second worker.** `list_client_roots`,
`submit_proposal` and `review_proposal` on the `organizer` server send a request
*back* to the Inspector mid-call. Over HTTP that deadlocks on a single-worker PHP
server; `make serve` sets `PHP_CLI_SERVER_WORKERS`. Over STDIO it is not an
issue. Full explanation in [`deployment.md`](deployment.md).

**The Inspector is not a model.** Sampling (`review_proposal`) needs a client
that can run a completion; the CLI advertises none, so the tool degrades to
"write it by hand" — which is the correct behaviour, not a failure. To see the
round trip actually complete, use this application's own client:

```console
$ php bin/console app:mcp:regression organizer_stdio
```

**`isError` makes the CLI exit non-zero.** A tool result carrying
`isError: true` is a *successful* protocol exchange, but the CLI treats it as a
failure: it prints the result, appends
`{"error":{"code":"tool_is_error","message":"Tool 'x' returned isError:true."}}`,
and exits non-zero. Worth knowing before you put it in a script — see how
`run_tool_error()` in [`bin/inspector-tour`](../bin/inspector-tour) handles it.

**A stale `dev` container.** The first STDIO call in `dev` can outlive the
Inspector's connect timeout while Symfony compiles. Either warm it
(`php bin/console cache:warmup`) or point the Inspector at `prod`.

---

## When to use which

| Question | Tool |
|---|---|
| What does this server look like to a person? | Inspector UI |
| Does my MCP App render, and do its buttons work? | Inspector UI (it has the sandbox) |
| Why is this one tool call failing? | Inspector CLI — it prints the raw envelope |
| Does the whole surface still work after an upstream change? | `make regression` |
| Does the transport handle a malformed request correctly? | `make test` |

The Inspector is for looking; the regression suite is for knowing.
