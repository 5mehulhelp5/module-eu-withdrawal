<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Rule;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use MageMe\EUWithdrawal\Model\CustomerGroup\CustomerGroupScope;

class CustomerGroupScopeRule extends AbstractRule
{
    public const CODE = 'customer_group_scope_rule';
    public const PRIORITY = 6;
    public const REASON = 'group_out_of_scope';
    public const BASIS = 'merchant_group_scope';

    /**
     * Constructor.
     *
     * @param CustomerGroupScope $customerGroupScope
     */
    public function __construct(
        private readonly CustomerGroupScope $customerGroupScope,
    ) {
    }

    /**
     * Get code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * Get priority.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return self::PRIORITY;
    }

    /**
     * Get scope.
     *
     * @return string
     */
    public function getScope(): string
    {
        return self::SCOPE_ORDER;
    }

    /**
     * Evaluate.
     *
     * @param EligibilityRequestInterface $request
     * @param EligibilityDecisionInterface $current
     * @return EligibilityDecisionInterface
     */
    public function evaluate(
        EligibilityRequestInterface $request,
        EligibilityDecisionInterface $current,
    ): EligibilityDecisionInterface {
        if ($this->customerGroupScope->orderInScope($request->getOrder())) {
            return $current;
        }
        return $current->withApplied(self::CODE)->withDeny(self::REASON, self::BASIS);
    }
}
