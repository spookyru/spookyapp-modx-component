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
 * SportsAPIService — клиент для FlashLive Sports API (RapidAPI).
 *
 * ═══════════════════════════════════════════════════════════════
 * Два раздела функциональности:
 *
 * A) Topic Finder — поиск трендовых спортивных тем:
 *    - fetchTrendingTopics()
 *
 * B) Chunk Generator — детальная информация для генерации контента:
 *    - getMatchDetails()        — детали конкретного матча
 *    - getTournamentCalendar()  — календарь матчей турнира
 *
 * Все методы:
 *    - Используют Guzzle HTTP Client с RapidAPI авторизацией
 *    - Кешируют ответы через MODX cacheManager
 *    - Логируют ошибки через modX::log()
 *    - Возвращают типизированные массивы
 * ═══════════════════════════════════════════════════════════════
 *
 * @package SpookyApp
 * @subpackage Services\API
 */
class SportsAPIService extends APIService
{
    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constants                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var string Базовый URL FlashLive Sports API */
    private const BASE_URL = 'https://flashlive-sports.p.rapidapi.com';

    /** @var string RapidAPI host header */
    private const RAPIDAPI_HOST = 'flashlive-sports.p.rapidapi.com';

    /** @var string Префикс ключей кеша */
    private const CACHE_PREFIX = 'spookyapp/sports/';

    /** @var string Системная настройка MODX: RapidAPI key для FlashLive Sports */
    private const SETTING_API_KEY = 'spookyapp.flashlive_api_key';

    // ── Cache TTL (секунды) ──────────────────────────────────

    /** @var int Кеш для Topic Finder (3 часа — live-события) */
    private const TTL_TRENDING = 10800;

    /** @var int Кеш для деталей матча (1 час — счёт может измениться) */
    private const TTL_MATCH = 3600;

    /** @var int Кеш для календаря турнира (6 часов) */
    private const TTL_CALENDAR = 21600;

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Properties                                             ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var string RapidAPI key */
    private string $apiKey;

    /** @var bool Включён ли кеш */
    private bool $cacheEnabled;

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constructor                                            ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Конструктор SportsAPIService.
     *
     * Инициализирует Guzzle Client с RapidAPI авторизацией.
     *
     * @param modX   $modx    MODX instance
     * @param string $apiKey  RapidAPI key для FlashLive Sports
     * @param array  $options Дополнительные опции:
     *   - cache_enabled (bool): включить кеш, default true
     *   - timeout (int): таймаут запроса в секундах, default 15
     */
    public function __construct(modX $modx, CacheService $cache)
    {
        parent::__construct($modx, $cache);

        $this->apiKey       = (string)$this->modx->getOption(self::SETTING_API_KEY, null, '');
        if (empty($this->apiKey)) {
            $this->apiKey = (string)$this->modx->getOption('spookyapp.rapidapi_key', null, '');
        }
        $this->cacheEnabled = true;

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[SportsAPIService] Initialized. Key: ' . (empty($this->apiKey) ? 'MISSING' : 'OK')
        );
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A) Topic Finder Methods                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить трендовые спортивные темы.
     *
     * Используется модулем Topic Finder для агрегации
     * трендов из различных источников.
     *
     * FlashLive endpoint: GET /v1/events/list
     *
     * @param string $sportId Вид спорта (1=football, 2=tennis, 4=hockey, etc.)
     * @param string $locale  Локаль результатов
     * @param int    $limit   Максимум записей
     *
     * @return array<int, array{
     *   id: string,
     *   title: string,
     *   sport: string,
     *   tournament: string,
     *   home_team: string,
     *   away_team: string,
     *   score: string,
     *   status: string,
     *   start_time: string,
     *   url: string
     * }>
     */
    public function fetchTrendingTopics(
        string $sportId = '1',
        string $locale = 'ru_INT',
        int $limit = 20,
        int $indentDays = -1,
        int $timezone = 0
    ): array {
        $cacheKey = self::CACHE_PREFIX . 'trending/' . $sportId . '/' . $locale . '/d' . $indentDays . '/tz' . $timezone;

        // ── Проверяем кеш ────────────────────────────────────
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[SportsAPIService] fetchTrendingTopics: cache hit ('
                . count($cached) . ' items)'
            );
            return array_slice($cached, 0, $limit);
        }

        // ── Запрос к API ─────────────────────────────────────
        $data = $this->request('GET', '/v1/events/list', [
            'sport_id'    => $sportId,
            'locale'      => $locale,
            'indent_days' => (string)$indentDays,
            'timezone'    => (string)$timezone,
        ]);

        if ($data === null) {
            return [];
        }

        $events = $data['DATA'] ?? [];

        $topics = [];
        foreach ($events as $event) {
            $eventsList = $event['EVENTS'] ?? [];
            $tournamentName = $event['NAME'] ?? '';

            foreach ($eventsList as $item) {
                $topics[] = [
                    'id'         => (string)($item['EVENT_ID'] ?? ''),
                    'title'      => ($item['HOME_NAME'] ?? '') . ' vs '
                        . ($item['AWAY_NAME'] ?? ''),
                    'sport'      => $item['SPORT_NAME'] ?? '',
                    'tournament' => $tournamentName,
                    'home_team'  => $item['HOME_NAME'] ?? '',
                    'away_team'  => $item['AWAY_NAME'] ?? '',
                    'score'      => ($item['HOME_SCORE_CURRENT'] ?? '-')
                        . ':' . ($item['AWAY_SCORE_CURRENT'] ?? '-'),
                    'status'     => $item['STAGE'] ?? $item['STAGE_TYPE'] ?? '',
                    'start_time' => $item['START_TIME'] ?? '',
                    'url'        => '',
                ];
            }
        }

        // ── Кешируем ─────────────────────────────────────────
        $this->setCache($cacheKey, $topics, self::TTL_TRENDING);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[SportsAPIService] fetchTrendingTopics: fetched '
            . count($topics) . ' events'
        );

        return array_slice($topics, 0, $limit);
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A.2) Topic Finder — aggregateForTopicFinder            ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Агрегировать спортивные темы для TopicFinder.
     *
     * Вызывает fetchTrendingTopics() и нормализует каждое событие
     * в единый формат TopicFinder.
     *
     * @return array{success: bool, topics: array<int, array>, error: string|null}
     */
    public function aggregateForTopicFinder(array $opts = []): array
    {
        try {
            $sportId    = (string)($opts['sport_id'] ?? '1');
            $indentDays = (int)($opts['indent_days'] ?? -1);
            $timezone   = (int)($opts['timezone'] ?? 0);

            $rawTopics = $this->fetchTrendingTopics($sportId, 'ru_INT', 30, $indentDays, $timezone);

            if (empty($rawTopics)) {
                $this->modx->log(
                    modX::LOG_LEVEL_DEBUG,
                    '[SportsAPIService] aggregateForTopicFinder: нет трендовых тем'
                );
                return ['success' => true, 'topics' => [], 'error' => null];
            }

            $topics = [];
            $seen = [];

            foreach ($rawTopics as $event) {
                $topic = $this->eventToTopic($event);
                $eventId = $topic['metadata']['event_id'] ?? '';
                if (!empty($eventId) && isset($seen[$eventId])) {
                    continue;
                }
                $seen[$eventId] = true;
                $topics[] = $topic;
            }

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                '[SportsAPIService] aggregateForTopicFinder: ' . count($topics) . ' уникальных тем'
            );

            return ['success' => true, 'topics' => $topics, 'error' => null];
        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[SportsAPIService] aggregateForTopicFinder error: ' . $e->getMessage()
            );
            return ['success' => false, 'topics' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Нормализовать событие FlashLive в единый формат TopicFinder.
     *
     * @param array $event Сырые данные события из fetchTrendingTopics()
     *
     * @return array Нормализованная тема TopicFinder
     */
    private function eventToTopic(array $event): array
    {
        $title = trim((string)($event['title'] ?? ''));
        $eventId = (string)($event['id'] ?? '');
        $startTime = (string)($event['start_time'] ?? '');

        // Нормализуем дату в ISO 8601
        $publishedAt = '';
        if (!empty($startTime)) {
            try {
                $publishedAt = (new \DateTimeImmutable($startTime))->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable) {
                $publishedAt = $startTime;
            }
        }

        $descParts = array_filter([
            (string)($event['tournament'] ?? ''),
            (string)($event['sport'] ?? ''),
            (string)($event['status'] ?? ''),
        ]);
        $description = implode(' | ', $descParts);

        return [
            'id'           => 'sports_' . $eventId,
            'source'       => 'sports',
            'title'        => $title,
            'url'          => (string)($event['url'] ?? ''),
            'description'  => $description,
            'category'     => 'sports',
            'published_at' => $publishedAt,
            'score'        => 0.0,
            'metadata'     => [
                'event_id'   => $eventId,
                'sport'      => (string)($event['sport'] ?? ''),
                'tournament' => (string)($event['tournament'] ?? ''),
                'home_team'  => (string)($event['home_team'] ?? ''),
                'away_team'  => (string)($event['away_team'] ?? ''),
                'match_score'=> (string)($event['score'] ?? ''),
                'status'     => (string)($event['status'] ?? ''),
            ],
        ];
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Match Details              ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить детальную информацию о матче.
     *
     * FlashLive endpoint: GET /v1/events/data
     *
     * Возвращает полную информацию: команды, счёт по таймам,
     * дата/время, стадион, судья, статус матча.
     *
     * @param string $matchId ID матча (EVENT_ID) в FlashLive
     * @param string $locale  Локаль результатов
     *
     * @return array{
     *   event_id: string,
     *   home_team: array{id: string, name: string, logo: string|null},
     *   away_team: array{id: string, name: string, logo: string|null},
     *   score: array{
     *     home: int|null,
     *     away: int|null,
     *     home_period_1: int|null,
     *     away_period_1: int|null,
     *     home_period_2: int|null,
     *     away_period_2: int|null
     *   },
     *   tournament: array{id: string, name: string, season: string|null},
     *   start_time: string,
     *   start_timestamp: int|null,
     *   status: string,
     *   status_type: string,
     *   venue: string|null,
     *   referee: string|null,
     *   round: string|null,
     *   sport: string
     * }|array{}
     */
    public function getMatchDetails(string $matchId, string $locale = 'ru_INT'): array
    {
        if (empty(trim($matchId))) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[SportsAPIService] getMatchDetails: empty matchId'
            );
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'match/' . $matchId . '/' . $locale;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[SportsAPIService] getMatchDetails: cache hit for id=' . $matchId
            );
            return $cached;
        }

        // ── Запрос к API ─────────────────────────────────────
        $data = $this->request('GET', '/v1/events/data', [
            'event_id' => $matchId,
            'locale'   => $locale,
        ]);

        if ($data === null) {
            return [];
        }

        $event = $data['DATA'] ?? $data;

        // ── Нормализация ─────────────────────────────────────
        $result = [
            'event_id'        => (string)($event['EVENT_ID'] ?? $matchId),
            'home_team'       => [
                'id'   => (string)($event['HOME_PARTICIPANT_IDS'][0] ?? $event['HOME_ID'] ?? ''),
                'name' => $event['HOME_NAME'] ?? '',
                'logo' => $event['HOME_IMAGES'][0] ?? $event['HOME_IMAGE'] ?? null,
            ],
            'away_team'       => [
                'id'   => (string)($event['AWAY_PARTICIPANT_IDS'][0] ?? $event['AWAY_ID'] ?? ''),
                'name' => $event['AWAY_NAME'] ?? '',
                'logo' => $event['AWAY_IMAGES'][0] ?? $event['AWAY_IMAGE'] ?? null,
            ],
            'score'           => [
                'home'           => isset($event['HOME_SCORE_CURRENT'])
                    ? (int)$event['HOME_SCORE_CURRENT'] : null,
                'away'           => isset($event['AWAY_SCORE_CURRENT'])
                    ? (int)$event['AWAY_SCORE_CURRENT'] : null,
                'home_period_1'  => isset($event['HOME_SCORE_PART_1'])
                    ? (int)$event['HOME_SCORE_PART_1'] : null,
                'away_period_1'  => isset($event['AWAY_SCORE_PART_1'])
                    ? (int)$event['AWAY_SCORE_PART_1'] : null,
                'home_period_2'  => isset($event['HOME_SCORE_PART_2'])
                    ? (int)$event['HOME_SCORE_PART_2'] : null,
                'away_period_2'  => isset($event['AWAY_SCORE_PART_2'])
                    ? (int)$event['AWAY_SCORE_PART_2'] : null,
            ],
            'tournament'      => [
                'id'     => (string)($event['TOURNAMENT_ID'] ?? ''),
                'name'   => $event['TOURNAMENT_NAME']
                    ?? $event['CATEGORY_NAME'] ?? '',
                'season' => $event['SEASON'] ?? null,
            ],
            'start_time'      => $event['START_TIME'] ?? '',
            'start_timestamp' => isset($event['START_UTIME'])
                ? (int)$event['START_UTIME'] : null,
            'status'          => $event['STAGE'] ?? '',
            'status_type'     => $event['STAGE_TYPE'] ?? '',
            'venue'           => $event['VENUE'] ?? $event['STADIUM'] ?? null,
            'referee'         => $event['REFEREE'] ?? null,
            'round'           => $event['ROUND'] ?? null,
            'sport'           => $event['SPORT_NAME'] ?? '',
        ];

        $this->setCache($cacheKey, $result, self::TTL_MATCH);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[SportsAPIService] getMatchDetails: id=' . $matchId
            . ' → "' . $result['home_team']['name']
            . ' vs ' . $result['away_team']['name'] . '"'
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Tournament Calendar       ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить календарь матчей турнира.
     *
     * FlashLive endpoint: GET /v1/tournaments/fixtures
     *
     * Возвращает список предстоящих и прошедших матчей
     * конкретного турнира.
     *
     * @param string $tournamentId ID турнира в FlashLive
     * @param string $locale       Локаль результатов
     *
     * @return array{
     *   tournament_id: string,
     *   tournament_name: string,
     *   season: string|null,
     *   matches: array<int, array{
     *     event_id: string,
     *     home_team: string,
     *     away_team: string,
     *     home_score: int|null,
     *     away_score: int|null,
     *     start_time: string,
     *     start_timestamp: int|null,
     *     status: string,
     *     round: string|null
     *   }>
     * }
     */
    public function getTournamentCalendar(
        string $tournamentId,
        string $locale = 'ru_INT'
    ): array {
        $empty = [
            'tournament_id'   => $tournamentId,
            'tournament_name' => '',
            'season'          => null,
            'matches'         => [],
        ];

        if (empty(trim($tournamentId))) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[SportsAPIService] getTournamentCalendar: empty tournamentId'
            );
            return $empty;
        }

        $cacheKey = self::CACHE_PREFIX . 'tournament/' . $tournamentId . '/calendar/' . $locale;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[SportsAPIService] getTournamentCalendar: cache hit for id=' . $tournamentId
            );
            return $cached;
        }

        // ── Запрос к API ─────────────────────────────────────
        // TODO: Проверить endpoint. FlashLive API может использовать
        //       /v1/tournaments/fixtures или /v1/tournaments/events
        //       в зависимости от версии подписки RapidAPI.
        $data = $this->request('GET', '/v1/tournaments/fixtures', [
            'tournament_id' => $tournamentId,
            'locale'        => $locale,
        ]);

        if ($data === null) {
            return $empty;
        }

        $events = $data['DATA'] ?? [];
        $tournamentName = '';
        $season = null;

        $matches = [];
        foreach ($events as $group) {
            $groupEvents = $group['EVENTS'] ?? [$group];
            if (empty($tournamentName)) {
                $tournamentName = $group['NAME']
                    ?? $group['TOURNAMENT_NAME'] ?? '';
            }
            if ($season === null && isset($group['SEASON'])) {
                $season = $group['SEASON'];
            }

            foreach ($groupEvents as $item) {
                $matches[] = [
                    'event_id'        => (string)($item['EVENT_ID'] ?? ''),
                    'home_team'       => $item['HOME_NAME'] ?? '',
                    'away_team'       => $item['AWAY_NAME'] ?? '',
                    'home_score'      => isset($item['HOME_SCORE_CURRENT'])
                        ? (int)$item['HOME_SCORE_CURRENT'] : null,
                    'away_score'      => isset($item['AWAY_SCORE_CURRENT'])
                        ? (int)$item['AWAY_SCORE_CURRENT'] : null,
                    'start_time'      => $item['START_TIME'] ?? '',
                    'start_timestamp' => isset($item['START_UTIME'])
                        ? (int)$item['START_UTIME'] : null,
                    'status'          => $item['STAGE'] ?? '',
                    'round'           => $item['ROUND'] ?? null,
                ];
            }
        }

        $result = [
            'tournament_id'   => $tournamentId,
            'tournament_name' => $tournamentName,
            'season'          => $season,
            'matches'         => $matches,
        ];

        $this->setCache($cacheKey, $result, self::TTL_CALENDAR);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[SportsAPIService] getTournamentCalendar: id=' . $tournamentId
            . ' → ' . count($matches) . ' matches'
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  C) Search                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Поиск участников, команд, турниров через FlashLive Multi-Search.
     *
     * Endpoint: GET /v1/search/multi-search
     *
     * @param string $query  Поисковый запрос
     * @param string $locale Локаль (e.g. 'ru_RU', 'en_US'), default 'ru_RU'
     *
     * @return array<int, array> Массив найденных элементов
     */
    public function searchParticipants(string $query, string $locale = 'ru_RU'): array
    {
        if (empty(trim($query))) {
            return [];
        }

        $data = $this->request('GET', '/v1/search/multi-search', [
            'query'  => $query,
            'locale' => $locale,
        ]);

        if ($data === null) {
            return [];
        }

        // Response may be a direct array or wrapped in DATA key
        if (isset($data['DATA']) && is_array($data['DATA'])) {
            $items = $data['DATA'];
        } elseif (array_is_list($data)) {
            $items = $data;
        } else {
            $items = [];
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[SportsAPIService] searchParticipants: "' . $query . '" locale=' . $locale
            . ' → ' . count($items) . ' results'
        );

        return array_values($items);
    }

    /**
     * Получить детальную информацию об участнике.
     *
     * Endpoint: GET /v1/participants/summary
     *
     * @param string $participantId Participant ID (e.g. "SULbStSO")
     * @param string $locale        Локаль, default 'ru_RU'
     *
     * @return array
     */
    public function getParticipantDetails(string $participantId, string $locale = 'ru_RU'): array
    {
        if (empty(trim($participantId))) {
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'participant/' . md5($participantId . '|' . $locale);
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[SportsAPIService] getParticipantDetails: cache hit id=' . $participantId
            );
            return $cached;
        }

        $data = $this->request('GET', '/v1/participants/summary', [
            'participant_id' => $participantId,
            'locale'         => $locale,
        ]);

        if ($data === null) {
            return [];
        }

        $result = (isset($data['DATA']) && is_array($data['DATA'])) ? $data['DATA'] : $data;

        if (!empty($result) && is_array($result)) {
            $this->setCache($cacheKey, $result, 3600);
            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                '[SportsAPIService] getParticipantDetails: fetched id=' . $participantId
            );
        }

        return is_array($result) ? $result : [];
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  D) FlashSport: Event Search & Details                  ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Поиск матчей по виду спорта и запросу.
     * Вызывает /v1/events/list для заданного дня и фильтрует по тексту.
     *
     * @param int    $sportId    Вид спорта (1=Soccer, 4=Hockey, ...)
     * @param string $query      Текст для фильтрации по командам/турниру
     * @param string $locale     Локаль
     * @param int    $indentDays Смещение дня (0=сегодня, -1=вчера, 1=завтра)
     *
     * @return array<int, array{id:string, title:string, year:string, overview:string, poster:null}>
     */
    public function searchEventsByQuery(int $sportId, string $query, string $locale, int $indentDays = 0): array
    {
        $data = $this->request('GET', '/v1/events/list', [
            'sport_id'    => (string)$sportId,
            'locale'      => $locale,
            'indent_days' => (string)$indentDays,
            'timezone'    => '0',
        ]);

        if ($data === null) {
            return [];
        }

        $groups  = $data['DATA'] ?? [];
        $needle  = mb_strtolower(trim($query));
        $results = [];

        foreach ($groups as $group) {
            $tournamentName = $group['NAME'] ?? '';
            foreach (($group['EVENTS'] ?? []) as $event) {
                $home = $event['HOME_NAME'] ?? '';
                $away = $event['AWAY_NAME'] ?? '';
                if (
                    $needle === ''
                    || str_contains(mb_strtolower($home), $needle)
                    || str_contains(mb_strtolower($away), $needle)
                    || str_contains(mb_strtolower($tournamentName), $needle)
                ) {
                    $score     = ($event['HOME_SCORE_CURRENT'] ?? '—') . ':' . ($event['AWAY_SCORE_CURRENT'] ?? '—');
                    $startTs   = isset($event['START_UTIME']) ? (int)$event['START_UTIME'] : null;
                    $dateLabel = $startTs ? date('d.m H:i', $startTs) : (string)($event['START_TIME'] ?? '');
                    $results[] = [
                        'id'      => (string)($event['EVENT_ID'] ?? ''),
                        'title'   => $home . ' — ' . $away,
                        'year'    => '',
                        'overview' => implode(' | ', array_filter([
                            $tournamentName,
                            $score,
                            $event['STAGE_TYPE'] ?? null,
                            $event['ROUND']      ?? null,
                            $dateLabel           ?: null,
                        ])),
                        'poster'  => null,
                    ];
                }
            }
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[SportsAPIService] searchEventsByQuery: sport=' . $sportId
            . ' day=' . $indentDays . ' query="' . $query . '" → ' . count($results) . ' events'
        );

        return $results;
    }

    /**
     * Поиск турниров по виду спорта и запросу.
     * Кеширует полный список турниров, фильтрует в PHP.
     *
     * @param int    $sportId Вид спорта
     * @param string $query   Текст для фильтрации
     * @param string $locale  Локаль
     *
     * @return array<int, array{id:string, title:string, year:string, overview:string, poster:null}>
     */
    public function searchTournamentsByQuery(int $sportId, string $query, string $locale): array
    {
        $cacheKey = self::CACHE_PREFIX . 'tourlst/' . $sportId . '_' . $locale;
        $all      = $this->getCache($cacheKey);

        if ($all === null) {
            $data = $this->request('GET', '/v1/tournaments/list', [
                'sport_id' => (string)$sportId,
                'locale'   => $locale,
            ]);
            $all = $data['DATA'] ?? [];
            if (!empty($all)) {
                $this->setCache($cacheKey, $all, self::TTL_CALENDAR);
            }
        }

        $needle  = mb_strtolower(trim($query));
        $results = [];

        foreach ($all as $t) {
            $name    = $t['LEAGUE_NAME']   ?? '';
            $country = $t['COUNTRY_NAME']  ?? '';
            if (
                $needle === ''
                || str_contains(mb_strtolower($name), $needle)
                || str_contains(mb_strtolower($country), $needle)
            ) {
                // Encode stageId + seasonId in id field, split by ':' on details side
                $stageId  = $t['STAGES'][0]['STAGE_ID']           ?? '';
                $seasonId = $t['ACTUAL_TOURNAMENT_SEASON_ID']      ?? '';
                // Season label: prefer the stage SEASON string, fall back to season ID
                $season   = $t['STAGES'][0]['SEASON'] ?? $t['SEASON'] ?? (string)$seasonId;
                $results[] = [
                    'id'      => $stageId . ':' . $seasonId,
                    'title'   => $country . ': ' . $name,
                    'year'    => (string)$season,
                    'overview' => implode(' | ', array_filter([$country, $season ?: null])),
                    'poster'  => null,
                ];
            }
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[SportsAPIService] searchTournamentsByQuery: sport=' . $sportId
            . ' query="' . $query . '" → ' . count($results) . ' tournaments'
        );

        return $results;
    }

    /**
     * Получить полные данные матча: events/data + events/summary.
     *
     * @param string $eventId Event ID
     * @param string $locale  Локаль
     *
     * @return array{event:array, tournament:array, sport:array, summary:array, info:array}|array{}
     */
    public function getFullMatchData(string $eventId, string $locale): array
    {
        if (empty(trim($eventId))) {
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'fullmatch/' . md5($eventId . '|' . $locale);
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $rawData    = $this->request('GET', '/v1/events/data',    ['locale' => $locale, 'event_id' => $eventId]);
        $rawSummary = $this->request('GET', '/v1/events/summary', ['locale' => $locale, 'event_id' => $eventId]);

        if ($rawData === null) {
            return [];
        }

        $result = [
            'event'      => $rawData['DATA']['EVENT']      ?? ($rawData['DATA'] ?? []),
            'tournament' => $rawData['DATA']['TOURNAMENT'] ?? [],
            'sport'      => $rawData['DATA']['SPORT']      ?? [],
            'summary'    => $rawSummary['DATA']            ?? [],
            'info'       => $rawSummary['INFO']            ?? [],
        ];

        // Finished matches cached longer, live events cached briefly
        $stage = $result['event']['STAGE_TYPE'] ?? '';
        $ttl   = ($stage === 'FINISHED') ? self::TTL_CALENDAR : 60;
        $this->setCache($cacheKey, $result, $ttl);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[SportsAPIService] getFullMatchData: event=' . $eventId . ' stage=' . $stage
        );

        return $result;
    }

    /**
     * Получить таблицу турнира.
     * Сначала запрашивает доступные типы таблицы, затем саму таблицу.
     *
     * @param string $stageId  Tournament stage ID
     * @param string $seasonId Tournament season ID
     * @param string $locale   Локаль
     *
     * @return array
     */
    public function getTournamentStandingsData(string $stageId, string $seasonId, string $locale): array
    {
        $cacheKey = self::CACHE_PREFIX . 'standings/' . md5($stageId . '|' . $seasonId . '|' . $locale);
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Get available standing tabs to pick the first type
        $tabsRaw = $this->request('GET', '/v1/tournaments/standings/tabs', [
            'locale'                => $locale,
            'tournament_stage_id'   => $stageId,
            'tournament_season_id'  => $seasonId,
        ]);

        $standingType = 'overall';
        if ($tabsRaw !== null && !empty($tabsRaw['DATA'][0]['STANDING_TYPE'])) {
            $standingType = (string)$tabsRaw['DATA'][0]['STANDING_TYPE'];
        }

        $data = $this->request('GET', '/v1/tournaments/standings', [
            'locale'                => $locale,
            'standing_type'         => $standingType,
            'tournament_stage_id'   => $stageId,
            'tournament_season_id'  => $seasonId,
        ]);

        $result = $data['DATA'] ?? [];
        if (!empty($result)) {
            $this->setCache($cacheKey, $result, 3600);
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[SportsAPIService] getTournamentStandingsData: stage=' . $stageId
            . ' type=' . $standingType
        );

        return is_array($result) ? $result : [];
    }

    /**
     * Получить расписание или результаты турнира.
     *
     * @param string $stageId   Tournament stage ID
     * @param string $locale    Локаль
     * @param string $direction 'fixtures' (upcoming) or 'results' (past)
     *
     * @return array
     */
    public function getTournamentScheduleData(string $stageId, string $locale, string $direction = 'fixtures'): array
    {
        $endpoint = ($direction === 'results')
            ? '/v1/tournaments/results'
            : '/v1/tournaments/fixtures';

        $cacheKey = self::CACHE_PREFIX . 'schedule/' . md5($stageId . '|' . $locale . '|' . $direction);
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->request('GET', $endpoint, [
            'locale'              => $locale,
            'tournament_stage_id' => $stageId,
            'page'                => '1',
        ]);

        $result = $data['DATA'] ?? [];
        $ttl    = ($direction === 'results') ? self::TTL_CALENDAR : 3600;
        if (!empty($result)) {
            $this->setCache($cacheKey, $result, $ttl);
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[SportsAPIService] getTournamentScheduleData: stage=' . $stageId
            . ' direction=' . $direction
        );

        return is_array($result) ? $result : [];
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: HTTP Request                                  ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Выполнить HTTP-запрос к FlashLive Sports API.
     *
     * @param string $method HTTP-метод (GET)
     * @param string $uri    Относительный URI
     * @param array  $params Query-параметры
     *
     * @return array|null Декодированный JSON или null при ошибке
     */
    private function request(string $method, string $uri, array $params = []): ?array
    {
        $result = $this->httpGet(
            $this->buildUrl(self::BASE_URL . $uri, $params),
            ['X-RapidAPI-Key: ' . $this->apiKey, 'X-RapidAPI-Host: ' . self::RAPIDAPI_HOST, 'Accept: application/json'],
            15
        );
        if (!$result['success']) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[SportsAPIService] HTTP error for ' . $uri . ': ' . ($result['error'] ?? ''));
            return null;
        }
        return is_array($result['data']) ? $result['data'] : null;
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
