<?php

/**
 * @desc 技能目录递归遍历器
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness\Skills;

use InvalidArgumentException;

class SkillWalker
{
    public const MAX_WALK_DEPTH = 6;

    /**
     * Visits SKILL.md files under root.
     *
     * @param callable(Skill): (void|bool) $callback
     */
    public static function walk(string $root, callable $callback): void
    {
        if (!is_dir($root)) {
            throw new InvalidArgumentException(sprintf('skills: walk %s: directory not found', $root));
        }

        self::walkDir($root, $root, 0, $callback);
    }

    private static function walkDir(string $baseRoot, string $currentDir, int $depth, callable $callback): void
    {
        if ($depth > self::MAX_WALK_DEPTH) {
            return;
        }

        $items = scandir($currentDir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $currentDir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                if (self::shouldSkipDir($item)) {
                    continue;
                }
                self::walkDir($baseRoot, $path, $depth + 1, $callback);
            } elseif (is_file($path)) {
                if (strcasecmp($item, Skill::SKILL_FILENAME) === 0) {
                    $skill = SkillParser::parse($path);
                    $result = $callback($skill);
                    if ($result === false) {
                        return;
                    }
                }
            }
        }
    }

    private static function shouldSkipDir(string $name): bool
    {
        return in_array($name, ['.git', 'node_modules', '.venv', '__pycache__', 'vendor'], true);
    }
}
