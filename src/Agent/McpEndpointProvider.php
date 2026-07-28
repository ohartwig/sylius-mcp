<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Agent;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusAgentContracts\Model\LinkTarget;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;
use Koh\SyliusAgentContracts\Provider\ApiCatalogEntryProviderInterface;

/**
 * Announces the server card to llms.txt and the api-catalog.
 */
final class McpEndpointProvider implements AgentEndpointProviderInterface, ApiCatalogEntryProviderInterface
{
    public function __construct(private readonly string $transportPath = '/_mcp')
    {
    }

    public function getAgentEndpoints(AgentContext $context): iterable
    {
        yield new AgentEndpoint(
            path: '/.well-known/mcp/server-card.json',
            title: 'MCP server card',
            rel: 'describedby',
            protocol: 'mcp',
            description: 'Transport endpoint and tool surface of the MCP server.',
        );
    }

    public function getApiCatalogEntries(AgentContext $context): iterable
    {
        yield new ApiCatalogEntry(
            anchor: $context->url($this->transportPath),
            serviceDesc: [new LinkTarget(
                $context->url('/.well-known/mcp/server-card.json'),
                'application/json',
            )],
            title: 'MCP server',
        );
    }
}
