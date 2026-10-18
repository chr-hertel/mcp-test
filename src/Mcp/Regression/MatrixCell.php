<?php

declare(strict_types=1);

namespace App\Mcp\Regression;

use Mcp\Schema\Enum\ProtocolVersion;

/**
 * One client against one server, and the revision the two must settle on.
 *
 * A full cell runs the whole {@see RegressionRunner}; a negotiation cell only
 * connects and checks the revision, which is all the matrix asks of the
 * revisions older than its columns.
 */
final class MatrixCell
{
    public function __construct(
        public readonly string $client,
        public readonly string $server,
        public readonly ProtocolVersion $expected,
        public readonly bool $full = true,
    ) {
    }

    /**
     * The transport is not introspectable from a connection, so the naming
     * convention in config/packages/mcp.yaml is what this reads.
     */
    public function transport(): string
    {
        return str_ends_with($this->server, '_stdio') ? 'stdio' : 'http';
    }

    public function label(): string
    {
        return \sprintf('%s × %s', $this->client, $this->server);
    }
}
