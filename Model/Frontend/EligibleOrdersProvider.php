<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Frontend;

use MageMe\EUWithdrawal\Model\Customer\OrderWithdrawalBadgeService;
use MageMe\EUWithdrawal\Model\Frontend\Dto\EligibleOrdersPage;
use MageMe\EUWithdrawal\Model\Rule\PeriodRule;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

class EligibleOrdersProvider
{
    public const DEFAULT_LIMIT = 20;
    public const LIMIT_STEP = 20;
    public const MAX_LIMIT = 200;

    private const SCAN_PAGE_SIZE = 100;
    private const MAX_SCAN_ORDERS = 1000;

    public function __construct(
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PeriodRule $periodRule,
        private readonly OrderWithdrawalBadgeService $badgeService,
        private readonly OrderEligibilityResolver $eligibilityResolver,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Newest-first page of the customer's withdrawal-eligible orders.
     *
     * Eligibility is decided per order in PHP, so the query is paged from the
     * top (created_at DESC) and filtered until $limit eligible are collected,
     * one extra is peeked to report `hasMore`, the order history is exhausted,
     * or the safety scan cap is reached.
     */
    public function forCustomer(int $customerId, int $limit = self::DEFAULT_LIMIT): EligibleOrdersPage
    {
        if ($customerId <= 0) {
            return new EligibleOrdersPage([], false);
        }

        $want = $limit + 1;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $eligible = [];
        $seen = [];
        $scanned = 0;
        $page = 1;
        while (count($eligible) < $want && $scanned < self::MAX_SCAN_ORDERS) {
            $result = $this->fetchOrderPage($customerId, $page);
            // Past the last page the collection returns the last page again: only unseen orders count.
            $orders = array_values(array_filter(
                $result->getItems(),
                static fn (OrderInterface $order): bool => !isset($seen[(int) $order->getEntityId()]),
            ));
            if ($orders === []) {
                if ($scanned < $result->getTotalCount()) {
                    // With a stable sort a page of already-seen orders before the end means the list moved
                    // under the scan: orders after this point may be left out.
                    $this->logger->warning(
                        'EUWithdrawal eligible-orders scan got a page of orders it had already seen before the end of the list',
                        ['customer_id' => $customerId, 'page' => $page, 'scanned' => $scanned, 'total' => $result->getTotalCount()],
                    );
                }
                break;
            }
            foreach ($orders as $order) {
                $seen[(int) $order->getEntityId()] = true;
            }
            $scanned += count($orders);
            $this->collectEligible($orders, $now, $want, $eligible);
            if (count($orders) < self::SCAN_PAGE_SIZE || $scanned >= $result->getTotalCount()) {
                break;
            }
            $page++;
        }

        if (count($eligible) < $want && $scanned >= self::MAX_SCAN_ORDERS) {
            $this->logger->warning(
                'EUWithdrawal eligible-orders scan reached the safety cap before satisfying the request',
                ['customer_id' => $customerId, 'scanned' => $scanned, 'limit' => $limit],
            );
        }

        return new EligibleOrdersPage(array_slice($eligible, 0, $limit), count($eligible) > $limit);
    }

    private function fetchOrderPage(int $customerId, int $page): OrderSearchResultInterface
    {
        $newestFirst = $this->sortOrderBuilder
            ->setField('created_at')
            ->setDirection(SortOrder::SORT_DESC)
            ->create();
        // Orders placed in the same second keep one order across pages.
        $tieBreaker = $this->sortOrderBuilder
            ->setField('entity_id')
            ->setDirection(SortOrder::SORT_DESC)
            ->create();

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('customer_id', $customerId)
            ->addFilter('state', [Order::STATE_CANCELED, Order::STATE_CLOSED], 'nin')
            ->setSortOrders([$newestFirst, $tieBreaker])
            ->setPageSize(self::SCAN_PAGE_SIZE)
            ->setCurrentPage($page)
            ->create();

        return $this->orderRepository->getList($criteria);
    }

    /**
     * @param OrderInterface[] $orders
     * @param OrderInterface[] $eligible accumulator, mutated in place
     */
    private function collectEligible(array $orders, \DateTimeImmutable $now, int $want, array &$eligible): void
    {
        $ids = array_map(static fn (OrderInterface $o) => (int) $o->getEntityId(), $orders);
        $badges = $this->badgeService->getBadges($ids);

        foreach ($orders as $order) {
            if (count($eligible) >= $want) {
                return;
            }
            $id = (int) $order->getEntityId();
            if (($badges[$id] ?? null) === OrderWithdrawalBadgeService::BADGE_FULL) {
                continue;
            }
            // Cheap pre-check before the full engine: drop orders whose period already ended.
            // No period end (nothing dates the delivery yet) keeps the order listed.
            $periodEnd = $this->periodRule->resolvePeriodEnd($order, (int) $order->getStoreId());
            if ($periodEnd !== null && $periodEnd < $now) {
                continue;
            }
            if (!$this->eligibilityResolver->isEligibleForOrder($order)) {
                continue;
            }
            $eligible[] = $order;
        }
    }
}
