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
 * Сервис для работы с API мобильных устройств.
 *
 * Объединяет два источника данных:
 *
 * ═══════════════════════════════════════════════════════════════
 * Источник 1: MobileApi.dev (основной, для TopicFinder)
 * ═══════════════════════════════════════════════════════════════
 *   Base URL:  https://api.mobileapi.dev
 *   Auth:      Bearer token (spookyapp.mobileapi_token)
 *   Методы:    getDevicesByYear(), getRecentDevices(), searchDevices(),
 *              getPopularBrands(), aggregateForTopicFinder()
 *
 * ═══════════════════════════════════════════════════════════════
 * Источник 2: Mobile Phones API via RapidAPI (для Chunk Generator)
 * ═══════════════════════════════════════════════════════════════
 *   Base URL:  https://mobile-phones2.p.rapidapi.com
 *   Auth:      X-RapidAPI-Key (spookyapp.rapidapi_key)
 *   Host:      mobile-phones2.p.rapidapi.com
 *   Методы:    getBrands(), getPhonesByBrand(), searchPhones(),
 *              getPhoneDetailsBySlug()
 *
 * ─────────────────────────────────────────────────────────────
 * Два источника обновляются в разное время и различаются по
 * актуальности данных. Это позволяет:
 * - Сравнивать корректность и полноту данных
 * - Использовать каждый для своих задач
 * - Иметь fallback при недоступности одного из API
 *
 * API Docs:
 * - https://mobileapi.dev/docs
 * - https://rapidapi.com/anthropic-ai/api/mobile-phones2
 *
 * @package SpookyApp
 * @subpackage Services\API
 */
class MobileDevicesAPIService extends APIService
{
    // ─── Конфигурация API: MobileApi.dev (Источник 1) ────────────
    private const BASE_URL = 'https://api.mobileapi.dev';

    // ─── Конфигурация API: RapidAPI Mobile Phones (Источник 2) ───
    private const RAPIDAPI_BASE_URL = 'https://mobile-phones2.p.rapidapi.com';
    private const RAPIDAPI_HOST     = 'mobile-phones2.p.rapidapi.com';

    // ─── Системные настройки ─────────────────────────────────────
    private const SETTING_TOKEN      = 'spookyapp.mobileapi_token';
    private const SETTING_RAPIDAPI   = 'spookyapp.rapidapi_key';

    // ─── Кеш TTL: MobileApi.dev (секунды) ───────────────────────
    private const TTL_BY_YEAR    = 86400;   // 24 часа
    private const TTL_RECENT     = 43200;   // 12 часов
    private const TTL_SEARCH     = 86400;   // 24 часа
    private const TTL_BRANDS     = 86400;   // 24 часа

    // ─── Кеш TTL: RapidAPI Mobile Phones (секунды) ──────────────
    private const TTL_RA_BRANDS         = 604800;  // 7 дней — бренды меняются крайне редко
    private const TTL_RA_PHONES_BY_BRAND = 86400;  // 24 часа
    private const TTL_RA_SEARCH          = 43200;  // 12 часов
    private const TTL_RA_PHONE_DETAILS   = 604800; // 7 дней — характеристики не меняются

    // ─── Кеш префиксы ───────────────────────────────────────────
    private const CACHE_PREFIX    = 'mobiledev_';
    private const CACHE_PREFIX_RA = 'mobilephones_';

    // ─── User-Agent ──────────────────────────────────────────────
    private const USER_AGENT = 'SpookyApp/1.0 (MODX Blog Topic Finder)';

    // ─── HTTP ────────────────────────────────────────────────────
    private const REQUEST_TIMEOUT = 15;

    // ─── Типы устройств, релевантные для TopicFinder ─────────────
    private const RELEVANT_TYPES = ['smartphone', 'tablet'];

    // ─── Приоритетные бренды (для сортировки в агрегации) ─────────
    private const PRIORITY_BRANDS = [
        'samsung'  => 10,
        'apple'    => 10,
        'xiaomi'   => 8,
        'google'   => 8,
        'oneplus'  => 7,
        'huawei'   => 6,
        'oppo'     => 5,
        'vivo'     => 5,
        'sony'     => 5,
        'motorola' => 4,
        'nothing'  => 4,
        'realme'   => 3,
        'honor'    => 3,
    ];

    public function __construct(modX $modx, CacheService $cache)
    {
        parent::__construct($modx, $cache);
    }

    // ╔═════════════════════════════════════════════════════════════╗
    // ║  ИСТОЧНИК 2: RapidAPI Mobile Phones                       ║
    // ║  (mobile-phones2.p.rapidapi.com)                          ║
    // ║  Для Chunk Generator — детальная информация об устройствах ║
    // ╚═════════════════════════════════════════════════════════════╝

    // ═════════════════════════════════════════════════════════════
    // RA-1. getBrands — Список всех брендов
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить список всех брендов мобильных устройств.
     *
     * Источник: RapidAPI Mobile Phones
     * Endpoint: GET /brands
     * Кеш: 7 дней (список брендов обновляется крайне редко).
     *
     * ─────────────────────────────────────────────────────────
     * Пример вызова:
     * ```php
     * $brands = $service->getBrands();
     * // [
     * //     ['name' => 'Samsung', 'slug' => 'samsung'],
     * //     ['name' => 'Apple',   'slug' => 'apple'],
     * //     ...
     * // ]
     * ```
     *
     * @return array{success: bool, brands: array<int, array{name: string, slug: string}>, error: string|null}
     */
    public function getBrands(): array
    {
        $cacheKey = self::CACHE_PREFIX_RA . 'brands_all';

        $result = $this->cachedRequest($cacheKey, self::TTL_RA_BRANDS, function (): ?array {
            return $this->fetchBrands();
        });

        if ($result === null) {
            return ['success' => false, 'brands' => [], 'error' => 'Failed to fetch brands from RapidAPI'];
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // RA-2. getPhonesByBrand — Телефоны конкретного бренда
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить список телефонов конкретного бренда.
     *
     * Источник: RapidAPI Mobile Phones
     * Endpoint: GET /{brandName}/phones
     * Кеш: 24 часа.
     *
     * ─────────────────────────────────────────────────────────
     * Пример вызова:
     * ```php
     * $result = $service->getPhonesByBrand('Samsung', 1);
     * // [
     * //     'success' => true,
     * //     'brand'   => 'Samsung',
     * //     'page'    => 1,
     * //     'phones'  => [
     * //         ['name' => 'Samsung Galaxy S25 Ultra', 'slug' => '...', 'image' => '...'],
     * //         ...
     * //     ],
     * //     'error' => null,
     * // ]
     * ```
     *
     * @param string $brandName Название бренда (например "Samsung", "Apple", "Nothing")
     * @param int    $page      Номер страницы (default: 1)
     * @return array{success: bool, brand: string, page: int, phones: array, error: string|null}
     */
    public function getPhonesByBrand(string $brandName, int $page = 1): array
    {
        $brandName = trim($brandName);
        if (empty($brandName)) {
            return $this->rapidApiError('getPhonesByBrand: empty brandName');
        }

        $page = max(1, $page);
        $cacheKey = self::CACHE_PREFIX_RA . 'brand_' . $this->slugify($brandName) . '_p' . $page;

        $result = $this->cachedRequest($cacheKey, self::TTL_RA_PHONES_BY_BRAND, function () use ($brandName, $page): ?array {
            return $this->fetchPhonesByBrand($brandName, $page);
        });

        if ($result === null) {
            return [
                'success' => false,
                'brand'   => $brandName,
                'page'    => $page,
                'phones'  => [],
                'error'   => "Failed to fetch phones for brand '{$brandName}'",
            ];
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // RA-3. searchPhones — Поиск телефонов по названию
    // ═════════════════════════════════════════════════════════════

    /**
     * Поиск телефонов по названию.
     *
     * Источник: RapidAPI Mobile Phones
     * Endpoint: GET /search?q={query}
     * Кеш: 12 часов.
     *
     * ─────────────────────────────────────────────────────────
     * Пример вызова:
     * ```php
     * $result = $service->searchPhones('NOTHING Phone (4a)');
     * // [
     * //     'success' => true,
     * //     'query'   => 'NOTHING Phone (4a)',
     * //     'count'   => 2,
     * //     'phones'  => [
     * //         ['name' => 'Nothing Phone (4a) 5G', 'slug' => 'nothing_phone_(4a)_5g-14503', ...],
     * //         ...
     * //     ],
     * //     'error' => null,
     * // ]
     * ```
     *
     * @param string $query Поисковый запрос (минимум 2 символа)
     * @return array{success: bool, query: string, count: int, phones: array, error: string|null}
     */
    public function searchPhones(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return $this->rapidApiError("searchPhones: query too short: '{$query}'");
        }

        $cacheKey = self::CACHE_PREFIX_RA . 'search_' . md5($query);

        $result = $this->cachedRequest($cacheKey, self::TTL_RA_SEARCH, function () use ($query): ?array {
            return $this->fetchSearchPhones($query);
        });

        if ($result === null) {
            return [
                'success' => false,
                'query'   => $query,
                'count'   => 0,
                'phones'  => [],
                'error'   => "Search failed for query: '{$query}'",
            ];
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // RA-4. getPhoneDetailsBySlug — Полные характеристики устройства
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить полные характеристики телефона по slug.
     *
     * Источник: RapidAPI Mobile Phones
     * Endpoint: GET /phones/{slug}
     * Кеш: 7 дней (характеристики выпущенного устройства не меняются).
     *
     * ─────────────────────────────────────────────────────────
     * Пример вызова:
     * ```php
     * $details = $service->getPhoneDetailsBySlug('nothing_phone_(4a)_5g-14503');
     * // [
     * //     'success'      => true,
     * //     'name'         => 'Nothing Phone (4a) 5G',
     * //     'slug'         => 'nothing_phone_(4a)_5g-14503',
     * //     'brand'        => 'Nothing',
     * //     'image'        => 'https://...',
     * //     'release_date' => '2025-03-01',
     * //     'os'           => 'Android 15, Nothing OS 3.1',
     * //     'specifications' => [
     * //         ['category' => 'Display', 'specs' => [...]],
     * //         ['category' => 'Battery', 'specs' => [...]],
     * //         ...
     * //     ],
     * //     'specs_flat' => [
     * //         'display'   => [...],
     * //         'processor' => [...],
     * //         'memory'    => [...],
     * //         'camera'    => [...],
     * //         'battery'   => [...],
     * //         'network'   => [...],
     * //         'body'      => [...],
     * //     ],
     * //     'raw' => [...],
     * //     'error' => null,
     * // ]
     * ```
     *
     * @param string $slug Slug устройства (например "nothing_phone_(4a)_5g-14503")
     * @return array Полные характеристики (success=false при ошибке)
     */
    public function getPhoneDetailsBySlug(string $slug): array
    {
        $slug = trim($slug);
        if (empty($slug)) {
            return $this->rapidApiError('getPhoneDetailsBySlug: empty slug');
        }

        $cacheKey = self::CACHE_PREFIX_RA . 'details_' . $this->slugify($slug);

        $result = $this->cachedRequest($cacheKey, self::TTL_RA_PHONE_DETAILS, function () use ($slug): ?array {
            return $this->fetchPhoneDetails($slug);
        });

        if ($result === null) {
            return [
                'success' => false,
                'error'   => "Failed to fetch details for slug: '{$slug}'",
            ];
        }

        return $result;
    }

    // ╔═════════════════════════════════════════════════════════════╗
    // ║  ИСТОЧНИК 1: MobileApi.dev                                ║
    // ║  (api.mobileapi.dev)                                      ║
    // ║  Для TopicFinder — тренды и агрегация                     ║
    // ╚═════════════════════════════════════════════════════════════╝

    // ═════════════════════════════════════════════════════════════
    // 1. Устройства по году
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить устройства, анонсированные в указанном году.
     *
     * Endpoint: /devices/by-year/?year={year}&page={page}
     *
     * @param int $year Год анонса (напр. 2025)
     * @param int $page Номер страницы (1-based)
     * @return array{success: bool, devices: array<int, array>, total_count: int, error: string|null}
     */
    public function getDevicesByYear(int $year, int $page = 1): array
    {
        if ($year < 1990 || $year > (int)date('Y') + 1) {
            return $this->errorResponse("Invalid year: {$year}");
        }

        $cacheKey = self::CACHE_PREFIX . "by_year_{$year}_p{$page}";

        $result = $this->cachedRequest($cacheKey, self::TTL_BY_YEAR, function () use ($year, $page): ?array {
            return $this->fetchDevicesByYear($year, $page);
        });

        if ($result === null) {
            return $this->errorResponse("Failed to fetch devices for year {$year}");
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // 2. Последние анонсированные устройства
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить последние анонсированные устройства.
     *
     * Endpoint: /devices/?page=1
     *
     * @param int $limit Максимальное количество (нарезка из page=1)
     * @return array{success: bool, devices: array<int, array>, total_count: int, error: string|null}
     */
    public function getRecentDevices(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $cacheKey = self::CACHE_PREFIX . "recent_l{$limit}";

        $result = $this->cachedRequest($cacheKey, self::TTL_RECENT, function () use ($limit): ?array {
            return $this->fetchRecentDevices($limit);
        });

        if ($result === null) {
            return $this->errorResponse('Failed to fetch recent devices');
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // 3. Поиск устройств
    // ═════════════════════════════════════════════════════════════

    /**
     * Поиск устройств по названию.
     *
     * Endpoint: /devices/search/?name={query}&page={page}
     *
     * @param string $query Поисковый запрос
     * @param int    $page  Номер страницы (1-based)
     * @return array{success: bool, devices: array<int, array>, total_count: int, error: string|null}
     */
    public function searchDevices(string $query, int $page = 1): array
    {
        $query = trim($query);
        if (empty($query)) {
            return $this->errorResponse('Search query is empty');
        }

        $cacheKey = self::CACHE_PREFIX . 'search_' . md5($query) . "_p{$page}";

        $result = $this->cachedRequest($cacheKey, self::TTL_SEARCH, function () use ($query, $page): ?array {
            return $this->fetchSearchDevices($query, $page);
        });

        if ($result === null) {
            return $this->errorResponse("Search failed for query: '{$query}'");
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // 3b. getDeviceByIdMobileApi — Детали устройства по ID (MobileApi.dev)
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить данные устройства по ID из MobileApi.dev.
     *
     * Endpoint: GET /devices/{id}/
     * Кеш: 24 часа.
     *
     * @param string $id ID устройства (из normalizeDevice результатов поиска)
     * @return array{success: bool, device: array|null, error: string|null}
     */
    public function getDeviceByIdMobileApi(string $id): array
    {
        $id = trim($id);
        if (empty($id)) {
            return ['success' => false, 'device' => null, 'error' => 'getDeviceByIdMobileApi: empty id'];
        }

        $cacheKey = self::CACHE_PREFIX . 'detail_' . $this->slugify($id);

        $result = $this->cachedRequest($cacheKey, self::TTL_BY_YEAR, function () use ($id): ?array {
            return $this->fetchDeviceById($id);
        });

        if ($result === null) {
            return ['success' => false, 'device' => null, 'error' => "Device not found: '{$id}'"];
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // 4. Агрегация для TopicFinder
    // ═════════════════════════════════════════════════════════════

    /**
     * Агрегировать данные из MobileApi.dev для TopicFinderService.
     *
     * Получает новинки текущего года, фильтрует по типу (смартфоны, планшеты),
     * нормализует в формат TopicFinder и сортирует по приоритету бренда.
     *
     * Формат темы TopicFinder:
     * - id: string — уникальный идентификатор
     * - source: 'mobileapi'
     * - title: string — Brand + Name
     * - url: string|null — детальная страница
     * - description: string — описание характеристик
     * - category: 'Gadgets'
     * - published_at: string (ISO 8601)
     * - score: 0.0
     * - metadata: array
     *
     * @return array<int, array> Массив тем для TopicFinder
     */
    public function aggregateForTopicFinder(): array
    {
        $topics = [];
        $currentYear = (int)date('Y');

        // ── Устройства текущего года (до 3 страниц) ────────────
        for ($page = 1; $page <= 3; $page++) {
            $result = $this->getDevicesByYear($currentYear, $page);

            if (!$result['success'] || empty($result['devices'])) {
                break;
            }

            foreach ($result['devices'] as $device) {
                // Фильтруем по релевантности (смартфоны и планшеты)
                if (!$this->isRelevant($device)) {
                    continue;
                }

                $topics[] = $this->deviceToTopic($device);
            }

            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                "[MobileDevices] Year {$currentYear}, page {$page}: " . count($result['devices']) . ' devices'
            );
        }

        // ── Дополняем последними анонсами (если мало данных) ────
        if (count($topics) < 10) {
            $recent = $this->getRecentDevices(40);
            if ($recent['success']) {
                foreach ($recent['devices'] as $device) {
                    if (!$this->isRelevant($device)) {
                        continue;
                    }
                    $topics[] = $this->deviceToTopic($device);
                }
                $this->modx->log(
                    modX::LOG_LEVEL_DEBUG,
                    '[MobileDevices] Recent devices added: ' . count($recent['devices'])
                );
            }
        }

        // ── Дедупликация по device_id ──────────────────────────
        $seen = [];
        $unique = [];
        foreach ($topics as $topic) {
            $deviceId = $topic['metadata']['device_id'] ?? '';
            if (!empty($deviceId) && isset($seen[$deviceId])) {
                continue;
            }
            $seen[$deviceId] = true;
            $unique[] = $topic;
        }

        // ── Сортировка по приоритету бренда ─────────────────────
        usort($unique, function (array $a, array $b): int {
            $priorityA = $this->getBrandPriority((string)($a['metadata']['brand'] ?? ''));
            $priorityB = $this->getBrandPriority((string)($b['metadata']['brand'] ?? ''));
            return $priorityB <=> $priorityA;
        });

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[MobileDevices] aggregateForTopicFinder: ' . count($unique) . ' уникальных тем'
            . ' (до дедупликации: ' . count($topics) . ')'
        );

        return ['success' => true, 'topics' => $unique, 'error' => null];
    }

    // ═════════════════════════════════════════════════════════════
    // 5. Популярные бренды
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить статистику по популярным брендам.
     *
     * Собирает бренды из устройств текущего года и считает
     * количество новинок на бренд.
     *
     * @return array{success: bool, brands: array<string, int>, error: string|null}
     */
    public function getPopularBrands(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'popular_brands_' . date('Y');

        $result = $this->cachedRequest($cacheKey, self::TTL_BRANDS, function (): ?array {
            return $this->fetchPopularBrands();
        });

        if ($result === null) {
            return ['success' => false, 'brands' => [], 'error' => 'Failed to fetch popular brands'];
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // 6. Нормализация устройства (MobileApi.dev)
    // ═════════════════════════════════════════════════════════════

    /**
     * Нормализовать данные устройства из ответа MobileApi.dev.
     *
     * @param array $rawDevice Сырые данные устройства из API
     * @return array Нормализованные данные
     */
    public function normalizeDevice(array $rawDevice): array
    {
        // Pre-extract nested sub-objects (present in detail endpoint, absent in search)
        $platform     = is_array($rawDevice['platform']     ?? null) ? $rawDevice['platform']     : [];
        $memoryObj    = is_array($rawDevice['memory']       ?? null) ? $rawDevice['memory']       : [];
        $batteryObj   = is_array($rawDevice['battery']      ?? null) ? $rawDevice['battery']      : [];
        $manufacturer = is_array($rawDevice['manufacturer'] ?? null) ? $rawDevice['manufacturer'] : [];

        $brand = (string)($rawDevice['brand'] ?? $rawDevice['manufacturer_name'] ?? $manufacturer['name'] ?? '');
        $name  = (string)($rawDevice['name'] ?? '');

        return [
            'id'             => (string)($rawDevice['id'] ?? $rawDevice['slug'] ?? ''),
            'brand'          => $brand,
            'name'           => $name,
            'full_name'      => trim($brand . ' ' . $name),
            'type'           => mb_strtolower((string)($rawDevice['type'] ?? $rawDevice['device_type'] ?? '')),
            'announced_date' => (string)(
                $rawDevice['announced_date']
                ?? $rawDevice['announced']
                ?? $rawDevice['release_date']
                ?? ''
            ),
            'os'             => (string)(
                $rawDevice['os'] ?? $rawDevice['operating_system'] ?? $platform['os'] ?? ''
            ),
            'chipset'        => (string)(
                $rawDevice['chipset'] ?? $rawDevice['processor']
                ?? $platform['chipset'] ?? $platform['cpu'] ?? ''
            ),
            'display'        => $this->extractDisplay($rawDevice),
            'camera'         => $this->extractCamera($rawDevice),
            'battery'        => (string)(
                $rawDevice['battery_capacity'] ?? $batteryObj['type'] ?? ''
            ),
            'ram'            => (string)($rawDevice['ram'] ?? $memoryObj['ram'] ?? ''),
            'storage'        => (string)($rawDevice['storage'] ?? $rawDevice['internal_storage'] ?? $memoryObj['internal'] ?? ''),
            'image_url'      => (string)($rawDevice['image_url'] ?? $rawDevice['image'] ?? $rawDevice['thumbnail'] ?? ''),
            'detail_url'     => (string)($rawDevice['detail_url'] ?? $rawDevice['url'] ?? ''),
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // 7. Генерация описания
    // ═════════════════════════════════════════════════════════════

    /**
     * Генерировать человекочитаемое описание устройства.
     *
     * Пример: "Samsung Galaxy S26 Ultra — флагман на Android 16
     * с процессором Snapdragon 8 Gen 4, камерой 200MP и батареей 5000 mAh."
     *
     * @param array $device Нормализованное устройство
     * @return string Описание
     */
    public function generateDescription(array $device): string
    {
        $fullName = (string)($device['full_name'] ?? '');
        $os = (string)($device['os'] ?? '');
        $chipset = (string)($device['chipset'] ?? '');
        $camera = (string)($device['camera'] ?? '');
        $display = (string)($device['display'] ?? '');
        $battery = (string)($device['battery'] ?? '');
        $ram = (string)($device['ram'] ?? '');
        $type = (string)($device['type'] ?? '');

        $parts = [];

        // Заголовок с типом
        $typeLabel = $this->getTypeLabel($type);
        if (!empty($fullName)) {
            $parts[] = !empty($typeLabel)
                ? "{$fullName} — {$typeLabel}"
                : $fullName;
        }

        // ОС
        if (!empty($os)) {
            $parts[] = "на {$os}";
        }

        // Чипсет
        if (!empty($chipset)) {
            $parts[] = "с процессором {$chipset}";
        }

        // Камера
        if (!empty($camera)) {
            $parts[] = "камера {$camera}";
        }

        // Дисплей
        if (!empty($display)) {
            $parts[] = "экран {$display}";
        }

        // Батарея
        if (!empty($battery)) {
            $parts[] = "батарея {$battery}";
        }

        // RAM
        if (!empty($ram)) {
            $parts[] = "RAM {$ram}";
        }

        if (empty($parts)) {
            return "Мобильное устройство {$fullName}.";
        }

        // Собираем: первая часть — заглавная, остальные через запятую
        $description = array_shift($parts);
        if (!empty($parts)) {
            $description .= ', ' . implode(', ', $parts);
        }

        return rtrim($description, '.') . '.';
    }

    // ═════════════════════════════════════════════════════════════
    // 8. Проверка релевантности
    // ═════════════════════════════════════════════════════════════

    /**
     * Проверить, является ли устройство релевантным для TopicFinder.
     *
     * Пропускает только смартфоны и планшеты.
     * Исключает feature phones, smartwatches, accessories.
     *
     * @param array $device Нормализованное устройство
     * @return bool true если устройство релевантно
     */
    public function isRelevant(array $device): bool
    {
        $type = mb_strtolower((string)($device['type'] ?? ''));
        $name = mb_strtolower((string)($device['name'] ?? $device['full_name'] ?? ''));

        // Если тип указан — проверяем по whitelist
        if (!empty($type)) {
            foreach (self::RELEVANT_TYPES as $relevantType) {
                if (strpos($type, $relevantType) !== false) {
                    return true;
                }
            }
            return false;
        }

        // Если тип не указан — пробуем определить по имени
        $irrelevantKeywords = ['watch', 'band', 'buds', 'earphone', 'headset', 'charger', 'case', 'cover'];
        foreach ($irrelevantKeywords as $keyword) {
            if (strpos($name, $keyword) !== false) {
                return false;
            }
        }

        // Если тип неизвестен и нет irrelevant keyword — считаем релевантным
        // (большинство устройств в API — смартфоны)
        return true;
    }

    // ╔═════════════════════════════════════════════════════════════╗
    // ║  Приватные: HTTP-запросы RapidAPI                          ║
    // ╚═════════════════════════════════════════════════════════════╝

    /**
     * Выполнить GET-запрос к Mobile Phones API (RapidAPI).
     *
     * Авторизация: X-RapidAPI-Key + X-RapidAPI-Host headers.
     *
     * @param string               $endpoint Относительный путь (например "/brands")
     * @param array<string, mixed> $params   Query-параметры
     * @return array{success: bool, data: mixed, error: string|null}
     */
    private function rapidApiGet(string $endpoint, array $params = []): array
    {
        $apiKey = $this->getRapidApiKey();
        if ($apiKey === null) {
            return ['success' => false, 'data' => null, 'error' => 'RapidAPI key not configured'];
        }

        $url = $this->buildUrl(self::RAPIDAPI_BASE_URL . $endpoint, $params);

        $headers = [
            'X-RapidAPI-Key: ' . $apiKey,
            'X-RapidAPI-Host: ' . self::RAPIDAPI_HOST,
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT,
        ];

        $this->modx->log(modX::LOG_LEVEL_DEBUG, "[MobilePhones:RapidAPI] GET {$endpoint}");

        return $this->httpGet($url, $headers, self::REQUEST_TIMEOUT);
    }

    // ╔═════════════════════════════════════════════════════════════╗
    // ║  Приватные: HTTP-запросы MobileApi.dev                     ║
    // ╚═════════════════════════════════════════════════════════════╝

    /**
     * Выполнить GET-запрос к MobileApi.dev.
     *
     * Авторизация: Bearer token в Authorization header.
     *
     * @param string               $endpoint Относительный путь
     * @param array<string, mixed> $params   Query-параметры
     * @return array{success: bool, data: mixed, error: string|null}
     */
    private function apiGet(string $endpoint, array $params = []): array
    {
        $token = $this->getBearerToken();
        if ($token === null) {
            return ['success' => false, 'data' => null, 'error' => 'MobileApi.dev Bearer token not configured'];
        }

        $url = $this->buildUrl(self::BASE_URL . $endpoint, $params);

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT,
        ];

        $this->modx->log(modX::LOG_LEVEL_DEBUG, "[MobileDevices] GET {$endpoint}");

        return $this->httpGet($url, $headers, self::REQUEST_TIMEOUT);
    }

    /**
     * Извлечь массив устройств из ответа MobileApi.dev.
     *
     * MobileApi.dev может возвращать результаты в разных форматах:
     * - {results: [...], count: N}
     * - [...] (массив напрямую)
     * - {devices: [...]}
     *
     * @param array $response HTTP-ответ от apiGet
     * @return array{items: array, total_count: int}
     */
    private function extractResults(array $response): array
    {
        if (!$response['success'] || $response['data'] === null) {
            return ['items' => [], 'total_count' => 0];
        }

        $data = $response['data'];

        // Формат: {results: [...], count: N}
        if (is_array($data) && isset($data['results'])) {
            return [
                'items'       => (array)$data['results'],
                'total_count' => (int)($data['count'] ?? count($data['results'])),
            ];
        }

        // Формат: {devices: [...]}
        if (is_array($data) && isset($data['devices'])) {
            return [
                'items'       => (array)$data['devices'],
                'total_count' => (int)($data['count'] ?? $data['total'] ?? count($data['devices'])),
            ];
        }

        // Формат: массив напрямую
        if (is_array($data) && !empty($data) && isset($data[0])) {
            return [
                'items'       => $data,
                'total_count' => count($data),
            ];
        }

        return ['items' => [], 'total_count' => 0];
    }

    // ╔═════════════════════════════════════════════════════════════╗
    // ║  Приватные: fetch-методы RapidAPI (без кеша)               ║
    // ╚═════════════════════════════════════════════════════════════╝

    /**
     * Запросить список брендов из RapidAPI (без кеша).
     *
     * @return array|null
     */
    private function fetchBrands(): ?array
    {
        try {
            $response = $this->rapidApiGet('/brands');

            if (!$response['success'] || $response['data'] === null) {
                $this->modx->log(
                    modX::LOG_LEVEL_WARN,
                    '[MobilePhones:RapidAPI] getBrands: пустой результат. '
                    . ($response['error'] ?? '')
                );
                return null;
            }

            $data = $response['data'];
            $brands = $this->normalizeBrandsResponse($data);

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                '[MobilePhones:RapidAPI] getBrands: ' . count($brands) . ' брендов'
            );

            return [
                'success' => true,
                'brands'  => $brands,
                'error'   => null,
            ];
        } catch (Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[MobilePhones:RapidAPI] getBrands error: ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Запросить телефоны бренда из RapidAPI (без кеша).
     *
     * @param string $brandName Название бренда
     * @param int    $page      Номер страницы
     * @return array|null
     */
    private function fetchPhonesByBrand(string $brandName, int $page): ?array
    {
        try {
            $endpoint = '/' . rawurlencode($brandName) . '/phones';
            $params = ($page > 1) ? ['page' => $page] : [];

            $response = $this->rapidApiGet($endpoint, $params);

            if (!$response['success'] || $response['data'] === null) {
                $this->modx->log(
                    modX::LOG_LEVEL_WARN,
                    "[MobilePhones:RapidAPI] getPhonesByBrand '{$brandName}' page {$page}: пустой результат. "
                    . ($response['error'] ?? '')
                );
                return null;
            }

            $phones = $this->normalizePhonesListResponse($response['data']);

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                "[MobilePhones:RapidAPI] getPhonesByBrand '{$brandName}' page {$page}: "
                . count($phones) . ' телефонов'
            );

            return [
                'success' => true,
                'brand'   => $brandName,
                'page'    => $page,
                'phones'  => $phones,
                'error'   => null,
            ];
        } catch (Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                "[MobilePhones:RapidAPI] getPhonesByBrand '{$brandName}' error: " . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Поиск телефонов через RapidAPI (без кеша).
     *
     * @param string $query Поисковый запрос
     * @return array|null
     */
    private function fetchSearchPhones(string $query): ?array
    {
        try {
            $response = $this->rapidApiGet('/search', ['q' => $query]);

            if (!$response['success'] || $response['data'] === null) {
                $this->modx->log(
                    modX::LOG_LEVEL_WARN,
                    "[MobilePhones:RapidAPI] searchPhones '{$query}': пустой результат. "
                    . ($response['error'] ?? '')
                );
                return null;
            }

            $phones = $this->normalizePhonesListResponse($response['data']);

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                "[MobilePhones:RapidAPI] searchPhones '{$query}': " . count($phones) . ' результатов'
            );

            return [
                'success' => true,
                'query'   => $query,
                'count'   => count($phones),
                'phones'  => $phones,
                'error'   => null,
            ];
        } catch (Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                "[MobilePhones:RapidAPI] searchPhones '{$query}' error: " . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Запросить детали телефона из RapidAPI (без кеша).
     *
     * @param string $slug Slug устройства
     * @return array|null
     */
    private function fetchPhoneDetails(string $slug): ?array
    {
        try {
            $response = $this->rapidApiGet('/phones/' . rawurlencode($slug));

            if (!$response['success'] || $response['data'] === null) {
                $this->modx->log(
                    modX::LOG_LEVEL_WARN,
                    "[MobilePhones:RapidAPI] getPhoneDetails '{$slug}': пустой результат. "
                    . ($response['error'] ?? '')
                );
                return null;
            }

            $data = $response['data'];

            // Log raw response type/structure for diagnosis
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[MobilePhones:RapidAPI] fetchPhoneDetails raw data type=' . gettype($data)
                . (is_array($data) ? ' keys=[' . implode(',', array_keys($data)) . ']' : '')
            );

            $result = $this->normalizePhoneDetailsResponse($data);

            if (empty($result)) {
                $this->modx->log(
                    modX::LOG_LEVEL_WARN,
                    "[MobilePhones:RapidAPI] getPhoneDetails '{$slug}': нормализация вернула пустой результат"
                );
                return null;
            }

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                "[MobilePhones:RapidAPI] getPhoneDetails: " . ($result['name'] ?? $slug)
            );

            return $result;
        } catch (Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                "[MobilePhones:RapidAPI] getPhoneDetails '{$slug}' error: " . $e->getMessage()
            );
            return null;
        }
    }

    // ╔═════════════════════════════════════════════════════════════╗
    // ║  Приватные: fetch-методы MobileApi.dev (без кеша)          ║
    // ╚═════════════════════════════════════════════════════════════╝

    /**
     * Запросить устройства по году (без кеша).
     *
     * @param int $year Год
     * @param int $page Страница
     * @return array|null
     */
    private function fetchDevicesByYear(int $year, int $page): ?array
    {
        $response = $this->apiGet('/devices/by-year/', [
            'year' => $year,
            'page' => $page,
        ]);

        $extracted = $this->extractResults($response);

        if (empty($extracted['items'])) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                "[MobileDevices] Devices by year {$year}, page {$page}: пустой результат"
            );
            return null;
        }

        $devices = array_map([$this, 'normalizeDevice'], $extracted['items']);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[MobileDevices] Year {$year}, page {$page}: " . count($devices) . ' устройств'
        );

        return [
            'success'     => true,
            'devices'     => $devices,
            'total_count' => $extracted['total_count'],
            'error'       => null,
        ];
    }

    /**
     * Запросить последние устройства (без кеша).
     *
     * @param int $limit Лимит
     * @return array|null
     */
    private function fetchRecentDevices(int $limit): ?array
    {
        $response = $this->apiGet('/devices/', [
            'page' => 1,
        ]);

        $extracted = $this->extractResults($response);

        if (empty($extracted['items'])) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[MobileDevices] Recent devices: пустой результат');
            return null;
        }

        // Обрезаем до лимита
        $items = array_slice($extracted['items'], 0, $limit);
        $devices = array_map([$this, 'normalizeDevice'], $items);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[MobileDevices] Recent devices: ' . count($devices) . " (limit={$limit})"
        );

        return [
            'success'     => true,
            'devices'     => $devices,
            'total_count' => $extracted['total_count'],
            'error'       => null,
        ];
    }

    /**
     * Запросить устройство по ID из MobileApi.dev (без кеша).
     *
     * @param string $id ID устройства
     * @return array|null
     */
    private function fetchDeviceById(string $id): ?array
    {
        $response = $this->apiGet('/devices/' . rawurlencode($id) . '/');

        if (!$response['success'] || $response['data'] === null) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                "[MobileDevices] fetchDeviceById '{$id}': empty result. " . ($response['error'] ?? '')
            );
            return null;
        }

        $data = $response['data'];
        // Может вернуть одиночный объект или {results:[...]}
        $rawDevice = is_array($data) && isset($data['results']) && !empty($data['results'])
            ? $data['results'][0]
            : $data;

        if (!is_array($rawDevice) || empty($rawDevice)) {
            return null;
        }

        $device = $this->normalizeDevice($rawDevice);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[MobileDevices] fetchDeviceById '{$id}': " . ($device['full_name'] ?? $id)
        );

        return ['success' => true, 'device' => $device, 'specs' => $this->buildMobileApiSpecsArray($rawDevice), 'error' => null];
    }

    /**
     * Собрать массив спецификаций из вложенных объектов ответа MobileApi.dev /devices/{id}/.
     *
     * Конвертирует поля platform, memory, display... в [{title, specs:[{key,val}]}]
     *
     * @param array $rawDevice Сырой ответ detail-эндпойнта
     * @return array
     */
    private function buildMobileApiSpecsArray(array $rawDevice): array
    {
        $sectionMap = [
            'Platform'      => 'platform',
            'Memory'        => 'memory',
            'Display'       => 'display',
            'Battery'       => 'battery',
            'Body'          => 'body',
            'Network'       => 'network',
            'Main Camera'   => 'main_camera',
            'Selfie Camera' => 'selfie_camera',
            'Sound'         => 'sound',
            'Communications'=> 'comms',
            'Features'      => 'features',
            'Misc'          => 'misc',
        ];
        $skipKeys = ['id'];
        $result = [];
        foreach ($sectionMap as $label => $field) {
            $obj = $rawDevice[$field] ?? null;
            if (!is_array($obj)) {
                continue;
            }
            $specs = [];
            foreach ($obj as $key => $value) {
                if (in_array($key, $skipKeys, true) || is_array($value) || $value === null || $value === '') {
                    continue;
                }
                $specs[] = ['key' => ucwords(str_replace('_', ' ', (string)$key)), 'val' => (string)$value];
            }
            if (!empty($specs)) {
                $result[] = ['title' => $label, 'specs' => $specs];
            }
        }
        return $result;
    }

    /**
     * Поиск устройств (без кеша).
     *
     * @param string $query Запрос
     * @param int    $page  Страница
     * @return array|null
     */
    private function fetchSearchDevices(string $query, int $page): ?array
    {
        $response = $this->apiGet('/devices/search/', [
            'name' => $query,
            'page' => $page,
        ]);

        $extracted = $this->extractResults($response);

        if (empty($extracted['items'])) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                "[MobileDevices] Search '{$query}', page {$page}: пустой результат"
            );
            return null;
        }

        $devices = array_map([$this, 'normalizeDevice'], $extracted['items']);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[MobileDevices] Search '{$query}': " . count($devices) . ' устройств'
        );

        return [
            'success'     => true,
            'devices'     => $devices,
            'total_count' => $extracted['total_count'],
            'error'       => null,
        ];
    }

    /**
     * Собрать статистику по брендам (без кеша).
     *
     * @return array|null
     */
    private function fetchPopularBrands(): ?array
    {
        $currentYear = (int)date('Y');
        $brands = [];

        // Собираем данные с первых 5 страниц текущего года
        for ($page = 1; $page <= 5; $page++) {
            $response = $this->apiGet('/devices/by-year/', [
                'year' => $currentYear,
                'page' => $page,
            ]);

            $extracted = $this->extractResults($response);

            if (empty($extracted['items'])) {
                break;
            }

            foreach ($extracted['items'] as $item) {
                $brand = (string)($item['brand'] ?? '');
                if (empty($brand)) {
                    continue;
                }
                $brandLower = mb_strtolower($brand);
                if (!isset($brands[$brandLower])) {
                    $brands[$brandLower] = [
                        'name'  => $brand,
                        'count' => 0,
                    ];
                }
                $brands[$brandLower]['count']++;
            }
        }

        if (empty($brands)) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[MobileDevices] Popular brands: нет данных');
            return null;
        }

        // Сортировка по количеству устройств (desc)
        uasort($brands, function (array $a, array $b): int {
            return $b['count'] <=> $a['count'];
        });

        // Формируем простой массив brand => count
        $result = [];
        foreach ($brands as $data) {
            $result[$data['name']] = $data['count'];
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[MobileDevices] Popular brands: ' . count($result) . ' брендов'
        );

        return [
            'success' => true,
            'brands'  => $result,
            'error'   => null,
        ];
    }

    // ╔═════════════════════════════════════════════════════════════╗
    // ║  Приватные: нормализация RapidAPI ответов                  ║
    // ╚═════════════════════════════════════════════════════════════╝

    /**
     * Нормализовать ответ /brands (RapidAPI).
     *
     * API может возвращать:
     * - Массив строк: ["Samsung", "Apple", ...]
     * - Массив объектов: [{name: "Samsung", slug: "samsung"}, ...]
     * - Вложенный: {data: [...]} или {brands: [...]}
     *
     * @param mixed $data Сырой ответ API
     * @return array<int, array{name: string, slug: string}>
     */
    private function normalizeBrandsResponse($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        // Вложенный формат
        $items = $data['data'] ?? $data['brands'] ?? $data;
        if (!is_array($items)) {
            return [];
        }

        $brands = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                // Массив строк
                $name = trim($item);
                if (!empty($name)) {
                    $brands[] = [
                        'name' => $name,
                        'slug' => $this->slugify($name),
                    ];
                }
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $name = (string)($item['name'] ?? $item['brand_name'] ?? $item['title'] ?? '');
            if (empty($name)) {
                continue;
            }

            $brands[] = [
                'name' => $name,
                'slug' => (string)($item['slug'] ?? $item['id'] ?? $this->slugify($name)),
            ];
        }

        return $brands;
    }

    /**
     * Нормализовать список телефонов из ответа RapidAPI.
     *
     * Используется для /search и /{brand}/phones.
     *
     * API может возвращать:
     * - Массив напрямую: [{phone_name, slug, image}, ...]
     * - Вложенный: {data: [...]} или {phones: [...]}
     *
     * @param mixed $data Сырой ответ API
     * @return array<int, array{name: string, slug: string, image: string, short_spec: string}>
     */
    private function normalizePhonesListResponse($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        // Вложенный формат
        $items = $data['data'] ?? $data['phones'] ?? $data;
        if (!is_array($items)) {
            return [];
        }

        $phones = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = (string)($item['phone_name'] ?? $item['name'] ?? $item['title'] ?? '');
            if (empty($name)) {
                continue;
            }

            // Build slug: prefer explicit slug fields; extract from URL-path href as last resort
            $slug = (string)($item['phone_slug'] ?? $item['device_slug'] ?? $item['slug'] ?? '');
            if (empty($slug)) {
                $href = (string)($item['href'] ?? '');
                // href may be "/phones/samsung_galaxy_s26_ultra-123" — use only the basename
                $slug = !empty($href) ? basename(trim($href, '/')) : (string)($item['id'] ?? '');
            }

            $phones[] = [
                'name'       => $name,
                'slug'       => $slug,
                'image'      => (string)($item['image_url'] ?? $item['image'] ?? $item['thumbnail'] ?? $item['img'] ?? ''),
                'short_spec' => (string)($item['detail'] ?? $item['description'] ?? $item['short_spec'] ?? ''),
            ];
        }

        return $phones;
    }

    /**
     * Нормализовать детали телефона из RapidAPI /phones/{slug}.
     *
     * Извлекает структурированные спецификации, приводит к
     * плоской структуре по категориям для Chunk Generator.
     *
     * @param mixed $data Сырой ответ API
     * @return array Нормализованные данные (пустой при ошибке)
     */
    private function normalizePhoneDetailsResponse($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[MobilePhones:RapidAPI] normalizePhoneDetailsResponse keys: '
            . implode(', ', array_keys($data))
        );

        // ── Формат: {spotlight:{...}, all_specs:{SectionName:[{title,info}]}} ──
        // Это реальный формат мобильного API mobile-phones2.p.rapidapi.com
        if (isset($data['spotlight']) || isset($data['all_specs'])) {
            $spotlight = is_array($data['spotlight'] ?? null) ? $data['spotlight'] : [];
            $allSpecs  = is_array($data['all_specs']  ?? null) ? $data['all_specs']  : [];

            // Имя телефона не возвращается в ответе — будет установлено из заголовка грида
            $name  = '';
            $brand = '';
            $image = (string)($spotlight['image'] ?? $spotlight['thumbnail'] ?? '');

            // Дату релиза и ОС берём из spotlight
            $releaseDate = (string)($spotlight['releaseDate'] ?? '');
            $os          = (string)($spotlight['os'] ?? '');
            $chipset     = (string)($spotlight['chipset'] ?? '');
            $display     = trim(
                ((string)($spotlight['display_size'] ?? ''))
                . (!empty($spotlight['display_resolution']) ? ', ' . $spotlight['display_resolution'] : '')
            );
            $camera      = (string)($spotlight['camera_pixels'] ?? '');
            $battery     = (string)($spotlight['battery_size'] ?? '');
            $ram         = (string)($spotlight['ram_size'] ?? '');
            $storage     = (string)($spotlight['storage'] ?? '');

            // Приводим all_specs к внутренней плоской структуре
            $specsFlat = $this->flattenRapidApiSpecs($allSpecs);

            // Собираем для отображения: [{title, specs:[{key,val}]}]
            $specifications = $this->buildRapidApiSpecsArray($allSpecs);

            return [
                'success'        => true,
                'name'           => $name,
                'slug'           => '',
                'brand'          => $brand,
                'image'          => $image,
                'release_date'   => $releaseDate,
                'os'             => $os,
                'chipset'        => $chipset,
                'display'        => $display,
                'camera'         => $camera,
                'battery'        => $battery,
                'ram'            => $ram,
                'storage'        => $storage,
                'spotlight'      => $spotlight,
                'specifications' => $specifications,
                'specs_flat'     => $specsFlat,
                'raw'            => $data,
                'error'          => null,
            ];
        }

        // ── Разворачиваем envelope при наличии (legacy/альтернативные источники) ──
        if (!isset($data['phone_name']) && !isset($data['name']) && !isset($data['title'])) {
            $unwrapped = $data['data'] ?? $data['phone'] ?? $data['result'] ?? $data['device'] ?? null;
            if (is_array($unwrapped)) {
                $data = $unwrapped;
            }
        }

        $name = (string)($data['phone_name'] ?? $data['name'] ?? $data['title'] ?? '');
        if (empty($name)) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[MobilePhones:RapidAPI] normalizePhoneDetailsResponse: no name found. Keys: '
                . implode(', ', array_keys($data))
            );
            return [];
        }

        $slug  = (string)($data['slug'] ?? $data['id'] ?? '');
        $brand = (string)($data['brand'] ?? $this->extractBrandFromName($name));
        $image = (string)($data['image_url'] ?? $data['image'] ?? $data['thumbnail'] ?? '');

        $rawSpecs     = $data['specifications'] ?? $data['specs'] ?? [];
        $specsFlatRaw = is_array($rawSpecs) ? $rawSpecs : [];
        $specsFlat    = $this->flattenRapidApiSpecs($specsFlatRaw);

        $releaseDate = $this->extractSpecValue($specsFlat, 'body', ['status', 'announced']);
        $os          = $this->extractSpecValue($specsFlat, 'processor', ['os']);

        return [
            'success'        => true,
            'name'           => $name,
            'slug'           => $slug,
            'brand'          => $brand,
            'image'          => $image,
            'release_date'   => $releaseDate,
            'os'             => $os,
            'specifications' => $specsFlatRaw,
            'specs_flat'     => $specsFlat,
            'raw'            => $data,
            'error'          => null,
        ];
    }

    /**
     * Собрать массив спецификаций из all_specs RapidAPI для отображения.
     *
     * Конвертирует {SectionName: [{title, info}]} → [{title, specs:[{key,val}]}]
     *
     * @param array $allSpecs Объект all_specs из ответа RapidAPI
     * @return array
     */
    private function buildRapidApiSpecsArray(array $allSpecs): array
    {
        $result = [];
        foreach ($allSpecs as $sectionName => $sectionItems) {
            if (!is_array($sectionItems)) {
                continue;
            }
            $specs = [];
            foreach ($sectionItems as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $key = (string)($item['title'] ?? $item['key'] ?? $item['name'] ?? '');
                $val = (string)($item['info']  ?? $item['val'] ?? $item['value'] ?? '');
                if (!empty(trim($key)) || !empty(trim($val))) {
                    $specs[] = ['key' => $key, 'val' => $val];
                }
            }
            if (!empty($specs)) {
                $result[] = ['title' => (string)$sectionName, 'specs' => $specs];
            }
        }
        return $result;
    }

    /**
     * Привести спецификации RapidAPI к плоской структуре по категориям.
     *
     * RapidAPI может возвращать спеки в формате:
     * - Массив секций: [{title: "Display", specs: [{key: "Size", val: "6.7\""}]}]
     * - Объект секций: {Display: [{key: "Size", val: "6.7\""}], Battery: [...]}
     * - Вложенные пары: {Display: {Size: "6.7\"", Type: "AMOLED"}}
     *
     * Результат: {display: {size: "6.7\"", type: "AMOLED"}, processor: {...}, ...}
     *
     * @param array $rawSpecs Сырые спеки из API
     * @return array<string, array<string, string>>
     */
    private function flattenRapidApiSpecs(array $rawSpecs): array
    {
        $result = [
            'display'   => [],
            'processor' => [],
            'memory'    => [],
            'camera'    => [],
            'battery'   => [],
            'network'   => [],
            'body'      => [],
        ];

        if (empty($rawSpecs)) {
            return $result;
        }

        // ── Формат 1: Массив секций [{title, specs: [{key, val}]}] ──
        if (isset($rawSpecs[0]) && is_array($rawSpecs[0])) {
            foreach ($rawSpecs as $section) {
                $sectionTitle = (string)($section['title'] ?? $section['category'] ?? $section['name'] ?? '');
                $category = $this->mapSpecCategory($sectionTitle);
                if ($category === null) {
                    continue;
                }

                $specs = $section['specs'] ?? $section['specifications'] ?? $section['data'] ?? [];
                if (!is_array($specs)) {
                    continue;
                }

                foreach ($specs as $spec) {
                    if (!is_array($spec)) {
                        continue;
                    }
                    $key = (string)($spec['key'] ?? $spec['name'] ?? '');
                    $val = (string)($spec['val'] ?? $spec['value'] ?? '');
                    if (!empty($key) && !empty($val)) {
                        $result[$category][mb_strtolower(trim($key))] = $val;
                    }
                }
            }
            return $result;
        }

        // ── Формат 2: Объект секций {Display: [...], Battery: [...]} ──
        foreach ($rawSpecs as $sectionName => $sectionData) {
            if (!is_array($sectionData)) {
                continue;
            }

            $category = $this->mapSpecCategory((string)$sectionName);
            if ($category === null) {
                continue;
            }

            // Подформат: массив пар [{key, val}] или [{title, info}] (RapidAPI)
            if (isset($sectionData[0]) && is_array($sectionData[0])) {
                foreach ($sectionData as $spec) {
                    $key = (string)($spec['key'] ?? $spec['name'] ?? $spec['title'] ?? '');
                    $val = (string)($spec['val'] ?? $spec['value'] ?? $spec['info'] ?? '');
                    if (!empty($key) && !empty($val)) {
                        $result[$category][mb_strtolower(trim($key))] = $val;
                    }
                }
                continue;
            }

            // Подформат: ассоциативный массив {Size: "6.7\"", Type: "AMOLED"}
            foreach ($sectionData as $key => $value) {
                if (is_string($key) && !empty($value)) {
                    $result[$category][mb_strtolower(trim($key))] = (string)$value;
                }
            }
        }

        return $result;
    }

    /**
     * Маппинг названия секции спецификаций → внутренняя категория.
     *
     * @param string $sectionName Название из API (Display, Platform, etc.)
     * @return string|null Внутренняя категория или null
     */
    private function mapSpecCategory(string $sectionName): ?string
    {
        $map = [
            'display'       => 'display',
            'screen'        => 'display',
            'platform'      => 'processor',
            'processor'     => 'processor',
            'chipset'       => 'processor',
            'memory'        => 'memory',
            'storage'       => 'memory',
            'main camera'   => 'camera',
            'selfie camera' => 'camera',
            'camera'        => 'camera',
            'battery'       => 'battery',
            'charging'      => 'battery',
            'comms'         => 'network',
            'network'       => 'network',
            'connectivity'  => 'network',
            'body'          => 'body',
            'design'        => 'body',
            'misc'          => 'body',
            'launch'        => 'body',
        ];

        $lower = mb_strtolower(trim($sectionName));
        return $map[$lower] ?? null;
    }

    /**
     * Извлечь значение спецификации из плоской структуры.
     *
     * Перебирает ключи по приоритету и возвращает первое непустое значение.
     *
     * @param array    $specsFlat  Плоская структура {category: {key: val}}
     * @param string   $category   Категория (display, processor, etc.)
     * @param string[] $keys       Ключи для поиска (по приоритету)
     * @return string Значение или пустая строка
     */
    private function extractSpecValue(array $specsFlat, string $category, array $keys): string
    {
        $categoryData = $specsFlat[$category] ?? [];
        foreach ($keys as $key) {
            $keyLower = mb_strtolower($key);
            if (!empty($categoryData[$keyLower])) {
                return (string)$categoryData[$keyLower];
            }
        }
        return '';
    }

    /**
     * Извлечь название бренда из полного названия устройства.
     *
     * @param string $name Например "Samsung Galaxy S25 Ultra"
     * @return string Первое слово ("Samsung") или пустая строка
     */
    private function extractBrandFromName(string $name): string
    {
        $parts = explode(' ', trim($name), 2);
        return $parts[0] ?? '';
    }

    // ╔═════════════════════════════════════════════════════════════╗
    // ║  Приватные: конвертация в TopicFinder (MobileApi.dev)      ║
    // ╚═════════════════════════════════════════════════════════════╝

    /**
     * Конвертировать нормализованное устройство в формат темы TopicFinder.
     *
     * @param array $device Нормализованное устройство
     * @return array Тема в формате TopicFinder
     */
    private function deviceToTopic(array $device): array
    {
        $deviceId = (string)($device['id'] ?? '');
        $fullName = (string)($device['full_name'] ?? '');
        $brand = (string)($device['brand'] ?? '');
        $type = (string)($device['type'] ?? '');
        $announcedDate = (string)($device['announced_date'] ?? '');
        $detailUrl = (string)($device['detail_url'] ?? '');

        $description = $this->generateDescription($device);

        // Краткое саммари спецификаций для metadata
        $specsSummary = $this->buildSpecsSummary($device);

        return [
            'id'           => 'mobiledev_' . ($deviceId ?: md5($fullName)),
            'source'       => 'mobileapi',
            'title'        => $fullName,
            'url'          => !empty($detailUrl) ? $detailUrl : null,
            'description'  => $description,
            'category'     => 'Gadgets',
            'published_at' => $this->formatAnnouncedDate($announcedDate),
            'score'        => 0.0,
            'has_image'    => !empty($device['image_url']),
            'metadata'     => [
                'device_id'     => $deviceId,
                'brand'         => $brand,
                'name'          => (string)($device['name'] ?? ''),
                'type'          => $type,
                'specs_summary' => $specsSummary,
                'image_url'     => (string)($device['image_url'] ?? ''),
                'os'            => (string)($device['os'] ?? ''),
                'chipset'       => (string)($device['chipset'] ?? ''),
                'display'       => (string)($device['display'] ?? ''),
                'camera'        => (string)($device['camera'] ?? ''),
                'battery'       => (string)($device['battery'] ?? ''),
                'ram'           => (string)($device['ram'] ?? ''),
                'storage'       => (string)($device['storage'] ?? ''),
            ],
        ];
    }

    // ╔═════════════════════════════════════════════════════════════╗
    // ║  Приватные: утилиты                                        ║
    // ╚═════════════════════════════════════════════════════════════╝

    /**
     * Получить Bearer token для MobileApi.dev из системных настроек.
     *
     * @return string|null Token или null
     */
    private function getBearerToken(): ?string
    {
        $token = $this->modx->getOption(self::SETTING_TOKEN, null, '');
        if (empty($token)) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                "[MobileDevices] Системная настройка '" . self::SETTING_TOKEN . "' не задана"
            );
            return null;
        }
        return $token;
    }

    /**
     * Получить RapidAPI key из системных настроек.
     *
     * @return string|null API key или null
     */
    private function getRapidApiKey(): ?string
    {
        $key = $this->modx->getOption(self::SETTING_RAPIDAPI, null, '');
        if (empty($key)) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                "[MobilePhones:RapidAPI] Системная настройка '" . self::SETTING_RAPIDAPI . "' не задана"
            );
            return null;
        }
        return $key;
    }

    /**
     * Получить приоритет бренда для сортировки.
     *
     * @param string $brand Название бренда
     * @return int Приоритет (чем больше, тем выше)
     */
    private function getBrandPriority(string $brand): int
    {
        $brandLower = mb_strtolower(trim($brand));
        return self::PRIORITY_BRANDS[$brandLower] ?? 1;
    }

    /**
     * Извлечь информацию о дисплее из сырых данных.
     *
     * @param array $rawDevice Сырые данные
     * @return string Описание дисплея
     */
    private function extractDisplay(array $rawDevice): string
    {
        $raw = $rawDevice['display'] ?? $rawDevice['display_size'] ?? null;

        // Вложенный объект {size, resolution, type} (ответ detail MobileApi.dev)
        if (is_array($raw)) {
            $parts = array_filter([
                !empty($raw['size'])       ? (string)$raw['size']       : '',
                !empty($raw['resolution']) ? (string)$raw['resolution'] : '',
            ]);
            return implode(', ', $parts);
        }

        if (!empty($raw)) {
            return (string)$raw;
        }

        // Собираем из отдельных плоских полей
        $parts = [];
        foreach (['display_size', 'display_type', 'display_resolution', 'screen_resolution'] as $k) {
            if (!empty($rawDevice[$k]) && !is_array($rawDevice[$k])) {
                $parts[] = (string)$rawDevice[$k];
            }
        }
        return implode(', ', $parts);
    }

    /**
     * Извлечь информацию о камере из сырых данных.
     *
     * @param array $rawDevice Сырые данные
     * @return string Описание камеры
     */
    private function extractCamera(array $rawDevice): string
    {
        $raw = $rawDevice['camera'] ?? null;

        // Если camera — строка из плоского поиска — возвращаем как есть
        if (is_string($raw) && !empty($raw)) {
            return $raw;
        }

        // Вложенный объект main_camera (detail MobileApi.dev) — может быть null или массивом
        $mainCam = $rawDevice['main_camera'] ?? null;
        if (is_array($mainCam)) {
            foreach (['pixels', 'resolution', 'features', 'quad', 'single', 'dual', 'triple'] as $k) {
                if (!empty($mainCam[$k]) && is_string($mainCam[$k])) {
                    return $mainCam[$k];
                }
            }
        }

        if (!empty($rawDevice['camera_main']) && is_string($rawDevice['camera_main'])) {
            return $rawDevice['camera_main'];
        }

        return '';
    }

    /**
     * Сформировать краткое саммари спецификаций.
     *
     * @param array $device Нормализованное устройство
     * @return string
     */
    private function buildSpecsSummary(array $device): string
    {
        $parts = [];

        if (!empty($device['os'])) {
            $parts[] = (string)$device['os'];
        }
        if (!empty($device['chipset'])) {
            $parts[] = (string)$device['chipset'];
        }
        if (!empty($device['ram'])) {
            $parts[] = 'RAM ' . (string)$device['ram'];
        }
        if (!empty($device['camera'])) {
            $parts[] = (string)$device['camera'];
        }
        if (!empty($device['battery'])) {
            $parts[] = (string)$device['battery'];
        }

        return implode(' | ', $parts);
    }

    /**
     * Получить человекочитаемую метку типа устройства.
     *
     * @param string $type Тип устройства
     * @return string Метка
     */
    private function getTypeLabel(string $type): string
    {
        $type = mb_strtolower(trim($type));

        $labels = [
            'smartphone' => 'смартфон',
            'tablet'     => 'планшет',
            'phone'      => 'телефон',
            'phablet'    => 'фаблет',
        ];

        return $labels[$type] ?? '';
    }

    /**
     * Форматировать дату анонса в ISO 8601.
     *
     * MobileApi.dev может возвращать даты в различных форматах:
     * - "2025" (только год)
     * - "2025, January" (год и месяц)
     * - "2025-01-15" (полная дата)
     * - "January 2025"
     *
     * @param string $date Дата из API
     * @return string ISO 8601 дата
     */
    private function formatAnnouncedDate(string $date): string
    {
        if (empty($date)) {
            return date('Y-m-d\TH:i:s\Z');
        }

        // Полная дата YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date . 'T00:00:00Z';
        }

        // ISO 8601 уже
        if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $date)) {
            return $date;
        }

        // Только год: "2025"
        if (preg_match('/^(\d{4})$/', $date, $matches)) {
            return $matches[1] . '-01-01T00:00:00Z';
        }

        // "2025, January" или "January 2025"
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d\TH:i:s\Z', $timestamp);
        }

        // Fallback
        return date('Y-m-d\TH:i:s\Z');
    }

    /**
     * Сформировать стандартный ответ ошибки (MobileApi.dev).
     *
     * @param string $error Сообщение об ошибке
     * @return array{success: bool, devices: array, total_count: int, error: string}
     */
    private function errorResponse(string $error): array
    {
        $this->modx->log(modX::LOG_LEVEL_ERROR, "[MobileDevices] {$error}");

        return [
            'success'     => false,
            'devices'     => [],
            'total_count' => 0,
            'error'       => $error,
        ];
    }

    /**
     * Сформировать стандартный ответ ошибки (RapidAPI).
     *
     * @param string $error Сообщение об ошибке
     * @return array{success: bool, error: string}
     */
    private function rapidApiError(string $error): array
    {
        $this->modx->log(modX::LOG_LEVEL_ERROR, "[MobilePhones:RapidAPI] {$error}");

        return [
            'success' => false,
            'error'   => $error,
        ];
    }

    /**
     * Slugify: привести строку к safe-ключу для кеша.
     *
     * @param string $value Исходная строка
     * @return string
     */
    private function slugify(string $value): string
    {
        $slug = mb_strtolower(trim($value));
        $slug = (string)preg_replace('/[^a-z0-9_\-]+/', '_', $slug);
        $slug = (string)preg_replace('/_+/', '_', $slug);
        $slug = trim($slug, '_');

        // Ограничиваем длину ключа кеша
        if (mb_strlen($slug) > 80) {
            $slug = mb_substr($slug, 0, 60) . '_' . md5($value);
        }

        return $slug ?: md5($value);
    }
}