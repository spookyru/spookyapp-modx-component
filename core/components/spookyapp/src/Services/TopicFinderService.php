<?php

declare(strict_types=1);

namespace SpookyApp\Services;

use MODX\Revolution\modX;
use SpookyApp\Services\API\NewsAPIService;
use SpookyApp\Services\API\RedditService;
use SpookyApp\Services\API\YandexAPIService;
use SpookyApp\Services\API\TMDBService;
use SpookyApp\Services\API\GamesAPIService;
use SpookyApp\Services\API\GitHubService;
use SpookyApp\Services\API\MobileDevicesAPIService;
use SpookyApp\Services\API\SportsAPIService;
use SpookyApp\Services\API\FootballAPIService;
use SpookyApp\Services\API\BiathlonIBUService;
use SpookyApp\Services\TopicScoringService;
use SpookyApp\Services\Cache\CacheService;
use DateTimeImmutable;
use Throwable;
use xPDO\xPDO;

/**
 * Главный сервис для поиска, агрегации и рекомендации тем для блога.
 *
 * Объединяет данные из нескольких источников (News API, Reddit, TMDB, Games, GitHub, Mobile Devices, Sports, Football, Biathlon, AI),
 * дедуплицирует, оценивает через TopicScoringService, фильтрует
 * и предоставляет упорядоченный список тем для публикации.
 *
 * Архитектура:
 * ┌───────────────────────────────────────────────────────────────┐
 * │                    TopicFinderService                         │
 * │                                                               │
 * │  fetchFromNews()    ──→ NewsAPIService                        │
 * │  fetchFromReddit()  ──→ RedditService                         │
 * │  fetchFromTMDB()    ──→ TMDBService                           │
 * │  fetchFromGames()   ──→ GamesAPIService                       │
 * │  fetchFromGitHub()  ──→ GitHubService                         │
 * │  fetchFromDevices() ──→ MobileDevicesAPIService               │
 * │  fetchFromSports()  ──→ SportsAPIService                      │
 * │  fetchFromFootball()──→ FootballAPIService                     │
 * │  fetchFromBiathlon()──→ BiathlonIBUService                    │
 * │  generateAITopics() ──→ YandexAPIService                      │
 * │           │                                                   │
 * │           ▼                                                   │
 * │  aggregateTopics() → deduplicate → scoringService             │
 * │           │                                                   │
 * │           ▼                                                   │
 * │  saveToDatabase() / getFromDatabase()                         │
 * └───────────────────────────────────────────────────────────────┘
 *
 * Единый формат темы:
 * - id: string — уникальный идентификатор
 * - source: string — источник (news, reddit, tmdb, games, github, devices, sports, football, biathlon, ai)
 * - title: string — заголовок
 * - url: string|null — ссылка на оригинал
 * - description: string|null — описание/аннотация
 * - category: string — категория
 * - published_at: string — дата публикации (ISO 8601)
 * - score: float — оценка (0–100)
 * - metadata: array — доп. данные (upvotes, comments, thumbnail и т.д.)
 */
class TopicFinderService
{
    // ─── Кеширование ─────────────────────────────────────────────
    private const CACHE_TTL_AGGREGATED = 3600;      // 1 час
    private const CACHE_PREFIX = 'topicfinder_';

    // ─── Дедупликация ────────────────────────────────────────────
    private const SIMILARITY_THRESHOLD = 85;         // % для similar_text
    private const LEVENSHTEIN_MAX_LENGTH = 255;      // Макс. длина строки для levenshtein()

    // ─── Значения по умолчанию ───────────────────────────────────
    private const DEFAULT_MIN_SCORE = 20.0;
    private const DEFAULT_LIMIT = 50;

    // ─── Таблица БД ──────────────────────────────────────────────
    // TABLE_NAME is now dynamic — see getTopicsTableName()

    // ─── Приоритеты источников для дедупликации ──────────────────
    /** @var array<string, int> Чем выше — тем предпочтительнее */
    private const SOURCE_PRIORITY = [
        'tmdb'           => 70,
        'realtime_news'  => 62,
        'thenewsapi'     => 61,
        'newsdata'       => 60,
        'news'           => 60, // legacy
        'tmdb_trends'    => 70,
        'tmdb_upcoming'  => 69,
        'sports'         => 55,
        'football'       => 53,
        'biathlon'       => 51,
        'reddit'         => 50,
        'games'          => 40,
        'github'         => 30,
        'devices'        => 20,
        'ai'             => 10,
    ];

    // ─── Источники по умолчанию ──────────────────────────────────
    private const DEFAULT_SOURCES = [
        'realtime_news', 'thenewsapi', 'newsdata',
        'reddit', 'tmdb_trends', 'tmdb_upcoming', 'games', 'github', 'devices',
        'sports', 'football', 'biathlon',
    ];

    // ─── Категории по умолчанию для News API ─────────────────────
    private const DEFAULT_NEWS_CATEGORIES = ['technology', 'sports', 'entertainment'];

    private modX $modx;
    private NewsAPIService $newsService;
    private RedditService $redditService;
    private ?YandexAPIService $yandexService;
    private TMDBService $tmdbService;
    private GamesAPIService $gamesService;
    private GitHubService $githubService;
    private MobileDevicesAPIService $devicesService;
    private SportsAPIService $sportsService;
    private FootballAPIService $footballService;
    private BiathlonIBUService $biathlonService;
    private TopicScoringService $scoringService;
    private CacheService $cache;

    /**
     * @param modX                      $modx            Инстанс MODX
     * @param NewsAPIService            $newsService     Сервис новостей
     * @param RedditService             $redditService   Сервис Reddit
     * @param YandexAPIService|null     $yandexService   Сервис Yandex AI (опциональный)
     * @param TMDBService               $tmdbService     Сервис TMDB (фильмы/сериалы)
     * @param GamesAPIService           $gamesService    Сервис игр
     * @param GitHubService             $githubService   Сервис GitHub
     * @param MobileDevicesAPIService   $devicesService  Сервис мобильных устройств
     * @param SportsAPIService          $sportsService   Сервис спорта
     * @param FootballAPIService        $footballService Сервис футбола
     * @param BiathlonIBUService        $biathlonService Сервис биатлона IBU
     * @param TopicScoringService       $scoringService  Сервис оценки тем
     * @param CacheService              $cache           Сервис кеширования
     */
    public function __construct(
        modX $modx,
        NewsAPIService $newsService,
        RedditService $redditService,
        ?YandexAPIService $yandexService,
        TMDBService $tmdbService,
        GamesAPIService $gamesService,
        GitHubService $githubService,
        MobileDevicesAPIService $devicesService,
        SportsAPIService $sportsService,
        FootballAPIService $footballService,
        BiathlonIBUService $biathlonService,
        TopicScoringService $scoringService,
        CacheService $cache
    ) {
        $this->modx = $modx;
        $this->newsService = $newsService;
        $this->redditService = $redditService;
        $this->yandexService = $yandexService;
        $this->tmdbService = $tmdbService;
        $this->gamesService = $gamesService;
        $this->githubService = $githubService;
        $this->devicesService = $devicesService;
        $this->sportsService = $sportsService;
        $this->footballService = $footballService;
        $this->biathlonService = $biathlonService;
        $this->scoringService = $scoringService;
        $this->cache = $cache;
    }

    /**
     * Вернуть имя таблицы тем с учётом префикса MODX.
     */
    private function getTopicsTableName(): string
    {
        $prefix = $this->modx->getOption('table_prefix', null, 'modx_');
        return $prefix . 'spookyapp_topics';
    }

    // ═════════════════════════════════════════════════════════════
    // 1. Главный метод: поиск трендовых тем
    // ═════════════════════════════════════════════════════════════

    /**
     * Найти трендовые темы из всех источников.
     *
     * Агрегирует темы из News API, Reddit, TMDB, Games, GitHub, Devices,
     * Sports, Football, Biathlon и (опционально) AI,
     * дедуплицирует, рассчитывает score и возвращает отсортированный список.
     *
     * @param array<string, mixed> $options Параметры:
     *   - categories: array<string> — категории для фильтрации (по умолчанию все)
     *   - sources: array<string> — источники: 'news', 'reddit', 'tmdb', 'games', 'github', 'devices', 'sports', 'football', 'biathlon', 'ai' (по умолчанию все кроме ai)
     *   - min_score: float — минимальный score (по умолчанию 20.0)
     *   - limit: int — макс. количество тем (по умолчанию 50)
     *   - use_cache: bool — использовать кеш (по умолчанию true)
     *   - save: bool — сохранять в БД (по умолчанию false)
     * @return array{
     *     success: bool,
     *     topics: array<int, array>,
     *     total: int,
     *     sources_status: array<string, array{success: bool, count: int, error: string|null}>,
     *     error: string|null
     * }
     */
    public function findTrendingTopics(array $options = []): array
    {
        $categories = (array)($options['categories'] ?? []);
        $sources = (array)($options['sources'] ?? self::DEFAULT_SOURCES);
        $minScore = (float)($options['min_score'] ?? self::DEFAULT_MIN_SCORE);
        // Accept both 'limit' and 'max_topics' (refresh.class.php uses max_topics)
        $limit = (int)($options['limit'] ?? $options['max_topics'] ?? self::DEFAULT_LIMIT);
        // Accept both 'use_cache' and 'force_refresh' (refresh.class.php uses force_refresh)
        $forceRefresh = (bool)($options['force_refresh'] ?? false);
        $useCache = isset($options['use_cache']) ? (bool)$options['use_cache'] : !$forceRefresh;
        $saveToDb = (bool)($options['save'] ?? false);
        // Per-source options (e.g. ['news' => ['categories'=>[...], 'keyword'=>'...'], 'reddit' => ['subreddits'=>[...]]])
        $sourceOpts = (array)($options['source_options'] ?? []);

        $cacheKey = self::CACHE_PREFIX . 'trending_' . md5(
            implode(',', $categories) . '|' .
            implode(',', $sources) . '|' .
            $minScore . '|' . $limit . '|' .
            md5(json_encode($sourceOpts))
        );

        // Попытка получить из кеша
        if ($useCache) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TopicFinder] Результат из кеша');
                return $cached;
            }
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TopicFinder] Поиск тем: sources=[' . implode(',', $sources)
            . '], categories=[' . implode(',', $categories)
            . '], min_score=' . $minScore . ', limit=' . $limit
        );

        $sourcesStatus = [];
        $allTopics = [];

        // ── Определяем маппинг источников на fetch-методы ──────
        $fetchMap = [
            'realtime_news' => fn() => $this->fetchFromRealTimeNews($sourceOpts['realtime_news'] ?? []),
            'thenewsapi'    => fn() => $this->fetchFromTheNewsAPI($sourceOpts['thenewsapi'] ?? []),
            'newsdata'      => fn() => $this->fetchFromNewsData($sourceOpts['newsdata'] ?? []),
            'reddit'        => fn() => $this->fetchFromReddit($sourceOpts['reddit']['subreddits'] ?? []),
            'tmdb_trends'   => fn() => $this->fetchFromTMDBTrends($sourceOpts['tmdb_trends'] ?? []),
            'tmdb_upcoming' => fn() => $this->fetchFromTMDBUpcoming($sourceOpts['tmdb_upcoming'] ?? []),
            'games'     => fn() => $this->fetchFromGames($sourceOpts['games'] ?? []),
            'github'    => fn() => $this->fetchFromGitHub(),
            'devices'   => fn() => $this->fetchFromDevices(),
            'sports'    => fn() => $this->fetchFromSports($sourceOpts['sports'] ?? []),
            'football'  => fn() => $this->fetchFromFootball(),
            'biathlon'  => fn() => $this->fetchFromBiathlon(),
        ];

        // ── Fetch из каждого включённого источника ─────────────
        foreach ($fetchMap as $sourceKey => $fetchCallback) {
            if (!in_array($sourceKey, $sources, true)) {
                continue;
            }

            try {
                $topics = $fetchCallback();
                $allTopics = array_merge($allTopics, $topics);
                $sourcesStatus[$sourceKey] = [
                    'success' => true,
                    'count'   => count($topics),
                    'error'   => null,
                ];
            } catch (Throwable $e) {
                $this->modx->log(
                    modX::LOG_LEVEL_ERROR,
                    "[TopicFinder] {$sourceKey} fetch ошибка: {$e->getMessage()}"
                );
                $sourcesStatus[$sourceKey] = [
                    'success' => false,
                    'count'   => 0,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        // ── Fetch из AI ────────────────────────────────────────
        if (in_array('ai', $sources, true) && $this->yandexService !== null) {
            try {
                $aiTopics = $this->generateAITopics(10);
                $allTopics = array_merge($allTopics, $aiTopics);
                $sourcesStatus['ai'] = [
                    'success' => true,
                    'count'   => count($aiTopics),
                    'error'   => null,
                ];
            } catch (Throwable $e) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[TopicFinder] AI fetch ошибка: {$e->getMessage()}");
                $sourcesStatus['ai'] = [
                    'success' => false,
                    'count'   => 0,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        // ── Агрегация и дедупликация ───────────────────────────
        $beforeCount = count($allTopics);
        $aggregated = $this->deduplicateTopics($allTopics);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TopicFinder] Агрегация: total_raw=' . $beforeCount
            . ' → unique=' . count($aggregated)
            . ' (sources: ' . implode(', ', array_map(
                fn(string $k, array $v) => "{$k}={$v['count']}",
                array_keys($sourcesStatus),
                array_values($sourcesStatus)
            )) . ')'
        );

        // ── Scoring + фильтрация blacklist ─────────────────────
        $scored = $this->scoringService->batchScore($aggregated);

        // ── Фильтрация по min_score ────────────────────────────
        $filtered = array_values(array_filter($scored, function (array $topic) use ($minScore): bool {
            return ($topic['score'] ?? 0.0) >= $minScore;
        }));

        // ── Фильтрация по категориям ───────────────────────────
        if (!empty($categories)) {
            $categoriesLower = array_map('mb_strtolower', $categories);
            $filtered = array_values(array_filter($filtered, function (array $topic) use ($categoriesLower): bool {
                $topicCategory = mb_strtolower(trim((string)($topic['category'] ?? '')));
                return empty($topicCategory) || in_array($topicCategory, $categoriesLower, true);
            }));
        }

        // ── Limit ──────────────────────────────────────────────
        $topics = array_slice($filtered, 0, $limit);

        // ── Сохранение в БД (опционально) ──────────────────────
        if ($saveToDb && !empty($topics)) {
            $this->saveToDatabase($topics);
        }

        $result = [
            'success'        => true,
            'topics'         => $topics,
            'total'          => count($topics),
            'sources_status' => $sourcesStatus,
            'error'          => null,
        ];

        // ── Кеширование результата ─────────────────────────────
        if ($useCache) {
            $this->cache->set($cacheKey, $result, self::CACHE_TTL_AGGREGATED);
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[TopicFinder] Результат: {$result['total']} тем"
            . (count($topics) > 0 ? ', top score: ' . ($topics[0]['score'] ?? 0) : '')
        );

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // 2. Получение тем из News API
    // ═════════════════════════════════════════════════════════════

    /**
     * SOURCE 1-A: RealTime News (real-time-news-data.p.rapidapi.com)
     *
     * Routing (priority order):
     * - keyword present  → /search
     * - topics[] present → /topic-headlines (TECHNOLOGY, SPORTS, ENTERTAINMENT, BUSINESS, SCIENCE, HEALTH)
     * - fallback         → /top-headlines by country/lang
     */
    private function fetchFromRealTimeNews(array $opts = []): array
    {
        $lang       = trim((string)($opts['lang']        ?? 'en'));
        $country    = strtoupper(trim((string)($opts['country']    ?? 'US')));
        $timePeriod = trim((string)($opts['time_period'] ?? '7d'));
        $limit      = (int)($opts['limit']   ?? 50);
        $keyword    = trim((string)($opts['keyword']     ?? ''));
        $topics     = (array)($opts['topics']            ?? []);

        if (!empty($keyword)) {
            $articles = $this->newsService->getRealTimeBySearch($keyword, $lang, $country, $timePeriod, $limit);
        } elseif (!empty($topics)) {
            $articles = $this->newsService->getRealTimeByTopics($topics, $lang, $country, $timePeriod, $limit);
        } else {
            $articles = $this->newsService->getRealTimeTopHeadlines($lang, $country, $limit);
        }

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[TopicFinder] RealTimeNews: получено ' . count($articles) . ' статей');

        return array_map(fn($a) => $this->normalizeNewsServiceArticle($a), $articles);
    }

    /**
     * SOURCE 1-B: TheNewsAPI (api.thenewsapi.com)
     * Categories: tech, sports, business, health, entertainment, science, food, travel
     */
    private function fetchFromTheNewsAPI(array $opts = []): array
    {
        $categories = (array)($opts['categories'] ?? []);
        $lang       = trim((string)($opts['lang']   ?? 'en'));
        $limit      = (int)($opts['limit'] ?? 50);

        $articles = $this->newsService->getTheNewsAPITop($categories, $lang, $limit);

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[TopicFinder] TheNewsAPI: получено ' . count($articles) . ' статей');

        return array_map(fn($a) => $this->normalizeNewsServiceArticle($a), $articles);
    }

    /**
     * SOURCE 1-C: NewsData (newsdata.io)
     * Categories: sports, technology, business, domestic, entertainment, environment,
     *             food, health, lifestyle, science, tourism, other
     */
    private function fetchFromNewsData(array $opts = []): array
    {
        $categories = (array)($opts['categories'] ?? []);
        $lang       = trim((string)($opts['lang']   ?? 'en'));
        $limit      = (int)($opts['limit'] ?? 10);

        $articles = $this->newsService->getNewsDataLatest($categories, $lang, $limit);

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[TopicFinder] NewsData: получено ' . count($articles) . ' статей');

        return array_map(fn($a) => $this->normalizeNewsServiceArticle($a), $articles);
    }

    /**
     * Normalize an article pre-normalized by NewsAPIService into the TopicFinder topic format.
     */
    private function normalizeNewsServiceArticle(array $a): array
    {
        $title = trim($a['title'] ?? '');
        $url   = $a['url'] ?? '';
        return [
            'id'           => $a['id'] ?? ('news_' . md5($title . $url)),
            'source'       => $a['source'] ?? 'news',
            'title'        => $title,
            'url'          => $url,
            'description'  => $a['description'] ?? '',
            'category'     => $a['category'] ?? 'news',
            'published_at' => $a['published_at'] ?? '',
            'score'        => (float)($a['score'] ?? 0.0),
            'metadata'     => $a['metadata'] ?? [],
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // 3. Получение тем из Reddit
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить темы из Reddit и нормализовать в единый формат.
     *
     * @param array<int, string> $subreddits Список subreddit'ов (пусто = все из конфига)
     * @return array<int, array> Нормализованные темы
     */
    public function fetchFromReddit(array $subreddits = []): array
    {
        $allTopics = [];

        try {
            // Получаем топ посты из всех subreddit'ов
            $result = $this->redditService->getTopPostsFromAllSubreddits('day', 15);

            if ($result['success'] && !empty($result['posts'])) {
                foreach ($result['posts'] as $post) {
                    $allTopics[] = $this->normalizeRedditPost($post);
                }

                $this->modx->log(
                    modX::LOG_LEVEL_DEBUG,
                    '[TopicFinder] Reddit: top all — ' . count($result['posts']) . ' постов'
                );
            }
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[TopicFinder] Reddit top all ошибка: {$e->getMessage()}");
        }

        // Дополняем rising постами
        try {
            $rising = $this->redditService->getRisingPosts($subreddits);

            if ($rising['success'] && !empty($rising['posts'])) {
                foreach ($rising['posts'] as $post) {
                    $allTopics[] = $this->normalizeRedditPost($post);
                }

                $this->modx->log(
                    modX::LOG_LEVEL_DEBUG,
                    '[TopicFinder] Reddit: rising — ' . count($rising['posts']) . ' постов'
                );
            }
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[TopicFinder] Reddit rising ошибка: {$e->getMessage()}");
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TopicFinder] Reddit: всего получено ' . count($allTopics) . ' тем'
        );

        return $allTopics;
    }

    // ═════════════════════════════════════════════════════════════
    // 3a. Получение тем из TMDB (фильмы и сериалы)
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить темы из TMDB (The Movie Database) и нормализовать в единый формат.
     *
     * Использует метод aggregateForTopicFinder() из TMDBService, который
     * возвращает трендовые фильмы, сериалы и предстоящие релизы.
     *
     * @return array<int, array> Нормализованные темы
     */
    private function fetchFromTMDB(array $opts = []): array
    {
        $result = $this->tmdbService->aggregateForTopicFinder($opts);

        if (!$result['success'] || empty($result['topics'])) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TopicFinder] TMDB: нет тем');
            return [];
        }

        $topics = $result['topics'];

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TopicFinder] TMDB: получено ' . count($topics) . ' тем'
        );

        return $topics;
    }

    private function fetchFromTMDBTrends(array $opts = []): array
    {
        $result = $this->tmdbService->getTrendingTopics($opts);

        if (!$result['success'] || empty($result['topics'])) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TopicFinder] TMDB Trends: нет тем');
            return [];
        }

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[TopicFinder] TMDB Trends: получено ' . count($result['topics']) . ' тем');

        return $result['topics'];
    }

    private function fetchFromTMDBUpcoming(array $opts = []): array
    {
        $result = $this->tmdbService->getUpcomingTopics($opts);

        if (!$result['success'] || empty($result['topics'])) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TopicFinder] TMDB Upcoming: нет тем');
            return [];
        }

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[TopicFinder] TMDB Upcoming: получено ' . count($result['topics']) . ' тем');

        return $result['topics'];
    }

    // ═════════════════════════════════════════════════════════════
    // 3b. Получение тем из Games API
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить темы из Games API и нормализовать в единый формат.
     *
     * Использует метод aggregateForTopicFinder() из GamesAPIService, который
     * возвращает популярные и новые игры.
     *
     * @param array $opts Опции: type ('popular'|'new_releases'), platform
     * @return array<int, array> Нормализованные темы
     */
    private function fetchFromGames(array $opts = []): array
    {
        $result = $this->gamesService->aggregateForTopicFinder($opts);

        if (!$result['success'] || empty($result['topics'])) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TopicFinder] Games: нет тем');
            return [];
        }

        $topics = $result['topics'];

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TopicFinder] Games: получено ' . count($topics) . ' тем'
        );

        return $topics;
    }

    // ═════════════════════════════════════════════════════════════
    // 3c. Получение тем из GitHub
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить темы из GitHub и нормализовать в единый формат.
     *
     * Использует метод aggregateForTopicFinder() из GitHubService, который
     * возвращает трендовые репозитории, горячие дискуссии и releases.
     *
     * @return array<int, array> Нормализованные темы
     */
    private function fetchFromGitHub(): array
    {
        $result = $this->githubService->aggregateForTopicFinder();

        if (!$result['success'] || empty($result['topics'])) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TopicFinder] GitHub: нет тем');
            return [];
        }

        $topics = $result['topics'];

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TopicFinder] GitHub: получено ' . count($topics) . ' тем'
        );

        return $topics;
    }

    // ═════════════════════════════════════════════════════════════
    // 3d. Получение тем из Mobile Devices API
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить темы из Mobile Devices API и нормализовать в единый формат.
     *
     * Использует метод aggregateForTopicFinder() из MobileDevicesAPIService, который
     * возвращает новинки и популярные мобильные устройства.
     *
     * @return array<int, array> Нормализованные темы
     */
    private function fetchFromDevices(): array
    {
        $result = $this->devicesService->aggregateForTopicFinder();

        if (!$result['success'] || empty($result['topics'])) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TopicFinder] Devices: нет тем');
            return [];
        }

        $topics = $result['topics'];

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TopicFinder] Devices: получено ' . count($topics) . ' тем'
        );

        return $topics;
    }

    // ═════════════════════════════════════════════════════════════
    // 3e. Получение тем из Sports API
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить темы из Sports API и нормализовать в единый формат.
     *
     * Использует метод aggregateForTopicFinder() из SportsAPIService, который
     * возвращает спортивные новости и результаты.
     *
     * @return array<int, array> Нормализованные темы
     */
    private function fetchFromSports(array $opts = []): array
    {
        $result = $this->sportsService->aggregateForTopicFinder($opts);

        if (!$result['success'] || empty($result['topics'])) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TopicFinder] Sports: нет тем');
            return [];
        }

        $topics = $result['topics'];

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TopicFinder] Sports: получено ' . count($topics) . ' тем'
        );

        return $topics;
    }

    // ═════════════════════════════════════════════════════════════
    // 3f. Получение тем из Football API
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить темы из Football API и нормализовать в единый формат.
     *
     * Использует метод aggregateForTopicFinder() из FootballAPIService, который
     * возвращает результаты матчей, трансферы и футбольные новости.
     *
     * @return array<int, array> Нормализованные темы
     */
    private function fetchFromFootball(): array
    {
        $result = $this->footballService->aggregateForTopicFinder();

        if (!$result['success'] || empty($result['topics'])) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TopicFinder] Football: нет тем');
            return [];
        }

        $topics = $result['topics'];

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TopicFinder] Football: получено ' . count($topics) . ' тем'
        );

        return $topics;
    }

    // ═════════════════════════════════════════════════════════════
    // 3g. Получение тем из Biathlon IBU
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить темы из Biathlon IBU и нормализовать в единый формат.
     *
     * Использует метод aggregateForTopicFinder() из BiathlonIBUService, который
     * возвращает результаты соревнований и биатлонные события.
     *
     * @return array<int, array> Нормализованные темы
     */
    private function fetchFromBiathlon(): array
    {
        $result = $this->biathlonService->aggregateForTopicFinder();

        if (!$result['success'] || empty($result['topics'])) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[TopicFinder] Biathlon: нет тем');
            return [];
        }

        $topics = $result['topics'];

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TopicFinder] Biathlon: получено ' . count($topics) . ' тем'
        );

        return $topics;
    }

    // ═════════════════════════════════════════════════════════════
    // 4. Агрегация и дедупликация
    // ═════════════════════════════════════════════════════════════

    /**
     * Объединить темы из разных источников с дедупликацией.
     *
     * Для дубликатов (similar_text > 85%) оставляет тему из источника
     * с более высоким приоритетом (tmdb > news > sports > football > biathlon > reddit > games > github > devices > ai).
     *
     * @param array<int, array> $newsTopics   Темы из новостей
     * @param array<int, array> $redditTopics Темы из Reddit
     * @param array<int, array> ...$otherSources Прочие источники
     * @return array<int, array> Уникальные темы
     */
    public function aggregateTopics(
        array $newsTopics,
        array $redditTopics,
        array ...$otherSources
    ): array {
        // Объединяем все массивы
        $all = array_merge($newsTopics, $redditTopics, ...array_filter($otherSources));

        if (empty($all)) {
            return [];
        }

        $beforeCount = count($all);

        // Дедупликация
        $unique = $this->deduplicateTopics($all);

        $removedCount = $beforeCount - count($unique);
        if ($removedCount > 0) {
            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                "[TopicFinder] Дедупликация: {$beforeCount} → " . count($unique) . " (удалено {$removedCount} дубликатов)"
            );
        }

        return $unique;
    }

    // ═════════════════════════════════════════════════════════════
    // 5. Сохранение в БД
    // ═════════════════════════════════════════════════════════════

    /**
     * Сохранить темы в таблицу modx_spookyapp_topics.
     *
     * Если тема с таким title + source уже существует — обновляет score и metadata.
     *
     * @param array<int, array> $topics Массив тем
     * @return bool true при успеше (хотя бы одна запись сохранена)
     */
    public function saveToDatabase(array $topics): bool
    {
        if (empty($topics)) {
            return false;
        }

        $tableName = $this->getTopicsTableName();
        $savedCount = 0;
        $updatedCount = 0;

        foreach ($topics as $topic) {
            try {
                $title = trim((string)($topic['title'] ?? ''));
                $source = trim((string)($topic['source'] ?? ''));

                if (empty($title)) {
                    continue;
                }

                // Проверяем, есть ли уже такая тема
                $exists = $this->findExistingTopic($title, $source);

                if ($exists !== null) {
                    // Обновляем score и metadata
                    $this->updateTopicInDb(
                        (int)$exists['id'],
                        (float)($topic['score'] ?? 0.0),
                        $topic['metadata'] ?? []
                    );
                    $updatedCount++;
                } else {
                    // Вставляем новую запись
                    $this->insertTopicToDb($topic);
                    $savedCount++;
                }
            } catch (Throwable $e) {
                $this->modx->log(
                    modX::LOG_LEVEL_ERROR,
                    "[TopicFinder] saveToDatabase ошибка: {$e->getMessage()}"
                );
            }
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[TopicFinder] saveToDatabase: saved={$savedCount}, updated={$updatedCount}"
        );

        return ($savedCount + $updatedCount) > 0;
    }

    // ═════════════════════════════════════════════════════════════
    // 6. Получение из БД
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить сохранённые темы из БД с фильтрацией.
     *
     * @param array<string, mixed> $filters Фильтры:
     *   - category: string — категория
     *   - source: string — источник
     *   - min_score: float — минимальный score
     *   - date_from: string — дата с (Y-m-d)
     *   - date_to: string — дата по (Y-m-d)
     *   - limit: int — максимум записей (по умолчанию 100)
     *   - offset: int — смещение (по умолчанию 0)
     * @return array<int, array> Массив тем
     */
    public function getFromDatabase(array $filters = []): array
    {
        $tableName = $this->getTopicsTableName();
        $where = [];
        $bindings = [];

        // ── Фильтр по категории ────────────────────────────────
        if (!empty($filters['category'])) {
            $where[] = 'category = :category';
            $bindings[':category'] = (string)$filters['category'];
        }

        // ── Фильтр по источнику ────────────────────────────────
        if (!empty($filters['source'])) {
            $where[] = 'source = :source';
            $bindings[':source'] = (string)$filters['source'];
        }

        // ── Фильтр по минимальному score ───────────────────────
        if (isset($filters['min_score'])) {
            $where[] = 'score >= :min_score';
            $bindings[':min_score'] = (float)$filters['min_score'];
        }

        // ── Фильтр по дате (от) ────────────────────────────────
        if (!empty($filters['date_from'])) {
            $where[] = 'published_at >= :date_from';
            $bindings[':date_from'] = (string)$filters['date_from'] . ' 00:00:00';
        }

        // ── Фильтр по дате (до) ────────────────────────────────
        if (!empty($filters['date_to'])) {
            $where[] = 'published_at <= :date_to';
            $bindings[':date_to'] = (string)$filters['date_to'] . ' 23:59:59';
        }

        $limit = (int)($filters['limit'] ?? 100);
        $offset = (int)($filters['offset'] ?? 0);

        $sql = "SELECT * FROM {$tableName}";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY score DESC';
        $sql .= " LIMIT {$limit} OFFSET {$offset}";

        try {
            $stmt = $this->modx->prepare($sql);
            if (!$stmt) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[TopicFinder] getFromDatabase: не удалось подготовить запрос');
                return [];
            }

            foreach ($bindings as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Десериализуем metadata
            $topics = array_map(function (array $row): array {
                if (!empty($row['metadata']) && is_string($row['metadata'])) {
                    $row['metadata'] = json_decode($row['metadata'], true) ?? [];
                }
                return $row;
            }, $rows ?: []);

            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[TopicFinder] getFromDatabase: найдено ' . count($topics) . ' тем'
            );

            return $topics;
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[TopicFinder] getFromDatabase ошибка: {$e->getMessage()}");
            return [];
        }
    }

    // ═════════════════════════════════════════════════════════════
    // 7. AI генерация идей
    // ═════════════════════════════════════════════════════════════

    /**
     * Сгенерировать идеи тем через YandexGPT.
     *
     * На основе текущих трендов из News и Reddit формирует
     * запрос к AI для генерации новых идей для блога.
     *
     * @param int $count Количество идей (по умолчанию 10)
     * @return array<int, array> Нормализованные темы от AI
     */
    public function generateAITopics(int $count = 10): array
    {
        if ($this->yandexService === null) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[TopicFinder] YandexAPIService не доступен для AI генерации');
            return [];
        }

        // Собираем текущие тренды для контекста
        $trends = $this->collectCurrentTrends();

        if (empty($trends)) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[TopicFinder] Нет трендов для AI генерации');
            $trends = ['PHP development', 'JavaScript frameworks', 'Web technologies'];
        }

        $blogTheme = $this->modx->getOption(
            'spookyapp.blog_theme',
            null,
            'IT, технологии, гаджеты и веб-разработка'
        );

        try {
            $ideas = $this->yandexService->generateTopicIdeas(
                array_slice($trends, 0, 15), // Макс. 15 трендов
                $blogTheme
            );

            $topics = [];
            $now = (new DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

            foreach (array_slice($ideas, 0, $count) as $index => $idea) {
                $topics[] = [
                    'id'           => 'ai_' . md5($idea . $now),
                    'source'       => 'ai',
                    'title'        => $idea,
                    'url'          => null,
                    'description'  => null,
                    'category'     => 'ai-generated',
                    'published_at' => $now,
                    'score'        => 0.0, // будет рассчитан через scoring
                    'metadata'     => [
                        'generated_from_trends' => count($trends),
                        'blog_theme'            => $blogTheme,
                    ],
                ];
            }

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                '[TopicFinder] AI: сгенерировано ' . count($topics) . ' идей'
            );

            return $topics;
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[TopicFinder] AI генерация ошибка: {$e->getMessage()}");
            return [];
        }
    }

    // ═════════════════════════════════════════════════════════════
    // 8. Рерайт темы
    // ═════════════════════════════════════════════════════════════

    /**
     * Переписать тему через YandexGPT.
     *
     * @param array  $topic Тема с полями title и description
     * @param string $style Стиль: 'blog', 'news', 'social'
     * @return string Переписанный текст или исходный при ошибке
     */
    public function rewriteTopic(array $topic, string $style = 'blog'): string
    {
        if ($this->yandexService === null) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[TopicFinder] YandexAPIService не доступен для рерайта');
            return (string)($topic['title'] ?? '');
        }

        $text = trim((string)($topic['title'] ?? ''));
        $description = trim((string)($topic['description'] ?? ''));

        if (!empty($description)) {
            $text .= "\n\n" . $description;
        }

        if (empty($text)) {
            return '';
        }

        try {
            $rewritten = $this->yandexService->rewriteText($text, $style);

            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[TopicFinder] Рерайт: style=' . $style
                . ', input_len=' . mb_strlen($text)
                . ', output_len=' . mb_strlen($rewritten)
            );

            return $rewritten;
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[TopicFinder] Рерайт ошибка: {$e->getMessage()}");
            return $text;
        }
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные методы: нормализация
    // ═════════════════════════════════════════════════════════════

    /**
     * Нормализовать статью из News API в единый формат.
     *
     * @param array  $article  Статья из NewsAPIService
     * @param string $category Категория
     * @return array Нормализованная тема
     */
    private function normalizeNewsArticle(array $article, string $category): array
    {
        $title = trim((string)($article['title'] ?? ''));
        $publishedAt = (string)($article['published_at'] ?? $article['publishedAt'] ?? '');

        return [
            'id'           => 'news_' . md5($title . ($article['url'] ?? '')),
            'source'       => 'newsapi',
            'title'        => $title,
            'url'          => (string)($article['url'] ?? ''),
            'description'  => (string)($article['description'] ?? ''),
            'category'     => $category,
            'published_at' => $publishedAt,
            'score'        => 0.0,
            'metadata'     => [
                'source_name' => (string)($article['source']['name'] ?? $article['source_name'] ?? ''),
                'author'      => (string)($article['author'] ?? ''),
                'image'       => (string)($article['urlToImage'] ?? $article['image'] ?? ''),
            ],
        ];
    }

    /**
     * Нормализовать пост из Reddit в единый формат.
     *
     * @param array $post Пост из RedditService (уже нормализованный)
     * @return array Нормализованная тема
     */
    private function normalizeRedditPost(array $post): array
    {
        $title     = trim((string)($post['title'] ?? ''));
        $subreddit = (string)($post['subreddit'] ?? '');
        $upvotes   = (int)($post['upvotes'] ?? 0);
        $comments  = (int)($post['comments'] ?? 0);
        $selftext  = trim((string)($post['selftext'] ?? ''));

        // Use selftext body when available (text posts), otherwise build a
        // one-line summary from post stats (link posts have no body text).
        if (!empty($selftext)) {
            $description = mb_strimwidth($selftext, 0, 500, '…');
        } else {
            $parts = [];
            if (!empty($subreddit)) { $parts[] = 'r/' . $subreddit; }
            if ($upvotes > 0)       { $parts[] = $upvotes . ' upvotes'; }
            if ($comments > 0)      { $parts[] = $comments . ' comments'; }
            $description = implode(' · ', $parts);
        }

        return [
            'id'           => 'reddit_' . ($post['id'] ?? md5($title)),
            'source'       => 'reddit',
            'title'        => $title,
            'url'          => (string)($post['url'] ?? ''),
            'description'  => $description ?: null,
            'category'     => !empty($subreddit) ? $subreddit : ($post['category'] ?? 'reddit'),
            'published_at' => (string)($post['created_at'] ?? ''),
            'score'        => 0.0,
            'metadata'     => [
                'subreddit' => $subreddit,
                'author'    => (string)($post['author'] ?? ''),
                'upvotes'   => $upvotes,
                'comments'  => $comments,
                'thumbnail' => (string)($post['thumbnail'] ?? ''),
                'priority'  => (int)($post['priority'] ?? 0),
            ],
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные методы: дедупликация
    // ═════════════════════════════════════════════════════════════

    /**
     * Удалить дубликаты тем по схожести заголовков.
     *
     * Для пары дубликатов оставляет тему из источника с более высоким приоритетом.
     * Приоритет: tmdb (70) > news (60) > sports (55) > football (53) > biathlon (51)
     * > reddit (50) > games (40) > github (30) > devices (20) > ai (10).
     *
     * @param array<int, array> $topics Все темы
     * @return array<int, array> Уникальные темы
     */
    private function deduplicateTopics(array $topics): array
    {
        if (count($topics) <= 1) {
            return $topics;
        }

        /** @var array<int, bool> Индексы дубликатов для удаления */
        $duplicateIndices = [];

        $count = count($topics);
        for ($i = 0; $i < $count; $i++) {
            // Если уже помечен как дубликат — пропускаем
            if (isset($duplicateIndices[$i])) {
                continue;
            }

            $titleA = $this->normalizeForDedup((string)($topics[$i]['title'] ?? ''));
            if (empty($titleA)) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if (isset($duplicateIndices[$j])) {
                    continue;
                }

                $titleB = $this->normalizeForDedup((string)($topics[$j]['title'] ?? ''));
                if (empty($titleB)) {
                    continue;
                }

                if ($this->isSimilar($titleA, $titleB)) {
                    // Определяем, какой оставить на основе приоритета источника
                    $priorityI = self::SOURCE_PRIORITY[$topics[$i]['source'] ?? ''] ?? 0;
                    $priorityJ = self::SOURCE_PRIORITY[$topics[$j]['source'] ?? ''] ?? 0;

                    if ($priorityJ > $priorityI) {
                        $duplicateIndices[$i] = true;
                        break; // i помечен — переходим к следующему
                    } else {
                        $duplicateIndices[$j] = true;
                    }
                }
            }
        }

        // Собираем результат, исключая дубликаты
        $unique = [];
        foreach ($topics as $index => $topic) {
            if (!isset($duplicateIndices[$index])) {
                $unique[] = $topic;
            }
        }

        return $unique;
    }

    /**
     * Проверить схожесть двух нормализованных заголовков.
     *
     * Использует similar_text для коротких строк и levenshtein для длинных.
     *
     * @param string $a Первый заголовок (нормализованный)
     * @param string $b Второй заголовок (нормализованный)
     * @return bool true если заголовки считаются дубликатами
     */
    private function isSimilar(string $a, string $b): bool
    {
        // Точное совпадение
        if ($a === $b) {
            return true;
        }

        // similar_text — надёжнее для разных длин
        $similarity = 0.0;
        similar_text($a, $b, $similarity);

        if ($similarity >= self::SIMILARITY_THRESHOLD) {
            return true;
        }

        // Если строки короткие — дополнительная проверка через levenshtein
        if (
            strlen($a) <= self::LEVENSHTEIN_MAX_LENGTH
            && strlen($b) <= self::LEVENSHTEIN_MAX_LENGTH
        ) {
            $maxLen = max(strlen($a), strlen($b));
            if ($maxLen > 0) {
                $distance = levenshtein($a, $b);
                $levenshteinSimilarity = (1 - $distance / $maxLen) * 100;
                return $levenshteinSimilarity >= self::SIMILARITY_THRESHOLD;
            }
        }

        return false;
    }

    /**
     * Нормализовать заголовок для дедупликации.
     *
     * @param string $title Исходный заголовок
     * @return string Нормализованный заголовок
     */
    private function normalizeForDedup(string $title): string
    {
        $title = mb_strtolower($title);
        // Убираем спецсимволы и лишние пробелы
        $title = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title) ?? $title;
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        return trim($title);
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные методы: БД операции
    // ═════════════════════════════════════════════════════════════

    /**
     * Найти существующую тему в БД по title + source.
     *
     * @param string $title  Заголовок
     * @param string $source Источник
     * @return array|null Запись из БД или null
     */
    private function findExistingTopic(string $title, string $source): ?array
    {
        $tableName = $this->getTopicsTableName();

        try {
            $sql = "SELECT id, title, source FROM {$tableName} WHERE title = :title AND source = :source LIMIT 1";
            $stmt = $this->modx->prepare($sql);
            if (!$stmt) {
                return null;
            }

            $stmt->bindValue(':title', $title);
            $stmt->bindValue(':source', $source);
            $stmt->execute();

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, "[TopicFinder] findExistingTopic ошибка: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Вставить новую тему в БД.
     *
     * @param array $topic Данные темы
     * @return void
     */
    private function insertTopicToDb(array $topic): void
    {
        $tableName = $this->getTopicsTableName();

        $sql = "INSERT INTO {$tableName}
            (topic_id, source, title, url, description, category, published_at, score, metadata, status, created_at, updated_at)
            VALUES
            (:topic_id, :source, :title, :url, :description, :category, :published_at, :score, :metadata, 0, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                score = IF(:score2 > score, :score2, score),
                metadata = :metadata2,
                updated_at = NOW()";

        try {
            $stmt = $this->modx->prepare($sql);
            if (!$stmt) {
                return;
            }

            $metadataJson = json_encode($topic['metadata'] ?? [], JSON_UNESCAPED_UNICODE);
            $scoreVal = (float)($topic['score'] ?? 0.0);
            $topicId = (string)($topic['id'] ?? $topic['topic_id'] ?? '');

            $stmt->bindValue(':topic_id', $topicId);
            $stmt->bindValue(':source', (string)($topic['source'] ?? ''));
            $stmt->bindValue(':title', (string)($topic['title'] ?? ''));
            $stmt->bindValue(':url', (string)($topic['url'] ?? ''));
            $stmt->bindValue(':description', (string)($topic['description'] ?? ''));
            $stmt->bindValue(':category', (string)($topic['category'] ?? ''));
            $stmt->bindValue(':published_at', (string)($topic['published_at'] ?? date('Y-m-d H:i:s')));
            $stmt->bindValue(':score', $scoreVal);
            $stmt->bindValue(':score2', $scoreVal);
            $stmt->bindValue(':metadata', $metadataJson);
            $stmt->bindValue(':metadata2', $metadataJson);

            $stmt->execute();
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[TopicFinder] insertTopicToDb ошибка: {$e->getMessage()}");
        }
    }

    /**
     * Обновить score и metadata существующей темы.
     *
     * @param int   $id       ID записи в БД
     * @param float $score    Новый score
     * @param array $metadata Новый metadata
     * @return void
     */
    private function updateTopicInDb(int $id, float $score, array $metadata): void
    {
        $tableName = $this->getTopicsTableName();

        $sql = "UPDATE {$tableName} SET score = :score, metadata = :metadata, updated_at = NOW() WHERE id = :id";

        try {
            $stmt = $this->modx->prepare($sql);
            if (!$stmt) {
                return;
            }

            $stmt->bindValue(':score', $score);
            $stmt->bindValue(':metadata', json_encode($metadata, JSON_UNESCAPED_UNICODE));
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);

            $stmt->execute();
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[TopicFinder] updateTopicInDb ошибка: {$e->getMessage()}");
        }
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные методы: утилиты
    // ═════════════════════════════════════════════════════════════

    /**
     * Собрать текущие тренды из уже полученных данных для AI.
     *
     * @return array<int, string> Список трендовых заголовков
     */
    private function collectCurrentTrends(): array
    {
        $trends = [];

        // Пробуем собрать тренды из кеша (если ранее делали запрос)
        $cachedNews = $this->cache->get(self::CACHE_PREFIX . 'trends_news');
        $cachedReddit = $this->cache->get(self::CACHE_PREFIX . 'trends_reddit');

        if ($cachedNews !== null && is_array($cachedNews)) {
            $trends = array_merge($trends, $cachedNews);
        }

        if ($cachedReddit !== null && is_array($cachedReddit)) {
            $trends = array_merge($trends, $cachedReddit);
        }

        // Если кэш пуст — делаем быстрый запрос
        if (empty($trends)) {
            try {
                $newsResult = $this->newsService->getRealTimeByTopics(['TECHNOLOGY'], 'en', 'US', '7d', 10);
                if (!empty($newsResult)) {
                    $newsTrends = array_map(function (array $article): string {
                        return (string)($article['title'] ?? '');
                    }, $newsResult);
                    $newsTrends = array_filter($newsTrends);
                    $this->cache->set(self::CACHE_PREFIX . 'trends_news', $newsTrends, 3600);
                    $trends = array_merge($trends, $newsTrends);
                }
            } catch (Throwable $e) {
                $this->modx->log(modX::LOG_LEVEL_DEBUG, "[TopicFinder] collectCurrentTrends news ошибка: {$e->getMessage()}");
            }

            try {
                $popularResult = $this->redditService->getPopularPosts('hot');
                if ($popularResult['success'] && !empty($popularResult['posts'])) {
                    $redditTrends = array_map(function (array $post): string {
                        return (string)($post['title'] ?? '');
                    }, array_slice($popularResult['posts'], 0, 10));
                    $redditTrends = array_filter($redditTrends);
                    $this->cache->set(self::CACHE_PREFIX . 'trends_reddit', $redditTrends, 3600);
                    $trends = array_merge($trends, $redditTrends);
                }
            } catch (Throwable $e) {
                $this->modx->log(modX::LOG_LEVEL_DEBUG, "[TopicFinder] collectCurrentTrends reddit ошибка: {$e->getMessage()}");
            }
        }

        // Убираем дубликаты
        return array_values(array_unique(array_filter($trends)));
    }
}