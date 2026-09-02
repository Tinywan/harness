<?php

/**
 * @desc 提示词指令文件过滤与清理类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Directives
{
    /**
     * Directory basename patterns that agent CLIs load as configuration, hooks, or skills.
     *
     * @var string[]
     */
    public static array $directiveDirs = [
        // Harness backends
        '.claude',
        '.opencode',

        // Other agent CLIs
        '.anthropic',
        '.cursor',
        '.windsurf',
        '.continue',
        '.cline',
        '.roo',
        '.goose',
        '.aider',
        '.aider.*',
        '.gemini',
        '.codex',
        '.copilot',
        '.devin',
    ];

    /**
     * File basename patterns that agent CLIs load as project instructions.
     *
     * @var string[]
     */
    public static array $directiveFiles = [
        // Harness backends
        'claude.md',
        'claude.*.md',
        'agents.md',
        'copilot-instructions.md',

        // Other agent CLIs
        'agent.md',
        'gemini.md',
        'codex.md',
        '.cursorrules',
        '.cursorignore',
        '.windsurfrules',
        '.clinerules',
        '.roorules',
        '.rooignore',
        '.aider.conf.yml',
        '.aider.conf.yaml',
        '.aiderrules',
        '*.instructions.md',
        '*.prompt.md',
        '.rules',
        'llms.txt',
        'llms-full.txt',
    ];

    /**
     * @return array{dirs: string[], files: string[]}
     */
    public static function directivePaths(): array
    {
        return [
            'dirs' => self::$directiveDirs,
            'files' => self::$directiveFiles,
        ];
    }

    /**
     * Removes files and directories below root whose basenames match DirectivePaths.
     * The .git subtree is skipped. A missing root is a no-op.
     *
     * @return int Number of items removed
     */
    public static function stripDirectives(string $root): int
    {
        if (!is_dir($root)) {
            return 0;
        }

        $removed = 0;
        $items = scandir($root);
        if ($items === false) {
            return 0;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.git') {
                continue;
            }

            $path = $root . DIRECTORY_SEPARATOR . $item;
            $base = strtolower($item);

            if (is_dir($path) || is_link($path)) {
                if (self::matchesDirective(self::$directiveDirs, $base)) {
                    self::deleteRecursive($path);
                    $removed++;
                    continue;
                }
                // Recursively strip subdirectories
                $removed += self::stripDirectives($path);
            } elseif (is_file($path)) {
                if (self::matchesDirective(self::$directiveFiles, $base)) {
                    if (@unlink($path)) {
                        $removed++;
                    }
                }
            }
        }

        return $removed;
    }

    /**
     * @param string[] $patterns
     */
    public static function matchesDirective(array $patterns, string $base): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch(strtolower($pattern), strtolower($base))) {
                return true;
            }
        }

        return false;
    }

    private static function deleteRecursive(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir) || is_link($dir)) {
            return @unlink($dir);
        }

        $items = scandir($dir);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                self::deleteRecursive($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }
}
