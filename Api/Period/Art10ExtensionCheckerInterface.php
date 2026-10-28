<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Api\Period;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Decides whether the withdrawal period should be extended under Art.
 * 10(1) Directive 2011/83/EU.
 *
 * Bound to `Model\Period\NoOpArt10ExtensionChecker`, which always
 * returns false: the period is never extended automatically. The Pro
 * `MageMe_EUWithdrawalAnnexI` add-on does not replace it; it flags an
 * order without a record of the withdrawal information being shown on
 * the request page and leaves the decision to the merchant.
 */
interface Art10ExtensionCheckerInterface
{
    /**
     * Should extend.
     *
     * @param OrderInterface $order
     * @return bool True if the withdrawal period should be extended to
     *              12 months + 14 days under Art. 10(1) (no proof of
     *              Art. 6(1)(h) disclosure).
     */
    public function shouldExtend(OrderInterface $order): bool;
}
