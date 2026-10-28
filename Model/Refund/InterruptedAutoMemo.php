<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\ItemRepositoryInterface;
use MageMe\EUWithdrawal\Model\Item\ItemAmountResolver;
use MageMe\EUWithdrawal\Model\ResourceModel\Request as RequestResource;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * An automatic credit memo issue that claimed a request and never linked a memo to it: it stopped
 * before committing the memo, or committed it and failed to save the link. Staff refunding such a
 * request by hand must first rule out the second case, or the customer is refunded twice. While the
 * issue still holds the request it is in progress, not interrupted, and staff wait for it; a hold that
 * ran out, or an issue that failed after its payment step, leaves the outcome unknown instead.
 */
class InterruptedAutoMemo
{
    /**
     * The claim time comes from PHP, the memo's creation time from the database clock.
     */
    private const CLOCK_SKEW_SECONDS = 300;

    /**
     * Constructor.
     *
     * @param RequestResource $requestResource
     * @param ItemRepositoryInterface $itemRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param CreditMemoQtyExpander $qtyExpander
     * @param ItemAmountResolver $itemAmounts
     */
    public function __construct(
        private readonly RequestResource $requestResource,
        private readonly ItemRepositoryInterface $itemRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CreditMemoQtyExpander $qtyExpander,
        private readonly ItemAmountResolver $itemAmounts,
    ) {
    }

    /**
     * When the interrupted issue claimed the request (UTC `Y-m-d H:i:s`), or null when the request
     * is not interrupted: never claimed, a refund still holds it or left its outcome unknown, or its
     * refund is already linked or marked as refunded.
     *
     * @param RequestInterface $request
     * @return string|null
     */
    public function claimedAt(RequestInterface $request): ?string
    {
        $claimedAt = $this->claimedAtOfUnrefunded($request);
        if ($claimedAt === null
            || $this->requestResource->refundState((int) $request->getRequestId())['state'] !== RequestResource::REFUND_STATE_FREE
        ) {
            return null;
        }

        return $claimedAt;
    }

    /**
     * Who holds the request for a refund in flight right now, by the database clock:
     * RequestResource::LEASE_HOLDER_AUTO for the automatic issue, RequestResource::LEASE_HOLDER_ADMIN for
     * a credit memo form; null when none does.
     *
     * @param RequestInterface $request
     * @return string|null
     */
    public function refundHolder(RequestInterface $request): ?string
    {
        $state = $this->requestResource->refundState((int) $request->getRequestId())['state'];

        return in_array($state, [RequestResource::LEASE_HOLDER_AUTO, RequestResource::LEASE_HOLDER_ADMIN], true)
            ? $state
            : null;
    }

    /**
     * Since when (UTC `Y-m-d H:i:s`) the outcome of a refund of the request is unknown — it failed after
     * its payment step, or its hold ran out — or null when it is not.
     *
     * @param RequestInterface $request
     * @return string|null
     */
    public function outcomeUnknownSince(RequestInterface $request): ?string
    {
        $state = $this->requestResource->refundState((int) $request->getRequestId());

        return $state['state'] === RequestResource::REFUND_STATE_OUTCOME_UNKNOWN ? (string) $state['since'] : null;
    }

    /**
     * Until when (UTC `Y-m-d H:i:s`) the unknown outcome cannot be cleared or recorded offline, because the
     * refund whose hold ran out may still finish; null when those actions are open.
     *
     * @param RequestInterface $request
     * @return string|null
     */
    public function outcomeActionsWaitUntil(RequestInterface $request): ?string
    {
        return $this->requestResource->refundState((int) $request->getRequestId())['waits_until'];
    }

    /**
     * The credit memo on the order that the automatic issue left behind, for a request it claimed that
     * is not refunded: created since the claim, refunding every withdrawn quantity, linked to no request.
     * Null otherwise.
     *
     * @param RequestInterface $request
     * @return int|null
     */
    public function unlinkedMemoId(RequestInterface $request): ?int
    {
        $claimedAt = $this->claimedAtOfUnrefunded($request);
        if ($claimedAt === null) {
            return null;
        }
        $qtyByOrderItem = [];
        foreach ($this->itemRepository->getByRequest((int) $request->getRequestId()) as $item) {
            $qtyByOrderItem[$item->getOrderItemId()] = ($qtyByOrderItem[$item->getOrderItemId()] ?? 0.0)
                + (float) $item->getQtyWithdraw();
        }
        $since = gmdate('Y-m-d H:i:s', (int) strtotime($claimedAt . ' UTC') - self::CLOCK_SKEW_SECONDS);

        return $this->requestResource->findUnlinkedMemoCovering(
            (int) $request->getOrderId(),
            $this->coverageByOrderItem((int) $request->getOrderId(), $qtyByOrderItem),
            $since,
        );
    }

    /**
     * When the automatic issue claimed the request, for a request that is not refunded; null otherwise.
     *
     * @param RequestInterface $request
     * @return string|null
     */
    private function claimedAtOfUnrefunded(RequestInterface $request): ?string
    {
        $claimedAt = $request->getData(RequestInterface::REFUND_ISSUE_CLAIMED_AT);
        if ($claimedAt === null || $claimedAt === ''
            || $request->getRefundCreditmemoId() !== null
            || $request->getReimbursementPaidAt() !== null
        ) {
            return null;
        }

        return (string) $claimedAt;
    }

    /**
     * The quantities a memo must refund per order item. A bundle priced from its components is counted
     * on its components: core records such a parent's memo quantity as its invoiced quantity, whatever
     * the memo refunds.
     *
     * @param int $orderId
     * @param array<int, float> $qtyByOrderItem withdrawn order_item_id => qty
     * @return array<int, float>
     */
    private function coverageByOrderItem(int $orderId, array $qtyByOrderItem): array
    {
        $order = $this->orderRepository->get($orderId);
        $coverage = $this->qtyExpander->expand($order, $qtyByOrderItem);
        $expandedParents = [];
        foreach (($order->getItems() ?? []) as $item) {
            $itemId = (int) $item->getItemId();
            $parentId = (int) $item->getParentItemId();
            if ($parentId > 0 && isset($coverage[$itemId]) && !isset($qtyByOrderItem[$itemId])) {
                $expandedParents[$parentId] = true;
            }
        }
        foreach (($order->getItems() ?? []) as $item) {
            $itemId = (int) $item->getItemId();
            if (isset($expandedParents[$itemId]) && $this->itemAmounts->isChildCalculatedBundle($item)) {
                unset($coverage[$itemId]);
            }
        }

        return $coverage;
    }
}
