<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Support;

use Doctrine\Common\Collections\ArrayCollection;
use Koh\SyliusMcp\Security\CatalogueVisibility;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;

trait CatalogueFixtures
{
    use McpSecurityFixtures;

    private ?ChannelInterface $channel = null;

    private function shopChannel(): ChannelInterface
    {
        return $this->channel ??= $this->createStub(ChannelInterface::class);
    }

    /** @param list<string> $scopes */
    private function visibility(bool $channelResolves = true, array $scopes = ['mcp'], array $granted = ['ROLE_ADMINISTRATION_ACCESS']): CatalogueVisibility
    {
        $context = $this->createStub(ChannelContextInterface::class);
        if ($channelResolves) {
            $context->method('getChannel')->willReturn($this->shopChannel());
        } else {
            $context->method('getChannel')->willThrowException(new ChannelNotFoundException());
        }

        return new CatalogueVisibility($context, self::decider($granted), self::callers(scopes: $scopes));
    }

    private function product(string $code, bool $enabled = true, bool $inChannel = true): ProductInterface
    {
        $product = $this->createStub(ProductInterface::class);
        $product->method('getCode')->willReturn($code);
        $product->method('getName')->willReturn('Product '.$code);
        $product->method('getSlug')->willReturn(strtolower($code));
        $product->method('isEnabled')->willReturn($enabled);
        $product->method('hasChannel')->willReturn($inChannel);
        $product->method('getVariants')->willReturn(new ArrayCollection());

        return $product;
    }
}
