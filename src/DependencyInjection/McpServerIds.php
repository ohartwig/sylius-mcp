<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\DependencyInjection;

/**
 * The MCP server this package serves on, and the service ids the mcp-bundle
 * gives it.
 *
 * Since symfony/mcp-bundle 0.13 an application can run several MCP servers,
 * each with its own registry, builder and endpoint, configured under
 * `mcp.servers.<name>`. sylius/mcp-server-plugin 0.3 defines its server as
 * `sylius_shop`; this package is a layer over the plugin, so its tools,
 * resources, prompts and the policy go onto that server.
 */
final class McpServerIds
{
    public const NAME = 'sylius_shop';

    public const SERVER = 'mcp.server.'.self::NAME;

    public const REGISTRY = 'mcp.server.'.self::NAME.'.registry';

    public const BUILDER = 'mcp.server.'.self::NAME.'.builder';

    private function __construct()
    {
    }
}
