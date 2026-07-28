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

    /**
     * The limit is the agent's to choose, so it is the tool's to bound. A tool
     * result goes straight into a context window, where an unbounded list
     * crowds out whatever the agent was actually doing.
     */
    public function testTheCallerCannotRaiseTheLimitWithoutBound(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('findByName')->willReturn($this->products(500));

        $rows = (new ContentAwareProductSearchTool($repository))('mug', 'en_US', 1_000_000);

        self::assertCount(100, $rows);
    }

    /**
     * findByName() is a LIKE with no limit of its own: an empty phrase matches
     * the whole catalogue. Being authenticated does not make that a sensible
     * answer to an empty search.
     */
    public function testAnEmptyPhraseNeverReachesTheRepository(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::never())->method('findByName');

        self::assertSame([], (new ContentAwareProductSearchTool($repository))('   '));
    }

    /** @return list<ProductInterface> */
    private function products(int $count): array
    {
        $products = [];
        for ($i = 0; $i < $count; ++$i) {
            $product = $this->createMock(ProductInterface::class);
            $product->method('getCode')->willReturn('SKU-'.$i);
            $product->method('getName')->willReturn('Product '.$i);
            $product->method('getSlug')->willReturn('product-'.$i);
            $products[] = $product;
        }

        return $products;
    }
}
