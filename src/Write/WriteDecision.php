<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Write;

final readonly class WriteDecision
{
    /**
     * @param array<string, mixed>      $arguments for the tool, when it runs
     * @param list<string>              $notes     for the agent, appended to the result
     * @param array<string, mixed>|null $preview
     */
    private function __construct(
        public bool $runs,
        public array $arguments = [],
        public array $notes = [],
        public bool $confirmed = false,
        public ?array $preview = null,
        public ?string $refusal = null,
        public ?string $outcome = null,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $notes
     */
    public static function execute(array $arguments, array $notes = [], bool $confirmed = false): self
    {
        return new self(true, $arguments, $notes, $confirmed);
    }

    /** @param array<string, mixed> $preview */
    public static function preview(array $preview): self
    {
        return new self(false, preview: $preview, outcome: 'preview');
    }

    public static function refuse(string $outcome, string $reason): self
    {
        return new self(false, refusal: $reason, outcome: $outcome);
    }
}
