<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Security;

/**
 * Who is calling a tool: the administrator behind the bearer token, the token
 * itself, and where the request came from.
 */
final readonly class McpCaller
{
    /** Scopes a token carried before mcp:read and mcp:write existed. */
    public const LEGACY_SCOPE = 'mcp';

    public const SCOPE_READ = 'mcp:read';

    public const SCOPE_WRITE = 'mcp:write';

    /**
     * @param list<string>|null $scopes null: the authenticator did not say,
     *                                  which is read as the legacy full scope
     */
    public function __construct(
        public ?string $user,
        public ?array $scopes = null,
        public ?int $tokenId = null,
        public ?string $clientId = null,
        public ?string $ip = null,
        /** Console (stdio transport): the operator's shell, not a token. */
        public bool $console = false,
    ) {
    }

    public static function anonymous(?string $ip = null): self
    {
        return new self(null, [], ip: $ip);
    }

    public function isAuthenticated(): bool
    {
        return null !== $this->user || $this->console;
    }

    /** A write scope implies read: nobody wants a token that may change a product it may not look at. */
    public function mayRead(): bool
    {
        return null === $this->scopes
            || [] !== array_intersect($this->scopes, [self::LEGACY_SCOPE, self::SCOPE_READ, self::SCOPE_WRITE]);
    }

    public function mayWrite(): bool
    {
        return null === $this->scopes
            || [] !== array_intersect($this->scopes, [self::LEGACY_SCOPE, self::SCOPE_WRITE]);
    }
}
