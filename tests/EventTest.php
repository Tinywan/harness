<?php

/**
 * @desc 事件格式化单元测试类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

use Harness\Event;
use Harness\EventKind;
use Harness\RateLimitInfo;

test('format thinking event', function () {
    $event = new Event(EventKind::Thinking, text: 'considering solution');
    expect($event->format())->toBe('[thinking] considering solution');
});

test('format tool event', function () {
    $event = new Event(EventKind::Tool, tool: 'Bash', text: 'ls -la');
    expect($event->format())->toBe('[bash] ls -la');
});

test('format result event', function () {
    $event = new Event(EventKind::Result, text: 'Done', costUSD: 0.0512, turns: 3);
    expect($event->format())->toBe('[result] cost=$0.0512 turns=3 Done');
});

test('format session event', function () {
    $event = new Event(EventKind::Session, sessionID: 'sess-12345');
    expect($event->format())->toBe('[session] sess-12345');
});

test('format rate limit event', function () {
    $info = new RateLimitInfo(
        status: 'rejected',
        resetsAt: 1788393600, // 2026-09-03 00:00:00 UTC
        type: 'requests',
    );
    $event = new Event(EventKind::RateLimit, rateLimit: $info);
    expect($event->format())->toBe('[rate-limit] requests rejected resets 2026-09-03 00:00 UTC');
});

test('truncate string', function () {
    $long = str_repeat('a', 350);
    $truncated = Event::truncate($long, 300);
    expect($truncated)->toBe(str_repeat('a', 300) . '... (350 chars)');
});
