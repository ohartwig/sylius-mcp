<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Support;

/**
 * A tool handler that remembers being called — the evidence that a refused
 * call never reached the tool.
 */
final class RecordingTool
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @param mixed $result what every call returns */
    public function __construct(private readonly mixed $result = ['ok' => true])
    {
    }

    /** @return mixed */
    public function __invoke(string $code = '', string $description = ''): mixed
    {
        $this->calls[] = ['code' => $code, 'description' => $description];

        return $this->result;
    }
}
