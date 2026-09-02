<?php

/**
 * @desc 事件类型枚举
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness;

enum EventKind: string
{
    case Thinking = 'thinking';
    case Text = 'text';
    case Tool = 'tool';
    case Result = 'result';
    case Error = 'error';
    case Session = 'session';
    case RateLimit = 'rate_limit';
    case Egress = 'egress';
}
