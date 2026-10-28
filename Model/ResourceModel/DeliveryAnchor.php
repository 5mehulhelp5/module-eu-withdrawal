<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\ResourceModel;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Moments an order reached a delivery confirmation status, and the status-history lookup the withdrawal
 * period falls back to.
 */
class DeliveryAnchor extends AbstractDb
{
    public const DB_TABLE = 'mm_eu_withdrawal_delivery_anchor';
    public const ORDER_ID = 'order_id';
    public const STATUS = 'status';
    public const REACHED_AT = 'reached_at';

    private const TABLE_STATUS_HISTORY = 'sales_order_status_history';

    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(self::DB_TABLE, self::ORDER_ID);
    }

    /**
     * Record the moment an order reached a delivery status. Only the first time counts: reaching the
     * same status again leaves the row as it is.
     *
     * @param int $orderId
     * @param string $status
     * @param string $reachedAtUtc Y-m-d H:i:s, UTC
     * @return void
     */
    public function recordFirst(int $orderId, string $status, string $reachedAtUtc): void
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getMainTable(),
            [self::ORDER_ID => $orderId, self::STATUS => $status, self::REACHED_AT => $reachedAtUtc],
            [self::ORDER_ID, self::STATUS],
        );
    }

    /**
     * Earliest moment the order reached one of the statuses: the module's own record, or a
     * status-history row written before the order's first withdrawal request. Rows written from the
     * first request on are left out: the module's own order comments carry the order's current status
     * and must not move the start of the period.
     *
     * @param int $orderId
     * @param string[] $statuses
     * @return string|null Y-m-d H:i:s, UTC
     */
    public function findFirstReached(int $orderId, array $statuses): ?string
    {
        if ($statuses === []) {
            return null;
        }
        $connection = $this->getConnection();

        $recorded = $connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), [new \Zend_Db_Expr('MIN(' . self::REACHED_AT . ')')])
                ->where(self::ORDER_ID . ' = ?', $orderId)
                ->where(self::STATUS . ' IN (?)', $statuses),
        );

        $firstRequestAt = $connection->select()
            ->from($this->getTable(Request::DB_TABLE), [new \Zend_Db_Expr('MIN(' . RequestInterface::CREATED_AT . ')')])
            ->where(RequestInterface::ORDER_ID . ' = ?', $orderId);
        $history = $connection->fetchOne(
            $connection->select()
                ->from($this->getTable(self::TABLE_STATUS_HISTORY), ['created_at'])
                ->where('parent_id = ?', $orderId)
                ->where('status IN (?)', $statuses)
                ->where('created_at < COALESCE((' . $firstRequestAt . "), '9999-12-31 23:59:59')")
                ->order('created_at ASC')
                ->limit(1),
        );

        $found = array_filter([$recorded, $history], static fn ($v) => is_string($v) && $v !== '');
        return $found === [] ? null : min($found);
    }
}
