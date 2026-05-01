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
 * FootballAPIService — клиент для API-Football v3 (RapidAPI).
 *
 * ═══════════════════════════════════════════════════════════════
 * Два раздела функциональности:
 *
 * A) Topic Finder — поиск трендовых футбольных тем:
 *    - fetchTrendingTopics()
 *
 * B) Chunk Generator — детальная информация для генерации контента:
 *    - getTeamDetails()       — информация о клубе
 *    - getLeagueStandings()   — турнирная таблица лиги
 *
 * API-Football Base URL: https://v3.football.api-sports.io/
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
class FootballAPIService extends APIService
{
    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constants                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var string Базовый URL API-Football v3 */
    private const BASE_URL = 'https://v3.football.api-sports.io/';

    /** @var string RapidAPI host header */
    private const RAPIDAPI_HOST = 'v3.football.api-sports.io'; // нет уже на рапид апи, оставлен раади legacy

    /** @var string Префикс ключей кеша */
    private const CACHE_PREFIX = 'spookyapp/football/';

    /** @var string Системная настройка MODX: ключ API для API-Football */
    private const SETTING_API_KEY = 'spookyapp.football_api_key';

    // ── Cache TTL (секунды) ──────────────────────────────────

    /** @var int Кеш для Topic Finder (3 часа — live-данные) */
    private const TTL_TRENDING = 10800;

    /** @var int Кеш для деталей команды (7 дней — меняется редко) */
    private const TTL_TEAM = 604800;

    /** @var int Кеш для турнирной таблицы (6 часов) */
    private const TTL_STANDINGS = 21600;

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
     * Конструктор FootballAPIService.
     *
     * Инициализирует Guzzle Client с RapidAPI авторизацией для API-Football.
     *
     * @param modX   $modx    MODX instance
     * @param string $apiKey  RapidAPI key для API-Football
     * @param array  $options Дополнительные опции:
     *   - cache_enabled (bool): включить кеш, default true
     *   - timeout (int): таймаут запроса в секундах, default 15
     */
    public function __construct(modX $modx, CacheService $cache)
    {
        parent::__construct($modx, $cache);

        $this->apiKey       = (string)$this->modx->getOption(self::SETTING_API_KEY, null, '');
        $this->cacheEnabled = true;

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[FootballAPIService] Initialized. Key: ' . (empty($this->apiKey) ? 'MISSING' : 'OK')
        );
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A) Topic Finder Methods                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить трендовые футбольные темы.
     *
     * Используется модулем Topic Finder для агрегации
     * трендов из различных источников.
     *
     * API-Football endpoint: GET /fixtures?live=all
     *
     * @param int $limit Максимум записей
     *
     * @return array<int, array{
     *   id: int,
     *   title: string,
     *   league: string,
     *   home_team: string,
     *   away_team: string,
     *   score: string,
     *   status: string,
     *   date: string,
     *   venue: string|null
     * }>
     */
    public function fetchTrendingTopics(int $limit = 20): array
    {
        $cacheKey = self::CACHE_PREFIX . 'trending/' . $limit;

        // ── Проверяем кеш ────────────────────────────────────
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[FootballAPIService] fetchTrendingTopics: cache hit ('
                . count($cached) . ' items)'
            );
            return array_slice($cached, 0, $limit);
        }

        // ── Запрос к API ─────────────────────────────────────
        $data = $this->request('GET', '/fixtures', [
            'live' => 'all',
        ]);

        if ($data === null) {
            return [];
        }

        $fixtures = $data['response'] ?? [];

        $topics = array_map(function (array $item): array {
            $teams = $item['teams'] ?? [];
            $goals = $item['goals'] ?? [];
            $league = $item['league'] ?? [];
            $fixture = $item['fixture'] ?? [];

            $homeName = $teams['home']['name'] ?? '';
            $awayName = $teams['away']['name'] ?? '';

            return [
                'id'        => (int)($fixture['id'] ?? 0),
                'title'     => $homeName . ' vs ' . $awayName,
                'league'    => $league['name'] ?? '',
                'home_team' => $homeName,
                'away_team' => $awayName,
                'score'     => ($goals['home'] ?? '-') . ':' . ($goals['away'] ?? '-'),
                'status'    => $fixture['status']['long'] ?? $fixture['status']['short'] ?? '',
                'date'      => $fixture['date'] ?? '',
                'venue'     => $fixture['venue']['name'] ?? null,
            ];
        }, $fixtures);

        // ── Кешируем ─────────────────────────────────────────
        $this->setCache($cacheKey, $topics, self::TTL_TRENDING);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[FootballAPIService] fetchTrendingTopics: fetched '
            . count($topics) . ' live fixtures'
        );

        return array_slice($topics, 0, $limit);
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A.2) Topic Finder — aggregateForTopicFinder            ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Агрегировать футбольные темы для TopicFinder.
     *
     * Вызывает fetchTrendingTopics() и нормализует каждый матч
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
                    '[FootballAPIService] aggregateForTopicFinder: нет трендовых тем'
                );
                return ['success' => true, 'topics' => [], 'error' => null];
            }

            $topics = [];
            $seen = [];

            foreach ($rawTopics as $fixture) {
                $topic = $this->fixtureToTopic($fixture);
                $fixtureId = $topic['metadata']['fixture_id'] ?? '';
                if (!empty($fixtureId) && isset($seen[$fixtureId])) {
                    continue;
                }
                $seen[$fixtureId] = true;
                $topics[] = $topic;
            }

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                '[FootballAPIService] aggregateForTopicFinder: ' . count($topics) . ' уникальных тем'
            );

            return ['success' => true, 'topics' => $topics, 'error' => null];
        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[FootballAPIService] aggregateForTopicFinder error: ' . $e->getMessage()
            );
            return ['success' => false, 'topics' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Нормализовать матч API-Football в единый формат TopicFinder.
     *
     * @param array $fixture Сырые данные матча из fetchTrendingTopics()
     *
     * @return array Нормализованная тема TopicFinder
     */
    private function fixtureToTopic(array $fixture): array
    {
        $title = trim((string)($fixture['title'] ?? ''));
        $fixtureId = $fixture['id'] ?? 0;
        $date = (string)($fixture['date'] ?? '');

        // Нормализуем дату в ISO 8601
        $publishedAt = '';
        if (!empty($date)) {
            try {
                $publishedAt = (new \DateTimeImmutable($date))->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable) {
                $publishedAt = $date;
            }
        }

        return [
            'id'           => 'football_' . $fixtureId,
            'source'       => 'football',
            'title'        => $title,
            'url'          => '',
            'description'  => '',
            'category'     => 'football',
            'published_at' => $publishedAt,
            'score'        => 0.0,
            'metadata'     => [
                'fixture_id'  => (int)$fixtureId,
                'league'      => (string)($fixture['league'] ?? ''),
                'home_team'   => (string)($fixture['home_team'] ?? ''),
                'away_team'   => (string)($fixture['away_team'] ?? ''),
                'match_score' => (string)($fixture['score'] ?? ''),
                'status'      => (string)($fixture['status'] ?? ''),
                'venue'       => $fixture['venue'] ?? null,
            ],
        ];
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Team Details              ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить информацию о футбольном клубе.
     *
     * API-Football endpoint: GET /teams?id={teamId}
     *
     * Возвращает полную информацию о клубе: название, страна,
     * год основания, стадион, логотип, форма и т.д.
     *
     * @param int $teamId ID команды в API-Football
     *
     * @return array{
     *   team: array{
     *     id: int,
     *     name: string,
     *     code: string|null,
     *     country: string,
     *     founded: int|null,
     *     national: bool,
     *     logo: string|null
     *   },
     *   venue: array{
     *     id: int|null,
     *     name: string,
     *     address: string|null,
     *     city: string|null,
     *     capacity: int|null,
     *     surface: string|null,
     *     image: string|null
     *   }
     * }|array{}
     */
    public function getTeamDetails(int $teamId): array
    {
        if ($teamId <= 0) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[FootballAPIService] getTeamDetails: invalid teamId=' . $teamId
            );
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'team/' . $teamId;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[FootballAPIService] getTeamDetails: cache hit for id=' . $teamId
            );
            return $cached;
        }

        // ── Запрос к API ─────────────────────────────────────
        $data = $this->request('GET', '/teams', [
            'id' => $teamId,
        ]);

        if ($data === null) {
            return [];
        }

        $response = $data['response'][0] ?? null;
        if ($response === null) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[FootballAPIService] getTeamDetails: team not found, id=' . $teamId
            );
            return [];
        }

        $team = $response['team'] ?? [];
        $venue = $response['venue'] ?? [];

        $result = [
            'team'  => [
                'id'       => (int)($team['id'] ?? 0),
                'name'     => $team['name'] ?? '',
                'code'     => $team['code'] ?? null,
                'country'  => $team['country'] ?? '',
                'founded'  => isset($team['founded']) ? (int)$team['founded'] : null,
                'national' => (bool)($team['national'] ?? false),
                'logo'     => $team['logo'] ?? null,
            ],
            'venue' => [
                'id'       => isset($venue['id']) ? (int)$venue['id'] : null,
                'name'     => $venue['name'] ?? '',
                'address'  => $venue['address'] ?? null,
                'city'     => $venue['city'] ?? null,
                'capacity' => isset($venue['capacity']) ? (int)$venue['capacity'] : null,
                'surface'  => $venue['surface'] ?? null,
                'image'    => $venue['image'] ?? null,
            ],
        ];

        $this->setCache($cacheKey, $result, self::TTL_TEAM);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[FootballAPIService] getTeamDetails: id=' . $teamId
            . ' → "' . $result['team']['name'] . '"'
            . ' (' . $result['team']['country'] . ')'
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — League Standings          ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить турнирную таблицу лиги.
     *
     * API-Football endpoint: GET /standings?league={leagueId}&season={season}
     *
     * Возвращает таблицу с позициями команд, набранными очками,
     * статистикой побед/ничьих/поражений, голами и формой.
     *
     * @param int $leagueId ID лиги в API-Football (e.g. 39 = Premier League)
     * @param int $season   Год сезона (e.g. 2024)
     *
     * @return array{
     *   league: array{
     *     id: int,
     *     name: string,
     *     country: string,
     *     logo: string|null,
     *     flag: string|null,
     *     season: int
     *   },
     *   standings: array<int, array<int, array{
     *     rank: int,
     *     team: array{id: int, name: string, logo: string|null},
     *     points: int,
     *     goals_diff: int,
     *     form: string|null,
     *     description: string|null,
     *     all: array{
     *       played: int,
     *       win: int,
     *       draw: int,
     *       lose: int,
     *       goals_for: int,
     *       goals_against: int
     *     }
     *   }>>
     * }|array{}
     */
    public function getLeagueStandings(int $leagueId, int $season): array
    {
        if ($leagueId <= 0) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[FootballAPIService] getLeagueStandings: invalid leagueId=' . $leagueId
            );
            return [];
        }

        if ($season < 2000 || $season > (int)date('Y') + 1) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[FootballAPIService] getLeagueStandings: invalid season=' . $season
            );
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'standings/' . $leagueId . '/' . $season;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[FootballAPIService] getLeagueStandings: cache hit for league='
                . $leagueId . ' season=' . $season
            );
            return $cached;
        }

        // ── Запрос к API ─────────────────────────────────────
        $data = $this->request('GET', '/standings', [
            'league' => $leagueId,
            'season' => $season,
        ]);

        if ($data === null) {
            return [];
        }

        $response = $data['response'][0] ?? null;
        if ($response === null) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[FootballAPIService] getLeagueStandings: no data for league='
                . $leagueId . ' season=' . $season
            );
            return [];
        }

        $league = $response['league'] ?? [];

        // ── Нормализуем standings ────────────────────────────
        // API-Football возвращает standings как массив групп
        // (для лиг с группами, e.g. Champions League)
        $rawStandings = $league['standings'] ?? [];
        $standings = [];

        foreach ($rawStandings as $group) {
            $groupStandings = [];
            foreach ($group as $entry) {
                $team = $entry['team'] ?? [];
                $all = $entry['all'] ?? [];
                $goals = $all['goals'] ?? [];

                $groupStandings[] = [
                    'rank'        => (int)($entry['rank'] ?? 0),
                    'team'        => [
                        'id'   => (int)($team['id'] ?? 0),
                        'name' => $team['name'] ?? '',
                        'logo' => $team['logo'] ?? null,
                    ],
                    'points'      => (int)($entry['points'] ?? 0),
                    'goals_diff'  => (int)($entry['goalsDiff'] ?? 0),
                    'form'        => $entry['form'] ?? null,
                    'description' => $entry['description'] ?? null,
                    'all'         => [
                        'played'        => (int)($all['played'] ?? 0),
                        'win'           => (int)($all['win'] ?? 0),
                        'draw'          => (int)($all['draw'] ?? 0),
                        'lose'          => (int)($all['lose'] ?? 0),
                        'goals_for'     => (int)($goals['for'] ?? 0),
                        'goals_against' => (int)($goals['against'] ?? 0),
                    ],
                ];
            }
            $standings[] = $groupStandings;
        }

        $result = [
            'league'    => [
                'id'      => (int)($league['id'] ?? 0),
                'name'    => $league['name'] ?? '',
                'country' => $league['country'] ?? '',
                'logo'    => $league['logo'] ?? null,
                'flag'    => $league['flag'] ?? null,
                'season'  => (int)($league['season'] ?? $season),
            ],
            'standings' => $standings,
        ];

        $this->setCache($cacheKey, $result, self::TTL_STANDINGS);

        $teamCount = array_sum(array_map('count', $standings));

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[FootballAPIService] getLeagueStandings: league='
            . $leagueId . ' season=' . $season
            . ' → "' . $result['league']['name'] . '"'
            . ' (' . $teamCount . ' teams)'
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: HTTP Request                                  ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Выполнить HTTP-запрос к API-Football.
     *
     * Обрабатывает ограничения API:
     * - Free: 100 requests/day
     * - Логирует оставшееся количество запросов
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
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[FootballAPIService] HTTP error for ' . $uri . ': ' . ($result['error'] ?? ''));
            return null;
        }
        $data = $result['data'];
        if (!is_array($data)) {
            return null;
        }
        // API-Football возвращает errors в теле ответа
        if (!empty($data['errors'])) {
            $errors = is_array($data['errors'])
                ? implode('; ', array_values($data['errors']))
                : (string)$data['errors'];
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[FootballAPIService] API error for ' . $uri . ': ' . $errors);
            return null;
        }
        return $data;
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