<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Security;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Builds the {@see McpCaller} for the tool call being handled.
 */
final class McpCallerResolver
{
    public function __construct(
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly bool $consoleIsOperator = \PHP_SAPI === 'cli',
    ) {
    }

    public function resolve(): McpCaller
    {
        $request = $this->requestStack?->getMainRequest();
        $ip = $request?->getClientIp();

        // stdio: no HTTP request at all, the server runs inside
        // `bin/console mcp:server`. Whoever can start that already holds the
        // application's database credentials; a token check would protect
        // nothing.
        if (null === $request && $this->consoleIsOperator) {
            return new McpCaller('console', null, console: true);
        }

        $token = $this->tokenStorage?->getToken();
        if (null === $token || null === $token->getUser()) {
            return McpCaller::anonymous($ip);
        }

        $scopes = $token->hasAttribute(McpTokenAttributes::SCOPES) ? $token->getAttribute(McpTokenAttributes::SCOPES) : null;
        $tokenId = $token->hasAttribute(McpTokenAttributes::TOKEN_ID) ? $token->getAttribute(McpTokenAttributes::TOKEN_ID) : null;
        $clientId = $token->hasAttribute(McpTokenAttributes::CLIENT_ID) ? $token->getAttribute(McpTokenAttributes::CLIENT_ID) : null;

        return new McpCaller(
            user: $token->getUserIdentifier(),
            scopes: \is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : null,
            tokenId: \is_int($tokenId) ? $tokenId : null,
            clientId: \is_string($clientId) ? $clientId : null,
            ip: $ip,
        );
    }
}
