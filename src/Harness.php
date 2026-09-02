<?php

/**
 * @desc Harness 统一驱动与调度门面类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness;

use Harness\Backends\ClaudeHarness;
use Harness\Backends\CodexHarness;
use Harness\Backends\CopilotHarness;
use Harness\Backends\OpencodeHarness;
use InvalidArgumentException;
use RuntimeException;

class Harness
{
    public const DEFAULT_SRC_DIR = 'src';

    /**
     * @var array<string, HarnessInterface>
     */
    private static array $harnesses = [];

    private static bool $initialized = false;

    private static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$harnesses = [
            '' => new ClaudeHarness(),
            'claude' => new ClaudeHarness(),
            'codex' => new CodexHarness(),
            'copilot' => new CopilotHarness(),
            'opencode' => new OpencodeHarness(),
        ];

        self::$initialized = true;
    }

    /**
     * Resolves a backend by name. An empty string defaults to Claude.
     */
    public static function byName(string $name = ''): HarnessInterface
    {
        self::initialize();
        $key = strtolower(trim($name));

        if (isset(self::$harnesses[$key])) {
            return self::$harnesses[$key];
        }

        throw new InvalidArgumentException(sprintf(
            'harness: unknown backend "%s", must be one of %s',
            $name,
            self::names()
        ));
    }

    /**
     * Registers a custom backend.
     */
    public static function register(string $name, HarnessInterface $harness): void
    {
        self::initialize();
        self::$harnesses[strtolower(trim($name))] = $harness;
    }

    /**
     * Returns the registered name of the harness instance.
     */
    public static function name(HarnessInterface $harness): string
    {
        self::initialize();
        foreach (self::$harnesses as $name => $registered) {
            if ($name !== '' && get_class($registered) === get_class($harness)) {
                return $name;
            }
        }

        return $harness->getBinary();
    }

    /**
     * Returns the registered backend names in lexical order.
     */
    public static function names(): string
    {
        self::initialize();
        $names = [];
        foreach (array_keys(self::$harnesses) as $key) {
            if ($key !== '') {
                $names[] = $key;
            }
        }
        sort($names);

        return implode(', ', $names);
    }

    /**
     * Prefixes a leading space if prompt starts with `-` to avoid flag parsing.
     */
    public static function safePrompt(string $p): string
    {
        if (str_starts_with($p, '-')) {
            return ' ' . $p;
        }

        return $p;
    }

    /**
     * Validates session ID and returns empty string if it starts with `-`.
     */
    public static function safeSessionID(string $id): string
    {
        if (str_starts_with($id, '-')) {
            return '';
        }

        return $id;
    }

    /**
     * Extracts environment variables that exist on the host.
     *
     * @param string[] $keys
     * @return array<string, string>
     */
    public static function passthroughEnv(array $keys): array
    {
        $env = [];
        foreach ($keys as $key) {
            $val = getenv($key);
            if ($val !== false && $val !== '') {
                $env[$key] = $val;
            }
        }

        return $env;
    }

    /**
     * Computes the relative path to the source repository for prompts.
     */
    public static function sourcePromptPath(Job $j): string
    {
        $dir = trim($j->srcDir);
        if ($dir === '') {
            $dir = self::DEFAULT_SRC_DIR;
        }

        $dir = str_replace('\\', '/', $dir);
        $dir = rtrim($dir, '/');

        if ($dir === '.' || $dir === '') {
            return 'the workspace root';
        }

        if (str_starts_with($dir, './')) {
            return $dir;
        }

        return './' . $dir;
    }

    /**
     * Tells the agent to check its JSON output against the staged schema.json.
     */
    public static function schemaValidationHint(Job $j): string
    {
        if (!str_ends_with($j->outputFile, '.json')) {
            return '';
        }

        if ($j->validationHint !== '') {
            return ' ' . $j->validationHint;
        }

        return sprintf(' Validate ./%s against ./schema.json before finishing.', $j->outputFile);
    }

    /**
     * Builds Claude's activation prompt.
     */
    public static function buildSkillPrompt(Job $j): string
    {
        $prompt = sprintf('Use the "%s" skill on the repository cloned at %s.', $j->skillName, self::sourcePromptPath($j));
        if ($j->outputFile !== '') {
            $prompt .= sprintf(' Write your structured output to ./%s as the skill specifies.', $j->outputFile);
            $prompt .= self::schemaValidationHint($j);
        }

        return $prompt;
    }

    /**
     * Builds Claude's resume prompt.
     */
    public static function buildResumePrompt(Job $j): string
    {
        if ($j->skillName === '') {
            return 'Continue from where you left off.';
        }

        $prompt = sprintf(
            'Continue the "%s" skill on the repository at %s from where you left off.',
            $j->skillName,
            self::sourcePromptPath($j)
        );

        if ($j->outputFile !== '') {
            $prompt .= sprintf(' Write your structured output to ./%s as the skill specifies.', $j->outputFile);
            $prompt .= self::schemaValidationHint($j);
        }

        return $prompt;
    }

    /**
     * Builds explicit activation prompt for Codex, Copilot, OpenCode.
     */
    public static function explicitSkillPrompt(Job $j, string $skillPath): string
    {
        $resume = $j->resumeSessionID !== '';
        if ($resume && $j->resumePrompt !== '') {
            return $j->resumePrompt;
        }
        if (!$resume && $j->prompt !== '') {
            return $j->prompt;
        }
        if ($j->skillName === '') {
            if ($resume) {
                return 'Continue from where you left off.';
            }
            return '';
        }

        $verb = $resume ? 'Continue following' : 'Follow';
        $prompt = sprintf(
            '%s the instructions in %s/SKILL.md against the repository cloned at %s.',
            $verb,
            $skillPath,
            self::sourcePromptPath($j)
        );

        if ($j->outputFile !== '') {
            $prompt .= sprintf(' Write your structured output to ./%s as the skill specifies.', $j->outputFile);
            $prompt .= self::schemaValidationHint($j);
        }

        return $prompt;
    }

    /**
     * Extracts the useful part of common tool payloads.
     */
    public static function summariseInput(string $tool, mixed $raw): string
    {
        $input = is_array($raw) ? $raw : json_decode(is_string($raw) ? $raw : '', true);

        if (is_array($input)) {
            switch (strtolower($tool)) {
                case 'bash':
                case 'command':
                case 'shell':
                    if (!empty($input['command']) && is_string($input['command'])) {
                        return $input['command'];
                    }
                    break;
                case 'read':
                case 'write':
                case 'edit':
                    foreach (['file_path', 'path'] as $key) {
                        if (!empty($input[$key]) && is_string($input[$key])) {
                            return $input[$key];
                        }
                    }
                    break;
                case 'grep':
                case 'glob':
                    if (!empty($input['pattern']) && is_string($input['pattern'])) {
                        return $input['pattern'];
                    }
                    break;
            }
        }

        if (is_string($raw) && $raw !== '') {
            return Event::truncate($raw);
        }

        if (is_array($raw) && !empty($raw)) {
            $json = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return Event::truncate($json ?: '');
        }

        return '';
    }

    /**
     * Writes $job->systemPrompt to the guide file used by $harness.
     */
    public static function writeSystemPrompt(HarnessInterface $harness, Job $job): void
    {
        if (trim($job->systemPrompt) === '') {
            return;
        }

        if ($harness->systemPromptViaArgs()) {
            return;
        }

        if ($job->workspace === '') {
            throw new InvalidArgumentException('harness: workspace is required for a system prompt');
        }

        $guide = $harness->getGuideFilename();
        if ($guide === '') {
            throw new InvalidArgumentException('harness: invalid guide filename');
        }

        $path = $job->workspace . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $guide);
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('harness: create guide directory: %s', $dir));
        }

        $content = rtrim($job->systemPrompt) . "\n";
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException(sprintf('harness: write %s failed', $guide));
        }
    }
}
