<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Write\Preview;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Persistence\ObjectRepository;
use Koh\SyliusMcp\Write\Preview\CheckoutPreview;
use Koh\SyliusMcp\Write\Preview\ProductWritePreview;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariantInterface;

final class PreviewTest extends TestCase
{
    public function testCreatingAnExistingProductIsANoOp(): void
    {
        $existing = new Product();
        $existing->setCode('MUG');
        $existing->setCurrentLocale('de_DE');
        $existing->setFallbackLocale('de_DE');
        $existing->setName('Mug');

        $preview = (new ProductWritePreview($this->repo(['MUG' => $existing]), $this->repo([]), $this->repo([])))
            ->preview('create_product', ['code' => 'MUG', 'name' => 'Mug', 'price' => 100]);

        self::assertSame('none', $preview['effect']);
    }

    /** A reviewer sees whether the channel and taxon the call names exist. */
    public function testCreatingReportsMissingChannelAndTaxon(): void
    {
        $preview = (new ProductWritePreview($this->repo([]), $this->repo(['budde' => new \stdClass()]), $this->repo([])))
            ->preview('create_product', ['code' => 'NEW', 'name' => 'New', 'price' => 1150, 'channelCode' => 'budde', 'taxonCode' => 'pfeifen']);

        self::assertSame('create', $preview['effect']);
        self::assertTrue($preview['checks']['channel']['exists']);
        self::assertFalse($preview['checks']['taxon']['exists']);
    }

    public function testCheckoutPreviewShowsWhatWouldBeBought(): void
    {
        $variant = $this->createStub(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('MUG-RED');
        $item = $this->createStub(OrderItemInterface::class);
        $item->method('getVariant')->willReturn($variant);
        $item->method('getVariantName')->willReturn('Red mug');
        $item->method('getQuantity')->willReturn(2);
        $item->method('getTotal')->willReturn(2300);

        $order = $this->createStub(OrderInterface::class);
        $order->method('getItems')->willReturn(new ArrayCollection([$item]));
        $order->method('getTotalQuantity')->willReturn(2);
        $order->method('getTotal')->willReturn(2790);
        $order->method('getCurrencyCode')->willReturn('EUR');
        $order->method('getShipments')->willReturn(new ArrayCollection());

        $preview = (new CheckoutPreview($this->repo(['cart-token' => $order], 'tokenValue')))
            ->preview('complete_checkout', ['tokenValue' => 'cart-token']);

        self::assertSame('place_order', $preview['effect']);
        self::assertStringContainsString('27.90 EUR', $preview['summary']);
        self::assertSame([['variant' => 'MUG-RED', 'name' => 'Red mug', 'quantity' => 2, 'total' => 2300]], $preview['items']);
        self::assertArrayNotHasKey('email', $preview);
    }

    /**
     * @param array<string, object> $rows
     *
     * @return ObjectRepository<object>
     */
    private function repo(array $rows, string $key = 'code'): ObjectRepository
    {
        $repository = $this->createStub(ObjectRepository::class);
        $repository->method('findOneBy')->willReturnCallback(static fn (array $c): ?object => $rows[$c[$key] ?? ''] ?? null);

        return $repository;
    }
}
