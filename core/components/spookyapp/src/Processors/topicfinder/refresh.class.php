<?php

declare(strict_types=1);

namespace SpookyApp\Processors\TopicFinder;

use MODX\Revolution\modX;
use MODX\Revolution\Processors\Processor;
use SpookyApp\Model\SpookyAppTopic;
use SpookyApp\Services\TopicFinderService;
use SpookyApp\Services\Cache\CacheService;
use SpookyApp\Services\API\NewsAPIService;
use SpookyApp\Services\API\RedditService;
use SpookyApp\Services\API\TMDBService;
use SpookyApp\Services\API\GamesAPIService;
use SpookyApp\Services\API\GitHubService;
use SpookyApp\Services\API\MobileDevicesAPIService;
use SpookyApp\Services\API\SportsAPIService;
use SpookyApp\Services\API\BiathlonIBUService;
use SpookyApp\Services\API\FootballAPIService;
use SpookyApp\Services\API\YandexAPIService;
use SpookyApp\Services\TopicScoringService;
use Throwable;

/**
 * Процессор обновления (refresh) тем TopicFinder.
 *
 * Вызывает TopicFinderService::findTrendingTopics(), получает свежие данные
 * из выбранных API-источников, нормализует, скорит и сохраняет/обновляет
 * записи в таблице spookyapp_topics.
 *
 * ═══════════════════════════════════════════════════════════════
 * Параметры запроса (все опциональные):
 * ═══════════════════════════════════════════════════════════════
 *
 * @property string $sources        CSV-список источников для опроса.
 *                                  Допустимые: newsapi, reddit, tmdb, rawg, github,
 *                                  mobileapi, flashlive, ibu, apifootball
 *                                  Пусто = все доступные (default: '' = все)
 *
 * @property string $categories     CSV-список категорий для фильтрации результатов.
 *                                  Допустимые: IT, Gadgets, Games, Cinema, Sports,
 *                                  Science, Other, General
 *                                  Пусто = все (default: '')
 *
 * @property float  $min_score      Минимальный score для сохранения темы (default: 5.0)
 *                                  Темы с score ниже этого порога будут отброшены
 *
 * @property int    $max_topics     Максимальное кол-во тем для получения (default: 50, max: 200)
 *
 * @property bool   $force_refresh  Игнорировать кеш API (default: false)
 *                                  Если true — принудительно запрашивает свежие данные
 *
 * @property bool   $dry_run        Режим «сухого прогона» (default: false)
 *                                  Если true — получает темы, но НЕ сохраняет в БД.
 *                                  Полезно для предварительного просмотра
 *
 * @property bool   $cleanup_old    Архивировать старые темы (default: false)
 *                                  Если true — темы со status=0 старше 30 дней
 *                                  получат status=5 (archived)
 *
 * @property int    $cleanup_days   Возраст тем для архивации в днях (default: 30)
 *                                  Применяется только если cleanup_old=true
 *
 * ═══════════════════════════════════════════════════════════════
 * Ответ (success):
 * ═══════════════════════════════════════════════════════════════
 * ```json
 * {
 *   "success": true,
 *   "message": "Обновление завершено: получено 47 тем, сохранено 12 новых, обновлено 35",
 *   "object": {
 *     "fetched":    47,
 *     "saved_new":  12,
 *     "updated":    35,
 *     "skipped":    0,
 *     "errors":     0,
 *     "archived":   5,
 *     "sources_queried": ["newsapi","tmdb","rawg","github"],
 *     "duration_sec": 3.42,
 *     "by_source": {
 *       "NewsAPI": 15,
 *       "TMDB": 12,
 *       "RAWG (Games)": 10,
 *       "GitHub": 10
 *     },
 *     "by_category": {
 *       "IT": 8,
 *       "Cinema": 12,
 *       "Games": 10,
 *       "Sports": 7,
 *       "Other": 10
 *     },
 *     "top_topics": [
 *       {"topic_id": "tmdb_movie_12345", "title": "...", "score": 92.5, "source": "tmdb"},
 *       ...
 *     ],
 *     "dry_run": false
 *   }
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════
 * Примеры вызова:
 * ═══════════════════════════════════════════════════════════════
 *
 * ```php
 * // Из CMP/контроллера — обновить все источники:
 * $response = $modx->runProcessor('topicfinder/refresh', [], [
 *     'processors_path' => $corePath . 'processors/',
 * ]);
 *
 * // Только кино и игры, принудительно:
 * $response = $modx->runProcessor('topicfinder/refresh', [
 *     'sources'       => 'tmdb,rawg',
 *     'categories'    => 'Cinema,Games',
 *     'force_refresh' => true,
 *     'max_topics'    => 100,
 * ], [
 *     'processors_path' => $corePath . 'processors/',
 * ]);
 *
 * // Сухой прогон (preview без записи в БД):
 * $response = $modx->runProcessor('topicfinder/refresh', [
 *     'dry_run'   => true,
 *     'min_score' => 20,
 * ], [
 *     'processors_path' => $corePath . 'processors/',
 * ]);
 *
 * // С архивацией старых тем:
 * $response = $modx->runProcessor('topicfinder/refresh', [
 *     'cleanup_old'  => true,
 *     'cleanup_days' => 14,
 * ], [
 *     'processors_path' => $corePath . 'processors/',
 * ]);
 * ```
 *
 * @package SpookyApp
 * @subpackage Processors\TopicFinder
 */
class Refresh extends Processor
{
    // ─── Метки источников (для статистики и логов) ───────────────
    private const SOURCE_LABELS = [
        // ── 3 новостных агрегатора ──────────────────────────────
        'realtime_news'    => 'RealTime News',
        'thenewsapi'       => 'TheNewsAPI',
        'newsdata'         => 'NewsData',
        // ── Прочие источники ────────────────────────────────────
        'reddit'           => 'Reddit',
        'tmdb_trends'      => 'TMDB Trends',
        'tmdb_upcoming'    => 'TMDB Upcoming',
        'rawg'        => 'RAWG (Games)',
        'github'      => 'GitHub',
        'mobileapi'   => 'MobileApi.dev',
        'flashlive'   => 'FlashLive Sports',
        'ibu'         => 'IBU Biathlon',
        'apifootball' => 'API-Football',
    ];

    // ─── Маппинг имён процессора → TopicFinderService ────────────
    private const SOURCE_NAME_MAP = [
        // ── 3 новостных агрегатора ──────────────────────────────
        'realtime_news'  => 'realtime_news',
        'thenewsapi'     => 'thenewsapi',
        'newsdata'       => 'newsdata',
        // ── Прочие источники ────────────────────────────────────
        'reddit'         => 'reddit',
        'tmdb_trends'    => 'tmdb_trends',
        'tmdb_upcoming'  => 'tmdb_upcoming',
        'rawg'           => 'games',
        'github'         => 'github',
        'mobileapi'      => 'devices',
        'flashlive'      => 'sports',
        'ibu'            => 'biathlon',
        'apifootball'    => 'football',
    ];

    // ─── Допустимые категории ────────────────────────────────────
    private const ALLOWED_CATEGORIES = [
        'IT', 'Gadgets', 'Games', 'Cinema', 'Sports',
        'Science', 'Other', 'General',
    ];

    /** Maps external API category words (lowercase) → internal ALLOWED_CATEGORIES */
    private const CATEGORY_ALIASES = [
        // IT / Technology
        'it'           => 'IT',
        'tech'         => 'IT',
        'technology'   => 'IT',
        'programming'  => 'IT',
        'software'     => 'IT',
        'coding'       => 'IT',
        // Gadgets / Devices
        'gadgets'      => 'Gadgets',
        'devices'      => 'Gadgets',
        'mobile'       => 'Gadgets',
        'hardware'     => 'Gadgets',
        // Games
        'games'        => 'Games',
        'gaming'       => 'Games',
        'esports'      => 'Games',
        // Cinema / Entertainment
        'cinema'        => 'Cinema',
        'entertainment' => 'Cinema',
        'movies'        => 'Cinema',
        'movie'         => 'Cinema',
        'film'          => 'Cinema',
        'tv'            => 'Cinema',
        'tv-shows'      => 'Cinema',
        'television'    => 'Cinema',
        'celebrities'   => 'Other',
        'person'        => 'Other',
        // Sports
        'sports'       => 'Sports',
        'sport'        => 'Sports',
        // Science
        'science'      => 'Science',
        'research'     => 'Science',
        'health'       => 'Science',
        // General
        'general'      => 'General',
        'world'        => 'General',
        'business'     => 'General',
        'finance'      => 'General',
        'economy'      => 'General',
        'food'         => 'General',
        'travel'       => 'General',
        'lifestyle'    => 'General',
        'tourism'      => 'General',
        'environment'  => 'General',
        'domestic'     => 'General',
        'news'         => 'General',
        // Other
        'other'        => 'Other',
    ];

    // ─── Лимиты ──────────────────────────────────────────────────
    private const MAX_TOPICS_LIMIT    = 200;
    private const DEFAULT_MAX_TOPICS  = 50;
    private const DEFAULT_MIN_SCORE   = 5.0;
    private const DEFAULT_CLEANUP_DAYS = 30;
    /** Map JS source key → internal fetch key → display label */
 private const SOURCE_MAP = [
        // News
        'realtime_news' => ['fetch' => 'realtime_news', 'label' => 'RealTime News'],
        'thenewsapi'    => ['fetch' => 'thenewsapi',    'label' => 'TheNewsAPI'],
        'newsdata'      => ['fetch' => 'newsdata',      'label' => 'NewsData'],
        // Social
        'reddit'        => ['fetch' => 'reddit',        'label' => 'Reddit'],
        // Entertainment
        'tmdb'          => ['fetch' => 'tmdb',          'label' => 'TMDB'],
        'games'         => ['fetch' => 'games',         'label' => 'RAWG Games'],
        // Tech
        'github'        => ['fetch' => 'github',        'label' => 'GitHub'],
        'devices'       => ['fetch' => 'devices',       'label' => 'Mobile Devices'],
        // Sports
        'sports'        => ['fetch' => 'sports',        'label' => 'FlashLive Sports'],
        'football'      => ['fetch' => 'football',      'label' => 'Football API'],
        'biathlon'      => ['fetch' => 'biathlon',      'label' => 'Biathlon IBU'],
    ];

    /** @var string */
    public $languageTopics = ['spookyapp:default'];

    /** @var float Время начала выполнения */
    private float $startTime;

    /**
     * Инициализация: autoload + xPDO-пакет.
     *
     * @return bool|string
     */
    public function initialize()
    {
        // External API calls can be slow — give PHP more time
        @set_time_limit(300);

        $this->startTime = microtime(true);

        $corePath = $this->modx->getOption(
            'spookyapp.core_path',
            null,
            $this->modx->getOption('core_path') . 'components/spookyapp/'
        );

        $autoload = $corePath . 'vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        // Регистрируем xPDO-пакет для SpookyAppTopic
        $this->modx->addPackage('SpookyApp\\Model', $corePath . 'src/Model/');

        return parent::initialize();
    }

    /**
     * Основная логика: fetch → normalize → score → save/update.
     *
     * @return array|string
     */
    public function process()
    {
        // ══════════════════════════════════════════════════════════
        // 1. Разбираем и валидируем параметры
        // ══════════════════════════════════════════════════════════
        $params = $this->parseAndValidateParams();

        if (empty($params['sources'])) {
            return $this->failure('No valid sources selected.');
        }

        // ══════════════════════════════════════════════════════════
        // 2. Получаем темы из API через TopicFinderService
        // ══════════════════════════════════════════════════════════
        try {
            $topicFinder = $this->createTopicFinderService();

            $result = $topicFinder->findTrendingTopics([
                'sources'        => $params['sources'],
                'max_topics'     => $params['max_topics'],
                'min_score'      => $params['min_score'],
                'categories'     => $params['categories'],
                'force_refresh'  => $params['force_refresh'],
                'source_options' => $params['source_options'],
            ]);
        } catch (Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[TopicFinder:Refresh] API fetch error: ' . $e->getMessage()
                . "\n" . $e->getTraceAsString()
            );

            return $this->failure(
                'Ошибка при получении тем из API: ' . $e->getMessage()
            );
        }

        if (!$result['success']) {
            $errorMsg = $result['error'] ?? 'Не удалось получить темы из API';

            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[TopicFinder:Refresh] API returned error: ' . $errorMsg
            );

            return $this->failure($errorMsg);
        }

        $topics = $result['topics'] ?? [];
        $fetchedCount = count($topics);

        if ($fetchedCount === 0) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[TopicFinder:Refresh] No topics returned from API'
            );

            return $this->success('Нет новых тем из выбранных источников', [
                'fetched'          => 0,
                'saved_new'        => 0,
                'updated'          => 0,
                'skipped'          => 0,
                'errors'           => 0,
                'archived'         => 0,
                'sources_queried'  => $params['sources'],
                'duration_sec'     => $this->getDuration(),
                'by_source'        => [],
                'by_category'      => [],
                'top_topics'       => [],
                'dry_run'          => $params['dry_run'],
            ]);
        }

        // ══════════════════════════════════════════════════════════
        // 3. Сохраняем в БД (если не dry_run)
        // ══════════════════════════════════════════════════════════
        $counters = [
            'saved_new' => 0,
            'updated'   => 0,
            'skipped'   => 0,
            'errors'    => 0,
        ];

        if (!$params['dry_run']) {
            $counters = $this->saveTopicsBatch($topics, $params['min_score']);
        }

        // ══════════════════════════════════════════════════════════
        // 4. Архивация старых тем (если cleanup_old=true)
        // ══════════════════════════════════════════════════════════
        $archivedCount = 0;

        if ($params['cleanup_old'] && !$params['dry_run']) {
            $archivedCount = $this->archiveOldTopics($params['cleanup_days']);
        }

        // ══════════════════════════════════════════════════════════
        // 5. Собираем статистику ответа
        // ══════════════════════════════════════════════════════════
        $bySource = $this->countByField($topics, 'source');
        $byCategory = $this->countByField($topics, 'category');
        $topTopics = $this->getTopTopics($topics, 10);
        $duration = $this->getDuration();

        // Преобразуем ключи source в человекочитаемые метки
        $bySourceLabeled = [];
        foreach ($bySource as $src => $cnt) {
            $label = self::SOURCE_LABELS[$src] ?? ucfirst($src);
            $bySourceLabeled[$label] = $cnt;
        }

        $message = sprintf(
            'Обновление завершено: получено %d тем, сохранено %d новых, обновлено %d',
            $fetchedCount,
            $counters['saved_new'],
            $counters['updated']
        );

        if ($params['dry_run']) {
            $message = sprintf(
                'Сухой прогон: получено %d тем (не сохранены в БД)',
                $fetchedCount
            );
        }

        if ($archivedCount > 0) {
            $message .= sprintf(', архивировано %d', $archivedCount);
        }

        if ($counters['errors'] > 0) {
            $message .= sprintf(', ошибок %d', $counters['errors']);
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[TopicFinder:Refresh] Done in {$duration}s. {$message}"
        );

        return $this->success($message, [
            'fetched'          => $fetchedCount,
            'saved_new'        => $counters['saved_new'],
            'updated'          => $counters['updated'],
            'skipped'          => $counters['skipped'],
            'errors'           => $counters['errors'],
            'archived'         => $archivedCount,
            'sources_queried'  => $params['sources'],
            'duration_sec'     => $duration,
            'by_source'        => $bySourceLabeled,
            'by_category'      => $byCategory,
            'top_topics'       => $topTopics,
            'dry_run'          => $params['dry_run'],
        ]);
    }

    // ═════════════════════════════════════════════════════════════
    // Парсинг и валидация параметров
    // ═════════════════════════════════════════════════════════════

    /**
     * Разобрать и провалидировать все входные параметры.
     *
     * @return array{
     *     sources: string[],
     *     categories: string[],
     *     min_score: float,
     *     max_topics: int,
     *     force_refresh: bool,
     *     dry_run: bool,
     *     cleanup_old: bool,
     *     cleanup_days: int
     * }
     */
    private function parseAndValidateParams(): array
    {
        // ── Sources ──────────────────────────────────────────────
        $sourcesStr = trim((string)$this->getProperty('sources', ''));
        $sources = [];

        if (!empty($sourcesStr)) {
            // JS может передавать JSON-строку (Ext.encode) или CSV
            $decoded = json_decode($sourcesStr, true);
            $raw = is_array($decoded)
                ? $decoded
                : array_map('trim', explode(',', $sourcesStr));

            $raw = array_map('mb_strtolower', $raw);
            $raw = array_filter($raw);

            // Валидируем каждый — только допустимые, и маппим для TopicFinderService
            foreach ($raw as $src) {
                if (isset(self::SOURCE_LABELS[$src])) {
                    $sources[] = self::SOURCE_NAME_MAP[$src] ?? $src;
                } else {
                    $this->modx->log(
                        modX::LOG_LEVEL_WARN,
                        "[TopicFinder:Refresh] Unknown source skipped: '{$src}'"
                    );
                }
            }
        }
        // Пустой массив = все источники (TopicFinderService решает сам)

        // ── Categories ───────────────────────────────────────────
        $categoriesStr = trim((string)$this->getProperty('categories', ''));
        $categories = [];

        if (!empty($categoriesStr)) {
            $raw = array_map('trim', explode(',', $categoriesStr));
            $raw = array_filter($raw);

            foreach ($raw as $cat) {
                $matched = $this->matchCategory($cat);
                if ($matched !== null) {
                    $categories[] = $matched;
                } else {
                    $this->modx->log(
                        modX::LOG_LEVEL_WARN,
                        "[TopicFinder:Refresh] Unknown category skipped: '{$cat}'"
                    );
                }
            }

            $categories = array_unique($categories);
        }

        // ── Числовые параметры ───────────────────────────────────
        $minScore = max(0.0, (float)$this->getProperty('min_score', self::DEFAULT_MIN_SCORE));

        $maxTopics = (int)$this->getProperty('max_topics', self::DEFAULT_MAX_TOPICS);
        $maxTopics = max(1, min(self::MAX_TOPICS_LIMIT, $maxTopics));

        // ── Булевые параметры ────────────────────────────────────
        $forceRefresh = $this->toBool($this->getProperty('force_refresh', false));
        $dryRun       = $this->toBool($this->getProperty('dry_run', false));
        $cleanupOld   = $this->toBool($this->getProperty('cleanup_old', false));

        $cleanupDays = (int)$this->getProperty('cleanup_days', self::DEFAULT_CLEANUP_DAYS);
        $cleanupDays = max(1, min(365, $cleanupDays));

        // ── Per-source options (JSON) ─────────────────────────────
        // Expected format: {"news":{"categories":["technology"],"keyword":"AI"},"reddit":{"subreddits":["programming"]}}
        $sourceOptionsRaw = trim((string)$this->getProperty('source_options', ''));
        $sourceOptions = [];
        if (!empty($sourceOptionsRaw)) {
            $decoded = json_decode($sourceOptionsRaw, true);
            if (is_array($decoded)) {
                $sourceOptions = $decoded;
            }
        }

        return [
            'sources'        => $sources,
            'categories'     => $categories,
            'min_score'      => $minScore,
            'max_topics'     => $maxTopics,
            'force_refresh'  => $forceRefresh,
            'dry_run'        => $dryRun,
            'cleanup_old'    => $cleanupOld,
            'cleanup_days'   => $cleanupDays,
            'source_options' => $sourceOptions,
        ];
    }

    /**
     * Сопоставить название категории (case-insensitive).
     *
     * @param string $input
     * @return string|null Каноническое имя или null
     */
    private function matchCategory(string $input): ?string
    {
        // Handle comma-separated values (e.g. 'tech,sports' from TheNewsAPI) — use first token
        $first = mb_strtolower(trim(explode(',', $input)[0]));
        if (empty($first)) {
            return null;
        }

        // Exact match against allowed categories (case-insensitive)
        foreach (self::ALLOWED_CATEGORIES as $allowed) {
            if (mb_strtolower($allowed) === $first) {
                return $allowed;
            }
        }

        // Alias map (API-specific words → internal categories)
        return self::CATEGORY_ALIASES[$first] ?? null;
    }

    /**
     * Преобразовать значение в bool (поддержка '1', 'true', 'yes', true).
     *
     * @param mixed $value
     * @return bool
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(mb_strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool)$value;
    }

    // ═════════════════════════════════════════════════════════════
    // Сохранение тем в БД (batch)
    // ═════════════════════════════════════════════════════════════

    /**
     * Пакетное сохранение тем в таблицу spookyapp_topics.
     *
     * Логика:
     * - Если topic_id уже есть → обновляем score (если вырос), metadata (merge), updated_at
     * - Если topic_id нет → создаём новую запись со status = STATUS_NEW
     * - Если score < minScore → пропускаем
     *
     * @param array $topics   Массив тем из TopicFinderService
     * @param float $minScore Минимальный score для сохранения
     * @return array{saved_new: int, updated: int, skipped: int, errors: int}
     */
    private function saveTopicsBatch(array $topics, float $minScore): array
    {
        $counters = [
            'saved_new' => 0,
            'updated'   => 0,
            'skipped'   => 0,
            'errors'    => 0,
        ];

        $now = date('Y-m-d H:i:s');

        // ─── Предварительно загружаем существующие topic_id ──────
        // Это значительно быстрее, чем делать getObject() на каждую тему
        $existingTopicIds = $this->loadExistingTopicIds($topics);

        foreach ($topics as $topicData) {
            try {
                $topicId = (string)($topicData['id'] ?? '');
                $score   = (float)($topicData['score'] ?? 0);

                // Пропускаем без ID
                if (empty($topicId)) {
                    $counters['skipped']++;
                    continue;
                }

                // Пропускаем ниже порога
                if ($score < $minScore) {
                    $counters['skipped']++;
                    continue;
                }

                // ─── Обновление существующей записи ──────────────
                if (isset($existingTopicIds[$topicId])) {
                    $result = $this->updateExistingTopic(
                        $existingTopicIds[$topicId],
                        $topicData,
                        $now
                    );

                    if ($result) {
                        $counters['updated']++;
                    } else {
                        $counters['errors']++;
                    }

                    continue;
                }

                // ─── Создание новой записи ───────────────────────
                $result = $this->createNewTopic($topicData, $now);

                if ($result) {
                    $counters['saved_new']++;
                } else {
                    $counters['errors']++;
                }

            } catch (Throwable $e) {
                $counters['errors']++;
                $this->modx->log(
                    modX::LOG_LEVEL_ERROR,
                    '[TopicFinder:Refresh] Save error for topic "'
                    . ($topicData['id'] ?? 'unknown') . '": ' . $e->getMessage()
                );
            }
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            sprintf(
                '[TopicFinder:Refresh] Batch save: new=%d, updated=%d, skipped=%d, errors=%d',
                $counters['saved_new'],
                $counters['updated'],
                $counters['skipped'],
                $counters['errors']
            )
        );

        return $counters;
    }

    /**
     * Загрузить существующие topic_id из БД для быстрого lookup.
     *
     * @param array $topics Массив тем (нужны только поля 'id')
     * @return array<string, array{id: int, score: float, metadata: string}> topic_id => данные
     */
    private function loadExistingTopicIds(array $topics): array
    {
        if (empty($topics)) {
            return [];
        }

        // Собираем все topic_id
        $topicIds = [];
        foreach ($topics as $t) {
            $tid = (string)($t['id'] ?? '');
            if (!empty($tid)) {
                $topicIds[] = $tid;
            }
        }

        if (empty($topicIds)) {
            return [];
        }

        // Прямой SQL-запрос (безопаснее, чем xPDO при большом наборе ID)
        $tableName = $this->modx->getTableName(SpookyAppTopic::class);
        $placeholders = implode(',', array_fill(0, count($topicIds), '?'));
        $sql = "SELECT id, topic_id, score, metadata FROM {$tableName} WHERE topic_id IN ({$placeholders})";

        try {
            $stmt = $this->modx->prepare($sql);
            if (!$stmt) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[TopicFinder:Refresh] loadExistingTopicIds prepare failed');
                return [];
            }

            $stmt->execute(array_values($topicIds));

            $existing = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $existing[$row['topic_id']] = [
                    'id'       => (int)$row['id'],
                    'score'    => (float)$row['score'],
                    'metadata' => (string)$row['metadata'],
                ];
            }

            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[TopicFinder:Refresh] loadExistingTopicIds: found ' . count($existing) . ' of ' . count($topicIds)
            );

            return $existing;
        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[TopicFinder:Refresh] loadExistingTopicIds error: ' . $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Обновить существующую тему в БД.
     *
     * Обновляем:
     * - score (только если новый выше)
     * - metadata (merge)
     * - updated_at
     *
     * @param array  $existingData Данные из loadExistingTopicIds(): {id, score, metadata}
     * @param array  $topicData    Свежие данные из API
     * @param string $now          Текущее время (Y-m-d H:i:s)
     * @return bool
     */
    private function updateExistingTopic(array $existingData, array $topicData, string $now): bool
    {
        /** @var SpookyAppTopic|null $topic */
        $topic = $this->modx->getObject(SpookyAppTopic::class, $existingData['id']);

        if (!$topic) {
            return false;
        }

        $changed = false;

        // Score — обновляем только если вырос
        $newScore = round((float)($topicData['score'] ?? 0), 2);
        $oldScore = (float)$topic->get('score');

        if ($newScore > $oldScore) {
            $topic->set('score', $newScore);
            $changed = true;
        }

        // Metadata — merge (новые данные перезаписывают старые ключи)
        $oldMeta = $topic->getMetadataArray();
        $newMeta = (array)($topicData['metadata'] ?? []);

        if (!empty($newMeta)) {
            // Сохраняем историю score обновлений
            $scoreHistory = $oldMeta['_score_history'] ?? [];
            if ($newScore !== $oldScore) {
                $scoreHistory[] = [
                    'score' => $newScore,
                    'date'  => $now,
                ];
                // Ограничиваем историю 20 записями
                $scoreHistory = array_slice($scoreHistory, -20);
            }

            $merged = array_merge($oldMeta, $newMeta);
            $merged['_score_history'] = $scoreHistory;
            $merged['_last_refresh'] = $now;
            $merged['_refresh_count'] = ($oldMeta['_refresh_count'] ?? 0) + 1;

            $topic->set('metadata', json_encode($merged, JSON_UNESCAPED_UNICODE));
            $changed = true;
        }

        // Title / description — обновляем если были пустыми
        $fieldsToFillIfEmpty = [
            'title'       => 'title',
            'description' => 'description',
            'url'         => 'url',
        ];

        foreach ($fieldsToFillIfEmpty as $dbField => $apiField) {
            if (empty($topic->get($dbField)) && !empty($topicData[$apiField])) {
                $topic->set($dbField, (string)$topicData[$apiField]);
                $changed = true;
            }
        }

        if ($changed) {
            $topic->set('updated_at', $now);
            return $topic->save();
        }

        return true; // Ничего не изменилось — это не ошибка
    }

    /**
     * Создать новую тему в БД.
     *
     * @param array  $topicData Данные из API
     * @param string $now       Текущее время (Y-m-d H:i:s)
     * @return bool
     */
    private function createNewTopic(array $topicData, string $now): bool
    {
        // Подготовка metadata с системными полями
        $metadata = (array)($topicData['metadata'] ?? []);
        $metadata['_created_by_refresh'] = true;
        $metadata['_first_seen'] = $now;
        $metadata['_refresh_count'] = 1;

        /** @var SpookyAppTopic $topic */
        $topic = $this->modx->newObject(SpookyAppTopic::class);
        $topic->fromArray([
            'topic_id'     => (string)($topicData['id'] ?? ''),
            'source'       => (string)($topicData['source'] ?? ''),
            'title'        => (string)($topicData['title'] ?? ''),
            'url'          => (string)($topicData['url'] ?? ''),
            'description'  => (string)($topicData['description'] ?? ''),
            'category'     => $this->normalizeCategory((string)($topicData['category'] ?? 'Other')),
            'published_at' => $this->formatDateForDb((string)($topicData['published_at'] ?? '')),
            'score'        => round((float)($topicData['score'] ?? 0), 2),
            'metadata'     => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'status'       => SpookyAppTopic::STATUS_NEW,
            'assigned_to'  => 0,
            'notes'        => '',
            'created_at'   => $now,
            'updated_at'   => null,
        ]);

        return $topic->save();
    }

    // ═════════════════════════════════════════════════════════════
    // Архивация старых тем
    // ═════════════════════════════════════════════════════════════

    /**
     * Архивировать старые необработанные темы.
     *
     * Архивирует (status → STATUS_ARCHIVED) все темы, которые:
     * - имеют status = STATUS_NEW (0) — необработанные
     * - были созданы более $days дней назад
     *
     * Это предотвращает бесконечное разрастание списка «новых» тем.
     *
     * @param int $days Количество дней
     * @return int Количество архивированных записей
     */
    private function archiveOldTopics(int $days): int
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $now = date('Y-m-d H:i:s');

        $tableName = $this->modx->getTableName(SpookyAppTopic::class);

        $sql = "UPDATE {$tableName} 
                SET status = :newStatus, updated_at = :now, 
                    notes = CONCAT(IFNULL(notes, ''), :note) 
                WHERE status = :oldStatus 
                  AND created_at < :cutoff";

        $stmt = $this->modx->prepare($sql);

        if (!$stmt) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[TopicFinder:Refresh] Failed to prepare archive SQL'
            );
            return 0;
        }

        $note = "\n[auto-archived {$now}, older than {$days} days]";

        $executed = $stmt->execute([
            ':newStatus' => SpookyAppTopic::STATUS_ARCHIVED,
            ':now'       => $now,
            ':note'      => $note,
            ':oldStatus' => SpookyAppTopic::STATUS_NEW,
            ':cutoff'    => $cutoffDate,
        ]);

        if (!$executed) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[TopicFinder:Refresh] Archive SQL execution failed'
            );
            return 0;
        }

        $count = $stmt->rowCount();

        if ($count > 0) {
            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                "[TopicFinder:Refresh] Archived {$count} topics older than {$days} days"
            );
        }

        return $count;
    }

    // ═════════════════════════════════════════════════════════════
    // Создание TopicFinderService
    // ═════════════════════════════════════════════════════════════

    /**
     * Создать экземпляр TopicFinderService со всеми зависимостями.
     *
     * Инициализирует CacheService и все API-сервисы, передаёт их
     * в конструктор TopicFinderService.
     *
     * @return TopicFinderService
     */
    private function createTopicFinderService(): TopicFinderService
    {
        $cache = new CacheService($this->modx);

          $newsService     = new NewsAPIService($this->modx, $cache);
        $redditService   = new RedditService($this->modx, $cache);
        $tmdbService     = new TMDBService($this->modx, $cache);
        $gamesService    = new GamesAPIService($this->modx, $cache);
        $githubService   = new GitHubService($this->modx, $cache);
        $devicesService  = new MobileDevicesAPIService($this->modx, $cache);
        $sportsService   = new SportsAPIService($this->modx, $cache);
        $biathlonService = new BiathlonIBUService($this->modx, $cache);
        $footballService = new FootballAPIService($this->modx, $cache);
        $scoringService  = new TopicScoringService($this->modx);

        return new TopicFinderService(
            $this->modx,
            $newsService,
            $redditService,
            null, // YandexAPIService
            $tmdbService,
            $gamesService,
            $githubService,
            $devicesService,
            $sportsService,
            $footballService,
            $biathlonService,
            $scoringService,
            $cache
        );
    }

    // ═════════════════════════════════════════════════════════════
    // Утилиты для статистики ответа
    // ═════════════════════════════════════════════════════════════

    /**
     * Подсчитать количество тем по полю.
     *
     * @param array  $topics Массив тем
     * @param string $field  Имя поля ('source', 'category')
     * @return array<string, int>
     */
    private function countByField(array $topics, string $field): array
    {
        $counts = [];

        foreach ($topics as $t) {
            $value = (string)($t[$field] ?? 'unknown');
            if (!isset($counts[$value])) {
                $counts[$value] = 0;
            }
            $counts[$value]++;
        }

        arsort($counts);
        return $counts;
    }

    /**
     * Получить top-N тем по score (для превью в ответе).
     *
     * @param array $topics    Массив тем
     * @param int   $topCount  Количество (default: 10)
     * @return array
     */
    private function getTopTopics(array $topics, int $topCount = 10): array
    {
        // Сортируем по score DESC
        usort($topics, static function ($a, $b) {
            return ((float)($b['score'] ?? 0)) <=> ((float)($a['score'] ?? 0));
        });

        $top = array_slice($topics, 0, $topCount);
        $result = [];

        foreach ($top as $t) {
            $source = (string)($t['source'] ?? '');
            $result[] = [
                'topic_id'     => (string)($t['id'] ?? ''),
                'title'        => (string)($t['title'] ?? ''),
                'score'        => round((float)($t['score'] ?? 0), 2),
                'source'       => $source,
                'source_label' => self::SOURCE_LABELS[$source] ?? ucfirst($source),
                'category'     => (string)($t['category'] ?? 'Other'),
            ];
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // Утилиты форматирования
    // ═════════════════════════════════════════════════════════════

    /**
     * Нормализовать название категории.
     *
     * @param string $category
     * @return string
     */
    private function normalizeCategory(string $category): string
    {
        $matched = $this->matchCategory($category);
        return $matched ?? 'Other';
    }

    /**
     * Привести дату к формату MySQL datetime.
     *
     * @param string $date ISO 8601, unix timestamp или произвольный формат
     * @return string|null 'Y-m-d H:i:s' или null
     */
    private function formatDateForDb(string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        // Числовой timestamp
        if (is_numeric($date)) {
            return date('Y-m-d H:i:s', (int)$date);
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Получить время выполнения процессора в секундах.
     *
     * @return float Округлено до 2 знаков
     */
    private function getDuration(): float
    {
        return round(microtime(true) - $this->startTime, 2);
    }
}

return Refresh::class;
