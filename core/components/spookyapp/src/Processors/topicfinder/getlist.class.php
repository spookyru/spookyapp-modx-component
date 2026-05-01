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
use PDO;
use Throwable;

/**
 * Процессор получения списка тем для TopicFinder.
 *
 * Поддерживает два режима работы:
 * 1. **db** — чтение из таблицы spookyapp_topics (кешированные темы)
 * 2. **live** — прямой вызов TopicFinderService::findTrendingTopics()
 *    с последующим сохранением в БД
 *
 * Параметры запроса (все опциональные):
 * @property string $mode           Режим: 'db' | 'live' (default: 'db')
 * @property string $category       Фильтр по категории: 'IT', 'Games', 'Cinema', 'Sports', ...
 * @property string $source         Фильтр по источнику: 'newsapi', 'tmdb', 'rawg', ...
 * @property int    $status         Фильтр по статусу: 0-5 (null = все)
 * @property float  $min_score      Минимальный score (default: 0)
 * @property string $search         Поиск по title и description
 * @property string $sort           Поле сортировки (default: 'score')
 * @property string $dir            Направление: 'ASC' | 'DESC' (default: 'DESC')
 * @property int    $limit          Кол-во записей (default: 20, max: 100)
 * @property int    $start          Offset (default: 0)
 * @property bool   $force_refresh  Принудительно обновить из API (только для mode=live)
 * @property string $sources        CSV список источников для live-режима (пусто = все)
 * @property string $date_from      Фильтр: дата от (YYYY-MM-DD)
 * @property string $date_to        Фильтр: дата до (YYYY-MM-DD)
 *
 * Ответ:
 * ```json
 * {
 *   "success": true,
 *   "total": 150,
 *   "results": [
 *     {
 *       "id": 42,
 *       "topic_id": "tmdb_movie_12345",
 *       "source": "tmdb",
 *       "source_label": "TMDB",
 *       "title": "...",
 *       "url": "...",
 *       "description": "...",
 *       "category": "Cinema",
 *       "published_at": "2025-01-15T12:00:00Z",
 *       "score": 85.50,
 *       "metadata": {...},
 *       "status": 0,
 *       "status_label": "Новая",
 *       "status_css": "badge-secondary",
 *       "assigned_to": 0,
 *       "notes": "",
 *       "created_at": "2025-01-15 13:00:00",
 *       "updated_at": null,
 *       "is_major": false,
 *       "cross_source_count": 1
 *     }
 *   ],
 *   "stats": { ... }
 * }
 * ```
 *
 * @package SpookyApp
 * @subpackage Processors\TopicFinder
 */
class GetList extends Processor
{
    // ─── Метки источников для UI ─────────────────────────────────
    private const SOURCE_LABELS = [
        'newsapi'     => 'NewsAPI',
        'reddit'      => 'Reddit',
        'tmdb'        => 'TMDB',
        'rawg'        => 'RAWG (Games)',
        'github'      => 'GitHub',
        'mobileapi'   => 'MobileApi.dev',
        'flashlive'   => 'FlashLive Sports',
        'ibu'         => 'IBU Biathlon',
        'apifootball'  => 'API-Football',
    ];

    // ─── Допустимые поля сортировки ──────────────────────────────
    private const ALLOWED_SORT_FIELDS = [
        'id', 'topic_id', 'source', 'title', 'category',
        'published_at', 'score', 'status', 'created_at', 'updated_at',
    ];

    // ─── Допустимые категории ────────────────────────────────────
    private const ALLOWED_CATEGORIES = [
        'IT', 'Gadgets', 'Games', 'Cinema', 'Sports',
        'Science', 'Other', 'General',
    ];

    // ─── Лимиты ──────────────────────────────────────────────────
    private const MAX_LIMIT = 100;
    private const DEFAULT_LIMIT = 20;

    /** @var string */
    public $languageTopics = ['spookyapp:default'];

    /**
     * Инициализация процессора.
     *
     * Проверяем наличие таблицы и подключаем autoload.
     *
     * @return bool|string
     */
    public function initialize()
    {
        // Подключаем autoload компонента
        $corePath = $this->modx->getOption(
            'spookyapp.core_path',
            null,
            $this->modx->getOption('core_path') . 'components/spookyapp/'
        );

        $autoload = $corePath . 'vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        // Регистрируем пакет xPDO для модели SpookyAppTopic
        $this->modx->addPackage('SpookyApp\\Model', $corePath . 'src/Model/');

        return parent::initialize();
    }

    /**
     * Основная логика процессора.
     *
     * @return array|string
     */
    public function process()
    {
        $mode = $this->getProperty('mode', 'db');

        switch ($mode) {
            case 'live':
                return $this->processLive();
            case 'db':
            default:
                return $this->processFromDb();
        }
    }

    // ═════════════════════════════════════════════════════════════
    // Режим DB: чтение из таблицы spookyapp_topics
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить темы из базы данных.
     *
     * @return array
     */
    private function processFromDb(): array
    {
        // ── Параметры ───────────────────────────────────────────
        $category   = $this->sanitizeCategory($this->getProperty('category', ''));
        $source     = $this->sanitizeSource($this->getProperty('source', ''));
        $status     = $this->getProperty('status');
        $minScore   = max(0.0, (float)$this->getProperty('min_score', 0));
        $search     = trim((string)$this->getProperty('search', ''));
        $sortField  = $this->sanitizeSortField($this->getProperty('sort', 'score'));
        $sortDir    = $this->sanitizeSortDirection($this->getProperty('dir', 'DESC'));
        $limit      = min(self::MAX_LIMIT, max(1, (int)$this->getProperty('limit', self::DEFAULT_LIMIT)));
        $start      = max(0, (int)$this->getProperty('start', 0));
        $dateFrom   = $this->sanitizeDate($this->getProperty('date_from', ''));
        $dateTo     = $this->sanitizeDate($this->getProperty('date_to', ''));

        // ── Построение запроса через xPDO ───────────────────────
        $c = $this->modx->newQuery(SpookyAppTopic::class);
        $cCount = $this->modx->newQuery(SpookyAppTopic::class);

        // Условия
        $where = [];

        if ($minScore > 0) {
            $where['score:>='] = $minScore;
        }

        if (!empty($category)) {
            $where['category'] = $category;
        }

        if (!empty($source)) {
            $where['source'] = $source;
        }

        if ($status !== null && $status !== '') {
            $statusInt = (int)$status;
            if ($statusInt >= 0 && $statusInt <= 5) {
                $where['status'] = $statusInt;
            }
        }

        if (!empty($dateFrom)) {
            $where['published_at:>='] = $dateFrom . ' 00:00:00';
        }

        if (!empty($dateTo)) {
            $where['published_at:<='] = $dateTo . ' 23:59:59';
        }

        // Поиск по заголовку и описанию
        if (!empty($search)) {
            $searchEscaped = '%' . $search . '%';
            $where[] = [
                'title:LIKE'       => $searchEscaped,
                'OR:description:LIKE' => $searchEscaped,
            ];
        }

        if (!empty($where)) {
            $c->where($where);
            $cCount->where($where);
        }

        // ── Подсчёт общего количества ───────────────────────────
        $total = $this->modx->getCount(SpookyAppTopic::class, $cCount);

        // ── Сортировка ──────────────────────────────────────────
        $c->sortby($sortField, $sortDir);

        // Вторичная сортировка для стабильности
        if ($sortField !== 'published_at') {
            $c->sortby('published_at', 'DESC');
        }
        if ($sortField !== 'id') {
            $c->sortby('id', 'DESC');
        }

        // ── Пагинация ──────────────────────────────────────────
        $c->limit($limit, $start);

        // ── Выполнение запроса ──────────────────────────────────
        /** @var SpookyAppTopic[] $collection */
        $collection = $this->modx->getIterator(SpookyAppTopic::class, $c);

        $results = [];
        foreach ($collection as $topic) {
            $results[] = $this->prepareRow($topic);
        }

        // ── Статистика ─────────────────────────────────────────
        $stats = $this->getDbStats();

        return $this->formatOutputArray($results, (int)$total, $stats);
    }

    /**
     * Подготовить строку для вывода из xPDO объекта.
     *
     * @param SpookyAppTopic $topic Объект темы
     * @return array
     */
    private function prepareRow(SpookyAppTopic $topic): array
    {
        $source = $topic->get('source');
        $rawMeta = $topic->get('metadata');
        $metadata = is_array($rawMeta) ? $rawMeta : (is_string($rawMeta) ? (json_decode($rawMeta, true) ?: []) : []);

        return [
            'id'                 => (int)$topic->get('id'),
            'topic_id'           => (string)$topic->get('topic_id'),
            'source'             => (string)$source,
            'source_label'       => self::SOURCE_LABELS[$source] ?? ucfirst($source),
            'title'              => (string)$topic->get('title'),
            'url'                => (string)$topic->get('url'),
            'description'        => (string)$topic->get('description'),
            'description_short'  => $this->truncate((string)$topic->get('description'), 200),
            'category'           => (string)$topic->get('category'),
            'published_at'       => (string)$topic->get('published_at'),
            'published_at_human' => $this->humanDate((string)$topic->get('published_at')),
            'score'              => round((float)$topic->get('score'), 2),
            'metadata'           => $metadata,
            'status'             => (int)$topic->get('status'),
            'status_label'       => $topic->getStatusLabel(),
            'status_css'         => $topic->getStatusCss(),
            'assigned_to'        => (int)$topic->get('assigned_to'),
            'notes'              => (string)$topic->get('notes'),
            'created_at'         => (string)$topic->get('created_at'),
            'updated_at'         => (string)$topic->get('updated_at'),
            'is_major'           => $topic->isMajor(),
            'cross_source_count' => $topic->getCrossSourceCount(),
        ];
    }

    /**
     * Получить статистику из БД.
     *
     * @return array
     */
    private function getDbStats(): array
    {
        $tableName = $this->modx->getTableName(SpookyAppTopic::class);

        $stats = [
            'total_in_db'   => 0,
            'by_status'     => [],
            'by_source'     => [],
            'by_category'   => [],
            'avg_score'     => 0.0,
            'max_score'     => 0.0,
        ];

        try {
            // Общее количество
            $stats['total_in_db'] = (int)$this->modx->getCount(SpookyAppTopic::class);

            // По статусам
            $sql = "SELECT status, COUNT(*) as cnt FROM {$tableName} GROUP BY status ORDER BY status";
            $stmt = $this->modx->prepare($sql);
            if ($stmt && $stmt->execute()) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $statusInt = (int)$row['status'];
                    $label = SpookyAppTopic::STATUS_LABELS[$statusInt] ?? 'Unknown';
                    $stats['by_status'][$label] = (int)$row['cnt'];
                }
            }

            // По источникам
            $sql = "SELECT source, COUNT(*) as cnt FROM {$tableName} GROUP BY source ORDER BY cnt DESC";
            $stmt = $this->modx->prepare($sql);
            if ($stmt && $stmt->execute()) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $label = self::SOURCE_LABELS[$row['source']] ?? $row['source'];
                    $stats['by_source'][$label] = (int)$row['cnt'];
                }
            }

            // По категориям
            $sql = "SELECT category, COUNT(*) as cnt FROM {$tableName} GROUP BY category ORDER BY cnt DESC";
            $stmt = $this->modx->prepare($sql);
            if ($stmt && $stmt->execute()) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $stats['by_category'][$row['category']] = (int)$row['cnt'];
                }
            }

            // Средний и максимальный score
            $sql = "SELECT AVG(score) as avg_score, MAX(score) as max_score FROM {$tableName}";
            $stmt = $this->modx->prepare($sql);
            if ($stmt && $stmt->execute()) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $stats['avg_score'] = round((float)($row['avg_score'] ?? 0), 2);
                $stats['max_score'] = round((float)($row['max_score'] ?? 0), 2);
            }
        } catch (Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[TopicFinder:GetList] Stats error: ' . $e->getMessage()
            );
        }

        return $stats;
    }

    // ═════════════════════════════════════════════════════════════
    // Режим LIVE: прямой вызов TopicFinderService
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить темы напрямую из API и сохранить в БД.
     *
     * @return array
     */
    private function processLive(): array
    {
        $forceRefresh = (bool)$this->getProperty('force_refresh', false);
        $sourcesStr   = trim((string)$this->getProperty('sources', ''));
        $category     = $this->sanitizeCategory($this->getProperty('category', ''));
        $maxTopics    = min(self::MAX_LIMIT, max(1, (int)$this->getProperty('limit', self::DEFAULT_LIMIT)));
        $minScore     = max(0.0, (float)$this->getProperty('min_score', 5.0));

        $sources = [];
        if (!empty($sourcesStr)) {
            $sources = array_map('trim', explode(',', $sourcesStr));
            $sources = array_filter($sources);
        }

        $categories = [];
        if (!empty($category)) {
            $categories[] = $category;
        }

        try {
            $topicFinder = $this->createTopicFinderService();

            $result = $topicFinder->findTrendingTopics([
                'sources'       => $sources,
                'max_topics'    => $maxTopics,
                'min_score'     => $minScore,
                'categories'    => $categories,
                'force_refresh' => $forceRefresh,
            ]);
        } catch (Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[TopicFinder:GetList] Live mode error: ' . $e->getMessage()
            );

            return $this->failure(
                'Ошибка при получении тем из API: ' . $e->getMessage()
            );
        }

        if (!$result['success']) {
            return $this->failure(
                $result['error'] ?? 'Не удалось получить темы из API'
            );
        }

        // ── Сохраняем в БД ──────────────────────────────────────
        $savedCount  = 0;
        $updatedCount = 0;
        $topics = $result['topics'] ?? [];

        foreach ($topics as $topicData) {
            [$saved, $updated] = $this->saveTopic($topicData);
            $savedCount += $saved;
            $updatedCount += $updated;
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[TopicFinder:GetList] Live: saved={$savedCount}, updated={$updatedCount}"
            . ' topics to DB'
        );

        // ── Формируем ответ ─────────────────────────────────────
        $outputTopics = [];
        foreach ($topics as $topicData) {
            $outputTopics[] = $this->prepareRawRow($topicData);
        }

        $stats = $result['stats'] ?? [];
        $stats['saved_to_db'] = $savedCount;
        $stats['updated_in_db'] = $updatedCount;

        return $this->formatOutputArray($outputTopics, count($outputTopics), $stats);
    }

    /**
     * Сохранить тему в БД (insert or update).
     *
     * Если тема с таким topic_id уже есть — обновляем score/metadata/updated_at.
     * Если нет — создаём новую запись.
     *
     * @param array $topicData Данные темы из TopicFinderService
     * @return array{int, int} [saved, updated] (0 или 1 каждый)
     */
    private function saveTopic(array $topicData): array
    {
        $topicId = (string)($topicData['id'] ?? '');
        if (empty($topicId)) {
            return [0, 0];
        }

        $now = date('Y-m-d H:i:s');

        // Проверяем, существует ли уже
        /** @var SpookyAppTopic|null $existing */
        $existing = $this->modx->getObject(SpookyAppTopic::class, ['topic_id' => $topicId]);

        if ($existing) {
            // Обновляем score и metadata, если score вырос
            $newScore = (float)($topicData['score'] ?? 0);
            $oldScore = (float)$existing->get('score');

            if ($newScore > $oldScore) {
                $existing->set('score', $newScore);
            }

            // Обновляем metadata (merge)
            $rawOldMeta = $existing->get('metadata');
            $oldMeta = is_array($rawOldMeta) ? $rawOldMeta : (is_string($rawOldMeta) ? (json_decode($rawOldMeta, true) ?: []) : []);
            $newMeta = (array)($topicData['metadata'] ?? []);
            $merged = array_merge($oldMeta, $newMeta);
            $existing->set('metadata', json_encode($merged, JSON_UNESCAPED_UNICODE));

            $existing->set('updated_at', $now);
            $existing->save();

            return [0, 1];
        }

        // Создаём новую запись
        /** @var SpookyAppTopic $topic */
        $topic = $this->modx->newObject(SpookyAppTopic::class);
        $topic->fromArray([
            'topic_id'     => $topicId,
            'source'       => (string)($topicData['source'] ?? ''),
            'title'        => (string)($topicData['title'] ?? ''),
            'url'          => (string)($topicData['url'] ?? ''),
            'description'  => (string)($topicData['description'] ?? ''),
            'category'     => (string)($topicData['category'] ?? 'Other'),
            'published_at' => $this->formatDateForDb((string)($topicData['published_at'] ?? '')),
            'score'        => round((float)($topicData['score'] ?? 0), 2),
            'metadata'     => json_encode((array)($topicData['metadata'] ?? []), JSON_UNESCAPED_UNICODE),
            'status'       => SpookyAppTopic::STATUS_NEW,
            'assigned_to'  => 0,
            'notes'        => '',
            'created_at'   => $now,
            'updated_at'   => null,
        ]);

        if ($topic->save()) {
            return [1, 0];
        }

        $this->modx->log(
            modX::LOG_LEVEL_ERROR,
            "[TopicFinder:GetList] Failed to save topic: {$topicId}"
        );

        return [0, 0];
    }

    /**
     * Подготовить строку из «сырых» данных TopicFinderService (без xPDO объекта).
     *
     * @param array $topicData Данные темы
     * @return array
     */
    private function prepareRawRow(array $topicData): array
    {
        $source = (string)($topicData['source'] ?? '');
        $metadata = (array)($topicData['metadata'] ?? []);
        $description = (string)($topicData['description'] ?? '');

        return [
            'id'                 => null, // Ещё не сохранён (или уже, но мы не знаем db-id)
            'topic_id'           => (string)($topicData['id'] ?? ''),
            'source'             => $source,
            'source_label'       => self::SOURCE_LABELS[$source] ?? ucfirst($source),
            'title'              => (string)($topicData['title'] ?? ''),
            'url'                => (string)($topicData['url'] ?? ''),
            'description'        => $description,
            'description_short'  => $this->truncate($description, 200),
            'category'           => (string)($topicData['category'] ?? 'Other'),
            'published_at'       => (string)($topicData['published_at'] ?? ''),
            'published_at_human' => $this->humanDate((string)($topicData['published_at'] ?? '')),
            'score'              => round((float)($topicData['score'] ?? 0), 2),
            'metadata'           => $metadata,
            'status'             => SpookyAppTopic::STATUS_NEW,
            'status_label'       => SpookyAppTopic::STATUS_LABELS[SpookyAppTopic::STATUS_NEW],
            'status_css'         => SpookyAppTopic::STATUS_CSS[SpookyAppTopic::STATUS_NEW],
            'assigned_to'        => 0,
            'notes'              => '',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => null,
            'is_major'           => !empty($metadata['is_major']) || (float)($topicData['score'] ?? 0) >= 50.0,
            'cross_source_count' => (int)($metadata['cross_source_count'] ?? 1),
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // Создание TopicFinderService
    // ═════════════════════════════════════════════════════════════

    /**
     * Создать экземпляр TopicFinderService со всеми зависимостями.
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
    // Формирование ответа
    // ═════════════════════════════════════════════════════════════

    /**
     * Формирование массива ответа.
     *
     * @param array $results Массив тем
     * @param int   $total   Общее количество
     * @param array $stats   Статистика
     * @return array
     */
    private function formatOutputArray(array $results, int $total, array $stats = []): array
    {
        @session_write_close();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        die($this->modx->toJSON([
            'success' => true,
            'total'   => $total,
            'results' => $results,
            'stats'   => $stats,
        ]));
    }

    // ═════════════════════════════════════════════════════════════
    // Валидация и санитизация
    // ═════════════════════════════════════════════════════════════

    /**
     * Санитизировать имя категории.
     *
     * @param string $category
     * @return string Пустая строка если невалидная
     */
    private function sanitizeCategory(string $category): string
    {
        $category = trim($category);
        if (empty($category)) {
            return '';
        }

        // Case-insensitive match
        foreach (self::ALLOWED_CATEGORIES as $allowed) {
            if (mb_strtolower($category) === mb_strtolower($allowed)) {
                return $allowed;
            }
        }

        // Если не в списке — логируем и пропускаем
        $this->modx->log(
            modX::LOG_LEVEL_WARN,
            "[TopicFinder:GetList] Unknown category: '{$category}'"
        );

        return '';
    }

    /**
     * Санитизировать имя источника.
     *
     * @param string $source
     * @return string
     */
    private function sanitizeSource(string $source): string
    {
        $source = mb_strtolower(trim($source));
        if (empty($source)) {
            return '';
        }

        if (isset(self::SOURCE_LABELS[$source])) {
            return $source;
        }

        return '';
    }

    /**
     * Санитизировать поле сортировки.
     *
     * @param string $field
     * @return string
     */
    private function sanitizeSortField(string $field): string
    {
        $field = mb_strtolower(trim($field));
        return in_array($field, self::ALLOWED_SORT_FIELDS, true) ? $field : 'score';
    }

    /**
     * Санитизировать направление сортировки.
     *
     * @param string $dir
     * @return string
     */
    private function sanitizeSortDirection(string $dir): string
    {
        return mb_strtoupper(trim($dir)) === 'ASC' ? 'ASC' : 'DESC';
    }

    /**
     * Санитизировать дату (YYYY-MM-DD).
     *
     * @param string $date
     * @return string Пустая строка если невалидная
     */
    private function sanitizeDate(string $date): string
    {
        $date = trim($date);
        if (empty($date)) {
            return '';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        return '';
    }

    /**
     * Форматировать дату для БД.
     *
     * @param string $date ISO 8601 или произвольный формат
     * @return string|null
     */
    private function formatDateForDb(string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    // ═════════════════════════════════════════════════════════════
    // Утилиты форматирования
    // ═════════════════════════════════════════════════════════════

    /**
     * Обрезать строку до N символов с многоточием.
     *
     * @param string $text Текст
     * @param int    $max  Максимальная длина
     * @return string
     */
    private function truncate(string $text, int $max = 200): string
    {
        $text = strip_tags($text);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . '…';
    }

    /**
     * Человекочитаемая дата.
     *
     * @param string $date ISO 8601 или datetime
     * @return string
     */
    private function humanDate(string $date): string
    {
        if (empty($date)) {
            return '';
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        $now = time();
        $diff = $now - $timestamp;

        if ($diff < 0) {
            // Будущее
            $absDiff = abs($diff);
            if ($absDiff < 3600) {
                $min = max(1, (int)floor($absDiff / 60));
                return "через {$min} мин.";
            }
            if ($absDiff < 86400) {
                $hours = (int)floor($absDiff / 3600);
                return "через {$hours} ч.";
            }
            $days = (int)floor($absDiff / 86400);
            return "через {$days} дн.";
        }

        if ($diff < 60) {
            return 'только что';
        }
        if ($diff < 3600) {
            $min = (int)floor($diff / 60);
            return "{$min} мин. назад";
        }
        if ($diff < 86400) {
            $hours = (int)floor($diff / 3600);
            return "{$hours} ч. назад";
        }
        if ($diff < 604800) { // 7 дней
            $days = (int)floor($diff / 86400);
            return "{$days} дн. назад";
        }

        return date('d.m.Y H:i', $timestamp);
    }
}

return GetList::class;
