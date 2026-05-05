<?php

declare(strict_types=1);

namespace SpookyApp\Services\API;

use MODX\Revolution\modX;
use SpookyApp\Services\Cache\CacheService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
/**
 * TMDBService — клиент для The Movie Database API v3.
 *
 * ═══════════════════════════════════════════════════════════════
 * Два раздела функциональности:
 *
 * A) Topic Finder — поиск трендовых тем:
 *    - fetchTrendingTopics()
 *
 * B) Chunk Generator — детальная информация для генерации контента:
 *    - searchMovies(), searchTVShows()
 *    - getMovieDetails(), getTVShowDetails()
 *    - getMovieCredits(), getTVShowCredits()
 *    - getPersonDetails(), getPersonMovieCredits(), getPersonTVCredits()
 *
 * Все методы:
 *    - Используют единый Guzzle Client с Bearer-авторизацией
 *    - Кешируют ответы через MODX cacheManager
 *    - Логируют ошибки через modX::log()
 *    - Возвращают типизированные массивы
 * ═══════════════════════════════════════════════════════════════
 *
 * @package SpookyApp
 * @subpackage Services\API
 */
class TMDBService extends APIService
{
    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constants                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var string Базовый URL API */
    private const BASE_URL = 'https://api.themoviedb.org/3';

    /** @var string Базовый URL для изображений */
    public const IMAGE_BASE_URL = 'https://image.tmdb.org/t/p/';

    /** @var string Префикс ключей кеша */
    private const CACHE_PREFIX = 'spookyapp/tmdb/';

    /** @var string Системная настройка MODX: TMDB Bearer Token */
    private const SETTING_API_TOKEN = 'spookyapp.tmdb_bearer_token';

    // ── Cache TTL (секунды) ──────────────────────────────────

    /** @var int Кеш для Topic Finder (6 часов) */
    private const TTL_TRENDING = 21600;

    /** @var int Кеш для поиска (24 часа) */
    private const TTL_SEARCH = 86400;

    /** @var int Кеш для деталей фильма/сериала (7 дней) */
    private const TTL_DETAILS = 604800;

    /** @var int Кеш для credits и person (30 дней) */
    private const TTL_CREDITS = 2592000;

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Properties                                             ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var string API Bearer Token */
    private string $apiToken;

    /** @var bool Включён ли кеш */
    private bool $cacheEnabled;

    /** @var Client Guzzle HTTP клиент с Bearer-авторизацией */
    private Client $client;

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constructor                                            ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Конструктор TMDBService.
     *
     * Инициализирует Guzzle Client с Bearer-авторизацией.
     *
     * @param modX   $modx     MODX instance
     * @param string $apiToken TMDB API Read Access Token (v4 auth / Bearer)
     * @param array  $options  Дополнительные опции:
     *   - cache_enabled (bool): включить кеш, default true
     *   - timeout (int): таймаут запроса в секундах, default 15
     */
    public function __construct(modX $modx, CacheService $cache)
    {
        parent::__construct($modx, $cache);

        $this->apiToken     = (string)$this->modx->getOption(self::SETTING_API_TOKEN, null, '');
        $this->cacheEnabled = true;

        // Настраиваем Guzzle HandlerStack с прокси-мидлвером (если proxy активен)
        $stack = HandlerStack::create();
        if ($this->proxy->isActive()) {
            $proxyConfig = $this->proxy;
            $stack->push(Middleware::mapRequest(
                static function (\Psr\Http\Message\RequestInterface $request) use ($proxyConfig): \Psr\Http\Message\RequestInterface {
                    $originalUrl = (string)$request->getUri();
                    $rewritten   = $proxyConfig->rewrite($originalUrl);
                    if ($rewritten['url'] !== $originalUrl) {
                        $request = $request
                            ->withUri(new Uri($rewritten['url']))
                            ->withHeader('X-Proxy-Secret', $proxyConfig->getSecretValue());
                    }
                    return $request;
                }
            ));
        }

        $this->client = new Client([
            'base_uri' => self::BASE_URL,
            'handler'  => $stack,
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->apiToken,
                'Accept'        => 'application/json',
            ],
            'timeout'  => 15,
        ]);

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[TMDBService] Initialized. Token: ' . (empty($this->apiToken) ? 'MISSING' : 'OK')
        );
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A) Topic Finder Methods                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить трендовые темы из TMDB.
     *
     * Используется модулем Topic Finder для агрегации
     * трендов из различных источников.
     *
     * @param string $timeWindow Временное окно: 'day' | 'week'
     * @param string $language   Язык результатов
     * @param int    $limit      Максимум записей
     *
     * @return array<int, array{
     *   id: int,
     *   title: string,
     *   media_type: string,
     *   overview: string,
     *   poster_path: string|null,
     *   backdrop_path: string|null,
     *   vote_average: float,
     *   release_date: string,
     *   popularity: float
     * }>
     */
    public function fetchTrendingTopics(
        string $timeWindow = 'week',
        string $language = 'en-US',
        int $limit = 20
    ): array {
        $cacheKey = self::CACHE_PREFIX . 'trending/' . $timeWindow . '/' . $language;

        // ── Проверяем кеш ────────────────────────────────────
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[TMDBService] fetchTrendingTopics: cache hit (' . count($cached) . ' items)'
            );
            return array_slice($cached, 0, $limit);
        }

        // ── Запрос к API ─────────────────────────────────────
        try {
            $trendingUrl = '/3/trending/all/' . $timeWindow . '?language=' . urlencode($language);
            $this->modx->log(modX::LOG_LEVEL_INFO, '[TMDBService] → GET ' . $trendingUrl);
            $response = $this->client->get('/3/trending/all/' . $timeWindow, [
                'query' => [
                    'language' => $language,
                ],
            ]);
            $this->modx->log(modX::LOG_LEVEL_INFO, '[TMDBService] ← HTTP ' . $response->getStatusCode() . ' ' . $trendingUrl);

            $data = json_decode(
                $response->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $results = $data['results'] ?? [];

            // Нормализуем результаты
            $topics = array_map(function (array $item): array {
                return [
                    'id'            => (int)($item['id'] ?? 0),
                    'title'         => $item['title'] ?? $item['name'] ?? '',
                    'media_type'    => $item['media_type'] ?? 'unknown',
                    'overview'      => $item['overview'] ?? '',
                    'poster_path'   => $item['poster_path'] ?? null,
                    'backdrop_path' => $item['backdrop_path'] ?? null,
                    'vote_average'  => (float)($item['vote_average'] ?? 0),
                    'release_date'  => $item['release_date']
                        ?? $item['first_air_date']
                        ?? '',
                    'popularity'    => (float)($item['popularity'] ?? 0),
                ];
            }, $results);

            // ── Кешируем ─────────────────────────────────────
            $this->setCache($cacheKey, $topics, self::TTL_TRENDING);

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                '[TMDBService] fetchTrendingTopics: fetched '
                . count($topics) . ' items'
            );

            return array_slice($topics, 0, $limit);

        } catch (GuzzleException $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[TMDBService] fetchTrendingTopics HTTP error: ' . $e->getMessage()
            );
            return [];
        } catch (\JsonException $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[TMDBService] fetchTrendingTopics JSON error: ' . $e->getMessage()
            );
            return [];
        }
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A.2) Topic Finder — getTrendingTopics                  ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Trending по выбранным типам медиа (movie / tv / person).
     *
     * Если types пустой — вызывает /trending/all/{time_window}.
     * Иначе — отдельный запрос /trending/{type}/{time_window} для каждого типа
     * и объединяет результаты.
     *
     * @param array $opts Опции: types (array), period (day|week), language
     * @return array{success: bool, topics: array<int, array>, error: string|null}
     */
    public function getTrendingTopics(array $opts = []): array
    {
        $timeWindow = in_array((string)($opts['period'] ?? 'week'), ['day', 'week'], true)
            ? (string)$opts['period'] : 'week';
        $language   = !empty($opts['language']) ? (string)$opts['language'] : 'ru-RU';
        $types      = !empty($opts['types']) && is_array($opts['types'])
            ? array_values(array_intersect($opts['types'], ['movie', 'tv', 'person']))
            : [];

        try {
            $items = [];

            if (empty($types)) {
                // No specific type chosen → /trending/all
                $items = $this->fetchTrendingTopics($timeWindow, $language, 50);
            } else {
                // One request per type
                foreach ($types as $type) {
                    $cacheKey = self::CACHE_PREFIX . "trending/{$type}/{$timeWindow}/{$language}";
                    $cached   = $this->getCache($cacheKey);

                    if ($cached !== null) {
                        $items = array_merge($items, $cached);
                        continue;
                    }

                    $endpoint = "/3/trending/{$type}/{$timeWindow}";
                    $logUrl   = $endpoint . '?language=' . urlencode($language);
                    $this->modx->log(modX::LOG_LEVEL_INFO, '[TMDBService] → GET ' . $logUrl);

                    $response = $this->client->get($endpoint, ['query' => ['language' => $language]]);
                    $this->modx->log(modX::LOG_LEVEL_INFO,
                        '[TMDBService] ← HTTP ' . $response->getStatusCode() . ' ' . $logUrl);

                    $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

                    $typeItems = array_map(function (array $item) use ($type): array {
                        return [
                            'id'            => (int)($item['id'] ?? 0),
                            'title'         => $item['title'] ?? $item['name'] ?? '',
                            'media_type'    => $type,
                            'overview'      => $item['overview'] ?? '',
                            'poster_path'   => $item['poster_path'] ?? $item['profile_path'] ?? null,
                            'backdrop_path' => $item['backdrop_path'] ?? null,
                            'vote_average'  => (float)($item['vote_average'] ?? 0),
                            'release_date'  => $item['release_date'] ?? $item['first_air_date'] ?? '',
                            'popularity'    => (float)($item['popularity'] ?? 0),
                        ];
                    }, $data['results'] ?? []);

                    $this->setCache($cacheKey, $typeItems, self::TTL_TRENDING);
                    $items = array_merge($items, $typeItems);
                }
            }

            return [
                'success' => true,
                'topics'  => $this->normalizeItemsToTopics($items, 'tmdb_trends'),
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[TMDBService] getTrendingTopics error: ' . $e->getMessage());
            return ['success' => false, 'topics' => [], 'error' => $e->getMessage()];
        }
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A.3) Topic Finder — getUpcomingTopics                  ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Предстоящие фильмы /movie/upcoming.
     *
     * @param array $opts Опции: language, region
     * @return array{success: bool, topics: array<int, array>, error: string|null}
     */
    public function getUpcomingTopics(array $opts = []): array
    {
        $language = !empty($opts['language']) ? (string)$opts['language'] : 'ru-RU';
        $region   = strtoupper(trim((string)($opts['region'] ?? 'US'))) ?: 'US';

        $cacheKey = self::CACHE_PREFIX . "upcoming/{$language}/{$region}";
        $cached   = $this->getCache($cacheKey);

        try {
            if ($cached !== null) {
                return ['success' => true, 'topics' => $this->normalizeItemsToTopics($cached, 'tmdb_upcoming'), 'error' => null];
            }

            $logUrl = '/3/movie/upcoming?language=' . urlencode($language) . '&region=' . urlencode($region);
            $this->modx->log(modX::LOG_LEVEL_INFO, '[TMDBService] → GET ' . $logUrl);

            $response = $this->client->get('/3/movie/upcoming', [
                'query' => ['language' => $language, 'page' => 1, 'region' => $region],
            ]);
            $this->modx->log(modX::LOG_LEVEL_INFO,
                '[TMDBService] ← HTTP ' . $response->getStatusCode() . ' ' . $logUrl);

            $data  = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $items = array_map(function (array $item): array {
                return [
                    'id'            => (int)($item['id'] ?? 0),
                    'title'         => $item['title'] ?? '',
                    'media_type'    => 'movie',
                    'overview'      => $item['overview'] ?? '',
                    'poster_path'   => $item['poster_path'] ?? null,
                    'backdrop_path' => $item['backdrop_path'] ?? null,
                    'vote_average'  => (float)($item['vote_average'] ?? 0),
                    'release_date'  => $item['release_date'] ?? '',
                    'popularity'    => (float)($item['popularity'] ?? 0),
                ];
            }, $data['results'] ?? []);

            $this->setCache($cacheKey, $items, self::TTL_TRENDING);

            return [
                'success' => true,
                'topics'  => $this->normalizeItemsToTopics($items, 'tmdb_upcoming'),
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[TMDBService] getUpcomingTopics error: ' . $e->getMessage());
            return ['success' => false, 'topics' => [], 'error' => $e->getMessage()];
        }
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A.4) Topic Finder — aggregateForTopicFinder (legacy)   ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Нормализовать массив raw TMDB items в формат TopicFinder topics.
     *
     * @param array  $items  Raw items (id, title, media_type, overview, ...)
     * @param string $source Source label ('tmdb_trends' | 'tmdb_upcoming')
     * @return array<int, array>
     */
    private function normalizeItemsToTopics(array $items, string $source): array
    {
        $topics = [];
        foreach ($items as $item) {
            $title = trim((string)($item['title'] ?? ''));
            if (empty($title)) {
                continue;
            }

            $mediaType   = (string)($item['media_type'] ?? 'movie');
            $tmdbId      = (int)($item['id'] ?? 0);
            $releaseDate = (string)($item['release_date'] ?? '');

            $tmdbUrl = match ($mediaType) {
                'tv'     => "https://www.themoviedb.org/tv/{$tmdbId}",
                'person' => "https://www.themoviedb.org/person/{$tmdbId}",
                default  => "https://www.themoviedb.org/movie/{$tmdbId}",
            };

            $category = match ($mediaType) {
                'tv'     => 'tv-shows',
                'person' => 'celebrities',
                default  => 'movies',
            };

            $publishedAt = '';
            if (!empty($releaseDate)) {
                try {
                    $publishedAt = (new \DateTimeImmutable($releaseDate))->format('Y-m-d\TH:i:s\Z');
                } catch (\Throwable) {
                    $publishedAt = $releaseDate;
                }
            }

            $topics[] = [
                'id'           => $source . '_' . $mediaType . '_' . $tmdbId,
                'source'       => $source,
                'title'        => $title,
                'url'          => $tmdbUrl,
                'description'  => (string)($item['overview'] ?? ''),
                'category'     => $category,
                'published_at' => $publishedAt,
                'score'        => 0.0,
                'metadata'     => [
                    'tmdb_id'       => $tmdbId,
                    'media_type'    => $mediaType,
                    'vote_average'  => (float)($item['vote_average'] ?? 0),
                    'popularity'    => (float)($item['popularity'] ?? 0),
                    'poster_path'   => self::buildImageUrl($item['poster_path'] ?? null, 'w342'),
                    'backdrop_path' => self::buildImageUrl($item['backdrop_path'] ?? null, 'w780'),
                ],
            ];
        }
        return $topics;
    }

    /**
     * Агрегировать трендовые темы из TMDB для TopicFinder (legacy — single source).
     *
     * @param array $opts Опции: period, language, types, include_upcoming
     * @return array{success: bool, topics: array<int, array>, error: string|null}
     */
    public function aggregateForTopicFinder(array $opts = []): array
    {
        $timeWindow      = in_array((string)($opts['period'] ?? 'week'), ['day', 'week'], true)
            ? (string)$opts['period']
            : 'week';
        $language        = !empty($opts['language']) ? (string)$opts['language'] : 'ru-RU';
        $types           = !empty($opts['types']) ? (array)$opts['types'] : ['movie', 'tv'];
        $includeUpcoming = (bool)($opts['include_upcoming'] ?? false);
        $upcomingRegion  = strtoupper(trim((string)($opts['upcoming_region'] ?? 'US'))) ?: 'US';

        try {
            $trending = $this->fetchTrendingTopics($timeWindow, $language, 50);

            // Filter by requested media types
            if (!empty($types)) {
                $trending = array_values(array_filter(
                    $trending,
                    fn(array $item): bool => in_array($item['media_type'] ?? 'movie', $types, true)
                ));
            }

            // Upcoming movies (appended to trending, filtered above if 'movie' not in types)
            if ($includeUpcoming && in_array('movie', $types, true)) {
                try {
                    $upcomingUrl = '/3/movie/upcoming?language=' . urlencode($language) . '&page=1&region=' . urlencode($upcomingRegion);
                    $this->modx->log(modX::LOG_LEVEL_INFO, '[TMDBService] → GET ' . $upcomingUrl);
                    $response = $this->client->get('/3/movie/upcoming', [
                        'query' => ['language' => $language, 'page' => 1, 'region' => $upcomingRegion],
                    ]);
                    $this->modx->log(modX::LOG_LEVEL_INFO, '[TMDBService] ← HTTP ' . $response->getStatusCode() . ' ' . $upcomingUrl);
                    $upData = json_decode(
                        $response->getBody()->getContents(),
                        true, 512, JSON_THROW_ON_ERROR
                    );
                    foreach ($upData['results'] ?? [] as $item) {
                        $trending[] = [
                            'id'            => (int)($item['id'] ?? 0),
                            'title'         => $item['title'] ?? '',
                            'media_type'    => 'movie',
                            'overview'      => $item['overview'] ?? '',
                            'poster_path'   => $item['poster_path'] ?? null,
                            'backdrop_path' => $item['backdrop_path'] ?? null,
                            'vote_average'  => (float)($item['vote_average'] ?? 0),
                            'release_date'  => $item['release_date'] ?? '',
                            'popularity'    => (float)($item['popularity'] ?? 0),
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->modx->log(
                        modX::LOG_LEVEL_WARN,
                        '[TMDBService] fetchUpcomingMovies error: ' . $e->getMessage()
                    );
                }
            }

            if (empty($trending)) {
                $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TMDBService] aggregateForTopicFinder: нет трендовых тем');
                return [
                    'success' => true,
                    'topics'  => [],
                    'error'   => null,
                ];
            }

            $topics = [];

            foreach ($trending as $item) {
                $title = trim((string)($item['title'] ?? ''));
                if (empty($title)) {
                    continue;
                }

                $mediaType   = (string)($item['media_type'] ?? 'movie');
                $tmdbId      = (int)($item['id'] ?? 0);
                $releaseDate = (string)($item['release_date'] ?? '');

                // Формируем URL на TMDB
                $tmdbUrl = match ($mediaType) {
                    'tv'     => "https://www.themoviedb.org/tv/{$tmdbId}",
                    'person' => "https://www.themoviedb.org/person/{$tmdbId}",
                    default  => "https://www.themoviedb.org/movie/{$tmdbId}",
                };

                // Категория по типу контента
                $category = match ($mediaType) {
                    'tv'     => 'tv-shows',
                    'person' => 'celebrities',
                    default  => 'movies',
                };

                // Нормализуем published_at в ISO 8601
                $publishedAt = '';
                if (!empty($releaseDate)) {
                    try {
                        $publishedAt = (new \DateTimeImmutable($releaseDate))->format('Y-m-d\TH:i:s\Z');
                    } catch (\Throwable) {
                        $publishedAt = $releaseDate;
                    }
                }

                $topics[] = [
                    'id'           => 'tmdb_' . $mediaType . '_' . $tmdbId,
                    'source'       => 'tmdb',
                    'title'        => $title,
                    'url'          => $tmdbUrl,
                    'description'  => (string)($item['overview'] ?? ''),
                    'category'     => $category,
                    'published_at' => $publishedAt,
                    'score'        => 0.0, // рассчитывается TopicScoringService
                    'metadata'     => [
                        'tmdb_id'       => $tmdbId,
                        'media_type'    => $mediaType,
                        'vote_average'  => (float)($item['vote_average'] ?? 0),
                        'popularity'    => (float)($item['popularity'] ?? 0),
                        'poster_path'   => self::buildImageUrl($item['poster_path'] ?? null, 'w342'),
                        'backdrop_path' => self::buildImageUrl($item['backdrop_path'] ?? null, 'w780'),
                    ],
                ];
            }

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                '[TMDBService] aggregateForTopicFinder: ' . count($topics) . ' тем'
            );

            return [
                'success' => true,
                'topics'  => $topics,
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[TMDBService] aggregateForTopicFinder error: ' . $e->getMessage()
            );

            return [
                'success' => false,
                'topics'  => [],
                'error'   => $e->getMessage(),
            ];
        }
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Search                    ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Поиск фильмов по названию.
     *
     * Endpoint: GET /search/movie
     *
     * @param string   $query    Поисковый запрос (название фильма)
     * @param int|null $year     Год выпуска для фильтрации (опционально)
     * @param string   $language Язык результатов
     *
     * @return array<int, array{
     *   id: int,
     *   title: string,
     *   original_title: string,
     *   release_date: string,
     *   poster_path: string|null,
     *   overview: string,
     *   vote_average: float,
     *   popularity: float
     * }>
     */
    public function searchMovies(
        string $query,
        ?int $year = null,
        string $language = 'ru-RU'
    ): array {
        if (empty(trim($query))) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[TMDBService] searchMovies: empty query'
            );
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'search/movie/'
            . md5($query . '|' . ($year ?? '') . '|' . $language);

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $params = [
            'query'    => $query,
            'language' => $language,
        ];
        if ($year !== null) {
            $params['year'] = $year;
        }

        $data = $this->request('GET', '/search/movie', $params);
        if ($data === null) {
            return [];
        }

        $results = array_map(function (array $item): array {
            return [
                'id'             => (int)($item['id'] ?? 0),
                'title'          => $item['title'] ?? '',
                'original_title' => $item['original_title'] ?? '',
                'release_date'   => $item['release_date'] ?? '',
                'poster_path'    => $item['poster_path'] ?? null,
                'overview'       => $item['overview'] ?? '',
                'vote_average'   => (float)($item['vote_average'] ?? 0),
                'popularity'     => (float)($item['popularity'] ?? 0),
            ];
        }, $data['results'] ?? []);

        $this->setCache($cacheKey, $results, self::TTL_SEARCH);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] searchMovies: "' . $query . '" → '
            . count($results) . ' results'
        );

        return $results;
    }

    /**
     * Поиск сериалов по названию.
     *
     * Endpoint: GET /search/tv
     *
     * @param string   $query    Поисковый запрос (название сериала)
     * @param int|null $year     Год первого выпуска для фильтрации (опционально)
     * @param string   $language Язык результатов
     *
     * @return array<int, array{
     *   id: int,
     *   name: string,
     *   original_name: string,
     *   first_air_date: string,
     *   poster_path: string|null,
     *   overview: string,
     *   vote_average: float,
     *   popularity: float
     * }>
     */
    public function searchTVShows(
        string $query,
        ?int $year = null,
        string $language = 'ru-RU'
    ): array {
        if (empty(trim($query))) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[TMDBService] searchTVShows: empty query'
            );
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'search/tv/'
            . md5($query . '|' . ($year ?? '') . '|' . $language);

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $params = [
            'query'    => $query,
            'language' => $language,
        ];
        if ($year !== null) {
            $params['first_air_date_year'] = $year;
        }

        $data = $this->request('GET', '/search/tv', $params);
        if ($data === null) {
            return [];
        }

        $results = array_map(function (array $item): array {
            return [
                'id'             => (int)($item['id'] ?? 0),
                'name'           => $item['name'] ?? '',
                'original_name'  => $item['original_name'] ?? '',
                'first_air_date' => $item['first_air_date'] ?? '',
                'poster_path'    => $item['poster_path'] ?? null,
                'overview'       => $item['overview'] ?? '',
                'vote_average'   => (float)($item['vote_average'] ?? 0),
                'popularity'     => (float)($item['popularity'] ?? 0),
            ];
        }, $data['results'] ?? []);

        $this->setCache($cacheKey, $results, self::TTL_SEARCH);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] searchTVShows: "' . $query . '" → '
            . count($results) . ' results'
        );

        return $results;
    }

    /**
     * Поиск персон (актёры, режиссёры и т.д.) по имени.
     *
     * Endpoint: GET /search/person
     *
     * @param string $query    Поисковый запрос (имя персоны)
     * @param string $language Язык результатов
     *
     * @return array<int, array{
     *   id: int,
     *   name: string,
     *   known_for_department: string,
     *   popularity: float,
     *   profile_path: string|null
     * }>
     */
    public function searchPerson(
        string $query,
        string $language = 'ru-RU'
    ): array {
        if (empty(trim($query))) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[TMDBService] searchPerson: empty query'
            );
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'search/person/'
            . md5($query . '|' . $language);

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->request('GET', '/search/person', [
            'query'    => $query,
            'language' => $language,
        ]);
        if ($data === null) {
            return [];
        }

        $results = array_map(function (array $item): array {
            return [
                'id'                   => (int)($item['id'] ?? 0),
                'name'                 => $item['name'] ?? '',
                'known_for_department' => $item['known_for_department'] ?? '',
                'popularity'           => (float)($item['popularity'] ?? 0),
                'profile_path'         => $item['profile_path'] ?? null,
            ];
        }, $data['results'] ?? []);

        $this->setCache($cacheKey, $results, self::TTL_SEARCH);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] searchPerson: "' . $query . '" → '
            . count($results) . ' results'
        );

        return $results;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Details                   ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить полную информацию о фильме.
     *
     * Endpoint: GET /movie/{movie_id}
     *
     * Возвращает все основные поля: backdrop_path, genres, homepage,
     * id, imdb_id, original_language, original_title, overview,
     * poster_path, production_companies, production_countries,
     * release_date, runtime, status, tagline, title, vote_average.
     *
     * @param int    $movieId  ID фильма в TMDB
     * @param string $language Язык результатов
     *
     * @return array{
     *   id: int,
     *   imdb_id: string|null,
     *   title: string,
     *   original_title: string,
     *   original_language: string,
     *   tagline: string,
     *   overview: string,
     *   release_date: string,
     *   runtime: int|null,
     *   status: string,
     *   homepage: string|null,
     *   poster_path: string|null,
     *   backdrop_path: string|null,
     *   vote_average: float,
     *   vote_count: int,
     *   popularity: float,
     *   genres: array<int, array{id: int, name: string}>,
     *   production_companies: array<int, array{id: int, name: string, logo_path: string|null, origin_country: string}>,
     *   production_countries: array<int, array{iso_3166_1: string, name: string}>
     * }|array{}
     */
    public function getMovieDetails(int $movieId, string $language = 'ru-RU'): array
    {
        if ($movieId <= 0) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[TMDBService] getMovieDetails: invalid movieId=' . $movieId
            );
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'movie/' . $movieId . '/' . $language;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->request('GET', '/movie/' . $movieId, [
            'language' => $language,
        ]);

        if ($data === null) {
            return [];
        }

        $result = [
            'id'                   => (int)($data['id'] ?? 0),
            'imdb_id'              => $data['imdb_id'] ?? null,
            'title'                => $data['title'] ?? '',
            'original_title'       => $data['original_title'] ?? '',
            'original_language'    => $data['original_language'] ?? '',
            'tagline'              => $data['tagline'] ?? '',
            'overview'             => $data['overview'] ?? '',
            'release_date'         => $data['release_date'] ?? '',
            'runtime'              => isset($data['runtime']) ? (int)$data['runtime'] : null,
            'status'               => $data['status'] ?? '',
            'homepage'             => $data['homepage'] ?? null,
            'poster_path'          => $data['poster_path'] ?? null,
            'backdrop_path'        => $data['backdrop_path'] ?? null,
            'vote_average'         => (float)($data['vote_average'] ?? 0),
            'vote_count'           => (int)($data['vote_count'] ?? 0),
            'popularity'           => (float)($data['popularity'] ?? 0),
            'genres'               => $data['genres'] ?? [],
            'production_companies' => $data['production_companies'] ?? [],
            'production_countries' => $data['production_countries'] ?? [],
        ];

        $this->setCache($cacheKey, $result, self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] getMovieDetails: id=' . $movieId
            . ' → "' . $result['title'] . '"'
        );

        return $result;
    }

    /**
     * Получить полную информацию о сериале.
     *
     * Endpoint: GET /tv/{tv_id}
     *
     * @param int    $tvId     ID сериала в TMDB
     * @param string $language Язык результатов
     *
     * @return array{
     *   id: int,
     *   name: string,
     *   original_name: string,
     *   original_language: string,
     *   tagline: string,
     *   overview: string,
     *   first_air_date: string,
     *   last_air_date: string|null,
     *   status: string,
     *   type: string,
     *   number_of_seasons: int,
     *   number_of_episodes: int,
     *   episode_run_time: array<int>,
     *   homepage: string|null,
     *   poster_path: string|null,
     *   backdrop_path: string|null,
     *   vote_average: float,
     *   vote_count: int,
     *   popularity: float,
     *   genres: array<int, array{id: int, name: string}>,
     *   production_companies: array<int, array{id: int, name: string, logo_path: string|null, origin_country: string}>,
     *   networks: array<int, array{id: int, name: string, logo_path: string|null}>
     * }|array{}
     */
    public function getTVShowDetails(int $tvId, string $language = 'ru-RU'): array
    {
        if ($tvId <= 0) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[TMDBService] getTVShowDetails: invalid tvId=' . $tvId
            );
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'tv/' . $tvId . '/' . $language;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->request('GET', '/tv/' . $tvId, [
            'language' => $language,
        ]);

        if ($data === null) {
            return [];
        }

        $result = [
            'id'                   => (int)($data['id'] ?? 0),
            'name'                 => $data['name'] ?? '',
            'original_name'        => $data['original_name'] ?? '',
            'original_language'    => $data['original_language'] ?? '',
            'tagline'              => $data['tagline'] ?? '',
            'overview'             => $data['overview'] ?? '',
            'first_air_date'       => $data['first_air_date'] ?? '',
            'last_air_date'        => $data['last_air_date'] ?? null,
            'status'               => $data['status'] ?? '',
            'type'                 => $data['type'] ?? '',
            'number_of_seasons'    => (int)($data['number_of_seasons'] ?? 0),
            'number_of_episodes'   => (int)($data['number_of_episodes'] ?? 0),
            'episode_run_time'     => $data['episode_run_time'] ?? [],
            'homepage'             => $data['homepage'] ?? null,
            'poster_path'          => $data['poster_path'] ?? null,
            'backdrop_path'        => $data['backdrop_path'] ?? null,
            'vote_average'         => (float)($data['vote_average'] ?? 0),
            'vote_count'           => (int)($data['vote_count'] ?? 0),
            'popularity'           => (float)($data['popularity'] ?? 0),
            'genres'               => $data['genres'] ?? [],
            'production_companies' => $data['production_companies'] ?? [],
            'networks'             => $data['networks'] ?? [],
            'created_by'           => array_map(function (array $c): array {
                return ['id' => (int)($c['id'] ?? 0), 'name' => $c['name'] ?? ''];
            }, $data['created_by'] ?? []),
            'seasons'              => array_map(function (array $s): array {
                return [
                    'season_number' => (int)($s['season_number'] ?? 0),
                    'name'          => $s['name'] ?? '',
                    'episode_count' => (int)($s['episode_count'] ?? 0),
                    'air_date'      => $s['air_date'] ?? '',
                    'poster_path'   => $s['poster_path'] ?? null,
                    'overview'      => $s['overview'] ?? '',
                ];
            }, $data['seasons'] ?? []),
            'last_episode_to_air'  => !empty($data['last_episode_to_air']) ? [
                'name'           => $data['last_episode_to_air']['name'] ?? '',
                'episode_number' => (int)($data['last_episode_to_air']['episode_number'] ?? 0),
                'season_number'  => (int)($data['last_episode_to_air']['season_number'] ?? 0),
                'runtime'        => $data['last_episode_to_air']['runtime'] ?? null,
            ] : null,
            'next_episode_to_air'  => !empty($data['next_episode_to_air']) ? [
                'name'           => $data['next_episode_to_air']['name'] ?? '',
                'episode_number' => (int)($data['next_episode_to_air']['episode_number'] ?? 0),
                'season_number'  => (int)($data['next_episode_to_air']['season_number'] ?? 0),
            ] : null,
            'origin_country'       => $data['origin_country'] ?? [],
        ];

        $this->setCache($cacheKey, $result, self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] getTVShowDetails: id=' . $tvId
            . ' → "' . $result['name'] . '"'
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Credits                   ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить актёрский и съёмочный состав фильма.
     *
     * Endpoint: GET /movie/{movie_id}/credits
     *
     * @param int $movieId ID фильма в TMDB
     *
     * @return array{
     *   cast: array<int, array{
     *     id: int,
     *     name: string,
     *     character: string,
     *     profile_path: string|null,
     *     order: int,
     *     known_for_department: string
     *   }>,
     *   crew: array<int, array{
     *     id: int,
     *     name: string,
     *     job: string,
     *     department: string,
     *     profile_path: string|null
     *   }>
     * }
     */
    public function getMovieCredits(int $movieId): array
    {
        return $this->getCredits('movie', $movieId);
    }

    /**
     * Получить актёрский и съёмочный состав сериала.
     *
     * Endpoint: GET /tv/{tv_id}/credits
     *
     * @param int $tvId ID сериала в TMDB
     *
     * @return array{
     *   cast: array<int, array{
     *     id: int,
     *     name: string,
     *     character: string,
     *     profile_path: string|null,
     *     order: int,
     *     known_for_department: string
     *   }>,
     *   crew: array<int, array{
     *     id: int,
     *     name: string,
     *     job: string,
     *     department: string,
     *     profile_path: string|null
     *   }>
     * }
     */
    public function getTVShowCredits(int $tvId): array
    {
        return $this->getCredits('tv', $tvId);
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Person                    ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить информацию о персоне (актёр, режиссёр и т.д.).
     *
     * Endpoint: GET /person/{person_id}
     *
     * @param int    $personId ID персоны в TMDB
     * @param string $language Язык результатов
     *
     * @return array{
     *   id: int,
     *   name: string,
     *   birthday: string|null,
     *   deathday: string|null,
     *   place_of_birth: string|null,
     *   biography: string,
     *   profile_path: string|null,
     *   imdb_id: string|null,
     *   known_for_department: string,
     *   popularity: float,
     *   gender: int,
     *   also_known_as: array<string>
     * }|array{}
     */
    public function getPersonDetails(int $personId, string $language = 'ru-RU'): array
    {
        if ($personId <= 0) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[TMDBService] getPersonDetails: invalid personId=' . $personId
            );
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'person/' . $personId . '/' . $language;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->request('GET', '/person/' . $personId, [
            'language' => $language,
        ]);

        if ($data === null) {
            return [];
        }

        $result = [
            'id'                   => (int)($data['id'] ?? 0),
            'name'                 => $data['name'] ?? '',
            'birthday'             => $data['birthday'] ?? null,
            'deathday'             => $data['deathday'] ?? null,
            'place_of_birth'       => $data['place_of_birth'] ?? null,
            'biography'            => $data['biography'] ?? '',
            'profile_path'         => $data['profile_path'] ?? null,
            'imdb_id'              => $data['imdb_id'] ?? null,
            'homepage'             => $data['homepage'] ?? null,
            'known_for_department' => $data['known_for_department'] ?? '',
            'popularity'           => (float)($data['popularity'] ?? 0),
            'gender'               => (int)($data['gender'] ?? 0),
            'also_known_as'        => $data['also_known_as'] ?? [],
        ];

        $this->setCache($cacheKey, $result, self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] getPersonDetails: id=' . $personId
            . ' → "' . $result['name'] . '"'
        );

        return $result;
    }

    /**
     * Получить фильмографию персоны (фильмы).
     *
     * Endpoint: GET /person/{person_id}/movie_credits
     *
     * @param int    $personId ID персоны в TMDB
     * @param string $language Язык результатов
     *
     * @return array{
     *   cast: array<int, array{
     *     id: int,
     *     title: string,
     *     character: string,
     *     release_date: string,
     *     poster_path: string|null,
     *     vote_average: float
     *   }>,
     *   crew: array<int, array{
     *     id: int,
     *     title: string,
     *     job: string,
     *     department: string,
     *     release_date: string,
     *     poster_path: string|null
     *   }>
     * }
     */
    public function getPersonMovieCredits(int $personId, string $language = 'ru-RU'): array
    {
        if ($personId <= 0) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[TMDBService] getPersonMovieCredits: invalid personId=' . $personId
            );
            return ['cast' => [], 'crew' => []];
        }

        $cacheKey = self::CACHE_PREFIX . 'person/' . $personId . '/movie_credits/' . $language;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->request('GET', '/person/' . $personId . '/movie_credits', [
            'language' => $language,
        ]);

        if ($data === null) {
            return ['cast' => [], 'crew' => []];
        }

        $result = [
            'cast' => array_map(function (array $item): array {
                return [
                    'id'           => (int)($item['id'] ?? 0),
                    'title'        => $item['title'] ?? '',
                    'character'    => $item['character'] ?? '',
                    'release_date' => $item['release_date'] ?? '',
                    'poster_path'  => $item['poster_path'] ?? null,
                    'vote_average' => (float)($item['vote_average'] ?? 0),
                    'popularity'   => (float)($item['popularity'] ?? 0),
                ];
            }, $data['cast'] ?? []),
            'crew' => array_map(function (array $item): array {
                return [
                    'id'           => (int)($item['id'] ?? 0),
                    'title'        => $item['title'] ?? '',
                    'job'          => $item['job'] ?? '',
                    'department'   => $item['department'] ?? '',
                    'release_date' => $item['release_date'] ?? '',
                    'poster_path'  => $item['poster_path'] ?? null,
                ];
            }, $data['crew'] ?? []),
        ];

        $this->setCache($cacheKey, $result, self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_ERROR,
            '[TMDBService] getPersonMovieCredits: personId=' . $personId
            . ' → cast: ' . count($result['cast'])
            . ', crew: ' . count($result['crew'])
        );
        return $result;
    }

    /**
     * Получить сериалы персоны.
     *
     * Endpoint: GET /person/{person_id}/tv_credits
     *
     * @param int    $personId ID персоны в TMDB
     * @param string $language Язык результатов
     *
     * @return array{
     *   cast: array<int, array{
     *     id: int,
     *     name: string,
     *     character: string,
     *     first_air_date: string,
     *     poster_path: string|null,
     *     vote_average: float,
     *     episode_count: int
     *   }>,
     *   crew: array<int, array{
     *     id: int,
     *     name: string,
     *     job: string,
     *     department: string,
     *     first_air_date: string,
     *     poster_path: string|null,
     *     episode_count: int
     *   }>
     * }
     */
    public function getPersonTVCredits(int $personId, string $language = 'ru-RU'): array
    {
        if ($personId <= 0) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[TMDBService] getPersonTVCredits: invalid personId=' . $personId
            );
            return ['cast' => [], 'crew' => []];
        }

        $cacheKey = self::CACHE_PREFIX . 'person/' . $personId . '/tv_credits/' . $language;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->request('GET', '/person/' . $personId . '/tv_credits', [
            'language' => $language,
        ]);

        if ($data === null) {
            return ['cast' => [], 'crew' => []];
        }

        $result = [
            'cast' => array_map(function (array $item): array {
                return [
                    'id'             => (int)($item['id'] ?? 0),
                    'name'           => $item['name'] ?? '',
                    'character'      => $item['character'] ?? '',
                    'first_air_date' => $item['first_air_date'] ?? '',
                    'poster_path'    => $item['poster_path'] ?? null,
                    'vote_average'   => (float)($item['vote_average'] ?? 0),
                    'episode_count'  => (int)($item['episode_count'] ?? 0),
                ];
            }, $data['cast'] ?? []),
            'crew' => array_map(function (array $item): array {
                return [
                    'id'             => (int)($item['id'] ?? 0),
                    'name'           => $item['name'] ?? '',
                    'job'            => $item['job'] ?? '',
                    'department'     => $item['department'] ?? '',
                    'first_air_date' => $item['first_air_date'] ?? '',
                    'poster_path'    => $item['poster_path'] ?? null,
                    'episode_count'  => (int)($item['episode_count'] ?? 0),
                ];
            }, $data['crew'] ?? []),
        ];

        $this->setCache($cacheKey, $result, self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] getPersonTVCredits: personId=' . $personId
            . ' → cast: ' . count($result['cast'])
            . ', crew: ' . count($result['crew'])
        );

        return $result;
    }

    /**
     * Получить объединённую фильмографию персоны (фильмы + сериалы).
     *
     * Endpoint: GET /person/{person_id}/combined_credits
     *
     * Каждый элемент содержит поле media_type ('movie' | 'tv').
     *
     * @param int    $personId ID персоны в TMDB
     * @param string $language Язык результатов
     *
     * @return array{
     *   cast: array<int, array{
     *     id: int,
     *     media_type: string,
     *     title: string,
     *     character: string,
     *     date: string,
     *     poster_path: string|null,
     *     vote_average: float,
     *     popularity: float,
     *     episode_count: int|null
     *   }>,
     *   crew: array<int, array{
     *     id: int,
     *     media_type: string,
     *     title: string,
     *     job: string,
     *     department: string,
     *     date: string,
     *     poster_path: string|null,
     *     popularity: float
     *   }>
     * }
     */
    public function getPersonCombinedCredits(int $personId, string $language = 'ru-RU'): array
    {
        if ($personId <= 0) {
            return ['cast' => [], 'crew' => []];
        }

        $cacheKey = self::CACHE_PREFIX . 'person/' . $personId . '/combined_credits/' . $language;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->request('GET', '/person/' . $personId . '/combined_credits', [
            'language' => $language,
        ]);

        if ($data === null) {
            return ['cast' => [], 'crew' => []];
        }

        $result = [
            'cast' => array_map(function (array $item): array {
                $mediaType = $item['media_type'] ?? 'movie';
                return [
                    'id'            => (int)($item['id'] ?? 0),
                    'media_type'    => $mediaType,
                    'title'         => $mediaType === 'tv'
                        ? ($item['name'] ?? '')
                        : ($item['title'] ?? ''),
                    'character'     => $item['character'] ?? '',
                    'date'          => $mediaType === 'tv'
                        ? ($item['first_air_date'] ?? '')
                        : ($item['release_date'] ?? ''),
                    'poster_path'   => $item['poster_path'] ?? null,
                    'vote_average'  => (float)($item['vote_average'] ?? 0),
                    'popularity'    => (float)($item['popularity'] ?? 0),
                    'episode_count' => $mediaType === 'tv'
                        ? (int)($item['episode_count'] ?? 0)
                        : null,
                ];
            }, $data['cast'] ?? []),
            'crew' => array_map(function (array $item): array {
                $mediaType = $item['media_type'] ?? 'movie';
                return [
                    'id'          => (int)($item['id'] ?? 0),
                    'media_type'  => $mediaType,
                    'title'       => $mediaType === 'tv'
                        ? ($item['name'] ?? '')
                        : ($item['title'] ?? ''),
                    'job'         => $item['job'] ?? '',
                    'department'  => $item['department'] ?? '',
                    'date'        => $mediaType === 'tv'
                        ? ($item['first_air_date'] ?? '')
                        : ($item['release_date'] ?? ''),
                    'poster_path' => $item['poster_path'] ?? null,
                    'popularity'  => (float)($item['popularity'] ?? 0),
                ];
            }, $data['crew'] ?? []),
        ];

        $this->setCache($cacheKey, $result, self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] getPersonCombinedCredits: personId=' . $personId
            . ' → cast: ' . count($result['cast'])
            . ', crew: ' . count($result['crew'])
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Person Translations       ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить переводы биографии персоны по всем языкам.
     *
     * Endpoint: GET /person/{person_id}/translations
     *
     * Возвращает только языки с непустой биографией.
     * Ключ — ISO 639-1 код языка ('en', 'ru', 'fr', ...).
     *
     * @param int $personId ID персоны в TMDB
     *
     * @return array<string, string>  ['en' => 'biography text', 'ru' => '...', ...]
     */
    public function getPersonTranslations(int $personId): array
    {
        if ($personId <= 0) {
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'person/' . $personId . '/translations';
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->request('GET', '/person/' . $personId . '/translations');
        if ($data === null) {
            return [];
        }

        // Индексируем биографии по iso_639_1 (пустые пропускаем)
        $result = [];
        foreach ($data['translations'] ?? [] as $t) {
            $langCode = $t['iso_639_1'] ?? '';
            $bio      = trim((string)($t['data']['biography'] ?? ''));
            if ($langCode !== '' && $bio !== '') {
                $result[$langCode] = $bio;
            }
        }

        $this->setCache($cacheKey, $result, self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] getPersonTranslations: personId=' . $personId
            . ' → languages: ' . implode(', ', array_keys($result))
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Person External IDs       ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить внешние идентификаторы персоны и ссылку на Wikipedia.
     *
     * Endpoint: GET /person/{person_id}/external_ids
     *
     * Разрешает wikidata_id в URL Wikipedia через Wikidata API
     * (кешируется отдельно).
     *
     * @param int    $personId      ID персоны в TMDB
     * @param string $wikiLanguage  Код языка Wikipedia ('en' | 'ru' | ...)
     *
     * @return array{
     *   wikidata_id:   string|null,
     *   wikipedia_url: string|null,
     *   imdb_id:       string|null,
     *   facebook_id:   string|null,
     *   instagram_id:  string|null,
     *   twitter_id:    string|null,
     *   tiktok_id:     string|null,
     *   youtube_id:    string|null
     * }
     */
    public function getPersonExternalIds(int $personId, string $wikiLanguage = 'en'): array
    {
        if ($personId <= 0) {
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'person/' . $personId . '/external_ids/' . $wikiLanguage;
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->request('GET', '/person/' . $personId . '/external_ids');
        if ($data === null) {
            return [];
        }

        $wikidataId  = trim((string)($data['wikidata_id'] ?? ''));
        $wikipediaUrl = $wikidataId
            ? $this->wikidataIdToWikipediaUrl($wikidataId, $wikiLanguage)
            : null;

        $result = [
            'wikidata_id'   => $wikidataId ?: null,
            'wikipedia_url' => $wikipediaUrl,
            'imdb_id'       => trim((string)($data['imdb_id'] ?? '')) ?: null,
            'facebook_id'   => trim((string)($data['facebook_id'] ?? '')) ?: null,
            'instagram_id'  => trim((string)($data['instagram_id'] ?? '')) ?: null,
            'twitter_id'    => trim((string)($data['twitter_id'] ?? '')) ?: null,
            'tiktok_id'     => trim((string)($data['tiktok_id'] ?? '')) ?: null,
            'youtube_id'    => trim((string)($data['youtube_id'] ?? '')) ?: null,
        ];

        $this->setCache($cacheKey, $result, self::TTL_CREDITS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] getPersonExternalIds: personId=' . $personId
            . ' wikidata=' . ($result['wikidata_id'] ?? 'none')
            . ' wikipedia=' . ($result['wikipedia_url'] ?? 'none')
        );

        return $result;
    }

    /**
     * Преобразовать Wikidata Q-идентификатор в URL страницы Wikipedia.
     *
     * Использует Wikidata MediaWiki API (/w/api.php) для получения sitelink
     * нужной языковой Википедии. Результат кешируется.
     *
     * @param string $wikidataId  Q-идентификатор (например "Q2263")
     * @param string $language    Код языка ('en', 'ru', ...)
     *
     * @return string|null URL вида "https://en.wikipedia.org/wiki/Tom_Hanks"
     *                     или null, если sitelink не найден
     */
    private function wikidataIdToWikipediaUrl(string $wikidataId, string $language = 'en'): ?string
    {
        if (empty($wikidataId) || !preg_match('/^Q\d+$/i', $wikidataId)) {
            return null;
        }

        $siteKey  = $language . 'wiki'; // e.g. enwiki, ruwiki
        $cacheKey = self::CACHE_PREFIX . 'wikidata/' . $wikidataId . '/' . $language;
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached['url'] ?: null;
        }

        $apiUrl = $this->buildUrl('https://www.wikidata.org/w/api.php', [
            'action'     => 'wbgetentities',
            'ids'        => $wikidataId,
            'props'      => 'sitelinks',
            'sitefilter' => $siteKey,
            'format'     => 'json',
        ]);

        $this->modx->log(modX::LOG_LEVEL_INFO, '[TMDBService] Wikidata API: ' . $wikidataId . ' → ' . $siteKey);

        $result = $this->httpGet($apiUrl, ['Accept: application/json'], 10);

        $url = null;
        if ($result['success'] && is_array($result['data'])) {
            $title = $result['data']['entities'][$wikidataId]['sitelinks'][$siteKey]['title'] ?? null;
            if (!empty($title)) {
                // Пробелы → подчёркивания; специальные символы кодируем
                $url = 'https://' . $language . '.wikipedia.org/wiki/'
                    . str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', $title)));
            }
        }

        // Кешируем пустую строку как маркер "не найдено"
        $this->setCache($cacheKey, ['url' => $url ?? ''], self::TTL_CREDITS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] wikidataIdToWikipediaUrl: ' . $wikidataId . ' → ' . ($url ?? 'null')
        );

        return $url;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Chunk Generator Methods — Age Rating (PG)           ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить возрастной рейтинг фильма.
     *
     * Endpoint: GET /movie/{movie_id}/release_dates
     *
     * Приоритет стран: RU → US → первая доступная.
     * Если у страны несколько релизов — берём тот, у которого есть certification.
     *
     * @param int $movieId ID фильма в TMDB
     *
     * @return string  Строка вида "PG-13" / "R" / "18+" / "16"
     *                 или пустая строка, если рейтинг не найден
     */
    public function getMovieCertification(int $movieId): string
    {
        if ($movieId <= 0) {
            return '';
        }

        $cacheKey = self::CACHE_PREFIX . 'movie/' . $movieId . '/certification';
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null && isset($cached['rating'])) {
            return $cached['rating'];
        }

        $data = $this->request('GET', '/movie/' . $movieId . '/release_dates');
        if ($data === null) {
            return '';
        }

        $results = $data['results'] ?? [];

        // Индексируем по коду страны для быстрого доступа
        $byCountry = [];
        foreach ($results as $entry) {
            $iso = strtoupper((string)($entry['iso_3166_1'] ?? ''));
            if ($iso === '') {
                continue;
            }
            $byCountry[$iso] = $entry['release_dates'] ?? [];
        }

        $rating = '';

        // Ищем сначала RU, затем US
        foreach (['RU', 'US'] as $iso) {
            if (!isset($byCountry[$iso])) {
                continue;
            }
            foreach ($byCountry[$iso] as $rel) {
                $cert = trim((string)($rel['certification'] ?? ''));
                if ($cert !== '') {
                    $rating = $cert;
                    break 2;
                }
            }
        }

        // Если RU/US не нашли — берём первый попавшийся непустой рейтинг
        if ($rating === '') {
            foreach ($byCountry as $iso => $releaseDates) {
                foreach ($releaseDates as $rel) {
                    $cert = trim((string)($rel['certification'] ?? ''));
                    if ($cert !== '') {
                        $rating = $iso . ':' . $cert; // например "DE:16"
                        break 2;
                    }
                }
            }
        }

        $this->setCache($cacheKey, ['rating' => $rating], self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] getMovieCertification: movieId=' . $movieId . ' → "' . $rating . '"'
        );

        return $rating;
    }

    /**
     * Получить возрастной рейтинг сериала.
     *
     * Endpoint: GET /tv/{series_id}/content_ratings
     *
     * Приоритет стран: RU → US → первая доступная.
     *
     * @param int $tvId ID сериала в TMDB
     *
     * @return string  Строка вида "16+" / "TV-MA" / "12"
     *                 или пустая строка, если рейтинг не найден
     */
    public function getTVCertification(int $tvId): string
    {
        if ($tvId <= 0) {
            return '';
        }

        $cacheKey = self::CACHE_PREFIX . 'tv/' . $tvId . '/certification';
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null && isset($cached['rating'])) {
            return $cached['rating'];
        }

        $data = $this->request('GET', '/tv/' . $tvId . '/content_ratings');
        if ($data === null) {
            return '';
        }

        $results = $data['results'] ?? [];

        // Индексируем по коду страны
        $byCountry = [];
        foreach ($results as $entry) {
            $iso    = strtoupper((string)($entry['iso_3166_1'] ?? ''));
            $rating = trim((string)($entry['rating'] ?? ''));
            if ($iso !== '' && $rating !== '') {
                $byCountry[$iso] = $rating;
            }
        }

        $rating = '';

        // Ищем RU, затем US
        foreach (['RU', 'US'] as $iso) {
            if (isset($byCountry[$iso]) && $byCountry[$iso] !== '') {
                $rating = $byCountry[$iso];
                break;
            }
        }

        // Fallback — первый доступный
        if ($rating === '' && !empty($byCountry)) {
            $firstIso = array_key_first($byCountry);
            $rating   = $firstIso . ':' . $byCountry[$firstIso];
        }

        $this->setCache($cacheKey, ['rating' => $rating], self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] getTVCertification: tvId=' . $tvId . ' → "' . $rating . '"'
        );

        return $rating;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Utility: Image URL Builder                             ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Собрать полный URL изображения из TMDB path.
     *
     * Размеры:
     * - poster:   w92, w154, w185, w342, w500, w780, original
     * - backdrop: w300, w780, w1280, original
     * - profile:  w45, w185, h632, original
     *
     * @param string|null $path TMDB image path (e.g. "/nBNZadXqJSdt05SHLqgT0HuC5Gm.jpg")
     * @param string      $size Размер изображения
     *
     * @return string|null Полный URL или null если path пуст
     */
    public static function buildImageUrl(?string $path, string $size = 'w500'): ?string
    {
        if (empty($path)) {
            return null;
        }

        return self::IMAGE_BASE_URL . $size . $path;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: HTTP Request                                  ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Выполнить HTTP-запрос к TMDB API.
     *
     * Единая точка для всех запросов — обеспечивает:
     * - Единообразную обработку ошибок
     * - Логирование
     * - JSON-парсинг
     *
     * @param string $method HTTP-метод (GET, POST)
     * @param string $uri    Относительный URI (e.g. '/3/movie/550')
     * @param array  $params Query-параметры
     *
     * @return array|null Декодированный JSON или null при ошибке
     */
    private function request(string $method, string $uri, array $params = []): ?array
    {
        $fullUrl = $this->buildUrl(self::BASE_URL . $uri, $params);
        $this->modx->log(modX::LOG_LEVEL_ERROR, '[TMDBService] ' . $method . ' ' . $fullUrl);
        $result = $this->httpGet(
            $fullUrl,
            ['Authorization: Bearer ' . $this->apiToken, 'Accept: application/json'],
            15
        );
        if (!$result['success']) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[TMDBService] HTTP error for ' . $uri . ': ' . ($result['error'] ?? ''));
            return null;
        }
        return is_array($result['data']) ? $result['data'] : null;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Credits (shared logic for movie/tv)           ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить credits для фильма или сериала.
     *
     * Общая логика для getMovieCredits() и getTVShowCredits().
     *
     * @param string $type 'movie' | 'tv'
     * @param int    $id   ID в TMDB
     *
     * @return array{cast: array, crew: array}
     */
    private function getCredits(string $type, int $id): array
    {
        $empty = ['cast' => [], 'crew' => []];

        if ($id <= 0) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[TMDBService] getCredits: invalid id=' . $id
                . ' for type=' . $type
            );
            return $empty;
        }

        $cacheKey = self::CACHE_PREFIX . $type . '/' . $id . '/credits';

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->request('GET', '/' . $type . '/' . $id . '/credits');
        if ($data === null) {
            return $empty;
        }

        $result = [
            'cast' => array_map(function (array $item): array {
                return [
                    'id'                   => (int)($item['id'] ?? 0),
                    'name'                 => $item['name'] ?? '',
                    'original_name'        => $item['original_name'] ?? '',
                    'character'            => $item['character'] ?? '',
                    'profile_path'         => $item['profile_path'] ?? null,
                    'order'                => (int)($item['order'] ?? 999),
                    'known_for_department' => $item['known_for_department'] ?? '',
                    'popularity'           => (float)($item['popularity'] ?? 0),
                ];
            }, $data['cast'] ?? []),
            'crew' => array_map(function (array $item): array {
                return [
                    'id'            => (int)($item['id'] ?? 0),
                    'name'          => $item['name'] ?? '',
                    'original_name' => $item['original_name'] ?? '',
                    'job'           => $item['job'] ?? '',
                    'department'    => $item['department'] ?? '',
                    'profile_path'  => $item['profile_path'] ?? null,
                    'popularity'    => (float)($item['popularity'] ?? 0),
                ];
            }, $data['crew'] ?? []),
        ];

        $this->setCache($cacheKey, $result, self::TTL_CREDITS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TMDBService] getCredits: ' . $type . '/' . $id
            . ' → cast: ' . count($result['cast'])
            . ', crew: ' . count($result['crew'])
        );

        return $result;
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