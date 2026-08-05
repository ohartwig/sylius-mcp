<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

/**
 * composer-unused only sees symbols that PHP source references directly, and
 * this bundle's whole purpose is to configure someone else's plugin rather
 * than to call it.
 */
return static function (Configuration $config): Configuration {
    return $config
        // The plugin this bundle is a layer over. config/packages/
        // sylius_mcp_server.yaml imports @SyliusMcpServerPlugin/config/config.yaml
        // by resource path, which is what puts the MCP server on POST /_mcp and
        // makes the #[McpTool] classes here reachable. Removing the require
        // would leave that import pointing at a bundle that is not installed.
        ->addNamedFilter(NamedFilter::fromString('sylius/mcp-server-plugin'));
};
