<?php

/**
 * @desc 沙箱与域名白名单配置管理类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness\Egress;

use InvalidArgumentException;
use RuntimeException;

class Sandbox
{
    /**
     * Writes Claude's workspace sandbox domain allowlist (.claude/settings.json).
     *
     * @param string[] $allowedDomains
     */
    public static function writeSandboxSettings(string $workspace, array $allowedDomains): void
    {
        if ($workspace === '') {
            throw new InvalidArgumentException('egress: workspace is required');
        }

        $path = $workspace . DIRECTORY_SEPARATOR . '.claude' . DIRECTORY_SEPARATOR . 'settings.json';
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('egress: create settings directory %s failed', $dir));
        }

        $settings = [
            'permissions' => [
                'allow' => [],
                'deny' => [],
                'sandbox' => [
                    'allowedDomains' => array_values($allowedDomains),
                ],
            ],
        ];

        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException(sprintf('egress: write sandbox settings %s failed', $path));
        }
    }
}
