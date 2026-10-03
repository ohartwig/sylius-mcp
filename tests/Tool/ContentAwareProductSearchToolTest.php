<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Tool;

use Koh\SyliusMcp\Tests\Support\CatalogueFixtures;
use Koh\SyliusMcp\Tool\ContentAwareProductSearchTool;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Product\Repository\ProductRepositoryInterface;

final class ContentAwareProductSearchToolTest extends TestCase
{
    use CatalogueFixtures;

    public function testItReturnsFlattenedProductRows(): void
    {
        $rows = $this->tool([$this->product('MUG')])('mug', 'en_US', 10);

        self::assertSame([['code' => 'MUG', 'name' => 'Product MUG', 'slug' => 'mug']], $rows);
    }

    /**
     * The repository's name search knows neither channel nor enabled flag.
     * What it found in another channel, or disabled, stays out.
     */
    public function testOnlyEnabledProductsOfTheChannelAreFound(): void
    {
        $rows = $this->tool([
            $this->product('LIVE'),
            $this->product('DRAFT', enabled: false),
            $this->product('ELSEWHERE', inChannel: false),
        ])('p');

        self::assertSame(['LIVE'], array_column($rows, 'code'));
    }

    /** No channel, no catalogue — not every channel's catalogue. */
    public function testNothingIsFoundWhenNoChannelResolves(): void
    {
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('findByName')->willReturn([$this->product('LIVE')]);

        self::assertSame([], (new ContentAwareProductSearchTool($repository, $this->visibility(channelResolves: false)))('p'));
    }

    /**
     * The limit is the agent's to choose, so it is the tool's to bound. A tool
     * result goes straight into a context window, where an unbounded list
     * crowds out whatever the agent was actually doing.
     */
    public function testTheCallerCannotRaiseTheLimitWithoutBound(): void
    {
        $products = [];
        for ($i = 0; $i < 500; ++$i) {
            $products[] = $this->product('SKU-'.$i);
        }

        self::assertCount(100, $this->tool($products)('mug', 'en_US', 1_000_000));
    }

    /** Invisible hits are filtered before the limit, so they cannot crowd out visible ones. */
    public function testTheLimitCountsVisibleProductsOnly(): void
    {
        $rows = $this->tool([
            $this->product('DRAFT-1', enabled: false),
            $this->product('DRAFT-2', enabled: false),
            $this->product('LIVE'),
        ])('p', 'en_US', 1);

        self::assertSame(['LIVE'], array_column($rows, 'code'));
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

        self::assertSame([], (new ContentAwareProductSearchTool($repository, $this->visibility()))('   '));
    }

    /** @param list<ProductInterface> $products */
    private function tool(array $products): ContentAwareProductSearchTool
    {
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('findByName')->willReturn($products);

        return new ContentAwareProductSearchTool($repository, $this->visibility());
    }
}
