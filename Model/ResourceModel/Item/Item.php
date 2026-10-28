<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\ResourceModel\Item;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Item extends AbstractDb
{
    public const DB_TABLE = 'mm_eu_withdrawal_item';

    private const TABLE_ORDER_ITEM = 'sales_order_item';

    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(self::DB_TABLE, 'item_id');
    }

    /**
     * The request's item lines for the receipt, with the ordered product's name, in order item order.
     *
     * @param int $requestId
     * @return array<int, array<string, string|null>> order_item_id, sku, qty_withdraw, refund_amount, name
     */
    public function fetchReceiptLines(int $requestId): array
    {
        $conn = $this->getConnection();

        return $conn->fetchAll(
            $conn->select()
                ->from(
                    ['i' => $this->getMainTable()],
                    ['order_item_id', 'sku', 'qty_withdraw', 'refund_amount'],
                )
                ->joinLeft(
                    ['soi' => $this->getTable(self::TABLE_ORDER_ITEM)],
                    'soi.item_id = i.order_item_id',
                    ['name'],
                )
                ->where('i.request_id = ?', $requestId)
                ->order('i.order_item_id ASC'),
        );
    }
}
