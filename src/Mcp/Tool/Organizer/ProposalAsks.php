<?php

declare(strict_types=1);

namespace App\Mcp\Tool\Organizer;

use App\Entity\Proposal;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;

/**
 * What the organizer tools ask the client, worded once.
 *
 * Each ask is made two ways — through the ClientGateway in
 * {@see ProposalTool}, and as a returned InputRequiredResult in
 * {@see MultiRoundTripTool} — and the regression suite compares the two. They can
 * only be compared if they ask the same thing, so the words live here.
 */
final class ProposalAsks
{
    public const REVIEW_MAX_TOKENS = 400;
    public const REVIEW_SYSTEM_PROMPT = 'You are a fair, concise conference programme committee member.';
    public const REVIEW_TEMPERATURE = 0.3;

    public static function speakerMessage(string $title): string
    {
        return \sprintf('Who should we credit for "%s"?', $title);
    }

    public static function speakerSchema(): ElicitationSchema
    {
        return new ElicitationSchema(
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
        );
    }

    public static function reviewPrompt(string $conference, Proposal $proposal): string
    {
        return \sprintf(
            "You are on the programme committee of %s.\n\n".
            "Review this proposal in at most six sentences, then give a score from 1 to 5 on its own last line as \"Score: N\".\n\n".
            "Title: %s\nTrack: %s\nLevel: %s\nSpeaker: %s\n\nAbstract:\n%s",
            $conference,
            $proposal->getTitle(),
            $proposal->getTrack()->value,
            $proposal->getLevel()->value,
            $proposal->getSpeakerName(),
            $proposal->getAbstract(),
        );
    }

    /**
     * The score on the review's last "Score: N" line, or a neutral 3 without one.
     */
    public static function score(string $review): int
    {
        return preg_match('/Score:\s*([1-5])/i', $review, $matches) ? (int) $matches[1] : 3;
    }
}
