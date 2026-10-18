<?php

declare(strict_types=1);

namespace App\Mcp\Regression;

use Mcp\Schema\Enum\ProtocolVersion;
use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Which client meets which server, and what they have to agree on.
 *
 * The SDK speaks two eras of the protocol on both ends: the handshake era
 * (`initialize`, a session, requests the server sends back) up to 2025-11-25,
 * and the modern one from 2026-07-28 (no handshake, no session, the server
 * returns what it needs). The suite runs once per era, against the same servers:
 *
 *                      handshake (2025-11-25)    modern (2026-07-28)
 *     conference_stdio          full                    full
 *     organizer_stdio           full                    full
 *     conference_http           full                    full
 *     organizer_http            full                    full
 *     diagnostics_http          full                    full
 *     modern_http               full                    full
 *
 * Older revisions get no column of their own. What can go wrong with them is
 * negotiation — a client and a server that do not ask for the same revision —
 * so the cells below the grid check only what two sides settle on:
 *
 *     legacy (2024-11-05) × conference  → 2024-11-05   the server speaks it
 *     legacy (2024-11-05) × legacy      → 2025-06-18   the server answers its own
 *     handshake           × legacy      → 2025-06-18   negotiated down
 *     modern              × legacy      → 2025-06-18   no modern leg: falls back
 *
 * The `legacy` server is pinned to 2025-06-18 and refuses the modern era; see
 * config/packages/mcp.yaml.
 *
 * @see RevisionSupport for what differs between the two columns
 */
final class ProtocolMatrix
{
    /**
     * The columns: one client per era, by the name it has in config/packages/mcp.yaml.
     */
    public const COLUMNS = [
        'handshake' => ProtocolVersion::V2025_11_25,
        'modern' => ProtocolVersion::V2026_07_28,
    ];

    /**
     * The rows: server connections both column clients declare.
     */
    public const ROWS = [
        'conference_stdio',
        'organizer_stdio',
        'conference_http',
        'organizer_http',
        'diagnostics_http',
        'modern_http',
    ];

    /**
     * @var array<string, McpClientInterface>
     */
    private readonly array $clients;

    public function __construct(
        #[Target('handshake')] McpClientInterface $handshake,
        #[Target('modern')] McpClientInterface $modern,
        #[Target('legacy')] McpClientInterface $legacy,
    ) {
        $this->clients = ['handshake' => $handshake, 'modern' => $modern, 'legacy' => $legacy];
    }

    /**
     * @return list<MatrixCell>
     */
    public static function cells(): array
    {
        $cells = [];

        foreach (self::ROWS as $server) {
            foreach (self::COLUMNS as $client => $version) {
                $cells[] = new MatrixCell($client, $server, $version);
            }
        }

        $cells[] = new MatrixCell('legacy', 'conference_stdio', ProtocolVersion::V2024_11_05, full: false);
        $cells[] = new MatrixCell('legacy', 'legacy_stdio', ProtocolVersion::V2025_06_18, full: false);
        $cells[] = new MatrixCell('handshake', 'legacy_stdio', ProtocolVersion::V2025_06_18, full: false);
        $cells[] = new MatrixCell('modern', 'legacy_stdio', ProtocolVersion::V2025_06_18, full: false);

        return $cells;
    }

    public function connect(MatrixCell $cell): ServerConnectionInterface
    {
        return $this->clients[$cell->client]->get($cell->server);
    }
}
