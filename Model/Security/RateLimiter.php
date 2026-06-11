<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Security;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Lock\LockManagerInterface;

class RateLimiter
{
    public const CACHE_PREFIX = 'mm_eu_w_rl_';
    public const CACHE_TAG = 'MAGEME_EU_WITHDRAWAL_RATE_LIMIT';

    private const LOCK_TIMEOUT_SECONDS = 2;

    /**
     * Constructor.
     *
     * @param CacheInterface $cache
     * @param LockManagerInterface $lockManager
     * @param int $budget
     * @param int $windowSeconds
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LockManagerInterface $lockManager,
        private readonly int $budget = 10,
        private readonly int $windowSeconds = 3600,
    ) {
    }

    /**
     * Configured attempt budget per window.
     *
     * @return int
     */
    public function getBudget(): int
    {
        return $this->budget;
    }

    /**
     * Configured window length in seconds.
     *
     * @return int
     */
    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /**
     * Allow.
     *
     * @param string $ipHash
     * @return bool
     */
    public function allow(string $ipHash): bool
    {
        $key = self::CACHE_PREFIX . $ipHash;

        // Serialise the read-modify-write per ipHash. Failing to obtain the
        // lock means heavy same-source contention — deny rather than admit
        // uncounted attempts. TTL refreshes on each counted request
        // (sliding window).
        if (!$this->lockManager->lock($key, self::LOCK_TIMEOUT_SECONDS)) {
            return false;
        }

        try {
            $current = (int) $this->cache->load($key);

            if ($current >= $this->budget) {
                return false;
            }

            $this->cache->save(
                (string) ($current + 1),
                $key,
                [self::CACHE_TAG],
                $this->windowSeconds,
            );
            return true;
        } finally {
            $this->lockManager->unlock($key);
        }
    }
}
