<?php

/**
 * @desc OpenCode 后端适配器
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
use Harness\Usage;

class OpencodeHarness implements HarnessInterface
{
    /**
     * @var string[]
     */
    public const ACCOUNT_PHRASES = [
        'rate limit',
        'rate_limit',
        'too many requests',
        '429',
        'usage limit',
        'quota',
        'insufficient_quota',
        'invalid_api_key',
        'incorrect api key',
        'invalid x-api-key',
        'credit balance',
        'billing',
    ];

    public function getBinary(): string
    {
        return 'opencode';
    }

    public function getArgs(Job $job): array
    {
        $args = [
            'run',
            '--format',
            'json',
            '--auto',
        ];

        if ($job->model !== '') {
            $args[] = '--model';
            $args[] = $job->model;
        }

        $id = Harness::safeSessionID($job->resumeSessionID);
        if ($id !== '') {
            $args[] = '--session';
            $args[] = $id;
        }

        $args[] = '--';
        $args[] = Harness::safePrompt($this->getPrompt($job));

        return $args;
    }

    public function getPrompt(Job $job): string
    {
        return Harness::explicitSkillPrompt($job, './.opencode/skill/' . $job->skillName);
    }

    public function parseStream(mixed $stream, callable $emit): void
    {
        JsonlScanner::scan($stream, $emit, function (string $line, callable $emitCallback): void {
            $this->parseOpencodeLine($line, $emitCallback);
        });
    }

    public function parseOpencodeLine(string $line, callable $emit): void
    {
        $event = json_decode($line, true);
        if (!is_array($event)) {
            $emit(new Event(EventKind::Text, text: $line));
            return;
        }

        $type = (string) ($event['type'] ?? '');
        $sessionId = (string) ($event['sessionID'] ?? '');
        $part = $event['part'] ?? null;

        if ($type === 'step_start' && $sessionId !== '') {
            $emit(new Event(EventKind::Session, sessionID: $sessionId));
            return;
        }

        if ($this->isOpencodeToolEvent($event)) {
            $name = (string) ($part['tool'] ?? $part['name'] ?? '');
            $input = $part['state']['input'] ?? null;
            $emit(new Event(EventKind::Tool, tool: $name, text: Harness::summariseInput($name, $input)));
            return;
        }

        if ($type === 'error' || !empty($event['error'])) {
            $emit(new Event(EventKind::Error, text: $this->opencodeErrorText($event['error'] ?? null, $line)));
            return;
        }

        if ($this->isOpencodeReasoningEvent($event)) {
            $emit(new Event(EventKind::Thinking, text: (string) $part['text']));
            return;
        }

        if ($this->isOpencodeTextEvent($event)) {
            $emit(new Event(EventKind::Text, text: (string) $part['text']));
            return;
        }

        if ($type === 'step_finish' && is_array($part)) {
            $emit(
                new Event(
                    EventKind::Result,
                    costUSD: (float) ($part['cost'] ?? 0.0),
                    turns: 1,
                    usage: $this->opencodeUsage($part['tokens'] ?? null),
                ),
            );
            return;
        }

        if ($type === 'step_finish') {
            return;
        }

        $emit(new Event(EventKind::Text, text: $line));
    }

    /**
     * @param array<string, mixed>|null $tokens
     */
    private function opencodeUsage(?array $tokens): Usage
    {
        if ($tokens === null) {
            return new Usage();
        }

        $input = (int) ($tokens['input'] ?? 0);
        $output = (int) ($tokens['output'] ?? 0);
        $reasoning = (int) ($tokens['reasoning'] ?? 0);
        $read = (int) ($tokens['cache']['read'] ?? 0);
        $write = (int) ($tokens['cache']['write'] ?? 0);

        return new Usage(
            inputTokens: $input,
            outputTokens: $output + $reasoning,
            cacheReadTokens: $read,
            cacheWriteTokens: $write,
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function isOpencodeToolEvent(array $event): bool
    {
        $part = $event['part'] ?? null;
        if (!is_array($part)) {
            return false;
        }

        $type = (string) ($event['type'] ?? '');
        $partType = (string) ($part['type'] ?? '');

        return $type === 'tool' || $partType === 'tool' || !empty($part['tool']) || !empty($part['name']);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function isOpencodeReasoningEvent(array $event): bool
    {
        $part = $event['part'] ?? null;
        if (!is_array($part) || empty($part['text'])) {
            return false;
        }

        $type = (string) ($event['type'] ?? '');
        $partType = (string) ($part['type'] ?? '');

        return $type === 'reasoning' || $partType === 'reasoning';
    }

    /**
     * @param array<string, mixed> $event
     */
    private function isOpencodeTextEvent(array $event): bool
    {
        $part = $event['part'] ?? null;
        if (!is_array($part) || empty($part['text'])) {
            return false;
        }

        $type = (string) ($event['type'] ?? '');
        $partType = (string) ($part['type'] ?? '');

        return $type === 'text' || $partType === 'text';
    }

    private function opencodeErrorText(mixed $raw, string $fallback): string
    {
        if ($raw === null) {
            return $fallback;
        }
        if (is_string($raw)) {
            return $raw;
        }
        if (is_array($raw)) {
            $candidates = [
                $raw['data']['message'] ?? '',
                $raw['message'] ?? '',
                $raw['code'] ?? '',
                $raw['name'] ?? '',
            ];
            foreach ($candidates as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
            return json_encode($raw) ?: $fallback;
        }

        return $fallback;
    }

    public function getSkillDir(string $workspace, string $name): string
    {
        return (
            $workspace . DIRECTORY_SEPARATOR . '.opencode' . DIRECTORY_SEPARATOR . 'skill' . DIRECTORY_SEPARATOR . $name
        );
    }

    public function getGuideFilename(): string
    {
        return 'AGENTS.md';
    }

    public function systemPromptViaArgs(): bool
    {
        return false;
    }

    public function getEgressHosts(): array
    {
        return ['models.dev', 'api.openai.com', '*.anthropic.com'];
    }

    public function getEnv(?string $baseURL = null): array
    {
        $env = [
            'OPENCODE_DISABLE_AUTOUPDATE' => 'true',
            'OPENCODE_DISABLE_MODELS_FETCH' => 'true',
            'OPENCODE_DISABLE_SHARE' => 'true',
            'OPENCODE_PRINT_LOGS' => '1',
            'OPENCODE_LOG_LEVEL' => 'error',
        ];

        return array_merge($env, Harness::passthroughEnv([
            'OPENAI_API_KEY',
            'ANTHROPIC_API_KEY',
            'OPENCODE_CONFIG_CONTENT',
            'OPENCODE_AUTH_CONTENT',
        ]));
    }

    public function getStateEnv(string $dir): array
    {
        return [
            'OPENCODE_CONFIG_DIR' => $dir,
            'OPENCODE_DB' => $dir . DIRECTORY_SEPARATOR . 'opencode.db',
        ];
    }

    public function getAccountErrorText(string $output): string
    {
        return Account::matchAccountPhrase($output, self::ACCOUNT_PHRASES);
    }

    public function getDefaultModels(): array
    {
        $claude = new ClaudeHarness()->getDefaultModels();
        $models = [];
        foreach ($claude as $m) {
            $models[] = new ModelDefault($m->name, 'anthropic/' . $m->id, $m->tier);
        }

        return $models;
    }
}
