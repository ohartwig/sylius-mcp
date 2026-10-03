<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Resource;

use Doctrine\Persistence\ObjectRepository;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Schema\Content\TextResourceContents;
use Sylius\Component\Attribute\Model\AttributeInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Product\Model\ProductOptionInterface;
use Sylius\Component\Product\Model\ProductOptionValueInterface;

/**
 * The shop's structure as MCP resources: channels, the taxon tree, product
 * attributes and options.
 *
 * Context an agent needs before it writes — which channel codes exist, which
 * taxon a product goes under, which attribute codes there are — and would
 * otherwise have to guess, or fish out of product data one call at a time.
 * Codes are what the write tools take; names are there to recognise them.
 *
 * Registered by {@see \Koh\SyliusMcp\Mcp\KohCapabilityLoader}; read access is
 * checked by {@see \Koh\SyliusMcp\Mcp\GuardedReadResourceHandler}.
 */
final class CatalogueResources
{
    /** Upper bound per resource — a context window is not a database export. */
    public const MAX_ROWS = 500;

    /**
     * @param ObjectRepository<object> $channels
     * @param ObjectRepository<object> $taxons
     * @param ObjectRepository<object> $attributes
     * @param ObjectRepository<object> $options
     */
    public function __construct(
        private readonly ObjectRepository $channels,
        private readonly ObjectRepository $taxons,
        private readonly ObjectRepository $attributes,
        private readonly ObjectRepository $options,
    ) {
    }

    #[McpResource(uri: 'sylius://channels', name: 'channels', description: 'Sales channels: code, name, hostname, enabled, locales and currencies.', mimeType: 'application/json')]
    public function channels(): TextResourceContents
    {
        $rows = [];
        foreach ($this->channels->findBy([], ['code' => 'ASC'], self::MAX_ROWS) as $channel) {
            if (!$channel instanceof ChannelInterface) {
                continue;
            }
            $rows[] = [
                'code' => $channel->getCode(),
                'name' => $channel->getName(),
                'hostname' => $channel->getHostname(),
                'enabled' => $channel->isEnabled(),
                'defaultLocale' => $channel->getDefaultLocale()?->getCode(),
                'locales' => array_values(array_map(static fn (LocaleInterface $l): ?string => $l->getCode(), $channel->getLocales()->toArray())),
                'baseCurrency' => $channel->getBaseCurrency()?->getCode(),
                'currencies' => array_values(array_map(static fn (CurrencyInterface $c): ?string => $c->getCode(), $channel->getCurrencies()->toArray())),
                'menuTaxon' => $channel->getMenuTaxon()?->getCode(),
            ];
        }

        return self::json('sylius://channels', $rows);
    }

    #[McpResource(uri: 'sylius://taxons', name: 'taxons', description: 'The taxon (category) tree: code, name, slug, parent code, enabled. Pass a code as taxonCode to the product tools.', mimeType: 'application/json')]
    public function taxons(): TextResourceContents
    {
        $rows = [];
        foreach ($this->taxons->findBy([], ['left' => 'ASC'], self::MAX_ROWS) as $taxon) {
            if (!$taxon instanceof TaxonInterface) {
                continue;
            }
            $rows[] = [
                'code' => $taxon->getCode(),
                'name' => $taxon->getName(),
                'slug' => $taxon->getSlug(),
                'parent' => $taxon->getParent()?->getCode(),
                'enabled' => $taxon->isEnabled(),
            ];
        }

        return self::json('sylius://taxons', $rows);
    }

    #[McpResource(uri: 'sylius://product-attributes', name: 'product-attributes', description: 'Product attributes: code, name, type and whether values are translatable.', mimeType: 'application/json')]
    public function attributes(): TextResourceContents
    {
        $rows = [];
        foreach ($this->attributes->findBy([], ['position' => 'ASC'], self::MAX_ROWS) as $attribute) {
            if (!$attribute instanceof AttributeInterface) {
                continue;
            }
            $rows[] = [
                'code' => $attribute->getCode(),
                'name' => $attribute->getName(),
                'type' => $attribute->getType(),
                'translatable' => $attribute->isTranslatable(),
            ];
        }

        return self::json('sylius://product-attributes', $rows);
    }

    #[McpResource(uri: 'sylius://product-options', name: 'product-options', description: 'Product options (the axes variants differ along, e.g. size, packaging) with their value codes.', mimeType: 'application/json')]
    public function options(): TextResourceContents
    {
        $rows = [];
        foreach ($this->options->findBy([], ['position' => 'ASC'], self::MAX_ROWS) as $option) {
            if (!$option instanceof ProductOptionInterface) {
                continue;
            }
            $rows[] = [
                'code' => $option->getCode(),
                'name' => $option->getName(),
                'values' => array_values(array_map(
                    static fn (ProductOptionValueInterface $value): array => ['code' => $value->getCode(), 'value' => $value->getValue()],
                    $option->getValues()->toArray(),
                )),
            ];
        }

        return self::json('sylius://product-options', $rows);
    }

    /** @param list<array<string, mixed>> $rows */
    private static function json(string $uri, array $rows): TextResourceContents
    {
        return new TextResourceContents(
            $uri,
            'application/json',
            json_encode([
                'truncated' => \count($rows) >= self::MAX_ROWS,
                'items' => $rows,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        );
    }
}
