<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Mcp;

use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Registers the tools, resources and prompts of this package with the MCP
 * server.
 *
 * Until now the package relied on being found by some attribute scan, and no
 * scan looked here: sylius/mcp-server-plugin only scans its own src/Tool, and
 * the mcp-bundle's discovery only the application's scan_dirs. The tools were
 * services nobody served. This loader is tagged `mcp.loader` and registers
 * them explicitly, as manual registrations, the way the plugin registers its
 * own.
 */
final class KohCapabilityLoader implements LoaderInterface
{
    /** Relative to the package root. */
    public const DIRECTORIES = ['src/Tool', 'src/Resource', 'src/Prompt'];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        $state = (new Discoverer($this->logger))->discover(\dirname(__DIR__, 2), self::DIRECTORIES);

        foreach ($state->getTools() as $reference) {
            $registry->registerTool($reference->tool, $reference->handler, isManual: true);
        }
        foreach ($state->getResources() as $reference) {
            $registry->registerResource($reference->resource, $reference->handler, isManual: true);
        }
        foreach ($state->getResourceTemplates() as $reference) {
            $registry->registerResourceTemplate($reference->resourceTemplate, $reference->handler, $reference->completionProviders, isManual: true);
        }
        foreach ($state->getPrompts() as $reference) {
            $registry->registerPrompt($reference->prompt, $reference->handler, $reference->completionProviders, isManual: true);
        }
    }
}
