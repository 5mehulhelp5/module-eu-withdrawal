<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Controller\Adminhtml\Request\StartCreditMemo;
use MageMe\EUWithdrawal\Model\Refund\CreditmemoFormBinding;
use MageMe\EUWithdrawal\Model\Refund\RefundInFlight;
use MageMe\EUWithdrawal\Model\ResourceModel\Request as RequestResource;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Listens on `sales_order_creditmemo_save_after`. When the admin started
 * the credit memo flow from a withdrawal request (via the "Issue Credit
 * Memo" button on the request edit page), the form `StartCreditMemo` opened
 * carries the request id into its save request. This observer reads it back
 * through CreditmemoFormBinding
 * and links the newly-saved credit memo's id to
 * `mm_eu_withdrawal_request.refund_creditmemo_id`, unless the request is
 * already refunded otherwise. The link finishes the hold GuardCreditmemoRefund
 * took for this refund.
 *
 * The link is best-effort: if the request load fails or the memo was not
 * saved from that form (admin issued the credit memo through the standard
 * order flow, not through our button), the observer no-ops silently.
 */
class LinkCreditMemoToRequest implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param CreditmemoFormBinding $formBinding
     * @param RequestRepositoryInterface $requestRepository
     * @param LoggerInterface $logger
     * @param AuthorizationInterface $authorization
     * @param RequestResource $requestResource
     * @param RefundInFlight $inFlight
     */
    public function __construct(
        private readonly CreditmemoFormBinding $formBinding,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly LoggerInterface $logger,
        private readonly AuthorizationInterface $authorization,
        private readonly RequestResource $requestResource,
        private readonly RefundInFlight $inFlight,
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
        $requestId = $this->formBinding->requestId();
        if ($requestId <= 0 || !$this->authorization->isAllowed(StartCreditMemo::ADMIN_RESOURCE)) {
            return;
        }
        $creditmemo = $observer->getEvent()->getCreditmemo();
        if (!$creditmemo) {
            return;
        }
        $creditmemoId = (int) $creditmemo->getEntityId();
        if ($creditmemoId <= 0) {
            return;
        }
        try {
            $request = $this->requestRepository->get($requestId);
            if ((int) $creditmemo->getOrderId() !== (int) $request->getOrderId()) {
                // The form names a withdrawal request of another order. Do not link.
                $this->logger->warning(
                    'EUWithdrawal: creditmemo order does not match the withdrawal request order; skipping link.',
                    [
                        'request_id' => $requestId,
                        'creditmemo_id' => $creditmemoId,
                        'creditmemo_order_id' => (int) $creditmemo->getOrderId(),
                        'request_order_id' => (int) $request->getOrderId(),
                    ],
                );
                return;
            }
            $refund = $this->inFlight->current();
            $token = $refund !== null && $refund['request_id'] === $requestId ? $refund['token'] : null;
            if (!$this->requestResource->linkRefundCreditmemo($requestId, $creditmemoId, $token)) {
                $this->logger->warning(
                    'EUWithdrawal: the withdrawal request is already refunded otherwise; creditmemo not linked.',
                    ['request_id' => $requestId, 'creditmemo_id' => $creditmemoId],
                );
            }
        } catch (\Throwable $t) {
            $this->logger->warning(
                'EUWithdrawal: failed to link creditmemo to request: ' . $t->getMessage(),
                ['request_id' => $requestId, 'creditmemo_id' => $creditmemoId],
            );
        }
    }
}
