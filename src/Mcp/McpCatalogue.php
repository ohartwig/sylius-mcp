<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Mcp;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use Mcp\Server;

/**
 * Read access to what the MCP server actually serves, for documents that
 * describe it from outside the protocol — the server card.
 *
 * The registry is filled when the server is built: the mcp-bundle hands the
 * builder its own registry, and the builder then loads every element into it
 * once, in its build(). Reading the registry before that returns an empty
 * list, so every read here first makes sure the server exists. The server
 * comes in as a closure so that a request which never reads the catalogue
 * does not build it.
 */
final class McpCatalogue
{
    /** @param (\Closure(): ?Server)|null $server */
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly ?\Closure $server = null,
    ) {
    }

    /** @return list<Tool> */
    public function tools(): array
    {
        $this->buildServer();

        $tools = array_values(array_filter(
            $this->registry->getTools()->references,
            static fn (mixed $tool): bool => $tool instanceof Tool,
        ));
        usort($tools, static fn (Tool $a, Tool $b): int => strcmp($a->name, $b->name));

        return $tools;
    }

    public function hasResources(): bool
    {
        $this->buildServer();

        return $this->registry->hasResources() || $this->registry->hasResourceTemplates();
    }

    public function hasPrompts(): bool
    {
        $this->buildServer();

        return $this->registry->hasPrompts();
    }

    private function buildServer(): void
    {
        if (null !== $this->server) {
            ($this->server)();
        }
    }
}
