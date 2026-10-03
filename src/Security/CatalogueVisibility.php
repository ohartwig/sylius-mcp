<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Security;

use Koh\SyliusMcp\Policy\ToolClass;
use Koh\SyliusMcp\Policy\ToolPolicy;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Product\Model\ProductInterface as BaseProductInterface;

/**
 * Which products the read tools of this package may show.
 *
 * By default what the channel's storefront shows: enabled, and assigned to
 * the channel the request came in on. The repository the tools read from
 * knows neither — it returned disabled products and products of other
 * channels to anyone holding a token.
 *
 * Unpublished products (disabled, the catalogue's drafts) are shown only on
 * request and only to a caller who may change the catalogue: someone
 * reviewing a draft has to see it, someone who can only read the shop has no
 * business with it.
 */
final class CatalogueVisibility
{
    public function __construct(
        private readonly ChannelContextInterface $channelContext,
        private readonly ToolAccessDecider $access,
        private readonly McpCallerResolver $callers,
    ) {
    }

    /**
     * The channel the request is for, or null if none can be resolved — in
     * which case nothing is visible: a product list without a channel is a
     * list of every channel's products.
     */
    public function channel(): ?ChannelInterface
    {
        try {
            $channel = $this->channelContext->getChannel();
        } catch (ChannelNotFoundException) {
            return null;
        }

        return $channel instanceof ChannelInterface ? $channel : null;
    }

    public function mayIncludeUnpublished(): bool
    {
        // Asked as "could this caller run a high-risk tool?" — the same rule
        // the catalogue tools are checked against, not a second one that
        // could drift from it. The name is not a tool, so only the class
        // roles apply.
        return $this->access->decide(new ToolPolicy('catalogue.unpublished', ToolClass::HighRisk), $this->callers->resolve())->allowed;
    }

    public function isVisible(BaseProductInterface $product, bool $includeUnpublished = false): bool
    {
        $channel = $this->channel();
        if (null === $channel || !$product instanceof ProductInterface || !$product->hasChannel($channel)) {
            return false;
        }

        return $product->isEnabled() || ($includeUnpublished && $this->mayIncludeUnpublished());
    }
}
