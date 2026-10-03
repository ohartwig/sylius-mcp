<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Integration;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusMcp\KohSyliusMcpBundle;
use Koh\SyliusMcp\Mcp\FilteredListToolsHandler;
use Koh\SyliusMcp\Mcp\GuardedCallToolHandler;
use Koh\SyliusMcp\Tests\Support\McpHarness;
use Koh\SyliusMcp\Tests\Support\McpSecurityFixtures;
use Mcp\Capability\RegistryInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Product\Repository\ProductRepositoryInterface;
use Sylius\McpServerPlugin\DependencyInjection\SyliusMcpServerExtension;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The bundle inside a container with the real mcp-bundle and the real
 * sylius/mcp-server-plugin services: does the wiring compile, does the server
 * build, and do our handlers answer?
 *
 * Unit tests cannot catch a decorator on the wrong id, a reference handler
 * without the tool service locator, or a loader nobody tagged; this one does.
 */
final class ContainerWiringTest extends TestCase
{
    use McpHarness;
    use McpSecurityFixtures;

    public function testTheServerServesOurToolsAndThePlugins(): void
    {
        $container = $this->container();
        $container->get('mcp.server');

        /** @var RegistryInterface $registry */
        $registry = $container->get('mcp.registry');
        $names = array_keys($registry->getTools()->references);

        self::assertContains('content_product_search', $names);
        self::assertContains('fetch_product', $names);
        self::assertContains('search_products', $names, 'the plugin\'s tools are served too');
        self::assertNotContains('complete_checkout', $names, 'switched off in the test configuration');

        $handler = $registry->getTool('fetch_product')->handler;
        self::assertSame('Koh\\SyliusMcp\\Tool\\FetchProductTool', \is_array($handler) ? $handler[0] : $handler);
    }

    public function testToolsListGoesThroughThePermissionFilter(): void
    {
        $container = $this->container(['mcp:read']);
        $container->get('mcp.server');

        /** @var FilteredListToolsHandler $list */
        $list = $container->get(FilteredListToolsHandler::class);
        $names = self::listToolNames($list);

        self::assertContains('search_products', $names);
        self::assertNotContains('add_item_to_order', $names, 'a read token sees no write tools');
    }

    /**
     * A plugin tool called through the guarded handler is resolved from the
     * service locator — with its HTTP client — not instantiated with `new`.
     */
    public function testAToolCallResolvesTheHandlerFromTheServiceLocator(): void
    {
        $container = $this->container();
        $container->get('mcp.server');

        /** @var GuardedCallToolHandler $call */
        $call = $container->get(GuardedCallToolHandler::class);
        $result = self::callTool($call, 'fetch_channel', ['code' => 'WEB']);

        self::assertFalse($result->isError, self::text($result));
        self::assertStringContainsString("\n{\"code\":\"WEB\"}\n", self::text($result));
        self::assertStringStartsWith('[BEGIN UNTRUSTED SHOP DATA', self::text($result), 'plugin output is marked as well');
    }

    /** @param list<string> $scopes */
    private function container(array $scopes = ['mcp']): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir().'/koh-mcp-test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir().'/koh-mcp-test');
        $container->setParameter('kernel.secret', 'test-secret');

        $synthetic = $this->syntheticServices($scopes);
        foreach (array_keys($synthetic) as $id) {
            $container->register($id)->setSynthetic(true)->setPublic(true);
        }

        foreach ([new McpBundle(), new KohSyliusMcpBundle()] as $bundle) {
            $extension = $bundle->getContainerExtension();
            self::assertNotNull($extension);
            $container->registerExtension($extension);
            $bundle->build($container);
        }
        $container->loadFromExtension('mcp', ['app' => 'test', 'version' => '1', 'client_transports' => ['http' => true, 'stdio' => false], 'discovery' => ['scan_dirs' => []]]);
        $container->loadFromExtension('koh_sylius_mcp', ['tools' => ['complete_checkout' => ['enabled' => false]]]);
        (new SyliusMcpServerExtension())->load([], $container);

        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach (['mcp.server', 'mcp.registry', FilteredListToolsHandler::class, GuardedCallToolHandler::class] as $id) {
                    $container->findDefinition($id)->setPublic(true);
                }
                $container->getAlias('mcp.registry')->setPublic(true);
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
        $container->compile();

        foreach ($synthetic as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
    }

    /**
     * @param list<string> $scopes
     *
     * @return array<string, object>
     */
    private function syntheticServices(array $scopes): array
    {
        [$storage, $requests] = self::securityState(scopes: $scopes);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn('{"code":"WEB"}');
        $http = $this->createStub(HttpClientInterface::class);
        $http->method('request')->willReturn($response);

        return [
            'logger' => new NullLogger(),
            'event_dispatcher' => new EventDispatcher(),
            'request_stack' => $requests,
            'security.token_storage' => $storage,
            'security.authorization_checker' => self::checker(['ROLE_ADMINISTRATION_ACCESS']),
            'sylius.repository.product' => $this->createStub(ProductRepositoryInterface::class),
            'sylius.repository.channel' => $this->createStub(ObjectRepository::class),
            'sylius.repository.taxon' => $this->createStub(ObjectRepository::class),
            'sylius.repository.order' => $this->createStub(ObjectRepository::class),
            'sylius.context.channel' => $this->createStub(ChannelContextInterface::class),
            AgentContextFactoryInterface::class => $this->createStub(AgentContextFactoryInterface::class),
            AgentJsonResponder::class => new AgentJsonResponder(),
            'sylius_mcp_server.http_client.api_shop' => $http,
            'sylius_mcp_server.http_client.api_shop_merge_patch' => $http,
        ];
    }
}
