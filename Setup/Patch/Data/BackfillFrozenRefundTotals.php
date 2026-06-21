<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Setup\Patch\Data;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Backfills the frozen refund columns (items_subtotal, tax_refund, total_refund)
 * on requests created before they existed. The grid, edit screen, order comment
 * and receipt all read these columns; legacy rows would otherwise read NULL.
 *
 * Source of truth is the request's own frozen receipt snapshot (its refund block
 * already holds net items / combined VAT / total). Rows without a usable snapshot
 * fall back to the canonical total recomputed from the stored columns; their
 * net/VAT split is unrecoverable and stays NULL.
 */
class BackfillFrozenRefundTotals implements DataPatchInterface
{
    private const TABLE_REQUEST = 'mm_eu_withdrawal_request';
    private const TABLE_ITEM    = 'mm_eu_withdrawal_item';

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
        $requestTable = $this->moduleDataSetup->getTable(self::TABLE_REQUEST);
        $itemTable = $this->moduleDataSetup->getTable(self::TABLE_ITEM);

        $select = $connection->select()
            ->from(
                $requestTable,
                ['request_id', 'receipt_snapshot', 'shipping_refund', 'order_adjustment_refund'],
            )
            ->where('total_refund IS NULL');

        foreach ($connection->fetchAll($select) as $row) {
            $bind = $this->resolveTotals($connection, $itemTable, $row);
            $connection->update($requestTable, $bind, ['request_id = ?' => (int) $row['request_id']]);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, float|null>
     */
    private function resolveTotals(AdapterInterface $connection, string $itemTable, array $row): array
    {
        $snapshot = $row['receipt_snapshot'] ?? null;
        if (is_string($snapshot) && $snapshot !== '') {
            $decoded = json_decode($snapshot, true);
            $refund = is_array($decoded) ? ($decoded['refund'] ?? null) : null;
            if (is_array($refund) && isset($refund['total'])) {
                return [
                    RequestInterface::ITEMS_SUBTOTAL => $this->toFloatOrNull($refund['items'] ?? null),
                    RequestInterface::TAX_REFUND => $this->toFloatOrNull($refund['tax'] ?? null),
                    RequestInterface::TOTAL_REFUND => (float) $refund['total'],
                ];
            }
        }

        $itemsGross = (float) $connection->fetchOne(
            $connection->select()
                ->from($itemTable, [new \Zend_Db_Expr('COALESCE(SUM(refund_amount), 0)')])
                ->where('request_id = ?', (int) $row['request_id']),
        );

        return [
            RequestInterface::TOTAL_REFUND => $itemsGross
                + (float) ($row['shipping_refund'] ?? 0.0)
                + (float) ($row['order_adjustment_refund'] ?? 0.0),
        ];
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
