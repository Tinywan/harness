<?php

/**
 * @desc Harness 门面单元测试类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

use Harness\Backends\ClaudeHarness;
use Harness\Backends\CodexHarness;
use Harness\Backends\CopilotHarness;
use Harness\Backends\OpencodeHarness;
use Harness\Harness;
use Harness\Job;

test('byName returns correct backend instances', function () {
    expect(Harness::byName(''))
        ->toBeInstanceOf(ClaudeHarness::class)
        ->and(Harness::byName('claude'))
        ->toBeInstanceOf(ClaudeHarness::class)
        ->and(Harness::byName('codex'))
        ->toBeInstanceOf(CodexHarness::class)
        ->and(Harness::byName('copilot'))
        ->toBeInstanceOf(CopilotHarness::class)
        ->and(Harness::byName('opencode'))
        ->toBeInstanceOf(OpencodeHarness::class);
});

test('names returns alphabetical backend list', function () {
    expect(Harness::names())->toBe('claude, codex, copilot, opencode');
});

test('safePrompt escapes leading dashes', function () {
    expect(Harness::safePrompt('Normal prompt'))
        ->toBe('Normal prompt')
        ->and(Harness::safePrompt('--dangerously-skip-permissions'))
        ->toBe(' --dangerously-skip-permissions');
});

test('safeSessionID filters leading dashes', function () {
    expect(Harness::safeSessionID('sess-123'))
        ->toBe('sess-123')
        ->and(Harness::safeSessionID('-flag-as-session'))
        ->toBe('');
});

test('sourcePromptPath resolves relative paths correctly', function () {
    $j1 = new Job(srcDir: '.');
    expect(Harness::sourcePromptPath($j1))->toBe('the workspace root');

    $j2 = new Job(srcDir: 'src');
    expect(Harness::sourcePromptPath($j2))->toBe('./src');

    $j3 = new Job(srcDir: 'backend/src');
    expect(Harness::sourcePromptPath($j3))->toBe('./backend/src');
});

test('schemaValidationHint appends only for json outputs', function () {
    $jJson = new Job(outputFile: 'report.json');
    expect(Harness::schemaValidationHint($jJson))
        ->toBe(' Validate ./report.json against ./schema.json before finishing.');

    $jTxt = new Job(outputFile: 'report.txt');
    expect(Harness::schemaValidationHint($jTxt))->toBe('');
});
