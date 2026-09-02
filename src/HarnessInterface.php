<?php

/**
 * @desc Harness 后端契约接口
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness;

interface HarnessInterface
{
    /**
     * Binary is the executable name expected on PATH.
     */
    public function getBinary(): string;

    /**
     * Returns argv without the binary for one job.
     *
     * @return string[]
     */
    public function getArgs(Job $job): array;

    /**
     * Returns the final user prompt passed by getArgs.
     */
    public function getPrompt(Job $job): string;

    /**
     * Maps the backend's combined output onto Event values.
     *
     * @param resource|string $stream
     * @param callable(Event): void $emit
     */
    public function parseStream(mixed $stream, callable $emit): void;

    /**
     * Returns the directory where the backend discovers a staged SKILL.md and its sibling files.
     */
    public function getSkillDir(string $workspace, string $name): string;

    /**
     * GuideFilename is the workspace-relative project instruction file loaded by the backend.
     */
    public function getGuideFilename(): string;

    /**
     * Reports whether getArgs passes Job::$systemPrompt itself.
     * When false, writeSystemPrompt writes it to getGuideFilename instead.
     */
    public function systemPromptViaArgs(): bool;

    /**
     * Lists the model and authentication hosts needed by the backend.
     *
     * @return string[]
     */
    public function getEgressHosts(): array;

    /**
     * Returns backend environment entries.
     *
     * @return array<string, string>|string[]
     */
    public function getEnv(?string $baseURL = null): array;

    /**
     * Points the backend at a caller-owned persistent state directory so a later
     * process can resume the same session.
     *
     * @return array<string, string>|string[]
     */
    public function getStateEnv(string $dir): array;

    /**
     * Returns the matching provider account error, or an empty string.
     */
    public function getAccountErrorText(string $output): string;

    /**
     * Returns the built-in model picker entries. The first entry is the backend default.
     *
     * @return ModelDefault[]
     */
    public function getDefaultModels(): array;
}
