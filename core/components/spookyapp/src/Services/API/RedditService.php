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
use Throwable;

/**
 * Сервис для получения данных из Reddit.
 *
 * Работает с двумя источниками:
 * 1. Официальный Reddit JSON API (без авторизации, rate-limited)
 * 2. Reddit34 RapidAPI (с авторизацией, fallback)
 *
 * При недоступности официального API автоматически переключается на Reddit34.
 * Конфигурация subreddit'ов загружается из config/subreddits.php.
 */
class RedditService extends APIService
{
    // ─── Официальный Reddit API ──────────────────────────────────
    private const REDDIT_BASE_URL = 'https://www.reddit.com';
    // Reddit requires: platform:app_id:version (by /u/username) — browser UAs are banned
    private const REDDIT_USER_AGENT = 'php:com.spookyapp.topicfinder:v1.0 (by /u/spookyru)';

    // ─── Reddit34 RapidAPI ───────────────────────────────────────
    private const REDDIT34_KEY_SETTING = 'spookyapp.reddit34_rapidapi_key';
    private const REDDIT34_HOST = 'reddit34.p.rapidapi.com';
    private const REDDIT34_BASE_URL = 'https://reddit34.p.rapidapi.com';

    // ─── TTL кеша (в секундах) ───────────────────────────────────
    private const TTL_SUBREDDIT_POSTS = 1800;   // 30 минут
    private const TTL_TOP_ALL = 3600;            // 1 час
    private const TTL_RISING = 900;              // 15 минут
    private const TTL_POPULAR = 1800;            // 30 минут

    // ─── Reddit Hot Score ────────────────────────────────────────
    private const REDDIT_EPOCH = 1134028003;     // 2005-12-08T07:46:43Z

    // ─── Допустимые значения сортировки ──────────────────────────
    private const VALID_SORTS = ['hot', 'new', 'top', 'rising', 'controversial'];
    private const VALID_TIME_FILTERS = ['hour', 'day', 'week', 'month', 'year', 'all'];

    // ─── Конфигурация subreddit'ов ───────────────────────────────

    /** @var array<string, array{category: string, priority: int}> */
    private array $subredditsConfig = [];

    public function __construct(modX $modx, CacheService $cache)
    {
        parent::__construct($modx, $cache);
        $this->loadSubredditsConfig();
    }

    // ═════════════════════════════════════════════════════════════
    // 1. Посты из конкретного subreddit'а
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить посты из конкретного subreddit'а.
     *
     * Сначала пробует официальный Reddit JSON API.
     * При ошибке (rate limit, 429, timeout) — fallback на Reddit34 RapidAPI.
     *
     * @param string $subreddit Название subreddit'а (без r/)
     * @param string $sort      Сортировка: hot, new, top, rising, controversial
     * @param string $time      Период для top/controversial: hour, day, week, month, year, all
     * @param int    $limit     Макс. количество постов (по умолчанию 25, макс. 100)
     * @return array{success: bool, posts: array<int, array>, source: string, error: string|null}
     */
    public function getSubredditPosts(
        string $subreddit,
        string $sort = 'hot',
        string $time = 'day',
        int $limit = 25
    ): array {
        $subreddit = $this->sanitizeSubreddit($subreddit);
        $sort = $this->validateSort($sort);
        $time = $this->validateTimeFilter($time);
        $limit = min(max($limit, 1), 100);

        $cacheKey = "reddit_sub_{$subreddit}_{$sort}_{$time}_{$limit}";

        $result = $this->cachedRequest($cacheKey, self::TTL_SUBREDDIT_POSTS, function () use ($subreddit, $sort, $time, $limit): ?array {
            // Попытка 1: Reddit34 RapidAPI (primary — официальный API блокирует серверные запросы)
            $posts = $this->fetchFromReddit34Subreddit($subreddit, $sort, $time, $limit);
            if ($posts !== null) {
                return ['posts' => $posts, 'source' => 'reddit34'];
            }

            // Попытка 2: Fallback на официальный Reddit API
            $this->modx->log(modX::LOG_LEVEL_WARN, "[RedditService] Reddit34 недоступен для r/{$subreddit}, переключение на официальный API");
            $posts = $this->fetchFromOfficialAPI($subreddit, $sort, $time, $limit);
            if ($posts !== null) {
                return ['posts' => $posts, 'source' => 'reddit-official'];
            }

            return null;
        });

        if ($result === null) {
            return $this->errorResponse("Не удалось получить посты из r/{$subreddit}");
        }

        return [
            'success' => true,
            'posts'   => $result['posts'],
            'source'  => $result['source'],
            'error'   => null,
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // 2. Топ посты из ВСЕХ subreddit'ов
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить топ-посты из всех subreddit'ов из конфигурации.
     *
     * Агрегирует посты из всех настроенных subreddit'ов,
     * сортирует по upvotes (убывание), добавляет категорию и приоритет.
     *
     * @param string $time        Период: hour, day, week, month, year, all
     * @param int    $limitPerSub Макс. постов на один subreddit
     * @return array{
     *     success: bool,
     *     posts: array<int, array>,
     *     subreddits_status: array<string, array{success: bool, count: int, error: string|null}>,
     *     total: int,
     *     error: string|null
     * }
     */
    public function getTopPostsFromAllSubreddits(string $time = 'day', int $limitPerSub = 10): array
    {
        $time = $this->validateTimeFilter($time);
        $cacheKey = "reddit_top_all_{$time}_{$limitPerSub}";

        $result = $this->cachedRequest($cacheKey, self::TTL_TOP_ALL, function () use ($time, $limitPerSub): ?array {
            $allPosts = [];
            $subredditsStatus = [];

            foreach ($this->subredditsConfig as $subreddit => $config) {
                try {
                    $result = $this->getSubredditPosts($subreddit, 'top', $time, $limitPerSub);

                    $subredditsStatus[$subreddit] = [
                        'success' => $result['success'],
                        'count'   => $result['success'] ? count($result['posts']) : 0,
                        'error'   => $result['error'],
                    ];

                    if ($result['success']) {
                        // Добавляем категорию и приоритет из конфига
                        $enrichedPosts = array_map(function (array $post) use ($config): array {
                            $post['category'] = $config['category'];
                            $post['priority'] = $config['priority'];
                            return $post;
                        }, $result['posts']);

                        $allPosts = array_merge($allPosts, $enrichedPosts);
                    }
                } catch (Throwable $e) {
                    $this->modx->log(modX::LOG_LEVEL_ERROR, "[RedditService] Исключение для r/{$subreddit}: {$e->getMessage()}");
                    $subredditsStatus[$subreddit] = [
                        'success' => false,
                        'count'   => 0,
                        'error'   => $e->getMessage(),
                    ];
                }
            }

            // Сортировка по upvotes (убывание)
            usort($allPosts, function (array $a, array $b): int {
                return ($b['upvotes'] ?? 0) <=> ($a['upvotes'] ?? 0);
            });

            return [
                'posts'             => $allPosts,
                'subreddits_status' => $subredditsStatus,
                'total'             => count($allPosts),
            ];
        });

        if ($result === null) {
            return [
                'success'           => false,
                'posts'             => [],
                'subreddits_status' => [],
                'total'             => 0,
                'error'             => 'Не удалось получить топ-посты',
            ];
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[RedditService] Топ из всех subreddit'ов: {$result['total']} постов"
        );

        return [
            'success'           => true,
            'posts'             => $result['posts'],
            'subreddits_status' => $result['subreddits_status'],
            'total'             => $result['total'],
            'error'             => null,
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // 3. Rising (растущие) посты
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить растущие (rising) посты.
     *
     * Использует Reddit34 RapidAPI endpoint /getRisingPopularPosts.
     * Если список subreddit'ов пуст — берёт из всех в конфиге.
     *
     * @param array<int, string> $subreddits Список subreddit'ов (пустой = все из конфига)
     * @return array{success: bool, posts: array<int, array>, source: string, error: string|null}
     */
    public function getRisingPosts(array $subreddits = []): array
    {
        if (empty($subreddits)) {
            $subreddits = array_keys($this->subredditsConfig);
        }

        $subredditsKey = md5(implode(',', $subreddits));
        $cacheKey = "reddit_rising_{$subredditsKey}";

        $result = $this->cachedRequest($cacheKey, self::TTL_RISING, function () use ($subreddits): ?array {
            $allPosts = [];

            // Пробуем Reddit34 API для общих rising постов
            $reddit34Posts = $this->fetchRisingFromReddit34();
            if ($reddit34Posts !== null) {
                $allPosts = array_merge($allPosts, $reddit34Posts);
            }

            // Дополняем rising постами из конкретных subreddit'ов через официальный API
            foreach ($subreddits as $subreddit) {
                try {
                    $posts = $this->fetchFromOfficialAPI($subreddit, 'rising', 'day', 10);
                    if ($posts !== null) {
                        $allPosts = array_merge($allPosts, $posts);
                    }
                } catch (Throwable $e) {
                    $this->modx->log(modX::LOG_LEVEL_DEBUG, "[RedditService] Rising для r/{$subreddit} недоступен: {$e->getMessage()}");
                }
            }

            if (empty($allPosts)) {
                return null;
            }

            // Сортировка по hot score
            usort($allPosts, function (array $a, array $b): int {
                $scoreA = $this->calculateRedditScore($a);
                $scoreB = $this->calculateRedditScore($b);
                return $scoreB <=> $scoreA;
            });

            return $allPosts;
        });

        if ($result === null) {
            return $this->errorResponse('Не удалось получить rising посты');
        }

        return [
            'success' => true,
            'posts'   => $result,
            'source'  => 'reddit-rising',
            'error'   => null,
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // 4. Популярные посты
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить общие популярные посты Reddit.
     *
     * Использует Reddit34 RapidAPI endpoint /getPopularPosts.
     *
     * @param string $sort Сортировка: hot, new, top, rising
     * @return array{success: bool, posts: array<int, array>, source: string, error: string|null}
     */
    public function getPopularPosts(string $sort = 'hot'): array
    {
        $sort = $this->validateSort($sort);
        $cacheKey = "reddit_popular_{$sort}";

        $result = $this->cachedRequest($cacheKey, self::TTL_POPULAR, function () use ($sort): ?array {
            $headers = $this->getReddit34Headers();
            if ($headers === null) {
                // Fallback: официальный API для r/popular
                return $this->fetchFromOfficialAPI('popular', $sort, 'day', 50);
            }

            $url = $this->buildUrl(self::REDDIT34_BASE_URL . '/getPopularPosts', [
                'sort' => $sort,
            ]);

            $response = $this->httpGet($url, $headers);

            if (!$response['success']) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[RedditService] Reddit34 getPopularPosts ошибка: {$response['error']}");
                // Fallback на официальный API
                return $this->fetchFromOfficialAPI('popular', $sort, 'day', 50);
            }

            $rawPosts = $response['data']['data'] ?? $response['data']['posts'] ?? $response['data'] ?? [];

            if (!is_array($rawPosts)) {
                return $this->fetchFromOfficialAPI('popular', $sort, 'day', 50);
            }

            return array_map(function (array $post): array {
                return $this->normalizePost($post, 'reddit34');
            }, $rawPosts);
        });

        if ($result === null) {
            return $this->errorResponse('Не удалось получить популярные посты');
        }

        return [
            'success' => true,
            'posts'   => $result,
            'source'  => 'reddit-popular',
            'error'   => null,
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // 5. Hot Score — формула Reddit
    // ═════════════════════════════════════════════════════════════

    /**
     * Вычислить Reddit "hot score" для поста.
     *
     * Формула: sign(score) * log10(max(|score|, 1)) + (created - epoch) / 45000
     *
     * @param array $post Нормализованный пост с полями upvotes и created_at
     * @return float Hot score
     */
    public function calculateRedditScore(array $post): float
    {
        $score = (int)($post['upvotes'] ?? 0);
        $createdAt = $post['created_at'] ?? '';

        // Определяем timestamp
        $timestamp = 0;
        if (is_numeric($createdAt)) {
            $timestamp = (int)$createdAt;
        } elseif (!empty($createdAt)) {
            try {
                $dt = new \DateTimeImmutable($createdAt);
                $timestamp = $dt->getTimestamp();
            } catch (Throwable $e) {
                $timestamp = time();
            }
        }

        // sign(score)
        if ($score > 0) {
            $sign = 1;
        } elseif ($score < 0) {
            $sign = -1;
        } else {
            $sign = 0;
        }

        // log10(max(|score|, 1))
        $order = log10(max(abs($score), 1));

        // Временная составляющая
        $seconds = $timestamp - self::REDDIT_EPOCH;

        return round($sign * $order + $seconds / 45000, 7);
    }

    // ═════════════════════════════════════════════════════════════
    // 6. Нормализация поста
    // ═════════════════════════════════════════════════════════════

    /**
     * Нормализовать сырой пост из любого API в единый формат.
     *
     * @param array  $rawPost Сырые данные поста
     * @param string $source  Источник: 'official' или 'reddit34'
     * @return array{
     *     id: string,
     *     title: string,
     *     url: string,
     *     subreddit: string,
     *     author: string,
     *     upvotes: int,
     *     comments: int,
     *     created_at: string,
     *     thumbnail: string
     * }
     */
    public function normalizePost(array $rawPost, string $source = 'official'): array
    {
        if ($source === 'official') {
            return $this->normalizeOfficialPost($rawPost);
        }

        return $this->normalizeReddit34Post($rawPost);
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные методы: получение данных из API
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить посты из официального Reddit JSON API.
     *
     * @param string $subreddit Название subreddit'а
     * @param string $sort      Сортировка
     * @param string $time      Период
     * @param int    $limit     Лимит
     * @return array<int, array>|null Нормализованные посты или null при ошибке
     */
    private function fetchFromOfficialAPI(
        string $subreddit,
        string $sort,
        string $time,
        int $limit
    ): ?array {
        $url = self::REDDIT_BASE_URL . "/r/{$subreddit}/{$sort}.json?" . http_build_query([
            't'        => $time,
            'limit'    => $limit,
            'raw_json' => 1,
        ]);

        try {
            // Use dedicated method to avoid double User-Agent (APIService always sets CURLOPT_USERAGENT)
            $response = $this->redditOfficialGet($url, 15);

            if (!$response['success']) {
                $this->modx->log(
                    modX::LOG_LEVEL_WARN,
                    "[RedditService] Official API ошибка для r/{$subreddit}: {$response['error']}"
                );
                return null;
            }

            $children = $response['data']['data']['children'] ?? [];
            if (empty($children)) {
                $this->modx->log(modX::LOG_LEVEL_DEBUG, "[RedditService] Пустой ответ для r/{$subreddit}");
                return [];
            }

            $posts = [];
            foreach ($children as $child) {
                if (($child['kind'] ?? '') !== 't3') {
                    continue;
                }
                $postData = $child['data'] ?? [];
                if (empty($postData)) {
                    continue;
                }

                $posts[] = $this->normalizeOfficialPost($postData);
            }

            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                "[RedditService] Official API: r/{$subreddit}/{$sort} — " . count($posts) . " постов"
            );

            return $posts;
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[RedditService] Исключение Official API для r/{$subreddit}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Получить посты из subreddit'а через Reddit34 RapidAPI.
     *
     * @param string $subreddit Название subreddit'а
     * @param string $sort      Сортировка
     * @param string $time      Период
     * @param int    $limit     Лимит
     * @return array<int, array>|null Нормализованные посты или null при ошибке
     */
    private function fetchFromReddit34Subreddit(
        string $subreddit,
        string $sort,
        string $time,
        int $limit
    ): ?array {
        $headers = $this->getReddit34Headers();
        if ($headers === null) {
            return null;
        }

        $url = $this->buildUrl(self::REDDIT34_BASE_URL . '/getPostsBySubreddit', [
            'subreddit' => $subreddit,
            'sort'      => $sort,
            'time'      => $time,
            'limit'     => $limit,
        ]);

        try {
            $response = $this->httpGet($url, $headers);

            if (!$response['success']) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[RedditService] Reddit34 ошибка для r/{$subreddit}: {$response['error']}");
                return null;
            }

            $rawPosts = $response['data']['data'] ?? $response['data']['posts'] ?? $response['data'] ?? [];
            if (!is_array($rawPosts) || empty($rawPosts)) {
                return [];
            }

            $posts = array_map(function (array $post): array {
                return $this->normalizeReddit34Post($post);
            }, $rawPosts);

            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                "[RedditService] Reddit34: r/{$subreddit} — " . count($posts) . " постов"
            );

            return $posts;
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[RedditService] Исключение Reddit34 для r/{$subreddit}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Прямой cURL-запрос к официальному Reddit JSON API.
     *
     * Не использует APIService::httpGet(), чтобы избежать дублирования User-Agent
     * (APIService всегда добавляет CURLOPT_USERAGENT='SpookyApp/1.0').
     * Reddit требует формат UA: platform:app_id:version (by /u/username).
     *
     * @param string $url     Полный URL
     * @param int    $timeout Таймаут в секундах
     * @return array{success: bool, data: mixed, error: string|null}
     */
    private function redditOfficialGet(string $url, int $timeout = 15): array
    {
        $startTime = microtime(true);
        $this->modx->log(modX::LOG_LEVEL_INFO, "[RedditService] → GET (official) {$url}");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => self::REDDIT_USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $elapsed = (int)round((microtime(true) - $startTime) * 1000);

        if ($response === false || !empty($curlError)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[RedditService] cURL error (official): {$curlError}");
            return ['success' => false, 'data' => null, 'error' => $curlError];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                "[RedditService] ← HTTP {$httpCode} ({$elapsed}ms) official: " . mb_substr((string)$response, 0, 300));
            return ['success' => false, 'data' => null, 'error' => "HTTP {$httpCode}"];
        }

        $this->modx->log(modX::LOG_LEVEL_INFO,
            "[RedditService] ← HTTP {$httpCode} ({$elapsed}ms) official: {$url}");

        $decoded = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'data' => null, 'error' => 'JSON decode error: ' . json_last_error_msg()];
        }

        return ['success' => true, 'data' => $decoded, 'error' => null];
    }

    /**
     * Получить rising посты через Reddit34 RapidAPI.
     *
     * @return array<int, array>|null Нормализованные посты или null при ошибке
     */
    private function fetchRisingFromReddit34(): ?array
    {
        $headers = $this->getReddit34Headers();
        if ($headers === null) {
            return null;
        }

        $url = self::REDDIT34_BASE_URL . '/getRisingPopularPosts';

        try {
            $response = $this->httpGet($url, $headers);

            if (!$response['success']) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[RedditService] Reddit34 getRisingPopularPosts ошибка: {$response['error']}");
                return null;
            }

            $rawPosts = $response['data']['data'] ?? $response['data']['posts'] ?? $response['data'] ?? [];
            if (!is_array($rawPosts)) {
                return null;
            }

            return array_map(function (array $post): array {
                return $this->normalizeReddit34Post($post);
            }, $rawPosts);
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[RedditService] Исключение Reddit34 rising: {$e->getMessage()}");
            return null;
        }
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные методы: нормализация
    // ═════════════════════════════════════════════════════════════

    /**
     * Нормализовать пост из официального Reddit API.
     *
     * @param array $data Данные поста из data.children[].data
     * @return array Нормализованный пост
     */
    private function normalizeOfficialPost(array $data): array
    {
        $thumbnail = $data['thumbnail'] ?? '';
        // Reddit возвращает 'self', 'default', 'nsfw', 'spoiler' вместо URL
        if (in_array($thumbnail, ['self', 'default', 'nsfw', 'spoiler', 'image', ''], true)) {
            $thumbnail = '';
        }

        $createdUtc = (int)($data['created_utc'] ?? 0);

        // selftext — body text of self-posts; empty string for link posts
        $selftext = trim((string)($data['selftext'] ?? ''));
        // strip Reddit markdown artifacts
        if ($selftext === '[deleted]' || $selftext === '[removed]') {
            $selftext = '';
        }

        return [
            'id'         => (string)($data['id'] ?? $data['name'] ?? ''),
            'title'      => trim((string)($data['title'] ?? '')),
            'url'        => $this->buildRedditPermalink($data['permalink'] ?? ''),
            'subreddit'  => (string)($data['subreddit'] ?? ''),
            'author'     => (string)($data['author'] ?? '[deleted]'),
            'upvotes'    => (int)($data['ups'] ?? $data['score'] ?? 0),
            'comments'   => (int)($data['num_comments'] ?? 0),
            'created_at' => $createdUtc > 0 ? date('Y-m-d\TH:i:s\Z', $createdUtc) : '',
            'thumbnail'  => $thumbnail,
            'selftext'   => $selftext,
        ];
    }

    /**
     * Нормализовать пост из Reddit34 RapidAPI.
     *
     * @param array $data Данные поста из Reddit34
     * @return array Нормализованный пост
     */
    private function normalizeReddit34Post(array $data): array
    {
        $thumbnail = $data['thumbnail'] ?? $data['image'] ?? '';
        if (in_array($thumbnail, ['self', 'default', 'nsfw', 'spoiler', 'image', ''], true)) {
            $thumbnail = '';
        }

        // Reddit34 может возвращать timestamp как число или строку
        $createdAt = $data['created_utc'] ?? $data['created'] ?? $data['date'] ?? '';
        if (is_numeric($createdAt)) {
            $createdAt = date('Y-m-d\TH:i:s\Z', (int)$createdAt);
        }

        return [
            'id'         => (string)($data['id'] ?? $data['name'] ?? md5(($data['title'] ?? '') . ($data['url'] ?? ''))),
            'title'      => trim((string)($data['title'] ?? '')),
            'url'        => (string)($data['url'] ?? $data['permalink'] ?? ''),
            'subreddit'  => (string)($data['subreddit'] ?? $data['community'] ?? ''),
            'author'     => (string)($data['author'] ?? $data['user'] ?? '[deleted]'),
            'upvotes'    => (int)($data['ups'] ?? $data['score'] ?? $data['upvotes'] ?? 0),
            'comments'   => (int)($data['num_comments'] ?? $data['comments'] ?? 0),
            'created_at' => (string)$createdAt,
            'thumbnail'  => $thumbnail,
            'selftext'   => trim((string)($data['selftext'] ?? $data['text'] ?? $data['body'] ?? '')),
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные методы: утилиты
    // ═════════════════════════════════════════════════════════════

    /**
     * Загрузить конфигурацию subreddit'ов из файла.
     *
     * @return void
     */
    private function loadSubredditsConfig(): void
    {
        $configPath = MODX_CORE_PATH . 'components/spookyapp/config/subreddits.php';

        if (!file_exists($configPath)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[RedditService] Конфиг subreddit'ов не найден: {$configPath}");
            $this->subredditsConfig = [];
            return;
        }

        try {
            $config = require $configPath;

            if (!is_array($config)) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[RedditService] Конфиг subreddit'ов вернул не массив");
                $this->subredditsConfig = [];
                return;
            }

            $this->subredditsConfig = $config;
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                "[RedditService] Загружено " . count($config) . " subreddit'ов из конфига"
            );
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[RedditService] Ошибка загрузки конфига: {$e->getMessage()}");
            $this->subredditsConfig = [];
        }
    }

    /**
     * Получить заголовки для Reddit34 RapidAPI.
     *
     * @return array<int, string>|null Заголовки или null если ключ не настроен
     */
    private function getReddit34Headers(): ?array
    {
        $apiKey = $this->modx->getOption(self::REDDIT34_KEY_SETTING, null, '');

        if (empty($apiKey)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[RedditService] Системная настройка '" . self::REDDIT34_KEY_SETTING . "' не задана");
            return null;
        }

        return [
            'X-RapidAPI-Key: ' . $apiKey,
            'X-RapidAPI-Host: ' . self::REDDIT34_HOST,
        ];
    }

    /**
     * Очистить название subreddit'а от лишних символов.
     *
     * @param string $subreddit Сырое название
     * @return string Очищенное название
     */
    private function sanitizeSubreddit(string $subreddit): string
    {
        // Убираем r/ или /r/ из начала
        $subreddit = preg_replace('#^/?r/#', '', trim($subreddit)) ?? $subreddit;
        // Оставляем только допустимые символы
        $subreddit = preg_replace('/[^a-zA-Z0-9_]/', '', $subreddit) ?? $subreddit;

        return $subreddit;
    }

    /**
     * Валидировать параметр сортировки.
     *
     * @param string $sort Сортировка
     * @return string Валидная сортировка (по умолчанию 'hot')
     */
    private function validateSort(string $sort): string
    {
        $sort = strtolower(trim($sort));
        return in_array($sort, self::VALID_SORTS, true) ? $sort : 'hot';
    }

    /**
     * Валидировать параметр временного фильтра.
     *
     * @param string $time Период
     * @return string Валидный период (по умолчанию 'day')
     */
    private function validateTimeFilter(string $time): string
    {
        $time = strtolower(trim($time));
        return in_array($time, self::VALID_TIME_FILTERS, true) ? $time : 'day';
    }

    /**
     * Построить полную ссылку на пост Reddit из permalink.
     *
     * @param string $permalink Permalink из API ответа
     * @return string Полный URL
     */
    private function buildRedditPermalink(string $permalink): string
    {
        if (empty($permalink)) {
            return '';
        }

        // Если уже полный URL
        if (str_starts_with($permalink, 'http')) {
            return $permalink;
        }

        return self::REDDIT_BASE_URL . $permalink;
    }

    /**
     * Сформировать стандартный ответ об ошибке.
     *
     * @param string $message Сообщение об ошибке
     * @return array{success: bool, posts: array, source: string, error: string}
     */
    private function errorResponse(string $message): array
    {
        return [
            'success' => false,
            'posts'   => [],
            'source'  => 'reddit',
            'error'   => $message,
        ];
    }

    /**
     * Получить конфигурацию subreddit'ов.
     *
     * @return array<string, array{category: string, priority: int}>
     */
    public function getSubredditsConfig(): array
    {
        return $this->subredditsConfig;
    }

    /**
     * Получить subreddit'ы по категории.
     *
     * @param string $category Название категории (IT, Gaming, Sports и т.д.)
     * @return array<string, array{category: string, priority: int}>
     */
    public function getSubredditsByCategory(string $category): array
    {
        return array_filter($this->subredditsConfig, function (array $config) use ($category): bool {
            return strcasecmp($config['category'], $category) === 0;
        });
    }
}