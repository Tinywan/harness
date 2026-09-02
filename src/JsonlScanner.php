<?php

/**
 * @desc JSONL 格式流式扫描解析器
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness;

class JsonlScanner
{
    /**
     * Scans a resource stream or string and dispatches each line to the parser callback.
     *
     * @param resource|string $input
     * @param callable(Event): void $emit
     * @param callable(string, callable(Event): void): void $parseLine
     */
    public static function scan(mixed $input, callable $emit, callable $parseLine): void
    {
        if (is_string($input)) {
            $lines = explode("\n", $input);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $parseLine($line, $emit);
                }
            }
            return;
        }

        if (is_resource($input)) {
            while (!feof($input)) {
                $line = fgets($input);
                if ($line === false) {
                    break;
                }
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $parseLine($trimmed, $emit);
                }
            }
        }
    }
}
