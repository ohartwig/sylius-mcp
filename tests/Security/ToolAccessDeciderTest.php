<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Security;

use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Koh\SyliusMcp\Security\McpCaller;
use Koh\SyliusMcp\Security\McpCallerResolver;
use Koh\SyliusMcp\Tests\Support\McpSecurityFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who may call which tool. Each case states a decision a reviewer should be
 * able to read off the configuration; the mechanism is incidental.
 */
final class ToolAccessDeciderTest extends TestCase
{
    use McpSecurityFixtures;

    /** @return iterable<string, array{list<string>|null, string, bool}> */
    public static function scopeMatrix(): iterable
    {
        yield 'legacy mcp reads' => [['mcp'], 'search_products', true];
        yield 'legacy mcp writes' => [['mcp'], 'create_product', true];
        yield 'mcp:read reads' => [['mcp:read'], 'fetch_order', true];
        yield 'mcp:read may not fill a cart' => [['mcp:read'], 'add_item_to_order', false];
        yield 'mcp:read may not touch the catalogue' => [['mcp:read'], 'update_product', false];
        yield 'mcp:write writes' => [['mcp:write'], 'update_product', true];
        yield 'mcp:write implies read' => [['mcp:write'], 'search_products', true];
        yield 'no scope at all reads nothing' => [[], 'search_products', false];
        yield 'an unknown scope grants nothing' => [['openid'], 'search_products', false];
        // An authenticator that predates scopes sets no attribute; its tokens
        // meant "everything", and they keep meaning it.
        yield 'no scope attribute counts as legacy' => [null, 'create_product', true];
        yield 'an unclassified tool needs write' => [['mcp:read'], 'brand_new_tool', false];
    }

    /** @param list<string>|null $scopes */
    #[DataProvider('scopeMatrix')]
    public function testScopesGateToolClasses(?array $scopes, string $tool, bool $allowed): void
    {
        $decision = self::decider()->decide((new ToolPolicyProvider())->get($tool), self::callers(scopes: $scopes)->resolve());

        self::assertSame($allowed, $decision->allowed, (string) $decision->reason);
    }

    public function testWithoutAnAuthenticatedUserNothingIsAllowed(): void
    {
        $decision = self::decider()->decide((new ToolPolicyProvider())->get('search_products'), self::callers(user: null)->resolve());

        self::assertFalse($decision->allowed);
    }

    /**
     * A shop can keep catalogue changes to some administrators: the class
     * roles say which, and an administrator without one is refused even with
     * a write token.
     */
    public function testClassRolesRestrictHighRiskTools(): void
    {
        $policies = new ToolPolicyProvider();
        $classRoles = ['high_risk' => ['ROLE_MCP_CATALOGUE']];

        $editor = self::decider(['ROLE_ADMINISTRATION_ACCESS', 'ROLE_MCP_CATALOGUE'], $classRoles);
        $clerk = self::decider(['ROLE_ADMINISTRATION_ACCESS'], $classRoles);
        $caller = self::callers(scopes: ['mcp:write'])->resolve();

        self::assertTrue($editor->decide($policies->get('create_product'), $caller)->allowed);
        self::assertFalse($clerk->decide($policies->get('create_product'), $caller)->allowed);
        // Read and cart tools keep their default role.
        self::assertTrue($clerk->decide($policies->get('search_products'), $caller)->allowed);
        self::assertTrue($clerk->decide($policies->get('create_order'), $caller)->allowed);
    }

    public function testToolRolesReplaceTheClassRoles(): void
    {
        $decider = self::decider(['ROLE_ADMINISTRATION_ACCESS'], [], ['fetch_order' => ['roles' => ['ROLE_ORDERS']]]);
        $caller = self::callers()->resolve();

        self::assertFalse($decider->decide((new ToolPolicyProvider())->get('fetch_order'), $caller)->allowed);
        self::assertTrue($decider->decide((new ToolPolicyProvider())->get('fetch_product'), $caller)->allowed);
    }

    /** Leaving roles out never means "no role needed". */
    public function testAnEmptyRoleListFallsBackInsteadOfOpeningTheTool(): void
    {
        $decider = self::decider([], ['read' => []], ['search_products' => ['roles' => []]]);

        self::assertSame(['ROLE_ADMINISTRATION_ACCESS'], $decider->rolesFor('search_products', (new ToolPolicyProvider())->get('search_products')->class));
        self::assertFalse($decider->decide((new ToolPolicyProvider())->get('search_products'), self::callers()->resolve())->allowed);
    }

    /** The stdio transport runs in the operator's shell; there is no token to check. */
    public function testTheConsoleOperatorIsAllowed(): void
    {
        $caller = (new McpCallerResolver(null, null, consoleIsOperator: true))->resolve();

        self::assertTrue($caller->console);
        self::assertTrue(self::decider([])->decide((new ToolPolicyProvider())->get('create_product'), $caller)->allowed);
    }

    /** Outside the console, a missing request is not a reason to trust anyone. */
    public function testNoRequestOutsideTheConsoleIsAnonymous(): void
    {
        $caller = (new McpCallerResolver(null, null, consoleIsOperator: false))->resolve();

        self::assertFalse($caller->isAuthenticated());
    }

    public function testTheCallerCarriesTheTokenFacts(): void
    {
        $caller = self::callers(scopes: ['mcp:read'])->resolve();

        self::assertEquals(
            new McpCaller('admin@example.com', ['mcp:read'], 42, 'mcpc_test', '203.0.113.7'),
            $caller,
        );
    }
}
