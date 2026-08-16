<?php

declare(strict_types=1);

namespace App\Mcp\Tool\Programme;

use App\Enum\Level;
use App\Enum\Track;
use App\Mcp\Completion\ConferenceDayCompletion;
use App\Mcp\Completion\SpeakerSlugCompletion;
use App\Repository\TalkRepository;
use App\Service\ProgrammePresenter;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Icon;
use Mcp\Schema\ToolAnnotations;

/**
 * The reference read-only tool of this demo.
 *
 * It exercises most of what the SDK's schema generator can express from a plain
 * method signature:
 *
 * - a native backed enum parameter becomes a JSON Schema `enum`;
 * - `#[Schema]` on a parameter adds constraints the type cannot carry
 *   (`minLength`, `minimum`/`maximum`, `pattern`);
 * - `#[CompletionProvider]` makes an argument completable through
 *   `completion/complete`, resolved from the service container;
 * - `#[McpTool(outputSchema: …)]` declares the shape of the structured result,
 *   which the SDK validates the return value against before answering.
 */
final class TalkSearchTool
{
    public function __construct(
        private readonly TalkRepository $talks,
        private readonly ProgrammePresenter $presenter,
    ) {
    }

    /**
     * Search the conference programme for talks.
     *
     * Every filter is optional; with none of them set the tool returns the first
     * page of the whole programme, ordered by title.
     *
     * @param string|null $query   free-text match against title, abstract and speaker name
     * @param Track|null  $track   restrict to one conference track
     * @param Level|null  $level   restrict to one audience level
     * @param string|null $day     restrict to talks scheduled on this day (`YYYY-MM-DD`)
     * @param string|null $speaker restrict to one speaker, by slug
     * @param int         $limit   maximum number of talks to return
     *
     * @return array{query: array<string, mixed>, total: int, count: int, talks: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'search_talks',
        title: 'Search talks',
        description: 'Search the conference programme by free text, track, level, day or speaker.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
        icons: [new Icon(src: 'https://symfony.com/favicon.ico', mimeType: 'image/x-icon', sizes: ['any'])],
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'object', 'description' => 'The filters that were applied.'],
                'total' => ['type' => 'integer', 'description' => 'Number of talks matching the filters, ignoring the limit.'],
                'count' => ['type' => 'integer', 'description' => 'Number of talks in this response.'],
                'talks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'slug' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'abstract' => ['type' => 'string'],
                            'track' => ['type' => 'string'],
                            'level' => ['type' => 'string'],
                            'language' => ['type' => 'string'],
                            'duration_minutes' => ['type' => 'integer'],
                            'status' => ['type' => 'string'],
                            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'speaker' => ['type' => 'object'],
                            'schedule' => ['type' => ['object', 'null']],
                            'uri' => ['type' => 'string'],
                        ],
                        'required' => ['slug', 'title', 'track', 'level', 'speaker', 'uri'],
                    ],
                ],
            ],
            'required' => ['query', 'total', 'count', 'talks'],
        ],
    )]
    public function __invoke(
        #[Schema(description: 'Free-text search across title, abstract and speaker name.', minLength: 2, maxLength: 120)]
        ?string $query = null,
        ?Track $track = null,
        ?Level $level = null,
        #[Schema(description: 'A conference day in YYYY-MM-DD form.', pattern: '^\d{4}-\d{2}-\d{2}$')]
        #[CompletionProvider(provider: ConferenceDayCompletion::class)]
        ?string $day = null,
        #[Schema(description: 'A speaker slug, e.g. "nadia-fischer".')]
        #[CompletionProvider(provider: SpeakerSlugCompletion::class)]
        ?string $speaker = null,
        #[Schema(minimum: 1, maximum: 50)]
        int $limit = 20,
    ): array {
        $talks = $this->talks->search(
            query: $query,
            track: $track,
            level: $level,
            day: $day,
            speakerSlug: $speaker,
            limit: $limit,
        );

        return [
            'query' => array_filter([
                'query' => $query,
                'track' => $track?->value,
                'level' => $level?->value,
                'day' => $day,
                'speaker' => $speaker,
                'limit' => $limit,
            ], static fn (mixed $value): bool => null !== $value),
            'total' => $this->talks->countMatching($query, $track, $level),
            'count' => \count($talks),
            'talks' => array_map($this->presenter->talk(...), $talks),
        ];
    }
}
