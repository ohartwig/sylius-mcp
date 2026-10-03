<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Policy;

/**
 * Risk class of an MCP tool.
 *
 * The three classes are the ones the agentic-systems standard of the handbook
 * defines (read only, low-risk write, high-risk write). Everything else a
 * policy decides — which scope a token needs, whether the call has to be
 * confirmed, which hints the client sees — follows from the class, so a tool
 * is classified once and not in five places.
 */
enum ToolClass: string
{
    /** Reads only. No state in the shop changes. */
    case Read = 'read';

    /** Changes state that is not yet visible to customers, e.g. a guest cart. */
    case Write = 'write';

    /** Changes the catalogue, content customers see, or places an order. */
    case HighRisk = 'high_risk';

    public function isWrite(): bool
    {
        return self::Read !== $this;
    }
}
