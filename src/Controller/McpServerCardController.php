<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Controller;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Model\AgentSkill;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the MCP Server Card at /.well-known/mcp/server-card.json (SEP-1649).
 *
 * Lets an agent learn the transport endpoint and the tool surface before
 * connecting — and, through the protected-resource link, how to authenticate
 * without first being refused.
 */
final class McpServerCardController
{
    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
        private readonly string $version = '1.0.0',
        private readonly string $transportPath = '/_mcp',
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $context = $this->contextFactory->create();
        $skills = $this->skills->all($context);

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
                'tools' => [] === $skills ? null : ['listChanged' => false],
                'resources' => null,
                'prompts' => null,
            ],
            'tools' => array_map(
                static fn (AgentSkill $skill): array => [
                    'name' => $skill->id,
                    'description' => $skill->description,
                ],
                $skills,
            ),
            'authorization' => [
                'protectedResourceMetadata' => $context->url('/.well-known/oauth-protected-resource'),
            ],
        ], request: $request);
    }
}
