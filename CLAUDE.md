# Working in this repository

This is a demo application for `mcp/sdk` and `symfony/mcp-bundle`. Its job is to
exercise both libraries widely enough that an upstream regression shows up here
as a failing check. Optimise for *demonstrating clearly*, not for the shortest
code.

## Before anything else

`upstream/` is gitignored and must exist:

```console
$ make upstream    # clone both branches, apply patches/
$ make setup       # composer install + seed dev, test and prod
```

## The loop

```console
$ make check                        # PHPUnit + regression over STDIO — no web server
$ make serve && make regression     # adds the three HTTP connections
$ make upstream-test                # the upstream suites, against the patched clones
```

`make check` is the gate. It needs no network and no web server, because the
STDIO connections spawn `bin/console mcp:server` themselves.

## Changing the upstream libraries

Edit the clones under `upstream/` directly, then keep `patches/` in sync — the
clones are disposable, the patches are the artefact:

1. make the change in `upstream/php-sdk` or `upstream/symfony-ai`;
2. add or update a test **in that upstream repository**, and run its own suite;
3. re-export the patch (`make export-patches` dumps the working tree; split and
   name it by logical change, one concern per file);
4. verify the whole series still applies to a pristine clone:
   `git clone --branch <ref> … /tmp/verify && cd /tmp/verify && git apply <each patch>`;
5. write it up in `docs/patches.md` — what was wrong, how it failed, what the
   fix is, and whether it is a BC break.

A patch that leaves an upstream suite red is not finished.

If a behaviour is arguable rather than broken, do **not** patch it. Pin it with
a regression check so a future change is visible, and record it under
*Not patched, but worth knowing* in `docs/patches.md`.

## Adding to the MCP surface

- Put a tool in the namespace matching the server that should expose it:
  `App\Mcp\Tool\Programme\` (read-only), `\Organizer\` (writes), `\Diagnostics\`
  (protocol probes). The capability lists in `config/packages/mcp.yaml` are
  namespace prefixes, and a prefix matching nothing fails the build.
- Throw `ToolCallException` for anything the model could plausibly fix. Any
  other throwable becomes an opaque protocol error — see `docs/patches.md`.
- Return shapes belong in `ProgrammePresenter`, not inline. Tools, resources and
  the app templates share them, and the regression suite asserts on them.
- New concepts need a regression check in `RegressionRunner`, guarded by
  `hasTool()` so the suite still runs against servers that do not expose it.

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
