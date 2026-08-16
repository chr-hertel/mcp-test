<?php

declare(strict_types=1);

namespace App\Mcp\Loader;

use App\Repository\ConferenceRepository;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;

/**
 * Registering elements at runtime, without attributes.
 *
 * Attributes are reflected once at container compile time, which is right for
 * anything the code knows about. A loader is the escape hatch for what it does
 * not: elements whose *existence* depends on data — one resource per conference
 * day here — or on a third-party bundle, a database table, a feature flag.
 *
 * Implementations are autoconfigured with the `mcp.loader` tag and run when the
 * server is built, on every process. Anything expensive belongs behind a cache;
 * this one is two queries.
 *
 * Registered on every configured server: a loader has no capability list, so
 * unlike an attributed element it cannot be scoped to one server. That
 * asymmetry is written up in docs/patches.md — it is the reason the
 * `diagnostics` server answers `resources/list` with entries its own
 * configuration never mentions.
 */
final class HouseKeepingLoader implements LoaderInterface
{
    public function __construct(private readonly ConferenceRepository $conferences)
    {
    }

    public function load(RegistryInterface $registry): void
    {
        $conference = $this->conferences->current();

        // One "practical information" resource per conference day. The set of
        // URIs is data, so no attribute could have declared them.
        foreach ($conference->getDays() as $index => $day) {
            $uri = \sprintf('info://day/%d', $index + 1);

            $registry->registerResource(
                new ResourceDefinition(
                    uri: $uri,
                    name: \sprintf('practical_information_day_%d', $index + 1),
                    description: \sprintf('Doors, breaks and evening plans for %s.', $day),
                    mimeType: 'text/markdown',
                    title: \sprintf('Practical information, day %d', $index + 1),
                ),
                static fn (): TextResourceContents => new TextResourceContents(
                    uri: $uri,
                    mimeType: 'text/markdown',
                    text: \sprintf(
                        "# Day %d — %s\n\n".
                        "- **08:30** Doors and registration\n".
                        "- **09:30** First session\n".
                        "- **10:15** Coffee, in the foyer\n".
                        "- **12:30** Lunch, in the foyer\n".
                        "- **15:30** Coffee, in the foyer\n".
                        "- **18:00** Doors close\n\n".
                        "Venue: %s. Wifi is on the badge.\n",
                        $index + 1,
                        (new \DateTimeImmutable($day))->format('l, j F Y'),
                        $conference->getCity(),
                    ),
                ),
            );
        }

        // A tool registered as a closure rather than a class, which is what makes
        // a loader worth reaching for when the handler is this small.
        $registry->registerTool(
            new Tool(
                name: 'get_venue_information',
                inputSchema: ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                description: 'Where the conference is, and how to get in.',
                title: 'Venue information',
                annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
            ),
            static fn (): array => [
                'conference' => $conference->getTitle(),
                'city' => $conference->getCity(),
                'timezone' => $conference->getTimezone(),
                'website' => $conference->getWebsite(),
                'days' => $conference->getDays(),
                'doors_open' => '08:30',
                'registered_via' => 'the badge you were sent by email',
            ],
        );
    }
}
