<?php

/**
 * @desc 技能路径匹配与过滤类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness\Skills;

class SkillFilter
{
    /**
     * Reports whether an ignore pattern excludes every file below $rel.
     *
     * @param string[] $ignore
     */
    public static function dirAllExcluded(string $rel, array $ignore): bool
    {
        if ($rel === '.git' || str_starts_with($rel, '.git/')) {
            return false;
        }

        return self::dirBlanketed($rel, $ignore);
    }

    /**
     * @param string[] $patterns
     */
    public static function dirBlanketed(string $rel, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '/**')) {
                $prefix = substr($pattern, 0, -3);
                if (self::match($prefix, $rel)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Applies an optional include list and an ignore list to $rel.
     *
     * @param string[] $paths
     * @param string[] $ignore
     */
    public static function pathIncluded(string $rel, array $paths, array $ignore): bool
    {
        if ($rel === '.git' || str_starts_with($rel, '.git/')) {
            return true;
        }

        if (!empty($paths) && !self::matchAny($paths, $rel)) {
            return false;
        }

        return !self::matchAny($ignore, $rel);
    }

    /**
     * @param string[] $patterns
     */
    public static function matchAny(array $patterns, string $name): bool
    {
        foreach ($patterns as $pattern) {
            if (self::match($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match reports whether name matches a shell glob with doublestar (**) support.
     */
    public static function match(string $pattern, string $name): bool
    {
        if ($pattern === '' || $name === '') {
            return $pattern === $name;
        }

        $pSegments = explode('/', str_replace('\\', '/', $pattern));
        $nSegments = explode('/', str_replace('\\', '/', $name));

        return self::matchSegments($pSegments, $nSegments);
    }

    /**
     * @param string[] $pattern
     * @param string[] $name
     */
    private static function matchSegments(array $pattern, array $name): bool
    {
        while (count($pattern) > 0) {
            if ($pattern[0] === '**') {
                $rest = array_slice($pattern, 1);
                if (count($rest) === 0) {
                    return true;
                }
                for ($i = 0, $nLen = count($name); $i <= $nLen; $i++) {
                    if (self::matchSegments($rest, array_slice($name, $i))) {
                        return true;
                    }
                }
                return false;
            }

            if (count($name) === 0) {
                return false;
            }

            if (!fnmatch($pattern[0], $name[0])) {
                return false;
            }

            array_shift($pattern);
            array_shift($name);
        }

        return count($name) === 0;
    }

    /**
     * @return string[]
     */
    public static function splitPatterns(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $lines = explode("\n", $value);
        $patterns = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $patterns[] = $trimmed;
            }
        }

        return $patterns;
    }

    /**
     * @param string[] $patterns
     */
    public static function joinPatterns(array $patterns): string
    {
        return implode("\n", $patterns);
    }
}
