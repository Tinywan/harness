<?php

/**
 * @desc 技能过滤器单元测试类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

use Harness\Skills\SkillFilter;

test('match glob patterns', function () {
    expect(SkillFilter::match('*.php', 'index.php'))->toBeTrue()
        ->and(SkillFilter::match('*.php', 'index.js'))->toBeFalse()
        ->and(SkillFilter::match('src/**/*.php', 'src/Controllers/Home.php'))->toBeTrue()
        ->and(SkillFilter::match('src/**/*.php', 'src/App.php'))->toBeTrue()
        ->and(SkillFilter::match('src/**/*.php', 'tests/AppTest.php'))->toBeFalse();
});

test('pathIncluded filters by include and ignore lists', function () {
    $paths = ['src/**', 'tests/**'];
    $ignore = ['**/*.tmp', 'vendor/**'];

    expect(SkillFilter::pathIncluded('.git/config', $paths, $ignore))->toBeTrue()
        ->and(SkillFilter::pathIncluded('src/App.php', $paths, $ignore))->toBeTrue()
        ->and(SkillFilter::pathIncluded('src/cache.tmp', $paths, $ignore))->toBeFalse()
        ->and(SkillFilter::pathIncluded('docs/README.md', $paths, $ignore))->toBeFalse();
});

test('dirAllExcluded blankets directories properly', function () {
    $ignore = ['vendor/**', 'build/**'];

    expect(SkillFilter::dirAllExcluded('vendor', $ignore))->toBeTrue()
        ->and(SkillFilter::dirAllExcluded('build', $ignore))->toBeTrue()
        ->and(SkillFilter::dirAllExcluded('src', $ignore))->toBeFalse()
        ->and(SkillFilter::dirAllExcluded('.git', $ignore))->toBeFalse();
});
