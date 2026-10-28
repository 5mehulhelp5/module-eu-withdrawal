<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Model\RequestNote\RequestNoteRepository;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Tells staff that a refund finished after the request was already recorded as refunded another way — a
 * payment provider that answered after the hold ran out — so the customer may have been refunded twice.
 */
class DoubleRefundAlert
{
    /**
     * Constructor.
     *
     * @param RequestNoteRepository $noteRepository
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestNoteRepository $noteRepository,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Logs a warning and notes on the request that credit memo $creditmemoId may refund it a second time.
     *
     * @param int $requestId
     * @param int $creditmemoId
     * @return void
     */
    public function raise(int $requestId, int $creditmemoId): void
    {
        $this->logger->warning(
            'EUWithdrawal: a refund finished after the request was already recorded as refunded; possible double refund.',
            ['request_id' => $requestId, 'creditmemo_id' => $creditmemoId],
        );
        try {
            $incrementId = (string) ($this->creditmemoRepository->get($creditmemoId)->getIncrementId() ?? $creditmemoId);
        } catch (\Throwable) {
            $incrementId = (string) $creditmemoId;
        }
        $this->noteRepository->add(
            $requestId,
            (string) __(
                'Credit memo #%1 was refunded after this request was already recorded as refunded: the customer may have been refunded twice. Check the refund in your payment provider.',
                $incrementId,
            ),
            'system',
            null,
        );
    }

    /**
     * Logs a warning and notes on the request that a refund failed after its payment step while another
     * refund of the request was running, so both may have paid out.
     *
     * @param int $requestId
     * @return void
     */
    public function raiseLateFailure(int $requestId): void
    {
        $this->logger->warning(
            'EUWithdrawal: a refund failed after its payment step while another refund held the request; possible double refund.',
            ['request_id' => $requestId],
        );
        $this->noteRepository->add(
            $requestId,
            (string) __('A refund of this request failed after the payment provider was contacted while another refund of it was running: the customer may have been refunded twice. Check the refund in your payment provider.'),
            'system',
            null,
        );
    }
}
