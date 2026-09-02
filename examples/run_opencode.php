<?php

/**
 * @desc OpenCode 运行示例
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Harness\Event;
use Harness\Harness;
use Harness\Job;
use Harness\Runner;

$workspace = sys_get_temp_dir() . '/harness-test-opencode';
if (!is_dir($workspace)) {
    mkdir($workspace, 0755, true);
}

$backend = Harness::byName('opencode');

$job = new Job(
    workspace: $workspace,
    srcDir: '.',
    prompt: 'Refactor database queries for optimized execution.',
    model: 'anthropic/claude-sonnet-4-6',
);

echo "Running OpenCode via Harness...\n";

try {
    Runner::run($backend, $job, function (Event $event): void {
        echo $event->format() . "\n";
    });
} catch (\Throwable $e) {
    echo "Execution failed: " . $e->getMessage() . "\n";
}
