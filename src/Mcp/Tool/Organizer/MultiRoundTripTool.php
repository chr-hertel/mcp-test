<?php

declare(strict_types=1);

namespace App\Mcp\Tool\Organizer;

use App\Entity\Proposal;
use App\Enum\Level;
use App\Enum\Track;
use App\Repository\ConferenceRepository;
use App\Repository\ProposalRepository;
use App\Service\ProgrammePresenter;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;

/**
 * The three client asks again — roots, elicitation and sampling — written the way
 * that serves every protocol revision: the handler *returns* what it needs.
 *
 * {@see WorkspaceTool} and {@see ProposalTool} reach for the ClientGateway, which
 * blocks until the client answers. That needs a channel back to the client while
 * the request is open, which revision 2026-07-28 does not have. Here the first
 * call returns an {@see InputRequiredResult} naming the ask, and the handler is
 * entered again with the answer under the key it chose:
 *
 *  - on 2026-07-28 the client does that itself: it answers the ask from its own
 *    handlers and re-sends the call with `inputResponses`;
 *  - on the handshake era the SDK's InputRequiredShim sends each ask as the real
 *    server-to-client request it describes and re-enters the handler with the
 *    answer, inside the same call.
 *
 * The handler cannot tell which happened, which is the point. It also runs once
 * per round, so it re-derives where it is from what came back rather than from
 * anything it kept — nothing is kept.
 *
 * The result envelopes are the gateway tools' own, so one regression check
 * asserts on both styles.
 */
final class MultiRoundTripTool
{
    public function __construct(
        private readonly ProposalRepository $proposals,
        private readonly ConferenceRepository $conferences,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgrammePresenter $presenter,
    ) {
    }

    /**
     * Ask the client which workspace folders it exposes, over a returned ask.
     */
    #[McpTool(
        name: 'list_client_roots_mrtr',
        title: 'List the client workspace roots (multi round-trip)',
        description: 'Ask the connected MCP client which filesystem roots it exposes, by returning the question instead of sending it. Works on every protocol revision.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: true),
    )]
    public function listRoots(RequestContext $context): CallToolResult|InputRequiredResult
    {
        if (!$context->getClientGateway()->supportsRoots()) {
            return $this->result([
                'status' => 'unsupported',
                'message' => 'This client does not advertise the "roots" capability, so the server cannot discover its workspace folders. Pass paths as tool arguments instead.',
                'roots' => [],
            ]);
        }

        $answer = $context->getInputContext()?->rootsResult('roots');

        if (null === $answer) {
            return new InputRequiredResult(['roots' => new ListRootsRequest()]);
        }

        $roots = [];
        foreach ($answer->roots as $root) {
            $roots[] = ['uri' => $root->uri, 'name' => $root->name];
        }

        return $this->result([
            'status' => 'ok',
            'message' => \sprintf('The client exposes %d root(s).', \count($roots)),
            'roots' => $roots,
        ]);
    }

    /**
     * Submit a CFP proposal, asking for the speaker details over a returned ask.
     *
     * The arguments need no `requestState`: the client re-sends the whole call,
     * arguments included, so they are there on every round.
     *
     * @param string $title    the proposal title
     * @param string $abstract the proposal abstract
     */
    #[McpTool(
        name: 'submit_proposal_mrtr',
        title: 'Submit a CFP proposal (multi round-trip)',
        description: 'Submit a talk proposal to the Call for Papers. The speaker details are asked for by returning the question instead of sending it. Works on every protocol revision.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function submitProposal(
        RequestContext $context,
        #[Schema(minLength: 8, maxLength: 200)]
        string $title,
        #[Schema(minLength: 40, maxLength: 2000)]
        string $abstract,
    ): CallToolResult|InputRequiredResult {
        if (!$this->conferences->current()->isCfpOpen()) {
            throw new ToolCallException('The Call for Papers is closed for this edition.');
        }

        if (!$context->getClientGateway()->supportsElicitation()) {
            return $this->result([
                'status' => 'needs_input',
                'message' => 'This client cannot prompt the user, and this tool takes the speaker details from the user only.',
            ]);
        }

        $answer = $context->getInputContext()?->elicitResult('speaker');

        if (null === $answer) {
            return new InputRequiredResult([
                'speaker' => new ElicitRequest(ProposalAsks::speakerMessage($title), ProposalAsks::speakerSchema()),
            ]);
        }

        if ($answer->isDeclined() || $answer->isCancelled()) {
            $status = $answer->isDeclined() ? 'declined' : 'cancelled';

            return $this->result([
                'status' => $status,
                'message' => \sprintf('The user %s the request for speaker details; nothing was submitted.', $status),
            ]);
        }

        $content = $answer->content ?? [];

        if ('accepted' !== ($content['consent'] ?? null)) {
            return $this->result(['status' => 'rejected', 'message' => 'The code of conduct was not accepted; nothing was submitted.']);
        }

        $proposal = new Proposal(
            title: $title,
            abstract: $abstract,
            speakerName: (string) ($content['name'] ?? ''),
            speakerEmail: (string) ($content['email'] ?? ''),
            track: Track::Backend,
            level: Level::Intermediate,
        );
        $this->entityManager->persist($proposal);
        $this->entityManager->flush();

        return $this->result([
            'status' => 'submitted',
            'message' => \sprintf('Proposal #%d recorded.', $proposal->getId()),
            'proposal' => $this->presenter->proposal($proposal),
        ]);
    }

    /**
     * Have the host's model draft a review of a pending proposal, over a returned ask.
     *
     * @param int $proposalId the proposal to review
     */
    #[McpTool(
        name: 'review_proposal_mrtr',
        title: 'Draft a proposal review (multi round-trip)',
        description: "Ask the host's model to draft a programme-committee review of a submitted proposal, by returning the sampling request instead of sending it, and store it. Works on every protocol revision.",
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function reviewProposal(RequestContext $context, int $proposalId): CallToolResult|InputRequiredResult
    {
        $proposal = $this->proposals->find($proposalId);

        if (null === $proposal) {
            throw new ToolCallException(\sprintf('No proposal with id %d.', $proposalId));
        }

        if (!$context->getClientGateway()->supportsSampling()) {
            return new CallToolResult([new TextContent(\sprintf(
                "This client does not offer sampling, so the review has to be written by hand.\n\nProposal #%d — %s\n\n%s",
                $proposal->getId(),
                $proposal->getTitle(),
                $proposal->getAbstract(),
            ))]);
        }

        $answer = $context->getInputContext()?->samplingResult('review');

        if (null === $answer) {
            return new InputRequiredResult(['review' => new CreateSamplingMessageRequest(
                messages: [new SamplingMessage(Role::User, new TextContent(
                    ProposalAsks::reviewPrompt($this->conferences->current()->getTitle(), $proposal),
                ))],
                maxTokens: ProposalAsks::REVIEW_MAX_TOKENS,
                systemPrompt: ProposalAsks::REVIEW_SYSTEM_PROMPT,
                temperature: ProposalAsks::REVIEW_TEMPERATURE,
            )]);
        }

        $review = $answer->content instanceof TextContent ? trim((string) $answer->content->text) : '';
        $score = ProposalAsks::score($review);

        $proposal->review($review, $score);
        $this->entityManager->flush();

        return new CallToolResult([new TextContent(\sprintf(
            "Review drafted by %s for proposal #%d (score %d):\n\n%s",
            $answer->model,
            $proposal->getId(),
            $score,
            $review,
        ))]);
    }

    /**
     * A structured result with the JSON mirrored into a text block, which is
     * what the SDK does for a tool returning an array.
     *
     * @param array<string, mixed> $data
     */
    private function result(array $data): CallToolResult
    {
        return new CallToolResult(
            [new TextContent(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES))],
            structuredContent: $data,
        );
    }
}
