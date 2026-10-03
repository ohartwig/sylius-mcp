<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Write\Preview;

use Doctrine\Persistence\ObjectRepository;
use Sylius\Component\Core\Model\ProductInterface;

/**
 * Previews create_product, import_product and update_product.
 *
 * Answers what a reviewer needs before saying yes: does the product exist
 * already (then create does nothing), is it live, do the channel and the
 * taxon the call refers to exist, and — for an update — which texts change
 * from what to what.
 */
final class ProductWritePreview implements ToolPreviewInterface
{
    private const TEXT_FIELDS = ['description', 'shortDescription', 'metaKeywords', 'metaDescription'];

    /**
     * @param ObjectRepository<object> $products
     * @param ObjectRepository<object> $channels
     * @param ObjectRepository<object> $taxons
     */
    public function __construct(
        private readonly ObjectRepository $products,
        private readonly ObjectRepository $channels,
        private readonly ObjectRepository $taxons,
        private readonly int $excerptLength = 300,
    ) {
    }

    public function supports(string $tool): bool
    {
        return \in_array($tool, ['create_product', 'import_product', 'update_product'], true);
    }

    public function preview(string $tool, array $arguments): array
    {
        $code = \is_string($arguments['code'] ?? null) ? $arguments['code'] : '';
        $product = '' === $code ? null : $this->products->findOneBy(['code' => $code]);
        $product = $product instanceof ProductInterface ? $product : null;

        if ('update_product' === $tool) {
            return $this->update($code, $product, $arguments);
        }

        if (null !== $product) {
            return [
                'effect' => 'none',
                'summary' => \sprintf('Product "%s" exists already; the call would leave it unchanged and report status "exists".', $code),
                'existing' => ['enabled' => $product->isEnabled(), 'name' => $product->getName()],
            ];
        }

        $checks = [];
        if (\is_string($arguments['channelCode'] ?? null)) {
            $checks['channel'] = [
                'code' => $arguments['channelCode'],
                'exists' => null !== $this->channels->findOneBy(['code' => $arguments['channelCode']]),
            ];
        }
        if (\is_string($arguments['taxonCode'] ?? null) && '' !== $arguments['taxonCode']) {
            $checks['taxon'] = [
                'code' => $arguments['taxonCode'],
                'exists' => null !== $this->taxons->findOneBy(['code' => $arguments['taxonCode']]),
            ];
        }
        $variants = \is_array($arguments['variants'] ?? null) ? \count($arguments['variants']) : 1;

        return [
            'effect' => 'create',
            'summary' => \sprintf('Would create product "%s" with %d variant(s).', $code, $variants),
            'name' => $arguments['name'] ?? null,
            'price' => $arguments['price'] ?? null,
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function update(string $code, ?ProductInterface $product, array $arguments): array
    {
        if (null === $product) {
            return ['effect' => 'none', 'summary' => \sprintf('No product "%s"; the call would report status "not_found".', $code)];
        }

        if (\is_string($arguments['localeCode'] ?? null)) {
            $product->setCurrentLocale($arguments['localeCode']);
        }

        $changes = [];
        foreach (self::TEXT_FIELDS as $field) {
            $new = $arguments[$field] ?? null;
            if (!\is_string($new) || '' === $new) {
                continue; // the tool leaves empty or omitted fields as they are
            }
            $old = match ($field) {
                'description' => $product->getDescription(),
                'shortDescription' => $product->getShortDescription(),
                'metaKeywords' => $product->getMetaKeywords(),
                'metaDescription' => $product->getMetaDescription(),
            };
            if ($old !== $new) {
                $changes[$field] = ['from' => $this->excerpt($old), 'to' => $this->excerpt($new)];
            }
        }

        return [
            'effect' => [] === $changes ? 'none' : 'update',
            'summary' => \sprintf('Would change %d field(s) of product "%s"%s.', \count($changes), $code, $product->isEnabled() ? ', which is LIVE' : ' (disabled, a draft)'),
            'live' => $product->isEnabled(),
            'changes' => $changes,
        ];
    }

    private function excerpt(?string $text): ?string
    {
        if (null === $text || mb_strlen($text) <= $this->excerptLength) {
            return $text;
        }

        return mb_substr($text, 0, $this->excerptLength).'… ['.mb_strlen($text).' chars]';
    }
}
