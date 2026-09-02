<?php

/**
 * @desc 模型 Token 价格表与费用计算类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness;

class Pricing
{
    private const PER_MILLION = 1e6;

    /**
     * USD list price per million tokens.
     * [in, out, cached_in, cache_write]
     *
     * @var array<string, array{in: float, out: float, cached_in: float, cache_write?: float}>
     */
    public static array $modelPricing = [
        // Anthropic
        'claude-opus-4-6' => ['in' => 5.00, 'out' => 25.00, 'cached_in' => 0.50, 'cache_write' => 6.25],
        'claude-opus-4-7' => ['in' => 5.00, 'out' => 25.00, 'cached_in' => 0.50, 'cache_write' => 6.25],
        'claude-opus-4-8' => ['in' => 5.00, 'out' => 25.00, 'cached_in' => 0.50, 'cache_write' => 6.25],
        'claude-opus-5' => ['in' => 5.00, 'out' => 25.00, 'cached_in' => 0.50, 'cache_write' => 6.25],
        'claude-sonnet-4-6' => ['in' => 3.00, 'out' => 15.00, 'cached_in' => 0.30, 'cache_write' => 3.75],
        'claude-sonnet-5' => ['in' => 3.00, 'out' => 15.00, 'cached_in' => 0.30, 'cache_write' => 3.75],
        'claude-haiku-4-5' => ['in' => 1.00, 'out' => 5.00, 'cached_in' => 0.10, 'cache_write' => 1.25],
        'claude-fable-5' => ['in' => 10.00, 'out' => 50.00, 'cached_in' => 1.00, 'cache_write' => 12.50],
        'claude-fable-5-1' => ['in' => 10.00, 'out' => 50.00, 'cached_in' => 0.25, 'cache_write' => 12.50],

        // Copilot uses dotted Anthropic version ids
        'claude-opus-4.6' => ['in' => 5.00, 'out' => 25.00, 'cached_in' => 0.50, 'cache_write' => 6.25],
        'claude-opus-4.7' => ['in' => 5.00, 'out' => 25.00, 'cached_in' => 0.50, 'cache_write' => 6.25],
        'claude-opus-4.8' => ['in' => 5.00, 'out' => 25.00, 'cached_in' => 0.50, 'cache_write' => 6.25],
        'claude-sonnet-4.6' => ['in' => 3.00, 'out' => 15.00, 'cached_in' => 0.30, 'cache_write' => 3.75],
        'claude-haiku-4.5' => ['in' => 1.00, 'out' => 5.00, 'cached_in' => 0.10, 'cache_write' => 1.25],

        // OpenAI
        'gpt-5.6-sol' => ['in' => 2.00, 'out' => 10.00, 'cached_in' => 0.20, 'cache_write' => 2.50],
        'gpt-5.6-terra' => ['in' => 2.00, 'out' => 12.00, 'cached_in' => 0.20, 'cache_write' => 2.50],
        'gpt-5.6-luna' => ['in' => 0.20, 'out' => 1.20, 'cached_in' => 0.02, 'cache_write' => 0.25],
        'gpt-5.5' => ['in' => 5.00, 'out' => 30.00, 'cached_in' => 0.50],
        'gpt-5.4' => ['in' => 2.50, 'out' => 15.00, 'cached_in' => 0.25],
        'gpt-5.4-mini' => ['in' => 0.75, 'out' => 4.50, 'cached_in' => 0.075],
        'gpt-5.3-codex' => ['in' => 1.75, 'out' => 14.00, 'cached_in' => 0.175],
        'gpt-5.2' => ['in' => 1.75, 'out' => 14.00, 'cached_in' => 0.175],
        'gpt-5-mini' => ['in' => 0.25, 'out' => 2.00, 'cached_in' => 0.02],

        // Copilot-hosted Microsoft, Google, and xAI models
        'mai-code-1-flash-picker' => ['in' => 0.75, 'out' => 4.50, 'cached_in' => 0.07],
        'mai-code-1.1-flash' => ['in' => 0.20, 'out' => 1.20, 'cached_in' => 0.02, 'cache_write' => 0.25],
        'gemini-3.7-flash' => ['in' => 0.75, 'out' => 3.75, 'cached_in' => 0.07],
        'gemini-3.6-flash' => ['in' => 0.75, 'out' => 3.75, 'cached_in' => 0.07],
        'gemini-3.5-flash' => ['in' => 1.50, 'out' => 9.00, 'cached_in' => 0.15],
        'gemini-3.1-pro-preview' => ['in' => 2.00, 'out' => 12.00, 'cached_in' => 0.20],
        'grok-4.5' => ['in' => 2.00, 'out' => 6.00, 'cached_in' => 0.50],
        'grok-4.6' => ['in' => 2.00, 'out' => 6.00, 'cached_in' => 0.50],
    ];

    /**
     * Calculates a result event's list-price cost in USD.
     * Returns 0.0 for an unknown model.
     */
    public static function costFromUsage(string $model, ?Usage $usage): float
    {
        if ($usage === null) {
            return 0.0;
        }

        $normalized = self::normalizeModelId($model);
        if (!isset(self::$modelPricing[$normalized])) {
            return 0.0;
        }

        $price = self::$modelPricing[$normalized];
        $cacheWriteRate = $price['cache_write'] ?? 0.0;

        $uncached = $usage->inputTokens - $usage->cacheReadTokens;
        if ($cacheWriteRate > 0) {
            $uncached -= $usage->cacheWriteTokens;
        }
        if ($uncached < 0) {
            $uncached = 0;
        }

        $cost = ($uncached * $price['in']
            + $usage->cacheReadTokens * $price['cached_in']
            + $usage->cacheWriteTokens * $cacheWriteRate
            + $usage->outputTokens * $price['out']) / self::PER_MILLION;

        return round($cost, 6);
    }

    /**
     * Removes OpenCode's provider prefix (e.g. anthropic/...) and a context-window variant suffix (e.g. [1m]).
     */
    public static function normalizeModelId(string $id): string
    {
        if (($slash = strrpos($id, '/')) !== false) {
            $id = substr($id, $slash + 1);
        }
        if (($bracket = strpos($id, '[')) !== false && $bracket > 0) {
            $id = substr($id, 0, $bracket);
        }

        return $id;
    }
}
