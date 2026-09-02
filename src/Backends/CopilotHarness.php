<?php

/**
 * @desc GitHub Copilot CLI 后端适配器
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness\Backends;

use DateTimeImmutable;
use Harness\Account;
use Harness\Event;
use Harness\EventKind;
use Harness\Harness;
use Harness\HarnessInterface;
use Harness\Job;
use Harness\JsonlScanner;
use Harness\ModelDefault;
use Harness\Pricing;
use Harness\RateLimitInfo;
use Harness\Usage;

class CopilotHarness implements HarnessInterface
{
    private const NANO_AIU_PER_AI_CREDIT = 1e9;
    private const USD_PER_AI_CREDIT = 0.01;

    /**
     * @var string[]
     */
    public const ACCOUNT_PHRASES = [
        'rate limit',
        'too many requests',
        'quota',
        'not entitled',
        'copilot access',
        'authentication failed',
        'unauthorized',
        'forbidden',
        'token expired',
        '429',
    ];

    /**
     * @var string[]
     */
    public const BYOK_ENV = [
        'COPILOT_MODEL',
        'COPILOT_PROVIDER_API_KEY',
        'COPILOT_PROVIDER_BEARER_TOKEN',
        'COPILOT_PROVIDER_TYPE',
        'COPILOT_PROVIDER_WIRE_API',
        'COPILOT_PROVIDER_TRANSPORT',
        'COPILOT_PROVIDER_AZURE_API_VERSION',
        'COPILOT_PROVIDER_MODEL_ID',
        'COPILOT_PROVIDER_WIRE_MODEL',
        'COPILOT_PROVIDER_MAX_PROMPT_TOKENS',
        'COPILOT_PROVIDER_MAX_OUTPUT_TOKENS',
        'COPILOT_PROVIDER_HEADERS',
    ];

    public function getBinary(): string
    {
        return 'copilot';
    }

    public function getArgs(Job $job): array
    {
        $maxTurns = $job->maxTurns > 0 ? $job->maxTurns : Job::DEFAULT_MAX_TURNS;

        $args = [
            '-p', $this->getPrompt($job),
            '--output-format', 'json',
            '--autopilot',
            '--max-autopilot-continues', (string) $maxTurns,
            '--allow-all',
            '--no-ask-user',
            '--no-auto-update',
            '--no-color',
            '--no-remote-export',
        ];

        if ($job->model !== '') {
            $args[] = '--model';
            $args[] = $job->model;
        }

        if ($job->effort !== '') {
            $args[] = '--effort';
            $args[] = $job->effort;
        }

        $id = Harness::safeSessionID($job->resumeSessionID);
        if ($id !== '') {
            $args[] = '--resume=' . $id;
        }

        return $args;
    }

    public function getPrompt(Job $job): string
    {
        return Harness::explicitSkillPrompt($job, './.github/skills/' . $job->skillName);
    }

    public function parseStream(mixed $stream, callable $emit): void
    {
        $state = new CopilotStreamState();
        JsonlScanner::scan($stream, $emit, function (string $line, callable $emitCallback) use ($state): void {
            $state->parseLine($line, $emitCallback);
        });

        if (!$state->sawTerminal) {
            return;
        }

        $state->result->costUSD = $state->sawCheckpoint ? $state->checkpointCostUSD : $state->estimatedCostUSD;
        $emit($state->result);
    }

    public function getSkillDir(string $workspace, string $name): string
    {
        return $workspace . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . $name;
    }

    public function getGuideFilename(): string
    {
        return '.github' . DIRECTORY_SEPARATOR . 'copilot-instructions.md';
    }

    public function systemPromptViaArgs(): bool
    {
        return false;
    }

    public function getEgressHosts(): array
    {
        return [
            'github.com',
            'api.github.com',
            'api.mcp.github.com',
            '*.githubcopilot.com',
        ];
    }

    public function getEnv(?string $baseURL = null): array
    {
        $env = [
            'COPILOT_AUTO_UPDATE' => 'false',
            'COPILOT_OTEL_ENABLED' => 'false',
            'NO_COLOR' => '1',
        ];

        $tokens = Harness::passthroughEnv(['COPILOT_GITHUB_TOKEN', 'GH_TOKEN', 'GITHUB_TOKEN']);
        $env = array_merge($env, $tokens);

        if ($baseURL !== null && $baseURL !== '') {
            $env['COPILOT_PROVIDER_BASE_URL'] = $baseURL;
            $env = array_merge($env, Harness::passthroughEnv(self::BYOK_ENV));
        }

        return $env;
    }

    public function getStateEnv(string $dir): array
    {
        return [
            'COPILOT_HOME' => $dir,
        ];
    }

    public function getAccountErrorText(string $output): string
    {
        return Account::matchAccountPhrase($output, self::ACCOUNT_PHRASES);
    }

    public function getDefaultModels(): array
    {
        return [
            new ModelDefault('GPT-5.6 Sol', 'gpt-5.6-sol'),
            new ModelDefault('Claude Sonnet 5', 'claude-sonnet-5'),
            new ModelDefault('Claude Opus 5', 'claude-opus-5', 'max'),
            new ModelDefault('Claude Opus 4.8', 'claude-opus-4.8'),
            new ModelDefault('Claude Opus 4.7', 'claude-opus-4.7'),
            new ModelDefault('Claude Sonnet 4.6', 'claude-sonnet-4.6', 'mid'),
            new ModelDefault('Claude Opus 4.6', 'claude-opus-4.6', 'high'),
            new ModelDefault('Claude Haiku 4.5', 'claude-haiku-4.5'),
            new ModelDefault('GPT-5.6 Terra', 'gpt-5.6-terra'),
            new ModelDefault('GPT-5.6 Luna', 'gpt-5.6-luna'),
            new ModelDefault('GPT-5.5', 'gpt-5.5'),
            new ModelDefault('GPT-5.4', 'gpt-5.4'),
            new ModelDefault('GPT-5.4 mini', 'gpt-5.4-mini'),
            new ModelDefault('GPT-5.3-Codex', 'gpt-5.3-codex'),
            new ModelDefault('GPT-5 mini', 'gpt-5-mini'),
            new ModelDefault('MAI-Code-1-Flash', 'mai-code-1-flash-picker'),
            new ModelDefault('Gemini 3.7 Flash', 'gemini-3.7-flash'),
            new ModelDefault('Gemini 3.6 Flash', 'gemini-3.6-flash'),
            new ModelDefault('Gemini 3.5 Flash', 'gemini-3.5-flash'),
            new ModelDefault('Gemini 3.1 Pro Preview', 'gemini-3.1-pro-preview'),
            new ModelDefault('Grok 4.5', 'grok-4.5'),
            new ModelDefault('Grok 4.6', 'grok-4.6'),
            new ModelDefault('MAI-Code-1.1-Flash', 'mai-code-1.1-flash'),
        ];
    }
}

/**
 * @desc Copilot JSONL 内部流状态机累加器
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */
class CopilotStreamState
{
    public Event $result;
    public string $resultCallID = '';
    /** @var string[] */
    public array $resultChunks = [];
    /** @var array<string, string> */
    public array $messageReasoning = [];
    public float $estimatedCostUSD = 0.0;
    public float $checkpointCostUSD = 0.0;
    public bool $sawCheckpoint = false;
    public bool $sawTerminal = false;

    public function __construct()
    {
        $this->result = new Event(EventKind::Result);
    }

    public function parseLine(string $line, callable $emit): void
    {
        $event = json_decode($line, true);
        if (!is_array($event)) {
            $emit(new Event(EventKind::Text, text: $line));
            return;
        }

        $type = (string) ($event['type'] ?? '');
        $data = $event['data'] ?? null;
        $agentId = (string) ($event['agentId'] ?? '');

        switch ($type) {
            case 'assistant.message':
                $this->handleMessage($event, $emit);
                break;

            case 'assistant.reasoning':
                $this->handleReasoning($event, $emit);
                break;

            case 'assistant.intent':
                if ($agentId === '' && is_array($data) && !empty($data['intent'])) {
                    $emit(new Event(EventKind::Thinking, text: (string) $data['intent']));
                }
                break;

            case 'tool.execution_start':
                if ($agentId === '' && is_array($data) && !empty($data['toolName'])) {
                    $toolName = (string) $data['toolName'];
                    $emit(new Event(
                        EventKind::Tool,
                        tool: $toolName,
                        text: Harness::summariseInput($toolName, $data['arguments'] ?? null)
                    ));
                }
                break;

            case 'assistant.usage':
                if (is_array($data)) {
                    $this->addUsage($data);
                    if (!empty($data['quotaSnapshots']) && is_array($data['quotaSnapshots'])) {
                        $this->emitCopilotRateLimits($data['quotaSnapshots'], $emit);
                    }
                }
                break;

            case 'session.usage_checkpoint':
                if (is_array($data) && isset($data['totalNanoAiu'])) {
                    $total = (float) $data['totalNanoAiu'];
                    $this->checkpointCostUSD = $total / 1e9 * 0.01;
                    $this->sawCheckpoint = true;
                }
                break;

            case 'assistant.turn_end':
                if ($agentId === '') {
                    $this->result->turns++;
                }
                break;

            case 'result':
                $this->sawTerminal = true;
                if (!empty($event['sessionId'])) {
                    $emit(new Event(EventKind::Session, sessionID: (string) $event['sessionId']));
                }
                if (isset($event['exitCode']) && (int) $event['exitCode'] !== 0) {
                    $emit(new Event(EventKind::Error, text: sprintf('copilot exited with code %d', (int) $event['exitCode'])));
                }
                break;

            case 'model.call_failure':
                $this->emitCopilotModelCallFailure($data, $emit);
                break;

            case 'session.error':
            case 'error':
                $this->emitCopilotError($data, $line, $emit);
                break;

            case 'abort':
                $reason = is_array($data) && !empty($data['reason']) ? (string) $data['reason'] : '';
                $text = $reason !== '' ? 'copilot aborted: ' . $reason : $line;
                $emit(new Event(EventKind::Error, text: $text));
                break;

            case 'session.info':
            case 'session.warning':
                if (is_array($data) && !empty($data['message'])) {
                    $emit(new Event(EventKind::Text, text: (string) $data['message']));
                }
                break;

            case 'assistant.message_delta':
            case 'assistant.message_start':
            case 'assistant.reasoning_delta':
            case 'assistant.streaming_delta':
            case 'assistant.tool_call_delta':
            case 'assistant.turn_start':
            case 'assistant.idle':
            case 'model.call_start':
            case 'model.call_end':
            case 'mcp.prompts.list_changed':
            case 'mcp.resources.list_changed':
            case 'mcp.tools.list_changed':
            case 'session.idle':
            case 'session.mcp_server_status_changed':
            case 'session.mcp_servers_loaded':
            case 'session.skills_loaded':
            case 'session.task_complete':
            case 'session.tools_updated':
            case 'session.usage_info':
            case 'tool.execution_complete':
            case 'tool.execution_partial_result':
            case 'tool.execution_progress':
            case 'user.message':
                break;

            default:
                $emit(new Event(EventKind::Text, text: $line));
                break;
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleMessage(array $event, callable $emit): void
    {
        $data = $event['data'] ?? null;
        if (!is_array($data) || !empty($event['agentId'])) {
            return;
        }

        $id = (string) ($event['id'] ?? '');
        $reasoning = (string) ($data['reasoningText'] ?? '');
        $content = (string) ($data['content'] ?? '');

        if ($id !== '' && $reasoning !== '') {
            $this->messageReasoning[$id] = $reasoning;
        }
        if ($reasoning !== '') {
            $emit(new Event(EventKind::Thinking, text: $reasoning));
        }
        if ($content !== '') {
            $emit(new Event(EventKind::Text, text: $content));
            $this->recordResultText($data);
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleReasoning(array $event, callable $emit): void
    {
        if (!empty($event['agentId'])) {
            return;
        }

        $data = $event['data'] ?? null;
        if (!is_array($data) || empty($data['content'])) {
            return;
        }

        $content = (string) $data['content'];
        $parentId = (string) ($event['parentId'] ?? '');
        if ($parentId !== '' && isset($this->messageReasoning[$parentId])) {
            $prev = $this->messageReasoning[$parentId];
            unset($this->messageReasoning[$parentId]);
            if ($content === $prev) {
                return;
            }
        }

        $emit(new Event(EventKind::Thinking, text: $content));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function recordResultText(array $data): void
    {
        $callId = (string) ($data['apiCallId'] ?? ($data['messageId'] ?? ''));
        $content = (string) ($data['content'] ?? '');
        $chunkCount = isset($data['chunkCount']) ? (int) $data['chunkCount'] : null;
        $chunkIndex = isset($data['chunkIndex']) ? (int) $data['chunkIndex'] : null;

        if ($callId === '' || $chunkCount === null || $chunkIndex === null || $chunkCount <= 1 || $chunkIndex < 0 || $chunkIndex >= $chunkCount) {
            $this->resultCallID = $callId;
            $this->resultChunks = [];
            $this->result->text = $content;
            return;
        }

        if ($this->resultCallID !== $callId || count($this->resultChunks) !== $chunkCount) {
            $this->resultCallID = $callId;
            $this->resultChunks = array_fill(0, $chunkCount, '');
        }

        $this->resultChunks[$chunkIndex] = $content;
        $this->result->text = implode('', $this->resultChunks);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function addUsage(array $data): void
    {
        $u = new Usage(
            inputTokens: (int) ($data['inputTokens'] ?? 0),
            outputTokens: (int) ($data['outputTokens'] ?? 0),
            cacheReadTokens: (int) ($data['cacheReadTokens'] ?? 0),
            cacheWriteTokens: (int) ($data['cacheWriteTokens'] ?? 0),
        );

        $model = (string) ($data['model'] ?? '');
        $this->estimatedCostUSD += Pricing::costFromUsage($model, $u);

        $this->result->usage->inputTokens += $u->inputTokens;
        $this->result->usage->outputTokens += $u->outputTokens;
        $this->result->usage->cacheReadTokens += $u->cacheReadTokens;
        $this->result->usage->cacheWriteTokens += $u->cacheWriteTokens;
    }

    /**
     * @param array<string, array<string, mixed>> $snapshots
     */
    private function emitCopilotRateLimits(array $snapshots, callable $emit): void
    {
        $keys = array_keys($snapshots);
        sort($keys);

        foreach ($keys as $key) {
            $snapshot = $snapshots[$key];
            if (!$this->copilotQuotaExhausted($snapshot)) {
                continue;
            }

            $hasUsageAllowed = (bool) ($snapshot['usageAllowedWithExhaustedQuota'] ?? false);
            $hasOverageAllowed = (bool) ($snapshot['overageAllowedWithExhaustedQuota'] ?? false);
            $overage = (float) ($snapshot['overage'] ?? 0.0);

            $status = (!$hasUsageAllowed && !$hasOverageAllowed) ? 'rejected' : 'allowed';
            $overageStatus = $hasOverageAllowed ? 'allowed' : 'rejected';

            $resetsAt = 0;
            if (!empty($snapshot['resetDate'])) {
                $dt = date_create_immutable((string) $snapshot['resetDate']);
                if ($dt !== false) {
                    $resetsAt = $dt->getTimestamp();
                }
            }

            $info = new RateLimitInfo(
                status: $status,
                overageStatus: $overageStatus,
                isUsingOverage: ($overage > 0 && $hasOverageAllowed),
                resetsAt: $resetsAt,
                type: $key,
            );

            $emit(new Event(EventKind::RateLimit, rateLimit: $info));
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function copilotQuotaExhausted(array $snapshot): bool
    {
        if (!empty($snapshot['isUnlimitedEntitlement'])) {
            return false;
        }
        if (isset($snapshot['hasQuota'])) {
            return !(bool) $snapshot['hasQuota'];
        }
        if (isset($snapshot['remainingPercentage']) && (float) $snapshot['remainingPercentage'] <= 0) {
            return true;
        }
        if (isset($snapshot['entitlementRequests'], $snapshot['usedRequests'])) {
            return (float) $snapshot['usedRequests'] >= (float) $snapshot['entitlementRequests'];
        }

        return false;
    }

    private function emitCopilotModelCallFailure(mixed $raw, callable $emit): void
    {
        if (!is_array($raw)) {
            return;
        }

        if (!empty($raw['quotaSnapshots']) && is_array($raw['quotaSnapshots'])) {
            $this->emitCopilotRateLimits($raw['quotaSnapshots'], $emit);
        }

        $text = (string) ($raw['errorMessage'] ?? 'model call failed');
        $details = $this->copilotErrorDetails(
            (string) ($raw['model'] ?? ''),
            (string) ($raw['failureKind'] ?? ''),
            (string) ($raw['errorType'] ?? ''),
            (string) ($raw['errorCode'] ?? ''),
            isset($raw['statusCode']) ? (int) $raw['statusCode'] : null
        );

        $emit(new Event(EventKind::Error, text: $text . $details));
    }

    private function emitCopilotError(mixed $raw, string $fallback, callable $emit): void
    {
        if (is_array($raw)) {
            $text = (string) ($raw['message'] ?? '');
            if ($text === '' && !empty($raw['error'])) {
                $text = is_string($raw['error']) ? $raw['error'] : (string) ($raw['error']['message'] ?? json_encode($raw['error'], JSON_UNESCAPED_UNICODE));
            }
            if ($text !== '') {
                $details = $this->copilotErrorDetails(
                    '',
                    '',
                    (string) ($raw['errorType'] ?? ''),
                    (string) ($raw['errorCode'] ?? ''),
                    isset($raw['statusCode']) ? (int) $raw['statusCode'] : null
                );
                $emit(new Event(EventKind::Error, text: $text . $details));
                return;
            }
        }

        $emit(new Event(EventKind::Error, text: $fallback));
    }

    private function copilotErrorDetails(string $model, string $failureKind, string $errorType, string $errorCode, ?int $statusCode): string
    {
        $details = [];
        foreach ([$model, $failureKind, $errorType, $errorCode] as $d) {
            if ($d !== '') {
                $details[] = $d;
            }
        }
        if ($statusCode !== null) {
            $details[] = 'status ' . $statusCode;
        }

        if (empty($details)) {
            return '';
        }

        return ' (' . implode(', ', $details) . ')';
    }
}
