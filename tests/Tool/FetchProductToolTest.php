<?php

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Tool;

use Koh\SyliusMcp\Tool\FetchProductTool;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Product\Repository\ProductRepositoryInterface;

final class FetchProductToolTest extends TestCase
{
    public function testItReturnsNullWhenUnknown(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('findOneBy')->willReturn(null);

        self::assertNull((new FetchProductTool($repository))('NOPE'));
    }

    public function testItMapsAProduct(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getCode')->willReturn('MUG');
        $product->method('getName')->willReturn('Mug');
        $product->method('getSlug')->willReturn('mug');
        $product->method('getDescription')->willReturn('A mug.');

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($product);

        self::assertSame(
            ['code' => 'MUG', 'name' => 'Mug', 'slug' => 'mug', 'description' => 'A mug.'],
            (new FetchProductTool($repository))('MUG'),
        );
    }
}
