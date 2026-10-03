<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Write;

final readonly class DraftOutcome
{
    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $notes
     */
    public function __construct(
        public array $arguments,
        public ?string $refusal = null,
        public array $notes = [],
    ) {
    }

    public static function refused(string $reason): self
    {
        return new self([], $reason);
    }
}
