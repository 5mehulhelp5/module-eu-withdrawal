<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Rule;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class StatusExclusionRule extends AbstractRule
{
    public const CODE = 'status_exclusion_rule';
    public const PRIORITY = 1;
    public const REASON_EXCLUDED = 'status_excluded';
    public const BASIS_EXCLUDED = 'merchant_status_exclusion';
    public const XML_EXCLUDED_STATUSES = 'mageme_eu_withdrawal/withdrawal_window/excluded_statuses';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getPriority(): int
    {
        return self::PRIORITY;
    }

    public function getScope(): string
    {
        return self::SCOPE_ORDER;
    }

    public function evaluate(
        EligibilityRequestInterface $request,
        EligibilityDecisionInterface $current,
    ): EligibilityDecisionInterface {
        $order = $request->getOrder();
        if (in_array((string) $order->getStatus(), $this->excludedStatuses($request->getStoreId()), true)) {
            return $current->withApplied(self::CODE)->withDeny(self::REASON_EXCLUDED, self::BASIS_EXCLUDED);
        }
        return $current;
    }

    /**
     * @return string[]
     */
    private function excludedStatuses(int $storeId): array
    {
        $raw = trim((string) $this->scopeConfig->getValue(
            self::XML_EXCLUDED_STATUSES,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        ));
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $s) => $s !== '',
        ));
    }
}
