<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Api\CreditmemoIssuerInterface;
use MageMe\EUWithdrawal\Api\Data\CreditmemoIssueResultInterface;
use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\Refund\Source\IssueOutcome;
use MageMe\EUWithdrawal\Model\ResourceModel\Request as RequestResource;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundInvoiceInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoDocumentFactory;
use Psr\Log\LoggerInterface;

/**
 * Issues a withdrawal request's refund as a credit memo without any admin
 * session in play. Builds the plan, previews the memo against the frozen
 * entitlement as a pre-commit gate, then commits it - online through core's
 * RefundInvoice, offline through CreditmemoManagement - and links the result
 * back onto the request.
 */
class HeadlessCreditmemoIssuer implements CreditmemoIssuerInterface
{
    public function __construct(
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly CreditmemoPlanBuilder $planBuilder,
        private readonly CreditmemoDocumentFactory $creditmemoDocumentFactory,
        private readonly RefundInvoiceInterface $refundInvoice,
        private readonly CreditmemoManagementInterface $creditmemoManagement,
        private readonly CreditmemoItemCreationInterfaceFactory $itemCreationFactory,
        private readonly CreditmemoCreationArgumentsInterfaceFactory $argsFactory,
        private readonly RequestResource $requestResource,
        private readonly LoggerInterface $logger,
        private readonly RefundInFlight $inFlight,
        private readonly DoubleRefundAlert $doubleRefundAlert,
    ) {
    }

    public function issue(int $requestId, bool $online): CreditmemoIssueResultInterface
    {
        $request = $this->requestRepository->get($requestId);
        if ($request->getRefundCreditmemoId() !== null) {
            return new CreditmemoIssueResult(IssueOutcome::ALREADY_DONE, $request->getRefundCreditmemoId());
        }
        // A manual paid mark means the refund was already issued outside the request
        // (offline/bank/external). Never auto-issue a memo on top of it; leave it for
        // a human to confirm rather than paying the customer twice.
        if ($request->getReimbursementPaidAt() !== null) {
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'already reimbursed manually');
        }

        $order = $this->orderRepository->get($request->getOrderId());
        // Core's CreditmemoDocumentFactory rebinds the invoice onto this same
        // repository-cached order further down; its item collection must be
        // keyed by item id for the memo build to resolve items by id.
        $order->setData('items', $order->getItemsCollection()->getItems());
        $plan = $this->planBuilder->build($request, $order);
        if (!$plan->isAutoIssuable()) {
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, $plan->manualReason);
        }

        if ($this->existingMemoCoversWithdrawnItems($order, $plan->items)) {
            $this->logger->warning('EUWithdrawal auto-memo found existing memo on order; routing to manual', [
                'request_id' => $requestId,
            ]);
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'existing memo on order');
        }

        $items = [];
        foreach ($plan->items as $oid => $qty) {
            $ci = $this->itemCreationFactory->create();
            $ci->setOrderItemId((int) $oid);
            $ci->setQty((float) $qty);
            $items[] = $ci;
        }
        $args = $this->argsFactory->create();
        if ($plan->shippingAmount !== null) {
            $args->setShippingAmount($plan->shippingAmount);
        }

        try {
            $invoice = $this->invoiceRepository->get($plan->invoiceId);
            // Invoice item quantities come back from storage as strings; core's
            // own memo-quantity resolution mixes them with float order quantities
            // via min(), which does not normalize the result type.
            foreach ($invoice->getAllItems() as $invoiceItem) {
                $invoiceItem->setQty((float) $invoiceItem->getQty());
            }
            $preview = $this->creditmemoDocumentFactory->createFromInvoice($invoice, $items, null, false, $args);
        } catch (\Throwable $e) {
            $this->logger->warning('EUWithdrawal auto-memo preview build failed; routing to manual', [
                'request_id' => $requestId,
                'exception' => $e->getMessage(),
            ]);
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'memo build failed');
        }

        // $plan->expectedTotal (frozen total_refund) is order currency, not base;
        // compare like for like here rather than against getBaseGrandTotal().
        $previewTotal = (float) $preview->getGrandTotal();
        if (abs($previewTotal - $plan->expectedTotal) > 0.01) {
            $this->logger->warning('EUWithdrawal auto-memo gate mismatch; routing to manual', [
                'request_id' => $requestId, 'preview' => $previewTotal, 'expected' => $plan->expectedTotal,
            ]);
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'gate mismatch');
        }

        // canRefund() is the ONLINE-refund capability flag: it reports whether the
        // method instance can send a refund to a gateway, and every built-in
        // offline tender leaves it false. It therefore gates the online lane only.
        $payment = $order->getPayment();
        if ($payment === null) {
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'refund not available');
        }
        if ($online && !$payment->canRefund()) {
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'online not available');
        }

        $token = bin2hex(random_bytes(16));
        if (!$this->requestResource->claimRefundIssue($requestId, $token)) {
            return $this->unclaimedOutcome($requestId, $order, $plan->items);
        }

        // While the claim holds the request, staff see the refund in progress and wait. A failure before
        // core's payment step, or of an offline memo, moved no money and ends the hold at once: staff see
        // the issue as interrupted. An online refund that fails after it may have paid out: the outcome
        // stays unknown until staff check with the payment provider.
        $started = false;
        try {
            $this->inFlight->start($requestId, $token);
            $started = true;
            $creditmemoId = $online
                ? (int) $this->refundInvoice->execute($plan->invoiceId, $items, true, false, false, null, $args)
                : $this->commitOffline($preview);
            $linked = $this->requestResource->linkRefundCreditmemo($requestId, $creditmemoId, $token);
        } catch (\Throwable $e) {
            if ($started && $online && $this->inFlight->hasPaymentStarted()) {
                if (!$this->requestResource->markRefundOutcomeUnknown($requestId, $token)) {
                    $this->doubleRefundAlert->raiseLateFailure($requestId);
                }
            } else {
                $this->requestResource->releaseRefundIssue($requestId, $token);
            }
            throw $e;
        } finally {
            if ($started) {
                $this->inFlight->finish();
            }
        }
        if (!$linked) {
            // Staff recorded the refund another way while this issue ran: they sort out the two records.
            $this->requestResource->releaseRefundIssue($requestId, $token);
            $this->doubleRefundAlert->raise($requestId, $creditmemoId);
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'memo not linked');
        }
        return new CreditmemoIssueResult(IssueOutcome::ISSUED, $creditmemoId);
    }

    /**
     * Commits the already-previewed memo offline, moving no money.
     *
     * Core's RefundInvoiceInterface is not usable here: its validator chain runs
     * Invoice\Validation\CanRefund, which rejects any non-Free tender reporting
     * canRefund() === false irrespective of the online flag. CreditmemoManagement
     * is the API behind the Admin "Refund Offline" button and carries no such
     * check, so it is the only route that issues an offline memo on an offline
     * tender. It commits the very document the amount gate above approved.
     */
    private function commitOffline(Creditmemo $preview): int
    {
        $this->creditmemoManagement->refund($preview, true);

        return (int) $preview->getId();
    }

    /**
     * The claim failed: the refund was linked or marked paid in the meantime,
     * or another issue claimed the request first. That issue may still be
     * committing its memo or may have stopped half-way, so the request goes to
     * staff, told whether a memo for the withdrawn items is already on the order.
     *
     * @param array<int,float> $oidQtys order_item_id => qty, the withdrawn set
     */
    private function unclaimedOutcome(int $requestId, OrderInterface $order, array $oidQtys): CreditmemoIssueResultInterface
    {
        $fresh = $this->requestRepository->get($requestId);
        if ($fresh->getRefundCreditmemoId() !== null) {
            return new CreditmemoIssueResult(IssueOutcome::ALREADY_DONE, $fresh->getRefundCreditmemoId());
        }
        if ($fresh->getReimbursementPaidAt() !== null) {
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'already reimbursed manually');
        }
        if ($this->requestResource->refundState($requestId)['state'] === RequestResource::REFUND_STATE_OUTCOME_UNKNOWN) {
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'refund outcome unknown');
        }
        if ($this->existingMemoCoversWithdrawnItems($order, $oidQtys)) {
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'existing memo on order');
        }
        if ($fresh->getData(RequestInterface::REFUND_ISSUE_CLAIMED_AT) === null) {
            return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'refund in progress');
        }

        return new CreditmemoIssueResult(IssueOutcome::ROUTED_TO_MANUAL, null, 'issue interrupted');
    }

    /**
     * Queried directly against the DB rather than via $order->getCreditmemosCollection():
     * OrderRepository caches loaded orders by id, and the collection memoizes itself
     * on first access, so a same-process retry against the cached order would see a
     * stale (pre-refund) empty collection instead of the memo the retry exists to detect.
     *
     * @param array<int,float> $oidQtys order_item_id => qty, the withdrawn set
     */
    private function existingMemoCoversWithdrawnItems(OrderInterface $order, array $oidQtys): bool
    {
        return $this->requestResource->hasMemoOnOrderItems(
            (int) $order->getEntityId(),
            array_map('intval', array_keys($oidQtys)),
        );
    }
}
