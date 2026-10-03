<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Write;

final readonly class ConfirmationToken
{
    public function __construct(
        public string $value,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
