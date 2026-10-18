# The chat host

`/chat` is the other half of the demo. Everywhere else this application is an MCP
server that something else drives; here it is the thing doing the driving — a
host, in the protocol's own word: a chat, a model, and a client connected to MCP
servers on the user's behalf.

```console
$ make chat           # make serve, plus where to point the browser
```

It is built out of three Symfony libraries and no glue of its own:

| | |
|---|---|
| `symfony/mcp-bundle` | the client: two connections, configured in `mcp.yaml` |
| `symfony/ai-agent` + `symfony/ai-mcp-tool` | the agent, and the toolbox that is a remote MCP server |
| `symfony/ai-bundle` | the wiring of both, in `ai.yaml` |
| `symfony/ai-chat` | the conversation, stored in the HTTP session |
| Symfony UX (Live Components, Stimulus, Asset Mapper) | the page, without a JavaScript build step |

## What a host is, beyond an agent with tools

An agent only ever draws **tools** from an MCP server — it lists them, and calls
them when the model asks. That is one of the protocol's three ways into a
conversation, and the page shows all three, because the difference between them
is *who decided*:

| | who decides | where it enters | in the UI |
|---|---|---|---|
| **Tools** | the model | `ChatHost::ask()` | the trail under each answer |
| **Prompts** | the user | `ChatHost::runPrompt()` | the list on the right, and a form |
| **Resources** | the user | `ChatHost::attach()` | a button that pastes it into the conversation |

A prompt is not a canned string: `prompts/get` is a *server-side render*. Picking
"Review a conference day" sends the day to the server, which reads the current
schedule out of the database and hands back the messages to start from — the
agenda in the conversation is the agenda as it is now, and the model never sees
the form. The arguments come with `completion/complete` behind them, which is why
the form can offer the actual conference days instead of asking you to know them.

The whole surface is read from the servers themselves, at request time, by
[`HostSurface`](../src/Chat/Mcp/HostSurface.php) — nothing on that page is a
hard-coded list. It is cached for a minute, because the connections are STDIO and
asking four questions of two servers means booting this application twice.

## The connections

`mcp.clients.host` in [`config/packages/mcp.yaml`](../config/packages/mcp.yaml)
connects to `conference` and `organizer` over **STDIO** — the chat spawns
`bin/console mcp:server <name>` per turn, exactly as Claude Desktop does. No web
server is involved in the MCP traffic, which is also why the whole path is
testable in `make check`.

Both servers are attached on purpose, even though `organizer` is a superset of
`conference`. `symfony/ai-mcp-tool` prefixes every tool with the connection it
came from, so the model sees `conference_search_talks` and
`organizer_search_talks` as two tools and the system prompt says which to prefer.
That overlap is what a host with two connections actually looks like.

Two client capabilities are deliberately different from the regression client:

* **sampling** is answered by the host's own model
  ([`ModelSamplingHandler`](../src/Chat/Mcp/ModelSamplingHandler.php)). This is
  the round trip a host exists for: `review_proposal` needs a review written, the
  server has no model, so it asks the client — whose model belongs to the user.
* **elicitation** is *not* configured. A server question mid-tool-call has nowhere
  to go inside one HTTP round trip, so `submit_proposal` degrades to the message
  it degrades to for any client that cannot be asked. Watching a tool degrade
  instead of fail is worth more here than pretending the capability exists.

## The model, and the model that is not one

`MCP_DEMO_CHAT_PLATFORM` decides who answers, and
[`HostPlatform`](../src/Chat/Platform/HostPlatform.php) is the one platform the
agent is configured against — the two real ones stay uninstantiated unless they
are picked.

```dotenv
MCP_DEMO_CHAT_PLATFORM=scripted        # scripted | anthropic | openai
MCP_DEMO_CHAT_MODEL=scripted-host-1    # a model of that platform
ANTHROPIC_API_KEY=
OPENAI_API_KEY=
```

`scripted` is the default, and it is not a mock:
[`ScriptedPlatform`](../src/Chat/Platform/ScriptedPlatform.php) plays a real
tool-calling model over the agent's real loop, in two rounds.

1. Asked a question, it scores every tool the servers advertised against the
   sentence — the tool's own name and description, then whether its *schema* has
   somewhere to put what the sentence is about — and calls the best fit
   ([`ToolChoice`](../src/Chat/Platform/ToolChoice.php)). A tool whose required
   argument is not in the sentence is passed over rather than called with an
   invented value.
2. Handed the result, it reports what came back, verbatim, and asks for nothing
   further — which is what ends the loop.

So it invents nothing, and it understands nothing. What it buys is that the whole
chain — agent, toolbox, MCP client, STDIO transport, MCP server, this
application's tools — is exercised on every run of `make check`, with no account
anywhere, which is the same reason
[`ScriptedSamplingHandler`](../src/Mcp/Client/ScriptedSamplingHandler.php) exists
on the other side of the protocol.

For a model that does understand, set the two variables together:

```dotenv
MCP_DEMO_CHAT_PLATFORM=anthropic
MCP_DEMO_CHAT_MODEL=claude-sonnet-4-5
ANTHROPIC_API_KEY=sk-ant-…
```

Changing one and forgetting the other is refused with a message that says so,
rather than surfacing as an unknown-model error from inside a bridge.

## The page

The conversation is a Live Component
([`App\Twig\Components\Chat`](../src/Twig/Components/Chat.php)): a turn is one
blocking round trip that spawns child processes, and Live Components make that
bearable without a line of custom JavaScript — the composer disables itself while
the turn runs, and the transcript re-renders from the session. The one Stimulus
controller is for scrolling and the starter buttons.

The prompt form is a plain controller action instead
([`ChatController`](../src/Controller/ChatController.php)), because its fields
differ per prompt and want the server's completions behind them; a Live Component
would have bought nothing there.

The transcript is the agent's own message bag, kept in the session by
`symfony/ai-chat`'s session store. It holds the tool call messages as well as the
prose, which is why the trail under each answer can show the arguments sent and
the bytes that came back — and why the model sees all of it as history on the
next turn.

## What it costs, and what breaks

* **A turn spawns processes.** Two connections, one PHP process each, booted per
  turn and closed at the end of it (`ChatHost::run()`). Fine for a demo; a real
  host would hold the connections open per session.
* **The prod environment needs its assets compiled** — `make assets`, which
  `make setup` includes. Dev serves them straight out of `assets/`.
* **Writes are real, and `/chat` is not behind a firewall.** `organizer` is
  attached over STDIO, where the process boundary is the boundary — so a turn can
  schedule a talk without the bearer token the HTTP endpoint demands. That is
  correct for a demo you run on your own machine and wrong for anything public:
  put the page behind a firewall, or drop the `organizer` connection, before it
  leaves localhost. The fixture database is what the regression suite asserts on,
  so `make db` puts it back.

## Where it is tested

| | |
|---|---|
| [`ChatTurnTest`](../tests/Functional/ChatTurnTest.php) | a full turn, down to the spawned server: the tool chosen, the arguments sent, the answer |
| [`ChatComponentTest`](../tests/Functional/ChatComponentTest.php) | the Live Component's actions, as HTTP requests |
| [`ChatPageTest`](../tests/Functional/ChatPageTest.php) | the page, the prompt form, and the completions behind it |
| [`ToolChoiceTest`](../tests/Unit/Chat/ToolChoiceTest.php) | the choosing rules, against schemas an MCP server could publish |
| [`ScriptedPlatformTest`](../tests/Unit/Chat/ScriptedPlatformTest.php) | the two rounds, and that the loop terminates |
| [`HostPlatformTest`](../tests/Unit/Chat/HostPlatformTest.php) | which platform answers, and the two variables that must agree |

There is no check in `RegressionRunner` for any of this on purpose: that suite
asserts what a *server* answers, and the chat adds no server surface. What it
adds is a second client, and the tests above are where it is held to that.
