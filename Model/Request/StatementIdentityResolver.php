<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Request;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * The name and receipt address a withdrawal statement is filed under (§ 356a(2) BGB, Art. 11a(2)):
 * the account of a logged-in customer, otherwise the verified order. The review step shows exactly
 * what the finalize controller stores.
 */
class StatementIdentityResolver
{
    public function __construct(
        private readonly CustomerSession $customerSession,
    ) {
    }

    /**
     * @return array{name: string, email: string}
     */
    public function resolve(?OrderInterface $order): array
    {
        if ($this->customerSession->isLoggedIn()) {
            $customer = $this->customerSession->getCustomer();
            return [
                'name' => trim(($customer->getFirstname() ?? '') . ' ' . ($customer->getLastname() ?? '')),
                'email' => (string) $customer->getEmail(),
            ];
        }
        if ($order === null) {
            return ['name' => '', 'email' => ''];
        }
        return [
            'name' => trim(($order->getCustomerFirstname() ?? '') . ' ' . ($order->getCustomerLastname() ?? '')),
            'email' => (string) $order->getCustomerEmail(),
        ];
    }
}
