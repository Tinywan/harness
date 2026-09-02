<?php

/**
 * @desc 技能解析器单元测试类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

use Harness\Skills\SkillDelivery;
use Harness\Skills\SkillParser;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/harness_skills_test_' . uniqid();
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        $deleteDir = function (string $dir) use (&$deleteDir): void {
            $items = scandir($dir);
            if ($items === false) {
                return;
            }
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $p = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($p)) {
                    $deleteDir($p);
                } else {
                    @unlink($p);
                }
            }
            @rmdir($dir);
        };
        $deleteDir($this->tempDir);
    }
});

test('skill parser with YAML frontmatter', function () {
    $content = <<<MARKDOWN
---
name: sec-review
description: Performs security review on PHP code
license: MIT
compatibility: PHP 8.4+
allowed-tools: read,grep
metadata:
  version: "1.0.0"
---

# Instructions
Check for SQL injection and XSS vulnerabilities.
MARKDOWN;

    $path = $this->tempDir . '/SKILL.md';
    file_put_contents($path, $content);
    file_put_contents($this->tempDir . '/schema.json', '{"type": "object"}');

    $skill = SkillParser::parse($path);

    expect($skill->name)->toBe('sec-review')
        ->and($skill->description)->toBe('Performs security review on PHP code')
        ->and($skill->license)->toBe('MIT')
        ->and($skill->compatibility)->toBe('PHP 8.4+')
        ->and($skill->allowedTools)->toBe('read,grep')
        ->and($skill->metadata)->toBe(['version' => '1.0.0'])
        ->and($skill->body)->toContain('Check for SQL injection')
        ->and($skill->schemaJSON)->toBe('{"type": "object"}')
        ->and($skill->sourceHash)->not()->toBeEmpty()
        ->and($skill->warnings)->toBeEmpty();
});

test('skill parser plain markdown without frontmatter', function () {
    $content = "# Simple Skill\nJust follow these steps.";
    $path = $this->tempDir . '/SKILL.md';
    file_put_contents($path, $content);

    $skill = SkillParser::parse($path);

    expect($skill->name)->toBe(basename($this->tempDir))
        ->and($skill->body)->toBe($content);
});

test('render and concat skills', function () {
    $path = $this->tempDir . '/SKILL.md';
    file_put_contents($path, "---\nname: test-skill\ndescription: A test skill\n---\n\nSkill body text.");

    $skill = SkillParser::parse($path);
    $rendered = SkillDelivery::render($skill);

    expect($rendered)->toContain('name: test-skill')
        ->toContain('description: \'A test skill\'')
        ->toContain('Skill body text.');

    $concat = SkillDelivery::concat($skill, $skill);
    expect($concat)->toBe("Skill body text.\n\n---\n\nSkill body text.");
});
