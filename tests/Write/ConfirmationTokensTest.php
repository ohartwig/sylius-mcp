<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Write;

use Koh\SyliusMcp\Security\McpCaller;
use Koh\SyliusMcp\Write\ConfirmationTokens;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A confirm token confirms exactly the call that was previewed: same tool,
 * same arguments, same administrator, same access token, within its lifetime,
 * once.
 */
final class ConfirmationTokensTest extends TestCase
{
    private McpCaller $caller;

    protected function setUp(): void
    {
        $this->caller = new McpCaller('admin@example.com', ['mcp:write'], 42, 'mcpc_a', '203.0.113.7');
    }

    public function testTheTokenConfirmsThePreviewedCall(): void
    {
        $tokens = new ConfirmationTokens('secret');
        $token = $tokens->issue('update_product', ['code' => 'MUG', 'description' => 'New'], $this->caller);

        self::assertNull($tokens->verify($token->value, 'update_product', ['code' => 'MUG', 'description' => 'New'], $this->caller));
    }

    /** Argument order is the client's serialisation detail, not part of the call. */
    public function testArgumentOrderDoesNotMatter(): void
    {
        $tokens = new ConfirmationTokens('secret');
        $token = $tokens->issue('update_product', ['code' => 'MUG', 'description' => 'New'], $this->caller);

        self::assertNull($tokens->verify($token->value, 'update_product', ['description' => 'New', 'code' => 'MUG'], $this->caller));
    }

    public function testChangedArgumentsAreNotConfirmed(): void
    {
        $tokens = new ConfirmationTokens('secret');
        $token = $tokens->issue('update_product', ['code' => 'MUG', 'description' => 'New'], $this->caller);

        self::assertNotNull($tokens->verify($token->value, 'update_product', ['code' => 'MUG', 'description' => 'Other'], $this->caller));
        self::assertNotNull($tokens->verify($token->value, 'create_product', ['code' => 'MUG', 'description' => 'New'], $this->caller));
    }

    /** Another administrator — or the same one through another token — cannot use it. */
    public function testAnotherCallerCannotUseTheToken(): void
    {
        $tokens = new ConfirmationTokens('secret');
        $token = $tokens->issue('update_product', ['code' => 'MUG'], $this->caller);

        self::assertNotNull($tokens->verify($token->value, 'update_product', ['code' => 'MUG'], new McpCaller('other@example.com', ['mcp:write'], 42)));
        self::assertNotNull($tokens->verify($token->value, 'update_product', ['code' => 'MUG'], new McpCaller('admin@example.com', ['mcp:write'], 43)));
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $now = 1_000_000;
        $tokens = new ConfirmationTokens('secret', 300, null, static function () use (&$now): int {
            return $now;
        });
        $token = $tokens->issue('update_product', ['code' => 'MUG'], $this->caller);

        $now += 301;
        self::assertStringContainsString('expired', (string) $tokens->verify($token->value, 'update_product', ['code' => 'MUG'], $this->caller));
    }

    public function testATokenWorksOnceWhereACacheIsWired(): void
    {
        $tokens = new ConfirmationTokens('secret', 300, new ArrayAdapter());
        $token = $tokens->issue('update_product', ['code' => 'MUG'], $this->caller);

        self::assertNull($tokens->verify($token->value, 'update_product', ['code' => 'MUG'], $this->caller));
        self::assertStringContainsString('already been used', (string) $tokens->verify($token->value, 'update_product', ['code' => 'MUG'], $this->caller));
    }

    public function testATokenSignedWithAnotherSecretIsRefused(): void
    {
        $token = (new ConfirmationTokens('one'))->issue('update_product', ['code' => 'MUG'], $this->caller);

        self::assertNotNull((new ConfirmationTokens('two'))->verify($token->value, 'update_product', ['code' => 'MUG'], $this->caller));
        self::assertNotNull((new ConfirmationTokens('two'))->verify('garbage', 'update_product', ['code' => 'MUG'], $this->caller));
    }
}
