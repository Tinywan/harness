<?php

/**
 * @desc 速率限制与配额状态信息类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness;

use DateTimeImmutable;
use DateTimeZone;

class RateLimitInfo
{
    public function __construct(
        public string $status = '',
        public string $overageStatus = '',
        public bool $isUsingOverage = false,
        public int $resetsAt = 0,
        public string $type = '',
    ) {
    }

    /**
     * Converts resetsAt (Unix timestamp) to a UTC DateTimeImmutable.
     * Returns null for absent or invalid reset time.
     */
    public function getResetTime(): ?DateTimeImmutable
    {
        if ($this->resetsAt <= 0) {
            return null;
        }

        return (new DateTimeImmutable("@{$this->resetsAt}"))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Reports whether the limit currently blocks requests.
     */
    public function isRejected(): bool
    {
        if (strcasecmp($this->status, 'rejected') === 0) {
            return true;
        }

        // Accounts without paid overage commonly report overageStatus: "rejected".
        // It blocks a request only when the account is currently using overage.
        return $this->isUsingOverage && strcasecmp($this->overageStatus, 'rejected') === 0;
    }
}
