<?php

/**
 * @desc 默认模型配置信息实体类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness;

class ModelDefault
{
    public function __construct(
        public string $name,
        public string $id,
        public string $tier = '',
    ) {
    }
}
