<?php

/**
 * @desc 指令文件清理测试类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

use Harness\Directives;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/harness_directives_test_' . uniqid();
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

test('strip directives removes configured files and directories but preserves git', function () {
    // Create files and dirs to strip
    mkdir($this->tempDir . '/.claude', 0755, true);
    file_put_contents($this->tempDir . '/.claude/config.json', '{}');
    file_put_contents($this->tempDir . '/CLAUDE.md', '# Claude rules');
    file_put_contents($this->tempDir . '/AGENTS.md', '# Agents rules');
    file_put_contents($this->tempDir . '/keep_me.php', '<?php echo 1;');

    // .git must not be stripped
    mkdir($this->tempDir . '/.git', 0755, true);
    file_put_contents($this->tempDir . '/.git/config', 'gitconfig');

    $removed = Directives::stripDirectives($this->tempDir);
    expect($removed)->toBe(3)
        ->and(is_dir($this->tempDir . '/.claude'))->toBeFalse()
        ->and(file_exists($this->tempDir . '/CLAUDE.md'))->toBeFalse()
        ->and(file_exists($this->tempDir . '/AGENTS.md'))->toBeFalse()
        ->and(file_exists($this->tempDir . '/keep_me.php'))->toBeTrue()
        ->and(is_dir($this->tempDir . '/.git'))->toBeTrue();
});
