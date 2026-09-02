<?php

/**
 * @desc 统一流式事件实体类
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

namespace Harness;

class Event
{
    public const LINE_LIMIT = 300;

    public function __construct(
        public EventKind|string $kind = EventKind::Text,
        public string $tool = '',
        public string $text = '',
        public float $costUSD = 0.0,
        public int $turns = 0,
        public ?Usage $usage = null,
        public string $sessionID = '',
        public ?RateLimitInfo $rateLimit = null,
    ) {
        $this->usage ??= new Usage();
    }

    public function getKindValue(): string
    {
        return $this->kind instanceof EventKind ? $this->kind->value : (string) $this->kind;
    }

    /**
     * Truncates a string to the default line limit.
     */
    public static function truncate(string $s, int $limit = self::LINE_LIMIT): string
    {
        $len = mb_strlen($s, 'UTF-8');
        if ($len <= $limit) {
            return $s;
        }

        return mb_substr($s, 0, $limit, 'UTF-8') . sprintf('... (%d chars)', $len);
    }

    /**
     * Renders an event as one plain-text log line.
     */
    public function format(): string
    {
        $kind = $this->getKindValue();

        return match ($kind) {
            EventKind::Thinking->value => '[thinking] ' . self::truncate($this->text),
            EventKind::Tool->value => sprintf('[%s] %s', strtolower($this->tool), self::truncate($this->text)),
            EventKind::Result->value => sprintf('[result] cost=$%.4f turns=%d %s', $this->costUSD, $this->turns, self::truncate($this->text)),
            EventKind::Session->value => '[session] ' . $this->sessionID,
            EventKind::RateLimit->value => $this->formatRateLimit(),
            EventKind::Egress->value => '[egress] ' . $this->text,
            EventKind::Error->value => '[error] ' . $this->text,
            default => $this->text,
        };
    }

    private function formatRateLimit(): string
    {
        if ($this->rateLimit === null) {
            return '[rate-limit]';
        }

        $line = '[rate-limit] ' . $this->rateLimit->type . ' ' . $this->rateLimit->status;
        $reset = $this->rateLimit->getResetTime();
        if ($reset !== null) {
            $line .= ' resets ' . $reset->format('Y-m-d H:i \U\T\C');
        }

        return $line;
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
