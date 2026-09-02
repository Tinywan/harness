<?php

/**
 * @desc GitHub Copilot CLI 运行示例
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Harness\Event;
use Harness\Harness;
use Harness\Job;
use Harness\Runner;

$workspace = sys_get_temp_dir() . '/harness-test-copilot';
if (!is_dir($workspace)) {
    mkdir($workspace, 0755, true);
}

$backend = Harness::byName('copilot');

$job = new Job(
    workspace: $workspace,
    srcDir: '.',
    prompt: 'Check code for security vulnerabilities and output findings.',
    model: 'gpt-5.6-sol',
    maxTurns: 15,
);

echo "Running GitHub Copilot CLI via Harness...\n";

try {
    Runner::run($backend, $job, function (Event $event): void {
        echo $event->format() . "\n";
    });
} catch (\Throwable $e) {
    echo 'Execution failed: ' . $e->getMessage() . "\n";
}
