<?php

/**
 * @desc Agent 技能实体模型类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness\Skills;

class Skill
{
    public const SPEC_URL = 'https://agentskills.io/specification';
    public const SKILL_FILENAME = 'SKILL.md';
    public const SCHEMA_FILENAME = 'schema.json';

    public const MAX_NAME_LEN = 64;
    public const MAX_DESC_LEN = 1024;
    public const MAX_COMPAT_LEN = 500;

    /**
     * @param array<string, mixed> $metadata
     * @param string[] $warnings
     */
    public function __construct(
        public string $name = '',
        public string $description = '',
        public string $license = '',
        public string $compatibility = '',
        public string $allowedTools = '',
        public array $metadata = [],
        public string $body = '',
        public string $sourcePath = '',
        public string $schemaJSON = '',
        public string $sourceHash = '',
        public array $warnings = [],
    ) {}
}
