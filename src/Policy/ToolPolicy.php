<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Policy;

use Mcp\Schema\ToolAnnotations;

/**
 * What the server is allowed to do with one tool, resolved from the built-in
 * defaults and the application's `koh_sylius_mcp.tools` configuration.
 */
final readonly class ToolPolicy
{
    public function __construct(
        public string $name,
        public ToolClass $class,
        public bool $enabled = true,
        public ?bool $idempotent = null,
        public ?bool $destructive = null,
        public bool $openWorld = false,
        /** The result carries text written by shop editors, importers or customers. */
        public bool $untrusted = true,
        /** True when the name is neither built in nor configured. */
        public bool $unknown = false,
        /** A call has to be previewed and then confirmed with the token from the preview. */
        public bool $confirm = false,
    ) {
    }

    /**
     * The hints a client sees in tools/list.
     *
     * The tool's own annotations — set in its #[McpTool] attribute — are kept
     * where they say something the policy does not. Where both speak, the
     * policy wins: it is what the server enforces, and a hint that contradicts
     * the enforcement is worse than no hint.
     */
    public function annotations(?ToolAnnotations $own = null): ToolAnnotations
    {
        $readOnly = ToolClass::Read === $this->class;

        return new ToolAnnotations(
            title: $own?->title,
            readOnlyHint: $readOnly,
            // The spec reads destructive and idempotent only when readOnly is
            // false. They are still set on read tools, with the only values
            // that are true of them, because some clients show a missing hint
            // as "unknown" and a reader of tools/list should not have to know
            // that rule to see that a read tool changes nothing.
            destructiveHint: $readOnly ? false : ($this->destructive ?? $own->destructiveHint ?? true),
            idempotentHint: $readOnly ? true : ($this->idempotent ?? $own->idempotentHint ?? false),
            openWorldHint: $this->openWorld,
        );
    }
}
