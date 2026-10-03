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
use Sylius\Component\Core\Model\Taxon;

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

    public function testReadingNeedsAReadScope(): void
    {
        $registry = new Registry();
        (new KohCapabilityLoader())->load($registry);
        $container = new Container();
        $container->set(CatalogueResources::class, $this->resources());
        $inner = new ReadResourceHandler($registry, new ReferenceHandler($container));

        $request = ReadResourceRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => ['uri' => 'sylius://taxons']]);
        $session = new Session(new InMemorySessionStore());

        self::assertInstanceOf(Error::class, (new GuardedReadResourceHandler($inner, self::callers(scopes: [])))->handle($request, $session));
        self::assertInstanceOf(Error::class, (new GuardedReadResourceHandler($inner, self::callers(user: null)))->handle($request, $session));
        self::assertInstanceOf(Response::class, (new GuardedReadResourceHandler($inner, self::callers(scopes: ['mcp:read'])))->handle($request, $session));
    }

    /** @param list<Taxon> $taxons */
    private function resources(array $taxons = []): CatalogueResources
    {
        $taxonRepository = $this->createStub(ObjectRepository::class);
        $taxonRepository->method('findBy')->willReturn($taxons);
        $empty = $this->createStub(ObjectRepository::class);
        $empty->method('findBy')->willReturn([]);

        return new CatalogueResources($empty, $taxonRepository, $empty, $empty);
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
