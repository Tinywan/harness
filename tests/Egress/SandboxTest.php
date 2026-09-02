<?php

/**
 * @desc 沙箱配置单元测试类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

use Harness\Egress\Sandbox;

beforeEach(function () {
    $this->tempWorkspace = sys_get_temp_dir() . '/harness_egress_' . uniqid();
    mkdir($this->tempWorkspace, 0755, true);
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
});

test('writeSandboxSettings writes claude settings json with domain allowlist', function () {
    $allowed = ['*.anthropic.com', 'api.github.com'];
    Sandbox::writeSandboxSettings($this->tempWorkspace, $allowed);

    $settingsFile = $this->tempWorkspace . '/.claude/settings.json';
    expect(file_exists($settingsFile))->toBeTrue();

    $json = json_decode((string) file_get_contents($settingsFile), true);
    expect($json)->toBeArray()->and($json['permissions']['sandbox']['allowedDomains'])->toBe($allowed);
});
