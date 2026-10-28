<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Period;

use MageMe\EUWithdrawal\Exception\NoDeliveryInfoException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Single source of truth for "when was this order delivered" — the signal the
 * storefront list, order-meta display and eligibility window all share, kept
 * consistent with PeriodRule's deadline anchor.
 */
class DeliveryDateResolver
{
    /**
     * Constructor.
     *
     * @param AnchorResolver $anchorResolver
     */
    public function __construct(
        private readonly AnchorResolver $anchorResolver,
    ) {
    }

    /**
     * Delivered-at timestamp (UTC `Y-m-d H:i:s`) — the same anchor PeriodRule
     * uses — or null when the order has not been delivered yet.
     *
     * @param OrderInterface $order
     * @return ?string
     */
    public function resolve(OrderInterface $order): ?string
    {
        try {
            return $this->anchorResolver->resolve($order, (int) $order->getStoreId())->format('Y-m-d H:i:s');
        } catch (NoDeliveryInfoException) {
            return null;
        }
    }
}
