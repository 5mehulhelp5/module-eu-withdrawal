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
use MageMe\EUWithdrawal\Model\RequestNote\RequestNoteRepository;
use MageMe\EUWithdrawal\Model\ResourceModel\Request as RequestResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Model\Order\Creditmemo;

/**
 * Links an existing credit memo of the request's order to the request, recording its refund. Offered
 * when an automatic credit memo was committed but its link to the request was never saved, so staff
 * record that refund instead of issuing a second one.
 */
class LinkCreditMemo extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageMe_EUWithdrawal::request_edit';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param RequestRepositoryInterface $requestRepository
     * @param RequestResource $requestResource
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param RequestNoteRepository $noteRepository
     * @param EventManager $eventManager
     * @param InterruptedAutoMemo $interruptedAutoMemo
     */
    public function __construct(
        Context $context,
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly RequestResource $requestResource,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly RequestNoteRepository $noteRepository,
        private readonly EventManager $eventManager,
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
        $id = (int) $this->getRequest()->getParam('request_id');
        $creditmemoId = (int) $this->getRequest()->getParam('creditmemo_id');
        $redirect = $this->resultRedirectFactory->create()->setPath('*/request/edit', ['request_id' => $id]);

        try {
            $request = $this->requestRepository->get($id);
            $creditmemo = $this->creditmemoRepository->get($creditmemoId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage((string) __('The request or the credit memo was not found.'));
            return $redirect;
        }

        $error = $this->refusal(
            $request,
            $creditmemoId,
            (int) $creditmemo->getOrderId(),
            (int) $creditmemo->getState(),
        );
        if ($error !== null) {
            $this->messageManager->addErrorMessage($error);
            return $redirect;
        }

        if (!$this->requestResource->linkExistingCreditmemo($id, $creditmemoId)) {
            // Something changed since the checks above: another refund, mark or link got there first.
            $this->messageManager->addErrorMessage(
                $this->refusal(
                    $this->requestRepository->get($id),
                    $creditmemoId,
                    (int) $creditmemo->getOrderId(),
                    (int) $creditmemo->getState(),
                ) ?? (string) __('The credit memo is already linked to another withdrawal request.'),
            );
            return $redirect;
        }

        $adminId = (string) ($this->_auth->getUser()?->getId() ?? '');
        $note = (string) __('Credit memo #%1 linked to the request as its refund.', $creditmemo->getIncrementId());
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

        $this->messageManager->addSuccessMessage(
            (string) __('Credit memo #%1 is linked to request #%2.', $creditmemo->getIncrementId(), $id),
        );
        return $redirect;
    }

    /**
     * Why the credit memo cannot be linked to the request, or null when it can.
     *
     * @param RequestInterface $request
     * @param int $creditmemoId
     * @param int $creditmemoOrderId
     * @param int $creditmemoState
     * @return string|null
     */
    private function refusal(
        RequestInterface $request,
        int $creditmemoId,
        int $creditmemoOrderId,
        int $creditmemoState,
    ): ?string {
        if ($request->getRefundCreditmemoId() !== null || $request->getReimbursementPaidAt() !== null) {
            return (string) __('The request is already recorded as refunded.');
        }
        $holder = $this->interruptedAutoMemo->refundHolder($request);
        if ($holder === RequestResource::LEASE_HOLDER_AUTO
            || $this->requestResource->isOrderHeldByAutoIssue((int) $request->getRequestId())
        ) {
            return (string) __('Automatic refund in progress. Try again in a few minutes.');
        }
        if ($holder !== null) {
            return (string) __('Another refund of this request is in progress. Reload the request in a few minutes.');
        }
        if ($creditmemoOrderId !== (int) $request->getOrderId()) {
            return (string) __('The credit memo belongs to another order.');
        }
        if ($creditmemoState === Creditmemo::STATE_CANCELED) {
            return (string) __('The credit memo is canceled.');
        }
        if ($this->requestResource->isCreditmemoLinked($creditmemoId)) {
            return (string) __('The credit memo is already linked to another withdrawal request.');
        }
        if ($this->interruptedAutoMemo->unlinkedMemoId($request) !== $creditmemoId) {
            return (string) __('Only the credit memo left by an interrupted automatic refund can be linked to the request.');
        }

        return null;
    }
}
