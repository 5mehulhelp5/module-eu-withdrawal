<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Controller\Adminhtml\Request;

use MageMe\EUWithdrawal\Model\RequestNote\RequestNoteRepository;
use MageMe\EUWithdrawal\Model\ResourceModel\Request as RequestResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Clears an unknown refund outcome once staff confirmed with the payment provider that the customer was
 * not refunded, so the request can be refunded again. Records the decision as a note and in the audit trail.
 */
class ClearRefundOutcome extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageMe_EUWithdrawal::request_edit';

    /**
     * Constructor.
     *
     * @param Context $context
     * @param RequestResource $requestResource
     * @param RequestNoteRepository $noteRepository
     * @param EventManager $eventManager
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        Context $context,
        private readonly RequestResource $requestResource,
        private readonly RequestNoteRepository $noteRepository,
        private readonly EventManager $eventManager,
        private readonly TimezoneInterface $timezone,
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
        $redirect = $this->resultRedirectFactory->create()->setPath('*/request/edit', ['request_id' => $id]);

        if (!$this->requestResource->clearRefundOutcomeUnknown($id)) {
            $waitsUntil = $this->requestResource->refundState($id)['waits_until'];
            $this->messageManager->addErrorMessage(
                $waitsUntil !== null
                    ? (string) __(
                        'The refund that ran out may still finish, so its outcome can be cleared from %1. Reload the request then.',
                        $this->timezone->formatDateTime($waitsUntil, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT),
                    )
                    : (string) __('The request has no refund with an unknown outcome to clear. Reload the request.'),
            );
            return $redirect;
        }

        $adminId = (string) ($this->_auth->getUser()?->getId() ?? '');
        $note = (string) __('The payment provider did not refund the customer: the unknown refund outcome is cleared, and the request can be refunded again.');
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
            (string) __('The unknown refund outcome of request #%1 is cleared. You can issue its credit memo again.', $id),
        );
        return $redirect;
    }
}
