<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Sylius\Component\Core\Model\ProductInterface as CoreProductInterface;
use Sylius\Component\Product\Model\ProductInterface;
use Sylius\Component\Product\Model\ProductVariantInterface;
use Sylius\Component\Product\Repository\ProductRepositoryInterface;

/**
 * Fetch a single product by its code.
 *
 * sylius/mcp-server-plugin registers a tool of the same name that asks the
 * shop API anonymously and returns its raw JSON-LD. Two tools under one name
 * overwrote each other in loader order; the policy registry now lets this one
 * win, every time. It answers in-process, and it returns what the plugin's
 * answer was used for — the variant codes to pass to fetch_product_variant —
 * so an agent following the plugin's description keeps working.
 */
#[McpTool(
    name: 'fetch_product',
    description: 'Fetch a single shop product by its code: name, slug, description, enabled flag, main taxon and its variants. Pass a variant code to fetch_product_variant for prices and stock.',
)]
final class FetchProductTool
{
    /** @param ProductRepositoryInterface<ProductInterface> $productRepository */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
    ) {
    }

    /**
     * @param string $code Unique product code, e.g. "Basic_regular".
     *
     * @return array{code: string, name: string, slug: string|null, description: string|null, shortDescription: string|null, enabled: bool, mainTaxon: string|null, variants: list<array{code: string, name: string|null, enabled: bool}>}|null
     */
    public function __invoke(string $code): ?array
    {
        $product = $this->productRepository->findOneBy(['code' => $code]);

        if (!$product instanceof ProductInterface) {
            return null;
        }

        $core = $product instanceof CoreProductInterface ? $product : null;

        return [
            'code' => (string) $product->getCode(),
            'name' => (string) $product->getName(),
            'slug' => $product->getSlug(),
            'description' => $product->getDescription(),
            'shortDescription' => $core?->getShortDescription(),
            'enabled' => $product->isEnabled(),
            'mainTaxon' => $core?->getMainTaxon()?->getCode(),
            'variants' => array_values(array_map(
                static fn (ProductVariantInterface $variant): array => [
                    'code' => (string) $variant->getCode(),
                    'name' => $variant->getName(),
                    'enabled' => $variant->isEnabled(),
                ],
                $product->getVariants()->toArray(),
            )),
        ];
    }
}
