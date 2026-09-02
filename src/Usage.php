<?php

/**
 * @desc Token 消耗计量实体类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness;

class Usage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_input_tokens' => $this->cacheReadTokens,
            'cache_creation_input_tokens' => $this->cacheWriteTokens,
        ];
    }
}
