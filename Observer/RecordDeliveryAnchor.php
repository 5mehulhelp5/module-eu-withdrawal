<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use MageMe\EUWithdrawal\Model\Period\AnchorResolver;
use MageMe\EUWithdrawal\Model\ResourceModel\DeliveryAnchor;
use Magento\Framework\DB\Adapter\ConnectionException;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Records when an order reaches a delivery confirmation status. Magento moves an order to Complete
 * after the last shipment or invoice without a status-history row, so the status history alone cannot
 * date that moment.
 */
class RecordDeliveryAnchor implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param AnchorResolver $anchorResolver
     * @param DeliveryAnchor $deliveryAnchor
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AnchorResolver $anchorResolver,
        private readonly DeliveryAnchor $deliveryAnchor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getData('order');
            if (!$order instanceof Order || (int) $order->getEntityId() <= 0) {
                return;
            }
            // An order saved without being loaded first (some imports and API saves) has no original
            // status: its status may well be unchanged, so nothing is recorded and the period falls back
            // to the last shipment.
            $original = $order->getOrigData();
            if (!is_array($original) || !array_key_exists('status', $original)) {
                return;
            }
            $status = (string) $order->getStatus();
            if ($status === (string) $original['status']) {
                return;
            }
            if (!$this->anchorResolver->isConfiguredDeliveryStatus($status, (int) $order->getStoreId())) {
                return;
            }
            $this->deliveryAnchor->recordFirst((int) $order->getEntityId(), $status, gmdate('Y-m-d H:i:s'));
        } catch (DeadlockException | LockWaitException | ConnectionException $e) {
            // The order-save transaction may be gone (a deadlock rolls it back, a lock wait timeout can, a lost
            // connection does): the save must fail, not carry on.
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EUWithdrawal: failed to record the delivery anchor: ' . $e->getMessage(),
                ['order_id' => isset($order) && $order instanceof Order ? (int) $order->getEntityId() : null],
            );
        }
    }
}
