<?php

declare(strict_types=1);

namespace App\Mcp\Completion;

use App\Repository\RoomRepository;
use Mcp\Capability\Completion\ProviderInterface;

final class RoomNameCompletion implements ProviderInterface
{
    public function __construct(private readonly RoomRepository $rooms)
    {
    }

    public function getCompletions(string $currentValue): array
    {
        $names = $this->rooms->findNames();

        if ('' === $currentValue) {
            return $names;
        }

        $needle = mb_strtolower($currentValue);

        return array_values(array_filter($names, static fn (string $name): bool => str_contains(mb_strtolower($name), $needle)));
    }
}
