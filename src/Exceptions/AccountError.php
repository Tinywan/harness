<?php

/**
 * @desc 账号配额与访问限制异常类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness\Exceptions;

use DateTimeImmutable;
use RuntimeException;

class AccountError extends RuntimeException
{
    public function __construct(
        public readonly string $detail = '',
        public readonly ?DateTimeImmutable $resetAt = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $message = $this->detail === ''
            ? 'model API account unavailable'
            : 'model API account unavailable: ' . $this->detail;

        parent::__construct($message, $code, $previous);
    }
}
