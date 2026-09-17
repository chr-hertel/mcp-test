<?php

declare(strict_types=1);

namespace App\Chat\Platform;

use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Picks one of the tools an MCP server advertises, from what the user typed.
 *
 * This is the part of {@see ScriptedPlatform} that stands in for the model's
 * judgement, and it is deliberately dull: score every tool by how much of its
 * name and description the sentence repeats, then try to fill the arguments its
 * schema requires from the same sentence. A tool whose required arguments cannot
 * be filled is passed over rather than called with invented values — a wrong
 * argument would come back as a protocol error and teach nobody anything.
 *
 * The scoring reads the *published schema*, not this application's classes: the
 * same rules work against any MCP server, which is what makes the chat page a
 * check of the client stack rather than of a hard-coded script.
 */
final class ToolChoice
{
    /**
     * Words that carry no subject. Short ones are filtered by length instead.
     */
    private const STOP_WORDS = [
        'about', 'after', 'again', 'against', 'anything', 'around', 'because', 'been', 'before',
        'being', 'below', 'between', 'both', 'could', 'does', 'doing', 'during', 'each', 'from',
        'further', 'give', 'have', 'having', 'here', 'hers', 'into', 'itself', 'just', 'know',
        'like', 'look', 'made', 'make', 'many', 'more', 'most', 'much', 'must', 'need', 'once',
        'only', 'other', 'ours', 'over', 'own', 'please', 'same', 'show', 'some', 'such', 'take',
        'tell', 'than', 'that', 'their', 'theirs', 'them', 'then', 'there', 'these', 'they',
        'this', 'those', 'through', 'under', 'until', 'very', 'want', 'what', 'when', 'where',
        'which', 'while', 'whom', 'will', 'with', 'would', 'your', 'yours',
    ];

    /**
     * @param list<Tool> $tools
     */
    public function choose(string $text, array $tools): ?ToolCall
    {
        $tokens = $this->tokenize($text);

        if ([] === $tokens || [] === $tools) {
            return null;
        }

        $best = $this->best($tools, $text, $tokens);

        if (null === $best) {
            return null;
        }

        return new ToolCall($this->id($best['name'], $best['arguments']), $best['name'], $best['arguments']);
    }

    /**
     * The tool the sentence fits best, or null when it fits none.
     *
     * Three criteria, in order: how much of the tool the sentence repeats, how
     * much of the sentence the tool can take as arguments, and — for what is
     * still tied — the tool name, which puts the read-only `conference_` server
     * ahead of the privileged `organizer_` one where both advertise the same
     * tool.
     *
     * The second one is what separates `search_talks` from `list_unscheduled_talks`
     * for "which talks are about doctrine": both are about talks, only one has
     * somewhere to put "doctrine".
     *
     * @param list<Tool>   $tools
     * @param list<string> $tokens
     *
     * @return array{name: string, arguments: array<string, mixed>}|null
     */
    private function best(array $tools, string $text, array $tokens): ?array
    {
        $candidates = [];

        foreach ($tools as $tool) {
            $score = $this->score($tool, $tokens);

            if ($score <= 0) {
                continue;
            }

            $arguments = $this->arguments($tool, $text, $tokens);

            // Its schema asks for something the sentence does not contain.
            if (null === $arguments) {
                continue;
            }

            $candidates[] = ['name' => $tool->getName(), 'arguments' => $arguments, 'score' => $score];
        }

        if ([] === $candidates) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => [$b['score'], \count($b['arguments']), $a['name']] <=> [$a['score'], \count($a['arguments']), $b['name']]);

        return $candidates[0];
    }

    /**
     * @param list<string> $tokens
     */
    private function score(Tool $tool, array $tokens): int
    {
        $stems = array_map($this->stem(...), $tokens);
        $score = 0;

        // The server prefix the toolbox added ("conference_") says nothing about
        // what the tool does, so it is not scored.
        $own = array_map($this->stem(...), \array_slice($this->tokenize($tool->getName()), 1));

        foreach ($own as $stem) {
            if ($this->significant($stem) && \in_array($stem, $stems, true)) {
                $score += 3;
            }
        }

        // Only what the name did not already say: a description that repeats the
        // name would otherwise score the same word twice, and "list_unscheduled_talks"
        // would beat "search_talks" at being about talks.
        foreach (array_unique($this->tokenize($tool->getDescription())) as $word) {
            $stem = $this->stem($word);

            if ($this->significant($stem) && !\in_array($stem, $own, true) && \in_array($stem, $stems, true)) {
                ++$score;
            }
        }

        return $score;
    }

    /**
     * The arguments of one call, or null when a required one cannot be filled.
     *
     * @param list<string> $tokens
     *
     * @return array<string, mixed>|null
     */
    private function arguments(Tool $tool, string $text, array $tokens): ?array
    {
        $schema = $tool->getParameters();

        if (null === $schema) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'] ?? [];
        /** @var list<string> $required */
        $required = $schema['required'] ?? [];

        $arguments = [];

        // Identifiers are handed out from a pool, so two arguments of the same
        // call never get the same value: in "schedule doctrine-without-tears in
        // \"Studio A\"" the slug is the talk and the quoted phrase is the room.
        $identifiers = $this->identifiers($text);

        foreach ($properties as $name => $property) {
            $value = $this->value($name, $property, $text, $tokens, $tool, $identifiers);

            if (null !== $value) {
                $arguments[$name] = $value;

                continue;
            }

            if (\in_array($name, $required, true)) {
                return null;
            }
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed>                    $property
     * @param list<string>                            $tokens
     * @param array{slugs: list<string>, phrases: list<string>} $identifiers consumed as they are used
     */
    private function value(string $name, array $property, string $text, array $tokens, Tool $tool, array &$identifiers): string|int|null
    {
        /** @var list<string>|null $enum */
        $enum = $property['enum'] ?? null;
        if (\is_array($enum)) {
            foreach ($enum as $case) {
                if (\in_array(mb_strtolower((string) $case), $tokens, true)) {
                    return $case;
                }
            }

            return null;
        }

        // A day, in the only format the schemas use.
        if (preg_match('/(day|date)/i', $name) && preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $matches)) {
            return $matches[1];
        }

        // Free-text search: one subject word, because the servers match it as a
        // substring — a whole sentence would match nothing.
        if (preg_match('/^(query|q|search|term|text)$/i', $name)) {
            return $this->subject($tokens, $tool);
        }

        // An identifier: a slug as written, or a quoted phrase for the names that
        // carry spaces ("Studio A").
        if (preg_match('/(slug|identifier|room|speaker|talk|name)/i', $name)) {
            $slugFirst = 1 === preg_match('/(slug|identifier|speaker|talk)/i', $name);

            return $this->take($identifiers, $slugFirst ? ['slugs', 'phrases'] : ['phrases', 'slugs']);
        }

        // Anything else — counts, flags, free-form bodies — is left to the
        // server's own default rather than invented here.
        return null;
    }

    /**
     * Every identifier-shaped thing in the sentence, in the order it appears.
     *
     * @return array{slugs: list<string>, phrases: list<string>}
     */
    private function identifiers(string $text): array
    {
        preg_match_all('/\b([a-z0-9]+(?:-[a-z0-9]+){2,})\b/', mb_strtolower($text), $slugs);
        preg_match_all('/["\x{201C}]([^"\x{201D}]{2,60})["\x{201D}]/u', $text, $phrases);

        return [
            'slugs' => array_values($slugs[1]),
            'phrases' => array_map(trim(...), array_values($phrases[1])),
        ];
    }

    /**
     * @param array{slugs: list<string>, phrases: list<string>} $identifiers
     * @param list<string>                                      $pools
     */
    private function take(array &$identifiers, array $pools): ?string
    {
        foreach ($pools as $pool) {
            if ([] !== $identifiers[$pool]) {
                return array_shift($identifiers[$pool]);
            }
        }

        return null;
    }

    /**
     * Words too short or too common to say what a sentence is about. Scoring on
     * them would make every tool a match for every question.
     */
    private function significant(string $word): bool
    {
        return mb_strlen($word) >= 4 && !\in_array($word, self::STOP_WORDS, true);
    }

    /**
     * The word the sentence is about: its longest one that the tool does not
     * already carry in its name.
     *
     * @param list<string> $tokens
     */
    private function subject(array $tokens, Tool $tool): ?string
    {
        $own = array_map($this->stem(...), $this->tokenize($tool->getName()));

        $candidates = array_values(array_filter(
            $tokens,
            fn (string $token): bool => $this->significant($token) && !\in_array($this->stem($token), $own, true),
        ));

        if ([] === $candidates) {
            return null;
        }

        // Longest first, and alphabetically among equals, so the same sentence
        // always produces the same call.
        usort($candidates, static fn (string $a, string $b): int => [mb_strlen($b), $a] <=> [mb_strlen($a), $b]);

        return $candidates[0];
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*/u', mb_strtolower($text), $matches);

        return array_values(array_filter($matches[0], static fn (string $token): bool => mb_strlen($token) > 1));
    }

    /**
     * Singular and plural have to match each other: "talks" must find `search_talks`.
     */
    private function stem(string $word): string
    {
        return rtrim($word, 's');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function id(string $name, array $arguments): string
    {
        return 'scripted-'.substr(hash('xxh128', $name.'|'.json_encode($arguments, \JSON_THROW_ON_ERROR)), 0, 12);
    }
}
