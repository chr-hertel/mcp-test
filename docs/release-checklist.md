# Release checklist

A manual walkthrough of every scenario this demo is built to show. Run it before
tagging, after bumping either upstream branch, or whenever you want to know that
the whole thing still works rather than that the tests still pass.

The automated suites are the first two phases. Everything after them exists
because it covers something no test can: what a real host does, what a rendered
app looks like, and whether a human can follow the instructions.

| Phase | Needs | Time |
|---|---|---|
| 0–2 · reset, automated gates, console | nothing | ~5 min |
| 3 · Inspector CLI | Node | ~2 min |
| 3b · protocol revision 2026-07-28 | — | ~2 min |
| 4 · Inspector UI | Node, a browser | ~10 min |
| 5 · Claude Desktop | the desktop app | ~15 min |
| 6 · browser | — | ~2 min |
| 7 · reproduction | a clean directory | ~5 min |

**Short on time?** Phases 0–3 plus phase 7 catch everything mechanical. Phases 4
and 5 are the ones that catch "it works but nobody could use it", and phase 5 is
the only place elicitation, sampling and roots run against a *real* model.

---

## 0. Reset to a known state

The regression suite writes: it submits proposals, reviews one, imports the
workspace inbox and reschedules a talk. Starting dirty makes half the expected
numbers below wrong, and the CFP inbox accumulates a
`A Proposal From The Regression Suite <hex>` row on every run.

- [ ] `make db` — recreates and re-seeds `dev`, `test` and `prod`, and warms the prod cache
- [ ] `php bin/console dbal:run-sql "SELECT (SELECT COUNT(*) FROM talk) talks, (SELECT COUNT(*) FROM slot) slots, (SELECT COUNT(*) FROM proposal) proposals"`

Expected: **14 talks, 12 slots, 4 proposals**. Two talks are unscheduled on
purpose — `schedule_talk` needs candidates.

---

## 1. Automated gates

Nothing below this line is worth doing if these are red.

- [ ] `make check` → **66 tests / 232 assertions OK**, then the STDIO regression:
      `conference_stdio 35 passed`, `organizer_stdio 40 passed`, **0 failed**
- [ ] `make serve` — then `make regression` → all six connections plus the raw probes, **0 failed**

  | Connection | passed | skipped |
  |---|---|---|
  | `conference_http` | 35 | 1 |
  | `organizer_http` | 40 | 1 |
  | `diagnostics_http` | 18 | 7 |
  | `conference_stdio` | 35 | 1 |
  | `organizer_stdio` | 40 | 1 |
  | `modern_http` | 35 | 3 |
  | `modern (raw)` | 26 | 0 |

- [ ] `make upstream-test` → `mcp/sdk` **1512 tests OK**, `symfony/mcp-bundle` **178 tests OK**

  These run the *upstream* suites against the patched clones. A failure here
  means a patch broke something, not that the demo did.

- [ ] `make upstream-check` → `up to date` for both clones

  This fetches and compares against the branch tips. It is the one check here
  that talks to the network, and it has to: everything else in this phase runs
  against the clone you already have, so a branch that moved this morning looks
  perfectly green until phase 7.

  A `MOVED` line means the branch advanced. Re-derive the patches against the new
  tip, re-run the upstream suites, re-export, and update `docs/patches.md` —
  `CLAUDE.md` has the procedure.

- [ ] `make apply-patches` → `in place` or `overlaps` for all six, never `applied`

  `in place` means the patch is already in the clone, `applied` is normal on a
  fresh clone but suspicious here, and `overlaps` means the question cannot be
  answered for that patch: its lines are context a later patch rewrote, so it
  reverse-applies only together with them. **`FAILED` is fatal** — nothing in
  the series fits either way, so upstream moved underneath it.

- [ ] `bin/export-patches --dry-run` → `the series reproduces the working tree`,
      then the bundle's own suite green in the pristine clone

  The authoritative version of the check above, and the only one that settles an
  `overlaps`. It costs a clone and a `composer install`; the phase-1 gate does
  not, which is why both are here.

> The counts move whenever a check is added. If they are off by a few but
> nothing failed, update this file rather than chasing it.

---

## 2. The console surface

What someone sees before they connect anything.

- [ ] `make debug` (`debug:mcp`) — four servers:

  | Server | tools | prompts | resources | templates |
  |---|---|---|---|---|
  | `conference` | 9 | 4 | 7 | 4 |
  | `organizer` | 16 | 4 | 7 | 4 |
  | `diagnostics` | 6 | — | 2 | — |
  | `modern` | 13 | 4 | 7 | 4 |

  and **no** *Not exposed by any server* section. If one appears, a class
  carries an MCP attribute that no capability list matches — usually a typo in a
  namespace prefix.

- [ ] `php bin/console debug:mcp search_talks` — one element with its full input schema
- [ ] `make clients` (`mcp:client:debug`) — `regression` with five servers, `modern` and `minimal` with one each
- [ ] `php bin/console mcp:client:debug regression conference_stdio` — connects, prints
      server info, the instructions block, and all four capability lists
- [ ] `make claude-config` — absolute paths, the right PHP binary, `APP_ENV=prod`
- [ ] `php bin/console debug:router | grep mcp` — four routes:
      `_mcp_endpoint_conference`, `_mcp_endpoint_organizer`, `_mcp_endpoint_diagnostics`,
      `_mcp_endpoint_modern`

---

## 3. The Inspector CLI

The reference client, which knows nothing about this application.

- [ ] `make inspector-tour` → **✓ 13 steps, all answered**

  Read the output rather than just the last line. Each step names what it is
  showing; the interesting ones are 2 (a schema generated from a PHP signature),
  4 (`isError` rather than a protocol failure), 5 (three content types in one
  result) and 10 (`--app-info` reporting what `#[AsMcpApp]` generated).

- [ ] `make inspector-cli SERVER=organizer ARGS='--method tools/list'` — the bearer
      token is injected; 16 tools come back
- [ ] `make inspector-cli SERVER=diagnostics ARGS='--method tools/call --tool-name probe_client'`
      — `roots=true`, everything else `false`. That is the CLI being
      non-interactive, not a bug.

---

## 3b. Protocol revision 2026-07-28

The reason the SDK branch exists. `make serve` first.

- [ ] `make regression-2026` → `modern_http` **35 passed / 3 skipped**, then the
      raw probes **26 passed**, **0 failed** either side

  Two runs over one revision. `modern_http` is the ordinary suite driven by the
  SDK's own client with `protocol_version: '2026-07-28'` — the same checks as
  every other connection, skipping `ping` and `logging/setLevel` because the
  revision removed them. The raw probes are nine groups (discovery, lifecycle,
  caching, notifications, subscriptions, MRTR, headers, apps, removals) driven by
  `ModernClient`, which sends what a conforming client will not.

  The subscriptions group takes about ten seconds of the run on its own: each
  stream has to be waited out, because the built-in server delivers a body only
  when it closes — [`deployment.md`](deployment.md) §3.

- [ ] `php bin/console mcp:client:debug modern modern_http` — connects with no
      `initialize` on the wire, and prints the server info and 13 tools

- [ ] `server/discover` answers without a handshake, and advertises the MCP Apps
      extension:

  ```console
  $ curl -sS http://127.0.0.1:8099/mcp/2026 \
      -H 'Content-Type: application/json' -H 'Accept: application/json' \
      -H 'MCP-Protocol-Version: 2026-07-28' -H 'Mcp-Method: server/discover' \
      -d '{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{"_meta":{
            "io.modelcontextprotocol/protocolVersion":"2026-07-28",
            "io.modelcontextprotocol/clientCapabilities":{}}}}' | head -c 400
  ```

  Expected: `supportedVersions: ["2026-07-28"]`, `ttlMs: 3600000`,
  `cacheScope: "public"`, and `io.modelcontextprotocol/ui` under `extensions`.
  Not `io.modelcontextprotocol/tasks` — see *Tasks left the branch* in
  [`patches.md`](patches.md).

- [ ] No `Mcp-Session-Id` comes back from any call to `/mcp/2026`
- [ ] The same tool, both eras: `submit_proposal` on `/mcp/organizer` blocks on an
      elicitation, and on `/mcp/2026` returns `resultType: "input_required"` with a
      signed `requestState`. Same feature, opposite direction.
- [ ] A `GET` or `DELETE` on `/mcp/2026` answers **405** — there is no session to
      open a stream on, and none to tear down. `subscriptions/listen` is what
      replaced the GET stream:

  ```console
  $ curl -sSN -X POST http://127.0.0.1:8099/mcp/2026 \
      -H 'Content-Type: application/json' -H 'Accept: text/event-stream' \
      -H 'MCP-Protocol-Version: 2026-07-28' -H 'Mcp-Method: subscriptions/listen' \
      -d '{"jsonrpc":"2.0","id":"sub-1","method":"subscriptions/listen","params":{
            "notifications":{"toolsListChanged":true},"_meta":{
            "io.modelcontextprotocol/protocolVersion":"2026-07-28",
            "io.modelcontextprotocol/clientCapabilities":{}}}}'
  ```

  Expected, five seconds later and all at once: an
  `notifications/subscriptions/acknowledged` frame naming subscription `sub-1`
  and agreeing to `toolsListChanged` alone, keep-alive comments, then a closing
  result. Nothing arrives before the close — that is the server, not a hang.

- [ ] In the Inspector UI, connect to `/mcp/2026` with version negotiation set to
      **Auto** or **Modern (2026-07-28, sessionless)** — the default is *Legacy*,
      which sends `initialize` and is refused with `-32602`. Then open
      `browse_schedule`: the MCP App renders over the modern lifecycle too.

> The Inspector's **CLI** has no flag for the era and is pinned to legacy, so
> `make inspector-tour` covers only the handshake servers.

---

## 4. The Inspector UI

The eyes-on phase. `make serve`, then `make inspector` and open the printed URL
(the token is part of it).

**Connect** to `http://127.0.0.1:8099/mcp` over Streamable HTTP.

- [ ] Server info and the `instructions` block render
- [ ] **Tools → `search_talks`** — the form has `track` and `level` as dropdowns
      (from PHP enums), `limit` bounded 1–50, `day` with its pattern. The result
      carries both `structuredContent` and text.
- [ ] **`get_talk`** with `slug: messenger-at-scale` — text, a `resource_link`, and
      an embedded `schedule://…` resource
- [ ] **`get_talk`** with `slug: nope` — `isError: true` with a readable message,
      *not* a red protocol failure
- [ ] **Prompts → `promote_talk`** — type `mess` in `slug`; completions arrive from
      the database. The rendered prompt names the talk and applies the platform's
      character limit.
- [ ] **Resources** — `conference://badge.png` renders as an image;
      `schedule://full` as Markdown; `info://day/1` exists (registered at runtime
      by a loader, not by an attribute)
- [ ] **Resource Templates** — `talk://{slug}` completes its variable and resolves
- [ ] **Tools → `browse_schedule`** with `day: 2026-11-19` — **the app renders in an
      iframe**: a table, day/track selectors, an *Open* button per talk
- [ ] Click **Open** on a talk → the detail view replaces it (that is the
      `open_talk` tool called from inside the iframe)
- [ ] Click **Back to the schedule** → returns (that is `back_to_schedule`, which
      is `appOnly` and *not* in `tools/list`)
- [ ] Change the day selector and submit → the table updates, and the selectors
      keep their values

**Reconnect** to `http://127.0.0.1:8099/mcp/diagnostics`.

- [ ] **`emit_progress`** with `steps: 10` — progress arrives incrementally, not all
      at the end. This is the SSE path; if it stalls, see
      [`deployment.md`](deployment.md).
- [ ] **`fail_on_purpose`** `mode: tool_error` → a result with `isError: true`
- [ ] **`fail_on_purpose`** `mode: exception` → JSON-RPC `-32603`, and the original
      exception message is **not** in the response
- [ ] **`get_test_image`** — the PNG renders

**Reconnect** to `http://127.0.0.1:8099/mcp/organizer`.

- [ ] Without the header → **401**, with a JSON-RPC error body rather than an HTML page
- [ ] With `Authorization: Bearer <MCP_DEMO_ORGANIZER_TOKEN>` → connects, 16 tools
- [ ] Configure a **root** in the Inspector's Roots pane, then call
      **`list_client_roots`** → the server reports what you configured
- [ ] **`schedule_talk`** — `slug: testing-what-matters`, `room: Studio A`,
      `day: 2026-11-20`, `startTime: 14:00` → `status: scheduled`
- [ ] The same call again → *Talk "testing-what-matters" is already scheduled in
      Studio A at 2026-11-20 14:00. Unschedule it first.*
- [ ] Now `slug: running-a-local-user-group` into the same room at `14:15` →
      *Studio A is busy from 14:00 to 14:45 with "Testing What Matters".*
      Both are real conflicts, not something to retry with force.
- [ ] **`unschedule_talk`** with `slug: testing-what-matters` → frees the slot again

**Over STDIO**, with no web server involved:

- [ ] `make inspector-stdio` — connects and lists; the same nine tools

---

## 5. Claude Desktop

The only phase where elicitation, sampling and roots run against a real model.
Full walkthrough in [`claude-desktop.md`](claude-desktop.md).

- [ ] `make claude-config`, merge into `claude_desktop_config.json`, **fully quit and
      reopen** the app
- [ ] Both servers appear connected, with no error badge
- [ ] Ask: *"Which talks are about queues or messaging?"* → it calls `search_talks`
- [ ] Ask: *"Show me the schedule for the 19th"* → **the app renders in the chat**,
      and its buttons work
- [ ] The `+` menu lists the four prompts under the server's name; run
      **`introduce_speaker`** and check the bio is the real one from the database
- [ ] The `+` menu offers resources; attach `conference://current`
- [ ] Ask: *"Submit a proposal titled X about Y"* → **an elicitation form appears**
      asking for speaker name, email and code-of-conduct consent. Fill it →
      the proposal is recorded.
- [ ] Decline the same form on a second attempt → nothing is written
- [ ] Ask: *"Review proposal 1"* → **your model writes the review** (sampling), and
      it is stored
- [ ] Ask: *"Import proposals from my workspace"* → it asks for your folders
      (roots), then imports the two `*.proposal.md` files from `workspace/`
- [ ] Ask it to schedule a talk into a slot that is taken → it reports the
      conflict rather than inventing success

Then check nothing leaked into the protocol stream:

- [ ] `~/.config/Claude/logs/` (Linux) shows the server's stderr logging, and no
      parse errors

---

## 6. The browser

- [ ] <http://127.0.0.1:8099> — the overview renders; the three server panels match
      what `debug:mcp` printed in phase 2
- [ ] Expand a server's *Tools* / *Prompts* / *Resources* — read out of the running
      container, so it cannot drift
- [ ] <http://127.0.0.1:8099/schedule> — both days switch; the unscheduled list
      matches `list_unscheduled_talks`
- [ ] <http://127.0.0.1:8099/speakers> — eight speakers with their talks
- [ ] The MCP panel in the web profiler (the toolbar's MCP icon) lists every
      server's capabilities

---

## 7. Reproduction from scratch

The thing most likely to be quietly broken, because nobody runs it twice.

```console
$ git clone <this repo> /tmp/mcp-demo-check && cd /tmp/mcp-demo-check
$ make upstream          # clones both branches, applies patches/
$ composer install
$ make db
$ make check
```

- [ ] Every step succeeds with no manual intervention
- [ ] `make upstream` reports `applied` for all six patches, not `skipped`
- [ ] `make check` is green in the clone

---

## Sign-off

| | |
|---|---|
| Version tagged | |
| `mcp/sdk` ref | Packagist `dev-main` @ |
| `symfony/mcp-bundle` ref | `symfony/ai` `main` @ |
| Phases run | 0–7 / 0–3 + 7 |
| Deviations | |

Record both upstream commit SHAs — the demo tracks moving tips, not releases, so
"which commit did this pass against" is the only meaningful answer to "does this
still work". The SDK's is in `composer.lock` rather than a clone, because it is
installed rather than checked out.

```console
$ composer info mcp/sdk | grep source
$ git -C upstream/symfony-ai rev-parse --short HEAD
```

---

## When something fails

| Symptom | Look at |
|---|---|
| An HTTP round trip hangs forever | worker count — [`deployment.md`](deployment.md) §1 |
| `make upstream-check` says `MOVED` | the branch advanced — re-derive the patches, see `CLAUDE.md` |
| `apply-patches` says `FAILED` | same cause, found later; the patch fits neither forwards nor back |
| `app:seed` fails only in `prod` with `Too few arguments` | a stale prod container — `APP_ENV=prod bin/console cache:clear` |
| A host shows garbled responses | something wrote to stdout — [`deployment.md`](deployment.md) §4 |
| Counts are off but nothing failed | a check was added; update this file |
| The Inspector CLI exits non-zero on a working call | `isError` does that — [`inspector.md`](inspector.md) |
| A tool call times out on first use in a host | `dev` container compile; use `prod` |
