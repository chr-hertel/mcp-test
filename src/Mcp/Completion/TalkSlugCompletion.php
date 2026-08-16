<?php

declare(strict_types=1);

namespace App\Mcp\Completion;

use App\Repository\TalkRepository;
use Mcp\Capability\Completion\ProviderInterface;

/**
 * Argument completion for a talk slug, backed by the database.
 *
 * The SDK resolves a `#[CompletionProvider(provider: self::class)]` through the
 * PSR-11 container the server was built with — in a Symfony application that is
 * the service container, so the provider can be a normal autowired service.
 */
final class TalkSlugCompletion implements ProviderInterface
{
    public function __construct(private readonly TalkRepository $talks)
    {
    }

    public function getCompletions(string $currentValue): array
    {
        $slugs = $this->talks->findSlugs();

        if ('' === $currentValue) {
            return \array_slice($slugs, 0, 100);
        }

        $needle = mb_strtolower($currentValue);

        return array_values(array_filter($slugs, static fn (string $slug): bool => str_contains($slug, $needle)));
    }
}
