<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp;

use Koh\SyliusMcp\DependencyInjection\McpReferenceContainerPass;
use Koh\SyliusMcp\Policy\ToolClass;
use Koh\SyliusMcp\Security\ToolAccessDecider;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
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
                ->enumNode('default_tool_class')
                    ->values(array_map(static fn (ToolClass $class): string => $class->value, ToolClass::cases()))
                    ->defaultValue(ToolClass::HighRisk->value)
                    ->info('Risk class of a tool that is neither built in nor listed under tools.')
                ->end()
                ->arrayNode('class_roles')
                    ->info('Roles of which the administrator needs one, per tool class. The scope check (mcp:read / mcp:write) applies on top.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('read')->requiresAtLeastOneElement()->scalarPrototype()->cannotBeEmpty()->end()->defaultValue([ToolAccessDecider::DEFAULT_ROLE])->end()
                        ->arrayNode('write')->requiresAtLeastOneElement()->scalarPrototype()->cannotBeEmpty()->end()->defaultValue([ToolAccessDecider::DEFAULT_ROLE])->end()
                        ->arrayNode('high_risk')->requiresAtLeastOneElement()->scalarPrototype()->cannotBeEmpty()->end()->defaultValue([ToolAccessDecider::DEFAULT_ROLE])->end()
                    ->end()
                ->end()
                ->arrayNode('tools')
                    ->info('Per-tool policy, keyed by tool name. Applies to every tool the server registers: ours, sylius/mcp-server-plugin\'s and the application\'s.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->booleanNode('enabled')->info('false removes the tool from the server entirely.')->end()
                            ->enumNode('class')
                                ->values(array_map(static fn (ToolClass $class): string => $class->value, ToolClass::cases()))
                                ->info('read, write (state customers do not see yet) or high_risk.')
                            ->end()
                            ->booleanNode('idempotent')->info('idempotentHint for write tools.')->end()
                            ->booleanNode('destructive')->info('destructiveHint for write tools.')->end()
                            ->booleanNode('untrusted')->info('The result carries text from editors, importers or customers.')->end()
                            ->arrayNode('roles')
                                ->info('Roles of which the administrator needs one to call this tool. Replaces the class roles.')
                                ->scalarPrototype()->cannotBeEmpty()->end()
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
            ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // After the mcp-bundle's McpPass (priority 0), which creates the
        // service locator this pass hands on.
        $container->addCompilerPass(new McpReferenceContainerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('koh_sylius_mcp.version', (string) ($config['version'] ?? '1.0.0'));
        $builder->setParameter('koh_sylius_mcp.transport_path', (string) ($config['transport_path'] ?? '/_mcp'));
        $builder->setParameter('koh_sylius_mcp.default_tool_class', (string) ($config['default_tool_class'] ?? ToolClass::HighRisk->value));
        $builder->setParameter('koh_sylius_mcp.tools', $config['tools'] ?? []);
        $builder->setParameter('koh_sylius_mcp.class_roles', $config['class_roles'] ?? []);

        $container->import(\dirname(__DIR__).'/config/services.php');
    }
}
