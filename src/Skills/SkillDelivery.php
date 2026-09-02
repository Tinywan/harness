<?php

/**
 * @desc Agent 技能暂存与分发管理类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness\Skills;

use Harness\Harness;
use Harness\HarnessInterface;
use Harness\Job;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class SkillDelivery
{
    /**
     * Writes a skill and its sibling files to the selected backend's skill discovery directory.
     */
    public static function stage(HarnessInterface $harness, Job $job, Skill $skill): void
    {
        $name = $job->skillName !== '' ? $job->skillName : $skill->name;
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new InvalidArgumentException(sprintf(
                'skills: skill name "%s" contains path separators or is empty',
                $name,
            ));
        }

        if ($job->workspace === '') {
            throw new InvalidArgumentException('skills: workspace is required');
        }

        $destination = $harness->getSkillDir($job->workspace, $name);
        if ($destination === '') {
            throw new InvalidArgumentException(sprintf(
                'skills: %s does not support staged skills',
                Harness::name($harness),
            ));
        }

        self::validateDestination($job->workspace, $destination);

        if (file_exists($destination)) {
            self::deleteRecursive($destination);
        }

        if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
            throw new RuntimeException(sprintf('skills: create destination %s failed', $destination));
        }

        if ($skill->sourcePath !== '' && is_dir($skill->sourcePath)) {
            self::copySiblings($skill->sourcePath, $destination);
        }

        $rendered = self::render($skill);
        $skillMdPath = $destination . DIRECTORY_SEPARATOR . Skill::SKILL_FILENAME;
        if (file_put_contents($skillMdPath, $rendered) === false) {
            throw new RuntimeException(sprintf('skills: write %s failed', Skill::SKILL_FILENAME));
        }

        if ($skill->schemaJSON !== '') {
            $schemaPath = $destination . DIRECTORY_SEPARATOR . Skill::SCHEMA_FILENAME;
            if (file_put_contents($schemaPath, $skill->schemaJSON) === false) {
                throw new RuntimeException(sprintf('skills: write %s failed', Skill::SCHEMA_FILENAME));
            }
        }
    }

    /**
     * Joins skill bodies into one system prompt.
     */
    public static function concat(Skill ...$skills): string
    {
        $parts = [];
        foreach ($skills as $skill) {
            $body = trim($skill->body);
            if ($body !== '') {
                $parts[] = $body;
            }
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Returns the SKILL.md string for skill with one trailing newline.
     */
    public static function render(Skill $skill): string
    {
        if (
            $skill->description === ''
            && $skill->license === ''
            && $skill->compatibility === ''
            && $skill->allowedTools === ''
            && empty($skill->metadata)
        ) {
            return rtrim($skill->body) . "\n";
        }

        $fm = [];
        if ($skill->name !== '') {
            $fm['name'] = $skill->name;
        }
        if ($skill->description !== '') {
            $fm['description'] = $skill->description;
        }
        if ($skill->license !== '') {
            $fm['license'] = $skill->license;
        }
        if ($skill->compatibility !== '') {
            $fm['compatibility'] = $skill->compatibility;
        }
        if ($skill->allowedTools !== '') {
            $fm['allowed-tools'] = $skill->allowedTools;
        }
        if (!empty($skill->metadata)) {
            $fm['metadata'] = $skill->metadata;
        }

        $yaml = Yaml::dump($fm, 4, 2);
        return "---\n" . $yaml . "---\n\n" . rtrim($skill->body) . "\n";
    }

    private static function validateDestination(string $workspace, string $destination): void
    {
        $realWorkspace = realpath($workspace) ?: $workspace;
        $realDest = realpath(dirname($destination));
        if ($realDest === false) {
            $realDest = dirname($destination);
        }

        $wsClean = rtrim(str_replace('\\', '/', $realWorkspace), '/');
        $destClean = rtrim(str_replace('\\', '/', $destination), '/');

        if ($destClean === $wsClean || !str_starts_with($destClean, $wsClean . '/')) {
            throw new InvalidArgumentException(sprintf(
                'skills: destination "%s" is outside workspace "%s"',
                $destination,
                $workspace,
            ));
        }
    }

    private static function copySiblings(string $source, string $destination): void
    {
        $items = scandir($source);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === Skill::SKILL_FILENAME || $item === '.git') {
                continue;
            }

            $srcPath = $source . DIRECTORY_SEPARATOR . $item;
            $dstPath = $destination . DIRECTORY_SEPARATOR . $item;

            self::copyTree($srcPath, $dstPath);
        }
    }

    private static function copyTree(string $source, string $destination): void
    {
        if (is_link($source)) {
            throw new RuntimeException(sprintf('symlink "%s" not staged', $source));
        }

        if (is_dir($source)) {
            if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
                throw new RuntimeException(sprintf('create directory %s failed', $destination));
            }

            $items = scandir($source);
            if ($items === false) {
                return;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                self::copyTree($source . DIRECTORY_SEPARATOR . $item, $destination . DIRECTORY_SEPARATOR . $item);
            }
        } elseif (is_file($source)) {
            copy($source, $destination);
        }
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
