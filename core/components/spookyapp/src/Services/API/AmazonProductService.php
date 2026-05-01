<?php

declare(strict_types=1);

namespace SpookyApp\Services\API;

use MODX\Revolution\modX;
use SpookyApp\Services\Cache\CacheService;

/**
 * AmazonProductService — клиент для Real-Time Amazon Data API (RapidAPI).
 *
 * ═══════════════════════════════════════════════════════════════
 * Поиск товаров Amazon и получение детальной информации.
 *
 * Endpoint: real-time-amazon-data.p.rapidapi.com
 *
 * A) Поиск: searchProducts()
 * B) Детали: getProductDetails()
 *
 * Возвращаемый формат совместим с ProductSearchService
 * для использования в Chunk Generator.
 * ═══════════════════════════════════════════════════════════════
 *
 * @package SpookyApp
 * @subpackage Services\API
 */
class AmazonProductService extends APIService
{
    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constants                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    private const BASE_URL     = 'https://real-time-amazon-data.p.rapidapi.com';
    private const RAPIDAPI_HOST = 'real-time-amazon-data.p.rapidapi.com';
    private const CACHE_PREFIX = 'spookyapp/amazon/';
    private const SETTING_RAPIDAPI_KEY = 'spookyapp.rapidapi_key';

    /** @var int Кеш для деталей товара (12 часов) */
    private const TTL_DETAILS = 43200;

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Properties                                             ║
    // ╚═════════════════════════════════════════════════════════╝

    private string $apiKey;

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constructor                                            ║
    // ╚═════════════════════════════════════════════════════════╝

    public function __construct(modX $modx, CacheService $cache)
    {
        parent::__construct($modx, $cache);

        $this->apiKey = (string)$this->modx->getOption(self::SETTING_RAPIDAPI_KEY, null, '');

        if (empty($this->apiKey)) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[AmazonProductService] RapidAPI key not configured. '
                . 'Set system setting "' . self::SETTING_RAPIDAPI_KEY . '"'
            );
        }
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  A) Search                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Поиск товаров Amazon.
     *
     * Endpoint: GET /search
     *
     * @param string $query   Поисковый запрос
     * @param array  $options Опции:
     *   - page    (int):    Номер страницы, default 1
     *   - country (string): Код страны ISO, default 'US'
     *
     * @return array{status: string, total: int, page: int, products: array}
     */
    public function searchProducts(string $query, array $options = []): array
    {
        $empty = [
            'status'   => 'error',
            'total'    => 0,
            'page'     => 1,
            'products' => [],
        ];

        if (empty(trim($query))) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[AmazonProductService] searchProducts: empty query');
            return $empty;
        }

        $country = strtoupper(trim((string)($options['country'] ?? 'US'))) ?: 'US';
        $page    = max(1, (int)($options['page'] ?? 1));

        $data = $this->request('GET', '/search', [
            'query'               => $query,
            'page'                => (string)$page,
            'country'             => $country,
            'sort_by'             => 'RELEVANCE',
            'product_condition'   => 'ALL',
            'is_prime'            => 'false',
            'deals_and_discounts' => 'NONE',
        ]);

        if ($data === null) {
            return $empty;
        }

        $rawProducts = $data['data']['products'] ?? [];
        $products    = array_map(
            [$this, 'normalizeSearchResult'],
            is_array($rawProducts) ? array_values($rawProducts) : []
        );

        $total = (int)($data['data']['total_products'] ?? count($products));

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[AmazonProductService] searchProducts: "' . $query . '" country=' . $country
            . ' page=' . $page . ' → ' . count($products) . ' products (total: ' . $total . ')'
        );

        return [
            'status'   => $data['status'] ?? 'OK',
            'total'    => $total,
            'page'     => $page,
            'products' => $products,
        ];
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  B) Details                                             ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить детальную информацию о товаре по ASIN.
     *
     * Endpoint: GET /product-details
     *
     * Возвращаемый формат совместим с ProductSearchService::getProductDetails().
     *
     * @param string $asin    Amazon ASIN (e.g. "B0DNV4NWF7")
     * @param string $country Код страны, default 'US'
     *
     * @return array|array{}
     */
    public function getProductDetails(string $asin, string $country = 'US'): array
    {
        if (empty(trim($asin))) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[AmazonProductService] getProductDetails: empty ASIN');
            return [];
        }

        $country = strtoupper(trim($country)) ?: 'US';

        // ── Кеш ──────────────────────────────────────────────
        $cacheKey = self::CACHE_PREFIX . 'details/' . md5($asin . '|' . $country);
        $cached   = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[AmazonProductService] getProductDetails: cache hit ASIN=' . $asin);
            return $cached;
        }

        // ── API запрос ───────────────────────────────────────
        $data = $this->request('GET', '/product-details', [
            'asin'    => $asin,
            'country' => $country,
        ]);

        if ($data === null) {
            return [];
        }

        $product = $data['data'] ?? [];
        if (empty($product)) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[AmazonProductService] getProductDetails: empty data for ASIN=' . $asin);
            return [];
        }

        // ── Изображения ──────────────────────────────────────
        $images = $this->extractImages($product);

        // ── Описание: about_product + product_description ────
        $description = '';
        if (!empty($product['about_product']) && is_array($product['about_product'])) {
            $description = implode("\n", array_filter(
                array_map('strval', $product['about_product'])
            ));
        }
        if (empty($description) && !empty($product['product_description'])) {
            $description = (string)$product['product_description'];
        }

        // ── Характеристики: product_information → текст ──────
        $productAttributes = '';
        if (!empty($product['product_information']) && is_array($product['product_information'])) {
            $parts = [];
            foreach ($product['product_information'] as $k => $v) {
                if (is_scalar($v)) {
                    $parts[] = '<b>' . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . '</b>: ' . $v;
                }
            }
            $productAttributes = implode('. ', $parts);
        }

        // ── Цена ─────────────────────────────────────────────
        $price = trim((string)($product['product_price'] ?? ''));
        // Если нет символа валюты — добавим $
        if ($price !== '' && $price[0] !== '$' && !preg_match('/^[A-Z€£¥₹]/', $price)) {
            $price = '$' . $price;
        }

        // ── Бренд ────────────────────────────────────────────
        $brand = null;
        if (!empty($product['product_information']['Brand'])) {
            $brand = (string)$product['product_information']['Brand'];
        } elseif (!empty($product['product_byline'])) {
            // "Visit the ASRock Store" → очищаем
            $brand = (string)$product['product_byline'];
        }

        // ── Рейтинг ──────────────────────────────────────────
        $rating = null;
        if (!empty($product['product_star_rating'])) {
            $rating = (float)$product['product_star_rating'];
        }

        // ── Кол-во отзывов ───────────────────────────────────
        $reviewsCount = null;
        if (isset($product['product_num_ratings'])) {
            $reviewsCount = (int)$product['product_num_ratings'];
        }

        // ── Минимальный оффер от Amazon ───────────────────────
        $offers = [];
        if ($price !== '') {
            $offers[] = [
                'store_name'   => 'Amazon',
                'store_url'    => 'https://www.amazon.com',
                'offer_url'    => $product['product_url'] ?? null,
                'price'        => $price,
                'currency'     => $product['currency'] ?? 'USD',
                'shipping'     => $product['delivery_price'] ?? null,
                'availability' => $product['product_availability'] ?? null,
                'condition'    => $product['product_condition'] ?? null,
                'tax'          => null,
            ];
        }

        // ── Отзывы (top_reviews) ─────────────────────────────
        $reviews = [];
        if (!empty($product['top_reviews']) && is_array($product['top_reviews'])) {
            foreach (array_slice($product['top_reviews'], 0, 5) as $review) {
                if (!is_array($review)) {
                    continue;
                }
                $reviews[] = [
                    'author'  => (string)($review['review_author'] ?? ''),
                    'rating'  => (string)($review['review_star_rating'] ?? ''),
                    'title'   => (string)($review['review_title'] ?? ''),
                    'comment' => mb_substr((string)($review['review_comment'] ?? ''), 0, 500),
                    'date'    => (string)($review['review_date'] ?? ''),
                ];
            }
        }

        $result = [
            'status'              => $data['status'] ?? 'OK',
            'product_id'          => $asin,
            'title'               => (string)($product['product_title'] ?? ''),
            'description'         => $description,
            'images'              => $images,
            'price'               => $price,
            'currency'            => (string)($product['currency'] ?? 'USD'),
            'availability'        => $product['product_availability'] ?? null,
            'brand'               => $brand,
            'rating'              => $rating,
            'reviews_count'       => $reviewsCount,
            'product_url'         => $product['product_url'] ?? null,
            'store'               => 'Amazon',
            'specifications'      => [],
            'product_attributes'  => $productAttributes,
            'typical_price_range' => null,
            'offers_count'        => (int)($product['product_num_offers'] ?? 1),
            'offers'              => $offers,
            'reviews'             => $reviews,
            'is_best_seller'      => (bool)($product['is_best_seller'] ?? false),
            'is_amazon_choice'    => (bool)($product['is_amazon_choice'] ?? false),
            'sales_volume'        => $product['sales_volume'] ?? null,
            'delivery'            => $product['delivery'] ?? null,
        ];

        $this->cache->set($cacheKey, $result, self::TTL_DETAILS);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[AmazonProductService] getProductDetails: ASIN=' . $asin
            . ' "' . mb_substr($result['title'], 0, 60) . '"'
            . ' price=' . $price
            . ' rating=' . ($rating ?? 'n/a')
            . ' images=' . count($images)
        );

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Normalization                                 ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Нормализовать элемент списка поиска.
     *
     * @param array $raw Сырые данные из /search response
     * @return array Нормализованный формат (совместим с ProductSearchService)
     */
    private function normalizeSearchResult(array $raw): array
    {
        $price = trim((string)($raw['product_price'] ?? $raw['product_minimum_offer_price'] ?? ''));
        if ($price !== '' && $price[0] !== '$' && !preg_match('/^[A-Z€£¥₹]/', $price)) {
            $price = '$' . $price;
        }

        return [
            'product_id'       => (string)($raw['asin'] ?? ''),
            'title'            => (string)($raw['product_title'] ?? ''),
            'description'      => null,
            'price'            => $price ?: null,
            'currency'         => $raw['currency'] ?? 'USD',
            'image_url'        => $raw['product_photo'] ?? null,
            'product_url'      => $raw['product_url'] ?? null,
            'brand'            => null,
            'rating'           => isset($raw['product_star_rating'])
                ? (float)$raw['product_star_rating'] : null,
            'reviews_count'    => isset($raw['product_num_ratings'])
                ? (int)$raw['product_num_ratings'] : null,
            'store'            => 'Amazon',
            'condition'        => null,
            'is_best_seller'   => (bool)($raw['is_best_seller'] ?? false),
            'is_amazon_choice' => (bool)($raw['is_amazon_choice'] ?? false),
            'sales_volume'     => $raw['sales_volume'] ?? null,
            'delivery'         => $raw['delivery'] ?? null,
        ];
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Image Extraction                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Извлечь массив URL изображений из данных товара.
     *
     * @param array $data Данные из API
     * @return array<string>
     */
    private function extractImages(array $data): array
    {
        if (!empty($data['product_photos']) && is_array($data['product_photos'])) {
            return array_values(array_filter($data['product_photos'], 'is_string'));
        }

        $images = [];
        if (!empty($data['product_photo']) && is_string($data['product_photo'])) {
            $images[] = $data['product_photo'];
        }

        return $images;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: HTTP Request                                  ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Выполнить HTTP-запрос к Amazon API.
     *
     * @param string $method HTTP-метод
     * @param string $uri    Относительный URI (e.g. '/search')
     * @param array  $params Query-параметры
     *
     * @return array|null Декодированный JSON или null при ошибке
     */
    private function request(string $method, string $uri, array $params = []): ?array
    {
        if (empty($this->apiKey)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[AmazonProductService] Cannot make request: RapidAPI key is empty');
            return null;
        }

        $result = $this->httpGet(
            $this->buildUrl(self::BASE_URL . $uri, $params),
            [
                'X-RapidAPI-Key: ' . $this->apiKey,
                'X-RapidAPI-Host: ' . self::RAPIDAPI_HOST,
                'Accept: application/json',
            ],
            30
        );

        if (!$result['success']) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[AmazonProductService] HTTP error for ' . $uri . ': ' . ($result['error'] ?? '')
            );
            return null;
        }

        return is_array($result['data']) ? $result['data'] : null;
    }
}
