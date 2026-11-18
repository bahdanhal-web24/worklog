<?php

namespace App\Service;

use App\Domain\Template;
use App\Entity\WorkDay;
use App\Entity\WorklogEntry;
use App\Repository\WorkDayRepository;

final class DayEntriesWriter
{
    public const MAX_MINUTES = 24 * 60;

    public function __construct(
        private readonly WorkDayRepository $days,
        private readonly Settings $settings,
    ) {
    }

    public static function parse(mixed $entries): array
    {
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new \InvalidArgumentException('entries: podaj listę wpisów (może być pusta).');
        }

        $rows = [];
        $errors = [];
        foreach ($entries as $i => $raw) {
            $n = $i + 1;
            if (!is_array($raw)) {
                $errors[] = sprintf('Wpis %d: oczekiwano obiektu z polami ticket, minutes, description.', $n);
                continue;
            }
            $errorsBefore = count($errors);
            $ticket = is_string($raw['ticket'] ?? null) ? trim($raw['ticket']) : '';
            $key = Template::extractIssueKey($ticket);
            $minutes = filter_var($raw['minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_MINUTES]]);
            $description = $raw['description'] ?? '';
            $title = $raw['title'] ?? Template::issueTitleAfterKey($ticket);

            if (null === $key) {
                $errors[] = sprintf('Wpis %d: niepoprawny numer ticketu „%s” (np. ABC-123).', $n, $ticket);
            }
            if (false === $minutes) {
                $errors[] = sprintf('Wpis %d: minutes musi być liczbą całkowitą od 1 do %d.', $n, self::MAX_MINUTES);
            }
            if (!is_string($description)) {
                $errors[] = sprintf('Wpis %d: description musi być tekstem.', $n);
            }
            if (!is_string($title)) {
                $errors[] = sprintf('Wpis %d: title musi być tekstem.', $n);
            }
            if (count($errors) > $errorsBefore) {
                continue;
            }

            $rows[] = ['key' => $key, 'minutes' => $minutes, 'description' => trim($description), 'title' => trim($title)];
        }

        if ([] !== $errors) {
            throw new \InvalidArgumentException(implode("\n", $errors));
        }

        return $rows;
    }

    public function replace(WorkDay $day, array $rows): void
    {
        $free = $day->getActiveEntries();
        $timed = array_find($free, static fn (WorklogEntry $e): bool => $e->isTimerRunning());
        if (null !== $timed) {
            throw new \InvalidArgumentException(sprintf('Przy tickecie %s działa stoper – zatrzymaj go w aplikacji i spróbuj ponownie.', $timed->getIssueKey()));
        }

        $knownTitles = null;
        foreach ($rows as $position => $row) {
            $sameTicket = static fn (WorklogEntry $e): bool => $e->getIssueKey() === $row['key'];
            $i = array_find_key($free, static fn (WorklogEntry $e): bool => $sameTicket($e) && $e->getDescription() === $row['description'])
                ?? array_find_key($free, $sameTicket);

            if (null === $i) {
                $entry = new WorklogEntry($day, $row['key'], $row['minutes'], $row['description'], $position);
            } else {
                $entry = $free[$i];
                unset($free[$i]);
                $entry->update($row['key'], $row['minutes'], $row['description'], $position);
            }
            $entry->setStartClock(null);

            $title = $row['title'] ?: $entry->getIssueTitle();
            if ('' === $title) {
                $knownTitles ??= $this->days->knownIssueTitles() + $this->settings->issueCache();
                $title = $knownTitles[$row['key']] ?? '';
            }
            $entry->setIssueTitle($title);
        }

        foreach ($free as $entry) {
            $entry->isInJira() ? $entry->markDeleted() : $day->removeEntry($entry);
        }
    }
}
