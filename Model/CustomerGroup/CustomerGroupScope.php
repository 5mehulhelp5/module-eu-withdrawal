<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\CustomerGroup;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Merchant-controlled storefront scoping by customer group. The right of
 * withdrawal is a consumer right, so a merchant can exclude its B2B
 * (non-consumer) groups from the self-service flow. Block-list semantics:
 * an order/quote is in scope unless its customer group is on the excluded
 * list. Anchored on the group recorded on the order/quote (the buyer's
 * capacity at purchase), not the customer's current group.
 */
class CustomerGroupScope
{
    public const XML_ENABLED  = 'mageme_eu_withdrawal/scope/customer_group_scope_enabled';
    public const XML_EXCLUDED = 'mageme_eu_withdrawal/scope/customer_group_scope_excluded';

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Whether the restriction is active for the store.
     *
     * @param int $storeId
     * @return bool
     */
    public function isActive(int $storeId): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
            return false;
        }
        return $this->excludedGroups($storeId) !== [];
    }

    /**
     * Whether the order's customer group is in scope.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function orderInScope(OrderInterface $order): bool
    {
        return $this->inScope((int) $order->getStoreId(), (int) $order->getCustomerGroupId());
    }

    /**
     * Whether the quote's customer group is in scope.
     *
     * @param CartInterface $quote
     * @return bool
     */
    public function quoteInScope(CartInterface $quote): bool
    {
        return $this->inScope((int) $quote->getStoreId(), (int) $quote->getCustomerGroupId());
    }

    /**
     * Block-list match; fail open when inactive.
     *
     * @param int $storeId
     * @param int $groupId
     * @return bool
     */
    private function inScope(int $storeId, int $groupId): bool
    {
        if (!$this->isActive($storeId)) {
            return true;
        }
        return !in_array($groupId, $this->excludedGroups($storeId), true);
    }

    /**
     * Configured excluded group ids.
     *
     * @param int $storeId
     * @return int[]
     */
    private function excludedGroups(int $storeId): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_EXCLUDED, ScopeInterface::SCOPE_STORE, $storeId);
        $parts = array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $g): bool => $g !== '',
        );
        return array_values(array_map(static fn (string $g): int => (int) $g, $parts));
    }
}
