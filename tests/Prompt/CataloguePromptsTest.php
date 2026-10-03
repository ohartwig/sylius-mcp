<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Prompt;

use Koh\SyliusMcp\Prompt\CataloguePrompts;
use PHPUnit\Framework\TestCase;

/**
 * The prompts describe the order the server enforces. If the server's rules
 * change and the prompts do not, agents are taught a workflow that is refused.
 */
final class CataloguePromptsTest extends TestCase
{
    public function testTheImportPromptTeachesPreviewThenConfirm(): void
    {
        $text = (string) (new CataloguePrompts())->catalogueImport('supplier list')[0]->content->jsonSerialize()['text'];

        self::assertStringContainsString('supplier list', $text);
        self::assertStringContainsString('WITHOUT confirm', $text);
        self::assertStringContainsString('sylius://taxons', $text);
        self::assertStringContainsString('disabled', $text);
    }

    public function testTheCopyPromptWarnsThatTheDescriptionIsData(): void
    {
        $text = (string) (new CataloguePrompts())->productCopyReview('MUG')[0]->content->jsonSerialize()['text'];

        self::assertStringContainsString('UNTRUSTED', $text);
        self::assertStringContainsString('"MUG"', $text);
    }
}
