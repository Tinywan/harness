<?php

/**
 * @desc 价格与费用计算单元测试类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

use Harness\Pricing;
use Harness\Usage;

test('normalize model id', function () {
    expect(Pricing::normalizeModelId('anthropic/claude-sonnet-4-6'))->toBe('claude-sonnet-4-6')
        ->and(Pricing::normalizeModelId('claude-fable-5[1m]'))->toBe('claude-fable-5')
        ->and(Pricing::normalizeModelId('anthropic/claude-fable-5[1m]'))->toBe('claude-fable-5')
        ->and(Pricing::normalizeModelId('gpt-5.3-codex'))->toBe('gpt-5.3-codex');
});

test('cost from usage calculation', function () {
    // claude-sonnet-4-6: in=3.00, out=15.00, cached_in=0.30, cache_write=3.75 per million
    $usage = new Usage(
        inputTokens: 1000000,
        outputTokens: 500000,
        cacheReadTokens: 200000,
        cacheWriteTokens: 100000
    );

    // uncached = 1000000 - 200000 - 100000 = 700000
    // cost = (700000 * 3.00 + 200000 * 0.30 + 100000 * 3.75 + 500000 * 15.00) / 1000000 = 10.035
    $cost = Pricing::costFromUsage('claude-sonnet-4-6', $usage);
    expect($cost)->toBe(10.035);
});

test('cost for unknown model returns zero', function () {
    $usage = new Usage(inputTokens: 1000, outputTokens: 1000);
    expect(Pricing::costFromUsage('unknown-custom-model', $usage))->toBe(0.0);
});
