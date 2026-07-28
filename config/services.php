<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;
use Koh\SyliusMcp\Agent\McpEndpointProvider;
use Koh\SyliusMcp\Controller\McpServerCardController;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // The MCP tools are discovered by sylius/mcp-server-plugin through its own
    // attribute scan, so they need no definition here.

    $services->set(McpServerCardController::class)
        ->args([
            service(AgentSkillRegistry::class),
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
