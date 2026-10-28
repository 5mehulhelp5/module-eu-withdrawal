<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Empties the Return Shipping Notice wherever it still holds the text the module used to ship
 * as its default ("You'll receive a prepaid shipping label…"). Saving the settings page writes
 * the default into the database, so the old promise would outlive the new empty default.
 * The comparison is byte for byte, in every scope; wording a merchant changed is left alone.
 */
class ClearPrepaidShippingLabelNotice implements DataPatchInterface
{
    private const TABLE_CONFIG = 'core_config_data';
    private const PATH = 'mageme_eu_withdrawal/frontend/review/shipping_info_text';
    private const OLD_DEFAULT = "You'll receive a prepaid shipping label in the confirmation email.";

    /**
     * Constructor.
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(private readonly ModuleDataSetupInterface $moduleDataSetup)
    {
    }

    /**
     * Get dependencies.
     *
     * @return array
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * Get aliases.
     *
     * @return array
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * Apply.
     *
     * @return self
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(self::TABLE_CONFIG);

        $select = $connection->select()
            ->from($table, ['config_id', 'value'])
            ->where('path = ?', self::PATH);

        // Compared in PHP: the column collation would also match a value that differs only in case.
        foreach ($connection->fetchAll($select) as $row) {
            if ($row['value'] === self::OLD_DEFAULT) {
                $connection->update($table, ['value' => ''], ['config_id = ?' => (int) $row['config_id']]);
            }
        }

        return $this;
    }
}
