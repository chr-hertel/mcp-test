<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * A bearer token in front of the privileged `organizer` MCP server.
 *
 * Deliberately the simplest thing that shows the point: an MCP endpoint is an
 * ordinary Symfony route, so it is protected by ordinary Symfony security. A
 * real deployment would use the MCP authorization spec (OAuth 2.1 with a
 * protected-resource-metadata document) — the SDK ships examples for Keycloak
 * and Microsoft Entra under `examples/server/oauth-*`.
 */
final class OrganizerTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly string $expectedToken)
    {
    }

    public function supports(Request $request): ?bool
    {
        return true; // The firewall pattern already scopes this to /mcp/organizer.
    }

    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            throw new CustomUserMessageAuthenticationException('An "Authorization: Bearer <token>" header is required.');
        }

        if (!hash_equals($this->expectedToken, substr($header, 7))) {
            throw new CustomUserMessageAuthenticationException('Invalid organizer token.');
        }

        return new SelfValidatingPassport(new UserBadge('organizer', static fn (): InMemoryUser => new InMemoryUser('organizer', null, ['ROLE_ORGANIZER'])));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // JSON-RPC clients read a body, not an HTML error page.
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32001, 'message' => $exception->getMessageKey()],
            'id' => null,
        ], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer realm="mcp-organizer"']);
    }
}
