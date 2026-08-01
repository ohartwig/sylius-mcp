<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * MCP tools for Sylius, plus the SEP-1649 server card.
 */
final class KohSyliusMcpBundle extends AbstractBundle
{
    protected string $extensionAlias = 'koh_sylius_mcp';

    /** config/ sits at the package root, not under src/. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('version')->defaultValue('1.0.0')->end()
                ->scalarNode('transport_path')
                    ->defaultValue('/_mcp')
                    ->info('Where the MCP server listens. Must match the route the plugin registers.')
                ->end()
            ->end();
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('koh_sylius_mcp.version', (string) ($config['version'] ?? '1.0.0'));
        $builder->setParameter('koh_sylius_mcp.transport_path', (string) ($config['transport_path'] ?? '/_mcp'));

        $container->import(\dirname(__DIR__).'/config/services.php');
    }
}
