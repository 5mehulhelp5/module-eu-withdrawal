<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\ResourceModel;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Model\ResourceModel\Type\Db\ConnectionFactory;
use Magento\Sales\Model\Order\Creditmemo;

class Request extends AbstractDb
{
    public const DB_TABLE = 'mm_eu_withdrawal_request';

    /**
     * How long a refund in flight holds the request without renewing its hold. A hold that runs out does
     * not free the request: its outcome is unknown until staff resolve it.
     */
    public const REFUND_LEASE_SECONDS = 300;

    /**
     * How long after its hold ran out a refund may still finish: a payment provider that answers late.
     * Until then a request whose hold ran out takes no new refund, and its unknown outcome cannot be
     * cleared. An outcome recorded by a refund that failed after its payment step is final at once.
     */
    public const REFUND_OUTCOME_GRACE_SECONDS = 3600;

    public const LEASE_HOLDER_AUTO = 'auto';
    public const LEASE_HOLDER_ADMIN = 'admin';

    public const REFUND_STATE_FREE = 'free';
    public const REFUND_STATE_HELD_AUTO = self::LEASE_HOLDER_AUTO;
    public const REFUND_STATE_HELD_ADMIN = self::LEASE_HOLDER_ADMIN;
    public const REFUND_STATE_OUTCOME_UNKNOWN = 'outcome_unknown';

    public const RENEW_DONE = 'done';
    public const RENEW_LOST = 'lost';
    public const RENEW_SKIPPED = 'skipped';

    private const TABLE_CREDITMEMO = 'sales_creditmemo';
    private const TABLE_CREDITMEMO_ITEM = 'sales_creditmemo_item';

    /**
     * No refund holds the request and none left it with an unknown outcome.
     */
    private const HOLD_FREE = '(' . RequestInterface::REFUND_LEASE_HOLDER . ' IS NULL AND '
        . RequestInterface::REFUND_OUTCOME_UNKNOWN_AT . ' IS NULL)';

    /**
     * A refund holds the request and its hold has not run out.
     */
    private const LIVE_HOLD = '(' . RequestInterface::REFUND_LEASE_HOLDER . ' IS NOT NULL AND '
        . RequestInterface::REFUND_LEASE_UNTIL . ' > UTC_TIMESTAMP())';

    private const NO_LIVE_HOLD = 'NOT ' . self::LIVE_HOLD;

    /**
     * A hold ran out less than REFUND_OUTCOME_GRACE_SECONDS ago and its refund never reported back: it may
     * still finish.
     */
    private const HOLD_MAY_STILL_FINISH = '(' . RequestInterface::REFUND_LEASE_HOLDER . ' IS NOT NULL AND '
        . RequestInterface::REFUND_OUTCOME_UNKNOWN_AT . ' IS NULL AND '
        . RequestInterface::REFUND_LEASE_UNTIL . ' > UTC_TIMESTAMP() - INTERVAL ' . self::REFUND_OUTCOME_GRACE_SECONDS . ' SECOND)';

    /**
     * A refund failed after its payment step, or its hold ran out before it reported an outcome.
     */
    private const OUTCOME_UNKNOWN = '(' . RequestInterface::REFUND_OUTCOME_UNKNOWN_AT . ' IS NOT NULL OR ('
        . RequestInterface::REFUND_LEASE_HOLDER . ' IS NOT NULL AND '
        . RequestInterface::REFUND_LEASE_UNTIL . ' <= UTC_TIMESTAMP()))';

    private const NO_HOLD = [
        RequestInterface::REFUND_LEASE_UNTIL => null,
        RequestInterface::REFUND_LEASE_HOLDER => null,
        RequestInterface::REFUND_LEASE_TOKEN => null,
    ];

    /**
     * Constructor.
     *
     * @param Context $context
     * @param ConnectionFactory $connectionFactory
     * @param DeploymentConfig $deploymentConfig
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        private readonly ConnectionFactory $connectionFactory,
        private readonly DeploymentConfig $deploymentConfig,
        $connectionName = null,
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * Construct.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(self::DB_TABLE, 'request_id');
    }

    /**
     * The request's row as stored, or null when there is no such request.
     *
     * @param int $requestId
     * @return array<string, mixed>|null
     */
    public function fetchRequestRow(int $requestId): ?array
    {
        $conn = $this->getConnection();
        $row = $conn->fetchRow(
            $conn->select()
                ->from($this->getMainTable())
                ->where($this->getIdFieldName() . ' = ?', $requestId),
        );

        return $row ?: null;
    }

    /**
     * Claims the request for one automatic credit memo issue and holds it for REFUND_LEASE_SECONDS. The
     * single conditional UPDATE succeeds only while no memo is linked, no manual reimbursement-paid mark
     * is set, no earlier issue has claimed the row and no refund holds it or left it with an unknown
     * outcome, so of two overlapping issues at most one gets true.
     *
     * @param int $requestId
     * @param string|null $token identifies this issue's hold for renewRefundLease, releaseRefundIssue
     *   and markRefundOutcomeUnknown
     * @return bool
     */
    public function claimRefundIssue(int $requestId, ?string $token = null): bool
    {
        $connection = $this->getConnection();

        return $connection->update(
            $this->getMainTable(),
            [
                RequestInterface::REFUND_ISSUE_CLAIMED_AT => new \Zend_Db_Expr('UTC_TIMESTAMP()'),
                RequestInterface::REFUND_LEASE_UNTIL => $this->leaseEnd(),
                RequestInterface::REFUND_LEASE_HOLDER => self::LEASE_HOLDER_AUTO,
                RequestInterface::REFUND_LEASE_TOKEN => $token ?? self::newLeaseToken(),
            ],
            [
                'request_id = ?' => $requestId,
                RequestInterface::REFUND_CREDITMEMO_ID . ' IS NULL',
                RequestInterface::REIMBURSEMENT_PAID_AT . ' IS NULL',
                RequestInterface::REFUND_ISSUE_CLAIMED_AT . ' IS NULL',
                self::HOLD_FREE,
            ],
        ) === 1;
    }

    /**
     * Gives back the automatic issue's hold after the issue failed before its payment step: the
     * request then counts as interrupted.
     *
     * @param int $requestId
     * @param string|null $token the issue's token; null ends any automatic hold
     * @return void
     */
    public function releaseRefundIssue(int $requestId, ?string $token = null): void
    {
        $where = [
            'request_id = ?' => $requestId,
            RequestInterface::REFUND_LEASE_HOLDER . ' = ?' => self::LEASE_HOLDER_AUTO,
        ];
        if ($token !== null) {
            $where[RequestInterface::REFUND_LEASE_TOKEN . ' = ?'] = $token;
        }
        $this->getConnection()->update($this->getMainTable(), self::NO_HOLD, $where);
    }

    /**
     * Holds the request for a refund from its credit memo form, for REFUND_LEASE_SECONDS. The single
     * conditional UPDATE succeeds only while no memo is linked, no manual paid mark is set and no other
     * refund holds the request or left it with an unknown outcome. With $overOutcomeUnknown, a request
     * whose outcome is unknown and that no refund holds right now is held too, and stays unknown until
     * the memo is linked: staff record a refund the payment provider already made with an offline memo.
     * Not while a hold that ran out may still finish (REFUND_OUTCOME_GRACE_SECONDS).
     * Returns the hold's token, or null when the request was not held.
     *
     * @param int $requestId
     * @param bool $overOutcomeUnknown
     * @return string|null
     */
    public function reserveRefund(int $requestId, bool $overOutcomeUnknown = false): ?string
    {
        $token = self::newLeaseToken();
        $hold = [
            RequestInterface::REFUND_LEASE_UNTIL => $this->leaseEnd(),
            RequestInterface::REFUND_LEASE_HOLDER => self::LEASE_HOLDER_ADMIN,
            RequestInterface::REFUND_LEASE_TOKEN => $token,
        ];
        $unrefunded = [
            'request_id = ?' => $requestId,
            RequestInterface::REFUND_CREDITMEMO_ID . ' IS NULL',
            RequestInterface::REIMBURSEMENT_PAID_AT . ' IS NULL',
        ];
        $connection = $this->getConnection();
        if ($connection->update($this->getMainTable(), $hold, array_merge($unrefunded, [self::HOLD_FREE])) === 1) {
            return $token;
        }
        if (!$overOutcomeUnknown) {
            return null;
        }
        $hold[RequestInterface::REFUND_OUTCOME_UNKNOWN_AT] = new \Zend_Db_Expr(
            'COALESCE(' . RequestInterface::REFUND_OUTCOME_UNKNOWN_AT . ', UTC_TIMESTAMP())',
        );

        return $connection->update(
            $this->getMainTable(),
            $hold,
            array_merge($unrefunded, [self::OUTCOME_UNKNOWN, self::NO_LIVE_HOLD, 'NOT ' . self::HOLD_MAY_STILL_FINISH]),
        ) === 1 ? $token : null;
    }

    /**
     * Gives back a hold reserveRefund took, unless the link of the memo already finished it. An unknown
     * outcome the hold was taken over stays.
     *
     * @param int $requestId
     * @param string $token what reserveRefund returned
     * @return void
     */
    public function releaseRefund(int $requestId, string $token): void
    {
        $this->getConnection()->update(
            $this->getMainTable(),
            self::NO_HOLD,
            [
                'request_id = ?' => $requestId,
                RequestInterface::REFUND_LEASE_TOKEN . ' = ?' => $token,
            ],
        );
    }

    /**
     * Records that a refund failed after its payment step started: the payment provider may have
     * refunded the customer, so the request keeps every new refund out until staff resolve it, and the
     * refund's hold ends. When another refund holds the request now, its outcome is left to it and false
     * is returned: the caller tells staff the customer may be refunded twice.
     *
     * @param int $requestId
     * @param string $token
     * @return bool
     */
    public function markRefundOutcomeUnknown(int $requestId, string $token): bool
    {
        $connection = $this->getConnection();
        $mine = '(' . RequestInterface::REFUND_LEASE_HOLDER . ' IS NULL OR '
            . $connection->quoteInto(RequestInterface::REFUND_LEASE_TOKEN . ' = ?', $token) . ')';
        $connection->update(
            $this->getMainTable(),
            [
                RequestInterface::REFUND_OUTCOME_UNKNOWN_AT => new \Zend_Db_Expr(
                    'COALESCE(' . RequestInterface::REFUND_OUTCOME_UNKNOWN_AT . ', UTC_TIMESTAMP())',
                ),
            ],
            [
                'request_id = ?' => $requestId,
                RequestInterface::REFUND_CREDITMEMO_ID . ' IS NULL',
                RequestInterface::REIMBURSEMENT_PAID_AT . ' IS NULL',
                $mine,
            ],
        );
        $connection->update(
            $this->getMainTable(),
            self::NO_HOLD,
            [
                'request_id = ?' => $requestId,
                RequestInterface::REFUND_LEASE_TOKEN . ' = ?' => $token,
            ],
        );

        return $connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), ['request_id'])
                ->where('request_id = ?', $requestId)
                ->where(RequestInterface::REFUND_LEASE_HOLDER . ' IS NOT NULL'),
        ) === false;
    }

    /**
     * Pushes the end of a live hold REFUND_LEASE_SECONDS past now. A hold that already ran out is not
     * renewed: its outcome is unknown once it runs out, whatever its refund does next. Inside an open
     * transaction the renewal goes through a connection of its own, opened for this call, so other
     * processes see it at once. Never throws: a renewal that cannot run reports RENEW_SKIPPED.
     *
     * @param int $requestId
     * @param string $token
     * @return string RENEW_DONE; RENEW_LOST when the hold is no longer this refund's or ran out;
     *   RENEW_SKIPPED when the row was locked or the database failed, and the hold stays as it was
     */
    public function renewRefundLease(int $requestId, string $token): string
    {
        $connection = $this->getConnection();
        $side = null;
        if ($connection->getTransactionLevel() > 0) {
            try {
                $side = $this->connectionFactory->create(
                    $this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTION_DEFAULT),
                );
            } catch (\Throwable) {
                return self::RENEW_SKIPPED;
            }
            $connection = $side;
        }

        try {
            $connection->beginTransaction();
            // Checked under the row's lock rather than by the rows the UPDATE changes: a renewal in the same
            // second as the hold writes the same end and changes nothing.
            $held = $connection->query(
                $connection->select()
                    ->from($this->getMainTable(), ['request_id'])
                    ->where('request_id = ?', $requestId)
                    ->where(RequestInterface::REFUND_LEASE_TOKEN . ' = ?', $token)
                    ->where(RequestInterface::REFUND_LEASE_UNTIL . ' > UTC_TIMESTAMP()')
                    ->forUpdate(true)
                    ->assemble() . ($side !== null ? ' NOWAIT' : ''),
            )->fetchColumn() !== false;
            if ($held) {
                $connection->update(
                    $this->getMainTable(),
                    [RequestInterface::REFUND_LEASE_UNTIL => $this->leaseEnd()],
                    ['request_id = ?' => $requestId],
                );
            }
            $connection->commit();

            return $held ? self::RENEW_DONE : self::RENEW_LOST;
        } catch (\Throwable) {
            try {
                if ($connection->getTransactionLevel() > 0) {
                    $connection->rollBack();
                }
            } catch (\Throwable) {
                // Nothing more to undo: the renewal is skipped either way.
            }

            return self::RENEW_SKIPPED;
        } finally {
            $side?->closeConnection();
        }
    }

    /**
     * Where the request stands on refunds right now, by the database clock: REFUND_STATE_HELD_AUTO or
     * REFUND_STATE_HELD_ADMIN while a refund holds it, REFUND_STATE_OUTCOME_UNKNOWN when a refund failed
     * after its payment step or its hold ran out, REFUND_STATE_FREE otherwise. `since` is when an unknown
     * outcome began: the failure, or the end of the hold that ran out. `waits_until` is, for a hold that
     * ran out and may still finish, when its outcome can be cleared or recorded offline.
     *
     * @param int $requestId
     * @return array{state: string, since: string|null, waits_until: string|null}
     */
    public function refundState(int $requestId): array
    {
        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->getMainTable(), [
                    'state' => new \Zend_Db_Expr(
                        'CASE WHEN ' . RequestInterface::REFUND_CREDITMEMO_ID . ' IS NOT NULL OR '
                        . RequestInterface::REIMBURSEMENT_PAID_AT . " IS NOT NULL THEN '" . self::REFUND_STATE_FREE . "'"
                        . ' WHEN ' . self::LIVE_HOLD . ' THEN ' . RequestInterface::REFUND_LEASE_HOLDER
                        . ' WHEN ' . self::OUTCOME_UNKNOWN . " THEN '" . self::REFUND_STATE_OUTCOME_UNKNOWN . "'"
                        . " ELSE '" . self::REFUND_STATE_FREE . "' END",
                    ),
                    'since' => new \Zend_Db_Expr(
                        'COALESCE(' . RequestInterface::REFUND_OUTCOME_UNKNOWN_AT . ', ' . RequestInterface::REFUND_LEASE_UNTIL . ')',
                    ),
                    'waits_until' => new \Zend_Db_Expr(
                        'IF(' . self::HOLD_MAY_STILL_FINISH . ', ' . RequestInterface::REFUND_LEASE_UNTIL
                        . ' + INTERVAL ' . self::REFUND_OUTCOME_GRACE_SECONDS . ' SECOND, NULL)',
                    ),
                ])
                ->where('request_id = ?', $requestId),
        );
        if (!$row) {
            return ['state' => self::REFUND_STATE_FREE, 'since' => null, 'waits_until' => null];
        }
        $state = (string) $row['state'];
        $unknown = $state === self::REFUND_STATE_OUTCOME_UNKNOWN;

        return [
            'state' => $state,
            'since' => $unknown ? (string) $row['since'] : null,
            'waits_until' => $unknown && $row['waits_until'] !== null ? (string) $row['waits_until'] : null,
        ];
    }

    /**
     * Clears an unknown refund outcome after staff confirmed with the payment provider that the
     * customer was not refunded, so the request can be refunded again. Only while no refund holds the
     * request, it is not refunded and no hold that ran out may still finish.
     *
     * @param int $requestId
     * @return bool
     */
    public function clearRefundOutcomeUnknown(int $requestId): bool
    {
        return $this->getConnection()->update(
            $this->getMainTable(),
            self::NO_HOLD + [RequestInterface::REFUND_OUTCOME_UNKNOWN_AT => null],
            [
                'request_id = ?' => $requestId,
                RequestInterface::REFUND_CREDITMEMO_ID . ' IS NULL',
                RequestInterface::REIMBURSEMENT_PAID_AT . ' IS NULL',
                self::OUTCOME_UNKNOWN,
                self::NO_LIVE_HOLD,
                'NOT ' . self::HOLD_MAY_STILL_FINISH,
            ],
        ) === 1;
    }

    /**
     * Records the credit memo a refund of the request just created as its refund, finishes the hold
     * that refund took and ends an unknown outcome it was recorded over. Idempotent: true also when the
     * memo is already the request's refund. False when the request is already refunded otherwise or
     * another request links the memo: linkers of one memo are serialised on the memo's row.
     *
     * @param int $requestId
     * @param int $creditmemoId
     * @param string|null $token the linking refund's hold; with it, a hold another refund holds now stays
     * @return bool
     */
    public function linkRefundCreditmemo(int $requestId, int $creditmemoId, ?string $token = null): bool
    {
        $connection = $this->getConnection();
        $hold = self::NO_HOLD;
        if ($token !== null) {
            // A hold another refund took since this one's ran out stays with that refund. MySQL assigns
            // left to right, so the token, cleared last in NO_HOLD, is still there for each IF before it.
            $mine = $connection->quoteInto(RequestInterface::REFUND_LEASE_TOKEN . ' = ?', $token);
            foreach (array_keys(self::NO_HOLD) as $column) {
                $hold[$column] = new \Zend_Db_Expr('IF(' . $mine . ', NULL, ' . $column . ')');
            }
        }
        $connection->beginTransaction();
        try {
            $this->lockCreditmemo($creditmemoId);
            $linkedTo = $this->requestLinking($creditmemoId);
            $linked = $linkedTo === $requestId || ($linkedTo === null && $connection->update(
                $this->getMainTable(),
                $hold + [
                    RequestInterface::REFUND_CREDITMEMO_ID => $creditmemoId,
                    RequestInterface::REFUND_OUTCOME_UNKNOWN_AT => null,
                ],
                [
                    'request_id = ?' => $requestId,
                    RequestInterface::REFUND_CREDITMEMO_ID . ' IS NULL',
                    // Staff may have marked the request as refunded on seeing this very refund at the
                    // payment provider while it still held the request: its memo is that refund.
                    $token === null
                        ? RequestInterface::REIMBURSEMENT_PAID_AT . ' IS NULL'
                        : '(' . RequestInterface::REIMBURSEMENT_PAID_AT . ' IS NULL OR '
                            . $connection->quoteInto(RequestInterface::REFUND_LEASE_TOKEN . ' = ?', $token) . ')',
                ],
            ) === 1);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $linked;
    }

    /**
     * Links an existing credit memo to the request as its refund, only while the request is not refunded,
     * no refund holds it, no request links the memo and no automatic issue holds another request of the
     * same order (the memo may be the one that issue is about to link). An unknown outcome ends with the
     * link. Linkers of one memo are serialised on the memo's row, so of two requests linking the same
     * memo at once at most one gets true.
     *
     * @param int $requestId
     * @param int $creditmemoId
     * @return bool
     */
    public function linkExistingCreditmemo(int $requestId, int $creditmemoId): bool
    {
        $connection = $this->getConnection();
        $connection->beginTransaction();
        try {
            $this->lockCreditmemo($creditmemoId);
            $autoHeldOnOrder = $connection->select()
                ->from(['o' => $this->getMainTable()], [new \Zend_Db_Expr('1')])
                ->where('o.order_id = r.order_id')
                ->where('o.request_id != r.request_id')
                ->where('o.' . RequestInterface::REFUND_LEASE_HOLDER . ' = ?', self::LEASE_HOLDER_AUTO)
                ->where('o.' . RequestInterface::REFUND_LEASE_UNTIL . ' > UTC_TIMESTAMP()');
            $request = $connection->fetchOne(
                $connection->select()
                    ->from(['r' => $this->getMainTable()], ['request_id'])
                    ->where('r.request_id = ?', $requestId)
                    ->where('NOT EXISTS (?)', $autoHeldOnOrder),
            );
            $linked = $request !== false && $this->requestLinking($creditmemoId) === null && $connection->update(
                $this->getMainTable(),
                self::NO_HOLD + [
                    RequestInterface::REFUND_CREDITMEMO_ID => $creditmemoId,
                    RequestInterface::REFUND_OUTCOME_UNKNOWN_AT => null,
                ],
                [
                    'request_id = ?' => $requestId,
                    RequestInterface::REFUND_CREDITMEMO_ID . ' IS NULL',
                    RequestInterface::REIMBURSEMENT_PAID_AT . ' IS NULL',
                    self::NO_LIVE_HOLD,
                ],
            ) === 1;
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $linked;
    }

    /**
     * Whether an automatic issue holds another request of the request's order right now.
     *
     * @param int $requestId
     * @return bool
     */
    public function isOrderHeldByAutoIssue(int $requestId): bool
    {
        $connection = $this->getConnection();

        return $connection->fetchOne(
            $connection->select()
                ->from(['r' => $this->getMainTable()], [])
                ->join(['o' => $this->getMainTable()], 'o.order_id = r.order_id AND o.request_id != r.request_id', ['o.request_id'])
                ->where('r.request_id = ?', $requestId)
                ->where('o.' . RequestInterface::REFUND_LEASE_HOLDER . ' = ?', self::LEASE_HOLDER_AUTO)
                ->where('o.' . RequestInterface::REFUND_LEASE_UNTIL . ' > UTC_TIMESTAMP()')
                ->limit(1),
        ) !== false;
    }

    /**
     * Sets the manual paid mark, only while no memo is linked, no mark is set and no refund holds the
     * request. Marking a request whose refund outcome is unknown records that the provider refunded it.
     * A hold that ran out and may still finish is kept.
     *
     * @param int $requestId
     * @param string $paidAt UTC Y-m-d H:i:s
     * @return bool
     */
    public function markReimbursementPaid(int $requestId, string $paidAt): bool
    {
        $connection = $this->getConnection();
        $connection->beginTransaction();
        try {
            $marked = $connection->update(
                $this->getMainTable(),
                [
                    RequestInterface::REIMBURSEMENT_PAID_AT => $paidAt,
                    RequestInterface::REFUND_OUTCOME_UNKNOWN_AT => null,
                ],
                [
                    'request_id = ?' => $requestId,
                    RequestInterface::REFUND_CREDITMEMO_ID . ' IS NULL',
                    RequestInterface::REIMBURSEMENT_PAID_AT . ' IS NULL',
                    self::NO_LIVE_HOLD,
                ],
            ) === 1;
            if ($marked) {
                // A hold that ran out and may still finish stays: its refund links its memo when it does,
                // and clearing the mark again brings the wait back.
                $connection->update(
                    $this->getMainTable(),
                    self::NO_HOLD,
                    ['request_id = ?' => $requestId, 'NOT ' . self::HOLD_MAY_STILL_FINISH],
                );
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $marked;
    }

    /**
     * Clears the manual paid mark, only while it is set and no memo is linked.
     *
     * @param int $requestId
     * @return bool
     */
    public function clearReimbursementPaid(int $requestId): bool
    {
        return $this->getConnection()->update(
            $this->getMainTable(),
            [RequestInterface::REIMBURSEMENT_PAID_AT => null],
            [
                'request_id = ?' => $requestId,
                RequestInterface::REFUND_CREDITMEMO_ID . ' IS NULL',
                RequestInterface::REIMBURSEMENT_PAID_AT . ' IS NOT NULL',
            ],
        ) === 1;
    }

    /**
     * Locks the credit memo's row until the transaction ends. Every link of a memo takes this lock first,
     * so the "linked to no request" read after it sees every link committed before.
     *
     * @param int $creditmemoId
     * @return void
     */
    private function lockCreditmemo(int $creditmemoId): void
    {
        $connection = $this->getConnection();
        $connection->fetchOne(
            $connection->select()
                ->from($this->getTable(self::TABLE_CREDITMEMO), ['entity_id'])
                ->where('entity_id = ?', $creditmemoId)
                ->forUpdate(true),
        );
    }

    /**
     * The request that links the credit memo, or null.
     *
     * @param int $creditmemoId
     * @return int|null
     */
    private function requestLinking(int $creditmemoId): ?int
    {
        $connection = $this->getConnection();
        $requestId = $connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), [$this->getIdFieldName()])
                ->where(RequestInterface::REFUND_CREDITMEMO_ID . ' = ?', $creditmemoId)
                ->limit(1),
        );

        return $requestId === false ? null : (int) $requestId;
    }

    /**
     * The end of a hold taken or renewed now, by the database clock.
     *
     * @return \Zend_Db_Expr
     */
    private function leaseEnd(): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr('UTC_TIMESTAMP() + INTERVAL ' . self::REFUND_LEASE_SECONDS . ' SECOND');
    }

    /**
     * A new hold token.
     *
     * @return string
     */
    private static function newLeaseToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Whether a credit memo on the order, cancelled ones aside, refunds any of the given order items.
     *
     * @param int $orderId
     * @param int[] $orderItemIds
     * @return bool
     */
    public function hasMemoOnOrderItems(int $orderId, array $orderItemIds): bool
    {
        if ($orderItemIds === []) {
            return false;
        }

        $connection = $this->getConnection();

        return $connection->fetchOne($this->memoOnOrderItemsSelect($orderId, $orderItemIds)->limit(1)) !== false;
    }

    /**
     * The latest credit memo on the order, cancelled ones aside, created at or after the given time, that
     * refunds at least the given quantity of every given order item and is linked to no withdrawal request.
     *
     * @param int $orderId
     * @param array<int, float> $qtyByOrderItem order_item_id => quantity the memo must refund
     * @param string $createdSince Y-m-d H:i:s, UTC
     * @return int|null
     */
    public function findUnlinkedMemoCovering(int $orderId, array $qtyByOrderItem, string $createdSince): ?int
    {
        if ($qtyByOrderItem === []) {
            return null;
        }

        $connection = $this->getConnection();
        $linked = $connection->select()
            ->from(['r' => $this->getMainTable()], [new \Zend_Db_Expr('1')])
            ->where('r.' . RequestInterface::REFUND_CREDITMEMO_ID . ' = cm.entity_id');
        $select = $this->memoOnOrderItemsSelect($orderId, array_keys($qtyByOrderItem))
            ->columns(['cmi.order_item_id', 'qty' => new \Zend_Db_Expr('SUM(cmi.qty)')])
            ->where('NOT EXISTS (?)', $linked)
            ->where('cm.created_at >= ?', $createdSince)
            ->group(['cm.entity_id', 'cmi.order_item_id'])
            ->order('cm.entity_id DESC');

        $refunded = [];
        foreach ($connection->fetchAll($select) as $row) {
            $refunded[(int) $row['entity_id']][(int) $row['order_item_id']] = (float) $row['qty'];
        }
        foreach ($refunded as $memoId => $qtys) {
            $covers = true;
            foreach ($qtyByOrderItem as $orderItemId => $qty) {
                if (($qtys[$orderItemId] ?? 0.0) + 0.0001 < $qty) {
                    $covers = false;
                    break;
                }
            }
            if ($covers) {
                return $memoId;
            }
        }

        return null;
    }

    /**
     * Whether a withdrawal request already links the credit memo.
     *
     * @param int $creditmemoId
     * @return bool
     */
    public function isCreditmemoLinked(int $creditmemoId): bool
    {
        $connection = $this->getConnection();

        return $connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), [$this->getIdFieldName()])
                ->where(RequestInterface::REFUND_CREDITMEMO_ID . ' = ?', $creditmemoId)
                ->limit(1),
        ) !== false;
    }

    /**
     * Credit memos of the order, cancelled ones aside, with an item line refunding any of the order items.
     *
     * @param int $orderId
     * @param int[] $orderItemIds
     * @return Select
     */
    private function memoOnOrderItemsSelect(int $orderId, array $orderItemIds): Select
    {
        return $this->getConnection()->select()
            ->from(['cm' => $this->getTable(self::TABLE_CREDITMEMO)], [])
            ->join(
                ['cmi' => $this->getTable(self::TABLE_CREDITMEMO_ITEM)],
                'cmi.parent_id = cm.entity_id',
                ['cm.entity_id'],
            )
            ->where('cm.order_id = ?', $orderId)
            ->where('cm.state != ?', Creditmemo::STATE_CANCELED)
            ->where('cmi.order_item_id IN (?)', $orderItemIds)
            ->where('cmi.qty > ?', 0);
    }
}
