<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests;

use Koh\SyliusMcp\Agent\McpEndpointProvider;
use Koh\SyliusMcp\Controller\McpServerCardController;
use Koh\SyliusMcp\KohSyliusMcpBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class KohSyliusMcpBundleTest extends TestCase
{
    /**
     * config/ sits at the package root, not under src/ -- getPath() has to
     * climb one directory further than the AbstractBundle default assumes.
     */
    public function testGetPathPointsAtThePackageRootNotSrc(): void
    {
        $path = (new KohSyliusMcpBundle())->getPath();

        self::assertSame(\dirname(__DIR__), $path);
        self::assertDirectoryExists($path.'/config');
        self::assertFileExists($path.'/config/services.php');
    }

    public function testLoadExtensionFallsBackToDefaultsWhenUnconfigured(): void
    {
        $container = $this->load([]);

        self::assertSame('1.0.0', $container->getParameter('koh_sylius_mcp.version'));
        self::assertSame('/_mcp', $container->getParameter('koh_sylius_mcp.transport_path'));
    }

    public function testLoadExtensionHonoursExplicitConfig(): void
    {
        $container = $this->load(['version' => '2.4.0', 'transport_path' => '/custom-mcp']);

        self::assertSame('2.4.0', $container->getParameter('koh_sylius_mcp.version'));
        self::assertSame('/custom-mcp', $container->getParameter('koh_sylius_mcp.transport_path'));
    }

    /**
     * loadExtension() imports config/services.php, which is where the
     * controller and the endpoint provider actually get wired up -- a bundle
     * that only sets parameters but never registers the services would leave
     * the MCP server card and llms.txt announcement dead in the container.
     */
    public function testLoadExtensionRegistersTheBundleServices(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->hasDefinition(McpServerCardController::class));
        self::assertTrue($container->hasDefinition(McpEndpointProvider::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = (new KohSyliusMcpBundle())->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([$config], $container);

        return $container;
    }
}
