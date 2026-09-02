<?php

/**
 * @desc Codex CLI 后端适配器
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

class CodexHarness implements HarnessInterface
{
    /**
     * @var string[]
     */
    public const ACCOUNT_PHRASES = [
        'rate_limit',
        'rate limit',
        'too many requests',
        '429',
        'insufficient_quota',
        'quota exceeded',
        'invalid_api_key',
        'incorrect api key',
        'account is not active',
    ];

    public function getBinary(): string
    {
        return 'codex';
    }

    public function getArgs(Job $job): array
    {
        $args = [];
        if ($job->baseURL !== '') {
            $args[] = '-c';
            $args[] = 'openai_base_url=' . $this->codexConfigValue($job->baseURL);
        }

        $args = array_merge($args, [
            'exec',
            '--json',
            '--sandbox',
            'danger-full-access',
            '--skip-git-repo-check',
        ]);

        if ($job->model !== '') {
            $args[] = '--model';
            $args[] = $job->model;
        }

        $id = Harness::safeSessionID($job->resumeSessionID);
        if ($id !== '') {
            $args[] = 'resume';
            $args[] = $id;
        }

        $args[] = '--';
        $args[] = Harness::safePrompt($this->getPrompt($job));

        return $args;
    }

    private function codexConfigValue(string $s): string
    {
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $ord = ord($s[$i]);
            if ($ord < 0x20 || $s[$i] === '"' || $s[$i] === '\\' || $ord === 0x7f) {
                return '""';
            }
        }

        return '"' . $s . '"';
    }

    public function getPrompt(Job $job): string
    {
        return Harness::explicitSkillPrompt($job, './skills/' . $job->skillName);
    }

    public function parseStream(mixed $stream, callable $emit): void
    {
        JsonlScanner::scan($stream, $emit, function (string $line, callable $emitCallback): void {
            $this->parseCodexLine($line, $emitCallback);
        });
    }

    public function parseCodexLine(string $line, callable $emit): void
    {
        $event = json_decode($line, true);
        if (!is_array($event)) {
            if (str_starts_with($line, 'Reading additional input from stdin')) {
                return;
            }
            $emit(new Event(EventKind::Text, text: $line));
            return;
        }

        $type = (string) ($event['type'] ?? '');
        $sessionId = (string) ($event['session_id'] ?? $event['thread_id'] ?? '');

        if ($this->isCodexSessionEvent($type) && $sessionId !== '') {
            $emit(new Event(EventKind::Session, sessionID: $sessionId));
            return;
        }

        switch ($type) {
            case 'turn.started':
                break;

            case 'turn.completed':
                $usage = null;
                if (!empty($event['usage']) && is_array($event['usage'])) {
                    $u = $event['usage'];
                    $usage = new Usage(
                        inputTokens: (int) ($u['input_tokens'] ?? 0),
                        outputTokens: (int) ($u['output_tokens'] ?? 0),
                        cacheReadTokens: (int) ($u['cached_input_tokens'] ?? 0),
                    );
                }
                $emit(new Event(EventKind::Result, turns: 1, usage: $usage));
                break;

            case 'item.started':
                break;

            default:
                $item = $event['item'] ?? null;
                if (is_array($item)) {
                    $itemType = (string) ($item['type'] ?? '');
                    if ($itemType === 'todo_list') {
                        return;
                    }
                    if ($itemType === 'error') {
                        $errText = is_string($item['message'] ?? null)
                            ? $item['message']
                            : (
                                is_array($item['message'] ?? null)
                                    ? $item['message']['message'] ?? json_encode(
                                        $item['message'],
                                        JSON_UNESCAPED_UNICODE,
                                    )
                                    : (string) ($item['message'] ?? '')
                            );
                        $emit(new Event(EventKind::Error, text: (string) $errText));
                        return;
                    }
                    if (!empty($item['text'])) {
                        $text = is_string($item['text'])
                            ? $item['text']
                            : json_encode($item['text'], JSON_UNESCAPED_UNICODE);
                        $emit(new Event(EventKind::Text, text: (string) $text));
                        return;
                    }
                    if ($this->isCodexToolItem($itemType)) {
                        $name = $this->codexToolName($item);
                        $emit(new Event(EventKind::Tool, tool: $name, text: $this->codexToolText($item)));
                        return;
                    }
                }

                if ($type === 'tool' || !empty($event['tool'])) {
                    $name = (string) ($event['tool'] ?? $event['name'] ?? '');
                    $emit(
                        new Event(
                            EventKind::Tool,
                            tool: $name,
                            text: Harness::summariseInput($name, $event['input'] ?? null),
                        ),
                    );
                    return;
                }

                if (!empty($event['error'])) {
                    $errorText = is_string($event['error'])
                        ? $event['error']
                        : $event['error']['message'] ?? json_encode($event['error'], JSON_UNESCAPED_UNICODE);
                    $emit(new Event(EventKind::Error, text: (string) $errorText));
                    return;
                }
                if (!empty($event['text'])) {
                    $text = is_string($event['text'])
                        ? $event['text']
                        : json_encode($event['text'], JSON_UNESCAPED_UNICODE);
                    $emit(new Event(EventKind::Text, text: (string) $text));
                    return;
                }
                if (!empty($event['message'])) {
                    $msg = is_string($event['message'])
                        ? $event['message']
                        : $event['message']['content'] ?? $event['message']['message'] ?? json_encode(
                            $event['message'],
                            JSON_UNESCAPED_UNICODE,
                        );
                    $emit(new Event(EventKind::Text, text: (string) $msg));
                    return;
                }

                $emit(new Event(EventKind::Text, text: $line));
                break;
        }
    }

    private function isCodexSessionEvent(string $type): bool
    {
        return in_array($type, ['thread.started', 'session.created', 'init'], true);
    }

    private function isCodexToolItem(string $itemType): bool
    {
        return (
            str_contains($itemType, 'command')
            || str_contains($itemType, 'tool')
            || $itemType === 'web_search'
            || $itemType === 'file_change'
        );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function codexToolName(array $item): string
    {
        if (!empty($item['tool'])) {
            return (string) $item['tool'];
        }
        if (!empty($item['name'])) {
            return (string) $item['name'];
        }
        $type = (string) ($item['type'] ?? '');
        if (str_contains($type, 'command')) {
            return 'command';
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function codexToolText(array $item): string
    {
        if (!empty($item['command'])) {
            return (string) $item['command'];
        }
        if (!empty($item['query'])) {
            return (string) $item['query'];
        }
        if (!empty($item['changes']) && is_array($item['changes'])) {
            $paths = [];
            foreach ($item['changes'] as $change) {
                if (!empty($change['path'])) {
                    $paths[] = (string) $change['path'];
                }
            }
            return implode(', ', $paths);
        }

        return Harness::summariseInput($this->codexToolName($item), $item['input'] ?? null);
    }

    public function getSkillDir(string $workspace, string $name): string
    {
        return $workspace . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . $name;
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
        return ['api.openai.com', 'auth0.openai.com', 'chatgpt.com'];
    }

    public function getEnv(?string $baseURL = null): array
    {
        $env = [
            'RUST_LOG' => 'error,opentelemetry_sdk=off,opentelemetry_otlp=off',
            'OMO_CODEX_SEND_ANONYMOUS_TELEMETRY' => '0',
            'OMO_CODEX_DISABLE_POSTHOG' => '1',
        ];

        return array_merge($env, Harness::passthroughEnv(['CODEX_API_KEY']));
    }

    public function getStateEnv(string $dir): array
    {
        return [
            'CODEX_HOME' => $dir,
        ];
    }

    public function getAccountErrorText(string $output): string
    {
        return Account::matchAccountPhrase($output, self::ACCOUNT_PHRASES);
    }

    public function getDefaultModels(): array
    {
        return [
            new ModelDefault('GPT-5.3 Codex', 'gpt-5.3-codex', 'high'),
            new ModelDefault('GPT-5.4 mini', 'gpt-5.4-mini', 'mid'),
            new ModelDefault('GPT-5.4', 'gpt-5.4'),
            new ModelDefault('GPT-5.5', 'gpt-5.5', 'max'),
            new ModelDefault('GPT-5.2', 'gpt-5.2'),
        ];
    }
}
