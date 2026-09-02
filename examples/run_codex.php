<?php

/**
 * @desc Codex CLI 运行示例
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Harness\Event;
use Harness\Harness;
use Harness\Job;
use Harness\Runner;

$workspace = sys_get_temp_dir() . '/harness-test-codex';
if (!is_dir($workspace)) {
    mkdir($workspace, 0755, true);
}

$backend = Harness::byName('codex');

$job = new Job(
    workspace: $workspace,
    srcDir: '.',
    prompt: '实现一个用于解析 CSV 文件的函数，并为其编写单元测试。',
    model: 'deepseek-v4-pro',
);

echo "Running Codex CLI via Harness...\n";

try {
    Runner::run($backend, $job, function (Event $event): void {
        echo $event->format() . "\n";
    });
} catch (\Throwable $e) {
    echo 'Execution failed: ' . $e->getMessage() . "\n";
}
