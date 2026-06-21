<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Scope;

use MageMe\EUWithdrawal\Model\CustomerGroup\CustomerGroupScope;
use MageMe\EUWithdrawal\Model\Geo\CountryScope;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Aggregates every merchant-controlled storefront scope dimension into a
 * single in/out gate. The non-engine surfaces (guest lookup, checkout
 * waiver, order/shipment email CTA, Hyva companions) depend on this facade
 * only. An order or quote is in scope when every active dimension is in
 * scope; an inactive dimension reports in-scope, so the gate reduces to the
 * dimensions the merchant has actually enabled.
 */
class WithdrawalScope
{
    /**
     * Constructor.
     *
     * @param CountryScope $countryScope
     * @param CustomerGroupScope $customerGroupScope
     */
    public function __construct(
        private readonly CountryScope $countryScope,
        private readonly CustomerGroupScope $customerGroupScope,
    ) {
    }

    /**
     * Whether the order is in scope across every dimension.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function orderInScope(OrderInterface $order): bool
    {
        return $this->countryScope->orderInScope($order)
            && $this->customerGroupScope->orderInScope($order);
    }

    /**
     * Whether the quote is in scope across every dimension.
     *
     * @param CartInterface $quote
     * @return bool
     */
    public function quoteInScope(CartInterface $quote): bool
    {
        return $this->countryScope->quoteInScope($quote)
            && $this->customerGroupScope->quoteInScope($quote);
    }
}
