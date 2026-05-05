<?php

declare(strict_types=1);

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modX;

/**
 * SpookyAppChunkGeneratorSearchProcessor — поиск контента для Chunk Generator.
 *
 * ═══════════════════════════════════════════════════════════════
 * Маршрутизирует поисковый запрос к соответствующему API-сервису
 * в зависимости от типа контента (movie, tv, game, device, product, football, biathlon, github).
 *
 * Параметры:
 *   - type (string):  Тип контента (movie|tv|person|game|device|product|football|biathlon|flashsport|sportapi|github)
 *   - query (string): Поисковый запрос
 *   - year (int):     Год выпуска (optional, для movie/tv)
 *   - page (int):     Номер страницы (optional, default 1)
 *   - limit (int):    Количество результатов (optional, default 10)
 *
 * Возвращает:
 *   - success: true/false
 *   - results: массив найденных элементов
 *   - total: общее количество результатов
 *   - page: текущая страница
 * ═══════════════════════════════════════════════════════════════
 *
 * @package SpookyApp
 * @subpackage Processors\ChunkGenerator
 */
class SpookyAppChunkGeneratorSearchProcessor extends Processor
{
    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constants                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var array<string> Допустимые типы контента */
    private const VALID_TYPES = ['movie', 'tv', 'person', 'game', 'device', 'product', 'football', 'biathlon', 'flashsport', 'sportapi', 'github'];

    /** @var string Класс модуля */
    public $classKey = 'SpookyAppChunkGeneratorSearch';

    /** @var string Лексикон */
    public $languageTopics = ['spookyapp:chunkgenerator'];

    /** @var \SpookyApp\Services\Proxy\ProxyConfig */
    private \SpookyApp\Services\Proxy\ProxyConfig $proxy;

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Initialize                                             ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Инициализация процессора.
     *
     * Загружает autoload и проверяет обязательные параметры.
     *
     * @return bool|string true при успехе, строка с ошибкой
     */
    public function initialize(): bool|string
    {
        // ── Autoload ─────────────────────────────────────────
        $autoload = MODX_CORE_PATH . 'components/spookyapp/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
        $this->proxy = \SpookyApp\Services\Proxy\ProxyConfig::fromModx($this->modx);
        // ── Валидация обязательных параметров ─────────────────
        $type = trim((string)$this->getProperty('type', ''));
        if (empty($type)) {
            return $this->modx->lexicon('spookyapp.chunkgenerator.err_type_required')
                ?: 'Parameter "type" is required';
        }

        if (!in_array($type, self::VALID_TYPES, true)) {
            return ($this->modx->lexicon('spookyapp.chunkgenerator.err_type_invalid')
                ?: 'Invalid type. Allowed: ') . implode(', ', self::VALID_TYPES);
        }

        $query = trim((string)$this->getProperty('query', ''));
        if (empty($query)) {
            return $this->modx->lexicon('spookyapp.chunkgenerator.err_query_required')
                ?: 'Parameter "query" is required';
        }

        return parent::initialize();
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Process                                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Основная логика процессора.
     *
     * Маршрутизирует запрос к нужному API-сервису и возвращает
     * унифицированный массив результатов.
     *
     * @return array Результат выполнения
     */
    public function process(): array
    {
        $type     = trim((string)$this->getProperty('type'));
        $query    = trim((string)$this->getProperty('query'));
        $year     = (int)$this->getProperty('year', 0);
        $page     = max(1, (int)$this->getProperty('page', 1));
        $limit    = max(1, min(50, (int)$this->getProperty('limit', 10)));
        $country  = trim((string)$this->getProperty('country', 'us')) ?: 'us';
        $language = trim((string)$this->getProperty('language', 'ru')) ?: 'ru';

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[ChunkGenerator:Search] type=' . $type
            . ' query="' . $query . '"'
            . ($year > 0 ? ' year=' . $year : '')
            . ' page=' . $page
            . ' country=' . $country . ' language=' . $language
        );

        try {
            $result = match ($type) {
                'movie'    => $this->searchMovies($query, $year, $page),
                'tv'       => $this->searchTVShows($query, $year, $page),
                'person'   => $this->searchPerson($query, $page),
                'game'     => $this->searchGames($query, $page, $limit),
                'device'   => $this->searchDevices($query, $page, $limit),
                'product'  => $this->searchProducts($query, $page, $limit, $country, $language),
                'football'   => $this->searchFootball($query, $page, $limit),
                'biathlon'   => $this->searchBiathlon($query, $page, $limit),
                'flashsport' => $this->searchFlashSport($query, $page, $limit, $language),
                'sportapi'   => $this->searchSportApi($query, $page, $limit),
                'github'     => $this->searchGitHub($query, $page, $limit),
                default    => throw new \InvalidArgumentException('Unknown type: ' . $type),
            };

            return $this->success('', [
                'type'    => $type,
                'query'   => $query,
                'results' => $this->rewriteTmdbImages($result['results'] ?? []),
                'total'   => $result['total'] ?? 0,
                'page'    => $result['page'] ?? $page,
            ]);

        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[ChunkGenerator:Search] Error: ' . $e->getMessage()
            );
            return $this->failure(
                $this->modx->lexicon('spookyapp.chunkgenerator.err_search_failed')
                    ?: 'Search failed: ' . $e->getMessage()
            );
        }
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Image URL Rewrite                             ║
    // ╚═════════════════════════════════════════════════════════╝

    private function rewriteTmdbImages(array $results): array
    {
        array_walk_recursive($results, function (&$value): void {
            if (is_string($value) && str_contains($value, '://image.tmdb.org/')) {
                $value = $this->proxy->rewriteImageForBrowser($value);
            }
        });
        return $results;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Search by Type                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Поиск фильмов через TMDBService.
     *
     * @param string $query Запрос
     * @param int    $year  Год выпуска (0 = любой)
     * @param int    $page  Номер страницы
     *
     * @return array{results: array, total: int, page: int}
     */
    private function searchMovies(string $query, int $year, int $page): array
    {
        $service = $this->getTMDBService();

        // TMDBService::searchMovies() returns a flat normalized array directly,
        // NOT a {results:[], total_results:N, page:N} wrapper.
        $data = $service->searchMovies($query, $year > 0 ? $year : null, 'ru-RU');

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[ChunkGenerator:Search] searchMovies raw count: ' . count($data)
        );

        $results = array_map(function (array $item): array {
            return [
                'id'             => $item['id'] ?? 0,
                'title'          => $item['title'] ?? '',
                'original_title' => $item['original_title'] ?? '',
                'year'           => !empty($item['release_date'])
                    ? substr($item['release_date'], 0, 4) : '',
                'overview'       => mb_substr($item['overview'] ?? '', 0, 200),
                'poster'         => !empty($item['poster_path'])
                    ? 'https://image.tmdb.org/t/p/w500' . $item['poster_path'] : null,
                'rating'         => $item['vote_average'] ?? 0,
                'vote_count'     => $item['vote_count'] ?? 0,
            ];
        }, $data);

        return [
            'results' => $results,
            'total'   => count($results),
            'page'    => $page,
        ];
    }

    /**
     * Поиск сериалов через TMDBService.
     *
     * @param string $query Запрос
     * @param int    $year  Год выпуска (0 = любой)
     * @param int    $page  Номер страницы
     *
     * @return array{results: array, total: int, page: int}
     */
    private function searchTVShows(string $query, int $year, int $page): array
    {
        $service = $this->getTMDBService();

        // TMDBService::searchTVShows() also returns a flat normalized array directly.
        $data = $service->searchTVShows($query, $year > 0 ? $year : null, 'ru-RU');

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[ChunkGenerator:Search] searchTVShows raw count: ' . count($data)
        );

        $results = array_map(function (array $item): array {
            return [
                'id'             => $item['id'] ?? 0,
                'title'          => $item['name'] ?? '',
                'original_title' => $item['original_name'] ?? '',
                'year'           => !empty($item['first_air_date'])
                    ? substr($item['first_air_date'], 0, 4) : '',
                'overview'       => mb_substr($item['overview'] ?? '', 0, 200),
                'poster'         => !empty($item['poster_path'])
                    ? 'https://image.tmdb.org/t/p/w500' . $item['poster_path'] : null,
                'rating'         => $item['vote_average'] ?? 0,
                'vote_count'     => $item['vote_count'] ?? 0,
            ];
        }, $data);

        return [
            'results' => $results,
            'total'   => count($results),
            'page'    => $page,
        ];
    }

    /**
     * Поиск персон (актёры, режиссёры) через TMDBService.
     *
     * @param string $query Запрос (имя персоны)
     * @param int    $page  Номер страницы
     *
     * @return array{results: array, total: int, page: int}
     */
    private function searchPerson(string $query, int $page): array
    {
        $service = $this->getTMDBService();

        $data = $service->searchPerson($query, 'ru-RU');

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[ChunkGenerator:Search] searchPerson raw count: ' . count($data)
        );

        $results = array_map(function (array $item): array {
            return [
                'id'             => $item['id'] ?? 0,
                'title'          => $item['name'] ?? '',
                'original_title' => $item['name'] ?? '',
                'year'           => '',
                'overview'       => $item['known_for_department'] ?? '',
                'poster'         => !empty($item['profile_path'])
                    ? 'https://image.tmdb.org/t/p/w185' . $item['profile_path'] : null,
                'rating'         => $item['popularity'] ?? 0,
                'vote_count'     => 0,
            ];
        }, $data);

        return [
            'results' => $results,
            'total'   => count($results),
            'page'    => $page,
        ];
    }

    /**
     * Поиск игр через GamesAPIService.
     *
     * @param string $query Запрос
     * @param int    $page  Номер страницы
     * @param int    $limit Лимит
     *
     * @return array{results: array, total: int, page: int}
     */
    private function searchGames(string $query, int $page, int $limit): array
    {
        $service = $this->getGamesService();

        $data = $service->searchGames($query, $page);

        $results = array_map(function (array $item): array {
            return [
                'id'           => $item['id'] ?? 0,
                'title'        => $item['name'] ?? '',
                'original_title' => $item['name'] ?? '',
                'year'         => !empty($item['released'])
                    ? substr($item['released'], 0, 4) : '',
                'overview'     => mb_substr($item['description_raw'] ?? $item['description'] ?? '', 0, 200),
                'poster'       => $item['background_image'] ?? null,
                'rating'       => $item['rating'] ?? 0,
                'vote_count'   => $item['ratings_count'] ?? 0,
            ];
        }, $data['results'] ?? []);

        return [
            'results' => $results,
            'total'   => $data['count'] ?? 0,
            'page'    => $page,
        ];
    }

    /**
     * Поиск мобильных устройств через MobileDevicesAPIService.
     *
     * @param string $query Запрос
     * @param int    $page  Номер страницы
     * @param int    $limit Лимит
     *
     * @return array{results: array, total: int, page: int}
     */
    private function searchDevices(string $query, int $page, int $limit): array
    {
        $service = $this->getDevicesService();
        $source  = trim((string)$this->getProperty('source', 'rapidapi'));

        // ─── MobileApi.dev ────────────────────────────────────
        if ($source === 'mobileapi') {
            $data       = $service->searchDevices($query, $page);
            $allResults = array_map(function (array $item): array {
                $overview = implode(' | ', array_filter([
                    $item['brand'] ?? '',
                    $item['os']    ?? '',
                    $item['chipset'] ?? '',
                ]));
                return [
                    'id'             => $item['id'] ?? '',
                    'title'          => $item['full_name'] ?? $item['name'] ?? '',
                    'original_title' => $item['name'] ?? '',
                    'year'           => !empty($item['announced_date'])
                        ? substr($item['announced_date'], 0, 4) : '',
                    'overview'       => trim($overview, ' |'),
                    'poster'         => $item['image_url'] ?? null,
                    'rating'         => null,
                    'vote_count'     => null,
                ];
            }, $data['devices'] ?? []);

            $offset  = ($page - 1) * $limit;
            $results = array_slice($allResults, $offset, $limit);

            return [
                'results' => $results,
                'total'   => $data['total_count'] ?? count($allResults),
                'page'    => $page,
            ];
        }

        // ─── RapidAPI (default) ───────────────────────────────
        $data = $service->searchPhones($query);

        $allResults = array_map(function (array $item): array {
            // normalized keys from normalizePhonesListResponse: name, slug, image, short_spec
            return [
                'id'             => $item['slug'] ?? '',
                'title'          => $item['name'] ?? '',
                'original_title' => $item['name'] ?? '',
                'year'           => '',
                'overview'       => $item['short_spec'] ?? '',
                'poster'         => $item['image'] ?? null,
                'rating'         => null,
                'vote_count'     => null,
            ];
        }, $data['phones'] ?? []);

        $offset  = ($page - 1) * $limit;
        $results = array_slice($allResults, $offset, $limit);

        return [
            'results' => $results,
            'total'   => count($allResults),
            'page'    => $page,
        ];
    }

    /**
     * Поиск товаров через ProductSearchService или AmazonProductService.
     *
     * Маршрутизация зависит от параметра source:
     *   - source=amazon → AmazonProductService
     *   - иначе         → ProductSearchService (Real-Time Product Search)
     *
     * @param string $query   Запрос
     * @param int    $page    Номер страницы
     * @param int    $limit   Лимит
     * @param string $country Код страны
     * @param string $language Код языка
     *
     * @return array{results: array, total: int, page: int}
     */
    private function searchProducts(string $query, int $page, int $limit, string $country = 'us', string $language = 'ru'): array
    {
        $source = trim((string)$this->getProperty('source', ''));

        // ── Amazon ───────────────────────────────────────────
        if ($source === 'amazon') {
            $service = $this->getAmazonService();
            $data    = $service->searchProducts($query, [
                'page'    => $page,
                'country' => strtoupper($country),
            ]);

            $results = array_map(function (array $item): array {
                return [
                    'id'             => $item['product_id'] ?? '',
                    'title'          => $item['title'] ?? '',
                    'original_title' => $item['title'] ?? '',
                    'year'           => '',
                    'overview'       => '',
                    'poster'         => $item['image_url'] ?? null,
                    'rating'         => $item['rating'] ?? null,
                    'vote_count'     => $item['reviews_count'] ?? null,
                    'price'          => $item['price'] ?? null,
                    'brand'          => $item['brand'] ?? null,
                    'is_best_seller'   => $item['is_best_seller'] ?? false,
                    'is_amazon_choice' => $item['is_amazon_choice'] ?? false,
                    'sales_volume'     => $item['sales_volume'] ?? null,
                ];
            }, $data['products'] ?? []);

            return [
                'results' => $results,
                'total'   => $data['total'] ?? 0,
                'page'    => $data['page'] ?? $page,
            ];
        }

        // ── Real-Time Product Search ─────────────────────────
        $service = $this->getProductSearchService();

        $data = $service->searchProducts($query, [
            'page'    => $page,
            'limit'   => $limit,
            'country' => $country,
            'language' => $language,
        ]);

        $results = array_map(function (array $item): array {
            return [
                'id'             => $item['product_id'] ?? '',
                'title'          => $item['title'] ?? '',
                'original_title' => $item['title'] ?? '',
                'year'           => '',
                'overview'       => mb_substr($item['description'] ?? '', 0, 200),
                'poster'         => $item['image_url'] ?? null,
                'rating'         => $item['rating'] ?? null,
                'vote_count'     => $item['reviews_count'] ?? null,
                'price'          => $item['price'] ?? null,
                'brand'          => $item['brand'] ?? null,
            ];
        }, $data['products'] ?? []);

        return [
            'results' => $results,
            'total'   => $data['total'] ?? 0,
            'page'    => $data['page'] ?? $page,
        ];
    }

    /**
     * Поиск футбольных матчей через FootballAPIService.
     *
     * Получает live/trending fixtures и фильтрует по поисковому запросу
     * (матч по названию матча, команде или лиге).
     *
     * @param string $query Запрос
     * @param int    $page  Номер страницы
     * @param int    $limit Лимит
     *
     * @return array{results: array, total: int, page: int}
     */
    private function searchFootball(string $query, int $page, int $limit): array
    {
        $service = $this->getFootballService();
        $allTopics = $service->fetchTrendingTopics(100);

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[ChunkGenerator:Search] searchFootball raw count: ' . count($allTopics)
        );

        // Filter by query
        $needle = mb_strtolower($query);
        $filtered = array_filter($allTopics, function (array $item) use ($needle): bool {
            return str_contains(mb_strtolower($item['title']     ?? ''), $needle)
                || str_contains(mb_strtolower($item['league']    ?? ''), $needle)
                || str_contains(mb_strtolower($item['home_team'] ?? ''), $needle)
                || str_contains(mb_strtolower($item['away_team'] ?? ''), $needle);
        });
        $filtered = array_values($filtered);

        $total   = count($filtered);
        $offset  = ($page - 1) * $limit;
        $paged   = array_slice($filtered, $offset, $limit);

        $results = array_map(function (array $item): array {
            return [
                'id'             => $item['id'] ?? 0,
                'title'          => $item['title'] ?? '',
                'original_title' => $item['title'] ?? '',
                'year'           => !empty($item['date'])
                    ? substr($item['date'], 0, 4) : '',
                'overview'       => implode(' | ', array_filter([
                    $item['league'] ?? '',
                    'Score: ' . ($item['score'] ?? '?:?'),
                    $item['status'] ?? '',
                    $item['venue'] ?? '',
                ])),
                'poster'         => null,
                'rating'         => null,
                'vote_count'     => null,
                'extra'          => [
                    'league'    => $item['league']    ?? '',
                    'home_team' => $item['home_team'] ?? '',
                    'away_team' => $item['away_team'] ?? '',
                    'score'     => $item['score']     ?? '',
                    'status'    => $item['status']    ?? '',
                    'date'      => $item['date']      ?? '',
                    'venue'     => $item['venue']     ?? null,
                ],
            ];
        }, $paged);

        return ['results' => $results, 'total' => $total, 'page' => $page];
    }

    /**
     * Поиск биатлонных событий через BiathlonIBUService.
     *
     * Получает события текущего сезона и фильтрует по запросу
     * (название события, место проведения, страна).
     *
     * @param string $query Запрос
     * @param int    $page  Номер страницы
     * @param int    $limit Лимит
     *
     * @return array{results: array, total: int, page: int}
     */
    private function searchBiathlon(string $query, int $page, int $limit): array
    {
        $service = $this->getBiathlonService();
        $allTopics = $service->fetchTrendingTopics(100);

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[ChunkGenerator:Search] searchBiathlon raw count: ' . count($allTopics)
        );

        $needle = mb_strtolower($query);
        $filtered = array_filter($allTopics, function (array $item) use ($needle): bool {
            return str_contains(mb_strtolower($item['title']      ?? ''), $needle)
                || str_contains(mb_strtolower($item['location']   ?? ''), $needle)
                || str_contains(mb_strtolower($item['country']    ?? ''), $needle)
                || str_contains(mb_strtolower($item['event_type'] ?? ''), $needle);
        });
        $filtered = array_values($filtered);

        $total  = count($filtered);
        $offset = ($page - 1) * $limit;
        $paged  = array_slice($filtered, $offset, $limit);

        $results = array_map(function (array $item): array {
            return [
                'id'             => $item['id'] ?? '',
                'title'          => $item['title'] ?? '',
                'original_title' => $item['title'] ?? '',
                'year'           => !empty($item['start_date'])
                    ? substr($item['start_date'], 0, 4) : '',
                'overview'       => implode(' | ', array_filter([
                    $item['event_type'] ?? '',
                    $item['location']   ?? '',
                    $item['country']    ?? '',
                    $item['status']     ?? '',
                ])),
                'poster'         => null,
                'rating'         => null,
                'vote_count'     => null,
                'extra'          => [
                    'event_type' => $item['event_type'] ?? '',
                    'location'   => $item['location']   ?? '',
                    'country'    => $item['country']    ?? '',
                    'start_date' => $item['start_date'] ?? '',
                    'end_date'   => $item['end_date']   ?? '',
                    'status'     => $item['status']     ?? '',
                    'season'     => $item['season']     ?? '',
                ],
            ];
        }, $paged);

        return ['results' => $results, 'total' => $total, 'page' => $page];
    }

    /**
     * Поиск матчей или турниров через FlashLive Sports API.
     *
     * Режимы (fs_mode):
     *   match     → /v1/events/list фильтр по командам/турниру
     *   standings → /v1/tournaments/list фильтр по названию
     *   schedule  → /v1/tournaments/list фильтр по названию
     *
     * @param string $query    Поисковый запрос
     * @param int    $page     Номер страницы
     * @param int    $limit    Лимит
     * @param string $language Язык (ru, en, de ...)
     *
     * @return array{results: array, total: int, page: int}
     */
    private function searchFlashSport(string $query, int $page, int $limit, string $language = 'ru'): array
    {
        $localeMap = [
            'ru' => 'ru_RU', 'en' => 'en_US', 'de' => 'de_DE',
            'fr' => 'fr_FR', 'es' => 'es_ES', 'it' => 'it_IT',
            'pt' => 'pt_PT', 'nl' => 'nl_NL', 'tr' => 'tr_TR', 'pl' => 'pl_PL',
        ];
        $locale  = $localeMap[$language] ?? 'ru_RU';
        $service = $this->getSportsService();

        $sportId = max(1, (int)$this->getProperty('fs_sport', 1));
        $mode    = trim((string)$this->getProperty('fs_mode', 'match')) ?: 'match';
        $day     = (int)$this->getProperty('fs_day', 0);

        if ($mode === 'match') {
            $items = $service->searchEventsByQuery($sportId, $query, $locale, $day);
        } else {
            // standings or schedule — search tournaments list
            $items = $service->searchTournamentsByQuery($sportId, $query, $locale);
        }

        $total  = count($items);
        $offset = ($page - 1) * $limit;
        $paged  = array_slice($items, $offset, $limit);

        $results = array_map(function (array $item): array {
            return [
                'id'             => (string)($item['id']      ?? ''),
                'title'          => (string)($item['title']   ?? ''),
                'original_title' => (string)($item['title']   ?? ''),
                'year'           => (string)($item['year']    ?? ''),
                'overview'       => (string)($item['overview'] ?? ''),
                'poster'         => $item['poster'] ?? null,
                'rating'         => null,
                'vote_count'     => null,
            ];
        }, $paged);

        return ['results' => $results, 'total' => $total, 'page' => $page];
    }

    /**
     * Поиск матчей через SportAPI7 (по дате и виду спорта).
     *
     * Читает параметры:
     *   sa_sport (string) — вид спорта (football, ice-hockey, volleyball, esports, olympics)
     *   sa_date  (string) — дата YYYY-MM-DD (default: сегодня)
     *
     * @param string $query  Фильтр по командам/турниру (пустой = все события дня)
     * @param int    $page   Страница
     * @param int    $limit  Лимит
     *
     * @return array{results: array, total: int, page: int}
     */
    private function searchSportApi(string $query, int $page, int $limit): array
    {
        $service = $this->getSportApi7Service();

        $sport = trim((string)$this->getProperty('sa_sport', 'football')) ?: 'football';
        $date  = trim((string)$this->getProperty('sa_date',  date('Y-m-d')));

        // Fallback to today if date is missing/invalid
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $items  = $service->searchEventsByDate($sport, $date, $query);
        $total  = count($items);
        $offset = ($page - 1) * $limit;
        $paged  = array_slice($items, $offset, $limit);

        $results = array_map(function (array $item): array {
            return [
                'id'             => (string)($item['id']       ?? ''),
                'title'          => (string)($item['title']    ?? ''),
                'original_title' => (string)($item['title']    ?? ''),
                'year'           => (string)($item['year']     ?? ''),
                'overview'       => (string)($item['overview'] ?? ''),
                'poster'         => $item['poster'] ?? null,
                'rating'         => null,
                'vote_count'     => null,
            ];
        }, $paged);

        return ['results' => $results, 'total' => $total, 'page' => $page];
    }

    /**
     * Поиск GitHub репозиториев через GitHubService.
     *
     * Использует getTrendingByTopic($query) для поиска трендовых
     * репозиториев по теме/ключевому слову.
     *
     * @param string $query Запрос / topic (machine-learning, web-dev и т.д.)
     * @param int    $page  Номер страницы
     * @param int    $limit Лимит
     *
     * @return array{results: array, total: int, page: int}
     */
    private function searchGitHub(string $query, int $page, int $limit): array
    {
        $service = $this->getGitHubService();
        $data    = $service->searchRepositories($query, max($limit, 20));

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[ChunkGenerator:Search] searchGitHub repos count: '
            . count($data['repos'] ?? [])
            . ' success=' . ($data['success'] ? 'true' : 'false')
        );

        if (!($data['success'] ?? false)) {
            return ['results' => [], 'total' => 0, 'page' => $page];
        }

        $allRepos = $data['repos'] ?? [];
        $total    = count($allRepos);
        $offset   = ($page - 1) * $limit;
        $paged    = array_slice($allRepos, $offset, $limit);

        $results = array_map(function (array $repo): array {
            return [
                'id'             => $repo['id'] ?? 0,
                'title'          => $repo['full_name'] ?? $repo['name'] ?? '',
                'original_title' => $repo['name'] ?? '',
                'year'           => !empty($repo['created_at'])
                    ? substr($repo['created_at'], 0, 4) : '',
                'overview'       => mb_substr($repo['description'] ?? '', 0, 200),
                'poster'         => $repo['owner']['avatar_url'] ?? null,
                'rating'         => $repo['stars'] ?? 0,
                'vote_count'     => $repo['forks'] ?? 0,
                'extra'          => [
                    'language'   => $repo['language']  ?? '',
                    'stars'      => $repo['stars']      ?? 0,
                    'forks'      => $repo['forks']      ?? 0,
                    'html_url'   => $repo['html_url']   ?? '',
                    'topics'     => $repo['topics']     ?? [],
                    'license'    => $repo['license']    ?? '',
                    'updated_at' => $repo['updated_at'] ?? '',
                ],
            ];
        }, $paged);

        return ['results' => $results, 'total' => $total, 'page' => $page];
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Service Factories                             ║
    // ╚═════════════════════════════════════════════════════════╝

    private function getCache(): \SpookyApp\Services\Cache\CacheService
    {
        return new \SpookyApp\Services\Cache\CacheService($this->modx);
    }

    /**
     * Получить экземпляр TMDBService.
     *
     * @return \SpookyApp\Services\API\TMDBService
     */
    private function getTMDBService(): \SpookyApp\Services\API\TMDBService
    {
        return new \SpookyApp\Services\API\TMDBService($this->modx, $this->getCache());
    }

    /**
     * Получить экземпляр GamesAPIService.
     *
     * @return \SpookyApp\Services\API\GamesAPIService
     */
    private function getGamesService(): \SpookyApp\Services\API\GamesAPIService
    {
        return new \SpookyApp\Services\API\GamesAPIService($this->modx, $this->getCache());
    }

    /**
     * Получить экземпляр MobileDevicesAPIService.
     *
     * @return \SpookyApp\Services\API\MobileDevicesAPIService
     */
    private function getDevicesService(): \SpookyApp\Services\API\MobileDevicesAPIService
    {
        return new \SpookyApp\Services\API\MobileDevicesAPIService($this->modx, $this->getCache());
    }

    /**
     * Получить экземпляр ProductSearchService.
     *
     * @return \SpookyApp\Services\API\ProductSearchService
     */
    private function getProductSearchService(): \SpookyApp\Services\API\ProductSearchService
    {
        return new \SpookyApp\Services\API\ProductSearchService($this->modx, $this->getCache());
    }

    /**
     * Получить экземпляр AmazonProductService.
     *
     * @return \SpookyApp\Services\API\AmazonProductService
     */
    private function getAmazonService(): \SpookyApp\Services\API\AmazonProductService
    {
        return new \SpookyApp\Services\API\AmazonProductService($this->modx, $this->getCache());
    }

    /**
     * Получить экземпляр SportsAPIService (FlashLive).
     *
     * @return \SpookyApp\Services\API\SportsAPIService
     */
    private function getSportsService(): \SpookyApp\Services\API\SportsAPIService
    {
        return new \SpookyApp\Services\API\SportsAPIService($this->modx, $this->getCache());
    }

    private function getSportApi7Service(): \SpookyApp\Services\API\SportAPI7Service
    {
        return new \SpookyApp\Services\API\SportAPI7Service($this->modx, $this->getCache());
    }

    private function getFootballService(): \SpookyApp\Services\API\FootballAPIService
    {
        return new \SpookyApp\Services\API\FootballAPIService($this->modx, $this->getCache());
    }

    /**
     * Получить экземпляр BiathlonIBUService.
     *
     * @return \SpookyApp\Services\API\BiathlonIBUService
     */
    private function getBiathlonService(): \SpookyApp\Services\API\BiathlonIBUService
    {
        return new \SpookyApp\Services\API\BiathlonIBUService($this->modx, $this->getCache());
    }

    /**
     * Получить экземпляр GitHubService.
     *
     * @return \SpookyApp\Services\API\GitHubService
     */
    private function getGitHubService(): \SpookyApp\Services\API\GitHubService
    {
        return new \SpookyApp\Services\API\GitHubService($this->modx, $this->getCache());
    }
}

return 'SpookyAppChunkGeneratorSearchProcessor';