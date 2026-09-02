<?php

/**
 * @desc Codex CLI 后端单元测试类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

use Harness\Backends\CodexHarness;
use Harness\Event;
use Harness\EventKind;
use Harness\Job;

beforeEach(function () {
    $this->harness = new CodexHarness();
});

test('codex getBinary returns codex', function () {
    expect($this->harness->getBinary())->toBe('codex');
});

test('codex getArgs builds correct CLI parameters', function () {
    $job = new Job(workspace: '/work', skillName: 'code-review', model: 'gpt-5.3-codex', outputFile: 'out.json');

    $args = $this->harness->getArgs($job);

    expect($args)
        ->toContain('exec')
        ->toContain('--json')
        ->toContain('--sandbox')
        ->toContain('danger-full-access')
        ->toContain('--model')
        ->toContain('gpt-5.3-codex');
});

test('codex parseStream maps stream to neutral events', function () {
    $jsonl = implode("\n", [
        json_encode(['type' => 'session.created', 'session_id' => 'codex-sess-1']),
        json_encode([
            'type' => 'item.completed',
            'item' => [
                'type' => 'command',
                'command' => 'composer test',
            ],
        ]),
        json_encode([
            'type' => 'item.completed',
            'item' => [
                'type' => 'text',
                'text' => 'All tests passed.',
            ],
        ]),
        json_encode([
            'type' => 'turn.completed',
            'usage' => [
                'input_tokens' => 200,
                'output_tokens' => 80,
                'cached_input_tokens' => 50,
            ],
        ]),
    ]);

    /** @var Event[] $events */
    $events = [];
    $this->harness->parseStream($jsonl, function (Event $e) use (&$events): void {
        $events[] = $e;
    });

    expect($events)
        ->toHaveCount(4)
        ->and($events[0]->getKindValue())
        ->toBe(EventKind::Session->value)
        ->and($events[0]->sessionID)
        ->toBe('codex-sess-1')
        ->and($events[1]->getKindValue())
        ->toBe(EventKind::Tool->value)
        ->and($events[1]->tool)
        ->toBe('command')
        ->and($events[1]->text)
        ->toBe('composer test')
        ->and($events[2]->getKindValue())
        ->toBe(EventKind::Text->value)
        ->and($events[2]->text)
        ->toBe('All tests passed.')
        ->and($events[3]->getKindValue())
        ->toBe(EventKind::Result->value)
        ->and($events[3]->usage->inputTokens)
        ->toBe(200)
        ->and($events[3]->usage->outputTokens)
        ->toBe(80)
        ->and($events[3]->usage->cacheReadTokens)
        ->toBe(50);
});
