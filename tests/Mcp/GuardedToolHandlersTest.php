<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Mcp;

use Koh\SyliusMcp\Mcp\FilteredListToolsHandler;
use Koh\SyliusMcp\Mcp\GuardedCallToolHandler;
use Koh\SyliusMcp\Mcp\PolicyRegistry;
use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Koh\SyliusMcp\Security\McpCallerResolver;
use Koh\SyliusMcp\Security\ToolAccessDecider;
use Koh\SyliusMcp\Tests\Support\McpHarness;
use Koh\SyliusMcp\Tests\Support\McpSecurityFixtures;
use Koh\SyliusMcp\Tests\Support\RecordingTool;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * The guarded handlers against a real SDK registry and the SDK's own
 * tools/call handler behind them: what a tool call does end to end.
 */
final class GuardedToolHandlersTest extends TestCase
{
    use McpHarness;
    use McpSecurityFixtures;

    /** @var array<string, RecordingTool> */
    private array $tools = [];

    private PolicyRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new PolicyRegistry(new Registry(), new ToolPolicyProvider());

        foreach (['search_products', 'add_item_to_order', 'update_product'] as $name) {
            $this->tools[$name] = new RecordingTool();
            $this->registry->registerTool(
                new Tool($name, ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]], $name, null),
                [$this->tools[$name], '__invoke'],
                true,
            );
        }
    }

    public function testAnAllowedCallReachesTheTool(): void
    {
        $result = self::callTool($this->call(self::callers()), 'update_product', ['code' => 'MUG']);

        self::assertFalse($result->isError);
        self::assertSame([['code' => 'MUG', 'description' => '']], $this->tools['update_product']->calls);
    }

    /**
     * Refused with a reason the agent can read, and the tool never ran.
     */
    public function testARefusedCallNeverReachesTheTool(): void
    {
        $result = self::callTool($this->call(self::callers(scopes: ['mcp:read'])), 'update_product', ['code' => 'MUG']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('mcp:write', self::text($result));
        self::assertSame([], $this->tools['update_product']->calls);
    }

    public function testAnAdministratorWithoutTheRoleIsRefused(): void
    {
        $handler = $this->call(self::callers(), self::decider(['ROLE_ADMINISTRATION_ACCESS'], ['high_risk' => ['ROLE_MCP_CATALOGUE']]));

        self::assertTrue(self::callTool($handler, 'update_product', ['code' => 'MUG'])->isError);
        self::assertFalse(self::callTool($handler, 'search_products')->isError);
    }

    public function testAnUnknownToolGetsTheSdksAnswer(): void
    {
        $handler = $this->call(self::callers());
        $request = CallToolRequest::fromArray(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'nope', 'arguments' => new \stdClass()]]);

        self::assertInstanceOf(Error::class, $handler->handle($request, new Session(new InMemorySessionStore())));
    }

    /** tools/list does not offer what tools/call would refuse. */
    public function testTheListShowsOnlyCallableTools(): void
    {
        self::assertSame(['search_products'], self::listToolNames($this->list(self::callers(scopes: ['mcp:read']))));
        self::assertSame(
            ['search_products', 'add_item_to_order', 'update_product'],
            self::listToolNames($this->list(self::callers(scopes: ['mcp:write']))),
        );
        self::assertSame([], self::listToolNames($this->list(self::callers(user: null))));
    }

    private function call(McpCallerResolver $callers, ?ToolAccessDecider $decider = null): GuardedCallToolHandler
    {
        return new GuardedCallToolHandler(
            $this->registry,
            new CallToolHandler($this->registry, new ReferenceHandler()),
            new ToolPolicyProvider(),
            $decider ?? self::decider(),
            $callers,
        );
    }

    private function list(McpCallerResolver $callers): FilteredListToolsHandler
    {
        return new FilteredListToolsHandler($this->registry, new ToolPolicyProvider(), self::decider(), $callers);
    }
}
