<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Rule;

use MageMe\EUWithdrawal\Api\Data\EligibilityDecisionInterface;
use MageMe\EUWithdrawal\Api\Data\EligibilityRequestInterface;
use MageMe\EUWithdrawal\Api\RuleInterface;
use MageMe\EUWithdrawal\Exception\NoDeliveryInfoException;
use MageMe\EUWithdrawal\Model\Config\Source\ContractType;
use MageMe\EUWithdrawal\Model\Config\Source\UnconfirmedDeliveryBehavior;
use MageMe\EUWithdrawal\Api\Period\Art10ExtensionCheckerInterface;
use MageMe\EUWithdrawal\Model\Period\AnchorResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;

class PeriodRule extends AbstractRule
{
    public const CODE = 'period_rule';
    public const PRIORITY = 10;
    public const WITHDRAWAL_DAYS = 14;
    public const ART10_EXTENSION_MONTHS = 12;
    public const XML_PERIOD_DAYS = 'mageme_eu_withdrawal/withdrawal_window/period_days';
    public const REASON_DELIVERY_DATE_UNRECORDED = 'delivery_date_unrecorded';
    public const BASIS_NO_DELIVERY_DATE = 'no_delivery_date';
    public const XML_UNCONFIRMED_BEHAVIOR = 'mageme_eu_withdrawal/withdrawal_window/unconfirmed_delivery_behavior';

    /**
     * Constructor.
     *
     * @param AnchorResolver $anchorResolver
     * @param Art10ExtensionCheckerInterface $extensionChecker
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly AnchorResolver $anchorResolver,
        private readonly Art10ExtensionCheckerInterface $extensionChecker,
        private readonly ScopeConfigInterface $scopeConfig,
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
        if ($request->getContractType() === ContractType::FINANCIAL) {
            return $current->withApplied(self::CODE)->withFinalize('financial_out_of_scope');
        }

        try {
            $anchorDate = $this->anchorResolver->resolve(
                $request->getOrder(),
                $request->getStoreId(),
            );
        } catch (NoDeliveryInfoException) {
            if ($this->isUnrecordedDeliveredOrder($request)) {
                return $current->withApplied(self::CODE)
                    ->withDeny(self::REASON_DELIVERY_DATE_UNRECORDED, self::BASIS_NO_DELIVERY_DATE);
            }
            return $current->withApplied(self::CODE);
        }

        $periodEnd = $this->periodEndFrom($anchorDate, $request->getOrder(), $request->getStoreId());

        $decision = $current->withPeriodEnd($periodEnd)->withApplied(self::CODE);

        if ($request->getSubmittedAt() > $periodEnd) {
            return $decision->withDeny('period_expired', 'Art. 9(2)');
        }

        return $decision;
    }

    /**
     * Last moment of the order's withdrawal period, or null while nothing dates the delivery.
     *
     * @param OrderInterface $order
     * @param int $storeId
     * @return \DateTimeImmutable|null
     */
    public function resolvePeriodEnd(OrderInterface $order, int $storeId): ?\DateTimeImmutable
    {
        try {
            $anchorDate = $this->anchorResolver->resolve($order, $storeId);
        } catch (NoDeliveryInfoException) {
            return null;
        }
        return $this->periodEndFrom($anchorDate, $order, $storeId);
    }

    private function periodEndFrom(\DateTimeImmutable $anchorDate, OrderInterface $order, int $storeId): \DateTimeImmutable
    {
        $days = max(
            self::WITHDRAWAL_DAYS,
            (int) $this->scopeConfig->getValue(self::XML_PERIOD_DAYS, ScopeInterface::SCOPE_STORE, $storeId),
        );
        $periodEnd = $anchorDate->modify('+' . $days . ' days');

        if ($this->extensionChecker->shouldExtend($order)) {
            $periodEnd = $periodEnd->modify('+' . self::ART10_EXTENSION_MONTHS . ' months');
        }
        return $periodEnd->setTime(23, 59, 59);
    }

    private function isUnrecordedDeliveredOrder(EligibilityRequestInterface $request): bool
    {
        $behavior = (string) $this->scopeConfig->getValue(
            self::XML_UNCONFIRMED_BEHAVIOR,
            ScopeInterface::SCOPE_STORE,
            $request->getStoreId(),
        );
        if ($behavior === UnconfirmedDeliveryBehavior::KEEP_OPEN) {
            return false;
        }
        return $this->anchorResolver->isConfiguredDeliveryStatus(
            (string) $request->getOrder()->getStatus(),
            $request->getStoreId(),
        );
    }
}
