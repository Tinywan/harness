<?php

/**
 * @desc GitHub Copilot CLI 后端单元测试类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

use Harness\Backends\CopilotHarness;
use Harness\Event;
use Harness\EventKind;
use Harness\Job;

beforeEach(function () {
    $this->harness = new CopilotHarness();
});

test('copilot getBinary returns copilot', function () {
    expect($this->harness->getBinary())->toBe('copilot');
});

test('copilot getArgs builds correct CLI parameters', function () {
    $job = new Job(workspace: '/work', skillName: 'sec-scan', model: 'gpt-5.6-sol', maxTurns: 20);

    $args = $this->harness->getArgs($job);

    expect($args)
        ->toContain('-p')
        ->toContain('--output-format')
        ->toContain('json')
        ->toContain('--autopilot')
        ->toContain('--max-autopilot-continues')
        ->toContain('20')
        ->toContain('--allow-all')
        ->toContain('--model')
        ->toContain('gpt-5.6-sol');
});

test('copilot parseStream aggregates usage checkpoints and costs', function () {
    $jsonl = implode("\n", [
        json_encode([
            'type' => 'assistant.reasoning',
            'data' => ['content' => 'Analyzing security issues'],
        ]),
        json_encode([
            'type' => 'assistant.message',
            'data' => ['content' => 'Found 0 vulnerabilities.'],
        ]),
        json_encode([
            'type' => 'assistant.usage',
            'data' => [
                'model' => 'gpt-5.6-sol',
                'inputTokens' => 500,
                'outputTokens' => 100,
                'cacheReadTokens' => 50,
                'cacheWriteTokens' => 20,
            ],
        ]),
        json_encode([
            'type' => 'session.usage_checkpoint',
            'data' => [
                'totalNanoAiu' => 5000000000.0, // 5 credits = $0.05
            ],
        ]),
        json_encode([
            'type' => 'result',
            'sessionId' => 'copilot-sess-99',
            'exitCode' => 0,
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
        ->toBe(EventKind::Thinking->value)
        ->and($events[0]->text)
        ->toBe('Analyzing security issues')
        ->and($events[1]->getKindValue())
        ->toBe(EventKind::Text->value)
        ->and($events[1]->text)
        ->toBe('Found 0 vulnerabilities.')
        ->and($events[2]->getKindValue())
        ->toBe(EventKind::Session->value)
        ->and($events[2]->sessionID)
        ->toBe('copilot-sess-99')
        ->and($events[3]->getKindValue())
        ->toBe(EventKind::Result->value)
        ->and($events[3]->costUSD)
        ->toBe(0.05)
        ->and($events[3]->usage->inputTokens)
        ->toBe(500)
        ->and($events[3]->text)
        ->toBe('Found 0 vulnerabilities.');
});
