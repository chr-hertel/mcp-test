<?php

declare(strict_types=1);

namespace App\Mcp\Regression;

use App\Service\ProgrammeSeeder;
use App\Mcp\Client\ScriptedElicitationHandler;
use App\Mcp\Client\ScriptedSamplingHandler;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptReference;
use Mcp\Schema\ResourceReference;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\AI\McpBundle\Exception\ExceptionInterface as McpBundleException;

/**
 * Drives a real MCP client against a real MCP server and reports what worked.
 *
 * This is the point of the demo talking to itself. Every check here is one
 * round trip through the whole stack — the bundle's client, the SDK's client,
 * a transport (a spawned process or HTTP), the bundle's server wiring and the
 * SDK's protocol handler — so an upstream change that breaks any layer shows up
 * as a failing check rather than as a mystery in production.
 *
 * The runner adapts to the server it is pointed at: a check whose subject the
 * server does not expose is skipped, not failed. That way the same suite runs
 * against the read-only `conference` server, the privileged `organizer` one and
 * the `diagnostics` workbench.
 *
 * @see \App\Command\McpRegressionCommand for the console entry point
 * @see \App\Tests\Regression\StdioRegressionTest for the PHPUnit one
 */
final class RegressionRunner
{
    /**
     * @var list<CheckResult>
     */
    private array $results = [];

    /**
     * @var list<Tool>
     */
    private array $tools = [];

    /**
     * @var list<Prompt>
     */
    private array $prompts = [];

    private bool $hasResourceTemplates = false;

    public function __construct(
        private readonly ScriptedElicitationHandler $elicitation,
    ) {
    }

    /**
     * @return list<CheckResult>
     */
    public function run(ServerConnectionInterface $connection): array
    {
        $this->results = [];
        $this->tools = [];
        $this->prompts = [];
        $this->hasResourceTemplates = false;

        $this->checkLifecycle($connection);
        $this->checkTools($connection);
        $this->checkPrompts($connection);
        $this->checkResources($connection);
        $this->checkCompletion($connection);
        $this->checkErrors($connection);
        $this->checkProgress($connection);
        $this->checkClientCapabilities($connection);
        $this->checkApps($connection);

        return $this->results;
    }

    // -- lifecycle -----------------------------------------------------------

    private function checkLifecycle(ServerConnectionInterface $connection): void
    {
        $this->check('lifecycle', 'ping', function () use ($connection): string {
            $connection->ping();

            return 'answered';
        });

        $this->check('lifecycle', 'server info', function () use ($connection): string {
            $info = $connection->getServerInfo();

            if (null === $info) {
                throw new \RuntimeException('The server advertised no implementation info.');
            }
            if ('' === $info->name) {
                throw new \RuntimeException('The server advertised an empty name.');
            }

            return \sprintf('%s %s', $info->name, $info->version);
        });

        $this->check('lifecycle', 'protocol version', function () use ($connection): string {
            $version = $connection->getProtocolVersion();

            if (null === $version) {
                throw new \RuntimeException('No protocol version was negotiated.');
            }

            return $version->value;
        });

        $this->check('lifecycle', 'instructions', function () use ($connection): string {
            $instructions = $connection->getInstructions();

            if (null === $instructions || '' === trim($instructions)) {
                return 'none advertised';
            }

            return \sprintf('%d characters', \strlen($instructions));
        });

        $this->check('lifecycle', 'logging/setLevel', function () use ($connection): string {
            $connection->setLoggingLevel(LoggingLevel::Info);

            return 'accepted';
        });
    }

    // -- tools ---------------------------------------------------------------

    private function checkTools(ServerConnectionInterface $connection): void
    {
        $this->check('tools', 'tools/list', function () use ($connection): string {
            $this->tools = $connection->getTools();

            foreach ($this->tools as $tool) {
                if ('' === $tool->name) {
                    throw new \RuntimeException('A tool was advertised without a name.');
                }

                $schema = $tool->inputSchema;
                if (!isset($schema['type']) || 'object' !== $schema['type']) {
                    throw new \RuntimeException(\sprintf('Tool "%s" has an input schema that is not an object.', $tool->name));
                }
            }

            return \sprintf('%d tool(s), all with an object input schema', \count($this->tools));
        });

        $this->check('tools', 'pagination follows the cursor', function () use ($connection): string {
            $firstPage = $connection->listTools();
            $paged = \count($connection->getTools());

            if ($paged < \count($firstPage->tools)) {
                throw new \RuntimeException('Following the cursor returned fewer tools than the first page.');
            }

            return null === $firstPage->nextCursor
                ? \sprintf('%d tool(s) in a single page', $paged)
                : \sprintf('%d tool(s) across several pages', $paged);
        });

        if (!$this->hasTool('search_talks')) {
            $this->results[] = CheckResult::skip('tools', 'tools/call', 'this server does not expose search_talks');

            return;
        }

        $this->check('tools', 'tools/call structured result', function () use ($connection): string {
            $result = $connection->callTool('search_talks', ['query' => 'messenger']);

            if ($result->isError) {
                throw new \RuntimeException('search_talks reported an error: '.$this->text($result));
            }

            $data = $this->structured($result);

            if (!isset($data['talks']) || !\is_array($data['talks'])) {
                throw new \RuntimeException('The result carries no "talks" list.');
            }
            if (1 !== \count($data['talks'])) {
                throw new \RuntimeException(\sprintf('Expected exactly one hit for "messenger", got %d.', \count($data['talks'])));
            }
            if ('messenger-at-scale' !== ($data['talks'][0]['slug'] ?? null)) {
                throw new \RuntimeException('The hit was not the expected talk.');
            }

            return 'one hit, matching the fixture';
        });

        $this->check('tools', 'enum argument', function () use ($connection): string {
            $result = $connection->callTool('search_talks', ['track' => 'ai']);
            $data = $this->structured($result);

            $tracks = array_unique(array_column($data['talks'] ?? [], 'track'));

            if (['ai'] !== array_values($tracks)) {
                throw new \RuntimeException('Filtering by the "ai" track returned talks from other tracks.');
            }

            return \sprintf('%d talk(s), all in the ai track', \count($data['talks']));
        });

        $this->check('tools', 'schema constraint is enforced', function () use ($connection): string {
            // `limit` is declared with maximum: 50; the SDK validates arguments
            // against the generated schema before the handler ever runs.
            try {
                $result = $connection->callTool('search_talks', ['limit' => 5000]);
            } catch (McpBundleException) {
                return 'rejected as a protocol error';
            }

            if ($result->isError) {
                return 'rejected as a tool error';
            }

            throw new \RuntimeException('A limit of 5000 was accepted despite "maximum: 50".');
        });

        if ($this->hasTool('get_talk')) {
            $this->check('tools', 'Content[] result with a resource link', function () use ($connection): string {
                $result = $connection->callTool('get_talk', ['slug' => 'messenger-at-scale']);

                $kinds = array_map(static fn (object $block): string => $block::class, $result->content);

                if (!\in_array(ResourceLink::class, $kinds, true)) {
                    throw new \RuntimeException('No resource_link block came back.');
                }
                if (!\in_array(TextContent::class, $kinds, true)) {
                    throw new \RuntimeException('No text block came back.');
                }

                return \sprintf('%d block(s): %s', \count($kinds), implode(', ', array_map(
                    static fn (string $class): string => substr($class, strrpos($class, '\\') + 1),
                    $kinds,
                )));
            });
        }

        if ($this->hasTool('get_test_image')) {
            $this->check('tools', 'binary content survives the transport', function () use ($connection): string {
                $result = $connection->callTool('get_test_image');

                foreach ($result->content as $block) {
                    if ($block instanceof ImageContent) {
                        $decoded = base64_decode($block->data, true);

                        if (false === $decoded || !str_starts_with($decoded, "\x89PNG")) {
                            throw new \RuntimeException('The image content was not a decodable PNG.');
                        }

                        return \sprintf('%d bytes of image/png', \strlen($decoded));
                    }
                }

                throw new \RuntimeException('No image block came back.');
            });
        }
    }

    // -- prompts -------------------------------------------------------------

    private function checkPrompts(ServerConnectionInterface $connection): void
    {
        $this->check('prompts', 'prompts/list', function () use ($connection): string {
            $this->prompts = $connection->getPrompts();

            return \sprintf('%d prompt(s)', \count($this->prompts));
        });

        if ([] === $this->prompts) {
            $this->results[] = CheckResult::skip('prompts', 'prompts/get', 'this server exposes no prompts');

            return;
        }

        $this->check('prompts', 'prompts/get with arguments', function () use ($connection): string {
            $result = $connection->getPrompt('promote_talk', ['slug' => 'messenger-at-scale', 'platform' => 'bluesky']);

            if ([] === $result->messages) {
                throw new \RuntimeException('The prompt returned no messages.');
            }

            $first = $result->messages[0]->content;
            if (!$first instanceof TextContent || !str_contains((string) $first->text, 'Messenger at Scale')) {
                throw new \RuntimeException('The rendered prompt does not mention the talk it was given.');
            }

            return \sprintf('%d message(s), filled from the database', \count($result->messages));
        });

        $this->check('prompts', 'prompt carrying an embedded resource', function () use ($connection): string {
            $result = $connection->getPrompt('review_schedule_day', ['day' => ProgrammeSeeder::DAY_ONE]);

            foreach ($result->messages as $message) {
                if ($message->content instanceof EmbeddedResource) {
                    $contents = $message->content->resource;

                    if (!$contents instanceof TextResourceContents) {
                        throw new \RuntimeException('The embedded resource carried no text.');
                    }

                    return \sprintf('embedded %s (%d characters)', $contents->uri, \strlen($contents->text));
                }
            }

            throw new \RuntimeException('No embedded resource came back with the prompt.');
        });

        $this->check('prompts', 'unknown prompt is refused', function () use ($connection): string {
            try {
                $connection->getPrompt('no_such_prompt');
            } catch (McpBundleException $e) {
                return 'refused: '.$this->firstLine($e->getMessage());
            }

            throw new \RuntimeException('An unknown prompt name was accepted.');
        });
    }

    // -- resources -----------------------------------------------------------

    private function checkResources(ServerConnectionInterface $connection): void
    {
        $resources = [];

        $this->check('resources', 'resources/list', function () use ($connection, &$resources): string {
            $resources = $connection->getResources();

            return \sprintf('%d resource(s)', \count($resources));
        });

        if ([] === $resources) {
            $this->results[] = CheckResult::skip('resources', 'resources/read', 'this server exposes no resources');
        } else {
            $this->check('resources', 'resources/read (JSON)', function () use ($connection): string {
                $result = $connection->readResource('conference://current');
                $contents = $result->contents[0] ?? null;

                if (!$contents instanceof TextResourceContents) {
                    throw new \RuntimeException('conference://current did not come back as text.');
                }

                $data = json_decode($contents->text, true, flags: \JSON_THROW_ON_ERROR);

                if ('SymfonyCon' !== ($data['name'] ?? null)) {
                    throw new \RuntimeException('The conference resource does not match the fixture.');
                }

                return \sprintf('%s %s, %d day(s)', $data['name'], $data['edition'], \count($data['days']));
            });

            $this->check('resources', 'resources/read (binary)', function () use ($connection): string {
                $result = $connection->readResource('conference://badge.png');
                $contents = $result->contents[0] ?? null;

                if (!$contents instanceof BlobResourceContents) {
                    throw new \RuntimeException('conference://badge.png did not come back as a blob.');
                }

                $decoded = base64_decode($contents->blob, true);

                if (false === $decoded || !str_starts_with($decoded, "\x89PNG")) {
                    throw new \RuntimeException('The blob was not a decodable PNG.');
                }

                return \sprintf('%d bytes of image/png', \strlen($decoded));
            });

            $this->check('resources', 'unknown URI is refused', function () use ($connection): string {
                try {
                    $connection->readResource('conference://nope');
                } catch (McpBundleException $e) {
                    return 'refused: '.$this->firstLine($e->getMessage());
                }

                throw new \RuntimeException('An unknown resource URI was accepted.');
            });
        }

        $templates = [];

        $this->check('resources', 'resources/templates/list', function () use ($connection, &$templates): string {
            $templates = $connection->getResourceTemplates();
            $this->hasResourceTemplates = [] !== $templates;

            return \sprintf('%d template(s)', \count($templates));
        });

        if ([] === $templates) {
            $this->results[] = CheckResult::skip('resources', 'read through a template', 'this server exposes no resource templates');

            return;
        }

        $this->check('resources', 'read through a template', function () use ($connection): string {
            $result = $connection->readResource('talk://messenger-at-scale');
            $contents = $result->contents[0] ?? null;

            if (!$contents instanceof TextResourceContents) {
                throw new \RuntimeException('talk://messenger-at-scale did not come back as text.');
            }

            $data = json_decode($contents->text, true, flags: \JSON_THROW_ON_ERROR);

            if ('Messenger at Scale' !== ($data['title'] ?? null)) {
                throw new \RuntimeException('The template resolved to the wrong talk.');
            }

            return 'talk://{slug} resolved to the right talk';
        });

        $this->check('resources', 'template rejects an unknown variable value', function () use ($connection): string {
            try {
                $connection->readResource('talk://no-such-talk');
            } catch (McpBundleException $e) {
                return 'refused: '.$this->firstLine($e->getMessage());
            }

            throw new \RuntimeException('An unknown slug was accepted by the template.');
        });
    }

    // -- completion ----------------------------------------------------------

    private function checkCompletion(ServerConnectionInterface $connection): void
    {
        if ([] === $this->prompts) {
            $this->results[] = CheckResult::skip('completion', 'completion/complete (prompt)', 'this server exposes no prompts');
        } else {
            $this->check('completion', 'completion/complete (prompt argument)', function () use ($connection): string {
                $result = $connection->complete(new PromptReference('promote_talk'), ['name' => 'slug', 'value' => 'messenger']);

                if (!\in_array('messenger-at-scale', $result->values, true)) {
                    throw new \RuntimeException('The completion did not offer the matching slug.');
                }

                return \sprintf('%d suggestion(s) for "messenger"', \count($result->values));
            });

            $this->check('completion', 'completion/complete (static values)', function () use ($connection): string {
                $result = $connection->complete(new PromptReference('promote_talk'), ['name' => 'platform', 'value' => 'b']);

                if (!\in_array('bluesky', $result->values, true)) {
                    throw new \RuntimeException('The static value list did not offer "bluesky".');
                }

                return implode(', ', $result->values);
            });
        }

        if (!$this->hasResourceTemplates) {
            $this->results[] = CheckResult::skip('completion', 'completion/complete (template variable)', 'this server exposes no resource templates');

            return;
        }

        $this->check('completion', 'completion/complete (template variable)', function () use ($connection): string {
            $result = $connection->complete(new ResourceReference('talk://{slug}'), ['name' => 'slug', 'value' => 'doctrine']);

            if (!\in_array('doctrine-without-tears', $result->values, true)) {
                throw new \RuntimeException('The template completion did not offer the matching slug.');
            }

            return \sprintf('%d suggestion(s) for "doctrine"', \count($result->values));
        });
    }

    // -- error handling ------------------------------------------------------

    private function checkErrors(ServerConnectionInterface $connection): void
    {
        $this->check('errors', 'unknown tool is refused', function () use ($connection): string {
            try {
                $connection->callTool('no_such_tool');
            } catch (McpBundleException $e) {
                return 'refused: '.$this->firstLine($e->getMessage());
            }

            throw new \RuntimeException('An unknown tool name was accepted.');
        });

        if ($this->hasTool('get_talk')) {
            $this->check('errors', 'tool error is a result, not a protocol error', function () use ($connection): string {
                $result = $connection->callTool('get_talk', ['slug' => 'no-such-talk']);

                if (!$result->isError) {
                    throw new \RuntimeException('A failing tool call did not set isError.');
                }

                return 'isError: true — '.$this->firstLine($this->text($result));
            });
        }

        if ($this->hasTool('fail_on_purpose')) {
            $this->check('errors', 'ToolCallException becomes an isError result', function () use ($connection): string {
                $result = $connection->callTool('fail_on_purpose', ['mode' => 'tool_error']);

                if (!$result->isError) {
                    throw new \RuntimeException('A ToolCallException did not produce isError.');
                }

                return 'isError: true — '.$this->firstLine($this->text($result));
            });

            $this->check('errors', 'other throwables stay a protocol error', function () use ($connection): string {
                // The SDK converts an unhandled throwable into -32603 with a fixed
                // message, so nothing internal leaks to the client. Pinned here
                // because it is a deliberate departure from the spec's advice to
                // report execution failures in the result — see docs/patches.md.
                try {
                    $result = $connection->callTool('fail_on_purpose', ['mode' => 'exception']);
                } catch (McpBundleException $e) {
                    if (str_contains($e->getMessage(), 'expected')) {
                        throw new \RuntimeException('The exception message leaked to the client.');
                    }

                    return 'refused: '.$this->firstLine($e->getMessage());
                }

                if ($result->isError) {
                    return 'reported as a tool error instead (upstream behaviour changed)';
                }

                throw new \RuntimeException('A tool that always throws returned a successful result.');
            });
        }

        if ($this->hasTool('search_talks')) {
            $this->check('errors', 'missing required argument is refused', function () use ($connection): string {
                // get_talk requires "slug"; calling it bare must not reach the handler.
                if (!$this->hasTool('get_talk')) {
                    return 'skipped: get_talk is not exposed';
                }

                try {
                    $result = $connection->callTool('get_talk');
                } catch (McpBundleException) {
                    return 'refused as a protocol error';
                }

                if ($result->isError) {
                    return 'refused as a tool error';
                }

                throw new \RuntimeException('A call missing a required argument reached the handler.');
            });
        }
    }

    // -- notifications -------------------------------------------------------

    private function checkProgress(ServerConnectionInterface $connection): void
    {
        if (!$this->hasTool('emit_progress')) {
            $this->results[] = CheckResult::skip('notifications', 'progress notifications', 'this server does not expose emit_progress');

            return;
        }

        $this->check('notifications', 'progress notifications reach the client', function () use ($connection): string {
            $seen = [];

            $result = $connection->callTool(
                'emit_progress',
                ['steps' => 4],
                static function (float $progress, ?float $total, ?string $message) use (&$seen): void {
                    $seen[] = ['progress' => $progress, 'total' => $total, 'message' => $message];
                },
            );

            if ($result->isError) {
                throw new \RuntimeException('emit_progress reported an error: '.$this->text($result));
            }

            if ([] === $seen) {
                throw new \RuntimeException('No progress notification reached the client.');
            }

            $last = $seen[array_key_last($seen)];
            if (1.0 !== $last['progress']) {
                throw new \RuntimeException('The last progress notification did not reach 1.0.');
            }

            return \sprintf('%d notification(s), ending at 1.0', \count($seen));
        });
    }

    // -- client capabilities -------------------------------------------------

    private function checkClientCapabilities(ServerConnectionInterface $connection): void
    {
        if ($this->hasTool('probe_client')) {
            $this->check('client capabilities', 'the server sees what the client advertises', function () use ($connection): string {
                $data = $this->structured($connection->callTool('probe_client'));

                $advertised = array_keys(array_filter(
                    array_intersect_key($data, array_flip(['roots', 'sampling', 'elicitation', 'tasks'])),
                    static fn (mixed $value): bool => true === $value,
                ));

                return [] === $advertised
                    ? 'none (a client with no handlers)'
                    : implode(', ', $advertised);
            });
        }

        if ($this->hasTool('list_client_roots')) {
            $this->check('client capabilities', 'roots/list round trip', function () use ($connection): string {
                $data = $this->structured($connection->callTool('list_client_roots'));

                if ('ok' !== ($data['status'] ?? null)) {
                    return 'skipped by the server: '.($data['message'] ?? 'no reason given');
                }

                if ([] === ($data['roots'] ?? [])) {
                    throw new \RuntimeException('The client answered roots/list with an empty list.');
                }

                return \sprintf('%d root(s): %s', \count($data['roots']), implode(', ', array_column($data['roots'], 'name')));
            });
        }

        if ($this->hasTool('submit_proposal')) {
            $this->check('client capabilities', 'elicitation round trip', function () use ($connection): string {
                $this->elicitation->reset();
                $this->elicitation->script([
                    'name' => 'Regression Speaker',
                    'email' => 'regression@example.com',
                    'consent' => 'accepted',
                ]);

                $data = $this->structured($connection->callTool('submit_proposal', [
                    'title' => 'A Proposal From The Regression Suite '.bin2hex(random_bytes(4)),
                    'abstract' => 'Submitted by the regression runner to prove that the elicitation round trip works end to end, over a real transport.',
                ]));

                if ('needs_input' === ($data['status'] ?? null)) {
                    return 'skipped by the server: the client advertises no elicitation';
                }

                if ('submitted' !== ($data['status'] ?? null)) {
                    throw new \RuntimeException('The proposal was not submitted: '.($data['message'] ?? 'no reason given'));
                }

                if ('Regression Speaker' !== ($data['proposal']['speaker_name'] ?? null)) {
                    throw new \RuntimeException('The scripted elicitation answer did not reach the server.');
                }

                return 'the scripted answer reached the handler';
            });

            $this->check('client capabilities', 'a declined elicitation is handled', function () use ($connection): string {
                $this->elicitation->reset();
                $this->elicitation->scriptDecline();

                $data = $this->structured($connection->callTool('submit_proposal', [
                    'title' => 'A Proposal That Will Be Declined',
                    'abstract' => 'The regression runner declines the elicitation, and the server has to survive that without writing anything.',
                ]));

                $this->elicitation->reset();

                if ('needs_input' === ($data['status'] ?? null)) {
                    return 'skipped by the server: the client advertises no elicitation';
                }

                if ('declined' !== ($data['status'] ?? null)) {
                    throw new \RuntimeException('A declined elicitation did not produce a "declined" status.');
                }

                return 'nothing was written';
            });
        }

        if ($this->hasTool('review_proposal') && $this->hasTool('list_proposals')) {
            $this->check('client capabilities', 'sampling round trip', function () use ($connection): string {
                $proposals = $this->structured($connection->callTool('list_proposals'))['proposals'] ?? [];

                if ([] === $proposals) {
                    return 'skipped: no proposal to review';
                }

                $result = $connection->callTool('review_proposal', ['proposalId' => $proposals[0]['id']]);

                if ($result->isError) {
                    throw new \RuntimeException('review_proposal reported an error: '.$this->text($result));
                }

                $text = $this->text($result);

                if (!str_contains($text, ScriptedSamplingHandler::MODEL)) {
                    return 'skipped by the server: the client advertises no sampling';
                }

                return 'the scripted model answered and the review was stored';
            });
        }
    }

    // -- MCP Apps ------------------------------------------------------------

    private function checkApps(ServerConnectionInterface $connection): void
    {
        if (!$this->hasTool('browse_schedule')) {
            $this->results[] = CheckResult::skip('apps', 'MCP App', 'this server exposes no apps');

            return;
        }

        $this->check('apps', 'the UI resource is served as an app', function () use ($connection): string {
            $result = $connection->readResource('ui://schedule');
            $contents = $result->contents[0] ?? null;

            if (!$contents instanceof TextResourceContents) {
                throw new \RuntimeException('ui://schedule did not come back as text.');
            }

            if (!str_contains((string) $contents->mimeType, 'mcp-app')) {
                throw new \RuntimeException(\sprintf('ui://schedule was served as "%s", not as an MCP App.', $contents->mimeType ?? 'no MIME type'));
            }

            if (!str_contains($contents->text, 'ui/notifications/initialized')) {
                throw new \RuntimeException('The app shell does not implement the MCP Apps handshake.');
            }

            return \sprintf('%s, %d characters', $contents->mimeType, \strlen($contents->text));
        });

        $this->check('apps', 'the app tool renders HTML on the server', function () use ($connection): string {
            $data = $this->structured($connection->callTool('browse_schedule', ['day' => ProgrammeSeeder::DAY_ONE]));

            if (!isset($data['html']) || !\is_string($data['html'])) {
                throw new \RuntimeException('The tool result carries no "html" field.');
            }

            if (!str_contains($data['html'], 'data-call="open_talk"')) {
                throw new \RuntimeException('The rendered HTML has no declarative tool trigger.');
            }

            return \sprintf('%d characters of server-rendered HTML', \strlen($data['html']));
        });

        $this->check('apps', 'the tool is linked to its app', function (): string {
            $ui = $this->uiMeta('browse_schedule');

            if ('ui://schedule' !== ($ui['resourceUri'] ?? null)) {
                throw new \RuntimeException('browse_schedule carries no ui link to ui://schedule.');
            }

            return \sprintf('browse_schedule → %s, visible to %s', $ui['resourceUri'], implode('+', $ui['visibility'] ?? ['(unset)']));
        });

        $this->check('apps', 'an app-only tool is marked as such', function (): string {
            // Note: `visibility` is a hint the *host* acts on. The server still
            // advertises the tool in tools/list — see docs/patches.md, where the
            // bundle documentation claiming otherwise is recorded.
            $ui = $this->uiMeta('back_to_schedule');

            if (['app'] !== ($ui['visibility'] ?? null)) {
                throw new \RuntimeException(\sprintf(
                    'back_to_schedule is declared appOnly but its visibility is %s.',
                    json_encode($ui['visibility'] ?? null),
                ));
            }

            return 'back_to_schedule is visible to the app only';
        });
    }

    // -- plumbing ------------------------------------------------------------

    /**
     * @param callable(): string $check
     */
    private function check(string $group, string $name, callable $check): void
    {
        try {
            $detail = $check();
            $this->results[] = str_starts_with($detail, 'skipped')
                ? CheckResult::skip($group, $name, substr($detail, \strlen('skipped: ')))
                : CheckResult::pass($group, $name, $detail);
        } catch (\Throwable $e) {
            $this->results[] = CheckResult::fail($group, $name, \sprintf('%s: %s', $e::class, $this->firstLine($e->getMessage())));
        }
    }

    /**
     * The MCP Apps `_meta.ui` block of one advertised tool.
     *
     * @return array{resourceUri?: string, visibility?: list<string>}
     */
    private function uiMeta(string $name): array
    {
        foreach ($this->tools as $tool) {
            if ($tool->name !== $name) {
                continue;
            }

            $ui = ($tool->meta ?? [])['ui'] ?? null;

            if (!\is_array($ui)) {
                throw new \RuntimeException(\sprintf('Tool "%s" carries no "_meta.ui" block.', $name));
            }

            return $ui;
        }

        throw new \RuntimeException(\sprintf('Tool "%s" was not advertised in tools/list.', $name));
    }

    private function hasTool(string $name): bool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function structured(CallToolResult $result): array
    {
        if (null !== $result->structuredContent) {
            return $result->structuredContent;
        }

        $text = $this->text($result);
        $decoded = json_decode($text, true);

        if (!\is_array($decoded)) {
            throw new \RuntimeException('The tool result carried neither structured content nor JSON text.');
        }

        return $decoded;
    }

    private function text(CallToolResult $result): string
    {
        foreach ($result->content as $block) {
            if ($block instanceof TextContent) {
                return (string) $block->text;
            }
        }

        return '';
    }

    private function firstLine(string $message): string
    {
        $line = strtok(trim($message), "\n");

        return \is_string($line) ? $line : $message;
    }
}
