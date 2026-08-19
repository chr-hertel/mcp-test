<?php

declare(strict_types=1);

namespace App\Mcp\Tool\Modern;

use App\Repository\ProposalRepository;
use App\Repository\TalkRepository;
use App\Service\ProgrammePresenter;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;

/**
 * What protocol revision 2026-07-28 changed, from a handler's point of view.
 *
 * The revision removed the `initialize` handshake, sessions, and — the part that
 * reshapes handler code — **server-initiated requests**. A server that needs
 * something from the client no longer asks and waits. It *returns* the ask, the
 * client answers by retrying the same call, and nothing is kept in between:
 * whatever the server needs to remember it seals into `requestState`, which
 * comes back verified.
 *
 * That is the difference between {@see \App\Mcp\Tool\Organizer\ProposalTool}, which
 * calls `$gateway->elicit()` and blocks, and `submit_proposal` here, which returns
 * an {@see InputRequiredResult} and is called twice. The handshake-era methods
 * throw under this revision rather than silently doing nothing.
 *
 * These tools are exposed only by the `modern` server — see config/packages/mcp.yaml.
 */
final class ModernLifecycleTool
{
    public function __construct(
        private readonly TalkRepository $talks,
        private readonly ProposalRepository $proposals,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgrammePresenter $presenter,
    ) {
    }

    /**
     * Report what this request declared about itself.
     *
     * In the handshake era all of this came from `initialize` and was kept on the
     * session. Here it travels in every request's `_meta`, which is exactly what
     * makes the server stateless: any worker can answer any request.
     *
     * @return array{protocol_version: string, era: string, client: array<string, mixed>, capabilities: array<string, bool>, trace: array<string, string>}
     */
    #[McpTool(
        name: 'describe_request',
        title: 'Describe this request',
        description: 'Report the protocol revision, client capabilities and trace context this request declared.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function describeRequest(RequestContext $context): array
    {
        $version = $context->getProtocolVersion();
        $capabilities = $context->getClientCapabilities();
        $gateway = $context->getClientGateway();

        return [
            'protocol_version' => $version->value,
            'era' => $version->isModern() ? 'modern' : 'handshake',
            'client' => [
                // Null in the handshake era, where it came from initialize instead.
                'declared_capabilities' => null !== $capabilities,
            ],
            'capabilities' => [
                'elicitation' => $gateway->supportsElicitation(),
                'elicitation_url' => $gateway->supportsElicitationUrl(),
                'sampling' => $gateway->supportsSampling(),
                'roots' => $gateway->supportsRoots(),
            ],
            'trace' => $context->getTraceContext(),
        ];
    }

    /**
     * Submit a CFP proposal, asking for the speaker details over two round trips.
     *
     * The modern replacement for elicitation: instead of blocking on a question,
     * return it. The client answers by calling this tool again with the answers
     * attached, and `requestState` carries the title and abstract across — signed,
     * because it travels through the client and comes back attacker-controlled.
     *
     * @param string $title    the proposal title
     * @param string $abstract the proposal abstract
     */
    #[McpTool(
        name: 'submit_proposal',
        title: 'Submit a CFP proposal',
        description: 'Submit a talk proposal. Asks for the speaker details it is missing, over a second round trip.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function submitProposal(
        RequestContext $context,
        #[Schema(minLength: 8, maxLength: 200)]
        string $title = '',
        #[Schema(minLength: 40, maxLength: 2000)]
        string $abstract = '',
    ): CallToolResult|InputRequiredResult {
        $input = $context->getInputContext();
        $answer = $input?->elicitResult('speaker');

        if (null === $answer) {
            // Round one. Nothing is stored server-side; the state travels with the
            // ask and comes back verified, or does not come back at all.
            return new InputRequiredResult(
                ['speaker' => new ElicitRequest(
                    \sprintf('Who should we credit for "%s"?', $title),
                    new ElicitationSchema(
                        properties: [
                            'name' => new StringSchemaDefinition('Speaker name', minLength: 2, maxLength: 120),
                            'email' => new StringSchemaDefinition('Speaker email', format: 'email'),
                            'consent' => new EnumSchemaDefinition(
                                'Code of conduct',
                                ['accepted', 'declined'],
                                enumNames: ['I accept', 'I decline'],
                            ),
                        ],
                        required: ['name', 'email', 'consent'],
                    ),
                )],
                requestState: $context->mintRequestState(['title' => $title, 'abstract' => $abstract]),
            );
        }

        // The same envelope the handshake-era tool returns, so that one check can
        // assert on both: the feature is what a caller sees, and it did not
        // change with the revision — only the direction the question travels.
        if ($answer->isDeclined() || $answer->isCancelled()) {
            $status = $answer->isDeclined() ? 'declined' : 'cancelled';

            return new CallToolResult(
                [new TextContent('Nothing was submitted: the speaker details were not provided.')],
                structuredContent: [
                    'status' => $status,
                    'message' => \sprintf('The user %s the request for speaker details; nothing was submitted.', $status),
                ],
            );
        }

        $content = $answer->content ?? [];
        if ('accepted' !== ($content['consent'] ?? null)) {
            // ::error() takes no structured content, so the constructor it is.
            return new CallToolResult(
                [new TextContent('The code of conduct was not accepted, so the proposal was not recorded.')],
                isError: true,
                structuredContent: [
                    'status' => 'rejected',
                    'message' => 'The code of conduct was not accepted; nothing was submitted.',
                ],
            );
        }

        // Round two. The title and abstract come from the sealed state, not from
        // the arguments — a retry need not repeat them, and could not be trusted to.
        $state = $context->getInputContext()?->requestState() ?? [];

        $proposal = new \App\Entity\Proposal(
            title: $state['title'] ?? $title,
            abstract: $state['abstract'] ?? $abstract,
            speakerName: (string) ($content['name'] ?? ''),
            speakerEmail: (string) ($content['email'] ?? ''),
            track: \App\Enum\Track::Backend,
            level: \App\Enum\Level::Intermediate,
        );

        $this->entityManager->persist($proposal);
        $this->entityManager->flush();

        return new CallToolResult(
            [new TextContent(\sprintf('Proposal #%d recorded over two round trips.', $proposal->getId()))],
            structuredContent: [
                'status' => 'submitted',
                'message' => \sprintf('Proposal #%d recorded over two round trips.', $proposal->getId()),
                'proposal' => $this->presenter->proposal($proposal),
            ],
        );
    }


    /**
     * Emit progress and log lines, both of which the client has to opt into.
     *
     * A handshake-era client turns logging on with `logging/setLevel`. Here that
     * method is gone: the level rides in each request's `_meta`, and a server
     * whose request did not carry one must stay silent.
     *
     * @param int $steps how many notifications to emit
     *
     * @return array{steps: int, message: string}
     */
    #[McpTool(
        name: 'reindex_programme',
        title: 'Reindex the programme',
        description: 'Walk the programme, reporting progress and logging as it goes.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function reindexProgramme(
        RequestContext $context,
        #[Schema(minimum: 1, maximum: 20)]
        int $steps = 3,
    ): array {
        $client = $context->getClientGateway();

        for ($step = 1; $step <= $steps; ++$step) {
            $client->log(LoggingLevel::Info, \sprintf('Reindexing shard %d of %d', $step, $steps));
            $client->progress($step, $steps, \sprintf('Shard %d of %d', $step, $steps));
        }

        return ['steps' => $steps, 'message' => \sprintf('Reindexed %d shard(s).', $steps)];
    }

    /**
     * Find talks in a track, with the track mirrored into a request header.
     *
     * `x-mcp-header` asks the client to repeat an argument as `Mcp-Param-Track`,
     * so a proxy can route on it without parsing the body. The server checks the
     * two agree and answers `-32020` when they do not.
     *
     * @return array{track: string, count: int, talks: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'search_track',
        title: 'Search one track',
        description: 'List the talks in one track. The track is mirrored into the Mcp-Param-Track header.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function searchTrack(
        #[Schema(definition: ['type' => 'string', 'description' => 'A conference track.', 'x-mcp-header' => 'Track'])]
        string $track = 'backend',
    ): array {
        $enum = \App\Enum\Track::tryFrom($track);
        $talks = null === $enum ? [] : $this->talks->search(track: $enum, limit: 50);

        return [
            'track' => $track,
            'count' => \count($talks),
            'talks' => array_map($this->presenter->talk(...), $talks),
        ];
    }

}
