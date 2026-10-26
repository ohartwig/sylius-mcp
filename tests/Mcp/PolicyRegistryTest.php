<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Mcp;

use Koh\SyliusMcp\Mcp\PolicyRegistry;
use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Koh\SyliusMcp\Tool\ContentAwareProductSearchTool;
use Koh\SyliusMcp\Tool\FetchProductTool;
use Mcp\Capability\Registry;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Tool;
use Mcp\Server;
use PHPUnit\Framework\TestCase;
use Sylius\McpServerPlugin\Tool\Product\Fetch as PluginFetchProduct;
use Sylius\McpServerPlugin\Tool\ProductVariant\Fetch as PluginFetchProductVariant;

final class PolicyRegistryTest extends TestCase
{
    public function testASwitchedOffToolIsNeverRegistered(): void
    {
        $registry = $this->registry(['search_products' => ['enabled' => false]]);
        $registry->registerTool($this->tool('search_products'), 'Vendor\\Search');

        self::assertFalse($registry->hasTools());
        $this->expectException(ToolNotFoundException::class);
        $registry->getTool('search_products');
    }

    public function testRegisteredToolsCarryThePolicyAnnotations(): void
    {
        $registry = $this->registry();
        $registry->registerTool($this->tool('update_product'), 'Vendor\\Update');

        $annotations = $registry->getTool('update_product')->tool->annotations;
        self::assertNotNull($annotations);
        self::assertFalse($annotations->readOnlyHint);
        self::assertTrue($annotations->destructiveHint);
        self::assertTrue($annotations->idempotentHint);
    }

    /**
     * Two tools called fetch_product used to overwrite each other in whatever
     * order the loaders ran. Ours wins either way.
     */
    public function testOurToolWinsANameConflictRegisteredAfterIt(): void
    {
        $registry = $this->registry();
        $registry->registerTool($this->tool('fetch_product'), FetchProductTool::class);
        $registry->registerTool($this->tool('fetch_product'), PluginFetchProduct::class);

        self::assertSame(FetchProductTool::class, $registry->getTool('fetch_product')->handler);
    }

    public function testOurToolWinsANameConflictRegisteredBeforeIt(): void
    {
        $registry = $this->registry();
        $registry->registerTool($this->tool('fetch_product'), PluginFetchProduct::class);
        $registry->registerTool($this->tool('fetch_product'), [FetchProductTool::class, '__invoke']);

        self::assertSame([FetchProductTool::class, '__invoke'], $registry->getTool('fetch_product')->handler);
    }

    /**
     * Through a real server build, the way the mcp-bundle registers the
     * plugin's tools and ours (one addTool() per element service), in both
     * orders: the registry the server answers from holds our fetch_product.
     */
    public function testTheBuiltServerServesOurFetchProductWhateverTheRegistrationOrder(): void
    {
        $tools = [
            [[PluginFetchProduct::class, '__invoke'], 'fetch_product'],
            [[PluginFetchProductVariant::class, '__invoke'], 'fetch_product_variant'],
            [[FetchProductTool::class, '__invoke'], 'fetch_product'],
            [[ContentAwareProductSearchTool::class, '__invoke'], 'content_product_search'],
        ];

        foreach ([$tools, array_reverse($tools)] as $order) {
            $registry = $this->registry();
            $builder = Server::builder()->setServerInfo('test', '1.0.0')->setRegistry($registry);
            foreach ($order as [$handler, $name]) {
                $builder->addTool($handler, $name);
            }
            $builder->build();

            $handler = $registry->getTool('fetch_product')->handler;
            self::assertSame(FetchProductTool::class, \is_array($handler) ? $handler[0] : $handler);
            // The plugin's other tools are still there.
            self::assertSame('fetch_product_variant', $registry->getTool('fetch_product_variant')->tool->name);
            self::assertSame('content_product_search', $registry->getTool('content_product_search')->tool->name);
        }
    }

    /**
     * The application's own tools, however they arrive, get the same policy:
     * switch, annotations, and no overwriting of a tool of ours.
     */
    public function testApplicationToolsPassThePolicyToo(): void
    {
        $registry = $this->registry(['list_articles' => ['enabled' => false]]);
        $registry->registerTool($this->tool('fetch_product'), FetchProductTool::class);

        $registry->registerTool($this->tool('update_product'), ['App\\Mcp\\Tool\\UpdateProductTool', '__invoke']);
        $registry->registerTool($this->tool('list_articles'), ['App\\Mcp\\Tool\\ListArticlesTool', '__invoke']);
        $registry->registerTool($this->tool('fetch_product'), ['App\\Mcp\\Tool\\FetchProduct', '__invoke']);

        $annotations = $registry->getTool('update_product')->tool->annotations;
        self::assertNotNull($annotations);
        self::assertTrue($annotations->destructiveHint);
        self::assertSame(FetchProductTool::class, $registry->getTool('fetch_product')->handler, 'an application tool does not overwrite one of ours');

        $this->expectException(ToolNotFoundException::class);
        $registry->getTool('list_articles');
    }

    /**
     * The SDK's DiscoveryLoader tracks what it registered by the identity of
     * the returned reference and later unregisters what it still "owns". A
     * refused registration must therefore not hand back the reference of the
     * tool that holds the name.
     */
    public function testARefusedRegistrationReturnsADetachedReference(): void
    {
        $registry = $this->registry(['list_articles' => ['enabled' => false]]);
        $ours = $registry->registerTool($this->tool('fetch_product'), FetchProductTool::class);

        $refused = $registry->registerTool($this->tool('fetch_product'), ['App\\Mcp\\Tool\\FetchProduct', '__invoke']);
        $switchedOff = $registry->registerTool($this->tool('list_articles'), ['App\\Mcp\\Tool\\ListArticlesTool', '__invoke']);

        self::assertSame($ours, $registry->getTool('fetch_product'));
        self::assertNotSame($ours, $refused);
        self::assertFalse($registry->hasTool('list_articles'));
        self::assertSame('list_articles', $switchedOff->tool->name);
    }

    public function testAnUnregisteredToolNoLongerHoldsItsName(): void
    {
        $registry = $this->registry();
        $registry->registerTool($this->tool('fetch_product'), FetchProductTool::class);
        $registry->unregisterTool('fetch_product');
        $registry->registerTool($this->tool('fetch_product'), PluginFetchProduct::class);

        self::assertSame(PluginFetchProduct::class, $registry->getTool('fetch_product')->handler);
    }

    /** @param array<string, array<string, mixed>> $config */
    private function registry(array $config = []): PolicyRegistry
    {
        /** @var array<string, array{enabled?: bool, class?: string|null, idempotent?: bool|null, destructive?: bool|null, untrusted?: bool|null}> $config */
        return new PolicyRegistry(new Registry(), new ToolPolicyProvider($config));
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, null, ['type' => 'object', 'properties' => []], 'A tool.', null);
    }
}
