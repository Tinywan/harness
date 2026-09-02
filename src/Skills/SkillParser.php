<?php

/**
 * @desc SKILL.md 技能文件解析器
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness\Skills;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class SkillParser
{
    private const FRONTMATTER_PATTERN = '/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s';
    private const NAME_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * Parses a markdown instruction file. YAML frontmatter is optional.
     */
    public static function parse(string $path): Skill
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException(sprintf('skills: read %s: file does not exist', $path));
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException(sprintf('skills: read %s failed', $path));
        }

        $abs = realpath(dirname($path));
        if ($abs === false) {
            $abs = dirname($path);
        }

        $schema = '';
        $schemaPath = $abs . DIRECTORY_SEPARATOR . Skill::SCHEMA_FILENAME;
        if (file_exists($schemaPath)) {
            $schema = (string) file_get_contents($schemaPath);
        }

        [$fmContent, $body, $hasFrontmatter] = self::splitFrontmatter($raw);

        $parsed = [];
        if ($hasFrontmatter) {
            try {
                $yaml = Yaml::parse($fmContent);
                if (is_array($yaml)) {
                    $parsed = $yaml;
                }
            } catch (\Throwable $e) {
                throw new RuntimeException(sprintf('skills: yaml %s: %s', $path, $e->getMessage()), 0, $e);
            }
        }

        $metadata = [];
        if (isset($parsed['metadata']) && is_array($parsed['metadata'])) {
            $metadata = $parsed['metadata'];
        }

        $skill = new Skill(
            name: trim((string) ($parsed['name'] ?? '')),
            description: trim((string) ($parsed['description'] ?? '')),
            license: trim((string) ($parsed['license'] ?? '')),
            compatibility: trim((string) ($parsed['compatibility'] ?? '')),
            allowedTools: trim((string) ($parsed['allowed-tools'] ?? ($parsed['allowed_tools'] ?? ''))),
            metadata: $metadata,
            body: trim($body),
            sourcePath: $abs,
            schemaJSON: $schema,
            sourceHash: self::hash($raw, $schema),
        );

        if (!$hasFrontmatter) {
            $skill->body = trim($raw);
        }

        self::validate($skill, $path, $hasFrontmatter);

        return $skill;
    }

    /**
     * @return array{0: string, 1: string, 2: bool} [frontmatter, body, hasFrontmatter]
     */
    private static function splitFrontmatter(string $raw): array
    {
        if (preg_match(self::FRONTMATTER_PATTERN, $raw, $matches)) {
            return [$matches[1], $matches[2], true];
        }

        return ['', $raw, false];
    }

    public static function hash(string ...$parts): string
    {
        $ctx = hash_init('sha256');
        foreach ($parts as $part) {
            hash_update($ctx, $part);
        }

        return hash_final($ctx);
    }

    private static function validate(Skill $skill, string $path, bool $hadFrontmatter): void
    {
        if ($skill->name === '') {
            $skill->name = self::inferredName($path);
            if ($hadFrontmatter) {
                $skill->warnings[] = 'name missing, using path name';
            }
        }

        if ($hadFrontmatter && $skill->description === '') {
            $skill->warnings[] = 'description is missing';
        }

        if (strlen($skill->name) > Skill::MAX_NAME_LEN) {
            $skill->warnings[] = sprintf('name "%s" exceeds %d characters', $skill->name, Skill::MAX_NAME_LEN);
        }

        if (!preg_match(self::NAME_PATTERN, $skill->name)) {
            $skill->warnings[] = sprintf('name "%s" is not spec-conformant (lowercase, digits, hyphens only)', $skill->name);
        }

        if (strlen($skill->description) > Skill::MAX_DESC_LEN) {
            $skill->warnings[] = sprintf('description exceeds %d characters', Skill::MAX_DESC_LEN);
        }

        if (strlen($skill->compatibility) > Skill::MAX_COMPAT_LEN) {
            $skill->warnings[] = sprintf('compatibility exceeds %d characters', Skill::MAX_COMPAT_LEN);
        }
    }

    private static function inferredName(string $path): string
    {
        $base = basename($path);
        if (strcasecmp($base, Skill::SKILL_FILENAME) === 0) {
            return basename(dirname($path));
        }

        $ext = pathinfo($base, PATHINFO_EXTENSION);
        return $ext !== '' ? substr($base, 0, -(strlen($ext) + 1)) : $base;
    }

    /**
     * Rejects metadata keys in prefix that are not listed in allowed.
     *
     * @param array<string, mixed> $meta
     * @param array<string, bool> $allowed
     */
    public static function validateNamespace(array $meta, string $prefix, array $allowed): void
    {
        foreach (array_keys($meta) as $key) {
            if (str_starts_with($key, $prefix) && empty($allowed[$key])) {
                throw new InvalidArgumentException(sprintf('skills: unknown metadata key "%s"', $key));
            }
        }
    }
}
