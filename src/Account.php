<?php

/**
 * @desc 账号错误匹配与限流恢复辅助类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness;

use DateTimeImmutable;

class Account
{
    /**
     * Phrases that indicate transient limits that may recover after a reset time.
     *
     * @var string[]
     */
    public const TRANSIENT_LIMIT_PHRASES = [
        'usage limit',
        'session limit',
        'plan limit',
        'rate limit',
        'rate_limit',
        'too many requests',
        'quota exceeded',
        'credit balance',
        '429',
    ];

    /**
     * Phrases that identify account problems requiring operator action,
     * not an automatic retry after a reset time.
     *
     * @var string[]
     */
    public const ACCESS_REVOKED_PHRASES = [
        'disabled claude subscription access',
        'use an anthropic api key instead',
        'ask your admin to enable access',
        'access has been revoked',
    ];

    /**
     * Returns the trimmed input when any phrase matches. Callers should invoke it
     * only after a backend exits non-zero.
     *
     * @param string[] ...$lists
     */
    public static function matchAccountPhrase(string $s, array ...$lists): string
    {
        $text = trim($s);
        if ($text === '') {
            return '';
        }

        $lower = strtolower($text);
        foreach ($lists as $list) {
            foreach ($list as $phrase) {
                if (str_contains($lower, strtolower($phrase))) {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * Recognises Claude usage limits and revoked access.
     */
    public static function claudeAccountErrorText(string $s): string
    {
        return self::matchAccountPhrase($s, self::TRANSIENT_LIMIT_PHRASES, self::ACCESS_REVOKED_PHRASES);
    }

    /**
     * Detects permanent account failures that must never drive an automatic resume.
     */
    public static function accountErrorAccessRevoked(string $s): bool
    {
        $lower = strtolower($s);
        foreach (self::ACCESS_REVOKED_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether an account error describes a transient limit.
     */
    public static function accountErrorResumable(string $s): bool
    {
        if (self::accountErrorAccessRevoked($s)) {
            return false;
        }

        $lower = strtolower($s);
        foreach (self::TRANSIENT_LIMIT_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keeps the first account error unless a later message is non-resumable
     * while the earlier one is a transient limit.
     */
    public static function preferAccountErrorText(string $current, string $candidate): string
    {
        if ($candidate === '') {
            return $current;
        }
        if ($current === '') {
            return $candidate;
        }
        if (!self::accountErrorResumable($candidate) && self::accountErrorResumable($current)) {
            return $candidate;
        }

        return $current;
    }

    /**
     * Returns the rejected rate limit with the later reset so a retry is not
     * scheduled while another reported window still blocks use.
     */
    public static function preferRateLimitReset(?RateLimitInfo $current, ?RateLimitInfo $candidate): ?RateLimitInfo
    {
        if ($candidate === null || !$candidate->isRejected() || $candidate->getResetTime() === null) {
            return $current;
        }
        if ($current === null) {
            return $candidate;
        }

        $currentReset = $current->getResetTime();
        $candidateReset = $candidate->getResetTime();
        if ($currentReset === null || $candidateReset > $currentReset) {
            return $candidate;
        }

        return $current;
    }

    /**
     * Returns a rejected limit's reset time only when the associated account error is transient.
     */
    public static function resumableReset(string $errText, ?RateLimitInfo $limit): ?DateTimeImmutable
    {
        if (!self::accountErrorResumable($errText) || $limit === null || !$limit->isRejected()) {
            return null;
        }

        return $limit->getResetTime();
    }
}
