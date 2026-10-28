<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Refund;

/**
 * The refund of a withdrawal request this process is running right now — from a credit memo form or the
 * automatic issue — and whether it reached core's payment step, where the payment provider may already
 * refund the customer. A failure after that point leaves the outcome unknown; one before it moved no money.
 */
class RefundInFlight
{
    private ?int $requestId = null;

    private ?string $token = null;

    private bool $paymentStarted = false;

    /**
     * Starts tracking the refund that holds $requestId under $token. One refund at a time: a second
     * one started inside the first would hide whether the first reached the payment step.
     *
     * @param int $requestId
     * @param string $token
     * @return void
     */
    public function start(int $requestId, string $token): void
    {
        if ($this->requestId !== null) {
            throw new \LogicException('A withdrawal refund is already running in this process.');
        }
        $this->requestId = $requestId;
        $this->token = $token;
        $this->paymentStarted = false;
    }

    /**
     * Records that the refund reached the payment step.
     *
     * @return void
     */
    public function markPaymentStarted(): void
    {
        if ($this->requestId !== null) {
            $this->paymentStarted = true;
        }
    }

    /**
     * Whether the refund reached the payment step.
     *
     * @return bool
     */
    public function hasPaymentStarted(): bool
    {
        return $this->paymentStarted;
    }

    /**
     * The request and hold token of the refund running now, or null.
     *
     * @return array{request_id: int, token: string}|null
     */
    public function current(): ?array
    {
        return $this->requestId === null || $this->token === null
            ? null
            : ['request_id' => $this->requestId, 'token' => $this->token];
    }

    /**
     * Stops tracking.
     *
     * @return void
     */
    public function finish(): void
    {
        $this->requestId = null;
        $this->token = null;
        $this->paymentStarted = false;
    }
}
