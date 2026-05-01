<?php

declare(strict_types=1);

namespace SpookyApp\Services\API;

use MODX\Revolution\modX;
use SpookyApp\Services\Cache\CacheService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
/**
 * BiathlonIBUService — клиент для IBU (International Biathlon Union) API.
 *
 * ═══════════════════════════════════════════════════════════════
 * IBU публичный API: https://biathlonresults.com/
 *
 * Два раздела функциональности:
 *
 * A) Topic Finder — поиск трендовых биатлонных тем:
 *    - fetchTrendingTopics()
 *
 * B) Chunk Generator — детальная информация для генерации контента:
 *    - getEventSchedule()  — расписание этапов сезона
 *    - getEventResults()   — результаты конкретного этапа/гонки
 *
 * ⚠️ IBU API — неофициальный, форматы ответов могут меняться.
 *    Все endpoint'ы помечены TODO для проверки актуальности.
 *
 * Все методы:
 *    - Используют Guzzle HTTP Client
 *    - Кешируют ответы через MODX cacheManager
 *    - Логируют ошибки через modX::log()
 *    - Возвращают типизированные массивы
 * ═══════════════════════════════════════════════════════════════
 *
 * @package SpookyApp
 * @subpackage Services\API
 */
class BiathlonIBUService extends APIService
{
    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constants                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Базовый URL IBU API.
     *
     * TODO: IBU может менять структуру API между сезонами.
     *       Проверять актуальность перед каждым сезоном.
     *       Альтернативы:
     *       - https://biathlonresults.com/modules/sportapi/api/
     *       - https://www.biathlonworld.com/api/
     */
    private const BASE_URL = 'https://biathlonresults.com/modules/sportapi/api';

    /** @var string Префикс ключей кеша */
    private const CACHE_PREFIX = 'spookyapp/biathlon/';

    // ── Cache TTL (секунды) ──────────────────────────────────

    /** @var int Кеш для Topic Finder (6 часов) */
    private const TTL_TRENDING = 21600;

    /** @var int Кеш для расписания сезона (24 часа) */
    private const TTL_SCHEDULE = 86400;

    /** @var int Кеш для результатов (7 дней — не меняются после публикации) */
    private const TTL_RESULTS = 604800;

    /** @var int Кеш для результатов текущего дня (1 час — могут обновляться) */
    private const TTL_RESULTS_LIVE = 3600;

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Properties                                             ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var bool Включён ли кеш */
    private bool $cacheEnabled;

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constructor                                            ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Конструктор BiathlonIBUService.
     *
     * IBU API не требует авторизации (публичный API).
     *
     * @param modX  $modx    MODX instance
     * @param array $options Дополнительные опции:
     *   - cache_enabled (bool): включить кеш, default true
     *   - timeout (int): таймаут запроса в секундах, default 15
     */
    public function __construct(modX $modx, CacheService $cache)
    {
        parent::__construct($modx, $cache);

        $this->cacheEnabled = true;

        $this->modx->log(modX::LOG_LEVEL_DEBUG, '[BiathlonIBUService] Initialized.');
    }

    private function request(string $method, string $uri, array $params = []): ?array
    {
        $result = $this->httpGet(
            $this->buildUrl(self::BASE_URL . $uri, $params),
            ['Accept: application/json', 'User-Agent: SpookyApp/1.0 (MODX)'],
            15
        );
        if (!$result['success']) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[BiathlonIBUService] HTTP error for ' . $uri . ': ' . ($result['error'] ?? ''));
            return null;
        }
        return is_array($result['data']) ? $result['data'] : null;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A) Topic Finder Methods                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить трендовые биатлонные темы.
     *
     * Используется модулем Topic Finder для агрегации
     * трендов из различных источников.
     *
     * Запрашивает ближайшие события текущего сезона.
     *
     * TODO: IBU API endpoint для текущих событий может отличаться.
     *       Проверить /CupResults или /Events для актуального сезона.
     *
     * @param int $limit Максимум записей
     *
     * @return array<int, array{
     *   id: string,
     *   title: string,
     *   event_type: string,
     *   location: string,
     *   country: string,
     *   start_date: string,
     *   end_date: string,
     *   status: string,
     *   season: string
     * }>
     */
    public function fetchTrendingTopics(int $limit = 20): array
    {
        $seasonId = $this->getCurrentSeasonId();
        $cacheKey = self::CACHE_PREFIX . 'trending/' . $seasonId;

        // ── Проверяем кеш ────────────────────────────────────
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[BiathlonIBUService] fetchTrendingTopics: cache hit ('
                . count($cached) . ' items)'
            );
            return array_slice($cached, 0, $limit);
        }

        // ── Запрос к API ─────────────────────────────────────
        // TODO: Проверить актуальный endpoint IBU.
        //       Возможные варианты:
        //       - /Events?SeasonId={seasonId}
        //       - /CupResults?SeasonId={seasonId}
        $data = $this->request('GET', '/Events', [
            'SeasonId' => $seasonId,
        ]);

        if ($data === null) {
            return [];
        }

        $events = is_array($data) ? $data : [];

        // Фильтруем ближайшие/текущие события
        $now = time();
        $topics = [];

        foreach ($events as $event) {
            $startDate = $event['StartDate'] ?? $event['startDate'] ?? '';
            $endDate = $event['EndDate'] ?? $event['endDate'] ?? '';

            $topics[] = [
                'id'         => (string)($event['EventId']
                    ?? $event['eventId']
                    ?? $event['Id'] ?? ''),
                'title'      => $event['Description']
                    ?? $event['ShortDescription']
                    ?? $event['description'] ?? '',
                'event_type' => $event['EventType']
                    ?? $event['eventType']
                    ?? $event['DisciplineId'] ?? '',
                'location'   => $event['Organizer']
                    ?? $event['Location']
                    ?? $event['organizer'] ?? '',
                'country'    => $event['NatCode']
                    ?? $event['Nat']
                    ?? $event['nat'] ?? '',
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'status'     => $this->determineEventStatus($startDate, $endDate),
                'season'     => $seasonId,
            ];
        }

        // Сортируем: сначала текущие, потом ближайшие
        usort($topics, function (array $a, array $b): int {
            $statusOrder = ['live' => 0, 'upcoming' => 1, 'finished' => 2];
            $aOrder = $statusOrder[$a['status']] ?? 3;
            $bOrder = $statusOrder[$b['status']] ?? 3;
            if ($aOrder !== $bOrder) {
                return $aOrder - $bOrder;
            }
            return strcmp($a['start_date'], $b['start_date']);
        });

        // ── Кешируем ─────────────────────────────────────────
        $this->setCache($cacheKey, $topics, self::TTL_TRENDING);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[BiathlonIBUService] fetchTrendingTopics: fetched '
            . count($topics) . ' events for season ' . $seasonId
        );

        return array_slice($topics, 0, $limit);
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A.2) Topic Finder — aggregateForTopicFinder            ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Агрегировать биатлонные темы для TopicFinder.
     *
     * Вызывает fetchTrendingTopics() и нормализует каждое событие
     * в единый формат TopicFinder.
     *
     * @return array{success: bool, topics: array<int, array>, error: string|null}
     */
    public function aggregateForTopicFinder(): array
    {
        try {
            $rawTopics = $this->fetchTrendingTopics(30);

            if (empty($rawTopics)) {
                $this->modx->log(
                    modX::LOG_LEVEL_DEBUG,
                    '[BiathlonIBUService] aggregateForTopicFinder: нет трендовых тем'
                );
                return ['success' => true, 'topics' => [], 'error' => null];
            }

            $topics = [];
            $seen = [];

            foreach ($rawTopics as $event) {
                $topic = $this->ibuEventToTopic($event);
                $eventId = $topic['metadata']['ibu_event_id'] ?? '';
                if (!empty($eventId) && isset($seen[$eventId])) {
                    continue;
                }
                $seen[$eventId] = true;
                $topics[] = $topic;
            }

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                '[BiathlonIBUService] aggregateForTopicFinder: ' . count($topics) . ' уникальных тем'
            );

            return ['success' => true, 'topics' => $topics, 'error' => null];
        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[BiathlonIBUService] aggregateForTopicFinder error: ' . $e->getMessage()
            );
            return ['success' => false, 'topics' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Нормализовать событие IBU в единый формат TopicFinder.
     *
     * @param array $event Сырые данные события из fetchTrendingTopics()
     *
     * @return array Нормализованная тема TopicFinder
     */
    private function ibuEventToTopic(array $event): array
    {
        $title = trim((string)($event['title'] ?? ''));
        $eventId = (string)($event['id'] ?? '');
        $startDate = (string)($event['start_date'] ?? '');
        $location = (string)($event['location'] ?? '');
        $country = (string)($event['country'] ?? '');

        // Обогащаем заголовок локацией, если title короткий
        if (!empty($location) && mb_strlen($title) < 30) {
            $title = $title . ' — ' . $location
                . (!empty($country) ? ' (' . $country . ')' : '');
        }

        // Нормализуем дату в ISO 8601
        $publishedAt = '';
        if (!empty($startDate)) {
            try {
                $publishedAt = (new \DateTimeImmutable($startDate))->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable) {
                $publishedAt = $startDate;
            }
        }

        $descParts = array_filter([
            !empty($event['status']) ? ('Status: ' . $event['status']) : null,
            !empty($country) ? $country : null,
            !empty($location) ? $location : null,
            !empty($startDate) ? ('From: ' . $startDate) : null,
            !empty($event['end_date']) ? ('To: ' . $event['end_date']) : null,
        ]);
        $description = implode(' | ', $descParts);

        return [
            'id'           => 'biathlon_' . $eventId,
            'source'       => 'biathlon',
            'title'        => $title,
            'url'          => '',
            'description'  => $description,
            'category'     => 'biathlon',
            'published_at' => $publishedAt,
            'score'        => 0.0,
            'metadata'     => [
                'ibu_event_id' => $eventId,
                'event_type'   => (string)($event['event_type'] ?? ''),
                'location'     => $location,
                'country'      => $country,
                'start_date'   => $startDate,
                'end_date'     => (string)($event['end_date'] ?? ''),
                'status'       => (string)($event['status'] ?? ''),
                'season'       => (string)($event['season'] ?? ''),
            ],
        ];
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Event Schedule            ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить расписание соревнований сезона.
     *
     * Возвращает все этапы Кубка мира (или другого турнира)
     * с датами, дисциплинами и локациями.
     *
     * TODO: IBU API endpoint — /Events или /Schedule.
     *       Параметр SeasonId формат: "2425" (для сезона 2024/2025).
     *       Проверить актуальность перед использованием.
     *
     * @param string $seasonId ID сезона (e.g. "2425" для 2024/2025).
     *                         Если пустой — используется текущий сезон.
     *
     * @return array{
     *   season_id: string,
     *   season_name: string,
     *   events: array<int, array{
     *     event_id: string,
     *     description: string,
     *     location: string,
     *     country: string,
     *     country_code: string,
     *     start_date: string,
     *     end_date: string,
     *     status: string,
     *     races: array<int, array{
     *       race_id: string,
     *       discipline: string,
     *       category: string,
     *       date: string,
     *       start_time: string|null,
     *       status: string
     *     }>
     *   }>
     * }
     */
    public function getEventSchedule(string $seasonId = ''): array
    {
        if (empty($seasonId)) {
            $seasonId = $this->getCurrentSeasonId();
        }

        $empty = [
            'season_id'   => $seasonId,
            'season_name' => $this->formatSeasonName($seasonId),
            'events'      => [],
        ];

        $cacheKey = self::CACHE_PREFIX . 'schedule/' . $seasonId;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[BiathlonIBUService] getEventSchedule: cache hit for season=' . $seasonId
            );
            return $cached;
        }

        // ── Запрос к API ─────────────────────────────────────
        // TODO: Проверить endpoint и параметры.
        //       IBU API может использовать:
        //       - /Events?SeasonId={seasonId}
        //       - /Schedule?SeasonId={seasonId}&Level=1 (World Cup)
        $data = $this->request('GET', '/Events', [
            'SeasonId' => $seasonId,
        ]);

        if ($data === null) {
            return $empty;
        }

        $rawEvents = is_array($data) ? $data : [];

        $events = [];

        foreach ($rawEvents as $event) {
            $eventId = (string)($event['EventId']
                ?? $event['eventId']
                ?? $event['Id'] ?? '');
            $startDate = $event['StartDate'] ?? $event['startDate'] ?? '';
            $endDate = $event['EndDate'] ?? $event['endDate'] ?? '';

            // ── Получаем гонки этапа ─────────────────────────
            // TODO: IBU может возвращать races внутри event,
            //       или может потребоваться отдельный запрос:
            //       /Competitions?EventId={eventId}
            $rawRaces = $event['Races']
                ?? $event['races']
                ?? $event['Competitions']
                ?? [];

            $races = [];
            foreach ($rawRaces as $race) {
                $races[] = [
                    'race_id'    => (string)($race['RaceId']
                        ?? $race['CompetitionId']
                        ?? $race['raceId'] ?? ''),
                    'discipline' => $race['DisciplineId']
                        ?? $race['Discipline']
                        ?? $race['discipline'] ?? '',
                    'category'   => $race['CatId']
                        ?? $race['Category']
                        ?? $race['category'] ?? '',
                    'date'       => $race['StartDate']
                        ?? $race['startDate']
                        ?? $race['Date'] ?? '',
                    'start_time' => $race['StartTime']
                        ?? $race['startTime'] ?? null,
                    'status'     => $this->determineEventStatus(
                        $race['StartDate'] ?? $race['Date'] ?? '',
                        $race['StartDate'] ?? $race['Date'] ?? ''
                    ),
                ];
            }

            $events[] = [
                'event_id'     => $eventId,
                'description'  => $event['Description']
                    ?? $event['ShortDescription']
                    ?? $event['description'] ?? '',
                'location'     => $event['Organizer']
                    ?? $event['Location']
                    ?? $event['organizer'] ?? '',
                'country'      => $event['OrganizerCountry']
                    ?? $event['Country'] ?? '',
                'country_code' => $event['NatCode']
                    ?? $event['Nat']
                    ?? $event['nat'] ?? '',
                'start_date'   => $startDate,
                'end_date'     => $endDate,
                'status'       => $this->determineEventStatus($startDate, $endDate),
                'races'        => $races,
            ];
        }

        $result = [
            'season_id'   => $seasonId,
            'season_name' => $this->formatSeasonName($seasonId),
            'events'      => $events,
        ];

        $this->setCache($cacheKey, $result, self::TTL_SCHEDULE);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[BiathlonIBUService] getEventSchedule: season=' . $seasonId
            . ' → ' . count($events) . ' events'
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Event Results             ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить результаты конкретного этапа/гонки.
     *
     * Возвращает финишный протокол с позициями, именами
     * спортсменов, странами, временем и штрафными кругами.
     *
     * TODO: IBU API endpoint — /CUPResults или /Results.
     *       Параметр может быть EventId или RaceId (CompetitionId).
     *       Если передан EventId — возвращает сводку по этапу.
     *       Если передан RaceId — возвращает детальные результаты гонки.
     *       Проверить формат перед использованием.
     *
     * @param string $eventId ID этапа или гонки в IBU
     *
     * @return array{
     *   event_id: string,
     *   event_name: string,
     *   discipline: string,
     *   category: string,
     *   location: string,
     *   date: string,
     *   status: string,
     *   results: array<int, array{
     *     rank: int|null,
     *     bib: int|null,
     *     athlete: array{
     *       id: string,
     *       given_name: string,
     *       family_name: string,
     *       full_name: string,
     *       country: string,
     *       country_code: string
     *     },
     *     result_time: string|null,
     *     behind: string|null,
     *     penalties: int|null,
     *     shooting: array{
     *       total_misses: int|null,
     *       prone_misses: int|null,
     *       standing_misses: int|null,
     *       shooting_details: string|null
     *     },
     *     status: string
     *   }>
     * }|array{}
     */
    public function getEventResults(string $eventId): array
    {
        if (empty(trim($eventId))) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[BiathlonIBUService] getEventResults: empty eventId'
            );
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'results/' . $eventId;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[BiathlonIBUService] getEventResults: cache hit for id=' . $eventId
            );
            return $cached;
        }

        // ── Запрос к API ─────────────────────────────────────
        // TODO: Проверить актуальный endpoint IBU для результатов.
        //       Возможные варианты:
        //       - /CUPResults?RaceId={eventId}
        //       - /Results?CompetitionId={eventId}
        //       - /Results/{eventId}
        $data = $this->request('GET', '/CUPResults', [
            'RaceId' => $eventId,
        ]);

        if ($data === null) {
            // Fallback: пробуем альтернативный endpoint
            $data = $this->request('GET', '/Results', [
                'CompetitionId' => $eventId,
            ]);
        }

        if ($data === null) {
            return [];
        }

        // ── Метаданные этапа ─────────────────────────────────
        $meta = $data['Competition'] ?? $data['Event'] ?? $data;
        $rawResults = $data['Results'] ?? $data['results'] ?? $data['Rows'] ?? [];

        // Определяем TTL: текущий день → короткий, прошедший → длинный
        $eventDate = $meta['StartDate'] ?? $meta['Date'] ?? '';
        $isToday = !empty($eventDate)
            && date('Y-m-d', strtotime($eventDate)) === date('Y-m-d');
        $ttl = $isToday ? self::TTL_RESULTS_LIVE : self::TTL_RESULTS;

        // ── Нормализуем результаты ───────────────────────────
        $results = [];

        foreach ($rawResults as $row) {
            $athlete = $row['IBUId'] ?? $row['Athlete'] ?? $row;

            $results[] = [
                'rank'        => isset($row['Rank']) ? (int)$row['Rank']
                    : (isset($row['rank']) ? (int)$row['rank'] : null),
                'bib'         => isset($row['Bib']) ? (int)$row['Bib']
                    : (isset($row['bib']) ? (int)$row['bib'] : null),
                'athlete'     => [
                    'id'           => (string)($athlete['IBUId']
                        ?? $athlete['id']
                        ?? $row['IBUId'] ?? ''),
                    'given_name'   => $athlete['GivenName']
                        ?? $athlete['givenName']
                        ?? $row['GivenName'] ?? '',
                    'family_name'  => $athlete['FamilyName']
                        ?? $athlete['familyName']
                        ?? $row['FamilyName'] ?? '',
                    'full_name'    => trim(
                        ($row['GivenName'] ?? $athlete['GivenName'] ?? '')
                        . ' '
                        . ($row['FamilyName'] ?? $athlete['FamilyName'] ?? '')
                    ),
                    'country'      => $row['NatLong']
                        ?? $row['Country'] ?? '',
                    'country_code' => $row['Nat']
                        ?? $row['NatCode']
                        ?? $athlete['Nat'] ?? '',
                ],
                'result_time' => $row['TotalTime']
                    ?? $row['ResultTime']
                    ?? $row['totalTime'] ?? null,
                'behind'      => $row['Behind']
                    ?? $row['Diff']
                    ?? $row['behind'] ?? null,
                'penalties'   => isset($row['Penalties'])
                    ? (int)$row['Penalties']
                    : (isset($row['penalties']) ? (int)$row['penalties'] : null),
                'shooting'    => [
                    'total_misses'    => isset($row['ShootingTotal'])
                        ? (int)$row['ShootingTotal'] : null,
                    'prone_misses'    => isset($row['Prone'])
                        ? (int)$row['Prone'] : null,
                    'standing_misses' => isset($row['Standing'])
                        ? (int)$row['Standing'] : null,
                    'shooting_details' => $row['Shooting']
                        ?? $row['ShootingDetails'] ?? null,
                ],
                'status'      => $row['IRM'] ?? $row['Status'] ?? 'OK',
            ];
        }

        $result = [
            'event_id'   => $eventId,
            'event_name' => $meta['Description']
                ?? $meta['ShortDescription']
                ?? $meta['description'] ?? '',
            'discipline' => $meta['DisciplineId']
                ?? $meta['Discipline']
                ?? $meta['discipline'] ?? '',
            'category'   => $meta['CatId']
                ?? $meta['Category']
                ?? $meta['category'] ?? '',
            'location'   => $meta['Organizer']
                ?? $meta['Location']
                ?? $meta['organizer'] ?? '',
            'date'       => $eventDate,
            'status'     => $this->determineEventStatus($eventDate, $eventDate),
            'results'    => $results,
        ];

        $this->setCache($cacheKey, $result, $ttl);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[BiathlonIBUService] getEventResults: id=' . $eventId
            . ' → "' . $result['event_name'] . '"'
            . ' (' . count($results) . ' athletes, TTL: '
            . ($isToday ? 'LIVE' : 'ARCHIVE') . ')'
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Season Helpers                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить ID текущего сезона IBU.
     *
     * Формат: "2425" для сезона 2024/2025.
     * Сезон начинается в ноябре, заканчивается в марте.
     *
     * @return string Season ID
     */
    private function getCurrentSeasonId(): string
    {
        $month = (int)date('n');
        $year = (int)date('Y');

        // Если ноябрь-декабрь → текущий/следующий год
        // Если январь-октябрь → предыдущий/текущий год
        if ($month >= 11) {
            $startYear = $year;
        } else {
            $startYear = $year - 1;
        }

        $endYear = $startYear + 1;

        return substr((string)$startYear, -2) . substr((string)$endYear, -2);
    }

    /**
     * Форматировать ID сезона в читаемое название.
     *
     * "2425" → "2024/2025"
     *
     * @param string $seasonId ID сезона
     *
     * @return string Читаемое название сезона
     */
    private function formatSeasonName(string $seasonId): string
    {
        if (strlen($seasonId) !== 4) {
            return $seasonId;
        }

        $startShort = substr($seasonId, 0, 2);
        $endShort = substr($seasonId, 2, 2);

        $century = ((int)$startShort > 50) ? '19' : '20';

        return $century . $startShort . '/' . $century . $endShort;
    }

    /**
     * Определить статус события по датам.
     *
     * @param string $startDate Дата начала
     * @param string $endDate   Дата окончания
     *
     * @return string 'upcoming' | 'live' | 'finished' | 'unknown'
     */
    private function determineEventStatus(string $startDate, string $endDate): string
    {
        if (empty($startDate)) {
            return 'unknown';
        }

        $now = time();
        $start = strtotime($startDate);
        $end = !empty($endDate) ? strtotime($endDate) : $start;

        if ($start === false) {
            return 'unknown';
        }

        // Добавляем весь конечный день (до 23:59:59)
        $endOfDay = $end + 86399;

        if ($now < $start) {
            return 'upcoming';
        }

        if ($now <= $endOfDay) {
            return 'live';
        }

        return 'finished';
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Cache Helpers                                 ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить данные из кеша.
     *
     * @param string $key Ключ кеша
     *
     * @return array|null Данные или null если нет / кеш выключен
     */
    private function getCache(string $key): ?array
    {
        if (!$this->cacheEnabled) {
            return null;
        }

        $data = $this->cache->get($key);

        return is_array($data) ? $data : null;
    }

    /**
     * Сохранить данные в кеш.
     *
     * @param string $key  Ключ кеша
     * @param array  $data Данные для кеширования
     * @param int    $ttl  Время жизни в секундах
     *
     * @return void
     */
    private function setCache(string $key, array $data, int $ttl): void
    {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->cache->set($key, $data, $ttl);
    }
}