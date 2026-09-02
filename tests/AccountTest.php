<?php

/**
 * @desc 账号错误与限流测试类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

use Harness\Account;
use Harness\RateLimitInfo;

test('match account phrase', function () {
    $text = 'Error: rate limit exceeded. Please retry later.';
    $matched = Account::matchAccountPhrase($text, Account::TRANSIENT_LIMIT_PHRASES);
    expect($matched)->toBe($text);

    $noMatch = 'Syntax error in file.js: Unexpected token';
    expect(Account::matchAccountPhrase($noMatch, Account::TRANSIENT_LIMIT_PHRASES))->toBe('');
});

test('account error resumable', function () {
    expect(Account::accountErrorResumable('429 too many requests'))->toBeTrue()
        ->and(Account::accountErrorResumable('quota exceeded'))->toBeTrue()
        ->and(Account::accountErrorResumable('disabled claude subscription access'))->toBeFalse()
        ->and(Account::accountErrorResumable('random error'))->toBeFalse();
});

test('prefer account error text', function () {
    $transient = '429 too many requests';
    $permanent = 'disabled claude subscription access';

    // Permanent should displace transient
    expect(Account::preferAccountErrorText($transient, $permanent))->toBe($permanent)
        ->and(Account::preferAccountErrorText($permanent, $transient))->toBe($permanent);
});

test('prefer rate limit reset', function () {
    $r1 = new RateLimitInfo(status: 'rejected', resetsAt: 1000);
    $r2 = new RateLimitInfo(status: 'rejected', resetsAt: 2000);

    expect(Account::preferRateLimitReset($r1, $r2))->toBe($r2)
        ->and(Account::preferRateLimitReset($r2, $r1))->toBe($r2);
});
