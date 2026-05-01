<?php

declare(strict_types=1);

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modX;

/**
 * Процессор удаления одной темы из spookyapp_topics.
 *
 * ═══════════════════════════════════════════════════════════════
 * Используется ExtJS-гридом TopicFinder для удаления
 * отдельных тем (кнопка "Удалить" в контекстном меню).
 * ═══════════════════════════════════════════════════════════════
 *
 * Запрос:
 *   POST connector.php?action=topicfinder/delete
 *
 * Параметры:
 *   - id (int, required) — ID темы из таблицы spookyapp_topics
 *
 * Ответ (success):
 *   {
 *       "success": true,
 *       "message": "Тема #123 «Samsung Galaxy S26» успешно удалена.",
 *       "object": { "id": 123 }
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
class SpookyAppTopicFinderDeleteProcessor extends Processor
{
    /** @var string Permission для доступа */
    public $permission = 'spookyapp';

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
     * Основная логика: валидация → проверка существования → удаление.
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

        // ── 2. Определяем таблицу ────────────────────────────────
        $tableName = $this->modx->getTableName('SpookyApp\Model\SpookyAppTopic');

        if (empty($tableName) || $tableName === 'SpookyApp\Model\SpookyAppTopic') {
            $tablePrefix = $this->modx->getOption('table_prefix', null, 'modx_');
            $tableName = '`' . $tablePrefix . 'spookyapp_topics`';
        }

        try {
            // ── 3. Получаем тему (для лога и проверки) ───────────
            $title = $this->fetchTopicTitle($tableName, $id);

            if ($title === null) {
                $this->modx->log(
                    modX::LOG_LEVEL_WARN,
                    "[SpookyApp:Delete] Попытка удалить несуществующую тему #{$id}"
                );
                return $this->failure("Тема с ID {$id} не найдена.");
            }

            // ── 4. DELETE с prepared statement ────────────────────
            $sql = "DELETE FROM {$tableName} WHERE `id` = :id LIMIT 1";

            $stmt = $this->modx->prepare($sql);

            if (!$stmt) {
                $this->modx->log(
                    modX::LOG_LEVEL_ERROR,
                    "[SpookyApp:Delete] Не удалось подготовить DELETE для темы #{$id}"
                );
                return $this->failure('Ошибка подготовки запроса к базе данных.');
            }

            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();

            $affectedRows = $stmt->rowCount();
            $stmt->closeCursor();

            // ── 5. Проверяем результат ───────────────────────────
            if ($affectedRows === 0) {
                $this->modx->log(
                    modX::LOG_LEVEL_WARN,
                    "[SpookyApp:Delete] DELETE для темы #{$id} вернул 0 affected rows "
                    . '(возможно, удалена параллельным запросом)'
                );
                return $this->failure("Не удалось удалить тему с ID {$id}. Возможно, она уже была удалена.");
            }

            // ── 6. Логируем успех ────────────────────────────────
            $displayTitle = mb_strlen($title) > 60
                ? mb_substr($title, 0, 60) . '…'
                : $title;

            $username = $this->modx->user ? $this->modx->user->get('username') : 'unknown';

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                "[SpookyApp:Delete] Тема #{$id} «{$displayTitle}» удалена пользователем {$username}"
            );

            return $this->success(
                "Тема #{$id} «{$displayTitle}» успешно удалена.",
                ['id' => $id]
            );

        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                "[SpookyApp:Delete] Исключение при удалении темы #{$id}: " . $e->getMessage()
            );
            return $this->failure('Ошибка при удалении темы: ' . $e->getMessage());
        }
    }

    /**
     * Получить title темы по ID (prepared statement).
     *
     * Используется для:
     * - Проверки существования перед DELETE
     * - Отображения названия в сообщении и логе
     *
     * @param string $tableName Полное имя таблицы
     * @param int    $id        ID темы
     * @return string|null Title темы или null если не найдена
     */
    private function fetchTopicTitle(string $tableName, int $id): ?string
    {
        $sql = "SELECT `title` FROM {$tableName} WHERE `id` = :id LIMIT 1";

        $stmt = $this->modx->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (empty($row)) {
            return null;
        }

        return (string) ($row['title'] ?? '');
    }
}

return SpookyAppTopicFinderDeleteProcessor::class;