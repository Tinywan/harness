<?php

/**
 * @desc 无头任务配置实体类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness;

class Job
{
    public const DEFAULT_MAX_TURNS = 30;

    public function __construct(
        /**
         * Workspace is the command's working directory. Paths passed to a CLI are relative to it.
         */
        public string $workspace = '',

        /**
         * SrcDir is the workspace-relative repository directory used in generated prompts.
         * It defaults to "src"; use "." when Workspace is already the repository root.
         */
        public string $srcDir = 'src',

        /**
         * SkillName selects a staged SKILL.md directory. An empty value means no staged skill.
         */
        public string $skillName = '',

        /**
         * Prompt is the user turn for a fresh run. When it is empty and SkillName is set,
         * the harness builds an activation prompt.
         */
        public string $prompt = '',

        /**
         * SystemPrompt supplies additional instructions.
         * Claude receives it via --system-prompt.
         * Other backends write it to the project guide file.
         */
        public string $systemPrompt = '',

        /**
         * Model identifier.
         */
        public string $model = '',

        /**
         * Effort is the backend-native reasoning effort accepted by Claude and Copilot.
         */
        public string $effort = '',

        /**
         * MaxTurns is the maximum number of turns. 0 uses the backend default (typically 30).
         */
        public int $maxTurns = self::DEFAULT_MAX_TURNS,

        /**
         * OutputFile is a workspace-relative path the skill should write.
         */
        public string $outputFile = '',

        /**
         * ValidationHint is appended to generated prompts after the OutputFile clause when
         * OutputFile ends in .json.
         */
        public string $validationHint = '',

        /**
         * AllowedTools is Claude's comma-separated tool allowlist.
         */
        public string $allowedTools = '',

        /**
         * BaseURL overrides the model API endpoint where the backend supports it.
         */
        public string $baseURL = '',

        /**
         * ResumeSessionID continues a prior conversation.
         */
        public string $resumeSessionID = '',

        /**
         * ResumePrompt is the corrective turn used for the resumed invocation.
         */
        public string $resumePrompt = '',
    ) {
        if ($this->maxTurns <= 0) {
            $this->maxTurns = self::DEFAULT_MAX_TURNS;
        }
    }
}
