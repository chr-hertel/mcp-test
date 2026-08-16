# Connecting Claude Desktop

Claude Desktop speaks MCP over STDIO: it launches your server as a child
process and talks JSON-RPC over its stdin and stdout. Nothing needs to be
listening on a port, and nothing needs to be publicly reachable.

## 1. Prepare the environment the host will launch

Claude Desktop launches the server from *its* working directory with *its*
environment, not yours. So the paths have to be absolute, and the environment
the server will find has to already work:

```console
$ make setup                                   # seeds dev, test and prod, warms the prod cache
```

or, by hand:

```console
$ APP_ENV=prod php bin/console app:seed
$ APP_ENV=prod php bin/console cache:warmup
```

`prod` matters more here than usual: every tool call pays for the container, and
in `dev` that includes a file-modification check on every request.

## 2. Generate the configuration

```console
$ php bin/console app:claude-desktop:config
```

which prints something like:

```json
{
    "mcpServers": {
        "symfonycon-programme": {
            "command": "/usr/bin/php8.5",
            "args": ["/path/to/mcp-demo/bin/console", "mcp:server", "conference"],
            "env": {
                "APP_ENV": "prod",
                "DATABASE_URL": "sqlite:////path/to/mcp-demo/var/data_prod.db"
            }
        },
        "symfonycon-organizer": {
            "command": "/usr/bin/php8.5",
            "args": ["/path/to/mcp-demo/bin/console", "mcp:server", "organizer"],
            "env": { "…": "…" }
        }
    }
}
```

Useful flags:

```console
$ php bin/console app:claude-desktop:config --json                # no prose, pipe-friendly
$ php bin/console app:claude-desktop:config --only=conference     # read-only server only
$ php bin/console app:claude-desktop:config --app-env=dev         # for debugging
```

## 3. Merge it into the host's configuration

| Platform | File |
|---|---|
| Linux | `~/.config/Claude/claude_desktop_config.json` |
| macOS | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Windows | `%APPDATA%\Claude\claude_desktop_config.json` |

Merge into the existing `mcpServers` object rather than replacing the file, then
**quit and reopen Claude Desktop** — the file is read once, at startup, so a
window reload is not enough.

> The `symfonycon-organizer` server can change the programme: it schedules
> talks, unschedules them, writes CFP proposals and imports files from your
> workspace. Over STDIO it is unauthenticated, because whoever can launch the
> process already has your shell. Leave it out of the configuration if you would
> rather the model could not reschedule anything.

## 4. Check it before you blame the host

The demo can drive its own server exactly the way Claude Desktop will, which is
much faster to debug than a desktop app that shows a red dot:

```console
$ php bin/console mcp:client:debug regression conference_stdio   # connect and list
$ php bin/console app:mcp:regression conference_stdio            # exercise everything
```

If those work and the host does not, the difference is environment: the PHP
binary on the host's `PATH`, a relative path in the configuration, or a database
that was never seeded for that `APP_ENV`.

## What to ask it

Once connected, the model can reach four kinds of thing.

**Tools** — it calls these itself:

> Which talks are about queues or messaging?
> Who is speaking on the AI track, and when?
> Is there anything on Friday morning that clashes with the Doctrine talk?
> Schedule "Testing What Matters" into Studio A on the 20th at 14:00.

**Prompts** — these appear in the host's UI as commands the *user* picks, not
as something the model invokes. In Claude Desktop they are behind the `+` in the
message box, under the server's name:

| Prompt | What it does |
|---|---|
| `draft_talk_abstract` | drafts an abstract, grounded in accepted ones from the same track |
| `introduce_speaker` | writes the 30-second stage introduction, from the real profile |
| `review_schedule_day` | reviews one day for clashes and pacing, with the agenda attached |
| `promote_talk` | writes a promotional post, with the platform's length limit applied |

**Resources** — attachable context, also behind the `+`:
`conference://current`, `schedule://full`, `speaker://all`,
`conference://badge.png`, plus everything under the `talk://`, `speaker://`,
`schedule://` and `track://` templates.

**An app** — `browse_schedule` returns a rendered, interactive screen rather
than text. Ask for the schedule and the host renders it in a sandboxed iframe;
the buttons in it call further tools. Hosts that do not support MCP Apps fall
back to the structured result, so nothing breaks.

## Elicitation, sampling and roots from a real host

Three tools on the `organizer` server ask the *client* for something:

- `submit_proposal` asks the user for the speaker details it is missing — in a
  host that supports elicitation, that is a form;
- `review_proposal` asks the host's model to draft the review — that is sampling,
  and the model you are talking to writes it;
- `import_proposals_from_roots` asks the host which folders it may read, then
  scans them for `*.proposal.md`.

All three check `supports*()` first and degrade into a plain message when the
host cannot do it, so they are safe to call from anything.

## If it does not connect

| Symptom | Likely cause |
|---|---|
| The server never appears | JSON syntax error in the configuration file, or the app was not fully restarted |
| It appears and immediately fails | `command` is not the right PHP binary, or a path is relative |
| "No conference in the database" | the `APP_ENV` in the configuration was never seeded — run `app:seed` for it |
| Tools work, then stop | the first call in `dev` can exceed the host's timeout while the container compiles; use `prod` |
| Garbled or truncated responses | something is writing to stdout besides the protocol — see [`deployment.md`](deployment.md) |

Claude Desktop keeps per-server logs; on Linux they are under
`~/.config/Claude/logs/`. The server's own logging goes to stderr, which the
host captures there — never to stdout, which belongs to the protocol.
