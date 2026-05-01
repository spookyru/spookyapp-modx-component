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
 * ProductSearchService — клиент для Real-Time Product Search API (RapidAPI).
 *
 * ═══════════════════════════════════════════════════════════════
 * Универсальный поиск товаров, не попадающих в категории
 * специализированных API (мобильные устройства, игры и т.д.).
 *
 * Функциональность:
 *
 * A) Поиск товаров:
 *    - searchProducts()   — поиск по запросу
 *    - searchDeals()      — поиск скидок/акций
 *
 * B) Детали товара:
 *    - getProductDetails() — полная информация
 *    - getProductOffers()  — предложения от продавцов
 *
 * C) Нормализация:
 *    - normalizeProduct()  — единый формат для Chunk Generator
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
class ProductSearchService extends APIService
{
    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constants                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var string Базовый URL Real-Time Product Search API */
    private const BASE_URL = 'https://real-time-product-search.p.rapidapi.com';

    /** @var string RapidAPI host header */
    private const RAPIDAPI_HOST = 'real-time-product-search.p.rapidapi.com';

    /** @var string Префикс ключей кеша */
    private const CACHE_PREFIX = 'spookyapp/products/';

    /** @var string Системная настройка MODX: RapidAPI key */
    private const SETTING_RAPIDAPI_KEY = 'spookyapp.rapidapi_key';

    // ── Cache TTL (секунды) ──────────────────────────────────

    /** @var int Кеш для поиска товаров (6 часов) */
    private const TTL_SEARCH = 21600;

    /** @var int Кеш для деталей товара (12 часов) */
    private const TTL_DETAILS = 43200;

    /** @var int Кеш для предложений/офферов (6 часов — цены меняются) */
    private const TTL_OFFERS = 21600;

    /** @var int Кеш для скидок/акций (3 часа — акции меняются часто) */
    private const TTL_DEALS = 10800;

    // ── Допустимые значения параметров ───────────────────────

    /** @var array<string> Допустимые значения sort_by */
    private const VALID_SORT_BY = [
        'BEST_MATCH',
        'TOP_RATED',
        'LOWEST_PRICE',
        'HIGHEST_PRICE',
        'MOST_REVIEWED',
    ];

    /** @var array<string> Допустимые значения product_condition */
    private const VALID_CONDITIONS = [
        'ANY',
        'NEW',
        'USED',
        'REFURBISHED',
    ];

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
     * Конструктор ProductSearchService.
     *
     * Инициализирует Guzzle Client с RapidAPI авторизацией.
     * API-ключ берётся из системной настройки MODX
     * или передаётся напрямую.
     *
     * @param modX        $modx    MODX instance
     * @param string|null $apiKey  RapidAPI key (если null — берётся из настроек)
     * @param array       $options Дополнительные опции:
     *   - cache_enabled (bool): включить кеш, default true
     *   - timeout (int): таймаут запроса в секундах, default 15
     */
    public function __construct(modX $modx, CacheService $cache)
    {
        parent::__construct($modx, $cache);

        $this->apiKey       = (string)$this->modx->getOption(self::SETTING_RAPIDAPI_KEY, null, '');
        $this->cacheEnabled = true;

        if (empty($this->apiKey)) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[ProductSearchService] RapidAPI key not configured. '
                . 'Set system setting "' . self::SETTING_RAPIDAPI_KEY . '"'
            );
        }

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[ProductSearchService] Initialized. Key: ' . (empty($this->apiKey) ? 'MISSING' : 'OK')
        );
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A) Search Methods                                      ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Поиск товаров по запросу.
     *
     * Endpoint: GET /search-v2
     *
     * @param string $query   Поисковый запрос (название товара)
     * @param array  $options Опции поиска:
     *   - country (string): код страны ISO 3166-1 alpha-2, default 'us'
     *   - language (string): код языка, default 'en'
     *   - page (int): номер страницы, default 1
     *   - limit (int): количество результатов (1-100), default 10
     *   - sort_by (string): сортировка, default 'BEST_MATCH'
     *     Допустимые: BEST_MATCH, TOP_RATED, LOWEST_PRICE, HIGHEST_PRICE, MOST_REVIEWED
     *   - product_condition (string): состояние, default 'ANY'
     *     Допустимые: ANY, NEW, USED, REFURBISHED
     *   - return_filters (bool): возвращать фильтры, default false
     *
     * @return array{
     *   status: string,
     *   request_id: string|null,
     *   total: int,
     *   page: int,
     *   products: array<int, array{
     *     product_id: string,
     *     title: string,
     *     description: string|null,
     *     price: string|null,
     *     currency: string|null,
     *     image_url: string|null,
     *     product_url: string|null,
     *     brand: string|null,
     *     rating: float|null,
     *     reviews_count: int|null,
     *     store: string|null,
     *     condition: string|null
     *   }>,
     *   filters: array|null
     * }
     */
    public function searchProducts(string $query, array $options = []): array
    {
        $empty = [
            'status'     => 'error',
            'request_id' => null,
            'total'      => 0,
            'page'       => 1,
            'products'   => [],
            'filters'    => null,
        ];

        if (empty(trim($query))) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[ProductSearchService] searchProducts: empty query'
            );
            return $empty;
        }

        // ── Параметры запроса ────────────────────────────────
        $country   = (string)($options['country'] ?? 'us');
        $language  = (string)($options['language'] ?? 'en');
        $page      = max(1, (int)($options['page'] ?? 1));
        $limit     = max(1, min(100, (int)($options['limit'] ?? 10)));
        $sortBy    = $this->validateEnum(
            (string)($options['sort_by'] ?? 'BEST_MATCH'),
            self::VALID_SORT_BY,
            'BEST_MATCH'
        );
        $condition = $this->validateEnum(
            (string)($options['product_condition'] ?? 'ANY'),
            self::VALID_CONDITIONS,
            'ANY'
        );
        $returnFilters = (bool)($options['return_filters'] ?? false);

        // ── Кеш ──────────────────────────────────────────────
        $cacheKey = self::CACHE_PREFIX . 'search/'
            . md5(implode('|', [
                $query, $country, $language, $page,
                $limit, $sortBy, $condition,
            ]));

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[ProductSearchService] searchProducts: cache hit for "' . $query . '"'
            );
            return $cached;
        }

        // ── API запрос ───────────────────────────────────────
        $params = [
            'q'                 => $query,
            'country'           => $country,
            'language'          => $language,
            'page'              => $page,
            'limit'             => $limit,
            'sort_by'           => $sortBy,
            'product_condition' => $condition,
        ];

        if ($returnFilters) {
            $params['return_filters'] = 'true';
        }

        $data = $this->request('GET', '/search-v2', $params);
        if ($data === null) {
            return $empty;
        }

        // ── Нормализуем ответ ────────────────────────────────
        // API возвращает {"status":"OK","data":{"products":[...]}}
        // поэтому продукты лежат в $data['data']['products']
        $rawProducts = $data['data']['products']
            ?? $data['products']
            ?? $data['results']
            ?? [];

        if (!is_array($rawProducts) || empty($rawProducts)) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[ProductSearchService] searchProducts: no products array in response. Keys: '
                . implode(', ', array_keys($data))
                . (isset($data['data']) ? ' | data keys: ' . implode(', ', array_keys((array)$data['data'])) : '')
            );
        }

        $products = array_map(
            [$this, 'normalizeProduct'],
            is_array($rawProducts) ? array_values($rawProducts) : []
        );

        $result = [
            'status'     => $data['status'] ?? 'OK',
            'request_id' => $data['request_id'] ?? null,
            'total'      => (int)(
                $data['data']['total_results']
                ?? $data['total']
                ?? $data['total_results']
                ?? count($products)
            ),
            'page'       => $page,
            'products'   => $products,
            'filters'    => $returnFilters ? ($data['filters'] ?? null) : null,
        ];

        // Search results are intentionally NOT cached — product listings
        // change frequently and the same query may return different items.

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[ProductSearchService] searchProducts: "' . $query . '" '
            . $country . '/' . $language . ' page=' . $page
            . ' → ' . count($products) . ' products'
            . ' (total: ' . $result['total'] . ')'
        );

        return $result;
    }

    /**
     * Поиск товаров со скидками/акциями.
     *
     * Endpoint: GET /deals-v2
     *
     * @param string $query   Поисковый запрос
     * @param array  $options Опции поиска:
     *   - country (string): код страны, default 'ru'
     *   - language (string): код языка, default 'ru'
     *   - page (int): номер страницы, default 1
     *   - limit (int): количество (1-100), default 10
     *   - sort_by (string): сортировка, default 'BEST_MATCH'
     *   - product_condition (string): состояние, default 'ANY'
     *
     * @return array{
     *   status: string,
     *   request_id: string|null,
     *   total: int,
     *   page: int,
     *   deals: array<int, array{
     *     product_id: string,
     *     title: string,
     *     description: string|null,
     *     price: string|null,
     *     original_price: string|null,
     *     discount_percent: int|null,
     *     currency: string|null,
     *     image_url: string|null,
     *     product_url: string|null,
     *     brand: string|null,
     *     rating: float|null,
     *     reviews_count: int|null,
     *     store: string|null
     *   }>
     * }
     */
    public function searchDeals(string $query, array $options = []): array
    {
        $empty = [
            'status'     => 'error',
            'request_id' => null,
            'total'      => 0,
            'page'       => 1,
            'deals'      => [],
        ];

        if (empty(trim($query))) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[ProductSearchService] searchDeals: empty query'
            );
            return $empty;
        }

        // ── Параметры ────────────────────────────────────────
        $country   = (string)($options['country'] ?? 'ru');
        $language  = (string)($options['language'] ?? 'ru');
        $page      = max(1, (int)($options['page'] ?? 1));
        $limit     = max(1, min(100, (int)($options['limit'] ?? 10)));
        $sortBy    = $this->validateEnum(
            (string)($options['sort_by'] ?? 'BEST_MATCH'),
            self::VALID_SORT_BY,
            'BEST_MATCH'
        );
        $condition = $this->validateEnum(
            (string)($options['product_condition'] ?? 'ANY'),
            self::VALID_CONDITIONS,
            'ANY'
        );

        // ── Кеш ──────────────────────────────────────────────
        $cacheKey = self::CACHE_PREFIX . 'deals/'
            . md5(implode('|', [
                $query, $country, $language, $page,
                $limit, $sortBy, $condition,
            ]));

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[ProductSearchService] searchDeals: cache hit for "' . $query . '"'
            );
            return $cached;
        }

        // ── API запрос ───────────────────────────────────────
        $data = $this->request('GET', '/deals-v2', [
            'q'                 => $query,
            'country'           => $country,
            'language'          => $language,
            'page'              => $page,
            'limit'             => $limit,
            'sort_by'           => $sortBy,
            'product_condition' => $condition,
        ]);

        if ($data === null) {
            return $empty;
        }

        // ── Нормализуем ответ ────────────────────────────────
        // API: {"status":"OK","data":{"deals":[...]} или "products":[...]}}
        $rawDeals = $data['data']['deals']
            ?? $data['data']['products']
            ?? $data['deals']
            ?? $data['products']
            ?? $data['results']
            ?? [];

        $deals = array_map(function (array $item): array {
            $base = $this->normalizeProduct($item);

            // Дополнительные поля для deals
            $base['original_price']   = $this->extractPrice($item, 'typical_price_range')
                ?? $this->extractPrice($item, 'original_price')
                ?? $item['old_price'] ?? null;
            $base['discount_percent'] = $this->extractDiscount($item);

            return $base;
        }, $rawDeals);

        $result = [
            'status'     => $data['status'] ?? 'OK',
            'request_id' => $data['request_id'] ?? null,
            'total'      => (int)($data['total'] ?? $data['total_results'] ?? count($deals)),
            'page'       => $page,
            'deals'      => $deals,
        ];

        $this->setCache($cacheKey, $result, self::TTL_DEALS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[ProductSearchService] searchDeals: "' . $query . '" '
            . $country . '/' . $language
            . ' → ' . count($deals) . ' deals'
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Detail Methods                                      ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить полную информацию о товаре.
     *
     * Endpoint: GET /product-details-v2
     *
     * @param string $productId Product ID (формат TMDB-подобный с catalogid, gpcid и т.д.)
     * @param string $country   Код страны, default 'ru'
     * @param string $language  Код языка, default 'ru'
     *
     * @return array{
     *   status: string,
     *   product_id: string,
     *   title: string,
     *   description: string,
     *   images: array<string>,
     *   price: string|null,
     *   currency: string|null,
     *   availability: string|null,
     *   brand: string|null,
     *   rating: float|null,
     *   reviews_count: int|null,
     *   product_url: string|null,
     *   store: string|null,
     *   specifications: array<string, string>,
     *   product_attributes: array<string, string>,
     *   typical_price_range: array{low: string|null, high: string|null}|null,
     *   offers_count: int
     * }|array{}
     */
    public function getProductDetails(
        string $productId,
        string $country = 'ru',
        string $language = 'ru'
    ): array {
        if (empty(trim($productId))) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[ProductSearchService] getProductDetails: empty productId'
            );
            return [];
        }

        // ── Кеш ──────────────────────────────────────────────
        $cacheKey = self::CACHE_PREFIX . 'details/'
            . md5($productId . '|' . $country . '|' . $language);

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[ProductSearchService] getProductDetails: cache hit'
            );
            return $cached;
        }

        // ── API запрос ───────────────────────────────────────
        $data = $this->request('GET', '/product-details-v2', [
            'product_id' => $productId,
            'country'    => $country,
            'language'   => $language,
        ]);

        if ($data === null) {
            return [];
        }

        // API может вернуть данные в data или напрямую
        $product = $data['data'] ?? $data['product'] ?? $data;

        // ── Извлекаем изображения ────────────────────────────
        $images = $this->extractImages($product);

        // ── Извлекаем спецификации ───────────────────────────
        $specifications = $this->extractSpecifications($product);

        // ── Типичный ценовой диапазон ────────────────────────
        $priceRange = null;
        if (isset($product['typical_price_range'])) {
            $range = $product['typical_price_range'];
            if (is_array($range)) {
                $priceRange = [
                    'low'  => $range['low'] ?? $range[0] ?? null,
                    'high' => $range['high'] ?? $range[1] ?? null,
                ];
            } elseif (is_string($range)) {
                // Формат "$100 – $200"
                $parts = preg_split('/\s*[–—-]\s*/', $range, 2);
                $priceRange = [
                    'low'  => trim($parts[0] ?? ''),
                    'high' => trim($parts[1] ?? ''),
                ];
            }
        }

        $result = [
            'status'              => $data['status'] ?? 'OK',
            'product_id'          => $product['product_id']
                ?? $product['id']
                ?? $productId,
            'title'               => $product['product_title']
                ?? $product['title']
                ?? $product['name']
                ?? '',
            'description'         => $product['product_description']
                ?? $product['description']
                ?? '',
            'images'              => $images,
            'price'               => $this->extractPrice($product, 'offer')
                ?? $this->extractPrice($product, 'price')
                ?? $product['price'] ?? null,
            'currency'            => $product['currency']
                ?? $product['offer']['currency'] ?? null,
            'availability'        => $product['availability']
                ?? $product['offer']['availability'] ?? null,
            'brand'               => $product['brand']
                ?? $product['product_brand'] ?? null,
            'rating'              => isset($product['product_rating'])
                ? (float)$product['product_rating']
                : (isset($product['rating']) ? (float)$product['rating'] : null),
            'reviews_count'       => isset($product['product_num_reviews'])
                ? (int)$product['product_num_reviews']
                : (isset($product['reviews_count']) ? (int)$product['reviews_count'] : null),
            'product_url'         => $product['product_page_url']
                ?? $product['product_url']
                ?? $product['url'] ?? null,
            'store'               => $product['offer']['store_name']
                ?? $product['store']
                ?? $product['source'] ?? null,
            'specifications'      => $specifications,
            'product_attributes'  => $product['product_attributes'] ?? [],
            'typical_price_range' => $priceRange,
            'offers_count'        => (int)($product['product_num_offers']
                ?? $product['offers_count'] ?? 0),
        ];

        $this->setCache($cacheKey, $result, self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[ProductSearchService] getProductDetails: "'
            . mb_substr($result['title'], 0, 60) . '..."'
            . ' (' . $country . '/' . $language . ')'
        );

        return $result;
    }

    /**
     * Получить предложения (офферы) от разных продавцов для товара.
     *
     * Endpoint: GET /product-offers-v2
     *
     * @param string $productId Product ID
     * @param array  $options   Опции:
     *   - page (int): номер страницы, default 1
     *   - country (string): default 'ru'
     *   - language (string): default 'ru'
     *
     * @return array{
     *   status: string,
     *   product_id: string,
     *   total: int,
     *   page: int,
     *   offers: array<int, array{
     *     store_name: string,
     *     store_url: string|null,
     *     store_favicon: string|null,
     *     offer_url: string|null,
     *     price: string|null,
     *     currency: string|null,
     *     original_price: string|null,
     *     shipping: string|null,
     *     availability: string|null,
     *     condition: string|null,
     *     tax: string|null
     *   }>
     * }
     */
    public function getProductOffers(string $productId, array $options = []): array
    {
        $empty = [
            'status'     => 'error',
            'product_id' => $productId,
            'total'      => 0,
            'page'       => 1,
            'offers'     => [],
        ];

        if (empty(trim($productId))) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[ProductSearchService] getProductOffers: empty productId'
            );
            return $empty;
        }

        $page     = max(1, (int)($options['page'] ?? 1));
        $country  = (string)($options['country'] ?? 'ru');
        $language = (string)($options['language'] ?? 'ru');

        // ── Кеш ──────────────────────────────────────────────
        $cacheKey = self::CACHE_PREFIX . 'offers/'
            . md5($productId . '|' . $page . '|' . $country . '|' . $language);

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[ProductSearchService] getProductOffers: cache hit'
            );
            return $cached;
        }

        // ── API запрос ───────────────────────────────────────
        $data = $this->request('GET', '/product-offers-v2', [
            'product_id' => $productId,
            'page'       => $page,
            'country'    => $country,
            'language'   => $language,
        ]);

        if ($data === null) {
            return $empty;
        }

        // API: {"status":"OK","data":{"offers":[...]}}
        $rawOffers = $data['data']['offers']
            ?? (is_array($data['data'] ?? null) && isset($data['data'][0]) ? $data['data'] : null)
            ?? $data['offers']
            ?? $data['results']
            ?? [];

        $offers = array_map(function (array $item): array {
            return [
                'store_name'     => $item['store_name']
                    ?? $item['merchant'] ?? $item['seller'] ?? '',
                'store_url'      => $item['store_url']
                    ?? $item['merchant_url'] ?? null,
                'store_favicon'  => $item['store_favicon']
                    ?? $item['store_icon'] ?? null,
                'offer_url'      => $item['offer_page_url']
                    ?? $item['offer_url'] ?? $item['url'] ?? null,
                'price'          => $item['price'] ?? $item['offer_price'] ?? null,
                'currency'       => $item['currency'] ?? null,
                'original_price' => $item['original_price']
                    ?? $item['base_price'] ?? null,
                'shipping'       => $item['shipping']
                    ?? $item['delivery'] ?? null,
                'availability'   => $item['availability']
                    ?? $item['in_stock'] ?? null,
                'condition'      => $item['product_condition']
                    ?? $item['condition'] ?? null,
                'tax'            => $item['tax'] ?? null,
            ];
        }, $rawOffers);

        $result = [
            'status'     => $data['status'] ?? 'OK',
            'product_id' => $productId,
            'total'      => (int)($data['total'] ?? $data['total_results'] ?? count($offers)),
            'page'       => $page,
            'offers'     => $offers,
        ];

        $this->setCache($cacheKey, $result, self::TTL_OFFERS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[ProductSearchService] getProductOffers: '
            . count($offers) . ' offers on page ' . $page
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  C) Normalization                                       ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Нормализовать данные товара из ответа API в единый формат.
     *
     * Real-Time Product Search API может возвращать данные
     * в разных форматах в зависимости от endpoint'а.
     * Этот метод приводит всё к единой структуре
     * для использования в Chunk Generator.
     *
     * @param array $raw Сырые данные товара из API
     *
     * @return array{
     *   product_id: string,
     *   title: string,
     *   description: string|null,
     *   price: string|null,
     *   currency: string|null,
     *   image_url: string|null,
     *   product_url: string|null,
     *   brand: string|null,
     *   rating: float|null,
     *   reviews_count: int|null,
     *   store: string|null,
     *   condition: string|null
     * }
     */
    public function normalizeProduct(array $raw): array
    {
        // ── product_id ───────────────────────────────────────
        $productId = (string)(
            $raw['product_id']
            ?? $raw['id']
            ?? $raw['asin']
            ?? ''
        );

        // ── title ────────────────────────────────────────────
        $title = $raw['product_title']
            ?? $raw['title']
            ?? $raw['name']
            ?? '';

        // ── description ──────────────────────────────────────
        $description = $raw['product_description']
            ?? $raw['description']
            ?? $raw['snippet']
            ?? null;

        // ── price (строка, включая валюту: "$99.99") ─────────
        $price = $this->extractPrice($raw, 'offer')
            ?? $raw['price'] ?? $raw['offer_price'] ?? null;

        // ── currency ─────────────────────────────────────────
        $currency = $raw['currency']
            ?? $raw['offer']['currency']
            ?? null;

        // ── image_url ────────────────────────────────────────
        $imageUrl = $raw['product_photos'][0]
            ?? $raw['product_photo']
            ?? $raw['thumbnail']
            ?? $raw['image']
            ?? $raw['image_url']
            ?? null;

        // ── product_url ──────────────────────────────────────
        $productUrl = $raw['product_page_url']
            ?? $raw['product_url']
            ?? $raw['url']
            ?? $raw['link']
            ?? null;

        // ── brand ────────────────────────────────────────────
        $brand = $raw['brand']
            ?? $raw['product_brand']
            ?? null;

        // ── rating ───────────────────────────────────────────
        $rating = null;
        if (isset($raw['product_rating'])) {
            $rating = (float)$raw['product_rating'];
        } elseif (isset($raw['rating'])) {
            $rating = (float)$raw['rating'];
        }

        // ── reviews_count ────────────────────────────────────
        $reviewsCount = null;
        if (isset($raw['product_num_reviews'])) {
            $reviewsCount = (int)$raw['product_num_reviews'];
        } elseif (isset($raw['reviews_count'])) {
            $reviewsCount = (int)$raw['reviews_count'];
        } elseif (isset($raw['num_reviews'])) {
            $reviewsCount = (int)$raw['num_reviews'];
        }

        // ── store ────────────────────────────────────────────
        $store = $raw['offer']['store_name']
            ?? $raw['store_name']
            ?? $raw['store']
            ?? $raw['source']
            ?? null;

        // ── condition ────────────────────────────────────────
        $condition = $raw['product_condition']
            ?? $raw['condition']
            ?? null;

        return [
            'product_id'    => $productId,
            'title'         => $title,
            'description'   => $description,
            'price'         => $price,
            'currency'      => $currency,
            'image_url'     => $imageUrl,
            'product_url'   => $productUrl,
            'brand'         => $brand,
            'rating'        => $rating,
            'reviews_count' => $reviewsCount,
            'store'         => $store,
            'condition'     => $condition,
        ];
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: HTTP Request                                  ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Выполнить HTTP-запрос к Real-Time Product Search API.
     *
     * Единая точка для всех запросов — обеспечивает:
     * - Единообразную обработку ошибок
     * - Проверку API-ключа
     * - Логирование
     * - JSON-парсинг
     *
     * @param string $method HTTP-метод (GET)
     * @param string $uri    Относительный URI (e.g. '/search-v2')
     * @param array  $params Query-параметры
     *
     * @return array|null Декодированный JSON или null при ошибке
     */
    private function request(string $method, string $uri, array $params = []): ?array
    {
        if (empty($this->apiKey)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[ProductSearchService] Cannot make request: RapidAPI key is empty');
            return null;
        }
        $result = $this->httpGet(
            $this->buildUrl(self::BASE_URL . $uri, $params),
            ['X-RapidAPI-Key: ' . $this->apiKey, 'X-RapidAPI-Host: ' . self::RAPIDAPI_HOST, 'Accept: application/json'],
            30
        );
        if (!$result['success']) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[ProductSearchService] HTTP error for ' . $uri . ': ' . ($result['error'] ?? ''));
            return null;
        }
        return is_array($result['data']) ? $result['data'] : null;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Data Extraction Helpers                       ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Извлечь цену из вложенной структуры.
     *
     * API может возвращать цену в разных форматах:
     * - Строка: "$99.99"
     * - В offer: {offer: {price: "$99.99"}}
     * - В price: {price: {value: 99.99, currency: "USD"}}
     *
     * @param array  $data Данные товара
     * @param string $key  Ключ для поиска (e.g. 'offer', 'price')
     *
     * @return string|null Цена или null
     */
    private function extractPrice(array $data, string $key): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }

        $value = $data[$key];

        // Строка
        if (is_string($value)) {
            return $value;
        }

        // Число
        if (is_numeric($value)) {
            return (string)$value;
        }

        // Объект с вложенным price
        if (is_array($value)) {
            return $value['price']
                ?? $value['value']
                ?? $value['amount']
                ?? $value['formatted']
                ?? null;
        }

        return null;
    }

    /**
     * Извлечь процент скидки из данных товара.
     *
     * @param array $data Данные товара
     *
     * @return int|null Процент скидки или null
     */
    private function extractDiscount(array $data): ?int
    {
        // Прямое поле
        if (isset($data['discount_percentage'])) {
            return (int)$data['discount_percentage'];
        }
        if (isset($data['discount_percent'])) {
            return (int)$data['discount_percent'];
        }
        if (isset($data['discount'])) {
            $d = $data['discount'];
            if (is_numeric($d)) {
                return (int)$d;
            }
            // Формат "-20%"
            if (is_string($d) && preg_match('/(\d+)\s*%/', $d, $m)) {
                return (int)$m[1];
            }
        }

        return null;
    }

    /**
     * Извлечь изображения из данных товара.
     *
     * @param array $data Данные товара
     *
     * @return array<string>
     */
    private function extractImages(array $data): array
    {
        // Массив фотографий
        if (!empty($data['product_photos']) && is_array($data['product_photos'])) {
            return array_filter($data['product_photos'], 'is_string');
        }

        // Одна фотография + массив
        $images = [];

        if (!empty($data['product_photo']) && is_string($data['product_photo'])) {
            $images[] = $data['product_photo'];
        }
        if (!empty($data['thumbnail']) && is_string($data['thumbnail'])) {
            $images[] = $data['thumbnail'];
        }
        if (!empty($data['image']) && is_string($data['image'])) {
            $images[] = $data['image'];
        }

        // images массив из объектов
        if (!empty($data['images']) && is_array($data['images'])) {
            foreach ($data['images'] as $img) {
                if (is_string($img)) {
                    $images[] = $img;
                } elseif (is_array($img)) {
                    $url = $img['url'] ?? $img['src'] ?? $img['image'] ?? null;
                    if ($url !== null) {
                        $images[] = $url;
                    }
                }
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * Извлечь спецификации из данных товара.
     *
     * @param array $data Данные товара
     *
     * @return array<string, string>
     */
    private function extractSpecifications(array $data): array
    {
        // Прямой формат specifications
        if (!empty($data['product_specifications']) && is_array($data['product_specifications'])) {
            $specs = [];
            foreach ($data['product_specifications'] as $spec) {
                if (is_array($spec) && isset($spec['name'], $spec['value'])) {
                    $specs[(string)$spec['name']] = (string)$spec['value'];
                } elseif (is_array($spec) && isset($spec['key'], $spec['value'])) {
                    $specs[(string)$spec['key']] = (string)$spec['value'];
                }
            }
            return $specs;
        }

        // Формат ключ-значение
        if (!empty($data['specifications']) && is_array($data['specifications'])) {
            $specs = $data['specifications'];
            // Проверяем что это ассоциативный массив
            if (array_keys($specs) !== range(0, count($specs) - 1)) {
                return array_map('strval', $specs);
            }
            // Иначе массив объектов
            $result = [];
            foreach ($specs as $spec) {
                if (is_array($spec) && isset($spec['name'], $spec['value'])) {
                    $result[(string)$spec['name']] = (string)$spec['value'];
                }
            }
            return $result;
        }

        return [];
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Validation Helpers                            ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Валидация значения enum-параметра.
     *
     * @param string        $value   Проверяемое значение
     * @param array<string> $allowed Допустимые значения
     * @param string        $default Значение по умолчанию
     *
     * @return string Валидное значение
     */
    private function validateEnum(string $value, array $allowed, string $default): string
    {
        $upper = strtoupper(trim($value));

        if (in_array($upper, $allowed, true)) {
            return $upper;
        }

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[ProductSearchService] Invalid enum value "' . $value
            . '", using default "' . $default . '"'
        );

        return $default;
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