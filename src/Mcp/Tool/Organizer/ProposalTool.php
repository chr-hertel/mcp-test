<?php

declare(strict_types=1);

namespace App\Mcp\Tool\Organizer;

use App\Entity\Proposal;
use App\Enum\Level;
use App\Enum\ProposalStatus;
use App\Enum\Track;
use App\Repository\ConferenceRepository;
use App\Repository\ProposalRepository;
use App\Service\ProgrammePresenter;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;

/**
 * The two client-capability round trips a server can start on its own:
 * **elicitation** (ask the human for structured input) and **sampling** (ask the
 * host's model to generate something).
 *
 * Both are optional client capabilities. A server that needs one has to check
 * `supports*()` first and degrade into something useful when the client cannot
 * do it — a client that never advertised the capability answers "method not
 * found", which reaches the model as an unhelpful protocol error.
 *
 * Both were deprecated in protocol revision 2026-07-28 (SEP-2577) and remain
 * functional; they are demonstrated here because hosts still speak them.
 */
final class ProposalTool
{
    public function __construct(
        private readonly ProposalRepository $proposals,
        private readonly ConferenceRepository $conferences,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgrammePresenter $presenter,
    ) {
    }

    /**
     * Submit a CFP proposal, asking the user for anything that is missing.
     *
     * @param string      $title    the proposal title
     * @param string      $abstract the proposal abstract
     * @param string|null $email    the speaker's email; elicited from the user when omitted
     * @param string|null $name     the speaker's name; elicited from the user when omitted
     *
     * @return array{status: string, message: string, proposal?: array<string, mixed>}
     */
    #[McpTool(
        name: 'submit_proposal',
        title: 'Submit a CFP proposal',
        description: 'Submit a talk proposal to the Call for Papers. Missing speaker details are collected from the user via elicitation.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function submitProposal(
        RequestContext $context,
        #[Schema(minLength: 8, maxLength: 200)]
        string $title,
        #[Schema(minLength: 40, maxLength: 2000)]
        string $abstract,
        Track $track = Track::Backend,
        Level $level = Level::Intermediate,
        #[Schema(format: 'email')]
        ?string $email = null,
        ?string $name = null,
    ): array {
        if (!$this->conferences->current()->isCfpOpen()) {
            throw new ToolCallException('The Call for Papers is closed for this edition.');
        }

        $client = $context->getClientGateway();

        if (null === $name || null === $email) {
            if (!$client->supportsElicitation()) {
                // Degrading, not failing: the model can retry with the arguments spelled out.
                return [
                    'status' => 'needs_input',
                    'message' => 'This client cannot prompt the user. Call submit_proposal again with the "name" and "email" arguments filled in.',
                ];
            }

            $result = $client->elicit(
                message: \sprintf('Who should we credit for "%s"?', $title),
                requestedSchema: new ElicitationSchema(
                    properties: [
                        'name' => new StringSchemaDefinition(
                            title: 'Speaker name',
                            description: 'The name to print in the programme.',
                            minLength: 2,
                            maxLength: 120,
                        ),
                        'email' => new StringSchemaDefinition(
                            title: 'Speaker email',
                            description: 'Where the programme committee should reply.',
                            format: 'email',
                        ),
                        'consent' => new EnumSchemaDefinition(
                            title: 'Code of conduct',
                            enum: ['accepted', 'declined'],
                            description: 'Speakers must accept the code of conduct.',
                            enumNames: ['I accept', 'I decline'],
                        ),
                    ],
                    required: ['name', 'email', 'consent'],
                ),
            );

            if ($result->isDeclined()) {
                return ['status' => 'declined', 'message' => 'The user declined to provide speaker details; nothing was submitted.'];
            }

            if ($result->isCancelled()) {
                return ['status' => 'cancelled', 'message' => 'The elicitation was cancelled; nothing was submitted.'];
            }

            $content = $result->content ?? [];

            if ('accepted' !== ($content['consent'] ?? null)) {
                return ['status' => 'rejected', 'message' => 'The code of conduct was not accepted; nothing was submitted.'];
            }

            $name ??= (string) ($content['name'] ?? '');
            $email ??= (string) ($content['email'] ?? '');
        }

        if ('' === $name || '' === $email) {
            throw new ToolCallException('A proposal needs both a speaker name and a speaker email.');
        }

        $proposal = new Proposal($title, $abstract, $name, $email, $track, $level);
        $this->entityManager->persist($proposal);
        $this->entityManager->flush();

        $client->log(LoggingLevel::Info, \sprintf('Proposal #%d submitted: %s', $proposal->getId(), $title));

        return [
            'status' => 'submitted',
            'message' => \sprintf('Proposal #%d recorded.', $proposal->getId()),
            'proposal' => $this->presenter->proposal($proposal),
        ];
    }

    /**
     * Have the host's model draft a review of a pending proposal.
     *
     * @param int $proposalId the proposal to review
     *
     * @return list<TextContent>
     */
    #[McpTool(
        name: 'review_proposal',
        title: 'Draft a proposal review',
        description: "Ask the host's model to draft a programme-committee review of a submitted proposal, and store it.",
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function reviewProposal(RequestContext $context, int $proposalId): array
    {
        $proposal = $this->proposals->find($proposalId);

        if (null === $proposal) {
            throw new ToolCallException(\sprintf('No proposal with id %d.', $proposalId));
        }

        $client = $context->getClientGateway();

        if (!$client->supportsSampling()) {
            return [new TextContent(\sprintf(
                "This client does not offer sampling, so the review has to be written by hand.\n\nProposal #%d — %s\n\n%s",
                $proposal->getId(),
                $proposal->getTitle(),
                $proposal->getAbstract(),
            ))];
        }

        $result = $client->sample(
            message: \sprintf(
                "You are on the programme committee of %s.\n\n".
                "Review this proposal in at most six sentences, then give a score from 1 to 5 on its own last line as \"Score: N\".\n\n".
                "Title: %s\nTrack: %s\nLevel: %s\nSpeaker: %s\n\nAbstract:\n%s",
                $this->conferences->current()->getTitle(),
                $proposal->getTitle(),
                $proposal->getTrack()->value,
                $proposal->getLevel()->value,
                $proposal->getSpeakerName(),
                $proposal->getAbstract(),
            ),
            maxTokens: 400,
            options: [
                'systemPrompt' => 'You are a fair, concise conference programme committee member.',
                'temperature' => 0.3,
            ],
        );

        $review = $result->content instanceof TextContent ? trim((string) $result->content->text) : '';
        $score = preg_match('/Score:\s*([1-5])/i', $review, $matches) ? (int) $matches[1] : 3;

        $proposal->review($review, $score);
        $this->entityManager->flush();

        return [new TextContent(\sprintf(
            "Review drafted by %s for proposal #%d (score %d):\n\n%s",
            $result->model,
            $proposal->getId(),
            $score,
            $review,
        ))];
    }

    /**
     * List CFP proposals.
     *
     * @return array{count: int, proposals: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'list_proposals',
        title: 'List CFP proposals',
        description: 'List the Call for Papers submissions, optionally filtered by status.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function listProposals(?ProposalStatus $status = null): array
    {
        $proposals = $this->proposals->findByStatus($status);

        return [
            'count' => \count($proposals),
            'proposals' => array_map($this->presenter->proposal(...), $proposals),
        ];
    }
}
