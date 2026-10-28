<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Period;

use MageMe\EUWithdrawal\Exception\NoDeliveryInfoException;
use MageMe\EUWithdrawal\Model\Order\LatestShipmentDateResolver;
use MageMe\EUWithdrawal\Model\ResourceModel\DeliveryAnchor;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves the 14-day withdrawal-period anchor: the first moment the order
 * reached a merchant-configured "delivery confirmation" status
 * (Stores → Configuration → MageMe Extensions → EU Withdrawal →
 * Withdrawal Period → Delivery Confirmation Status), Art. 9(2)(b) CRD.
 *
 * Sources, earliest wins:
 *  - the module's own record of the transition (Observer\RecordDeliveryAnchor);
 *  - a `sales_order_status_history` row in that status written before the
 *    order's first withdrawal request.
 * An order already in a delivery status with neither (Magento sets Complete
 * without a history row; orders completed before the record existed) falls
 * back to its last shipment. With no status configured, an order that is
 * fully shipped (state complete or closed) uses its last shipment.
 *
 * `NoDeliveryInfoException` when nothing dates the delivery: `PeriodRule`
 * treats that as "clock not started", or applies the unconfirmed-delivery
 * setting when the order is already in a delivery status.
 */
class AnchorResolver
{
    public const XML_DELIVERY_STATUS_CODE = 'mageme_eu_withdrawal/withdrawal_window/delivery_status_code';

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param DeliveryAnchor $deliveryAnchor
     * @param LatestShipmentDateResolver $latestShipment
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DeliveryAnchor $deliveryAnchor,
        private readonly LatestShipmentDateResolver $latestShipment,
    ) {
    }

    /**
     * Resolve.
     *
     * @param OrderInterface $order
     * @param int $storeId
     * @return \DateTimeImmutable
     */
    public function resolve(OrderInterface $order, int $storeId): \DateTimeImmutable
    {
        $rawConfig = (string) $this->scopeConfig->getValue(
            self::XML_DELIVERY_STATUS_CODE,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
        $statuses = $this->parseStatuses($rawConfig);
        $orderId = (int) $order->getEntityId();

        if ($statuses === []) {
            $fullyShipped = in_array((string) $order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED], true);
            $raw = $fullyShipped ? $this->latestShipment->resolve($orderId) : null;
        } else {
            $raw = $this->deliveryAnchor->findFirstReached($orderId, $statuses);
            if ($raw === null && in_array((string) $order->getStatus(), $statuses, true)) {
                $raw = $this->latestShipment->resolve($orderId);
            }
        }
        if ($raw === null || $raw === '') {
            throw new NoDeliveryInfoException();
        }

        try {
            return new \DateTimeImmutable($raw . 'Z', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new NoDeliveryInfoException();
        }
    }

    public function isConfiguredDeliveryStatus(string $status, int $storeId): bool
    {
        if ($status === '') {
            return false;
        }
        $statuses = $this->parseStatuses((string) $this->scopeConfig->getValue(
            self::XML_DELIVERY_STATUS_CODE,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        ));
        return in_array($status, $statuses, true);
    }

    /**
     * Multiselect persists the value as a comma-separated string. Trim, split
     * on commas, drop empties.
     *
     * @return string[]
     */
    private function parseStatuses(string $rawConfig): array
    {
        $rawConfig = trim($rawConfig);
        if ($rawConfig === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $rawConfig));
        return array_values(array_filter($parts, static fn (string $s) => $s !== ''));
    }
}
