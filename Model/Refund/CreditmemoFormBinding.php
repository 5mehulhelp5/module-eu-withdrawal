<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

use MageMe\EUWithdrawal\Controller\Adminhtml\Request\StartCreditMemo;
use Magento\Framework\App\RequestInterface;

/**
 * The withdrawal request a credit memo being refunded or saved in the admin was opened for: the form
 * StartCreditMemo opens carries the request id into its save request. A memo raised from the order view
 * carries none and binds to nothing; each open form keeps its own request, whatever other forms the
 * same admin opened since.
 */
class CreditmemoFormBinding
{
    /**
     * Constructor.
     *
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly RequestInterface $request,
    ) {
    }

    /**
     * The bound request id, or 0.
     *
     * @return int
     */
    public function requestId(): int
    {
        return max(0, (int) $this->request->getParam(StartCreditMemo::REQUEST_PARAM));
    }

    /**
     * Whether the form was opened to record, with an offline memo, a refund whose outcome was unknown and
     * that staff confirmed with the payment provider.
     *
     * @return bool
     */
    public function recordsOfflineRefund(): bool
    {
        return (bool) $this->request->getParam(StartCreditMemo::OFFLINE_RECORD_PARAM);
    }
}
