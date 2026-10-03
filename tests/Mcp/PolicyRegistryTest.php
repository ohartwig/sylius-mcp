<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Mcp;

use Koh\SyliusMcp\Mcp\KohCapabilityLoader;
use Koh\SyliusMcp\Mcp\PolicyRegistry;
use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Koh\SyliusMcp\Tool\FetchProductTool;
use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Tool;
use Mcp\Server;
use PHPUnit\Framework\TestCase;
use Sylius\McpServerPlugin\Mcp\Loader\PluginDiscoveryLoader;
use Sylius\McpServerPlugin\Tool\Product\Fetch as PluginFetchProduct;
use Psr\Log\NullLogger;

final class PolicyRegistryTest extends TestCase
{
    public function testASwitchedOffToolIsNeverRegistered(): void
    {
        $registry = $this->registry(['search_products' => ['enabled' => false]]);
        $registry->registerTool($this->tool('search_products'), 'Vendor\\Search', true);

        self::assertFalse($registry->hasTools());
        $this->expectException(ToolNotFoundException::class);
        $registry->getTool('search_products');
    }

    public function testRegisteredToolsCarryThePolicyAnnotations(): void
    {
        $registry = $this->registry();
        $registry->registerTool($this->tool('complete_checkout'), 'Vendor\\Complete', true);

        $annotations = $registry->getTool('complete_checkout')->tool->annotations;
        self::assertNotNull($annotations);
        self::assertFalse($annotations->readOnlyHint);
        self::assertTrue($annotations->destructiveHint);
    }

    /**
     * Two tools called fetch_product used to overwrite each other in whatever
     * order the loaders ran. Ours wins either way.
     */
    public function testOurToolWinsANameConflictRegisteredAfterIt(): void
    {
        $registry = $this->registry();
        $registry->registerTool($this->tool('fetch_product'), FetchProductTool::class, true);
        $registry->registerTool($this->tool('fetch_product'), PluginFetchProduct::class, true);

        self::assertSame(FetchProductTool::class, $registry->getTool('fetch_product')->handler);
    }

    public function testOurToolWinsANameConflictRegisteredBeforeIt(): void
    {
        $registry = $this->registry();
        $registry->registerTool($this->tool('fetch_product'), PluginFetchProduct::class, true);
        $registry->registerTool($this->tool('fetch_product'), [FetchProductTool::class, '__invoke'], false);

        self::assertSame([FetchProductTool::class, '__invoke'], $registry->getTool('fetch_product')->handler);
    }

    /**
     * The real loaders, in both orders, through a real server build: the
     * registry the server answers from holds our fetch_product.
     */
    public function testTheBuiltServerServesOurFetchProductWhateverTheLoaderOrder(): void
    {
        foreach ([[true, false], [false, true]] as [$pluginFirst]) {
            $registry = $this->registry();
            $loaders = [new PluginDiscoveryLoader(new NullLogger()), new KohCapabilityLoader()];
            Server::builder()
                ->setServerInfo('test', '1.0.0')
                ->setRegistry($registry)
                ->addLoaders($pluginFirst ? $loaders : array_reverse($loaders))
                ->build();

            $handler = $registry->getTool('fetch_product')->handler;
            self::assertSame(FetchProductTool::class, \is_array($handler) ? $handler[0] : $handler);
            // The plugin's other tools are still there.
            self::assertSame('fetch_product_variant', $registry->getTool('fetch_product_variant')->tool->name);
            self::assertSame('content_product_search', $registry->getTool('content_product_search')->tool->name);
        }
    }

    /**
     * The application's own tools arrive as a discovery state, not through
     * registerTool(). They get the same policy: switch, annotations, and no
     * overwriting of a tool already held.
     */
    public function testDiscoveredToolsPassThePolicyToo(): void
    {
        $registry = $this->registry(['list_articles' => ['enabled' => false]]);
        $registry->registerTool($this->tool('fetch_product'), FetchProductTool::class, true);

        $registry->setDiscoveryState(new DiscoveryState([
            'update_product' => new ToolReference($this->tool('update_product'), ['App\\Mcp\\Tool\\UpdateProductTool', '__invoke']),
            'list_articles' => new ToolReference($this->tool('list_articles'), ['App\\Mcp\\Tool\\ListArticlesTool', '__invoke']),
            'fetch_product' => new ToolReference($this->tool('fetch_product'), ['App\\Mcp\\Tool\\FetchProduct', '__invoke']),
        ]));

        $annotations = $registry->getTool('update_product')->tool->annotations;
        self::assertNotNull($annotations);
        self::assertTrue($annotations->destructiveHint);
        self::assertSame(FetchProductTool::class, $registry->getTool('fetch_product')->handler, 'a discovered tool does not overwrite one already held');

        $this->expectException(ToolNotFoundException::class);
        $registry->getTool('list_articles');
    }

    /** @param array<string, array<string, mixed>> $config */
    private function registry(array $config = []): PolicyRegistry
    {
        /** @var array<string, array{enabled?: bool, class?: string|null, idempotent?: bool|null, destructive?: bool|null, untrusted?: bool|null}> $config */
        return new PolicyRegistry(new Registry(), new ToolPolicyProvider($config));
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, ['type' => 'object', 'properties' => []], 'A tool.', null);
    }
}
