<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Write\Preview;

use Doctrine\Persistence\ObjectRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * Previews complete_checkout: the order that would be placed — items, total,
 * payment and shipping method — so the person confirming knows what they are
 * buying. The customer's e-mail and address stay out of it.
 */
final class CheckoutPreview implements ToolPreviewInterface
{
    /** @param ObjectRepository<object> $orders */
    public function __construct(
        private readonly ObjectRepository $orders,
    ) {
    }

    public function supports(string $tool): bool
    {
        return 'complete_checkout' === $tool;
    }

    public function preview(string $tool, array $arguments): array
    {
        $token = $arguments['tokenValue'] ?? null;
        $order = \is_string($token) && '' !== $token ? $this->orders->findOneBy(['tokenValue' => $token]) : null;
        if (!$order instanceof OrderInterface) {
            return ['effect' => 'none', 'summary' => 'No cart with this token; the call would fail.'];
        }

        $shipment = $order->getShipments()->first();

        return [
            'effect' => 'place_order',
            'summary' => \sprintf(
                'Would place a binding order of %d item(s), total %s %s.',
                $order->getTotalQuantity(),
                number_format($order->getTotal() / 100, 2, '.', ''),
                (string) $order->getCurrencyCode(),
            ),
            'checkoutState' => $order->getCheckoutState(),
            'items' => array_values(array_map(
                static fn (OrderItemInterface $item): array => [
                    'variant' => $item->getVariant()?->getCode(),
                    'name' => $item->getVariantName() ?? $item->getProductName(),
                    'quantity' => $item->getQuantity(),
                    'total' => $item->getTotal(),
                ],
                $order->getItems()->toArray(),
            )),
            'total' => $order->getTotal(),
            'currency' => $order->getCurrencyCode(),
            'payment' => $order->getLastPayment()?->getMethod()?->getCode(),
            'shipping' => $shipment instanceof ShipmentInterface ? $shipment->getMethod()?->getCode() : null,
        ];
    }
}
