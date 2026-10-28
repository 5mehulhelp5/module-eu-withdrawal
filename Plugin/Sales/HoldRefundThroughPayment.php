<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Plugin\Sales;

use MageMe\EUWithdrawal\Model\Refund\RefundInFlight;
use MageMe\EUWithdrawal\Model\ResourceModel\Request as RequestResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\RefundAdapterInterface;

/**
 * Around core's payment step of a refund that a withdrawal request's hold covers: renews the hold before
 * and after it, so a slow payment provider does not run the hold out while the refund is still going, and
 * records that the step started, so a failure from here on leaves the outcome unknown. A refund whose hold
 * was lost before the step stops there. Refunds that no withdrawal hold covers pass straight through.
 */
class HoldRefundThroughPayment
{
    /**
     * Constructor.
     *
     * @param RefundInFlight $inFlight
     * @param RequestResource $requestResource
     */
    public function __construct(
        private readonly RefundInFlight $inFlight,
        private readonly RequestResource $requestResource,
    ) {
    }

    /**
     * Around refund.
     *
     * @param RefundAdapterInterface $subject
     * @param callable $proceed
     * @param CreditmemoInterface $creditmemo
     * @param OrderInterface $order
     * @param bool $isOnline
     * @return OrderInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundRefund(
        RefundAdapterInterface $subject,
        callable $proceed,
        CreditmemoInterface $creditmemo,
        OrderInterface $order,
        $isOnline = false,
    ) {
        $refund = $this->inFlight->current();
        if ($refund === null) {
            return $proceed($creditmemo, $order, $isOnline);
        }
        if ($this->requestResource->renewRefundLease($refund['request_id'], $refund['token']) === RequestResource::RENEW_LOST) {
            // The hold ran out or was resolved by staff while this refund waited: another refund may be
            // under way, so this one stops before the payment provider is contacted.
            throw new LocalizedException(
                __('This refund took too long and was stopped before the payment provider was contacted. Reload the withdrawal request and check its refund.'),
            );
        }
        $this->inFlight->markPaymentStarted();
        $result = $proceed($creditmemo, $order, $isOnline);
        $this->requestResource->renewRefundLease($refund['request_id'], $refund['token']);

        return $result;
    }
}
