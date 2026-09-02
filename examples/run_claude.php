<?php

/**
 * @desc Claude Code 运行示例
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Harness\Egress\Sandbox;
use Harness\Event;
use Harness\Harness;
use Harness\Job;
use Harness\Runner;
use Harness\Skills\SkillDelivery;
use Harness\Skills\SkillParser;

$workspace = sys_get_temp_dir() . '/harness-test-claude';
if (!is_dir($workspace)) {
    mkdir($workspace, 0755, true);
}

$backend = Harness::byName('claude');

// Optional: write sandbox settings
Sandbox::writeSandboxSettings($workspace, $backend->getEgressHosts());

$job = new Job(
    workspace: $workspace,
    srcDir: '.',
    prompt: '审查/检查这个项目，找出潜在的性能瓶颈。',
    model: 'claude-sonnet-4-6',
    maxTurns: 10,
);

echo "Running Claude Code via Harness...\n";

try {
    Runner::run($backend, $job, function (Event $event): void {
        echo $event->format() . "\n";
    });
} catch (\Throwable $e) {
    echo "Execution failed: " . $e->getMessage() . "\n";
}
