<?php

namespace App\Mcp;

use App\Entity\WorklogEntry;
use App\Service\WorklogSync;

final readonly class EntryView
{
    public function __construct(
        public private(set) string $ticket,
        public private(set) string $title,
        public private(set) int $minutes,
        public private(set) string $description,
        public private(set) string $jira,
        public private(set) ?string $jiraError,
    ) {
    }

    public static function of(WorklogEntry $entry, string $jiraStatus): self
    {
        return new self(
            ticket: $entry->getIssueKey(),
            title: $entry->getIssueTitle(),
            minutes: $entry->getMinutes(),
            description: $entry->getDescription(),
            jira: $jiraStatus,
            jiraError: WorklogSync::STATUS_ERROR === $jiraStatus ? $entry->getJiraError() : null,
        );
    }
}
