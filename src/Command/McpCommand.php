<?php

namespace App\Command;

use App\Domain\Template;
use App\Entity\WorkDay;
use App\Entity\WorklogEntry;
use App\Repository\WorkDayRepository;
use App\Service\DayDefaults;
use App\Service\DayEntriesWriter;
use App\Service\ReportBuilder;
use App\Service\Settings;
use App\Service\WorkCalendar;
use App\Service\WorklogSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

#[AsCommand(name: 'app:mcp', description: 'Serwer MCP (stdio): Claude czyta i zapisuje wpisy dnia')]
final class McpCommand extends Command
{
    private const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    private const INSTRUCTIONS = 'Raport czasu pracy: każdy dzień to lista wpisów (ticket Jira, czas w minutach, opis).';

    private const DATE_SCHEMA = [
        'type' => 'string',
        'pattern' => '^\d{4}-\d{2}-\d{2}$',
        'description' => 'Dzień w formacie RRRR-MM-DD, np. 2026-11-17.',
    ];

    private const TOOLS = [
        [
            'name' => 'get_day',
            'title' => 'Odczytaj dzień',
            'description' => 'Odczytuje dzień z raportu czasu pracy: wpisy (ticket, tytuł, minuty, opis, stan w Jirze), sumę i normę w minutach oraz czy to dzień wolny. '
                .'Stan w Jirze: new – jeszcze niewysłany, synced – w Jirze, changed – zmieniony po wysłaniu, error – błąd Jiry (treść w jira_error), empty – 0 minut. '
                .'Wywołaj przed set_day, żeby zobaczyć, co już jest (np. wpisy dodane ręcznie przez użytkownika).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['date' => self::DATE_SCHEMA],
                'required' => ['date'],
                'additionalProperties' => false,
            ],
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        ],
        [
            'name' => 'set_day',
            'title' => 'Zapisz wpisy dnia',
            'description' => 'Zastępuje wszystkie wpisy dnia podaną listą: dzień ma potem dokładnie te wiersze, w tej kolejności. '
                .'Istniejący wiersz z tym samym ticketem jest aktualizowany i zostaje powiązany ze swoim worklogiem w Jirze. '
                .'Wiersze spoza listy są usuwane; te już wysłane do Jiry znikną z niej dopiero przy następnej wysyłce z aplikacji. '
                .'Godzina „od” zapisanych wierszy jest czyszczona (worklogi dostaną kolejne godziny dnia). '
                .'Nic nie jest wysyłane do Jiry ani na Slacka – użytkownik sprawdza dzień w aplikacji i sam klika „Zapisz i wyślij do Jiry”. '
                .'Zwraca dzień po zapisie, tak jak get_day.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'date' => self::DATE_SCHEMA,
                    'entries' => [
                        'type' => 'array',
                        'description' => 'Wszystkie wpisy dnia. Pusta lista usuwa wpisy dnia.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'ticket' => ['type' => 'string', 'description' => 'Numer ticketu Jira, np. BHPS-521. Może być z tytułem: „BHPS-521 Aktualizacja zmian”.'],
                                'minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => DayEntriesWriter::MAX_MINUTES, 'description' => 'Czas w minutach.'],
                                'description' => ['type' => 'string', 'description' => 'Co zostało zrobione – trafia do worklogu w Jirze i do podsumowania na Slacka.'],
                                'title' => ['type' => 'string', 'description' => 'Tytuł ticketu. Pominięty: zostaje dotychczasowy albo znany z wcześniejszych dni.'],
                            ],
                            'required' => ['ticket', 'minutes'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['date', 'entries'],
                'additionalProperties' => false,
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkDayRepository $days,
        private readonly DayEntriesWriter $writer,
        private readonly DayDefaults $defaults,
        private readonly WorklogSync $sync,
        private readonly ReportBuilder $reports,
        private readonly WorkCalendar $calendar,
        private readonly Settings $settings,
        #[Autowire(service: 'services_resetter')]
        private readonly ResetInterface $resetter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        while (false !== $line = fgets(STDIN)) {
            if ('' === trim($line)) {
                continue;
            }
            $response = $this->handle($line);
            if (null !== $response) {
                $output->writeln(json_encode($response, self::JSON_FLAGS), OutputInterface::OUTPUT_RAW);
            }
        }

        return Command::SUCCESS;
    }

    private function handle(string $line): ?array
    {
        $message = json_decode($line, true);
        if (!is_array($message)) {
            return self::error(null, -32700, 'Parse error');
        }
        if (array_is_list($message)) {
            return self::error(null, -32600, 'Batch requests are not supported');
        }
        if (!is_string($message['method'] ?? null) || !array_key_exists('id', $message)) {
            return null;
        }

        $id = $message['id'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($message['method']) {
            'initialize' => self::result($id, [
                'protocolVersion' => in_array($params['protocolVersion'] ?? null, self::PROTOCOL_VERSIONS, true) ? $params['protocolVersion'] : self::PROTOCOL_VERSIONS[0],
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'worklog', 'title' => 'Raport czasu pracy', 'version' => getenv('WORKLOG_VERSION') ?: 'dev'],
                'instructions' => self::INSTRUCTIONS,
            ]),
            'ping' => self::result($id, new \stdClass()),
            'tools/list' => self::result($id, ['tools' => self::TOOLS]),
            'tools/call' => $this->callTool($id, $params),
            default => self::error($id, -32601, 'Method not found: '.$message['method']),
        };
    }

    private function callTool(mixed $id, array $params): array
    {
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $tool = match ($params['name'] ?? null) {
            'get_day' => fn (): array => $this->describe(self::date($arguments)),
            'set_day' => fn (): array => $this->setDay(self::date($arguments), $arguments['entries'] ?? null),
            default => null,
        };
        if (null === $tool) {
            return self::error($id, -32602, 'Unknown tool: '.json_encode($params['name'] ?? null, self::JSON_FLAGS));
        }

        $this->resetter->reset();
        try {
            $data = $tool();
        } catch (\Throwable $e) {
            return self::result($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
        }

        return self::result($id, ['content' => [['type' => 'text', 'text' => json_encode($data, self::JSON_FLAGS)]]]);
    }

    private function setDay(\DateTimeImmutable $date, mixed $entries): array
    {
        $rows = DayEntriesWriter::parse($entries);
        $day = $this->days->findByDate($date);
        if (null !== $day || [] !== $rows) {
            $day ??= $this->defaults->newDay($date);
            $this->em->persist($day);
            $this->writer->replace($day, $rows);
            $this->em->flush();
            $this->em->clear();
        }

        return $this->describe($date);
    }

    private function describe(\DateTimeImmutable $date): array
    {
        $day = $this->days->findByDate($date) ?? new WorkDay($date);
        $statuses = $this->sync->statuses($day);
        $summary = $this->reports->summary($day);

        return [
            'date' => $date->format('Y-m-d'),
            'weekday' => Template::dayName($date),
            'day_off' => $this->calendar->dayOff($date)['label'] ?? null,
            'hours' => null !== $day->getStartTime() ? $day->getStartTime().'–'.$day->getEndTime() : null,
            'norm_minutes' => $summary->normMinutes,
            'total_minutes' => $summary->loggedMinutes,
            'entries' => array_map(static fn (WorklogEntry $entry): array => [
                'ticket' => $entry->getIssueKey(),
                'title' => $entry->getIssueTitle(),
                'minutes' => $entry->getMinutes(),
                'description' => $entry->getDescription(),
                'jira' => $statuses[(int) $entry->getId()],
            ] + (WorklogSync::STATUS_ERROR === $statuses[(int) $entry->getId()] ? ['jira_error' => $entry->getJiraError()] : []), $day->getActiveEntries()),
            'pending_jira_deletions' => count($day->getPendingDeletions()),
            'jira_configured' => $this->settings->jiraConfigured(),
        ];
    }

    private static function date(array $arguments): \DateTimeImmutable
    {
        $raw = $arguments['date'] ?? null;
        $date = is_string($raw) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw) : false;
        if (false === $date || $date->format('Y-m-d') !== $raw) {
            throw new \InvalidArgumentException('date: podaj dzień w formacie RRRR-MM-DD, np. 2026-11-17.');
        }

        return $date;
    }

    private static function result(mixed $id, array|\stdClass $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
