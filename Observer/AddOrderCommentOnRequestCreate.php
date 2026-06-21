<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Observer;

use MageMe\EUWithdrawal\Api\Data\RequestInterface;
use MageMe\EUWithdrawal\Api\RequestRepositoryInterface;
use MageMe\EUWithdrawal\Model\ModuleConfig;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Records a status-history comment on the related sales order when a withdrawal
 * request is filed, so the store team sees the full lifecycle in the order
 * timeline (submitted → approve / deny / cancel; the resolutions are added by
 * AddOrderCommentOnStatusChange).
 *
 * The order status is left untouched — only a comment is added. No-throw: any
 * failure is logged and swallowed so it can never break request creation.
 */
class AddOrderCommentOnRequestCreate implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param RequestRepositoryInterface $requestRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param ModuleConfig $moduleConfig
     * @param PriceCurrencyInterface $priceCurrency
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestRepositoryInterface $requestRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ModuleConfig $moduleConfig,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->moduleConfig->isEnabled()) {
            return;
        }
        try {
            $requestId = (int) ($observer->getEvent()->getData('request_id') ?? 0);
            if ($requestId === 0) {
                return;
            }
            $request = $this->requestRepository->get($requestId);
            $orderId = (int) $request->getOrderId();
            if ($orderId <= 0) {
                return;
            }
            $order = $this->orderRepository->get($orderId);
            if (!$order instanceof Order) {
                return;
            }
            $order->addCommentToStatusHistory($this->buildComment($request, $order));
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'EUWithdrawal: failed to add order comment on request create: ' . $e->getMessage(),
                ['event' => $observer->getEvent()?->getName()],
            );
        }
    }

    private function buildComment(RequestInterface $request, Order $order): string
    {
        $reference = (string) ($request->getIncrementId() ?? ('#' . (int) $request->getRequestId()));
        $refund = (float) $request->getTotalRefund();

        if ($refund > 0.0) {
            $formatted = $this->priceCurrency->format(
                $refund,
                false,
                2,
                null,
                $order->getOrderCurrencyCode(),
            );
            return (string) __('EU withdrawal request %1 submitted. Requested refund: %2.', $reference, $formatted);
        }

        return (string) __('EU withdrawal request %1 submitted.', $reference);
    }
}
