<?php

namespace App\Mcp;

use App\Entity\WorklogEntry;
use App\Service\WorklogSync;

final readonly class EntryView
{
    public function __construct(
        public string $ticket,
        public string $title,
        public int $minutes,
        public string $description,
        public string $jira,
        public ?string $jiraError,
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
