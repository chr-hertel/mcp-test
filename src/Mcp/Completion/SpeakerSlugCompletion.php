<?php

declare(strict_types=1);

namespace App\Mcp\Completion;

use App\Repository\SpeakerRepository;
use Mcp\Capability\Completion\ProviderInterface;

final class SpeakerSlugCompletion implements ProviderInterface
{
    public function __construct(private readonly SpeakerRepository $speakers)
    {
    }

    public function getCompletions(string $currentValue): array
    {
        $slugs = $this->speakers->findSlugs();

        if ('' === $currentValue) {
            return $slugs;
        }

        $needle = mb_strtolower($currentValue);

        return array_values(array_filter($slugs, static fn (string $slug): bool => str_contains($slug, $needle)));
    }
}
