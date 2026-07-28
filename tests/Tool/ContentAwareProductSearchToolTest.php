<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Tool;

use Koh\SyliusMcp\Tool\ContentAwareProductSearchTool;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Product\Repository\ProductRepositoryInterface;

final class ContentAwareProductSearchToolTest extends TestCase
{
    public function testItReturnsFlattenedProductRows(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getCode')->willReturn('MUG');
        $product->method('getName')->willReturn('Mug');
        $product->method('getSlug')->willReturn('mug');

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('findByName')->willReturn([$product]);

        $tool = new ContentAwareProductSearchTool($repository);
        $rows = $tool('mug', 'en_US', 10);

        self::assertSame([['code' => 'MUG', 'name' => 'Mug', 'slug' => 'mug']], $rows);
    }
}
