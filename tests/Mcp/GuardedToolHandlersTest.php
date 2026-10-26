<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Mcp;

use Koh\SyliusMcp\Audit\ToolCallAuditor;
use Koh\SyliusMcp\Mcp\FilteredListToolsHandler;
use Koh\SyliusMcp\Mcp\GuardedCallToolHandler;
use Koh\SyliusMcp\Mcp\PolicyRegistry;
use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Koh\SyliusMcp\Security\McpCallerResolver;
use Koh\SyliusMcp\Security\ToolAccessDecider;
use Koh\SyliusMcp\Tests\Support\McpHarness;
use Koh\SyliusMcp\Tests\Support\McpSecurityFixtures;
use Koh\SyliusMcp\Tests\Support\MemoryLogger;
use Koh\SyliusMcp\Tests\Support\RecordingTool;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
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
                new Tool($name, null, ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]], $name, null),
                [$this->tools[$name], '__invoke'],
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
     * The call is rebuilt with the arguments the write gate leaves; its _meta
     * must survive, mcp/sdk 0.8 reads the protocol revision and the progress
     * token from it.
     */
    public function testTheSdkHandlerGetsTheRequestMeta(): void
    {
        $inner = new class implements RequestHandlerInterface {
            public ?Request $seen = null;

            public function supports(Request $request): bool
            {
                return true;
            }

            /** @return Response<CallToolResult> */
            public function handle(Request $request, SessionInterface $session): Response
            {
                $this->seen = $request;

                return new Response($request->getId(), new CallToolResult([new TextContent('ok')]));
            }
        };
        $handler = new GuardedCallToolHandler($this->registry, $inner, new ToolPolicyProvider(), self::decider(), self::callers(), auditor: new ToolCallAuditor());

        $request = CallToolRequest::fromArray(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => [
            'name' => 'search_products',
            'arguments' => ['code' => 'MUG'],
            '_meta' => ['progressToken' => 'p-1', 'io.modelcontextprotocol/protocolVersion' => '2026-07-28'],
        ]]);
        $handler->handle($request, new Session(new InMemorySessionStore()));

        self::assertNotNull($inner->seen);
        self::assertSame(7, $inner->seen->getId());
        self::assertSame(['progressToken' => 'p-1', 'io.modelcontextprotocol/protocolVersion' => '2026-07-28'], $inner->seen->getMeta());
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

    /** Shop content reaches the agent fenced as data. */
    public function testResultsOfUntrustedToolsAreMarked(): void
    {
        $result = self::callTool($this->call(self::callers()), 'search_products', ['code' => 'MUG']);

        self::assertStringStartsWith('[BEGIN UNTRUSTED SHOP DATA ', self::text($result));
    }

    public function testATrustedToolIsLeftUnmarked(): void
    {
        $policies = new ToolPolicyProvider(['search_products' => ['untrusted' => false]]);
        $handler = new GuardedCallToolHandler(
            $this->registry,
            new CallToolHandler($this->registry, new ReferenceHandler()),
            $policies,
            self::decider(),
            self::callers(),
        );

        self::assertStringStartsNotWith('[BEGIN UNTRUSTED', self::text(self::callTool($handler, 'search_products')));
    }

    /** A refusal is our own text, not shop data. */
    public function testRefusalsAreNotMarked(): void
    {
        $result = self::callTool($this->call(self::callers(scopes: ['mcp:read'])), 'update_product');

        self::assertStringStartsWith('Access denied', self::text($result));
    }

    /** Every call leaves a record — the refused ones above all. */
    public function testAllowedAndRefusedCallsAreAudited(): void
    {
        $logger = new MemoryLogger();
        $auditor = new ToolCallAuditor($logger);

        self::callTool($this->call(self::callers(), auditor: $auditor), 'update_product', ['code' => 'MUG']);
        self::callTool($this->call(self::callers(scopes: ['mcp:read']), auditor: $auditor), 'update_product', ['code' => 'MUG']);

        self::assertSame(['ok', 'denied'], array_map(static fn (array $r): mixed => $r['context']['outcome'], $logger->records));
        self::assertSame('admin@example.com', $logger->records[1]['context']['user']);
        self::assertSame(42, $logger->records[1]['context']['token_id']);
        self::assertStringContainsString('mcp:write', (string) $logger->records[1]['context']['reason']);
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

    private function call(McpCallerResolver $callers, ?ToolAccessDecider $decider = null, ?ToolCallAuditor $auditor = null): GuardedCallToolHandler
    {
        return new GuardedCallToolHandler(
            $this->registry,
            new CallToolHandler($this->registry, new ReferenceHandler()),
            new ToolPolicyProvider(),
            $decider ?? self::decider(),
            $callers,
            auditor: $auditor ?? new ToolCallAuditor(),
        );
    }

    private function list(McpCallerResolver $callers): FilteredListToolsHandler
    {
        return new FilteredListToolsHandler($this->registry, new ToolPolicyProvider(), self::decider(), $callers);
    }
}
