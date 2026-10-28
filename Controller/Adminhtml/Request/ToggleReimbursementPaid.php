<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Adminhtml\Request;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\Refund\InterruptedAutoMemo;
use MageMe\EUWithdrawal\Model\ResourceModel\Request as RequestResource;
use MageMe\EUWithdrawal\Model\RequestNote\RequestNoteRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Sets or clears the manual "reimbursement paid" mark on a request, as the page asks
 * (the `paid` parameter; without it, toggles the mark), and records a plain note
 * either way. This covers refunds issued outside the request — offline, bank
 * transfer, external PSP, or a credit memo raised straight from the order — so the
 * grid due-state and the overdue digest stop treating the request as outstanding.
 *
 * A request already linked to a credit memo (via StartCreditMemo) is paid on that
 * evidence alone; the mark is rejected there so a manual flag can never shadow the
 * real refund record. Like withholding, this is a request fact (a column), not a
 * status transition.
 */
class ToggleReimbursementPaid extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageMe_EUWithdrawal::request_edit';

    /**
     * The state the page asks for: 1 marks the reimbursement as refunded, 0 clears the mark.
     */
    public const PAID_PARAM = 'paid';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param RequestRepositoryInterface $requestRepository
     * @param RequestNoteRepository $noteRepository
     * @param EventManager $eventManager
     * @param RequestResource $requestResource
     * @param InterruptedAutoMemo $interruptedAutoMemo
     */
    public function __construct(
        Context $context,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly RequestNoteRepository $noteRepository,
        private readonly EventManager $eventManager,
        private readonly RequestResource $requestResource,
        private readonly InterruptedAutoMemo $interruptedAutoMemo,
    ) {
        parent::__construct($context);
    }

    /**
     * Execute.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $id = (int) $this->getRequest()->getParam('request_id');

        try {
            $request = $this->requestRepository->get($id);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage((string) __('Request #%1 not found.', $id));
            return $redirect->setPath('*/request/edit', ['request_id' => $id]);
        }

        if ((int) $request->getRefundCreditmemoId() > 0) {
            $this->messageManager->addErrorMessage(
                (string) __(
                    'Request #%1 is already linked to a credit memo, so its reimbursement is recorded as refunded.',
                    $id,
                ),
            );
            return $redirect->setPath('*/request/edit', ['request_id' => $id]);
        }

        $adminId = (string) ($this->_auth->getUser()?->getId() ?? '');
        // The page posts the state it asks for, so two clicks on the same link both mark (or both clear);
        // without it the action toggles what it finds.
        $wanted = $this->getRequest()->getParam(self::PAID_PARAM);
        $clearing = $wanted === null ? $request->getReimbursementPaidAt() !== null : !(bool) (int) $wanted;
        $successMessage = $clearing
            ? (string) __('Refunded mark for request #%1 cleared.', $id)
            : (string) __('Reimbursement for request #%1 marked as refunded.', $id);

        if ($clearing) {
            $note = (string) __('Refunded mark cleared; the reimbursement is tracked as outstanding again.');
            $changed = $this->requestResource->clearReimbursementPaid($id);
        } else {
            $note = (string) __('Reimbursement recorded as refunded outside the request.');
            $changed = $this->requestResource->markReimbursementPaid($id, gmdate('Y-m-d H:i:s'));
        }

        if (!$changed) {
            $current = $this->requestRepository->get($id);
            if (($current->getReimbursementPaidAt() === null) === $clearing && $current->getRefundCreditmemoId() === null) {
                // Already as asked, by this click's twin or another admin: nothing to record twice.
                $this->messageManager->addSuccessMessage($successMessage);
            } else {
                $this->messageManager->addErrorMessage($this->notMarked($current));
            }
            return $redirect->setPath('*/request/edit', ['request_id' => $id]);
        }

        $this->noteRepository->add(
            $id,
            $note,
            $adminId !== '' ? 'admin' : 'system',
            $adminId !== '' ? $adminId : null,
        );

        $this->eventManager->dispatch(
            'mageme_eu_withdrawal_audit_admin_note_added',
            [
                'request_id' => $id,
                'admin_id'   => $adminId,
                'note_text'  => $note,
                'ip'         => (string) $this->getRequest()->getClientIp(true),
                'user_agent' => (string) $this->getRequest()->getHeader('User-Agent'),
            ],
        );

        $this->messageManager->addSuccessMessage($successMessage);
        return $redirect->setPath('*/request/edit', ['request_id' => $id]);
    }

    /**
     * Why the paid mark was not set: a refund holds the request, or it is refunded already.
     *
     * @param RequestInterface $request the request as it is now
     * @return string
     */
    private function notMarked(RequestInterface $request): string
    {
        $holder = $this->interruptedAutoMemo->refundHolder($request);
        if ($holder === RequestResource::LEASE_HOLDER_AUTO) {
            return (string) __('Automatic refund in progress. Try again in a few minutes.');
        }
        if ($holder !== null) {
            return (string) __('Another refund of this request is in progress. Reload the request in a few minutes.');
        }

        return (string) __('The request is already recorded as refunded.');
    }
}
