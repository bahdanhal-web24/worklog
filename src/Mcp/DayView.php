<?php

namespace App\Mcp;

final readonly class DayView
{
    public function __construct(
        public private(set) string $date,
        public private(set) string $weekday,
        public private(set) ?string $dayOff,
        public private(set) ?string $start,
        public private(set) ?string $end,
        public private(set) int $normMinutes,
        public private(set) int $totalMinutes,
        public private(set) array $entries,
        public private(set) int $pendingJiraDeletions,
        public private(set) bool $jiraConfigured,
    ) {
    }
}
