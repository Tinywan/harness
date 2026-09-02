<?php

/**
 * @desc OpenCode 后端单元测试类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

use Harness\Backends\OpencodeHarness;
use Harness\Event;
use Harness\EventKind;
use Harness\Job;

beforeEach(function () {
    $this->harness = new OpencodeHarness();
});

test('opencode getBinary returns opencode', function () {
    expect($this->harness->getBinary())->toBe('opencode');
});

test('opencode getArgs builds correct CLI parameters', function () {
    $job = new Job(
        workspace: '/work',
        skillName: 'my-skill',
        model: 'anthropic/claude-sonnet-4-6',
    );

    $args = $this->harness->getArgs($job);

    expect($args)->toContain('run')
        ->toContain('--format')
        ->toContain('json')
        ->toContain('--auto')
        ->toContain('--model')
        ->toContain('anthropic/claude-sonnet-4-6');
});

test('opencode parseStream maps stream to neutral events', function () {
    $jsonl = implode("\n", [
        json_encode([
            'type' => 'step_start',
            'sessionID' => 'opencode-sess-1',
        ]),
        json_encode([
            'type' => 'reasoning',
            'part' => [
                'text' => 'Thinking about implementation',
            ],
        ]),
        json_encode([
            'type' => 'tool',
            'part' => [
                'tool' => 'shell',
                'state' => [
                    'input' => ['command' => 'pwd'],
                ],
            ],
        ]),
        json_encode([
            'type' => 'step_finish',
            'part' => [
                'cost' => 0.003,
                'tokens' => [
                    'input' => 150,
                    'output' => 50,
                    'reasoning' => 25,
                    'cache' => [
                        'read' => 10,
                        'write' => 5,
                    ],
                ],
            ],
        ]),
    ]);

    /** @var Event[] $events */
    $events = [];
    $this->harness->parseStream($jsonl, function (Event $e) use (&$events): void {
        $events[] = $e;
    });

    expect($events)->toHaveCount(4)
        ->and($events[0]->getKindValue())->toBe(EventKind::Session->value)
        ->and($events[0]->sessionID)->toBe('opencode-sess-1')
        ->and($events[1]->getKindValue())->toBe(EventKind::Thinking->value)
        ->and($events[1]->text)->toBe('Thinking about implementation')
        ->and($events[2]->getKindValue())->toBe(EventKind::Tool->value)
        ->and($events[2]->tool)->toBe('shell')
        ->and($events[2]->text)->toBe('pwd')
        ->and($events[3]->getKindValue())->toBe(EventKind::Result->value)
        ->and($events[3]->costUSD)->toBe(0.003)
        ->and($events[3]->usage->inputTokens)->toBe(150)
        ->and($events[3]->usage->outputTokens)->toBe(75); // output (50) + reasoning (25)
});
