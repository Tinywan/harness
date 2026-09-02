<?php

/**
 * @desc 子进程执行与实时流式驱动器
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness;

use Harness\Exceptions\AccountError;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

class Runner
{
    /**
     * Starts $harness as a local subprocess in $job->workspace and streams parsed events to $emit.
     *
     * @param callable(Event): void|null $emit
     * @throws AccountError When the provider reports an account limit error on failure.
     * @throws \RuntimeException On process execution or other non-zero failures.
     */
    public static function run(
        HarnessInterface $harness,
        Job $job,
        ?callable $emit = null,
        ?float $timeout = null
    ): void {
        if ($job->workspace === '') {
            throw new InvalidArgumentException('harness: workspace is required');
        }

        Harness::writeSystemPrompt($harness, $job);

        $command = array_merge([$harness->getBinary()], $harness->getArgs($job));
        $env = self::mergeEnv(
            self::getHostEnv(),
            $harness->getEnv($job->baseURL)
        );

        $process = new Process($command, $job->workspace, $env, null, $timeout);

        self::streamProcess($process, $harness, $emit);
    }

    /**
     * Executes the Process, parses output streams into Events, and handles exit classification.
     *
     * @param callable(Event): void|null $emit
     * @return string Stderr output
     */
    public static function streamProcess(
        Process $process,
        HarnessInterface $harness,
        ?callable $emit = null
    ): string {
        $emit ??= function (Event $e): void {};

        $stderrBuf = '';
        /** @var string[] $parsedErrors */
        $parsedErrors = [];

        // Buffer partial line chunks between callbacks
        $stdoutBuffer = '';

        $process->start(function (string $type, string $buffer) use ($harness, $emit, &$stderrBuf, &$parsedErrors, &$stdoutBuffer): void {
            if ($type === Process::ERR) {
                $stderrBuf .= $buffer;
                // Also parse stderr as stream lines in case backend outputs JSONL on stderr
                $lines = explode("\n", $buffer);
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed !== '') {
                        $harness->parseStream($trimmed, function (Event $event) use ($emit, &$parsedErrors): void {
                            if ($event->getKindValue() === EventKind::Error->value && $event->text !== '') {
                                $parsedErrors[] = $event->text;
                            }
                            $emit($event);
                        });
                    }
                }
            } else {
                $stdoutBuffer .= $buffer;
                while (($pos = strpos($stdoutBuffer, "\n")) !== false) {
                    $line = substr($stdoutBuffer, 0, $pos);
                    $stdoutBuffer = substr($stdoutBuffer, $pos + 1);
                    $trimmed = trim($line);
                    if ($trimmed !== '') {
                        $harness->parseStream($trimmed, function (Event $event) use ($emit, &$parsedErrors): void {
                            if ($event->getKindValue() === EventKind::Error->value && $event->text !== '') {
                                $parsedErrors[] = $event->text;
                            }
                            $emit($event);
                        });
                    }
                }
            }
        });

        $process->wait();

        // Flush any remaining buffered stdout line
        $remaining = trim($stdoutBuffer);
        if ($remaining !== '') {
            $harness->parseStream($remaining, function (Event $event) use ($emit, &$parsedErrors): void {
                if ($event->getKindValue() === EventKind::Error->value && $event->text !== '') {
                    $parsedErrors[] = $event->text;
                }
                $emit($event);
            });
        }

        if (!$process->isSuccessful()) {
            $detail = $harness->getAccountErrorText($stderrBuf);
            foreach ($parsedErrors as $errorText) {
                $detail = Account::preferAccountErrorText($detail, $harness->getAccountErrorText($errorText));
            }

            if ($detail !== '') {
                throw new AccountError($detail);
            }

            $exitCode = $process->getExitCode();
            $cmdLine = $process->getCommandLine();
            throw new \RuntimeException(sprintf(
                'harness: %s failed with exit code %d: %s',
                $cmdLine,
                $exitCode ?? -1,
                $stderrBuf !== '' ? $stderrBuf : $process->getErrorOutput()
            ));
        }

        return $stderrBuf;
    }

    /**
     * Merges base host environment with backend-specific overrides.
     *
     * @param array<string, string> $base
     * @param array<string, string>|string[] $overrides
     * @return array<string, string>
     */
    public static function mergeEnv(array $base, array $overrides): array
    {
        $merged = $base;

        foreach ($overrides as $key => $val) {
            if (is_int($key)) {
                if (str_contains($val, '=')) {
                    [$k, $v] = explode('=', $val, 2);
                    $merged[$k] = $v;
                } else {
                    // Bare key passthrough
                    $hostVal = getenv($val);
                    if ($hostVal !== false) {
                        $merged[$val] = $hostVal;
                    }
                }
            } else {
                $merged[$key] = (string) $val;
            }
        }

        return $merged;
    }

    /**
     * @return array<string, string>
     */
    private static function getHostEnv(): array
    {
        $env = [];
        foreach ($_ENV as $k => $v) {
            $env[(string) $k] = (string) $v;
        }
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && !isset($env[$k])) {
                $env[$k] = $v;
            }
        }

        return $env;
    }
}
