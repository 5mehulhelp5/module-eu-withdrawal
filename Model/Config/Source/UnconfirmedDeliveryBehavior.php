<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class UnconfirmedDeliveryBehavior implements OptionSourceInterface
{
    public const KEEP_OPEN = 'keep_open';
    public const NOT_ELIGIBLE = 'not_eligible';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::NOT_ELIGIBLE, 'label' => __('Treat as not eligible')],
            ['value' => self::KEEP_OPEN, 'label' => __('Keep withdrawal open')],
        ];
    }
}
