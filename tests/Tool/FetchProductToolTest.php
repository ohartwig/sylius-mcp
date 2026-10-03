<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Tool;

use Doctrine\Common\Collections\ArrayCollection;
use Koh\SyliusMcp\Tests\Support\CatalogueFixtures;
use Koh\SyliusMcp\Tool\FetchProductTool;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Product\Repository\ProductRepositoryInterface;

final class FetchProductToolTest extends TestCase
{
    use CatalogueFixtures;

    public function testItReturnsNullWhenUnknown(): void
    {
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('findOneBy')->willReturn(null);

        self::assertNull((new FetchProductTool($repository, $this->visibility()))('NOPE'));
    }

    /**
     * The variant codes are what the plugin's fetch_product was used for: they
     * are the argument of fetch_product_variant.
     */
    public function testItMapsAProductWithItsVariants(): void
    {
        $variant = $this->createStub(ProductVariantInterface::class);
        $variant->method('getCode')->willReturn('MUG-RED');
        $variant->method('getName')->willReturn('Red');
        $variant->method('isEnabled')->willReturn(true);

        $taxon = $this->createStub(TaxonInterface::class);
        $taxon->method('getCode')->willReturn('mugs');

        $product = $this->createStub(ProductInterface::class);
        $product->method('getCode')->willReturn('MUG');
        $product->method('getName')->willReturn('Mug');
        $product->method('getSlug')->willReturn('mug');
        $product->method('getDescription')->willReturn('A mug.');
        $product->method('getShortDescription')->willReturn('Mug.');
        $product->method('isEnabled')->willReturn(true);
        $product->method('getMainTaxon')->willReturn($taxon);
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('hasChannel')->willReturn(true);

        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($product);

        self::assertSame(
            [
                'code' => 'MUG',
                'name' => 'Mug',
                'slug' => 'mug',
                'description' => 'A mug.',
                'shortDescription' => 'Mug.',
                'enabled' => true,
                'mainTaxon' => 'mugs',
                'variants' => [['code' => 'MUG-RED', 'name' => 'Red', 'enabled' => true]],
            ],
            (new FetchProductTool($repository, $this->visibility()))('MUG'),
        );
    }

    /** Another channel's product reads exactly like no product. */
    public function testAProductOfAnotherChannelIsNotThere(): void
    {
        self::assertNull($this->fetch($this->product('ELSEWHERE', inChannel: false), 'ELSEWHERE'));
    }

    /**
     * A draft is visible to whoever may change the catalogue and asks for it,
     * and to nobody else.
     */
    public function testADraftNeedsTheAskAndTheRight(): void
    {
        $draft = $this->product('DRAFT', enabled: false);

        self::assertNull($this->fetch($draft, 'DRAFT'));
        self::assertNull($this->fetch($draft, 'DRAFT', includeUnpublished: true, scopes: ['mcp:read']));
        self::assertSame('DRAFT', $this->fetch($draft, 'DRAFT', includeUnpublished: true)['code'] ?? null);
    }

    /**
     * @param list<string> $scopes
     *
     * @return array<string, mixed>|null
     */
    private function fetch(ProductInterface $product, string $code, bool $includeUnpublished = false, array $scopes = ['mcp']): ?array
    {
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($product);

        return (new FetchProductTool($repository, $this->visibility(scopes: $scopes)))($code, $includeUnpublished);
    }
}
