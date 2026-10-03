<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp;

use Koh\SyliusMcp\DependencyInjection\McpReferenceContainerPass;
use Koh\SyliusMcp\Mcp\ServerInstructions;
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
                ->arrayNode('writes')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('live')
                            ->defaultFalse()
                            ->info('false: write tools with a draft rule keep their changes off the storefront (products disabled, articles unpublished, live products refused). A person publishes in the admin.')
                        ->end()
                        ->booleanNode('confirm_high_risk')
                            ->defaultTrue()
                            ->info('High-risk tools need a preview and then the confirm token from it. A tool can override this with tools.<name>.confirm.')
                        ->end()
                        ->integerNode('confirmation_ttl')
                            ->min(30)
                            ->max(3600)
                            ->defaultValue(300)
                            ->info('Seconds a confirm token stays valid.')
                        ->end()
                        ->arrayNode('drafts')
                            ->info('Draft rules per tool, merged over the built-in ones (create_product, import_product, update_product, create_article, update_article).')
                            ->useAttributeAsKey('tool')
                            ->arrayPrototype()
                                ->children()
                                    ->arrayNode('force')->info('Arguments set to these values.')->useAttributeAsKey('name')->variablePrototype()->end()->end()
                                    ->arrayNode('refuse')->info('Calls with these argument values are refused.')->useAttributeAsKey('name')->variablePrototype()->end()->end()
                                    ->scalarNode('disable_created_product')->info('Argument naming the product code to disable after status "created".')->end()
                                    ->scalarNode('refuse_live_product')->info('Argument naming a product code; the call is refused while that product is enabled.')->end()
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('audit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('argument_max_length')
                            ->min(16)
                            ->defaultValue(200)
                            ->info('Strings in logged tool arguments are cut to this many characters.')
                        ->end()
                    ->end()
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
                            ->booleanNode('confirm')->info('A call needs a preview and then the confirm token from it. Default: true for high_risk tools.')->end()
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

    /**
     * Server instructions, unless the application sets mcp.instructions
     * itself: prepended configuration is overridden by the application's.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('mcp')) {
            $builder->prependExtensionConfig('mcp', ['instructions' => ServerInstructions::TEXT]);
        }
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
        $builder->setParameter('koh_sylius_mcp.writes.live', (bool) ($config['writes']['live'] ?? false));
        $builder->setParameter('koh_sylius_mcp.writes.confirm_high_risk', (bool) ($config['writes']['confirm_high_risk'] ?? true));
        $builder->setParameter('koh_sylius_mcp.writes.confirmation_ttl', (int) ($config['writes']['confirmation_ttl'] ?? 300));
        $builder->setParameter('koh_sylius_mcp.writes.drafts', $config['writes']['drafts'] ?? []);
        $builder->setParameter('koh_sylius_mcp.audit.argument_max_length', (int) ($config['audit']['argument_max_length'] ?? 200));

        $container->import(\dirname(__DIR__).'/config/services.php');
    }
}
