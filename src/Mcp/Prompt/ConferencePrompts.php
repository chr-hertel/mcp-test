<?php

declare(strict_types=1);

namespace App\Mcp\Prompt;

use App\Enum\Level;
use App\Enum\Track;
use App\Mcp\Completion\ConferenceDayCompletion;
use App\Mcp\Completion\SpeakerSlugCompletion;
use App\Mcp\Completion\TalkSlugCompletion;
use App\Repository\ConferenceRepository;
use App\Repository\SlotRepository;
use App\Repository\SpeakerRepository;
use App\Repository\TalkRepository;
use App\Service\ProgrammePresenter;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\PromptGetException;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Icon;

/**
 * Prompts are the user-initiated half of MCP: a host shows them as slash
 * commands or menu entries, the user picks one, and the server fills it with
 * live data before the model ever sees it.
 *
 * Every method parameter becomes a prompt argument. Arguments are always
 * strings on the wire, so `#[CompletionProvider]` is what makes them usable —
 * the host can offer the actual talk slugs instead of asking the user to
 * remember them.
 *
 * The three methods below show the three return shapes the SDK accepts:
 * the `['role' => …, 'content' => …]` shorthand, a list of
 * {@see PromptMessage} objects, and a message carrying an embedded resource.
 */
final class ConferencePrompts
{
    public function __construct(
        private readonly TalkRepository $talks,
        private readonly SpeakerRepository $speakers,
        private readonly SlotRepository $slots,
        private readonly ConferenceRepository $conferences,
        private readonly ProgrammePresenter $presenter,
    ) {
    }

    /**
     * Draft an abstract for a talk that does not exist yet.
     *
     * @param string $title the working title of the talk
     * @param Track  $track the track it would belong to
     * @param Level  $level the audience it targets
     *
     * @return list<array{role: string, content: string}>
     */
    #[McpPrompt(
        name: 'draft_talk_abstract',
        title: 'Draft a talk abstract',
        description: 'Draft a conference abstract for a working title, in the house style of this conference.',
    )]
    public function draftTalkAbstract(
        string $title,
        Track $track = Track::Backend,
        Level $level = Level::Intermediate,
    ): array {
        $conference = $this->conferences->current();

        // Ground the model in abstracts that were actually accepted, rather than
        // describing the house style in the abstract.
        $examples = [];
        foreach (\array_slice($this->talks->search(track: $track, limit: 3), 0, 3) as $talk) {
            $examples[] = \sprintf("### %s\n%s", $talk->getTitle(), $talk->getAbstract());
        }

        return [
            [
                'role' => 'user',
                'content' => \sprintf(
                    "You are writing a talk abstract for %s in %s.\n\n".
                    "House style: two or three sentences, present tense, concrete, no marketing language, no rhetorical questions.\n\n".
                    "Accepted abstracts from the %s track, for calibration:\n\n%s",
                    $conference->getTitle(),
                    $conference->getCity(),
                    $track->label(),
                    [] === $examples ? '_none yet_' : implode("\n\n", $examples),
                ),
            ],
            [
                'role' => 'user',
                'content' => \sprintf(
                    'Write the abstract for a %s-level talk titled "%s" in the %s track.',
                    $level->value,
                    $title,
                    $track->label(),
                ),
            ],
        ];
    }

    /**
     * Introduce a speaker from the stage, using their actual profile.
     *
     * @param string $speaker the speaker slug
     * @param string $tone    how the introduction should sound
     *
     * @return list<PromptMessage>
     */
    #[McpPrompt(
        name: 'introduce_speaker',
        title: 'Write a stage introduction',
        description: 'Write the 30-second introduction an MC reads before a speaker takes the stage.',
        icons: [new Icon(src: 'https://symfony.com/favicon.ico', mimeType: 'image/x-icon', sizes: ['any'])],
    )]
    public function introduceSpeaker(
        #[CompletionProvider(provider: SpeakerSlugCompletion::class)]
        string $speaker,
        #[Schema(enum: ['warm', 'neutral', 'playful'])]
        #[CompletionProvider(values: ['warm', 'neutral', 'playful'])]
        string $tone = 'warm',
    ): array {
        $entity = $this->speakers->findOneBySlug($speaker);

        if (null === $entity) {
            // PromptGetException is the prompt-side equivalent of ToolCallException.
            throw new PromptGetException(\sprintf('No speaker with slug "%s".', $speaker));
        }

        $talks = [];
        foreach ($entity->getTalks() as $talk) {
            $talks[] = \sprintf('"%s" (%s track)', $talk->getTitle(), $talk->getTrack()->label());
        }

        return [
            new PromptMessage(Role::User, new TextContent(\sprintf(
                "Write a %s, 30-second stage introduction for %s.\n\n".
                "Company: %s\nCountry: %s\nBio: %s\nSpeaking about: %s\n\n".
                'Do not invent achievements. End with the title of their talk.',
                $tone,
                $entity->getName(),
                $entity->getCompany() ?? 'independent',
                $entity->getCountry() ?? 'unknown',
                $entity->getBio(),
                [] === $talks ? 'nothing yet' : implode(', ', $talks),
            ))),
        ];
    }

    /**
     * Review one conference day against the schedule itself.
     *
     * @param string $day the conference day, YYYY-MM-DD
     *
     * @return list<PromptMessage>
     */
    #[McpPrompt(
        name: 'review_schedule_day',
        title: 'Review a conference day',
        description: 'Review one day of the programme for clashes, pacing and track balance, with the agenda attached.',
    )]
    public function reviewScheduleDay(
        #[CompletionProvider(provider: ConferenceDayCompletion::class)]
        string $day,
    ): array {
        $conference = $this->conferences->current();

        if (!\in_array($day, $conference->getDays(), true)) {
            throw new PromptGetException(\sprintf(
                'The conference does not run on %s. Its days are: %s.',
                $day,
                implode(', ', $conference->getDays()),
            ));
        }

        $agenda = $this->presenter->scheduleAsMarkdown($conference, $this->slots->findForDay($day));

        return [
            new PromptMessage(Role::User, new TextContent(
                'You are reviewing a conference day for the programme committee. '.
                'Look for two talks on the same topic opposite each other, a track that dominates a time band, '.
                'and rooms whose capacity does not match the expected draw. Be specific and brief.'
            )),
            // An embedded resource puts the data *in* the prompt, so the host does
            // not have to fetch schedule://<day> separately before sending it.
            new PromptMessage(Role::User, new EmbeddedResource(new TextResourceContents(
                uri: 'schedule://'.$day,
                mimeType: 'text/markdown',
                text: $agenda,
            ))),
        ];
    }

    /**
     * Turn one accepted talk into social-media copy.
     *
     * @param string $slug     the talk slug
     * @param string $platform where the post will go
     *
     * @return list<array{role: string, content: string}>
     */
    #[McpPrompt(
        name: 'promote_talk',
        title: 'Promote a talk',
        description: 'Write a short promotional post for an accepted talk.',
    )]
    public function promoteTalk(
        #[CompletionProvider(provider: TalkSlugCompletion::class)]
        string $slug,
        #[CompletionProvider(values: ['mastodon', 'bluesky', 'linkedin'])]
        string $platform = 'mastodon',
    ): array {
        $talk = $this->talks->findOneBySlug($slug);

        if (null === $talk) {
            throw new PromptGetException(\sprintf('No talk with slug "%s".', $slug));
        }

        $slot = $talk->getSlot();
        $limit = match ($platform) {
            'bluesky' => 300,
            'mastodon' => 500,
            default => 1200,
        };

        return [
            [
                'role' => 'user',
                'content' => \sprintf(
                    "Write one %s post of at most %d characters promoting this talk at %s.\n\n".
                    "Title: %s\nSpeaker: %s (%s)\nTrack: %s\nWhen: %s\nAbstract: %s\n\n".
                    'No hashtag soup: at most two. Do not use the word "excited".',
                    $platform,
                    $limit,
                    $this->conferences->current()->getTitle(),
                    $talk->getTitle(),
                    $talk->getSpeaker()->getName(),
                    $talk->getSpeaker()->getMastodon() ?? 'no handle',
                    $talk->getTrack()->label(),
                    null === $slot ? 'not scheduled yet' : $slot->getStartsAt()->format('l H:i').' in '.$slot->getRoom()->getName(),
                    $talk->getAbstract(),
                ),
            ],
        ];
    }
}
