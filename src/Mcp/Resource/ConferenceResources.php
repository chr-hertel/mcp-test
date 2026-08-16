<?php

declare(strict_types=1);

namespace App\Mcp\Resource;

use App\Repository\ConferenceRepository;
use App\Repository\SlotRepository;
use App\Repository\SpeakerRepository;
use App\Service\ProgrammePresenter;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Schema\Annotations;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Enum\Role;

/**
 * Static resources: fixed URIs a host can read without arguments.
 *
 * The SDK infers the response shape from the return type — an array becomes a
 * JSON text resource, a string becomes plain text, and returning a
 * {@see BlobResourceContents} explicitly is how binary data is served.
 */
final class ConferenceResources
{
    public function __construct(
        private readonly ConferenceRepository $conferences,
        private readonly SpeakerRepository $speakers,
        private readonly SlotRepository $slots,
        private readonly ProgrammePresenter $presenter,
    ) {
    }

    /**
     * The conference edition: dates, city, website and CFP state.
     *
     * @return array<string, mixed>
     */
    #[McpResource(
        uri: 'conference://current',
        name: 'conference',
        title: 'Conference details',
        description: 'Name, edition, city, dates, timezone and whether the CFP is open.',
        mimeType: 'application/json',
        annotations: new Annotations(audience: [Role::Assistant, Role::User], priority: 1.0),
    )]
    public function conference(): array
    {
        return $this->presenter->conference($this->conferences->current());
    }

    /**
     * The whole programme as a Markdown agenda.
     */
    #[McpResource(
        uri: 'schedule://full',
        name: 'full_schedule',
        title: 'Full schedule',
        description: 'Every scheduled talk across all conference days, as Markdown.',
        mimeType: 'text/markdown',
        annotations: new Annotations(audience: [Role::Assistant], priority: 0.9),
    )]
    public function fullSchedule(): string
    {
        return $this->presenter->scheduleAsMarkdown(
            $this->conferences->current(),
            $this->slots->findAllOrdered(),
        );
    }

    /**
     * Every speaker, as a JSON array.
     *
     * @return list<array<string, mixed>>
     */
    #[McpResource(
        uri: 'speaker://all',
        name: 'speakers',
        title: 'All speakers',
        description: 'Every speaker with their bio and the talks they are giving.',
        mimeType: 'application/json',
    )]
    public function speakers(): array
    {
        return array_map($this->presenter->speaker(...), $this->speakers->findAllOrdered());
    }

    /**
     * The conference badge, as a PNG.
     *
     * Returning {@see BlobResourceContents} is the explicit path for binary data:
     * the SDK base64-encodes it into a `blob` field rather than a `text` one.
     */
    #[McpResource(
        uri: 'conference://badge.png',
        name: 'badge',
        title: 'Conference badge',
        description: 'A small PNG badge for the current edition.',
        mimeType: 'image/png',
    )]
    public function badge(): BlobResourceContents
    {
        return new BlobResourceContents(
            uri: 'conference://badge.png',
            mimeType: 'image/png',
            blob: base64_encode($this->badgePng()),
        );
    }

    /**
     * A 16x16 PNG built at runtime, so the repository carries no binary blob.
     */
    private function badgePng(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', \strlen($data)).$type.$data.pack('N', crc32($type.$data));
        };

        $width = $height = 16;
        $ihdr = pack('NN', $width, $height)."\x08\x02\x00\x00\x00"; // 8-bit RGB

        $frame = "\x00\x00\x00";           // black
        $fill = \chr(0x00).\chr(0x9B).\chr(0x83); // Symfony green

        $raw = '';
        for ($y = 0; $y < $height; ++$y) {
            $raw .= "\x00"; // filter type: none
            for ($x = 0; $x < $width; ++$x) {
                $edge = 0 === $x || 0 === $y || $width - 1 === $x || $height - 1 === $y;
                $raw .= $edge ? $frame : $fill;
            }
        }

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', $ihdr)
            .$chunk('IDAT', gzcompress($raw, 9))
            .$chunk('IEND', '');
    }
}
