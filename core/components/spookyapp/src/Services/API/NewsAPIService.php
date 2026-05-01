<?php

declare(strict_types=1);

namespace SpookyApp\Services\API;

use MODX\Revolution\modX;
use SpookyApp\Services\Cache\CacheService;

/**
 * NewsAPIService — клиент для трёх новостных агрегаторов.
 *
 * SOURCE 1: RealTime News  → https://real-time-news-data.p.rapidapi.com
 *   - /topic-headlines?topic=TECHNOLOGY&limit=50&country=US&lang=en&time_published=7d
 *   - /search?query=...&limit=50&country=US&lang=en&time_published=1d
 *   - /top-headlines?limit=50&country=US&lang=en
 *   - /local-headlines?query=Belgorod&limit=50&country=RU&lang=ru
 *
 * SOURCE 2: TheNewsAPI    → https://api.thenewsapi.com/v1
 *   - /news/top?language=en&categories=tech&limit=50
 *
 * SOURCE 3: NewsData      → https://newsdata.io/api/1
 *   - /latest?category=technology&language=en
 */
class NewsAPIService extends APIService
{
    // ── Base URLs ────────────────────────────────────────────
    private const RAPIDAPI_BASE_URL   = 'https://real-time-news-data.p.rapidapi.com';
    private const THENEWSAPI_BASE_URL = 'https://api.thenewsapi.com/v1';
    private const NEWSDATA_BASE_URL   = 'https://newsdata.io/api/1';

    private const CACHE_PREFIX = 'spookyapp/news/';
    private const TTL          = 3600; // 1 hour

    // RealTime News valid topic values
    private const RT_VALID_TOPICS = [
        'TECHNOLOGY', 'SPORTS', 'ENTERTAINMENT',
        'BUSINESS', 'SCIENCE', 'HEALTH',
    ];

    // TheNewsAPI valid categories
    private const THENEWS_VALID_CATS = [
        'sports', 'science', 'business', 'health',
        'entertainment', 'tech', 'food', 'travel',
    ];

    // NewsData valid categories
    private const NEWSDATA_VALID_CATS = [
        'sports', 'technology', 'business', 'domestic',
        'entertainment', 'environment', 'food', 'health',
        'lifestyle', 'science', 'tourism', 'other',
    ];

    public function __construct(modX $modx, CacheService $cache)
    {
        parent::__construct($modx, $cache);
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  SOURCE 1 — RealTime News (RapidAPI)                   ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * RealTime News: fetch by topic categories.
     * URL: /topic-headlines?topic=TECHNOLOGY&limit=50&country=US&lang=en&time_published=7d
     *
     * @param array  $topics       e.g. ['TECHNOLOGY','SPORTS']
     * @param string $lang         e.g. 'en'
     * @param string $country      e.g. 'US'
     * @param string $timePeriod   '1h'|'1d'|'7d'|'1m'|'1y'|'anytime'
     * @param int    $limit
     * @return array normalized articles
     */
    public function getRealTimeByTopics(
        array  $topics,
        string $lang       = 'en',
        string $country    = 'US',
        string $timePeriod = '7d',
        int    $limit      = 50
    ): array {
        $validTopics = array_filter(
            array_map('strtoupper', $topics),
            fn($t) => in_array($t, self::RT_VALID_TOPICS, true)
        );

        if (empty($validTopics)) {
            $this->modx->log(modX::LOG_LEVEL_WARN,
                '[NewsAPIService][RealTime] No valid topics provided. Valid: '
                . implode(', ', self::RT_VALID_TOPICS));
            return [];
        }

        $articles = [];
        foreach ($validTopics as $topic) {
            $url = $this->buildUrl(self::RAPIDAPI_BASE_URL . '/topic-headlines', [
                'topic'          => $topic,
                'limit'          => min($limit, 150),
                'country'        => $country,
                'lang'           => $lang,
                'time_published' => $timePeriod,
            ]);

            $this->modx->log(modX::LOG_LEVEL_INFO,
                '[NewsAPIService][RealTime] → GET ' . $url);

            $cacheKey = self::CACHE_PREFIX . 'rt_topic/' . md5($url);
            $cached   = $this->getCache($cacheKey);
            if ($cached !== null) {
                $this->modx->log(modX::LOG_LEVEL_DEBUG,
                    '[NewsAPIService][RealTime] cache hit for topic=' . $topic);
                $articles = array_merge($articles, $cached);
                continue;
            }

            $result = $this->httpGet($url, $this->rtHeaders(), 15);

            $this->modx->log(modX::LOG_LEVEL_INFO,
                '[NewsAPIService][RealTime] ← HTTP '
                . ($result['http_code'] ?? '?')
                . ' topic=' . $topic
                . ' bytes=' . strlen($result['body'] ?? ''));

            if (!$result['success']) {
                $this->modx->log(modX::LOG_LEVEL_ERROR,
                    '[NewsAPIService][RealTime] Error topic=' . $topic
                    . ': ' . ($result['error'] ?? ''));
                continue;
            }

            $data = $result['data'] ?? [];
            if (($data['status'] ?? '') !== 'OK') {
                $this->modx->log(modX::LOG_LEVEL_WARN,
                    '[NewsAPIService][RealTime] API error topic=' . $topic
                    . ': ' . json_encode($data['error'] ?? $data));
                continue;
            }

            $normalized = $this->normalizeRealTimeArticles($data['data'] ?? [], 'rt_topics');
            // Tag each article with the requested topic so downstream can map it to a category
            $topicLower = strtolower($topic);
            foreach ($normalized as &$a) {
                $a['category'] = $topicLower;
            }
            unset($a);
            $this->setCache($cacheKey, $normalized, self::TTL);
            $articles = array_merge($articles, $normalized);
        }

        return $articles;
    }

    /**
     * RealTime News: keyword search.
     * URL: /search?query=...&limit=50&country=US&lang=en&time_published=1d
     */
    public function getRealTimeBySearch(
        string $query,
        string $lang       = 'en',
        string $country    = 'US',
        string $timePeriod = '7d',
        int    $limit      = 50
    ): array {
        $query = trim($query);
        if (empty($query)) {
            return [];
        }

        $url = $this->buildUrl(self::RAPIDAPI_BASE_URL . '/search', [
            'query'          => $query,
            'limit'          => min($limit, 100),
            'country'        => $country,
            'lang'           => $lang,
            'time_published' => $timePeriod,
        ]);

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[NewsAPIService][RealTime] → GET (search) ' . $url);

        $cacheKey = self::CACHE_PREFIX . 'rt_search/' . md5($url);
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->httpGet($url, $this->rtHeaders(), 15);

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[NewsAPIService][RealTime] ← HTTP '
            . ($result['http_code'] ?? '?')
            . ' search="' . $query . '"');

        if (!$result['success']) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[NewsAPIService][RealTime] Search error: ' . ($result['error'] ?? ''));
            return [];
        }

        $data = $result['data'] ?? [];
        if (($data['status'] ?? '') !== 'OK') {
            $this->modx->log(modX::LOG_LEVEL_WARN,
                '[NewsAPIService][RealTime] Search API error: '
                . json_encode($data['error'] ?? $data));
            return [];
        }

        $normalized = $this->normalizeRealTimeArticles($data['data'] ?? [], 'rt_search');
        $this->setCache($cacheKey, $normalized, self::TTL);
        return $normalized;
    }

    /**
     * RealTime News: top headlines by country/lang.
     * URL: /top-headlines?limit=50&country=US&lang=en
     */
    public function getRealTimeTopHeadlines(
        string $lang    = 'en',
        string $country = 'US',
        int    $limit   = 50
    ): array {
        $url = $this->buildUrl(self::RAPIDAPI_BASE_URL . '/top-headlines', [
            'limit'   => min($limit, 100),
            'country' => $country,
            'lang'    => $lang,
        ]);

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[NewsAPIService][RealTime] → GET (top-headlines) ' . $url);

        $cacheKey = self::CACHE_PREFIX . 'rt_top/' . md5($url);
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->httpGet($url, $this->rtHeaders(), 15);

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[NewsAPIService][RealTime] ← HTTP ' . ($result['http_code'] ?? '?') . ' top-headlines');

        if (!$result['success']) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[NewsAPIService][RealTime] top-headlines error: ' . ($result['error'] ?? ''));
            return [];
        }

        $data = $result['data'] ?? [];
        $normalized = $this->normalizeRealTimeArticles($data['data'] ?? [], 'rt_top');
        $this->setCache($cacheKey, $normalized, self::TTL);
        return $normalized;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  SOURCE 2 — TheNewsAPI                                 ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * TheNewsAPI: fetch top news by categories.
     * URL: /news/top?language=en&categories=tech,sports&limit=50
     *
     * @param array  $categories e.g. ['tech','sports']
     * @param string $lang       e.g. 'en'
     * @param int    $limit
     * @return array normalized articles
     */
    public function getTheNewsAPITop(
        array  $categories = [],
        string $lang       = 'en',
        int    $limit      = 50
    ): array {
        $validCats = array_filter(
            array_map('strtolower', $categories),
            fn($c) => in_array($c, self::THENEWS_VALID_CATS, true)
        );

        $params = [
            'language' => $lang,
            'limit'    => min($limit, 100),
            'api_token' => $this->modx->getOption('spookyapp.thenewsapi_key', null, ''),
        ];

        if (!empty($validCats)) {
            $params['categories'] = implode(',', $validCats);
        }

        $url = $this->buildUrl(self::THENEWSAPI_BASE_URL . '/news/top', $params);

        // Mask API token in log
        $logUrl = $this->buildUrl(self::THENEWSAPI_BASE_URL . '/news/top',
            array_merge($params, ['api_token' => '***']));
        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[NewsAPIService][TheNewsAPI] → GET ' . $logUrl);

        $cacheKey = self::CACHE_PREFIX . 'thenews/' . md5($url);
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG,
                '[NewsAPIService][TheNewsAPI] cache hit');
            return $cached;
        }

        $result = $this->httpGet($url, [], 15);

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[NewsAPIService][TheNewsAPI] ← HTTP '
            . ($result['http_code'] ?? '?'));

        if (!$result['success']) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[NewsAPIService][TheNewsAPI] Error: ' . ($result['error'] ?? ''));
            return [];
        }

        $data       = $result['data'] ?? [];
        $normalized = $this->normalizeTheNewsAPIArticles($data['data'] ?? []);
        $this->setCache($cacheKey, $normalized, self::TTL);
        return $normalized;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  SOURCE 3 — NewsData                                   ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * NewsData: fetch latest news by categories.
     * URL: /latest?category=sports,technology&language=en
     *
     * @param array  $categories e.g. ['sports','technology']
     * @param string $lang       e.g. 'en'
     * @param int    $limit      (NewsData free tier: max 10 per request)
     * @return array normalized articles
     */
    public function getNewsDataLatest(
        array  $categories = [],
        string $lang       = 'en',
        int    $limit      = 10
    ): array {
        $validCats = array_filter(
            array_map('strtolower', $categories),
            fn($c) => in_array($c, self::NEWSDATA_VALID_CATS, true)
        );

        $params = [
            'apikey'   => $this->modx->getOption('spookyapp.newsdata_key', null, ''),
            'language' => $lang,
        ];

        if (!empty($validCats)) {
            $params['category'] = implode(',', $validCats);
        }

        $url = $this->buildUrl(self::NEWSDATA_BASE_URL . '/latest', $params);

        // Mask API key in log
        $logUrl = $this->buildUrl(self::NEWSDATA_BASE_URL . '/latest',
            array_merge($params, ['apikey' => '***']));
        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[NewsAPIService][NewsData] → GET ' . $logUrl);

        $cacheKey = self::CACHE_PREFIX . 'newsdata/' . md5($url);
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG,
                '[NewsAPIService][NewsData] cache hit');
            return $cached;
        }

        $result = $this->httpGet($url, [], 15);

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[NewsAPIService][NewsData] ← HTTP ' . ($result['http_code'] ?? '?'));

        if (!$result['success']) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[NewsAPIService][NewsData] Error: ' . ($result['error'] ?? ''));
            return [];
        }

        $data       = $result['data'] ?? [];

        if (($data['status'] ?? '') !== 'success') {
            $this->modx->log(modX::LOG_LEVEL_WARN,
                '[NewsAPIService][NewsData] API error: '
                . json_encode($data['results']['message'] ?? $data));
            return [];
        }

        $normalized = $this->normalizeNewsDataArticles($data['results'] ?? []);
        $this->setCache($cacheKey, $normalized, self::TTL);
        return $normalized;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Normalization — RealTime News                         ║
    // ╚═════════════════════════════════════════════════════════╝

    private function normalizeRealTimeArticles(array $articles, string $subSource = 'realtime'): array
    {
        $normalized = [];
        foreach ($articles as $a) {
            $url = $a['link'] ?? $a['url'] ?? '';
            if (empty($url)) {
                continue;
            }
            $normalized[] = [
                'id'           => 'rt_' . md5($url),
                'source'       => 'realtime_news',
                'sub_source'   => $subSource,
                'title'        => trim($a['title'] ?? ''),
                'url'          => $url,
                'description'  => trim($a['snippet'] ?? $a['description'] ?? ''),
                'category'     => 'news',
                'published_at' => $a['published_datetime_utc'] ?? '',
                'image_url'    => $a['photo_url'] ?? $a['thumbnail_url'] ?? '',
                'score'        => 0.0,
                'metadata'     => [
                    'source_name'   => $a['source_name'] ?? '',
                    'source_url'    => $a['source_url'] ?? '',
                    'authors'       => $a['authors'] ?? [],
                    'article_id'    => $a['article_id'] ?? '',
                ],
            ];
        }
        return $normalized;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Normalization — TheNewsAPI                            ║
    // ╚═════════════════════════════════════════════════════════╝

    private function normalizeTheNewsAPIArticles(array $articles): array
    {
        $normalized = [];
        foreach ($articles as $a) {
            $url = $a['url'] ?? '';
            if (empty($url)) {
                continue;
            }
            $normalized[] = [
                'id'           => 'tna_' . ($a['uuid'] ?? md5($url)),
                'source'       => 'thenewsapi',
                'sub_source'   => 'thenewsapi',
                'title'        => trim($a['title'] ?? ''),
                'url'          => $url,
                'description'  => trim($a['description'] ?? $a['snippet'] ?? ''),
                'category'     => implode(',', (array)($a['categories'] ?? ['news'])),
                'published_at' => $a['published_at'] ?? '',
                'image_url'    => $a['image_url'] ?? '',
                'score'        => (float)($a['relevance_score'] ?? 0),
                'metadata'     => [
                    'source'   => $a['source'] ?? '',
                    'locale'   => $a['locale'] ?? '',
                    'keywords' => $a['keywords'] ?? '',
                    'uuid'     => $a['uuid'] ?? '',
                ],
            ];
        }
        return $normalized;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Normalization — NewsData                              ║
    // ╚═════════════════════════════════════════════════════════╝

    private function normalizeNewsDataArticles(array $articles): array
    {
        $normalized = [];
        foreach ($articles as $a) {
            $url = $a['link'] ?? $a['source_url'] ?? '';
            if (empty($url)) {
                continue;
            }
            $normalized[] = [
                'id'           => 'nd_' . ($a['article_id'] ?? md5($url)),
                'source'       => 'newsdata',
                'sub_source'   => 'newsdata',
                'title'        => trim($a['title'] ?? ''),
                'url'          => $url,
                'description'  => trim($a['description'] ?? $a['content'] ?? ''),
                'category'     => is_array($a['category'])
                    ? implode(',', $a['category'])
                    : ($a['category'] ?? 'news'),
                'published_at' => $a['pubDate'] ?? '',
                'image_url'    => $a['image_url'] ?? '',
                'score'        => 0.0,
                'metadata'     => [
                    'source_id'   => $a['source_id'] ?? '',
                    'source_name' => $a['source_name'] ?? '',
                    'country'     => is_array($a['country'])
                        ? implode(',', $a['country'])
                        : ($a['country'] ?? ''),
                    'language'    => $a['language'] ?? '',
                    'keywords'    => $a['keywords'] ?? [],
                    'creator'     => $a['creator'] ?? [],
                ],
            ];
        }
        return $normalized;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private helpers                                       ║
    // ╚═════════════════════════════════════════════════════════╝

    /** RapidAPI headers for real-time-news-data.p.rapidapi.com */
    private function rtHeaders(): array
    {
        $key = $this->modx->getOption('spookyapp.rapidapi_key', null, '');
        return [
            'X-RapidAPI-Key: ' . $key,
            'X-RapidAPI-Host: real-time-news-data.p.rapidapi.com',
        ];
    }

    private function getCache(string $key): ?array
    {
        $data = $this->cache->get($key);
        return is_array($data) ? $data : null;
    }

    private function setCache(string $key, array $data, int $ttl): void
    {
        $this->cache->set($key, $data, $ttl);
    }
}