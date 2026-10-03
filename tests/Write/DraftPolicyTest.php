<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Write;

use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Koh\SyliusMcp\Write\DraftPolicy;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Product;

/**
 * What an agent writes stays off the storefront until a person publishes it.
 */
final class DraftPolicyTest extends TestCase
{
    public function testANewArticleIsNotPublished(): void
    {
        $outcome = (new DraftPolicy())->before('create_article', ['title' => 'T', 'content' => 'C', 'publish' => true]);

        self::assertFalse($outcome->arguments['publish']);
        self::assertNotSame([], $outcome->notes);
    }

    public function testAnAgentMayEditAnArticleButNotPublishIt(): void
    {
        $policy = new DraftPolicy();

        self::assertNotNull($policy->before('update_article', ['code' => 'a', 'enabled' => true])->refusal);
        self::assertNull($policy->before('update_article', ['code' => 'a', 'title' => 'T'])->refusal);
        self::assertNull($policy->before('update_article', ['code' => 'a', 'enabled' => false])->refusal);
    }

    /**
     * update_product may edit a live product (owner's decision); the guard is
     * preview and confirmation, not refusal.
     */
    public function testALiveProductMayBeEdited(): void
    {
        $policy = new DraftPolicy(false, [], $this->repository(['MUG' => $this->product('MUG', true)]));

        self::assertNull($policy->before('update_product', ['code' => 'MUG', 'description' => 'x'])->refusal);
    }

    /** The rule still exists for an application that wants it. */
    public function testAConfiguredRuleRefusesEditsToLiveProducts(): void
    {
        $policy = new DraftPolicy(false, ['update_product' => ['refuse_live_product' => 'code']], $this->repository(['MUG' => $this->product('MUG', true), 'NEW' => $this->product('NEW', false)]));

        self::assertStringContainsString('is live', (string) $policy->before('update_product', ['code' => 'MUG', 'description' => 'x'])->refusal);
        self::assertNull($policy->before('update_product', ['code' => 'NEW', 'description' => 'x'])->refusal);
    }

    /**
     * create_product enables what it creates. The policy disables it right
     * after — only when this call created it.
     */
    public function testACreatedProductIsDisabledAfterwards(): void
    {
        $product = $this->product('NEW', true);
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('flush');
        $policy = new DraftPolicy(false, [], $this->repository(['NEW' => $product]), $manager);

        $note = $policy->after('create_product', ['code' => 'NEW'], ['code' => 'NEW', 'status' => 'created']);

        self::assertFalse($product->isEnabled());
        self::assertStringContainsString('draft', (string) $note);
    }

    public function testAnExistingProductIsLeftAlone(): void
    {
        $product = $this->product('OLD', true);
        $policy = new DraftPolicy(false, [], $this->repository(['OLD' => $product]));

        self::assertNull($policy->after('import_product', ['code' => 'OLD'], ['code' => 'OLD', 'status' => 'exists']));
        self::assertTrue($product->isEnabled());
    }

    public function testLiveModeChangesNothing(): void
    {
        $product = $this->product('NEW', true);
        $policy = new DraftPolicy(true, [], $this->repository(['NEW' => $product]));

        self::assertTrue($policy->before('create_article', ['publish' => true])->arguments['publish']);
        self::assertNull($policy->before('update_article', ['enabled' => true])->refusal);
        self::assertNull($policy->after('create_product', ['code' => 'NEW'], ['status' => 'created']));
        self::assertTrue($product->isEnabled());
    }

    public function testConfiguredRulesExtendTheBuiltInOnes(): void
    {
        $policy = new DraftPolicy(false, ['my_tool' => ['force' => ['visible' => false]], 'create_article' => ['refuse' => ['category' => 'Ads']]]);

        self::assertFalse($policy->before('my_tool', ['visible' => true])->arguments['visible']);
        self::assertNotNull($policy->before('create_article', ['category' => 'Ads'])->refusal);
        self::assertFalse($policy->before('create_article', ['category' => 'News', 'publish' => true])->arguments['publish'], 'the built-in force stays');
    }

    private function product(string $code, bool $enabled): Product
    {
        $product = new Product();
        $product->setCode($code);
        $product->setEnabled($enabled);

        return $product;
    }

    /**
     * @param array<string, Product> $products
     *
     * @return ObjectRepository<object>
     */
    private function repository(array $products): ObjectRepository
    {
        $repository = $this->createStub(ObjectRepository::class);
        $repository->method('findOneBy')->willReturnCallback(static fn (array $criteria): ?Product => $products[$criteria['code']] ?? null);

        return $repository;
    }
}
