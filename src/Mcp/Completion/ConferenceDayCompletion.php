<?php

declare(strict_types=1);

namespace App\Mcp\Completion;

use App\Repository\ConferenceRepository;
use Mcp\Capability\Completion\ProviderInterface;

final class ConferenceDayCompletion implements ProviderInterface
{
    public function __construct(private readonly ConferenceRepository $conferences)
    {
    }

    public function getCompletions(string $currentValue): array
    {
        $days = $this->conferences->current()->getDays();

        if ('' === $currentValue) {
            return $days;
        }

        return array_values(array_filter($days, static fn (string $day): bool => str_starts_with($day, $currentValue)));
    }
}
