<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusMcp\Agent\McpEndpointProvider;
use Koh\SyliusMcp\Controller\McpServerCardController;
use Koh\SyliusMcp\DependencyInjection\McpReferenceContainerPass;
use Koh\SyliusMcp\Mcp\FilteredListToolsHandler;
use Koh\SyliusMcp\Mcp\GuardedCallToolHandler;
use Koh\SyliusMcp\Mcp\KohCapabilityLoader;
use Koh\SyliusMcp\Mcp\McpCatalogue;
use Koh\SyliusMcp\Mcp\PolicyRegistry;
use Koh\SyliusMcp\Output\UntrustedContentMarker;
use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Koh\SyliusMcp\Security\CatalogueVisibility;
use Koh\SyliusMcp\Security\McpCallerResolver;
use Koh\SyliusMcp\Security\ToolAccessDecider;
use Koh\SyliusMcp\Tool\ContentAwareProductSearchTool;
use Koh\SyliusMcp\Tool\FetchProductTool;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server\Handler\Request\CallToolHandler;
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
            ->arg('$visibility', service(CatalogueVisibility::class))
            ->tag('mcp.tool');
    }

    $services->set(UntrustedContentMarker::class);

    $services->set(McpCallerResolver::class)
        ->args([
            service('security.token_storage')->nullOnInvalid(),
            service('request_stack')->nullOnInvalid(),
        ]);

    $services->set(ToolAccessDecider::class)
        ->args([
            service('security.authorization_checker')->nullOnInvalid(),
            param('koh_sylius_mcp.class_roles'),
            param('koh_sylius_mcp.tools'),
        ]);

    $services->set(CatalogueVisibility::class)
        ->args([
            service('sylius.context.channel'),
            service(ToolAccessDecider::class),
            service(McpCallerResolver::class),
        ]);

    // The SDK's own tools/call handler, wrapped by GuardedCallToolHandler.
    // Argument 0 of the reference handler — the tool service locator — is set
    // by McpReferenceContainerPass.
    $services->set(McpReferenceContainerPass::REFERENCE_HANDLER, ReferenceHandler::class)
        ->autoconfigure(false)
        ->args([null]);

    $services->set('koh_sylius_mcp.sdk_call_tool_handler', CallToolHandler::class)
        ->autoconfigure(false)
        ->args([
            service('mcp.registry'),
            service(McpReferenceContainerPass::REFERENCE_HANDLER),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'mcp']);

    // Tagged mcp.request_handler explicitly; the builder puts custom handlers
    // before its built-in ones, so these answer tools/call and tools/list.
    $services->set(GuardedCallToolHandler::class)
        ->autoconfigure(false)
        ->args([
            service('mcp.registry'),
            service('koh_sylius_mcp.sdk_call_tool_handler'),
            service(ToolPolicyProvider::class),
            service(ToolAccessDecider::class),
            service(McpCallerResolver::class),
            service(UntrustedContentMarker::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('mcp.request_handler')
        ->tag('monolog.logger', ['channel' => 'mcp']);

    $services->set(FilteredListToolsHandler::class)
        ->autoconfigure(false)
        ->args([
            service('mcp.registry'),
            service(ToolPolicyProvider::class),
            service(ToolAccessDecider::class),
            service(McpCallerResolver::class),
        ])
        ->tag('mcp.request_handler');

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
