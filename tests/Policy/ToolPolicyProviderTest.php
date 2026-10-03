<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Policy;

use Koh\SyliusMcp\Policy\ToolClass;
use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Mcp\Schema\ToolAnnotations;
use PHPUnit\Framework\TestCase;

final class ToolPolicyProviderTest extends TestCase
{
    public function testThePluginsCartToolsAreLowRiskWritesAndCheckoutIsHighRisk(): void
    {
        $policies = new ToolPolicyProvider();

        self::assertSame(ToolClass::Read, $policies->get('fetch_order')->class);
        self::assertSame(ToolClass::Write, $policies->get('add_item_to_order')->class);
        self::assertSame(ToolClass::HighRisk, $policies->get('complete_checkout')->class);
    }

    public function testTheApplicationsCatalogueToolsAreHighRisk(): void
    {
        $policies = new ToolPolicyProvider();

        foreach (['create_product', 'import_product', 'update_product', 'create_article', 'update_article'] as $name) {
            self::assertSame(ToolClass::HighRisk, $policies->get($name)->class, $name);
        }
    }

    /** A tool nobody has classified is not assumed to be harmless. */
    public function testAnUnknownToolIsHighRiskByDefault(): void
    {
        $policy = (new ToolPolicyProvider())->get('delete_everything');

        self::assertSame(ToolClass::HighRisk, $policy->class);
        self::assertTrue($policy->unknown);
        self::assertTrue($policy->enabled);
    }

    public function testTheDefaultClassForUnknownToolsIsConfigurable(): void
    {
        self::assertSame(ToolClass::Read, (new ToolPolicyProvider([], 'read'))->get('anything')->class);
    }

    public function testConfigurationOverridesTheBuiltInTable(): void
    {
        $policies = new ToolPolicyProvider([
            'search_products' => ['enabled' => false],
            'create_order' => ['class' => 'high_risk', 'idempotent' => true],
        ]);

        self::assertFalse($policies->get('search_products')->enabled);
        self::assertSame(ToolClass::HighRisk, $policies->get('create_order')->class);
        self::assertTrue($policies->get('create_order')->idempotent);
        self::assertFalse($policies->get('create_order')->unknown);
    }

    public function testReadToolsAreAnnotatedAsSafe(): void
    {
        $hints = (new ToolPolicyProvider())->get('search_products')->annotations();

        self::assertTrue($hints->readOnlyHint);
        self::assertFalse($hints->destructiveHint);
        self::assertTrue($hints->idempotentHint);
        self::assertFalse($hints->openWorldHint);
    }

    public function testWriteToolsCarryTheirOwnHints(): void
    {
        $hints = (new ToolPolicyProvider())->get('complete_checkout')->annotations();

        self::assertFalse($hints->readOnlyHint);
        self::assertTrue($hints->destructiveHint);
        self::assertFalse($hints->idempotentHint);
    }

    /**
     * The policy is what the server enforces; a hint from the tool that
     * contradicts it would describe a tool the server does not serve.
     */
    public function testThePolicyWinsOverTheToolsOwnHintsButKeepsItsTitle(): void
    {
        $own = new ToolAnnotations(title: 'Checkout', readOnlyHint: true, destructiveHint: false);

        $hints = (new ToolPolicyProvider())->get('complete_checkout')->annotations($own);

        self::assertSame('Checkout', $hints->title);
        self::assertFalse($hints->readOnlyHint);
        self::assertTrue($hints->destructiveHint);
    }

    public function testAnUnclassifiedWriteToolIsAssumedDestructiveAndNotIdempotent(): void
    {
        $hints = (new ToolPolicyProvider())->get('unknown_tool')->annotations();

        self::assertTrue($hints->destructiveHint);
        self::assertFalse($hints->idempotentHint);
    }
}
