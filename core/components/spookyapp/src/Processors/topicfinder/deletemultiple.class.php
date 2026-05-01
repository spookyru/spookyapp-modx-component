<?php

declare(strict_types=1);

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modX;

/**
 * Процессор массового удаления тем из spookyapp_topics.
 *
 * ═══════════════════════════════════════════════════════════════
 * Используется ExtJS-гридом TopicFinder для удаления
 * нескольких выбранных тем (кнопка "Delete Selected").
 * ═══════════════════════════════════════════════════════════════
 *
 * Запрос:
 *   POST connector.php?action=topicfinder/deletemultiple
 *
 * Параметры:
 *   - ids (string, required) — CSV список ID тем, например "1,5,12"
 *
 * Ответ (success):
 *   {
 *       "success": true,
 *       "message": "Удалено тем: 3.",
 *       "object": { "deleted": 3 }
 *   }
 *
 * Ответ (failure):
 *   {
 *       "success": false,
 *       "message": "...",
 *       "object": null
 *   }
 *
 * @package SpookyApp
 * @subpackage Processors\TopicFinder
 */
class SpookyAppTopicFinderDeleteMultipleProcessor extends Processor
{
    /** @var string Permission для доступа */
    public $permission = 'spookyapp';

    /** @var int Максимальное количество ID за один запрос */
    private const MAX_IDS = 200;

    /**
     * Инициализация: autoload + пакет моделей.
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
     * Основная логика: парсинг IDs → валидация → массовое удаление.
     *
     * @return mixed
     */
    public function process(): mixed
    {
        // ── 1. Парсим и валидируем список ID ────────────────────
        $idsStr = trim((string)$this->getProperty('ids', ''));

        if (empty($idsStr)) {
            return $this->failure('Параметр "ids" обязателен.');
        }

        $ids = array_values(array_unique(array_filter(
            array_map(fn($v) => (int)trim($v), explode(',', $idsStr)),
            fn($v) => $v > 0
        )));

        if (empty($ids)) {
            return $this->failure('Не передано ни одного корректного ID (положительного целого числа).');
        }

        if (count($ids) > self::MAX_IDS) {
            return $this->failure('Нельзя удалить более ' . self::MAX_IDS . ' тем за один запрос.');
        }

        // ── 2. Определяем таблицу ────────────────────────────────
        $tableName = $this->modx->getTableName('SpookyApp\Model\SpookyAppTopic');

        if (empty($tableName) || $tableName === 'SpookyApp\Model\SpookyAppTopic') {
            $tablePrefix = $this->modx->getOption('table_prefix', null, 'modx_');
            $tableName = '`' . $tablePrefix . 'spookyapp_topics`';
        }

        try {
            // ── 3. DELETE IN (...) с prepared statement ───────────
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "DELETE FROM {$tableName} WHERE `id` IN ({$placeholders})";

            $stmt = $this->modx->prepare($sql);

            if (!$stmt) {
                $this->modx->log(
                    modX::LOG_LEVEL_ERROR,
                    '[SpookyApp:DeleteMultiple] Не удалось подготовить DELETE IN запрос'
                );
                return $this->failure('Ошибка подготовки запроса к базе данных.');
            }

            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 1, $id, \PDO::PARAM_INT);
            }

            $stmt->execute();
            $deleted = $stmt->rowCount();
            $stmt->closeCursor();

            // ── 4. Логируем ──────────────────────────────────────
            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                '[SpookyApp:DeleteMultiple] Удалено ' . $deleted . ' тем. IDs: ' . implode(',', $ids)
            );

            return $this->success(
                'Удалено тем: ' . $deleted . '.',
                ['deleted' => $deleted]
            );

        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[SpookyApp:DeleteMultiple] Ошибка: ' . $e->getMessage()
            );
            return $this->failure('Ошибка при удалении: ' . $e->getMessage());
        }
    }
}

return SpookyAppTopicFinderDeleteMultipleProcessor::class;
