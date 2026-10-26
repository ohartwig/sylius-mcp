<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Mcp;

use Doctrine\Persistence\ObjectRepository;
use Koh\SyliusMcp\Mcp\GuardedCallToolHandler;
use Koh\SyliusMcp\Mcp\PolicyRegistry;
use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Koh\SyliusMcp\Tests\Support\McpHarness;
use Koh\SyliusMcp\Tests\Support\McpSecurityFixtures;
use Koh\SyliusMcp\Tests\Support\RecordingTool;
use Koh\SyliusMcp\Write\ConfirmationTokens;
use Koh\SyliusMcp\Write\DraftPolicy;
use Koh\SyliusMcp\Write\Preview\ProductWritePreview;
use Koh\SyliusMcp\Write\WriteGate;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\CallToolHandler;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Product;

/**
 * A high-risk write end to end: preview, confirm, execute — and the ways a
 * confirmation does not count.
 */
final class WriteFlowTest extends TestCase
{
    use McpHarness;
    use McpSecurityFixtures;

    /** @var array<string, RecordingTool> */
    private array $tools = [];

    private PolicyRegistry $registry;

    private Product $product;

    protected function setUp(): void
    {
        $this->registry = new PolicyRegistry(new Registry(), new ToolPolicyProvider());
        $this->product = new Product();
        $this->product->setCode('MUG');
        $this->product->setCurrentLocale('de_DE');
        $this->product->setFallbackLocale('de_DE');
        $this->product->setDescription('Old text');
        $this->product->setEnabled(false);

        foreach (['update_product' => ['code' => 'MUG', 'status' => 'updated'], 'create_product' => ['code' => 'NEW', 'status' => 'created'], 'add_item_to_order' => ['ok' => true]] as $name => $result) {
            $this->tools[$name] = new RecordingTool($result);
            $this->registry->registerTool(
                new Tool($name, null, ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'description' => ['type' => 'string']]], $name, null),
                [$this->tools[$name], '__invoke'],
            );
        }
    }

    public function testHighRiskToolsAdvertiseTheControls(): void
    {
        $tool = $this->registry->getTool('update_product')->tool;

        self::assertArrayHasKey('confirm', $tool->inputSchema['properties']);
        self::assertArrayHasKey('dryRun', $tool->inputSchema['properties']);
        self::assertStringContainsString('Needs confirmation', (string) $tool->description);

        $cart = $this->registry->getTool('add_item_to_order')->tool;
        self::assertArrayHasKey('dryRun', $cart->inputSchema['properties']);
        self::assertArrayNotHasKey('confirm', $cart->inputSchema['properties']);
    }

    public function testAHighRiskCallWithoutConfirmIsAPreview(): void
    {
        $result = self::callTool($this->handler(), 'update_product', ['code' => 'MUG', 'description' => 'New text']);

        self::assertFalse($result->isError);
        self::assertSame([], $this->tools['update_product']->calls, 'nothing was executed');
        $preview = $result->structuredContent['data'] ?? [];
        self::assertTrue($preview['dry_run']);
        self::assertSame(['from' => 'Old text', 'to' => 'New text'], $preview['preview']['changes']['description']);
        self::assertIsString($preview['confirm']);
    }

    public function testThePreviewedCallRunsWithItsConfirmToken(): void
    {
        $handler = $this->handler();
        $confirm = self::callTool($handler, 'update_product', ['code' => 'MUG', 'description' => 'New text'])->structuredContent['data']['confirm'] ?? null;
        self::assertIsString($confirm);

        $result = self::callTool($handler, 'update_product', ['code' => 'MUG', 'description' => 'New text', 'confirm' => $confirm]);

        self::assertFalse($result->isError, self::text($result));
        self::assertSame([['code' => 'MUG', 'description' => 'New text']], $this->tools['update_product']->calls, 'confirm never reaches the tool');
    }

    /** The token confirms what was previewed, not whatever comes next. */
    public function testAConfirmTokenForOtherArgumentsIsRefused(): void
    {
        $handler = $this->handler();
        $confirm = self::callTool($handler, 'update_product', ['code' => 'MUG', 'description' => 'Harmless'])->structuredContent['data']['confirm'] ?? null;

        $result = self::callTool($handler, 'update_product', ['code' => 'MUG', 'description' => 'Something else', 'confirm' => $confirm]);

        self::assertTrue($result->isError);
        self::assertSame([], $this->tools['update_product']->calls);
    }

    public function testDryRunPreviewsALowRiskWriteWithoutAToken(): void
    {
        $result = self::callTool($this->handler(), 'add_item_to_order', ['code' => 'MUG', 'dryRun' => true]);

        self::assertSame([], $this->tools['add_item_to_order']->calls);
        self::assertArrayNotHasKey('confirm', $result->structuredContent['data'] ?? []);
    }

    public function testALowRiskWriteRunsWithoutConfirmation(): void
    {
        self::callTool($this->handler(), 'add_item_to_order', ['code' => 'MUG']);

        self::assertCount(1, $this->tools['add_item_to_order']->calls);
    }

    /**
     * A live product is previewed, marked LIVE, and changed only with the
     * confirm token — not refused.
     */
    public function testALiveProductIsPreviewedAsLiveAndConfirmable(): void
    {
        $this->product->setEnabled(true);
        $handler = $this->handler();

        $preview = self::callTool($handler, 'update_product', ['code' => 'MUG', 'description' => 'New text'])->structuredContent['data'] ?? [];
        self::assertTrue($preview['preview']['live'] ?? null);
        self::assertStringContainsString('LIVE', (string) ($preview['preview']['summary'] ?? ''));
        self::assertSame([], $this->tools['update_product']->calls);

        $result = self::callTool($handler, 'update_product', ['code' => 'MUG', 'description' => 'New text', 'confirm' => $preview['confirm']]);

        self::assertFalse($result->isError, self::text($result));
        self::assertCount(1, $this->tools['update_product']->calls);
    }

    /** With confirmation switched off for a tool, it runs at once — the draft rules still apply. */
    public function testConfirmationCanBeSwitchedOffPerTool(): void
    {
        $registry = new PolicyRegistry(new Registry(), $policies = new ToolPolicyProvider(['update_product' => ['confirm' => false]]));
        $registry->registerTool(new Tool('update_product', null, ['type' => 'object', 'properties' => []], 'u', null), [$this->tools['update_product'], '__invoke']);

        self::callTool($this->handler($registry, $policies), 'update_product', ['code' => 'MUG', 'description' => 'x']);

        self::assertCount(1, $this->tools['update_product']->calls);
    }

    /** A failing database read in the preview does not leak its message to the agent. */
    public function testAFailingPreviewIsAnOpaqueInternalError(): void
    {
        $products = $this->createStub(ObjectRepository::class);
        $products->method('findOneBy')->willThrowException(new \RuntimeException('SQLSTATE[HY000] secret-host:3306'));
        $handler = new GuardedCallToolHandler(
            $this->registry,
            new CallToolHandler($this->registry, new ReferenceHandler()),
            new ToolPolicyProvider(),
            self::decider(),
            self::callers(),
            writes: new WriteGate(new DraftPolicy(false, [], $products), new ConfirmationTokens('secret'), [new ProductWritePreview($products, $products, $products)]),
        );
        $request = \Mcp\Schema\Request\CallToolRequest::fromArray(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'update_product', 'arguments' => ['code' => 'MUG']]]);

        $response = $handler->handle($request, new \Mcp\Server\Session\Session(new \Mcp\Server\Session\InMemorySessionStore()));

        self::assertInstanceOf(\Mcp\Schema\JsonRpc\Error::class, $response);
        self::assertStringNotContainsString('secret-host', $response->message);
    }

    private function handler(?PolicyRegistry $registry = null, ?ToolPolicyProvider $policies = null): GuardedCallToolHandler
    {
        $registry ??= $this->registry;
        $products = $this->createStub(ObjectRepository::class);
        $products->method('findOneBy')->willReturnCallback(fn (array $c): ?Product => 'MUG' === ($c['code'] ?? null) ? $this->product : null);

        return new GuardedCallToolHandler(
            $registry,
            new CallToolHandler($registry, new ReferenceHandler()),
            $policies ?? new ToolPolicyProvider(),
            self::decider(),
            self::callers(),
            writes: new WriteGate(
                new DraftPolicy(false, [], $products),
                new ConfirmationTokens('secret'),
                [new ProductWritePreview($products, $this->createStub(ObjectRepository::class), $this->createStub(ObjectRepository::class))],
            ),
        );
    }
}
