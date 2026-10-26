<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusMcp\Agent\McpEndpointProvider;
use Koh\SyliusMcp\Controller\McpServerCardController;
use Koh\SyliusMcp\DependencyInjection\McpReferenceContainerPass;
use Koh\SyliusMcp\DependencyInjection\McpServerIds;
use Koh\SyliusMcp\EventSubscriber\McpOriginSubscriber;
use Koh\SyliusMcp\Mcp\FilteredListToolsHandler;
use Koh\SyliusMcp\Mcp\GuardedCallToolHandler;
use Koh\SyliusMcp\Mcp\GuardedReadResourceHandler;
use Koh\SyliusMcp\Mcp\McpCatalogue;
use Koh\SyliusMcp\Mcp\NegotiatingInitializeHandler;
use Koh\SyliusMcp\Mcp\PolicyRegistry;
use Koh\SyliusMcp\Audit\ToolCallAuditor;
use Koh\SyliusMcp\Output\UntrustedContentMarker;
use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Koh\SyliusMcp\Prompt\CataloguePrompts;
use Koh\SyliusMcp\Resource\CatalogueResources;
use Koh\SyliusMcp\Security\CatalogueVisibility;
use Koh\SyliusMcp\Security\McpCallerResolver;
use Koh\SyliusMcp\Security\ToolAccessDecider;
use Koh\SyliusMcp\Tool\ContentAwareProductSearchTool;
use Koh\SyliusMcp\Tool\FetchProductTool;
use Koh\SyliusMcp\Write\ConfirmationTokens;
use Koh\SyliusMcp\Write\DraftPolicy;
use Koh\SyliusMcp\Write\Preview\CheckoutPreview;
use Koh\SyliusMcp\Write\Preview\ProductWritePreview;
use Koh\SyliusMcp\Write\WriteGate;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\ReadResourceHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Tools, resources and prompts are tagged by the mcp-bundle's
    // autoconfiguration (one tag per #[McpTool]/#[McpResource]/#[McpPrompt]
    // method). Its McpPass registers them with every server whose
    // `registry:` lists them — KohSyliusMcpBundle::prependExtension() adds
    // this package's namespaces to the plugin's server.
    foreach ([ContentAwareProductSearchTool::class, FetchProductTool::class] as $tool) {
        $services->set($tool)
            ->arg('$productRepository', service('sylius.repository.product'))
            ->arg('$visibility', service(CatalogueVisibility::class));
    }

    $services->set(UntrustedContentMarker::class);

    // Own channel, so the application can route the audit trail to its own
    // handler and retention (the handbook asks for a year).
    $services->set(ToolCallAuditor::class)
        ->args([
            service('logger')->nullOnInvalid(),
            param('koh_sylius_mcp.audit.argument_max_length'),
        ])
        ->tag('monolog.logger', ['channel' => 'koh_mcp_audit']);

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
            service(McpServerIds::REGISTRY),
            service(McpReferenceContainerPass::REFERENCE_HANDLER),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'mcp']);

    // Tagged mcp.request_handler explicitly; the builder puts custom handlers
    // before its built-in ones, so these answer tools/call and tools/list.
    $services->set(GuardedCallToolHandler::class)
        ->autoconfigure(false)
        ->args([
            service(McpServerIds::REGISTRY),
            service('koh_sylius_mcp.sdk_call_tool_handler'),
            service(ToolPolicyProvider::class),
            service(ToolAccessDecider::class),
            service(McpCallerResolver::class),
            service(UntrustedContentMarker::class),
            service(ToolCallAuditor::class),
            service(WriteGate::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('mcp.request_handler')
        ->tag('monolog.logger', ['channel' => 'mcp']);

    $services->set(CatalogueResources::class)
        ->args([
            service('sylius.repository.channel'),
            service('sylius.repository.taxon'),
            service('sylius.repository.product_attribute'),
            service('sylius.repository.product_option'),
        ]);

    $services->set(CataloguePrompts::class);

    $services->set('koh_sylius_mcp.sdk_read_resource_handler', ReadResourceHandler::class)
        ->autoconfigure(false)
        ->args([
            service(McpServerIds::REGISTRY),
            service(McpReferenceContainerPass::REFERENCE_HANDLER),
            service('logger')->nullOnInvalid(),
        ]);

    $services->set(GuardedReadResourceHandler::class)
        ->autoconfigure(false)
        ->args([
            service('koh_sylius_mcp.sdk_read_resource_handler'),
            service(McpCallerResolver::class),
            service(ToolCallAuditor::class),
        ])
        ->tag('mcp.request_handler');

    $services->set(NegotiatingInitializeHandler::class)
        ->autoconfigure(false)
        ->factory([NegotiatingInitializeHandler::class, 'create'])
        // Server info and instructions are copied from the server builder by
        // McpReferenceContainerPass.
        ->args([
            service(McpServerIds::REGISTRY),
            '$name' => 'app',
            '$version' => '0.0.1',
            '$description' => null,
            '$websiteUrl' => null,
            '$instructions' => null,
        ])
        ->tag('mcp.request_handler');

    $services->set(FilteredListToolsHandler::class)
        ->autoconfigure(false)
        ->args([
            service(McpServerIds::REGISTRY),
            service(ToolPolicyProvider::class),
            service(ToolAccessDecider::class),
            service(McpCallerResolver::class),
        ])
        ->tag('mcp.request_handler');

    $services->set(ToolPolicyProvider::class)
        ->args([
            param('koh_sylius_mcp.tools'),
            param('koh_sylius_mcp.default_tool_class'),
            param('koh_sylius_mcp.writes.confirm_high_risk'),
        ]);

    $services->set(DraftPolicy::class)
        ->args([
            param('koh_sylius_mcp.writes.live'),
            param('koh_sylius_mcp.writes.drafts'),
            service('sylius.repository.product')->nullOnInvalid(),
            service('doctrine.orm.entity_manager')->nullOnInvalid(),
        ]);

    $services->set(ConfirmationTokens::class)
        ->args([
            param('kernel.secret'),
            param('koh_sylius_mcp.writes.confirmation_ttl'),
            service('cache.app')->nullOnInvalid(),
        ]);

    $services->set(ProductWritePreview::class)
        ->args([
            service('sylius.repository.product'),
            service('sylius.repository.channel'),
            service('sylius.repository.taxon'),
        ])
        ->tag('koh_sylius_mcp.tool_preview');

    $services->set(CheckoutPreview::class)
        ->args([service('sylius.repository.order')])
        ->tag('koh_sylius_mcp.tool_preview');

    $services->set(WriteGate::class)
        ->args([
            service(DraftPolicy::class),
            service(ConfirmationTokens::class),
            tagged_iterator('koh_sylius_mcp.tool_preview'),
        ]);

    $services->set(PolicyRegistry::class)
        ->decorate(McpServerIds::REGISTRY, invalidBehavior: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([
            service('.inner'),
            service(ToolPolicyProvider::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'mcp']);

    $services->set(McpCatalogue::class)
        ->args([
            service(McpServerIds::REGISTRY),
            service_closure(McpServerIds::SERVER)->nullOnInvalid(),
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

    $services->set(McpOriginSubscriber::class)
        ->args([
            param('koh_sylius_mcp.transport_path'),
            param('koh_sylius_mcp.allowed_origins'),
        ])
        ->tag('kernel.event_subscriber');

    $services->set(McpEndpointProvider::class)
        ->args([param('koh_sylius_mcp.transport_path')])
        ->tag('koh_agent.endpoint')
        ->tag('koh_agent.api_catalog_entry');
};
