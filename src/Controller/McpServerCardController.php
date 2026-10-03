<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Controller;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusMcp\Mcp\McpCatalogue;
use Mcp\Schema\Tool;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the MCP Server Card at /.well-known/mcp/server-card.json (SEP-1649).
 *
 * Lets an agent learn the transport endpoint and the tool surface before
 * connecting — and, through the protected-resource link, how to authenticate
 * without first being refused.
 *
 * The tool list comes from the MCP registry the server itself answers
 * tools/list from, not from a second list kept for discovery documents. Until
 * 1.x it came from the AgentSkillRegistry, which could name tools the server
 * did not serve and miss tools it did; a card that disagrees with the server
 * is worse than no card. The card is public, so it lists every tool that is
 * switched on — what one token may call is narrower, and tools/list says so
 * once the agent is connected.
 */
final class McpServerCardController
{
    public function __construct(
        private readonly McpCatalogue $catalogue,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
        private readonly string $version = '1.0.0',
        private readonly string $transportPath = '/_mcp',
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $context = $this->contextFactory->create();
        $tools = $this->catalogue->tools();

        return $this->responder->respond([
            'serverInfo' => [
                'name' => $context->channel->getName() ?? 'Shop',
                'version' => $this->version,
            ],
            'transport' => [
                'type' => 'streamable-http',
                'endpoint' => $context->url($this->transportPath),
            ],
            'capabilities' => [
                // Advertised as present only when something is actually
                // registered — an agent that connects on the strength of this
                // and finds an empty tool list has been misled.
                'tools' => [] === $tools ? null : ['listChanged' => false],
                'resources' => $this->catalogue->hasResources() ? ['subscribe' => false, 'listChanged' => false] : null,
                'prompts' => $this->catalogue->hasPrompts() ? ['listChanged' => false] : null,
            ],
            'tools' => array_map(
                static fn (Tool $tool): array => array_filter([
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'annotations' => $tool->annotations?->jsonSerialize(),
                ], static fn (mixed $value): bool => null !== $value),
                $tools,
            ),
            'authorization' => [
                'protectedResourceMetadata' => $context->url('/.well-known/oauth-protected-resource'),
            ],
        ], request: $request);
    }
}
