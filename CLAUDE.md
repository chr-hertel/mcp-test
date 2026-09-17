# Working in this repository

This is a demo application for `mcp/sdk` and `symfony/mcp-bundle`. Its job is to
exercise both libraries widely enough that an upstream regression shows up here
as a failing check. Optimise for *demonstrating clearly*, not for the shortest
code.

## Before anything else

`upstream/` is gitignored and must exist:

```console
$ make upstream    # clone symfony/ai, apply patches/
$ make setup       # composer install + seed dev, test and prod
```

## The loop

```console
$ make check                        # PHPUnit + regression over STDIO — no web server
$ make serve && make regression     # adds the three HTTP connections
$ make chat                         # the chat host at /chat, in a browser
$ make upstream-test                # the bundle's own suite, against the patched clone
```

`make check` is the gate. It needs no network and no web server, because the
STDIO connections spawn `bin/console mcp:server` themselves.

To see the protocol rather than assert on it, use the reference client:

```console
$ make inspector-tour               # narrated CLI walk over the whole surface
$ make inspector                    # the UI; the only way to watch the MCP App render
$ make inspector-cli ARGS='--method tools/call --tool-name search_talks --tool-arg query=ai'
```

Reach for it when a single call is misbehaving — it prints the raw envelope.
`docs/inspector.md` has the rest.

Before tagging, work through `docs/release-checklist.md` — it covers what the
automated suites cannot: a real host, a rendered app, and whether the setup
instructions still work in a clean directory. Anything you add that changes a
count or adds a scenario belongs in that file too.

## Changing the upstream libraries

`mcp/sdk` comes from Packagist as `dev-main` and carries no patches — if it needs
one, that is a finding to raise upstream, not something to vendor here. The
bundle is a clone under `upstream/`, and there `patches/` is the artefact while
the clone is disposable:

1. make the change in `upstream/symfony-ai`;
2. add or update a test **in that upstream repository**, and run its own suite;
3. re-export the patch (`make export-patches` dumps the working tree; split and
   name it by logical change, one concern per file);
4. verify the whole series still applies to a pristine clone:
   `git clone --branch <ref> … /tmp/verify && cd /tmp/verify && git apply <each patch>`;
5. write it up in `docs/patches.md` — what was wrong, how it failed, what the
   fix is, and whether it is a BC break.

A patch that leaves an upstream suite red is not finished.

**When the branch moves under you** (`make upstream-check` says `MOVED`, or
`make apply-patches` says `FAILED`):

1. `git -C upstream/symfony-ai fetch origin main && git -C upstream/symfony-ai reset --hard FETCH_HEAD`
2. re-apply the series; the ones that still fit will, the rest you re-derive by
   hand against the new tip
3. run that repository's own suite, then `make check`
4. re-export and verify the series against a pristine clone of the **new** tip
5. note the move in `docs/patches.md` if it changed anything semantic

Do not pin the clone to an old SHA to make a patch fit. The demo exists to track
the tip; a patch that no longer applies is the finding.

If a behaviour is arguable rather than broken, do **not** patch it. Pin it with
a regression check so a future change is visible, and record it under
*Not patched, but worth knowing* in `docs/patches.md`.

## Adding to the MCP surface

- Put a tool in the namespace matching the server that should expose it:
  `App\Mcp\Tool\Programme\` (read-only), `\Organizer\` (writes), `\Diagnostics\`
  (protocol probes). The `registry:` lists in `config/packages/mcp.yaml` are
  namespace prefixes, and a prefix matching nothing fails the build.
- Throw `ToolCallException` for anything the model could plausibly fix. Any
  other throwable becomes an opaque protocol error — see `docs/patches.md`.
- Return shapes belong in `ProgrammePresenter`, not inline. Tools, resources and
  the app templates share them, and the regression suite asserts on them.
- New concepts need a regression check in `RegressionRunner`, guarded by
  `hasTool()` so the suite still runs against servers that do not expose it.
- If a new concept is worth *showing*, add a step to `bin/inspector-tour` too —
  that script is the demo's guided tour, and it should stay complete.

## Adding to the chat host

`/chat` is the client half with a user in front of it: `src/Chat/` (the host),
`src/Twig/Components/` (the page), `config/packages/ai.yaml` (the agent). Read
`docs/chat.md` before changing any of it.

- The chat must keep working with **no API key**. `ScriptedPlatform` is what makes
  that true, and `make check` covers the whole path because of it — a change that
  only works against a real model is a change that is not covered.
- Anything the host learns about a server is read *from* the server at request
  time (`HostSurface`). Do not hard-code a tool, prompt or resource into the page.
- A capability the host does not advertise is a demonstration, not a gap: the
  missing elicitation handler is why `submit_proposal` degrades there. Say so in
  `docs/chat.md` rather than quietly adding a handler.
- New behaviour needs a test next to the others in `tests/Unit/Chat` or
  `tests/Functional/Chat*Test.php`, not a check in `RegressionRunner` — that suite
  asserts what a *server* answers.

## Conventions

- Fixture data is fixed and asserted on. Changing `ProgrammeSeeder` means
  changing tests; that is intended.
- Anything that must be seedable in `prod` cannot live in `src/DataFixtures` —
  DoctrineFixturesBundle is a dev dependency.
- Never write to stdout from anything a STDIO server can reach.
  `StdioStreamPurityTest` will catch it; save yourself the round trip.
- Commit messages: what changed and why it had to. No AI co-author trailers.

## Reading order for someone new

`config/packages/mcp.yaml` → `docs/architecture.md` → `docs/patches.md` →
`src/Mcp/Regression/RegressionRunner.php`.

For the host half: `config/packages/ai.yaml` → `docs/chat.md` →
`src/Chat/ChatHost.php`.
