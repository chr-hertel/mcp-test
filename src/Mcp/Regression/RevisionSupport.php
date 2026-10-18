<?php

declare(strict_types=1);

namespace App\Mcp\Regression;

use Mcp\Schema\Enum\ProtocolVersion;

/**
 * What one protocol revision lets the suite exercise.
 *
 * The runner adapts to the *server* everywhere — a check whose subject is not
 * exposed is skipped. This is the same rule one level down: a method the
 * revision removed is not a missing feature, and a check for it is either
 * skipped or turned around into a check that the server refuses it.
 *
 * One place, so the difference between the matrix columns can be read off
 * rather than reconstructed from a dozen conditionals.
 */
final class RevisionSupport
{
    private function __construct(
        public readonly ProtocolVersion $version,
        /** `ping` — gone with the connection it kept alive (SEP-2577). */
        public readonly bool $ping,
        /** `logging/setLevel` — the level rides on each request instead. */
        public readonly bool $setLoggingLevel,
        /**
         * Requests the server sends while one of the client's is open —
         * ClientGateway::sample() and listRoots(). Elicitation is not in here:
         * the SDK turns ClientGateway::elicit() into a second round trip on
         * 2026-07-28, so it works on both.
         */
        public readonly bool $serverInitiatedRequests,
    ) {
    }

    public static function of(ProtocolVersion $version): self
    {
        $handshake = !$version->isModern();

        return new self(
            version: $version,
            ping: $handshake,
            setLoggingLevel: $handshake,
            serverInitiatedRequests: $handshake,
        );
    }

    /**
     * The reason a check does not apply, for the skip it becomes.
     */
    public function removed(string $what = ''): string
    {
        return 'removed by '.$this->version->value.('' === $what ? '' : '; '.$what);
    }
}
