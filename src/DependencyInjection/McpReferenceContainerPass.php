<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Gives our reference handler the same service locator the server builder
 * resolves tool handlers from.
 *
 * The mcp-bundle's McpPass builds that locator from every service tagged
 * mcp.tool/mcp.prompt/mcp.resource and hands it to the builder through a
 * setContainer() call; it is not a service of its own. The guarded tools/call
 * handler delegates to an SDK CallToolHandler that needs the very same
 * locator, or it would instantiate tool classes with `new` and no arguments.
 * Registered to run after McpPass.
 */
final class McpReferenceContainerPass implements CompilerPassInterface
{
    public const REFERENCE_HANDLER = 'koh_sylius_mcp.reference_handler';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::REFERENCE_HANDLER) || !$container->hasDefinition('mcp.server.builder')) {
            return;
        }

        foreach ($container->getDefinition('mcp.server.builder')->getMethodCalls() as [$method, $arguments]) {
            if ('setContainer' === $method && isset($arguments[0])) {
                $container->getDefinition(self::REFERENCE_HANDLER)->setArgument(0, $arguments[0]);

                return;
            }
        }
    }
}
