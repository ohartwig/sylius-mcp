<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Integration;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusMcp\DependencyInjection\McpServerIds;
use Koh\SyliusMcp\KohSyliusMcpBundle;
use Koh\SyliusMcp\Mcp\FilteredListToolsHandler;
use Koh\SyliusMcp\Mcp\GuardedCallToolHandler;
use Koh\SyliusMcp\Mcp\NegotiatingInitializeHandler;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
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
        $container->get(McpServerIds::SERVER);

        /** @var RegistryInterface $registry */
        $registry = $container->get(McpServerIds::REGISTRY);
        $names = array_keys($registry->getTools()->references);

        self::assertContains('content_product_search', $names);
        self::assertContains('fetch_product', $names);
        self::assertContains('search_products', $names, 'the plugin\'s tools are served too');
        self::assertNotContains('complete_checkout', $names, 'switched off in the test configuration');

        $handler = $registry->getTool('fetch_product')->handler;
        self::assertSame('Koh\\SyliusMcp\\Tool\\FetchProductTool', \is_array($handler) ? $handler[0] : $handler);
    }

    public function testTheServerServesOurResourcesAndPrompts(): void
    {
        $container = $this->container();
        $container->get(McpServerIds::SERVER);

        /** @var RegistryInterface $registry */
        $registry = $container->get(McpServerIds::REGISTRY);
        $resources = array_keys($registry->getResources()->references);
        sort($resources);
        $prompts = array_keys($registry->getPrompts()->references);
        sort($prompts);

        self::assertSame(['sylius://channels', 'sylius://product-attributes', 'sylius://product-options', 'sylius://taxons'], $resources);
        self::assertSame(['catalogue_import', 'product_copy_review'], $prompts);
    }

    /**
     * The plugin moves its server to /mcp/shop; the endpoint stays at
     * transport_path, where the origin check, the server card and the
     * firewall rules expect it.
     */
    public function testTheEndpointStaysAtTheTransportPath(): void
    {
        self::assertSame(['/_mcp'], $this->routePaths($this->container()));
        self::assertSame(['/agents/mcp'], $this->routePaths($this->container(transportPath: '/agents/mcp')));
    }

    public function testToolsListGoesThroughThePermissionFilter(): void
    {
        $container = $this->container(['mcp:read']);
        $container->get(McpServerIds::SERVER);

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
        $container->get(McpServerIds::SERVER);

        /** @var GuardedCallToolHandler $call */
        $call = $container->get(GuardedCallToolHandler::class);
        $result = self::callTool($call, 'fetch_channel', ['code' => 'WEB']);

        self::assertFalse($result->isError, self::text($result));
        self::assertStringContainsString("\n{\"code\":\"WEB\"}\n", self::text($result));
        self::assertStringStartsWith('[BEGIN UNTRUSTED SHOP DATA', self::text($result), 'plugin output is marked as well');
    }

    /**
     * initialize is answered by our handler: the client's version echoed,
     * the server instructions sent, resources and prompts announced.
     */
    public function testInitializeNegotiatesAndSendsInstructions(): void
    {
        $container = $this->container();
        $container->get(McpServerIds::SERVER);

        /** @var NegotiatingInitializeHandler $initialize */
        $initialize = $container->get(NegotiatingInitializeHandler::class);
        $request = InitializeRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'c', 'version' => '1'],
        ]]);
        $response = $initialize->handle($request, new Session(new InMemorySessionStore()));
        /** @var array<string, mixed> $result */
        $result = json_decode((string) json_encode($response->result), true);

        self::assertSame('2025-11-25', $result['protocolVersion']);
        self::assertSame(['name' => 'test', 'version' => '1'], $result['serverInfo'], 'the server info configured for the server');
        self::assertStringContainsString('UNTRUSTED SHOP DATA', (string) $result['instructions']);
        self::assertArrayHasKey('resources', $result['capabilities']);
        self::assertArrayHasKey('prompts', $result['capabilities']);
    }

    /** @return list<string> */
    private function routePaths(ContainerBuilder $container): array
    {
        /** @var list<array{path: string}> $routes */
        $routes = $container->getDefinition('mcp.server.route_loader')->getArgument(0);

        return array_column($routes, 'path');
    }

    /** @param list<string> $scopes */
    private function container(array $scopes = ['mcp'], ?string $transportPath = null): ContainerBuilder
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
        // What the plugin's config/config.yaml sets for its server.
        $container->loadFromExtension('mcp', ['servers' => [McpServerIds::NAME => [
            'name' => 'test',
            'version' => '1',
            'transports' => ['http' => true, 'stdio' => false],
            'http' => ['path' => '/mcp/shop'],
            'registry' => ['tools' => ['Sylius\\McpServerPlugin\\Tool\\']],
        ]]]);
        $container->loadFromExtension('koh_sylius_mcp', ['tools' => ['complete_checkout' => ['enabled' => false]]]);
        if (null !== $transportPath) {
            $container->loadFromExtension('koh_sylius_mcp', ['transport_path' => $transportPath]);
        }
        (new SyliusMcpServerExtension())->load([], $container);

        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ([McpServerIds::SERVER, McpServerIds::REGISTRY, FilteredListToolsHandler::class, GuardedCallToolHandler::class, NegotiatingInitializeHandler::class, 'mcp.server.route_loader'] as $id) {
                    $container->findDefinition($id)->setPublic(true);
                }
                $container->getAlias(McpServerIds::REGISTRY)->setPublic(true);
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
            'sylius.repository.product_attribute' => $this->createStub(ObjectRepository::class),
            'sylius.repository.product_option' => $this->createStub(ObjectRepository::class),
            'sylius.context.channel' => $this->createStub(ChannelContextInterface::class),
            AgentContextFactoryInterface::class => $this->createStub(AgentContextFactoryInterface::class),
            AgentJsonResponder::class => new AgentJsonResponder(),
            'sylius_mcp_server.http_client.api_shop.base' => $http,
            'sylius_mcp_server.http_client.api_shop_merge_patch.base' => $http,
        ];
    }
}
