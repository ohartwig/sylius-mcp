<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\DependencyInjection;

use Koh\SyliusMcp\Mcp\NegotiatingInitializeHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Hands our handlers what the mcp-bundle only gives the server builder.
 *
 * The mcp-bundle's McpPass builds a service locator from every MCP element
 * service the server exposes and passes it to the builder through a
 * setContainer() call; it is not a service of its own. The guarded tools/call
 * and resources/read handlers delegate to SDK handlers that need the very
 * same locator, or they would instantiate tool classes with `new` and no
 * arguments.
 *
 * Likewise, since mcp-bundle 0.13 the server's name, version, description,
 * website and instructions are no longer container parameters, only
 * arguments of the builder's setServerInfo() and setInstructions() calls.
 * NegotiatingInitializeHandler answers initialize with them.
 *
 * Registered to run after McpPass.
 */
final class McpReferenceContainerPass implements CompilerPassInterface
{
    public const REFERENCE_HANDLER = 'koh_sylius_mcp.reference_handler';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(McpServerIds::BUILDER)) {
            return;
        }

        foreach ($container->getDefinition(McpServerIds::BUILDER)->getMethodCalls() as [$method, $arguments]) {
            if ('setContainer' === $method && isset($arguments[0]) && $container->hasDefinition(self::REFERENCE_HANDLER)) {
                $container->getDefinition(self::REFERENCE_HANDLER)->setArgument(0, $arguments[0]);
            }

            if (!$container->hasDefinition(NegotiatingInitializeHandler::class)) {
                continue;
            }
            $initialize = $container->getDefinition(NegotiatingInitializeHandler::class);

            // setServerInfo(name, version, description, icons, websiteUrl)
            if ('setServerInfo' === $method) {
                $initialize->setArgument('$name', $arguments[0] ?? 'app');
                $initialize->setArgument('$version', $arguments[1] ?? '0.0.1');
                $initialize->setArgument('$description', $arguments[2] ?? null);
                $initialize->setArgument('$websiteUrl', $arguments[4] ?? null);
            }

            if ('setInstructions' === $method) {
                $initialize->setArgument('$instructions', $arguments[0] ?? null);
            }
        }
    }
}
