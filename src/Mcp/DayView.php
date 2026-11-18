<?php

namespace App\Mcp;

final readonly class DayView
{
    public function __construct(
        public string $date,
        public string $weekday,
        public ?string $dayOff,
        public ?string $start,
        public ?string $end,
        public int $normMinutes,
        public int $totalMinutes,
        public array $entries,
        public int $pendingJiraDeletions,
        public bool $jiraConfigured,
    ) {
    }
}
