<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Write;

use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Sylius\Resource\Model\ToggleableInterface;

/**
 * Keeps agent writes off the storefront until a human publishes them.
 *
 * TYPO3 has workspaces: an MCP write lands in a draft and goes live only when
 * someone publishes it. Sylius has no draft state for a product or an
 * article, but it has the next best thing — the enabled flag. A disabled
 * product is in the catalogue and in the admin, and not in the shop.
 *
 * With `koh_sylius_mcp.writes.live: false` (the default) every write tool
 * that has a rule here runs in draft mode:
 *
 *  - `force`: arguments overridden before the call, e.g. create_article
 *    `publish: false`;
 *  - `refuse`: argument values that are refused, e.g. update_article
 *    `enabled: true` — an agent may edit, not publish;
 *  - `disable_created_product`: after a call that reports `status: created`,
 *    the product named by that argument is disabled. create_product and
 *    import_product enable what they create, and they belong to the
 *    application, not to this package; this is the hook that works without
 *    changing them;
 *  - `refuse_live_product`: the call is refused if the product named by that
 *    argument is enabled. No built-in rule uses it: update_product may edit
 *    a live product (owner's decision), guarded by preview and confirmation
 *    as a high-risk tool. It is there for an application that wants it.
 *
 * Publishing — enabling the product or article — is a human's step in the
 * admin. With `live: true` none of this applies.
 */
final class DraftPolicy
{
    /** @var array<string, array{force?: array<string, mixed>, refuse?: array<string, mixed>, disable_created_product?: string, refuse_live_product?: string}> */
    public const BUILT_IN = [
        'create_product' => ['disable_created_product' => 'code'],
        'import_product' => ['disable_created_product' => 'code'],
        'create_article' => ['force' => ['publish' => false]],
        'update_article' => ['refuse' => ['enabled' => true]],
    ];

    /** @var array<string, array{force?: array<string, mixed>, refuse?: array<string, mixed>, disable_created_product?: string, refuse_live_product?: string}> */
    private readonly array $rules;

    /**
     * @param array<string, array{force?: array<string, mixed>, refuse?: array<string, mixed>, disable_created_product?: string|null, refuse_live_product?: string|null}> $rules merged over {@see self::BUILT_IN}
     * @param ObjectRepository<object>|null                                                                                                                                $products
     */
    public function __construct(
        private readonly bool $live = false,
        array $rules = [],
        private readonly ?ObjectRepository $products = null,
        private readonly ?ObjectManager $manager = null,
    ) {
        $merged = self::BUILT_IN;
        foreach ($rules as $tool => $rule) {
            $base = $merged[$tool] ?? [];
            foreach (['force', 'refuse'] as $key) {
                if (isset($rule[$key]) && [] !== $rule[$key]) {
                    $base[$key] = $rule[$key];
                }
            }
            foreach (['disable_created_product', 'refuse_live_product'] as $key) {
                if (isset($rule[$key]) && '' !== $rule[$key]) {
                    $base[$key] = $rule[$key];
                }
            }
            $merged[$tool] = $base;
        }
        $this->rules = $merged;
    }

    public function isLive(): bool
    {
        return $this->live;
    }

    public function governs(string $tool): bool
    {
        return !$this->live && isset($this->rules[$tool]);
    }

    /**
     * Arguments as the tool will receive them, or a refusal.
     *
     * @param array<string, mixed> $arguments
     */
    public function before(string $tool, array $arguments): DraftOutcome
    {
        if (!$this->governs($tool)) {
            return new DraftOutcome($arguments);
        }
        $rule = $this->rules[$tool];
        $notes = [];

        foreach ($rule['refuse'] ?? [] as $name => $value) {
            if (\array_key_exists($name, $arguments) && $arguments[$name] === $value) {
                return DraftOutcome::refused(\sprintf(
                    '%s: %s is not allowed while MCP writes are kept as drafts. A person publishes in the admin.',
                    $name,
                    json_encode($value),
                ));
            }
        }

        if (isset($rule['refuse_live_product'])) {
            $product = $this->product($arguments[$rule['refuse_live_product']] ?? null);
            if ($product instanceof ToggleableInterface && $product->isEnabled()) {
                return DraftOutcome::refused(\sprintf(
                    'Product "%s" is live; a change would be visible in the shop at once. MCP writes are kept as drafts: disable the product in the admin first, or ask the operator for live writes.',
                    (string) $arguments[$rule['refuse_live_product']],
                ));
            }
        }

        foreach ($rule['force'] ?? [] as $name => $value) {
            if (($arguments[$name] ?? null) !== $value) {
                $notes[] = \sprintf('%s set to %s (draft mode).', $name, json_encode($value));
            }
            $arguments[$name] = $value;
        }

        if (isset($rule['disable_created_product'])) {
            $notes[] = 'A product this call creates is saved disabled — a draft. A person enables it in the admin after review.';
        }

        return new DraftOutcome($arguments, null, $notes);
    }

    /**
     * Runs after a successful call. Returns a note for the agent when it
     * changed something.
     *
     * @param array<string, mixed>      $arguments
     * @param array<mixed, mixed>|null  $structured the tool's structured result
     */
    public function after(string $tool, array $arguments, ?array $structured): ?string
    {
        if (!$this->governs($tool) || !isset($this->rules[$tool]['disable_created_product'])) {
            return null;
        }
        if (($structured['status'] ?? null) !== 'created') {
            // "exists": the product was there before this call, and whether it
            // is live is not this call's doing.
            return null;
        }

        $code = $arguments[$this->rules[$tool]['disable_created_product']] ?? null;
        $product = $this->product($code);
        if (!$product instanceof ToggleableInterface) {
            return null;
        }

        $product->disable();
        $this->manager?->flush();

        return \sprintf('Product "%s" was saved as a draft (disabled). It is not in the shop until a person enables it in the admin.', (string) $code);
    }

    private function product(mixed $code): ?object
    {
        if (!\is_string($code) || '' === $code || null === $this->products) {
            return null;
        }

        return $this->products->findOneBy(['code' => $code]);
    }
}
