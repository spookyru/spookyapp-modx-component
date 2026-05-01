<?php

declare(strict_types=1);

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modX;

/**
 * Процессор получения детальной информации об одной теме.
 *
 * ═══════════════════════════════════════════════════════════════
 * Используется ExtJS-панелью TopicFinder для отображения
 * полных данных темы (включая распарсенные metadata).
 * ═══════════════════════════════════════════════════════════════
 *
 * Запрос:
 *   GET/POST connector.php?action=topicfinder/getdetails&id=123
 *
 * Параметры:
 *   - id (int, required) — ID темы из таблицы spookyapp_topics
 *
 * Ответ (success):
 *   {
 *       "success": true,
 *       "message": "",
 *       "object": {
 *           "id": 123,
 *           "source": "reddit",
 *           "title": "Nothing Phone (4a) announced",
 *           "url": "https://...",
 *           "description": "...",
 *           "category": "Gadgets",
 *           "published_at": "2026-03-05 10:00:00",
 *           "score": 85.5,
 *           "status": "new",
 *           "metadata": { "brand": "Nothing", "image_url": "..." },
 *           "cached_at": "2026-03-05 12:00:00"
 *       }
 *   }
 *
 * Ответ (failure):
 *   {
 *       "success": false,
 *       "message": "Тема с ID 999 не найдена.",
 *       "object": null
 *   }
 *
 * @package SpookyApp
 * @subpackage Processors\TopicFinder
 */
class SpookyAppTopicFinderGetDetailsProcessor extends Processor
{
    /** @var string Permission для доступа */
    public $permission = 'spookyapp';

    /**
     * Инициализация процессора.
     *
     * Загружает autoload и подключает пакет моделей.
     *
     * @return bool
     */
    public function initialize(): bool
    {
        $autoloadPath = MODX_CORE_PATH . 'components/spookyapp/vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }

        $this->modx->addPackage(
            'SpookyApp\Model',
            MODX_CORE_PATH . 'components/spookyapp/src/',
            null,
            'SpookyApp\\Model\\'
        );

        return parent::initialize();
    }

    /**
     * Проверка прав доступа.
     *
     * @return bool|string
     */
    public function checkPermissions(): bool|string
    {
        if (!$this->modx->hasPermission($this->permission)) {
            return $this->modx->lexicon('access_denied') ?: 'Access denied';
        }
        return true;
    }

    /**
     * Основная логика: получить тему по ID через prepared statement.
     *
     * @return mixed
     */
    public function process(): mixed
    {
        // ── 1. Валидация ID ──────────────────────────────────────
        $id = $this->getProperty('id', null);

        if ($id === null || $id === '') {
            return $this->failure('Параметр "id" обязателен.');
        }

        $id = (int) $id;

        if ($id <= 0) {
            return $this->failure('Параметр "id" должен быть положительным целым числом.');
        }

        // ── 2. Определяем имя таблицы ────────────────────────────
        $tableName = $this->modx->getTableName('SpookyApp\Model\SpookyAppTopic');

        // Fallback: если getTableName не сработал (пакет не подгружен)
        if (empty($tableName) || $tableName === 'SpookyApp\Model\SpookyAppTopic') {
            $tablePrefix = $this->modx->getOption('table_prefix', null, 'modx_');
            $tableName = '`' . $tablePrefix . 'spookyapp_topics`';
        }

        // ── 3. Prepared statement ────────────────────────────────
        try {
            $sql = "SELECT 
                        `id`,
                        `source`,
                        `title`,
                        `url`,
                        `description`,
                        `category`,
                        `published_at`,
                        `score`,
                        `status`,
                        `metadata`,
                        `cached_at`
                    FROM {$tableName}
                    WHERE `id` = :id
                    LIMIT 1";

            $stmt = $this->modx->prepare($sql);

            if (!$stmt) {
                $this->modx->log(
                    modX::LOG_LEVEL_ERROR,
                    "[SpookyApp:GetDetails] Failed to prepare statement for topic #{$id}"
                );
                return $this->failure('Ошибка подготовки запроса к базе данных.');
            }

            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $stmt->closeCursor();

        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                "[SpookyApp:GetDetails] SQL error for topic #{$id}: " . $e->getMessage()
            );
            return $this->failure('Ошибка запроса к базе данных: ' . $e->getMessage());
        }

        // ── 4. Проверяем результат ───────────────────────────────
        if (empty($row)) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                "[SpookyApp:GetDetails] Тема #{$id} не найдена в spookyapp_topics"
            );
            return $this->failure("Тема с ID {$id} не найдена.");
        }

        // ── 5. Парсинг metadata из JSON ──────────────────────────
        $metadata = $this->parseMetadata($row['metadata'] ?? null);

        // ── 6. Формируем ответ ───────────────────────────────────
        $topic = [
            'id'           => (int) $row['id'],
            'source'       => (string) ($row['source'] ?? ''),
            'title'        => (string) ($row['title'] ?? ''),
            'url'          => (string) ($row['url'] ?? ''),
            'description'  => (string) ($row['description'] ?? ''),
            'category'     => (string) ($row['category'] ?? ''),
            'published_at' => (string) ($row['published_at'] ?? ''),
            'score'        => round((float) ($row['score'] ?? 0), 2),
            'status'       => (string) ($row['status'] ?? 'new'),
            'metadata'     => $metadata,
            'cached_at'    => (string) ($row['cached_at'] ?? ''),
        ];

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            "[SpookyApp:GetDetails] Тема #{$id}: '{$topic['title']}' (source: {$topic['source']})"
        );

        return $this->success('', $topic);
    }

    /**
     * Безопасно распарсить JSON metadata.
     *
     * Обрабатывает случаи:
     * - null / пустая строка → пустой массив
     * - Валидный JSON → массив
     * - Невалидный JSON → пустой массив + запись в лог
     * - Уже массив (xPDO мог десериализовать) → вернуть как есть
     *
     * @param mixed $raw Сырые данные metadata
     * @return array Распарсенные метаданные
     */
    private function parseMetadata(mixed $raw): array
    {
        // Уже массив (xPDO может десериализовать автоматически)
        if (is_array($raw)) {
            return $raw;
        }

        // null или пустая строка
        if ($raw === null || $raw === '' || $raw === 'null') {
            return [];
        }

        // Строка — пробуем JSON decode
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[SpookyApp:GetDetails] Невалидный JSON в metadata: '
                . json_last_error_msg()
                . '. Raw: ' . mb_substr($raw, 0, 200)
            );
            return [];
        }

        return [];
    }
}

return SpookyAppTopicFinderGetDetailsProcessor::class;