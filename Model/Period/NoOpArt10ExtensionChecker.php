<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Period;

use MageMe\EUWithdrawal\Api\Period\Art10ExtensionCheckerInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Art. 10(1) extension checker: always returns false, so the standard
 * withdrawal period applies to every order.
 *
 * The Pro `MageMe_EUWithdrawalAnnexI` add-on keeps this binding; it only
 * flags, on the request page, an order without a record of the withdrawal
 * information being shown, and the merchant decides.
 */
class NoOpArt10ExtensionChecker implements Art10ExtensionCheckerInterface
{
    /**
     * Should extend.
     *
     * @param OrderInterface $order
     * @return bool Always false in the free tier.
     */
    public function shouldExtend(OrderInterface $order): bool
    {
        return false;
    }
}
