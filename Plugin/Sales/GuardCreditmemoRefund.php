<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Plugin\Sales;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Controller\Adminhtml\Request\StartCreditMemo;
use MageMe\EUWithdrawal\Model\Refund\CreditmemoFormBinding;
use MageMe\EUWithdrawal\Model\Refund\DoubleRefundAlert;
use MageMe\EUWithdrawal\Model\Refund\InterruptedAutoMemo;
use MageMe\EUWithdrawal\Model\Refund\RefundInFlight;
use MageMe\EUWithdrawal\Model\RequestNote\RequestNoteRepository;
use MageMe\EUWithdrawal\Model\ResourceModel\Request as RequestResource;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;

/**
 * Rejects a stale admin credit-memo refund whose withdrawal request has since been
 * recorded as reimbursed — a linked credit memo or a manual paid mark — so an
 * already-open form cannot refund the customer a second time. It also rejects a
 * refund of a request whose automatic credit memo was committed but never linked:
 * staff link that memo or mark the request as refunded instead.
 *
 * Before the refund goes ahead the request is held for it by one conditional UPDATE,
 * which succeeds only while no memo is linked, no paid mark is set and no other refund
 * holds the request: of two forms submitted at once, or a form and the automatic issue,
 * only one refunds. The hold is committed before core opens its refund transaction, so
 * other processes see it. Linking the new memo finishes the hold — LinkCreditMemoToRequest
 * links it inside core's transaction, and the guard links it again after the refund in case
 * that link did not happen. A refund that fails before core's payment step, or an offline one,
 * gives the hold back; an online refund that fails after it leaves the outcome unknown, and the
 * request takes no new refund until staff resolve it. A form opened to record an already-made
 * refund over an unknown outcome refunds offline only.
 *
 * This runs at CreditmemoManagementInterface::refund, BEFORE the payment gateway is
 * called (online) or the offline memo is committed. A save_before observer would be
 * too late online: core sends the gateway refund before saving the memo, so a throw
 * there rolls back the record while the money has already left. Blocking here stops
 * the payout itself.
 *
 * Scoped by CreditmemoFormBinding, so it only ever inspects credit memos the admin
 * started from a withdrawal request; every other refund passes straight through.
 * Reading the binding leaves it in place, so LinkCreditMemoToRequest still
 * links a legitimate first memo. adminhtml only — the headless auto-issue path
 * (cron) carries no form binding and is guarded separately by the issuer's row claim.
 */
class GuardCreditmemoRefund
{
    /**
     * Constructor.
     *
     * @param CreditmemoFormBinding $formBinding
     * @param RequestRepositoryInterface $requestRepository
     * @param InterruptedAutoMemo $interruptedAutoMemo
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param RequestResource $requestResource
     * @param AuthorizationInterface $authorization
     * @param RefundInFlight $inFlight
     * @param RequestNoteRepository $noteRepository
     * @param EventManager $eventManager
     * @param Auth $auth
     * @param HttpRequest $httpRequest
     * @param DoubleRefundAlert $doubleRefundAlert
     */
    public function __construct(
        private readonly CreditmemoFormBinding $formBinding,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly InterruptedAutoMemo $interruptedAutoMemo,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly RequestResource $requestResource,
        private readonly AuthorizationInterface $authorization,
        private readonly RefundInFlight $inFlight,
        private readonly RequestNoteRepository $noteRepository,
        private readonly EventManager $eventManager,
        private readonly Auth $auth,
        private readonly HttpRequest $httpRequest,
        private readonly DoubleRefundAlert $doubleRefundAlert,
    ) {
    }

    /**
     * Around refund.
     *
     * @param CreditmemoManagementInterface $subject
     * @param callable $proceed
     * @param CreditmemoInterface $creditmemo
     * @param bool $offlineRequested
     * @return CreditmemoInterface
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundRefund(
        CreditmemoManagementInterface $subject,
        callable $proceed,
        CreditmemoInterface $creditmemo,
        $offlineRequested = false,
    ) {
        $requestId = $this->formBinding->requestId();
        if ($requestId <= 0) {
            return $proceed($creditmemo, $offlineRequested);
        }
        try {
            $request = $this->requestRepository->get($requestId);
        } catch (\Throwable) {
            return $proceed($creditmemo, $offlineRequested);
        }
        if ((int) $creditmemo->getOrderId() !== (int) $request->getOrderId()) {
            return $proceed($creditmemo, $offlineRequested);
        }

        $recordsOffline = $this->formBinding->recordsOfflineRefund();
        if ($recordsOffline && !$offlineRequested) {
            throw new LocalizedException(
                __('This credit memo records a refund your payment provider already made, so it can only be refunded offline. Click Refund Offline.'),
            );
        }
        $hold = $this->requestResource->reserveRefund($requestId, $recordsOffline);
        if ($hold === null) {
            throw $this->notHeld($requestId, $this->requestRepository->get($requestId));
        }
        $started = false;
        try {
            $this->inFlight->start($requestId, $hold);
            $started = true;
            $memoId = $this->interruptedAutoMemo->unlinkedMemoId($this->requestRepository->get($requestId));
            if ($memoId !== null) {
                throw new LocalizedException(
                    __(
                        'Credit memo #%1 on this order already refunds the items of withdrawal request #%2, so no refund was issued. Link credit memo #%1 to the request, or mark the request as refunded, instead of refunding again.',
                        $this->creditmemoRepository->get($memoId)->getIncrementId(),
                        $requestId,
                    ),
                );
            }

            $result = $proceed($creditmemo, $offlineRequested);
            $creditmemoId = (int) $result->getEntityId();
            if ($this->authorization->isAllowed(StartCreditMemo::ADMIN_RESOURCE)) {
                if (!$this->requestResource->linkRefundCreditmemo($requestId, $creditmemoId, $hold)) {
                    $this->doubleRefundAlert->raise($requestId, $creditmemoId);
                } elseif ($recordsOffline) {
                    $this->recordOfflineRefund($requestId);
                }
            }
            $this->requestResource->releaseRefund($requestId, $hold);

            return $result;
        } catch (\Throwable $e) {
            // An online refund that failed once core reached the payment step may have paid out anyway.
            if ($started && !$offlineRequested && $this->inFlight->hasPaymentStarted()) {
                if (!$this->requestResource->markRefundOutcomeUnknown($requestId, $hold)) {
                    $this->doubleRefundAlert->raiseLateFailure($requestId);
                }
            } else {
                $this->requestResource->releaseRefund($requestId, $hold);
            }
            throw $e;
        } finally {
            if ($started) {
                $this->inFlight->finish();
            }
        }
    }

    /**
     * Notes on the request, and in the audit trail, that the refund the payment provider made is recorded
     * with an offline credit memo.
     *
     * @param int $requestId
     * @return void
     */
    private function recordOfflineRefund(int $requestId): void
    {
        $adminId = (string) ($this->auth->getUser()?->getId() ?? '');
        $note = (string) __('The payment provider refunded the customer: the refund is recorded with an offline credit memo.');
        $this->noteRepository->add(
            $requestId,
            $note,
            $adminId !== '' ? 'admin' : 'system',
            $adminId !== '' ? $adminId : null,
        );
        $this->eventManager->dispatch(
            'mageme_eu_withdrawal_audit_admin_note_added',
            [
                'request_id' => $requestId,
                'admin_id'   => $adminId,
                'note_text'  => $note,
                'ip'         => (string) $this->httpRequest->getClientIp(true),
                'user_agent' => (string) $this->httpRequest->getHeader('User-Agent'),
            ],
        );
    }

    /**
     * Why the request could not be held for this refund.
     *
     * @param int $requestId
     * @param RequestInterface $request the request as it is now
     * @return LocalizedException
     */
    private function notHeld(int $requestId, RequestInterface $request): LocalizedException
    {
        $holder = $this->interruptedAutoMemo->refundHolder($request);
        if ($holder === RequestResource::LEASE_HOLDER_AUTO) {
            return new LocalizedException(__('Automatic refund in progress. Try again in a few minutes.'));
        }
        if ($holder !== null) {
            return new LocalizedException(
                __('Another refund of withdrawal request #%1 is in progress, so no refund was issued. Reload the request in a few minutes.', $requestId),
            );
        }
        if ($this->interruptedAutoMemo->outcomeUnknownSince($request) !== null) {
            return new LocalizedException(
                __('The outcome of an earlier refund of withdrawal request #%1 is unknown, so no refund was issued. Check the refund in your payment provider, then resolve it on the request page.', $requestId),
            );
        }

        return new LocalizedException(
            __(
                'Withdrawal request #%1 is already recorded as reimbursed, so no refund was issued.'
                . ' Reload the request; if you really must refund again, clear its paid mark first.',
                $requestId,
            ),
        );
    }
}
