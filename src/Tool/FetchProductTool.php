<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Sylius\Component\Product\Model\ProductInterface;
use Sylius\Component\Product\Repository\ProductRepositoryInterface;

/**
 * Fetch a single product by its code, exposed as an MCP tool so an agent can
 * pull full product detail after a search. Grounded against the Sylius Product
 * component repository (Doctrine-generic findOneBy).
 */
final class FetchProductTool
{
    /** @param ProductRepositoryInterface<ProductInterface> $productRepository */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
    ) {
    }

    /**
     * @return array{code: string, name: string, slug: string|null, description: string|null}|null
     */
    #[McpTool(name: 'fetch_product', description: 'Fetch a single shop product by its code (name, slug, description).')]
    public function __invoke(string $code): ?array
    {
        /** @var object|null $product */
        $product = $this->productRepository->findOneBy(['code' => $code]);

        if ($product === null) {
            return null;
        }

        return [
            'code' => (string) $product->getCode(),
            'name' => (string) $product->getName(),
            'slug' => $product->getSlug(),
            'description' => method_exists($product, 'getDescription') ? $product->getDescription() : null,
        ];
    }
}
