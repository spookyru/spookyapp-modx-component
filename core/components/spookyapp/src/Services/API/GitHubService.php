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
 * Сервис для работы с GitHub REST API.
 *
 * Предоставляет данные о трендовых репозиториях, популярных проектах
 * и новинках для использования в TopicFinderService как источник тем категории IT.
 *
 * Архитектура:
 * - Primary: GitHub Search API (публичный доступ, 60 req/h)
 * - Enhanced: GitHub Token (5000 req/h) — из системных настроек
 *
 * API Docs: https://docs.github.com/en/rest/search/search
 *
 * Поддерживаемые сценарии:
 * - Трендовые репозитории по языкам (PHP, JS, Python, TS, Go)
 * - Трендовые по темам (machine-learning, devops, docker и т.д.)
 * - Популярные по количеству звёзд
 * - Агрегация для TopicFinderService
 */
class GitHubService extends APIService
{
    // ─── Конфигурация API ────────────────────────────────────────
    private const BASE_URL = 'https://api.github.com';

    // ─── Системные настройки ─────────────────────────────────────
    private const SETTING_TOKEN = 'spookyapp.github_token';

    // ─── Кеш TTL (секунды) ──────────────────────────────────────
    private const TTL_TRENDING = 21600;     // 6 часов
    private const TTL_POPULAR  = 86400;     // 24 часа

    // ─── Кеш префикс ────────────────────────────────────────────
    private const CACHE_PREFIX = 'github_';

    // ─── User-Agent (GitHub API требует) ─────────────────────────
    private const USER_AGENT = 'SpookyApp/1.0 (MODX Blog Topic Finder)';

    // ─── Размер страницы ─────────────────────────────────────────
    private const PER_PAGE = 20;

    // ─── Допустимые значения since ──────────────────────────────
    private const VALID_SINCE = ['daily', 'weekly', 'monthly'];

    // ─── Языки по умолчанию для агрегации ───────────────────────
    private const DEFAULT_LANGUAGES = ['php', 'javascript', 'python', 'typescript', 'go'];

    // ─── Минимум звёзд для TopicFinder ──────────────────────────
    private const MIN_STARS_FOR_TOPICS = 50;

    // ─── Ключевые слова нерелевантных репо ──────────────────────
    /** @var array<int, string> Паттерны для фильтрации */
    private const IRRELEVANT_PATTERNS = [
        'awesome-list',
        'cheatsheet',
        'cheat-sheet',
        'interview-questions',
        'interview-preparation',
        'coding-interview',
        'free-programming-books',
    ];

    /** @var array<int, string> Слова в description для осторожной фильтрации */
    private const IRRELEVANT_DESCRIPTION_KEYWORDS = [
        'curated list of',
        'collection of links',
        'list of resources',
        'interview questions',
    ];

    public function __construct(modX $modx, CacheService $cache)
    {
        parent::__construct($modx, $cache);
    }

    // ═════════════════════════════════════════════════════════════
    // 1. Трендовые репозитории
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить трендовые репозитории (недавно созданные, отсортированные по звёздам).
     *
     * @param string $language Язык программирования (пусто = все языки)
     * @param string $since    Период: 'daily', 'weekly', 'monthly'
     * @return array{success: bool, repos: array<int, array>, total_count: int, error: string|null}
     */
    public function getTrendingRepositories(string $language = '', string $since = 'daily'): array
    {
        $since = $this->validateSince($since);
        $langKey = !empty($language) ? mb_strtolower(trim($language)) : 'all';

        $cacheKey = self::CACHE_PREFIX . "trending_{$langKey}_{$since}";

        $result = $this->cachedRequest($cacheKey, self::TTL_TRENDING, function () use ($language, $since): ?array {
            return $this->fetchTrendingRepositories($language, $since);
        });

        if ($result === null) {
            return $this->errorResponse('Failed to fetch trending repositories');
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // 2. Тренды по нескольким языкам
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить тренды по нескольким языкам программирования.
     *
     * Для каждого языка делает отдельный запрос и объединяет результаты.
     *
     * @param array<int, string> $languages Языки (пусто = DEFAULT_LANGUAGES)
     * @param string             $since     Период: 'daily', 'weekly', 'monthly'
     * @return array{success: bool, repos: array<int, array>, by_language: array<string, int>, total_count: int, error: string|null}
     */
    public function getTrendingByLanguages(array $languages = [], string $since = 'weekly'): array
    {
        if (empty($languages)) {
            $languages = self::DEFAULT_LANGUAGES;
        }

        $since = $this->validateSince($since);
        $langsSorted = $languages;
        sort($langsSorted);
        $cacheKey = self::CACHE_PREFIX . 'trending_langs_' . md5(implode(',', $langsSorted) . '_' . $since);

        $result = $this->cachedRequest($cacheKey, self::TTL_TRENDING, function () use ($languages, $since): ?array {
            return $this->fetchTrendingByLanguages($languages, $since);
        });

        if ($result === null) {
            return [
                'success'     => false,
                'repos'       => [],
                'by_language' => [],
                'total_count' => 0,
                'error'       => 'Failed to fetch trending repositories by languages',
            ];
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // 3. Тренды по теме (topic)
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить трендовые репозитории по GitHub topic.
     *
     * @param string $topic Тема: 'machine-learning', 'web-development', 'devops', 'docker' и т.д.
     * @param string $since Период: 'daily', 'weekly', 'monthly'
     * @return array{success: bool, repos: array<int, array>, total_count: int, error: string|null}
     */
    public function getTrendingByTopic(string $topic, string $since = 'weekly'): array
    {
        $topic = mb_strtolower(trim($topic));
        if (empty($topic)) {
            return $this->errorResponse('Topic is empty');
        }

        $since = $this->validateSince($since);
        $cacheKey = self::CACHE_PREFIX . "trending_topic_{$topic}_{$since}";

        $result = $this->cachedRequest($cacheKey, self::TTL_TRENDING, function () use ($topic, $since): ?array {
            return $this->fetchTrendingByTopic($topic, $since);
        });

        if ($result === null) {
            return $this->errorResponse("Failed to fetch trending repos for topic '{$topic}'");
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // 4. Агрегация для TopicFinder
    // ═════════════════════════════════════════════════════════════

    /**
     * Агрегировать данные из GitHub для TopicFinderService.
     *
     * Получает тренды для PHP, JavaScript, Python,
     * фильтрует нерелевантные, дедуплицирует и конвертирует
     * в формат TopicFinder.
     *
     * @return array<int, array> Массив тем для TopicFinder
     */
    public function aggregateForTopicFinder(): array
    {
        $topics = [];

        // ── Трендовые по ключевым языкам ───────────────────────
        $trendingByLangs = $this->getTrendingByLanguages(
            ['php', 'javascript', 'python'],
            'weekly'
        );

        if ($trendingByLangs['success']) {
            foreach ($trendingByLangs['repos'] as $repo) {
                // Фильтруем по минимуму звёзд
                if (((int)($repo['stars'] ?? 0)) < self::MIN_STARS_FOR_TOPICS) {
                    continue;
                }

                // Фильтруем нерелевантные
                if (!$this->isRelevantRepo($repo)) {
                    continue;
                }

                $topics[] = $this->repoToTopic($repo, 'trending');
            }

            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[GitHub] Trending by languages for TopicFinder: ' . count($topics)
                . ' (by_language: ' . json_encode($trendingByLangs['by_language'] ?? []) . ')'
            );
        }

        // ── Дедупликация по repo id ────────────────────────────
        $seen = [];
        $unique = [];
        foreach ($topics as $topic) {
            $repoId = $topic['metadata']['github_id'] ?? '';
            if (!empty($repoId) && isset($seen[$repoId])) {
                continue;
            }
            $seen[$repoId] = true;
            $unique[] = $topic;
        }

        // ── Сортировка по звёздам (desc) ───────────────────────
        usort($unique, function (array $a, array $b): int {
            return ((int)($b['metadata']['stars'] ?? 0)) <=> ((int)($a['metadata']['stars'] ?? 0));
        });

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[GitHub] aggregateForTopicFinder: ' . count($unique) . ' уникальных тем'
        );

        return ['success' => true, 'topics' => $unique, 'error' => null];
    }

    // ═════════════════════════════════════════════════════════════
    // 5. Популярные по звёздам
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить популярные репозитории по количеству звёзд.
     *
     * @param int    $minStars Минимальное количество звёзд
     * @param string $language Язык программирования (пусто = все)
     * @return array{success: bool, repos: array<int, array>, total_count: int, error: string|null}
     */
    public function getPopularByStars(int $minStars = 100, string $language = ''): array
    {
        $langKey = !empty($language) ? mb_strtolower(trim($language)) : 'all';
        $cacheKey = self::CACHE_PREFIX . "popular_{$langKey}_s{$minStars}";

        $result = $this->cachedRequest($cacheKey, self::TTL_POPULAR, function () use ($minStars, $language): ?array {
            return $this->fetchPopularByStars($minStars, $language);
        });

        if ($result === null) {
            return $this->errorResponse('Failed to fetch popular repositories');
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // 6. Нормализация репозитория
    // ═════════════════════════════════════════════════════════════

    /**
     * Нормализовать данные репозитория из ответа GitHub API.
     *
     * @param array $rawRepo Сырые данные из API
     * @return array Нормализованные данные
     */
    public function normalizeRepository(array $rawRepo): array
    {
        return [
            'id'          => (int)($rawRepo['id'] ?? 0),
            'title'       => (string)($rawRepo['full_name'] ?? $rawRepo['name'] ?? ''),
            'full_name'   => (string)($rawRepo['full_name'] ?? ''),
            'name'        => (string)($rawRepo['name'] ?? ''),
            'description' => (string)($rawRepo['description'] ?? ''),
            'html_url'    => (string)($rawRepo['html_url'] ?? ''),
            'homepage'    => (string)($rawRepo['homepage'] ?? ''),
            'stars'       => (int)($rawRepo['stargazers_count'] ?? 0),
            'watchers'    => (int)($rawRepo['watchers_count'] ?? 0),
            'forks'       => (int)($rawRepo['forks_count'] ?? 0),
            'open_issues' => (int)($rawRepo['open_issues_count'] ?? 0),
            'language'    => (string)($rawRepo['language'] ?? ''),
            'topics'      => (array)($rawRepo['topics'] ?? []),
            'created_at'  => (string)($rawRepo['created_at'] ?? ''),
            'updated_at'  => (string)($rawRepo['updated_at'] ?? ''),
            'pushed_at'   => (string)($rawRepo['pushed_at'] ?? ''),
            'license'     => (string)($rawRepo['license']['spdx_id'] ?? $rawRepo['license']['name'] ?? ''),
            'owner'       => [
            'login'      => (string)($rawRepo['owner']['login'] ?? ''),
            'avatar_url' => (string)($rawRepo['owner']['avatar_url'] ?? ''),
            'type'       => (string)($rawRepo['owner']['type'] ?? ''),
            ],
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // 7. Расчёт фильтра даты
    // ═════════════════════════════════════════════════════════════

    /**
     * Преобразовать текстовый период в дату YYYY-MM-DD.
     *
     * @param string $since Период: 'daily', 'weekly', 'monthly'
     * @return string Дата в формате YYYY-MM-DD
     */
    public function calculateDateFilter(string $since): string
    {
        switch ($since) {
            case 'daily':
                return date('Y-m-d', strtotime('-1 day'));
            case 'weekly':
                return date('Y-m-d', strtotime('-7 days'));
            case 'monthly':
                return date('Y-m-d', strtotime('-30 days'));
            default:
                return date('Y-m-d', strtotime('-7 days'));
        }
    }

    // ═════════════════════════════════════════════════════════════
    // 8. Проверка релевантности репозитория
    // ═════════════════════════════════════════════════════════════

    /**
     * Проверить, является ли репозиторий релевантным для блога.
     *
     * Фильтрует awesome-lists, cheatsheets, pure-tutorial списки
     * без реального кода.
     *
     * @param array $repo Нормализованный репозиторий
     * @return bool true если репозиторий релевантный
     */
    public function isRelevantRepo(array $repo): bool
    {
        $fullName = mb_strtolower((string)($repo['full_name'] ?? ''));
        $description = mb_strtolower((string)($repo['description'] ?? ''));
        $topics = array_map('mb_strtolower', (array)($repo['topics'] ?? []));

        // Проверяем имя репозитория по нерелевантным паттернам
        foreach (self::IRRELEVANT_PATTERNS as $pattern) {
            if (strpos($fullName, $pattern) !== false) {
                return false;
            }

            // Проверяем topics
            if (in_array($pattern, $topics, true)) {
                return false;
            }
        }

        // Проверяем description
        foreach (self::IRRELEVANT_DESCRIPTION_KEYWORDS as $keyword) {
            if (strpos($description, $keyword) !== false) {
                return false;
            }
        }

        return true;
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные: HTTP-запросы
    // ═════════════════════════════════════════════════════════════

    /**
     * Выполнить GET-запрос к GitHub API.
     *
     * GitHub требует User-Agent header.
     * Опционально использует Authorization: Bearer {token} для увеличения rate limit.
     *
     * @param string               $endpoint Относительный путь
     * @param array<string, mixed> $params   Query-параметры
     * @return array{success: bool, data: mixed, error: string|null}
     */
    private function githubGet(string $endpoint, array $params = []): array
    {
        $url = $this->buildUrl(self::BASE_URL . $endpoint, $params);

        $headers = [
            'User-Agent: ' . self::USER_AGENT,
            'Accept: application/vnd.github.v3+json',
        ];

        // Опциональный токен для увеличения rate limit (60 → 5000 req/h)
        $token = $this->getGitHubToken();
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $this->modx->log(modX::LOG_LEVEL_DEBUG, "[GitHub] GET {$endpoint}");

        $response = $this->httpGet($url, $headers, 15);

        // Проверяем rate limit
        if (!$response['success']) {
            $this->checkRateLimitError($response);
        }

        return $response;
    }

    /**
     * Извлечь массив items из search response.
     *
     * @param array $response HTTP-ответ от githubGet
     * @return array{items: array, total_count: int}
     */
    private function extractSearchResults(array $response): array
    {
        if (!$response['success'] || !is_array($response['data'])) {
            return ['items' => [], 'total_count' => 0];
        }

        $data = $response['data'];
        $items = (array)($data['items'] ?? []);
        $totalCount = (int)($data['total_count'] ?? count($items));

        return ['items' => $items, 'total_count' => $totalCount];
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные: fetch-методы (без кеша)
    // ═════════════════════════════════════════════════════════════

    /**
     * Запросить трендовые репозитории из GitHub (без кеша).
     *
     * @param string $language Язык
     * @param string $since    Период
     * @return array|null
     */
    private function fetchTrendingRepositories(string $language, string $since): ?array
    {
        $dateFilter = $this->calculateDateFilter($since);

        // Формируем query string для GitHub Search
        $q = "created:>{$dateFilter}";
        if (!empty($language)) {
            $q .= ' language:' . trim($language);
        }

        $response = $this->githubGet('/search/repositories', [
            'q'        => $q,
            'sort'     => 'stars',
            'order'    => 'desc',
            'per_page' => self::PER_PAGE,
        ]);

        $extracted = $this->extractSearchResults($response);

        if (empty($extracted['items'])) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                "[GitHub] Trending repos (lang={$language}, since={$since}): пустой результат"
            );
            return null;
        }

        $repos = array_map([$this, 'normalizeRepository'], $extracted['items']);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[GitHub] Trending (lang={$language}, since={$since}): " . count($repos) . ' репозиториев'
        );

        return [
            'success'     => true,
            'repos'       => $repos,
            'total_count' => $extracted['total_count'],
            'error'       => null,
        ];
    }

    /**
     * Запросить тренды по нескольким языкам (без кеша).
     *
     * @param array<int, string> $languages Языки
     * @param string             $since     Период
     * @return array|null
     */
    private function fetchTrendingByLanguages(array $languages, string $since): ?array
    {
        $allRepos = [];
        $byLanguage = [];

        foreach ($languages as $language) {
            $language = mb_strtolower(trim($language));
            if (empty($language)) {
                continue;
            }

            try {
                $result = $this->fetchTrendingRepositories($language, $since);

                if ($result !== null && !empty($result['repos'])) {
                    $allRepos = array_merge($allRepos, $result['repos']);
                    $byLanguage[$language] = count($result['repos']);

                    $this->modx->log(
                        modX::LOG_LEVEL_DEBUG,
                        "[GitHub] Language '{$language}': " . count($result['repos']) . ' repos'
                    );
                } else {
                    $byLanguage[$language] = 0;
                }
            } catch (Throwable $e) {
                $this->modx->log(
                    modX::LOG_LEVEL_ERROR,
                    "[GitHub] Ошибка для языка '{$language}': {$e->getMessage()}"
                );
                $byLanguage[$language] = 0;
            }
        }

        if (empty($allRepos)) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[GitHub] Trending by languages: нет результатов');
            return null;
        }

        // Дедупликация по repo ID
        $seen = [];
        $unique = [];
        foreach ($allRepos as $repo) {
            $repoId = (int)($repo['id'] ?? 0);
            if ($repoId > 0 && isset($seen[$repoId])) {
                continue;
            }
            $seen[$repoId] = true;
            $unique[] = $repo;
        }

        // Сортировка по звёздам
        usort($unique, function (array $a, array $b): int {
            return ((int)($b['stars'] ?? 0)) <=> ((int)($a['stars'] ?? 0));
        });

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[GitHub] Trending by languages: ' . count($unique) . ' unique repos'
            . ' (before dedup: ' . count($allRepos) . ')'
        );

        return [
            'success'     => true,
            'repos'       => $unique,
            'by_language' => $byLanguage,
            'total_count' => count($unique),
            'error'       => null,
        ];
    }

    /**
     * Запросить тренды по теме (без кеша).
     *
     * @param string $topic Тема
     * @param string $since Период
     * @return array|null
     */
    private function fetchTrendingByTopic(string $topic, string $since): ?array
    {
        $dateFilter = $this->calculateDateFilter($since);

        $q = "topic:{$topic} created:>{$dateFilter}";

        $response = $this->githubGet('/search/repositories', [
            'q'        => $q,
            'sort'     => 'stars',
            'order'    => 'desc',
            'per_page' => self::PER_PAGE,
        ]);

        $extracted = $this->extractSearchResults($response);

        if (empty($extracted['items'])) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                "[GitHub] Trending by topic '{$topic}' (since={$since}): пустой результат"
            );
            return null;
        }

        $repos = array_map([$this, 'normalizeRepository'], $extracted['items']);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[GitHub] Topic '{$topic}' (since={$since}): " . count($repos) . ' repos'
        );

        return [
            'success'     => true,
            'repos'       => $repos,
            'total_count' => $extracted['total_count'],
            'error'       => null,
        ];
    }

    /**
     * Запросить популярные по звёздам (без кеша).
     *
     * @param int    $minStars Минимум звёзд
     * @param string $language Язык
     * @return array|null
     */
    private function fetchPopularByStars(int $minStars, string $language): ?array
    {
        $q = "stars:>{$minStars}";
        if (!empty($language)) {
            $q .= ' language:' . trim($language);
        }

        $response = $this->githubGet('/search/repositories', [
            'q'        => $q,
            'sort'     => 'stars',
            'order'    => 'desc',
            'per_page' => self::PER_PAGE,
        ]);

        $extracted = $this->extractSearchResults($response);

        if (empty($extracted['items'])) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                "[GitHub] Popular (min_stars={$minStars}, lang={$language}): пустой результат"
            );
            return null;
        }

        $repos = array_map([$this, 'normalizeRepository'], $extracted['items']);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[GitHub] Popular (min_stars={$minStars}): " . count($repos) . ' repos'
        );

        return [
            'success'     => true,
            'repos'       => $repos,
            'total_count' => $extracted['total_count'],
            'error'       => null,
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные: конвертация в TopicFinder
    // ═════════════════════════════════════════════════════════════

    /**
     * Конвертировать нормализованный репозиторий в формат темы TopicFinder.
     *
     * @param array  $repo      Нормализованный репозиторий
     * @param string $subSource Под-источник: 'trending', 'popular', 'topic'
     * @return array Тема в формате TopicFinder
     */
    private function repoToTopic(array $repo, string $subSource = 'trending'): array
    {
        $fullName = (string)($repo['full_name'] ?? '');
        $description = (string)($repo['description'] ?? '');
        $language = (string)($repo['language'] ?? '');
        $stars = (int)($repo['stars'] ?? 0);
        $topics = (array)($repo['topics'] ?? []);

        // Заголовок: имя + краткое описание
        $title = $fullName;
        if (!empty($description)) {
            // Ограничиваем описание в заголовке до 100 символов
            $shortDesc = mb_strlen($description) > 100
                ? mb_substr($description, 0, 100) . '…'
                : $description;
            $title .= ' — ' . $shortDesc;
        }

        // Подробное описание
        $detailedDescription = $this->buildRepoDescription($repo, $subSource);

        return [
            'id'           => 'github_' . ($repo['id'] ?? 0),
            'source'       => 'github',
            'title'        => $title,
            'url'          => (string)($repo['html_url'] ?? ''),
            'description'  => $detailedDescription,
            'category'     => 'IT',
            'published_at' => $this->formatGitHubDate((string)($repo['created_at'] ?? '')),
            'score'        => 0.0,
            'metadata'     => [
                'github_id'   => (int)($repo['id'] ?? 0),
                'sub_source'  => $subSource,
                'full_name'   => $fullName,
                'stars'       => $stars,
                'forks'       => (int)($repo['forks'] ?? 0),
                'watchers'    => (int)($repo['watchers'] ?? 0),
                'open_issues' => (int)($repo['open_issues'] ?? 0),
                'language'    => $language,
                'topics'      => $topics,
                'license'     => (string)($repo['license'] ?? ''),
                'owner'       => (string)($repo['owner']['login'] ?? ''),
                'owner_type'  => (string)($repo['owner']['type'] ?? ''),
                'homepage'    => (string)($repo['homepage'] ?? ''),
            ],
        ];
    }

    /**
     * Сформировать описание репозитория для TopicFinder.
     *
     * @param array  $repo      Нормализованный репозиторий
     * @param string $subSource Тип
     * @return string
     */
    private function buildRepoDescription(array $repo, string $subSource): string
    {
        $parts = [];

        $fullName = (string)($repo['full_name'] ?? '');
        $description = (string)($repo['description'] ?? '');
        $language = (string)($repo['language'] ?? '');
        $stars = (int)($repo['stars'] ?? 0);
        $forks = (int)($repo['forks'] ?? 0);
        $topics = (array)($repo['topics'] ?? []);

        // Тип
        switch ($subSource) {
            case 'trending':
                $parts[] = "Trending GitHub repo: {$fullName}";
                break;
            case 'popular':
                $parts[] = "Popular GitHub repo: {$fullName}";
                break;
            case 'topic':
                $parts[] = "GitHub repo: {$fullName}";
                break;
            default:
                $parts[] = "GitHub: {$fullName}";
        }

        if (!empty($description)) {
            $parts[] = $description;
        }

        // Статистика
        $stats = [];
        if ($stars > 0) {
            $stats[] = "⭐ {$stars}";
        }
        if ($forks > 0) {
            $stats[] = "🍴 {$forks}";
        }
        if (!empty($language)) {
            $stats[] = "Language: {$language}";
        }
        if (!empty($stats)) {
            $parts[] = implode(' | ', $stats);
        }

        // Topics
        if (!empty($topics)) {
            $parts[] = 'Topics: ' . implode(', ', array_slice($topics, 0, 8));
        }

        return implode('. ', $parts) . '.';
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные: утилиты
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить GitHub token из системных настроек (опциональный).
     *
     * @return string|null Token или null
     */
    private function getGitHubToken(): ?string
    {
        $token = $this->modx->getOption(self::SETTING_TOKEN, null, '');
        if (empty($token)) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[GitHub] Token не настроен — используем публичный доступ (60 req/h)'
            );
            return null;
        }
        return $token;
    }

    /**
     * Валидировать параметр since.
     *
     * @param string $since Запрошенный период
     * @return string Валидный период
     */
    private function validateSince(string $since): string
    {
        if (!in_array($since, self::VALID_SINCE, true)) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                "[GitHub] Неизвестный since: '{$since}', используем 'weekly'"
            );
            return 'weekly';
        }
        return $since;
    }

    /**
     * Проверить и залогировать ошибку rate limit.
     *
     * @param array $response Ответ от API
     * @return void
     */
    private function checkRateLimitError(array $response): void
    {
        $error = (string)($response['error'] ?? '');
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $message = (string)($data['message'] ?? '');

        if (
            stripos($error, '403') !== false
            || stripos($message, 'rate limit') !== false
            || stripos($message, 'API rate limit exceeded') !== false
        ) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[GitHub] Rate limit exceeded! '
                . ($this->getGitHubToken() !== null
                    ? 'Используется token (5000 req/h).'
                    : 'Token не настроен — лимит 60 req/h. Добавьте spookyapp.github_token')
            );
        }
    }

    /**
     * Форматировать дату GitHub в ISO 8601.
     *
     * GitHub возвращает: "2025-01-15T10:30:00Z"
     *
     * @param string $date Дата из GitHub API
     * @return string ISO 8601 дата
     */
    private function formatGitHubDate(string $date): string
    {
        if (empty($date)) {
            return date('Y-m-d\TH:i:s\Z');
        }

        // GitHub уже возвращает ISO 8601, просто валидируем
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $date)) {
            return $date;
        }

        return $date;
    }

    // ═════════════════════════════════════════════════════════════
    // 9. Поиск репозиториев по имени / ключевому слову
    // ═════════════════════════════════════════════════════════════

    /**
     * Поиск репозиториев по имени или ключевому слову (без фильтра даты).
     *
     * Подходит для поиска конкретного репозитория по имени (e.g. "ModExtra3"),
     * в отличие от getTrendingByTopic который фильтрует по topic-тегу и дате.
     *
     * @param string $query    Запрос: имя репо, ключевое слово, «owner/repo» и т.д.
     * @param int    $perPage  Количество результатов (max 30)
     * @return array{success: bool, repos: array<int, array>, total_count: int, error: string|null}
     */
    public function searchRepositories(string $query, int $perPage = 20): array
    {
        $query = trim($query);
        if (empty($query)) {
            return $this->errorResponse('Search query is empty');
        }

        $perPage  = max(1, min(30, $perPage));
        $cacheKey = self::CACHE_PREFIX . 'search_' . md5($query . '_' . $perPage);

        $result = $this->cachedRequest($cacheKey, 300, function () use ($query, $perPage): ?array {
            return $this->fetchSearchRepositories($query, $perPage);
        });

        if ($result === null) {
            return $this->errorResponse("Failed to search repositories for '{$query}'");
        }

        return $result;
    }

    /**
     * Выполнить поиск репозиториев (без кеша).
     *
     * Строит запрос вида "{query} in:name,description" — ищет в имени и описании.
     * Если запрос содержит «/» — ищет точное совпадение репозитория (owner/repo).
     *
     * @param string $query   Запрос
     * @param int    $perPage Размер страницы
     * @return array|null
     */
    private function fetchSearchRepositories(string $query, int $perPage): ?array
    {
        // Если «owner/repo» — ищем точное имя
        if (strpos($query, '/') !== false) {
            $q = 'repo:' . $query;
        } else {
            $q = $query . ' in:name,description';
        }

        $response = $this->githubGet('/search/repositories', [
            'q'        => $q,
            'sort'     => 'stars',
            'order'    => 'desc',
            'per_page' => $perPage,
        ]);

        $extracted = $this->extractSearchResults($response);

        if (empty($extracted['items'])) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                "[GitHub] searchRepositories('{$query}'): пустой результат"
            );
            return null;
        }

        $repos = array_map([$this, 'normalizeRepository'], $extracted['items']);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[GitHub] searchRepositories('{$query}'): " . count($repos) . ' репозиториев'
        );

        return [
            'success'     => true,
            'repos'       => $repos,
            'total_count' => $extracted['total_count'],
            'error'       => null,
        ];
    }

    /**
     * Получить детали одного репозитория по numeric ID или «owner/repo».
     *
     * Используется процессором getdetails при нажатии «Детали» в GitHub-чанко-генераторе.
     *
     * @param string $id Числовой ID или строка «owner/repo»
     * @return array Нормализованный репозиторий (пустой массив при ошибке)
     */
    public function getRepositoryById(string $id): array
    {
        $cacheKey = self::CACHE_PREFIX . 'repo_' . md5($id);

        $result = $this->cachedRequest($cacheKey, 600, function () use ($id): ?array {
            // Числовой ID → /repositories/{id}
            if (ctype_digit($id)) {
                $response = $this->githubGet('/repositories/' . $id);
            } else {
                // «owner/repo» → /repos/{owner}/{repo}
                $response = $this->githubGet('/repos/' . ltrim($id, '/'));
            }

            if (!$response['success'] || !is_array($response['data'])) {
                $this->modx->log(
                    modX::LOG_LEVEL_WARN,
                    "[GitHub] getRepositoryById('{$id}'): ошибка API"
                );
                return null;
            }

            return $this->normalizeRepository($response['data']);
        });

        return $result ?? [];
    }

    /**
     * Сформировать стандартный ответ ошибки.
     *
     * @param string $error Сообщение об ошибке
     * @return array{success: bool, repos: array, total_count: int, error: string}
     */
    private function errorResponse(string $error): array
    {
        $this->modx->log(modX::LOG_LEVEL_ERROR, "[GitHub] {$error}");

        return [
            'success'     => false,
            'repos'       => [],
            'total_count' => 0,
            'error'       => $error,
        ];
    }
}