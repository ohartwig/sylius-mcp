<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Sylius\Component\Product\Model\ProductInterface;
use Sylius\Component\Product\Repository\ProductRepositoryInterface;

/**
 * Content-aware product search, exposed to LLM agents as an MCP tool via the
 * sylius/mcp-server-plugin (autoconfigured -> discovered at container compile).
 *
 * First pass: plain Sylius product search by phrase + locale. The "content
 * aware" enrichment (matching TYPO3 CMS pages/articles to products) is wired
 * later through koh/typo3-sylius-bridge -- see the TODO below.
 */
final class ContentAwareProductSearchTool
{
    /** Upper bound on what one tool call may return into an agent's context. */
    private const MAX_RESULTS = 100;

    /** @param ProductRepositoryInterface<ProductInterface> $productRepository */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
    ) {
    }

    /**
     * @return list<array{code: string, name: string, slug: string|null}>
     */
    #[McpTool(
        name: 'content_product_search',
        description: 'Search shop products by phrase (content-commerce aware). Returns product code, name and slug.',
    )]
    public function __invoke(string $phrase, string $localeCode = 'en_US', int $limit = 10): array
    {
        // findByName() is a LIKE with no limit, so an empty phrase matches the
        // whole catalogue. The caller here is an authenticated agent rather than
        // an anonymous one, but "authenticated" does not make hydrating every
        // product a reasonable answer to an empty search.
        if ('' === trim($phrase)) {
            return [];
        }

        $products = $this->productRepository->findByName($phrase, $localeCode);

        // TODO: enrich/re-rank with matching TYPO3 content via koh/typo3-sylius-bridge.

        // Clamped at both ends. The result goes straight into an agent's context
        // window, where an unbounded list is not just expensive but crowds out
        // whatever the agent was actually doing.
        $products = \array_slice($products, 0, min(max(1, $limit), self::MAX_RESULTS));

        return array_map(
            static fn ($product): array => [
                'code' => (string) $product->getCode(),
                'name' => (string) $product->getName(),
                'slug' => $product->getSlug(),
            ],
            $products,
        );
    }
}
