<?php

/**
 * @desc Claude Code 后端单元测试类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

use Harness\Backends\ClaudeHarness;
use Harness\Event;
use Harness\EventKind;
use Harness\Job;

beforeEach(function () {
    $this->harness = new ClaudeHarness();
});

test('claude getBinary returns claude', function () {
    expect($this->harness->getBinary())->toBe('claude');
});

test('claude getArgs builds correct CLI parameters', function () {
    $job = new Job(
        workspace: '/work',
        prompt: 'Test prompt',
        model: 'claude-sonnet-4-6',
        maxTurns: 15,
        effort: 'high',
    );

    $args = $this->harness->getArgs($job);

    expect($args)->toContain('-p')
        ->toContain('--output-format')
        ->toContain('stream-json')
        ->toContain('--model')
        ->toContain('claude-sonnet-4-6')
        ->toContain('--permission-mode')
        ->toContain('bypassPermissions')
        ->toContain('--effort')
        ->toContain('high')
        ->toContain('--max-turns')
        ->toContain('15')
        ->toContain('Test prompt');
});

test('claude parseStream maps jsonl to neutral events', function () {
    $jsonl = implode("\n", [
        json_encode(['type' => 'system', 'subtype' => 'init', 'session_id' => 'sess-abc-123']),
        json_encode([
            'type' => 'assistant',
            'message' => [
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'I am thinking'],
                    ['type' => 'tool_use', 'name' => 'bash', 'input' => ['command' => 'echo 1']],
                    ['type' => 'text', 'text' => 'Hello user'],
                ],
            ],
        ]),
        json_encode([
            'type' => 'result',
            'result' => 'Operation succeeded',
            'total_cost_usd' => 0.0125,
            'num_turns' => 2,
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'cache_read_input_tokens' => 20,
                'cache_creation_input_tokens' => 10,
            ],
        ]),
    ]);

    /** @var Event[] $events */
    $events = [];
    $this->harness->parseStream($jsonl, function (Event $e) use (&$events): void {
        $events[] = $e;
    });

    expect($events)->toHaveCount(5)
        ->and($events[0]->getKindValue())->toBe(EventKind::Session->value)
        ->and($events[0]->sessionID)->toBe('sess-abc-123')
        ->and($events[1]->getKindValue())->toBe(EventKind::Thinking->value)
        ->and($events[1]->text)->toBe('I am thinking')
        ->and($events[2]->getKindValue())->toBe(EventKind::Tool->value)
        ->and($events[2]->tool)->toBe('bash')
        ->and($events[2]->text)->toBe('echo 1')
        ->and($events[3]->getKindValue())->toBe(EventKind::Text->value)
        ->and($events[3]->text)->toBe('Hello user')
        ->and($events[4]->getKindValue())->toBe(EventKind::Result->value)
        ->and($events[4]->text)->toBe('Operation succeeded')
        ->and($events[4]->costUSD)->toBe(0.0125)
        ->and($events[4]->turns)->toBe(2)
        ->and($events[4]->usage->inputTokens)->toBe(100);
});
