<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusMcp\Agent\McpEndpointProvider;
use Koh\SyliusMcp\Controller\McpServerCardController;
use Koh\SyliusMcp\Mcp\KohCapabilityLoader;
use Koh\SyliusMcp\Mcp\McpCatalogue;
use Koh\SyliusMcp\Mcp\PolicyRegistry;
use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Koh\SyliusMcp\Tool\ContentAwareProductSearchTool;
use Koh\SyliusMcp\Tool\FetchProductTool;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Tagged explicitly and with autoconfiguration off: the mcp-bundle's
    // autoconfiguration would tag them as well, and a service tagged twice is
    // yielded twice by a tagged iterator.
    foreach ([ContentAwareProductSearchTool::class, FetchProductTool::class] as $tool) {
        $services->set($tool)
            ->autoconfigure(false)
            ->arg('$productRepository', service('sylius.repository.product'))
            ->tag('mcp.tool');
    }

    $services->set(KohCapabilityLoader::class)
        ->autoconfigure(false)
        ->args([service('logger')->nullOnInvalid()])
        ->tag('mcp.loader')
        ->tag('monolog.logger', ['channel' => 'mcp']);

    $services->set(ToolPolicyProvider::class)
        ->args([
            param('koh_sylius_mcp.tools'),
            param('koh_sylius_mcp.default_tool_class'),
        ]);

    $services->set(PolicyRegistry::class)
        ->decorate('mcp.registry', invalidBehavior: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([
            service('.inner'),
            service(ToolPolicyProvider::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'mcp']);

    $services->set(McpCatalogue::class)
        ->args([
            service('mcp.registry'),
            service_closure('mcp.server')->nullOnInvalid(),
        ]);

    $services->set(McpServerCardController::class)
        ->args([
            service(McpCatalogue::class),
            service(AgentContextFactoryInterface::class),
            service(AgentJsonResponder::class),
            param('koh_sylius_mcp.version'),
            param('koh_sylius_mcp.transport_path'),
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->set(McpEndpointProvider::class)
        ->args([param('koh_sylius_mcp.transport_path')])
        ->tag('koh_agent.endpoint')
        ->tag('koh_agent.api_catalog_entry');
};
