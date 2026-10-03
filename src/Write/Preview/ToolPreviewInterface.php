<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Write\Preview;

/**
 * Says what a write tool would do with these arguments, without doing it.
 *
 * The tools themselves have no dry-run mode — most belong to the plugin or
 * the application — so a preview reads the state the call would change and
 * describes the difference. Tagged `koh_sylius_mcp.tool_preview`.
 */
interface ToolPreviewInterface
{
    public function supports(string $tool): bool;

    /**
     * @param array<string, mixed> $arguments as the tool would receive them
     *
     * @return array<string, mixed>
     */
    public function preview(string $tool, array $arguments): array;
}
