<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Resource;

use Doctrine\Persistence\ObjectRepository;
use Koh\SyliusMcp\Mcp\GuardedReadResourceHandler;
use Koh\SyliusMcp\Mcp\KohCapabilityLoader;
use Koh\SyliusMcp\Resource\CatalogueResources;
use Koh\SyliusMcp\Tests\Support\McpSecurityFixtures;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\Container;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Server\Handler\Request\ReadResourceHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Taxon;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Product\Model\ProductAttribute;
use Sylius\Component\Product\Model\ProductOption;
use Sylius\Component\Product\Model\ProductOptionValue;

final class CatalogueResourcesTest extends TestCase
{
    use McpSecurityFixtures;

    public function testTheLoaderRegistersTheResourcesAndPrompts(): void
    {
        $registry = new Registry();
        (new KohCapabilityLoader())->load($registry);

        self::assertSame(
            ['sylius://channels', 'sylius://product-attributes', 'sylius://product-options', 'sylius://taxons'],
            $this->sorted(array_keys($registry->getResources()->references)),
        );
        self::assertSame(['catalogue_import', 'product_copy_review'], $this->sorted(array_keys($registry->getPrompts()->references)));
    }

    /** Codes, because the write tools take codes; the parent, so the tree can be rebuilt. */
    public function testTaxonsAreListedWithCodeAndParent(): void
    {
        $root = $this->taxon('category', 'Kategorie');
        $child = $this->taxon('pfeifen', 'Pfeifen');
        $child->setParent($root);

        $json = json_decode($this->resources(taxons: [$root, $child])->taxons()->text, true);

        self::assertSame([
            ['code' => 'category', 'name' => 'Kategorie', 'slug' => 'category', 'parent' => null, 'enabled' => true],
            ['code' => 'pfeifen', 'name' => 'Pfeifen', 'slug' => 'pfeifen', 'parent' => 'category', 'enabled' => true],
        ], $json['items']);
        self::assertFalse($json['truncated']);
    }

    public function testChannelsListCodesLocalesAndCurrencies(): void
    {
        $locale = new Locale();
        $locale->setCode('de_DE');
        $currency = new Currency();
        $currency->setCode('EUR');
        $channel = new Channel();
        $channel->setCode('budde');
        $channel->setName('Budde');
        $channel->setHostname('shop.example');
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);

        $json = json_decode($this->resources(channels: [$channel, new \stdClass()])->channels()->text, true);

        self::assertSame([[
            'code' => 'budde', 'name' => 'Budde', 'hostname' => 'shop.example', 'enabled' => true,
            'defaultLocale' => 'de_DE', 'locales' => ['de_DE'], 'baseCurrency' => 'EUR', 'currencies' => ['EUR'], 'menuTaxon' => null,
        ]], $json['items']);
    }

    public function testAttributesAndOptionsAreListedByCode(): void
    {
        $attribute = new ProductAttribute();
        $attribute->setCurrentLocale('de_DE');
        $attribute->setFallbackLocale('de_DE');
        $attribute->setCode('strength');
        $attribute->setName('Stärke');
        $attribute->setType('integer');

        $value = new ProductOptionValue();
        $value->setCurrentLocale('de_DE');
        $value->setFallbackLocale('de_DE');
        $value->setCode('box');
        $value->setValue('Kiste');
        $option = new ProductOption();
        $option->setCurrentLocale('de_DE');
        $option->setFallbackLocale('de_DE');
        $option->setCode('packaging');
        $option->setName('Verpackung');
        $option->addValue($value);

        $resources = $this->resources(attributes: [$attribute, new \stdClass()], options: [$option, new \stdClass()]);

        self::assertSame([['code' => 'strength', 'name' => 'Stärke', 'type' => 'integer', 'translatable' => true]], json_decode($resources->attributes()->text, true)['items']);
        self::assertSame([['code' => 'packaging', 'name' => 'Verpackung', 'values' => [['code' => 'box', 'value' => 'Kiste']]]], json_decode($resources->options()->text, true)['items']);
    }

    public function testReadingNeedsAReadScope(): void
    {
        $registry = new Registry();
        (new KohCapabilityLoader())->load($registry);
        $container = new Container();
        $container->set(CatalogueResources::class, $this->resources());
        $inner = new ReadResourceHandler($registry, new ReferenceHandler($container));

        $request = ReadResourceRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => ['uri' => 'sylius://taxons']]);
        $session = new Session(new InMemorySessionStore());

        self::assertTrue((new GuardedReadResourceHandler($inner, self::callers()))->supports($request));
        self::assertInstanceOf(Error::class, (new GuardedReadResourceHandler($inner, self::callers(scopes: [])))->handle($request, $session));
        self::assertInstanceOf(Error::class, (new GuardedReadResourceHandler($inner, self::callers(user: null)))->handle($request, $session));
        self::assertInstanceOf(Response::class, (new GuardedReadResourceHandler($inner, self::callers(scopes: ['mcp:read'])))->handle($request, $session));
    }

    /**
     * @param list<object> $taxons
     * @param list<object> $channels
     * @param list<object> $attributes
     * @param list<object> $options
     */
    private function resources(array $taxons = [], array $channels = [], array $attributes = [], array $options = []): CatalogueResources
    {
        $repository = function (array $rows): ObjectRepository {
            $repository = $this->createStub(ObjectRepository::class);
            $repository->method('findBy')->willReturn($rows);

            return $repository;
        };

        return new CatalogueResources($repository($channels), $repository($taxons), $repository($attributes), $repository($options));
    }

    private function taxon(string $code, string $name): Taxon
    {
        $taxon = new Taxon();
        $taxon->setCurrentLocale('de_DE');
        $taxon->setFallbackLocale('de_DE');
        $taxon->setCode($code);
        $taxon->setName($name);
        $taxon->setSlug($code);

        return $taxon;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
