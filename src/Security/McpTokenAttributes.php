<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Security;

/**
 * Names of the security-token attributes through which the authenticator of
 * koh/sylius-mcp-oauth hands the facts about the bearer token to this package.
 *
 * The two packages do not depend on each other; these strings are the whole
 * contract, and both sides pin them in a test. An authenticator that does not
 * set them (koh/sylius-mcp-oauth before 3.0, a custom one) still works: the
 * caller then counts as holding the legacy `mcp` scope, which is what every
 * token meant before scopes were split.
 */
final class McpTokenAttributes
{
    /** int — database id of the access token. */
    public const TOKEN_ID = 'koh_mcp.token_id';

    /** string|null — OAuth client_id, null for a token minted on the CLI. */
    public const CLIENT_ID = 'koh_mcp.client_id';

    /** list<string> — granted scopes. */
    public const SCOPES = 'koh_mcp.scopes';

    /** string|null — RFC 8707 resource the token is bound to. */
    public const AUDIENCE = 'koh_mcp.audience';

    private function __construct()
    {
    }
}
