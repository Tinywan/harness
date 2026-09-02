<?php

/**
 * @desc Claude Code 后端适配器
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness\Backends;

use Harness\Account;
use Harness\Event;
use Harness\EventKind;
use Harness\Harness;
use Harness\HarnessInterface;
use Harness\Job;
use Harness\JsonlScanner;
use Harness\ModelDefault;
use Harness\RateLimitInfo;
use Harness\Usage;

class ClaudeHarness implements HarnessInterface
{
    public function getBinary(): string
    {
        return 'claude';
    }

    public function getArgs(Job $job): array
    {
        $args = [
            '-p',
            '--output-format',
            'stream-json',
            '--verbose',
        ];

        if ($job->model !== '') {
            $args[] = '--model';
            $args[] = $job->model;
        }

        if ($job->allowedTools !== '') {
            $args[] = '--permission-mode';
            $args[] = $this->claudePermissionMode($job);
            $args[] = '--allowedTools';
            $args[] = $job->allowedTools . ',Skill';
        } else {
            $args[] = '--permission-mode';
            $args[] = 'bypassPermissions';
        }

        if ($job->systemPrompt !== '') {
            $args[] = '--system-prompt';
            $args[] = $job->systemPrompt;
        }

        if ($job->effort !== '') {
            $args[] = '--effort';
            $args[] = $job->effort;
        }

        $id = Harness::safeSessionID($job->resumeSessionID);
        if ($id !== '') {
            $args[] = '--resume';
            $args[] = $id;
        }

        $maxTurns = $job->maxTurns > 0 ? $job->maxTurns : Job::DEFAULT_MAX_TURNS;
        $args[] = '--max-turns';
        $args[] = (string) $maxTurns;

        $args[] = '--';
        $args[] = Harness::safePrompt($this->getPrompt($job));

        return $args;
    }

    private function claudePermissionMode(Job $job): string
    {
        if ($job->outputFile === '') {
            return 'default';
        }

        return 'acceptEdits';
    }

    public function getPrompt(Job $job): string
    {
        if ($job->resumeSessionID !== '' && $job->resumePrompt !== '') {
            return $job->resumePrompt;
        }
        if ($job->resumeSessionID !== '') {
            return Harness::buildResumePrompt($job);
        }
        if ($job->prompt !== '') {
            return $job->prompt;
        }
        if ($job->skillName !== '') {
            return Harness::buildSkillPrompt($job);
        }

        return '';
    }

    public function parseStream(mixed $stream, callable $emit): void
    {
        JsonlScanner::scan($stream, $emit, function (string $line, callable $emitCallback): void {
            $this->parseClaudeLine($line, $emitCallback);
        });
    }

    public function parseClaudeLine(string $line, callable $emit): void
    {
        $data = json_decode($line, true);
        if (!is_array($data)) {
            $emit(new Event(EventKind::Text, text: $line));
            return;
        }

        $type = $data['type'] ?? '';
        $subtype = $data['subtype'] ?? '';

        switch ($type) {
            case 'system':
                if ($subtype === 'init' && !empty($data['session_id'])) {
                    $emit(new Event(EventKind::Session, sessionID: (string) $data['session_id']));
                }
                break;

            case 'assistant':
                if (!empty($data['message']['content']) && is_array($data['message']['content'])) {
                    $this->emitClaudeAssistant($data['message']['content'], $emit);
                }
                break;

            case 'result':
                $emit($this->claudeResultEvent($data));
                if ($subtype === 'error_max_turns') {
                    $emit(new Event(EventKind::Error, text: 'hit max turns'));
                }
                break;

            case 'error':
                $text = '';
                if (isset($data['error'])) {
                    $text = is_string($data['error'])
                        ? $data['error']
                        : $data['error']['message'] ?? json_encode($data['error'], JSON_UNESCAPED_UNICODE);
                }
                $emit(new Event(EventKind::Error, text: (string) $text));
                break;

            case 'rate_limit_event':
                if (!empty($data['rate_limit_info']) && is_array($data['rate_limit_info'])) {
                    $info = $data['rate_limit_info'];
                    $rateLimit = new RateLimitInfo(
                        status: (string) ($info['status'] ?? ''),
                        overageStatus: (string) ($info['overageStatus'] ?? ''),
                        isUsingOverage: (bool) ($info['isUsingOverage'] ?? false),
                        resetsAt: (int) ($info['resetsAt'] ?? 0),
                        type: (string) ($info['rateLimitType'] ?? ''),
                    );
                    $emit(new Event(EventKind::RateLimit, rateLimit: $rateLimit));
                }
                break;

            case 'user':
                // Tool results echo file contents and command output.
                break;

            default:
                $emit(new Event(EventKind::Text, text: $line));
                break;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $contents
     * @param callable(Event): void $emit
     */
    private function emitClaudeAssistant(array $contents, callable $emit): void
    {
        foreach ($contents as $block) {
            $type = $block['type'] ?? '';
            switch ($type) {
                case 'thinking':
                    if (!empty($block['thinking'])) {
                        $emit(new Event(EventKind::Thinking, text: (string) $block['thinking']));
                    }
                    break;

                case 'text':
                    if (!empty($block['text'])) {
                        $emit(new Event(EventKind::Text, text: (string) $block['text']));
                    }
                    break;

                case 'tool_use':
                    $name = (string) ($block['name'] ?? '');
                    $emit(
                        new Event(
                            EventKind::Tool,
                            tool: $name,
                            text: Harness::summariseInput($name, $block['input'] ?? null),
                        ),
                    );
                    break;
            }
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function claudeResultEvent(array $message): Event
    {
        $text = '';
        if (isset($message['result'])) {
            $text = is_string($message['result']) ? $message['result'] : json_encode($message['result']);
        }

        $usage = null;
        if (!empty($message['usage']) && is_array($message['usage'])) {
            $u = $message['usage'];
            $usage = new Usage(
                inputTokens: (int) ($u['input_tokens'] ?? 0),
                outputTokens: (int) ($u['output_tokens'] ?? 0),
                cacheReadTokens: (int) ($u['cache_read_input_tokens'] ?? 0),
                cacheWriteTokens: (int) ($u['cache_creation_input_tokens'] ?? 0),
            );
        }

        return new Event(
            EventKind::Result,
            text: (string) $text,
            costUSD: (float) ($message['total_cost_usd'] ?? 0.0),
            turns: (int) ($message['num_turns'] ?? 0),
            usage: $usage,
        );
    }

    public function getSkillDir(string $workspace, string $name): string
    {
        return (
            $workspace . DIRECTORY_SEPARATOR . '.claude' . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . $name
        );
    }

    public function getGuideFilename(): string
    {
        return 'CLAUDE.md';
    }

    public function systemPromptViaArgs(): bool
    {
        return true;
    }

    public function getEgressHosts(): array
    {
        return ['*.anthropic.com'];
    }

    public function getEnv(?string $baseURL = null): array
    {
        $env = [
            'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' => '1',
            'OTEL_SDK_DISABLED' => 'true',
            'DISABLE_TELEMETRY' => '1',
            'DISABLE_ERROR_REPORTING' => '1',
            'DISABLE_BUG_COMMAND' => '1',
            'DISABLE_AUTOUPDATER' => '1',
            'DISABLE_NON_ESSENTIAL_MODEL_CALLS' => '1',
        ];

        $passthrough = Harness::passthroughEnv(['ANTHROPIC_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN']);
        $env = array_merge($env, $passthrough);

        if ($baseURL !== null && $baseURL !== '') {
            $env['ANTHROPIC_BASE_URL'] = $baseURL;
        }

        return $env;
    }

    public function getStateEnv(string $dir): array
    {
        return [
            'CLAUDE_CONFIG_DIR' => $dir,
        ];
    }

    public function getAccountErrorText(string $output): string
    {
        return Account::claudeAccountErrorText($output);
    }

    public function getDefaultModels(): array
    {
        return [
            new ModelDefault('Opus 4.6', 'claude-opus-4-6', 'high'),
            new ModelDefault('Opus 4.7', 'claude-opus-4-7'),
            new ModelDefault('Opus 4.8', 'claude-opus-4-8'),
            new ModelDefault('Opus 5.0', 'claude-opus-5', 'max'),
            new ModelDefault('Sonnet 4.6', 'claude-sonnet-4-6', 'mid'),
            new ModelDefault('Sonnet 5.0', 'claude-sonnet-5'),
            new ModelDefault('Fable 5', 'claude-fable-5[1m]'),
            new ModelDefault('Fable 5.1', 'claude-fable-5-1[1m]'),
        ];
    }
}
