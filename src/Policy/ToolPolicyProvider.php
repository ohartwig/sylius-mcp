<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Policy;

/**
 * Resolves the {@see ToolPolicy} for a tool name.
 *
 * Three sources, in rising precedence: the class default for unknown tools,
 * the built-in table below, and `koh_sylius_mcp.tools.<name>` from the
 * application. The table covers the tools of sylius/mcp-server-plugin, the
 * tools of this package, and the catalogue and magazine tools the commerce
 * application registers itself — they are not ours to annotate in their own
 * code, so they are classified here by name. A name that is in none of them is
 * treated as {@see self::$defaultClass}, high risk unless configured otherwise:
 * a tool nobody has looked at is not assumed to be harmless.
 */
final class ToolPolicyProvider
{
    /** @var array<string, array{class: ToolClass, idempotent?: bool, destructive?: bool}> */
    private const BUILT_IN = [
        // sylius/mcp-server-plugin — read
        'search_products' => ['class' => ToolClass::Read],
        'fetch_product' => ['class' => ToolClass::Read],
        'search_product_variants' => ['class' => ToolClass::Read],
        'fetch_product_variant' => ['class' => ToolClass::Read],
        'fetch_channel' => ['class' => ToolClass::Read],
        'fetch_currency' => ['class' => ToolClass::Read],
        'list_payment_methods' => ['class' => ToolClass::Read],
        'list_shipping_methods' => ['class' => ToolClass::Read],
        'fetch_order' => ['class' => ToolClass::Read],
        // sylius/mcp-server-plugin — guest cart. A cart is not an order and
        // nobody but the agent holding its token sees it.
        'create_order' => ['class' => ToolClass::Write, 'idempotent' => false, 'destructive' => false],
        'add_item_to_order' => ['class' => ToolClass::Write, 'idempotent' => false, 'destructive' => false],
        'update_order_address' => ['class' => ToolClass::Write, 'idempotent' => true, 'destructive' => true],
        'select_payment_method' => ['class' => ToolClass::Write, 'idempotent' => true, 'destructive' => true],
        'select_shipping_method' => ['class' => ToolClass::Write, 'idempotent' => true, 'destructive' => true],
        // Places a real order. Financial transactions are not permitted for
        // agents (handbook, agent governance), hence high risk.
        'complete_checkout' => ['class' => ToolClass::HighRisk, 'idempotent' => false, 'destructive' => true],
        // koh/sylius-mcp
        'content_product_search' => ['class' => ToolClass::Read],
        // commerce application (src/Mcp/Tool)
        'list_articles' => ['class' => ToolClass::Read],
        'create_product' => ['class' => ToolClass::HighRisk, 'idempotent' => true, 'destructive' => false],
        'import_product' => ['class' => ToolClass::HighRisk, 'idempotent' => true, 'destructive' => false],
        'update_product' => ['class' => ToolClass::HighRisk, 'idempotent' => true, 'destructive' => true],
        'create_article' => ['class' => ToolClass::HighRisk, 'idempotent' => false, 'destructive' => false],
        'update_article' => ['class' => ToolClass::HighRisk, 'idempotent' => true, 'destructive' => true],
    ];

    /** @var array<string, ToolPolicy> */
    private array $resolved = [];

    private readonly ToolClass $defaultClass;

    /**
     * @param array<string, array{enabled?: bool, class?: string|null, idempotent?: bool|null, destructive?: bool|null, untrusted?: bool|null}> $overrides
     */
    public function __construct(
        private readonly array $overrides = [],
        ToolClass|string $defaultClass = ToolClass::HighRisk,
    ) {
        $this->defaultClass = $defaultClass instanceof ToolClass ? $defaultClass : ToolClass::from($defaultClass);
    }

    public function get(string $name): ToolPolicy
    {
        return $this->resolved[$name] ??= $this->resolve($name);
    }

    /** @return list<string> every name the provider knows without being asked */
    public function knownNames(): array
    {
        return array_values(array_unique([...array_keys(self::BUILT_IN), ...array_keys($this->overrides)]));
    }

    private function resolve(string $name): ToolPolicy
    {
        $builtIn = self::BUILT_IN[$name] ?? null;
        $override = $this->overrides[$name] ?? [];

        $class = isset($override['class'])
            ? ToolClass::from($override['class'])
            : ($builtIn['class'] ?? $this->defaultClass);

        return new ToolPolicy(
            name: $name,
            class: $class,
            enabled: $override['enabled'] ?? true,
            idempotent: $override['idempotent'] ?? $builtIn['idempotent'] ?? null,
            destructive: $override['destructive'] ?? $builtIn['destructive'] ?? null,
            untrusted: $override['untrusted'] ?? true,
            unknown: null === $builtIn && [] === $override,
        );
    }
}
