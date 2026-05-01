<?php

declare(strict_types=1);

namespace SpookyApp\Services\API;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use MODX\Revolution\modX;
use SpookyApp\Services\Cache\CacheService;

/**
 * GamesAPIService — клиент для RAWG Video Games Database API + Games Details API.
 *
 * ═══════════════════════════════════════════════════════════════
 * Два раздела функциональности:
 *
 * A) Topic Finder — поиск трендовых игровых тем:
 *    - fetchTrendingTopics()
 *
 * B) Chunk Generator — детальная информация для генерации контента:
 *    - searchGames()
 *    - getGameDetailsByRAWG(), getGameDetailsFromAPI()
 *    - getGameScreenshots()
 *    - getGameRequirements()
 *    - searchGameBySlug()
 *
 * Приоритет: RAWG API → Games Details API (fallback).
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
class GamesAPIService extends APIService
{
    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constants                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var string Базовый URL RAWG API */
    private const RAWG_BASE_URL = 'https://api.rawg.io/api';

    /** @var string Базовый URL Games Details API (RapidAPI) */
    private const GAMES_DETAILS_BASE_URL = 'https://games-details.p.rapidapi.com/';

    /** @var string Префикс ключей кеша */
    private const CACHE_PREFIX = 'spookyapp/games/';

    /** @var string Системная настройка MODX: RAWG API key */
    private const SETTING_RAWG_KEY = 'spookyapp.rawg_api_key';

    /** @var string Системная настройка MODX: RapidAPI key (для Games Details API) */
    private const SETTING_RAPIDAPI_KEY = 'spookyapp.rapidapi_key';

    // ── Cache TTL (секунды) ──────────────────────────────────

    /** @var int Кеш для Topic Finder (6 часов) */
    private const TTL_TRENDING = 21600;

    /** @var int Кеш для поиска (12 часов) */
    private const TTL_SEARCH = 43200;

    /** @var int Кеш для деталей игры (7 дней) */
    private const TTL_DETAILS = 604800;

    /** @var int Кеш для скриншотов и статичных данных (30 дней) */
    private const TTL_STATIC = 2592000;

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Properties                                             ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var string RAWG API key */
    private string $rawgKey;

    /** @var string|null RapidAPI key для Games Details API */
    private ?string $rapidApiKey;

    /** @var bool Включён ли кеш */
    private bool $cacheEnabled;

    /** @var Client|null Guzzle-клиент Games Details API (RapidAPI) */
    private ?Client $gamesDetailsClient = null;

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constructor                                            ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Конструктор GamesAPIService.
     *
     * Инициализирует HTTP-клиенты для RAWG и Games Details API.
     *
     * @param modX        $modx        MODX instance
     * @param string      $rawgKey     RAWG API key
     * @param string|null $rapidApiKey RapidAPI key для Games Details API (опционально)
     * @param array       $options     Дополнительные опции:
     *   - cache_enabled (bool): включить кеш, default true
     *   - timeout (int): таймаут запроса в секундах, default 15
     */
    public function __construct(modX $modx, CacheService $cache)
    {
        parent::__construct($modx, $cache);

        $this->rawgKey      = (string)$this->modx->getOption(self::SETTING_RAWG_KEY, null, '');
        $this->rapidApiKey  = $this->modx->getOption(self::SETTING_RAPIDAPI_KEY, null, null);
        $this->cacheEnabled = true;

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[GamesAPIService] Initialized. RAWG: ' . (empty($this->rawgKey) ? 'MISSING' : 'OK')
            . ', GamesDetails: ' . (!empty($this->rapidApiKey) ? 'OK' : 'N/A')
        );
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A) Topic Finder Methods                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить трендовые игровые темы.
     *
     * Используется модулем Topic Finder для агрегации
     * трендов из различных источников.
     *
     * Запрашивает список популярных игр за последний месяц
     * из RAWG API, отсортированных по рейтингу.
     *
     * @param int    $limit    Максимум записей (default 20)
     * @param string $ordering Сортировка, default '-rating'
     *
     * @return array<int, array{
     *   id: int,
     *   title: string,
     *   slug: string,
     *   description: string,
     *   url: string,
     *   image: string|null,
     *   rating: float,
     *   metacritic: int|null,
     *   released: string,
     *   genres: array<string>,
     *   platforms: array<string>
     * }>
     */
    public function fetchTrendingTopics(int $limit = 20, string $ordering = '-rating'): array
    {
        $cacheKey = self::CACHE_PREFIX . 'trending/' . $ordering . '/' . $limit;

        // ── Проверяем кеш ────────────────────────────────────
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[GamesAPIService] fetchTrendingTopics: cache hit ('
                . count($cached) . ' items)'
            );
            return $cached;
        }

        // ── Запрос к RAWG ────────────────────────────────────
        $dateFrom = date('Y-m-d', strtotime('-30 days'));
        $dateTo = date('Y-m-d');

        $data = $this->rawgRequest('/games', [
            'dates'     => $dateFrom . ',' . $dateTo,
            'ordering'  => $ordering,
            'page_size' => min($limit, 40),
        ]);

        if ($data === null) {
            return [];
        }

        $results = $data['results'] ?? [];

        $topics = array_map(function (array $item): array {
            return [
                'id'          => (int)($item['id'] ?? 0),
                'title'       => $item['name'] ?? '',
                'slug'        => $item['slug'] ?? '',
                'description' => '',
                'url'         => 'https://rawg.io/games/' . ($item['slug'] ?? ''),
                'image'       => $item['background_image'] ?? null,
                'rating'      => (float)($item['rating'] ?? 0),
                'metacritic'  => isset($item['metacritic']) ? (int)$item['metacritic'] : null,
                'released'    => $item['released'] ?? '',
                'genres'      => array_map(
                    fn(array $g): string => $g['name'] ?? '',
                    $item['genres'] ?? []
                ),
                'platforms'   => array_map(
                    fn(array $p): string => $p['platform']['name'] ?? '',
                    $item['platforms'] ?? []
                ),
            ];
        }, $results);

        // ── Кешируем ─────────────────────────────────────────
        $this->setCache($cacheKey, $topics, self::TTL_TRENDING);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[GamesAPIService] fetchTrendingTopics: fetched '
            . count($topics) . ' items'
        );

        return $topics;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A.2) Topic Finder — aggregateForTopicFinder            ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Агрегировать игровые темы для TopicFinder.
     *
     * Собирает новые релизы и трендовые игры, нормализует в единый
     * формат TopicFinder, дедуплицирует по rawg_id.
     *
     * @param array $opts Опции: type ('popular'|'new_releases')
     * @return array{success: bool, topics: array<int, array>, error: string|null}
     */
    public function aggregateForTopicFinder(array $opts = []): array
    {
        try {
            $type   = (string)($opts['type'] ?? 'popular'); // 'popular' or 'new_releases'
            $topics = [];

            if ($type === 'new_releases') {
                // ── Только новые релизы ────────────────────────
                $newReleases = $this->getNewReleases(30, 1);
                if ($newReleases['success']) {
                    foreach ($newReleases['games'] as $game) {
                        $topics[] = $this->gameToTopic($this->enrichGameWithDetails($game), 'new_release');
                    }
                    $this->modx->log(
                        modX::LOG_LEVEL_DEBUG,
                        '[Games] New releases for TopicFinder: ' . count($newReleases['games'])
                    );
                }
            } else {
                // ── Только трендовые (по умолчанию — popular) ─
                $trending = $this->getTrendingGames(1);
                if ($trending['success']) {
                    foreach ($trending['games'] as $game) {
                        $topics[] = $this->gameToTopic($this->enrichGameWithDetails($game), 'trending');
                    }
                    $this->modx->log(
                        modX::LOG_LEVEL_DEBUG,
                        '[Games] Trending for TopicFinder: ' . count($trending['games'])
                    );
                }
            }

            // ── Дедупликация по rawg_id ────────────────────────
            $seen = [];
            $unique = [];
            foreach ($topics as $topic) {
                $rawgId = $topic['metadata']['rawg_id'] ?? '';
                if (!empty($rawgId) && isset($seen[$rawgId])) {
                    continue;
                }
                $seen[$rawgId] = true;
                $unique[] = $topic;
            }

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                '[Games] aggregateForTopicFinder: ' . count($unique) . ' уникальных тем'
                . ' (до дедупликации: ' . count($topics) . ')'
            );

            return [
                'success' => true,
                'topics'  => $unique,
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[Games] aggregateForTopicFinder error: ' . $e->getMessage()
            );

            return [
                'success' => false,
                'topics'  => [],
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить новые релизы игр из RAWG API.
     *
     * @param int $pageSize Количество на странице (макс 40)
     * @param int $page     Номер страницы
     *
     * @return array{success: bool, games: array<int, array>}
     */
    private function getNewReleases(int $pageSize = 30, int $page = 1): array
    {
        $dateFrom = date('Y-m-d', strtotime('-30 days'));
        $dateTo = date('Y-m-d');

        $data = $this->rawgRequest('/games', [
            'dates'     => $dateFrom . ',' . $dateTo,
            'ordering'  => '-released',
            'page_size' => min($pageSize, 40),
            'page'      => max(1, $page),
        ]);

        if ($data === null) {
            return ['success' => false, 'games' => []];
        }

        return [
            'success' => true,
            'games'   => $data['results'] ?? [],
        ];
    }

    /**
     * Получить трендовые (высокий рейтинг) игры из RAWG API.
     *
     * @param int $page Номер страницы
     *
     * @return array{success: bool, games: array<int, array>}
     */
    private function getTrendingGames(int $page = 1): array
    {
        $dateFrom = date('Y-m-d', strtotime('-60 days'));
        $dateTo = date('Y-m-d');

        $data = $this->rawgRequest('/games', [
            'dates'     => $dateFrom . ',' . $dateTo,
            'ordering'  => '-rating',
            'page_size' => 20,
            'page'      => max(1, $page),
        ]);

        if ($data === null) {
            return ['success' => false, 'games' => []];
        }

        return [
            'success' => true,
            'games'   => $data['results'] ?? [],
        ];
    }

    /**
     * Нормализовать игру RAWG в единый формат TopicFinder.
     *
     * @param array  $game    Сырые данные игры из RAWG
     * @param string $subtype Подтип: 'new_release' | 'trending'
     *
     * @return array Нормализованная тема TopicFinder
     */
    private function gameToTopic(array $game, string $subtype = 'new_release'): array
    {
        $name = trim((string)($game['name'] ?? ''));
        $rawgId = (int)($game['id'] ?? 0);
        $slug = (string)($game['slug'] ?? '');
        $released = (string)($game['released'] ?? '');

        // Нормализуем дату в ISO 8601
        $publishedAt = '';
        if (!empty($released)) {
            try {
                $publishedAt = (new \DateTimeImmutable($released))->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable) {
                $publishedAt = $released;
            }
        }

        $genres = array_map(
            fn(array $g): string => $g['name'] ?? '',
            $game['genres'] ?? []
        );

        $platforms = array_map(
            fn(array $p): string => $p['platform']['name'] ?? '',
            $game['platforms'] ?? []
        );

        $description = trim((string)($game['description_raw'] ?? ''));

        return [
            'id'           => 'games_' . $rawgId,
            'source'       => 'games',
            'title'        => $name,
            'url'          => !empty($slug)
                ? 'https://rawg.io/games/' . $slug
                : '',
            'description'  => $description,
            'category'     => 'games',
            'published_at' => $publishedAt,
            'score'        => 0.0,
            'metadata'     => [
                'rawg_id'      => $rawgId,
                'slug'         => $slug,
                'subtype'      => $subtype,
                'rating'       => (float)($game['rating'] ?? 0),
                'metacritic'   => isset($game['metacritic']) ? (int)$game['metacritic'] : null,
                'image'        => $game['background_image'] ?? null,
                'genres'       => $genres,
                'platforms'    => $platforms,
                'release_date' => $released,
                'developer'    => (string)($game['developer_name'] ?? ''),
                'age_rating'   => (string)($game['esrb_rating_name'] ?? ''),
            ],
        ];
    }

    /**
     * Обогатить базовые данные игры детальной информацией из RAWG /games/{id}.
     *
     * Добавляет description_raw, developer_name, esrb_rating_name.
     * Результат кешируется на 7 дней — первый вызов медленнее, последующие мгновенные.
     *
     * @param array $game Базовые данные игры из list endpoint
     * @return array Обогащённые данные
     */
    private function enrichGameWithDetails(array $game): array
    {
        $rawgId = (int)($game['id'] ?? 0);
        if ($rawgId <= 0 || empty($this->rawgKey)) {
            return $game;
        }

        $details = $this->getGameDetailsByRAWG($rawgId);
        if (empty($details)) {
            return $game;
        }

        $game['description_raw'] = $details['description_raw'] ?? '';
        $developers = array_map(
            fn(array $d): string => $d['name'] ?? '',
            $details['developers'] ?? []
        );
        $game['developer_name'] = implode(', ', array_filter($developers));

        $esrb = $details['esrb_rating'];
        $game['esrb_rating_name'] = is_array($esrb) ? ($esrb['name'] ?? '') : '';

        return $game;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Search                    ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Поиск игр по названию через RAWG API.
     *
     * Endpoint: GET /games?search={query}&page_size=20
     *
     * @param string $query Поисковый запрос (название игры)
     * @param int    $page  Номер страницы (пагинация, начиная с 1)
     *
     * @return array{
     *   count: int,
     *   next: string|null,
     *   previous: string|null,
     *   results: array<int, array{
     *     id: int,
     *     name: string,
     *     slug: string,
     *     released: string|null,
     *     background_image: string|null,
     *     rating: float,
     *     metacritic: int|null,
     *     genres: array<int, array{id: int, name: string}>,
     *     platforms: array<int, array{platform: array{id: int, name: string}}>
     *   }>
     * }
     */
    public function searchGames(string $query, int $page = 1): array
    {
        $empty = ['count' => 0, 'next' => null, 'previous' => null, 'results' => []];

        if (empty(trim($query))) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[GamesAPIService] searchGames: empty query'
            );
            return $empty;
        }

        $cacheKey = self::CACHE_PREFIX . 'search/'
            . md5($query . '|' . $page);

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[GamesAPIService] searchGames: cache hit for "' . $query . '"'
            );
            return $cached;
        }

        $data = $this->rawgRequest('/games', [
            'search'    => $query,
            'page'      => max(1, $page),
            'page_size' => 20,
        ]);

        if ($data === null) {
            return $empty;
        }

        $result = [
            'count'    => (int)($data['count'] ?? 0),
            'next'     => $data['next'] ?? null,
            'previous' => $data['previous'] ?? null,
            'results'  => array_map(function (array $item): array {
                return [
                    'id'               => (int)($item['id'] ?? 0),
                    'name'             => $item['name'] ?? '',
                    'slug'             => $item['slug'] ?? '',
                    'released'         => $item['released'] ?? null,
                    'background_image' => $item['background_image'] ?? null,
                    'rating'           => (float)($item['rating'] ?? 0),
                    'metacritic'       => isset($item['metacritic']) ? (int)$item['metacritic'] : null,
                    'genres'           => array_map(function (array $g): array {
                        return [
                            'id'   => (int)($g['id'] ?? 0),
                            'name' => $g['name'] ?? '',
                        ];
                    }, $item['genres'] ?? []),
                    'platforms'        => array_map(function (array $p): array {
                        return [
                            'platform' => [
                                'id'   => (int)($p['platform']['id'] ?? 0),
                                'name' => $p['platform']['name'] ?? '',
                            ],
                        ];
                    }, $item['platforms'] ?? []),
                ];
            }, $data['results'] ?? []),
        ];

        $this->setCache($cacheKey, $result, self::TTL_SEARCH);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[GamesAPIService] searchGames: "' . $query . '" page=' . $page
            . ' → ' . count($result['results']) . ' results'
            . ' (total: ' . $result['count'] . ')'
        );

        return $result;
    }

    /**
     * Поиск игры по slug через Games Details API (fallback).
     *
     * Используется когда RAWG не находит игру по точному названию.
     *
     * Endpoint: GET /search?sugg={slug}
     *
     * @param string $slug Slug или частичное название игры
     *
     * @return array<int, array{
     *   id: int|string,
     *   name: string,
     *   image: string|null,
     *   release_date: string|null,
     *   platforms: array<string>
     * }>
     */
    public function searchGameBySlug(string $slug): array
    {
        if (empty(trim($slug))) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[GamesAPIService] searchGameBySlug: empty slug'
            );
            return [];
        }

        if ($this->gamesDetailsClient === null) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[GamesAPIService] searchGameBySlug: Games Details API not configured'
            );
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'search_slug/' . md5($slug);

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[GamesAPIService] searchGameBySlug: cache hit for "' . $slug . '"'
            );
            return $cached;
        }

        $data = $this->gamesDetailsRequest('/search', [
            'sugg' => $slug,
        ]);

        if ($data === null) {
            return [];
        }

        // Games Details API может возвращать массив или объект с results
        $items = [];
        if (isset($data['results']) && is_array($data['results'])) {
            $items = $data['results'];
        } elseif (isset($data[0])) {
            $items = $data;
        }

        $results = array_map(function (array $item): array {
            return [
                'id'           => $item['id'] ?? $item['game_id'] ?? 0,
                'name'         => $item['name'] ?? $item['title'] ?? '',
                'image'        => $item['image'] ?? $item['thumbnail'] ?? $item['cover'] ?? null,
                'release_date' => $item['release_date'] ?? $item['released'] ?? null,
                'platforms'    => $this->extractPlatformNames($item),
            ];
        }, $items);

        $this->setCache($cacheKey, $results, self::TTL_SEARCH);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[GamesAPIService] searchGameBySlug: "' . $slug . '" → '
            . count($results) . ' results'
        );

        return $results;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Details                   ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить полную информацию об игре из RAWG API.
     *
     * Endpoint: GET /games/{id}
     *
     * Возвращает все основные поля: name, released, background_image,
     * rating, ratings_count, metacritic, playtime, platforms, genres,
     * stores, developers, publishers, description_raw, website.
     *
     * @param int $rawgId ID игры в RAWG
     *
     * @return array{
     *   id: int,
     *   name: string,
     *   slug: string,
     *   name_original: string,
     *   description: string,
     *   description_raw: string,
     *   released: string|null,
     *   background_image: string|null,
     *   background_image_additional: string|null,
     *   website: string|null,
     *   rating: float,
     *   ratings_count: int,
     *   metacritic: int|null,
     *   metacritic_url: string|null,
     *   playtime: int,
     *   platforms: array<int, array{
     *     platform: array{id: int, name: string, slug: string},
     *     requirements: array{minimum: string|null, recommended: string|null}|null
     *   }>,
     *   genres: array<int, array{id: int, name: string, slug: string}>,
     *   stores: array<int, array{id: int, store: array{id: int, name: string, domain: string}}>,
     *   developers: array<int, array{id: int, name: string, slug: string}>,
     *   publishers: array<int, array{id: int, name: string, slug: string}>,
     *   esrb_rating: array{id: int, name: string, slug: string}|null,
     *   tags: array<int, array{id: int, name: string, slug: string}>
     * }|array{}
     */
    public function getGameDetailsByRAWG(int $rawgId): array
    {
        if ($rawgId <= 0) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[GamesAPIService] getGameDetailsByRAWG: invalid rawgId=' . $rawgId
            );
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'rawg/game/' . $rawgId;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[GamesAPIService] getGameDetailsByRAWG: cache hit for id=' . $rawgId
            );
            return $cached;
        }

        $data = $this->rawgRequest('/games/' . $rawgId);
        if ($data === null) {
            return [];
        }

        $result = [
            'id'                           => (int)($data['id'] ?? 0),
            'name'                         => $data['name'] ?? '',
            'slug'                         => $data['slug'] ?? '',
            'name_original'                => $data['name_original'] ?? $data['name'] ?? '',
            'description'                  => $data['description'] ?? '',
            'description_raw'              => $data['description_raw'] ?? '',
            'released'                     => $data['released'] ?? null,
            'background_image'             => $data['background_image'] ?? null,
            'background_image_additional'  => $data['background_image_additional'] ?? null,
            'website'                      => $data['website'] ?? null,
            'rating'                       => (float)($data['rating'] ?? 0),
            'ratings_count'                => (int)($data['ratings_count'] ?? 0),
            'metacritic'                   => isset($data['metacritic']) ? (int)$data['metacritic'] : null,
            'metacritic_url'               => $data['metacritic_url'] ?? null,
            'playtime'                     => (int)($data['playtime'] ?? 0),
            'platforms'                    => array_map(function (array $p): array {
                return [
                    'platform'     => [
                        'id'   => (int)($p['platform']['id'] ?? 0),
                        'name' => $p['platform']['name'] ?? '',
                        'slug' => $p['platform']['slug'] ?? '',
                    ],
                    'requirements' => isset($p['requirements_en']) || isset($p['requirements_ru'])
                        ? [
                            'minimum'     => $p['requirements_en']['minimum']
                                ?? $p['requirements_ru']['minimum']
                                ?? null,
                            'recommended' => $p['requirements_en']['recommended']
                                ?? $p['requirements_ru']['recommended']
                                ?? null,
                        ]
                        : (isset($p['requirements']) && is_array($p['requirements'])
                            ? [
                                'minimum'     => $p['requirements']['minimum'] ?? null,
                                'recommended' => $p['requirements']['recommended'] ?? null,
                            ]
                            : null
                        ),
                ];
            }, $data['platforms'] ?? []),
            'genres'                       => array_map(function (array $g): array {
                return [
                    'id'   => (int)($g['id'] ?? 0),
                    'name' => $g['name'] ?? '',
                    'slug' => $g['slug'] ?? '',
                ];
            }, $data['genres'] ?? []),
            'stores'                       => array_map(function (array $s): array {
                return [
                    'id'    => (int)($s['id'] ?? 0),
                    'store' => [
                        'id'     => (int)($s['store']['id'] ?? 0),
                        'name'   => $s['store']['name'] ?? '',
                        'domain' => $s['store']['domain'] ?? '',
                    ],
                ];
            }, $data['stores'] ?? []),
            'developers'                   => array_map(function (array $d): array {
                return [
                    'id'   => (int)($d['id'] ?? 0),
                    'name' => $d['name'] ?? '',
                    'slug' => $d['slug'] ?? '',
                ];
            }, $data['developers'] ?? []),
            'publishers'                   => array_map(function (array $p): array {
                return [
                    'id'   => (int)($p['id'] ?? 0),
                    'name' => $p['name'] ?? '',
                    'slug' => $p['slug'] ?? '',
                ];
            }, $data['publishers'] ?? []),
            'esrb_rating'                  => isset($data['esrb_rating']) && is_array($data['esrb_rating'])
                ? [
                    'id'   => (int)($data['esrb_rating']['id'] ?? 0),
                    'name' => $data['esrb_rating']['name'] ?? '',
                    'slug' => $data['esrb_rating']['slug'] ?? '',
                ]
                : null,
            'tags'                         => array_map(function (array $t): array {
                return [
                    'id'   => (int)($t['id'] ?? 0),
                    'name' => $t['name'] ?? '',
                    'slug' => $t['slug'] ?? '',
                ];
            }, $data['tags'] ?? []),
        ];

        $this->setCache($cacheKey, $result, self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[GamesAPIService] getGameDetailsByRAWG: id=' . $rawgId
            . ' → "' . $result['name'] . '"'
        );

        return $result;
    }

    /**
     * Получить информацию об игре из Games Details API (альтернативный источник).
     *
     * Endpoint: GET /gameinfo/single_game/{id}
     *
     * Используется как fallback, если RAWG не предоставляет
     * нужной информации или недоступен.
     *
     * @param int $gameId ID игры в Games Details API
     *
     * @return array{
     *   id: int|string,
     *   name: string,
     *   description: string,
     *   released: string|null,
     *   image: string|null,
     *   rating: float|null,
     *   metacritic: int|null,
     *   genres: array<string>,
     *   platforms: array<string>,
     *   developers: array<string>,
     *   publishers: array<string>,
     *   website: string|null,
     *   screenshots: array<string>
     * }|array{}
     */
    public function getGameDetailsFromAPI(int $gameId): array
    {
        if ($gameId <= 0) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[GamesAPIService] getGameDetailsFromAPI: invalid gameId=' . $gameId
            );
            return [];
        }

        if ($this->gamesDetailsClient === null) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[GamesAPIService] getGameDetailsFromAPI: Games Details API not configured'
            );
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'gamesdetails/game/' . $gameId;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[GamesAPIService] getGameDetailsFromAPI: cache hit for id=' . $gameId
            );
            return $cached;
        }

        $data = $this->gamesDetailsRequest('/gameinfo/single_game/' . $gameId);
        if ($data === null) {
            return [];
        }

        // Games Details API может вернуть данные в разных форматах
        $game = $data['result'] ?? $data['game'] ?? $data;

        $result = [
            'id'          => $game['id'] ?? $game['game_id'] ?? $gameId,
            'name'        => $game['name'] ?? $game['title'] ?? '',
            'description' => $game['description'] ?? $game['about'] ?? $game['summary'] ?? '',
            'released'    => $game['release_date'] ?? $game['released'] ?? null,
            'image'       => $game['image'] ?? $game['cover'] ?? $game['thumbnail'] ?? null,
            'rating'      => isset($game['rating'])
                ? (float)$game['rating']
                : null,
            'metacritic'  => isset($game['metacritic'])
                ? (int)$game['metacritic']
                : null,
            'genres'      => $this->extractStringList($game, 'genres'),
            'platforms'   => $this->extractPlatformNames($game),
            'developers'  => $this->extractStringList($game, 'developers'),
            'publishers'  => $this->extractStringList($game, 'publishers'),
            'website'     => $game['website'] ?? $game['url'] ?? null,
            'screenshots' => $this->extractScreenshotUrls($game),
        ];

        $this->setCache($cacheKey, $result, self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[GamesAPIService] getGameDetailsFromAPI: id=' . $gameId
            . ' → "' . $result['name'] . '"'
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Screenshots               ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить скриншоты игры из RAWG API.
     *
     * Endpoint: GET /games/{id}/screenshots
     *
     * @param int $rawgId ID игры в RAWG
     *
     * @return array{
     *   count: int,
     *   results: array<int, array{
     *     id: int,
     *     image: string,
     *     width: int,
     *     height: int
     *   }>
     * }
     */
    public function getGameScreenshots(int $rawgId): array
    {
        $empty = ['count' => 0, 'results' => []];

        if ($rawgId <= 0) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[GamesAPIService] getGameScreenshots: invalid rawgId=' . $rawgId
            );
            return $empty;
        }

        $cacheKey = self::CACHE_PREFIX . 'rawg/game/' . $rawgId . '/screenshots';

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[GamesAPIService] getGameScreenshots: cache hit for id=' . $rawgId
            );
            return $cached;
        }

        $data = $this->rawgRequest('/games/' . $rawgId . '/screenshots');
        if ($data === null) {
            return $empty;
        }

        $result = [
            'count'   => (int)($data['count'] ?? 0),
            'results' => array_map(function (array $item): array {
                return [
                    'id'     => (int)($item['id'] ?? 0),
                    'image'  => $item['image'] ?? '',
                    'width'  => (int)($item['width'] ?? 0),
                    'height' => (int)($item['height'] ?? 0),
                ];
            }, $data['results'] ?? []),
        ];

        $this->setCache($cacheKey, $result, self::TTL_STATIC);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[GamesAPIService] getGameScreenshots: id=' . $rawgId
            . ' → ' . $result['count'] . ' screenshots'
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Requirements              ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить системные требования игры.
     *
     * Извлекает и структурирует данные из platforms[].requirements
     * ответа getGameDetailsByRAWG().
     *
     * Если данные ещё не загружены, вызывает getGameDetailsByRAWG()
     * автоматически.
     *
     * @param int $rawgId ID игры в RAWG
     *
     * @return array<string, array{
     *   platform_id: int,
     *   platform_name: string,
     *   platform_slug: string,
     *   minimum: string|null,
     *   recommended: string|null,
     *   minimum_parsed: array{
     *     os: string|null,
     *     processor: string|null,
     *     memory: string|null,
     *     graphics: string|null,
     *     storage: string|null,
     *     directx: string|null
     *   },
     *   recommended_parsed: array{
     *     os: string|null,
     *     processor: string|null,
     *     memory: string|null,
     *     graphics: string|null,
     *     storage: string|null,
     *     directx: string|null
     *   }
     * }>
     *
     * Ключ массива — slug платформы (e.g. 'pc', 'playstation5').
     */
    public function getGameRequirements(int $rawgId): array
    {
        if ($rawgId <= 0) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[GamesAPIService] getGameRequirements: invalid rawgId=' . $rawgId
            );
            return [];
        }

        // Используем кеш деталей — requirements уже в них
        $cacheKey = self::CACHE_PREFIX . 'rawg/game/' . $rawgId . '/requirements';

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Загружаем полные детали (они тоже кешируются)
        $details = $this->getGameDetailsByRAWG($rawgId);
        if (empty($details) || empty($details['platforms'])) {
            return [];
        }

        $requirements = [];

        foreach ($details['platforms'] as $platformData) {
            $platform = $platformData['platform'] ?? [];
            $reqs = $platformData['requirements'] ?? null;

            $slug = $platform['slug'] ?? '';
            if (empty($slug)) {
                continue;
            }

            $minimum = $reqs['minimum'] ?? null;
            $recommended = $reqs['recommended'] ?? null;

            // Пропускаем платформы без требований
            if ($minimum === null && $recommended === null) {
                continue;
            }

            $requirements[$slug] = [
                'platform_id'        => (int)($platform['id'] ?? 0),
                'platform_name'      => $platform['name'] ?? '',
                'platform_slug'      => $slug,
                'minimum'            => $minimum,
                'recommended'        => $recommended,
                'minimum_parsed'     => $minimum !== null
                    ? $this->parseRequirementsText($minimum)
                    : $this->emptyRequirements(),
                'recommended_parsed' => $recommended !== null
                    ? $this->parseRequirementsText($recommended)
                    : $this->emptyRequirements(),
            ];
        }

        $this->setCache($cacheKey, $requirements, self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[GamesAPIService] getGameRequirements: id=' . $rawgId
            . ' → ' . count($requirements) . ' platforms with requirements'
        );

        return $requirements;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: RAWG HTTP Request                             ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Выполнить HTTP-запрос к RAWG API.
     *
     * Автоматически добавляет API key ко всем запросам.
     *
     * @param string $uri    Относительный URI (e.g. '/games/550')
     * @param array  $params Дополнительные query-параметры
     *
     * @return array|null Декодированный JSON или null при ошибке
     */
    private function rawgRequest(string $uri, array $params = []): ?array
    {
        $params['key'] = $this->rawgKey;
        $result = $this->httpGet($this->buildUrl(self::RAWG_BASE_URL . $uri, $params), ['Accept: application/json'], 15);
        if (!$result['success']) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[GamesAPIService] RAWG HTTP error for ' . $uri . ': ' . ($result['error'] ?? ''));
            return null;
        }
        return is_array($result['data']) ? $result['data'] : null;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Games Details API HTTP Request                 ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Выполнить HTTP-запрос к Games Details API (RapidAPI).
     *
     * @param string $uri    Относительный URI (e.g. '/gameinfo/single_game/123')
     * @param array  $params Query-параметры
     *
     * @return array|null Декодированный JSON или null при ошибке
     */
    private function gamesDetailsRequest(string $uri, array $params = []): ?array
    {
        if (empty($this->rapidApiKey)) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[GamesAPIService] Games Details RapidAPI key not set, skipping: ' . $uri);
            return null;
        }
        $result = $this->httpGet(
            $this->buildUrl(self::GAMES_DETAILS_BASE_URL . $uri, $params),
            ['X-RapidAPI-Key: ' . $this->rapidApiKey, 'X-RapidAPI-Host: games-details.p.rapidapi.com', 'Accept: application/json'],
            15
        );
        if (!$result['success']) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[GamesAPIService] GamesDetails HTTP error for ' . $uri . ': ' . ($result['error'] ?? ''));
            return null;
        }
        return is_array($result['data']) ? $result['data'] : null;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Requirements Parser                           ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Парсит текстовое описание системных требований.
     *
     * RAWG возвращает requirements как HTML-строку вида:
     *   "Minimum:\nOS: Windows 10\nProcessor: Intel Core i5\n..."
     *
     * Этот метод извлекает отдельные компоненты.
     *
     * @param string $text Текстовое описание требований
     *
     * @return array{
     *   os: string|null,
     *   processor: string|null,
     *   memory: string|null,
     *   graphics: string|null,
     *   storage: string|null,
     *   directx: string|null
     * }
     */
    private function parseRequirementsText(string $text): array
    {
        // Убираем HTML-теги
        $clean = strip_tags($text);

        $result = $this->emptyRequirements();

        // Маппинг ключевых слов → поле
        $patterns = [
            'os'        => '/(?:OS|Operating\s*System)\s*[:*]\s*(.+)/i',
            'processor' => '/(?:Processor|CPU)\s*[:*]\s*(.+)/i',
            'memory'    => '/(?:Memory|RAM)\s*[:*]\s*(.+)/i',
            'graphics'  => '/(?:Graphics|GPU|Video\s*Card|Video)\s*[:*]\s*(.+)/i',
            'storage'   => '/(?:Storage|Hard\s*Drive|HDD|SSD|Disk\s*Space)\s*[:*]\s*(.+)/i',
            'directx'   => '/(?:DirectX)\s*[:*]\s*(.+)/i',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $clean, $match)) {
                $value = trim($match[1]);
                // Обрезаем до конца строки или следующего ключевого слова
                $value = preg_replace('/\s*(?:Processor|CPU|Memory|RAM|Graphics|GPU|Video|Storage|HDD|SSD|DirectX|Network|Sound|Additional)\s*:.*/is', '', $value);
                $result[$key] = trim($value) ?: null;
            }
        }

        return $result;
    }

    /**
     * Пустая структура системных требований.
     *
     * @return array{
     *   os: null,
     *   processor: null,
     *   memory: null,
     *   graphics: null,
     *   storage: null,
     *   directx: null
     * }
     */
    private function emptyRequirements(): array
    {
        return [
            'os'        => null,
            'processor' => null,
            'memory'    => null,
            'graphics'  => null,
            'storage'   => null,
            'directx'   => null,
        ];
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Data Extraction Helpers                       ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Извлечь список строк из поля Games Details API.
     *
     * Обрабатывает случаи когда поле может быть:
     * - массивом строк: ['Action', 'Adventure']
     * - массивом объектов: [{name: 'Action'}, ...]
     * - строкой через запятую: 'Action, Adventure'
     *
     * @param array  $data Данные игры
     * @param string $key  Имя поля
     *
     * @return array<string>
     */
    private function extractStringList(array $data, string $key): array
    {
        if (!isset($data[$key])) {
            return [];
        }

        $value = $data[$key];

        // Строка через запятую
        if (is_string($value)) {
            return array_map('trim', explode(',', $value));
        }

        if (!is_array($value)) {
            return [];
        }

        // Массив строк
        if (isset($value[0]) && is_string($value[0])) {
            return $value;
        }

        // Массив объектов с name
        return array_filter(array_map(function ($item): string {
            if (is_array($item)) {
                return $item['name'] ?? $item['title'] ?? '';
            }
            return is_string($item) ? $item : '';
        }, $value));
    }

    /**
     * Извлечь названия платформ из данных Games Details API.
     *
     * @param array $data Данные игры
     *
     * @return array<string>
     */
    private function extractPlatformNames(array $data): array
    {
        // Формат RAWG: platforms[].platform.name
        if (isset($data['platforms'][0]['platform']['name'])) {
            return array_map(
                fn(array $p): string => $p['platform']['name'] ?? '',
                $data['platforms']
            );
        }

        // Формат Games Details: platforms как строки
        return $this->extractStringList($data, 'platforms');
    }

    /**
     * Извлечь URL скриншотов из данных Games Details API.
     *
     * @param array $data Данные игры
     *
     * @return array<string>
     */
    private function extractScreenshotUrls(array $data): array
    {
        $screenshots = $data['screenshots'] ?? $data['short_screenshots'] ?? [];

        if (!is_array($screenshots)) {
            return [];
        }

        return array_filter(array_map(function ($item): string {
            if (is_string($item)) {
                return $item;
            }
            if (is_array($item)) {
                return $item['image'] ?? $item['url'] ?? $item['path'] ?? '';
            }
            return '';
        }, $screenshots));
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