<?php

/**
 * @desc 技能暂存单元测试类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

use Harness\Backends\ClaudeHarness;
use Harness\Job;
use Harness\Skills\Skill;
use Harness\Skills\SkillDelivery;

beforeEach(function () {
    $this->tempWorkspace = sys_get_temp_dir() . '/harness_ws_' . uniqid();
    $this->tempSkillSource = sys_get_temp_dir() . '/harness_skill_src_' . uniqid();

    mkdir($this->tempWorkspace, 0755, true);
    mkdir($this->tempSkillSource, 0755, true);
});

afterEach(function () {
    $deleteDir = function (string $dir) use (&$deleteDir): void {
        if (!file_exists($dir)) {
            return;
        }
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

    $deleteDir($this->tempWorkspace);
    $deleteDir($this->tempSkillSource);
});

test('stage skill copies files and creates discovery directory', function () {
    $skill = new Skill(
        name: 'my-staged-skill',
        description: 'My staged skill description',
        body: 'Instructions for staged skill.',
        sourcePath: $this->tempSkillSource,
        schemaJSON: '{"type": "object"}'
    );

    // create a sibling helper file in source
    file_put_contents($this->tempSkillSource . '/helper.py', 'print(1)');

    $harness = new ClaudeHarness();
    $job = new Job(workspace: $this->tempWorkspace, skillName: 'my-staged-skill');

    SkillDelivery::stage($harness, $job, $skill);

    $targetDir = $this->tempWorkspace . '/.claude/skills/my-staged-skill';
    expect(is_dir($targetDir))->toBeTrue()
        ->and(file_exists($targetDir . '/SKILL.md'))->toBeTrue()
        ->and(file_exists($targetDir . '/schema.json'))->toBeTrue()
        ->and(file_exists($targetDir . '/helper.py'))->toBeTrue();

    $content = (string) file_get_contents($targetDir . '/SKILL.md');
    expect($content)->toContain('my-staged-skill')
        ->toContain('Instructions for staged skill.');
});
