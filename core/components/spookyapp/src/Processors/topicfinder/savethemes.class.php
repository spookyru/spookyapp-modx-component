<?php

declare(strict_types=1);

use MODX\Revolution\modX;
use MODX\Revolution\modResource;
use MODX\Revolution\Processors\Processor;
use SpookyApp\Model\SpookyAppTopic;
use Throwable;

/**
 * Процессор сохранения выбранных тем как черновиков MODX-ресурсов.
 *
 * ═══════════════════════════════════════════════════════════════
 * Используется ExtJS-гридом TopicFinder для массового создания
 * ресурсов из найденных тем (spookyapp_topics → modResource).
 * ═══════════════════════════════════════════════════════════════
 *
 * Запрос:
 *   POST connector.php?action=topicfinder/savethemes
 *
 * Параметры:
 *   - topics      (array|string, required) — массив ID тем из spookyapp_topics
 *   - parent_id   (int, optional)          — родительский ресурс (default: 0)
 *   - template_id (int, optional)          — ID шаблона (default: 0)
 *   - published   (bool, optional)         — публиковать сразу (default: false, черновик)
 *
 * Ответ:
 *   {
 *       "success": true,
 *       "message": "Создано ресурсов: 5 из 7",
 *       "object": {
 *           "total_requested": 7,
 *           "total_created": 5,
 *           "total_errors": 2,
 *           "created": [
 *               {"topic_id": 1, "resource_id": 123, "pagetitle": "...", "url": "..."},
 *               ...
 *           ],
 *           "errors": [
 *               {"topic_id": 3, "error": "Topic not found"},
 *               {"topic_id": 5, "error": "Failed to save resource"}
 *           ]
 *       }
 *   }
 *
 * @package SpookyApp
 * @subpackage Processors\TopicFinder
 */
class SpookyAppTopicFinderSaveThemesProcessor extends Processor
{
    /** @var string Класс Permission для доступа */
    public $permission = 'spookyapp';

    /** @var array Результаты: успешно созданные ресурсы */
    private array $created = [];

    /** @var array Результаты: ошибки */
    private array $errors = [];

    /**
     * Инициализация процессора.
     *
     * Загружает autoload компонента и проверяет наличие
     * обязательных классов.
     *
     * @return bool
     */
    public function initialize(): bool
    {
        // ── Autoload компонента ──────────────────────────────────
        $autoloadPath = MODX_CORE_PATH . 'components/spookyapp/vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }

        // ── Подключаем пакет моделей (для xPDO) ─────────────────
        $this->modx->addPackage(
            'SpookyApp\Model',
            MODX_CORE_PATH . 'components/spookyapp/src/',
            null,
            'SpookyApp\\Model\\'
        );

        // ── Нормализация параметра topics ────────────────────────
        // ExtJS может отправить как JSON-строку, так и массив
        $topics = $this->getProperty('topics', '');
        if (is_string($topics)) {
            $decoded = json_decode($topics, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->setProperty('topics', $decoded);
            } else {
                // Попробуем comma-separated
                $parts = array_filter(array_map('trim', explode(',', $topics)));
                $this->setProperty('topics', !empty($parts) ? array_map('intval', $parts) : []);
            }
        }

        return parent::initialize();
    }

    /**
     * Проверить, имеет ли пользователь доступ к процессору.
     *
     * @return bool|string true если разрешено, иначе строка ошибки
     */
    public function checkPermissions(): bool|string
    {
        if (!$this->modx->hasPermission($this->permission)) {
            return $this->modx->lexicon('access_denied') ?: 'Access denied';
        }
        return true;
    }

    /**
     * Основная логика процессора.
     *
     * @return mixed
     */
    public function process(): mixed
    {
        // ── 1. Валидация входных данных ──────────────────────────
        $topics     = $this->getProperty('topics', []);
        $parentId   = (int) $this->getProperty('parent_id', 0);
        $templateId = (int) $this->getProperty('template_id', 0);
        $published  = (bool) $this->getProperty('published', false);

        if (!is_array($topics) || empty($topics)) {
            return $this->failure('Параметр "topics" обязателен и должен содержать массив ID тем.', [
                'total_requested' => 0,
                'total_created'   => 0,
                'total_errors'    => 0,
                'created'         => [],
                'errors'          => [],
            ]);
        }

        // Приводим к int, убираем дубли и нули
        $topicIds = array_values(array_unique(array_filter(
            array_map('intval', $topics),
            static fn(int $id): bool => $id > 0
        )));

        if (empty($topicIds)) {
            return $this->failure('Не передано ни одного валидного ID темы.', [
                'total_requested' => count($topics),
                'total_created'   => 0,
                'total_errors'    => 0,
                'created'         => [],
                'errors'          => [],
            ]);
        }

        // ── 2. Валидация parent_id (если указан) ─────────────────
        if ($parentId > 0) {
            $parentResource = $this->modx->getObject(modResource::class, $parentId);
            if (!$parentResource) {
                return $this->failure(
                    "Родительский ресурс #{$parentId} не найден.",
                    ['total_requested' => count($topicIds), 'total_created' => 0, 'total_errors' => 0, 'created' => [], 'errors' => []]
                );
            }
        }

        // ── 3. Обработка каждой темы ─────────────────────────────
        $totalRequested = count($topicIds);

        foreach ($topicIds as $topicId) {
            try {
                $this->processOneTopic($topicId, $parentId, $templateId, $published);
            } catch (Throwable $e) {
                $this->modx->log(
                    modX::LOG_LEVEL_ERROR,
                    "[SpookyApp:SaveThemes] Исключение для topic #{$topicId}: " . $e->getMessage()
                );
                $this->errors[] = [
                    'topic_id' => $topicId,
                    'error'    => 'Exception: ' . $e->getMessage(),
                ];
            }
        }

        // ── 4. Формируем ответ ───────────────────────────────────
        $totalCreated = count($this->created);
        $totalErrors  = count($this->errors);

        $data = [
            'total_requested' => $totalRequested,
            'total_created'   => $totalCreated,
            'total_errors'    => $totalErrors,
            'created'         => $this->created,
            'errors'          => $this->errors,
        ];

        $message = "Создано ресурсов: {$totalCreated} из {$totalRequested}";

        if ($totalErrors > 0) {
            $message .= " (ошибок: {$totalErrors})";
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[SpookyApp:SaveThemes] {$message}"
        );

        // Если ничего не создано — failure, иначе success
        if ($totalCreated === 0) {
            return $this->failure($message, $data);
        }

        return $this->success($message, $data);
    }

    /**
     * Обработать одну тему: получить из БД → создать ресурс.
     *
     * @param int  $topicId    ID темы в spookyapp_topics
     * @param int  $parentId   Родительский ресурс
     * @param int  $templateId Шаблон
     * @param bool $published  Опубликовать сразу
     * @return void
     */
    private function processOneTopic(
        int  $topicId,
        int  $parentId,
        int  $templateId,
        bool $published
    ): void {
        // ── A. Получаем тему из spookyapp_topics ─────────────────
        /** @var SpookyAppTopic|null $topic */
        $topic = $this->modx->getObject(SpookyAppTopic::class, ['id' => $topicId]);

        if (!$topic) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                "[SpookyApp:SaveThemes] Тема #{$topicId} не найдена в spookyapp_topics"
            );
            $this->errors[] = [
                'topic_id' => $topicId,
                'error'    => 'Topic not found',
            ];
            return;
        }

        // ── B. Извлекаем данные темы ─────────────────────────────
        $title       = trim((string) $topic->get('title'));
        $description = trim((string) $topic->get('description'));
        $url         = trim((string) $topic->get('url'));
        $source      = trim((string) $topic->get('source'));
        $category    = trim((string) $topic->get('category'));

        if (empty($title)) {
            $this->errors[] = [
                'topic_id' => $topicId,
                'error'    => 'Topic has empty title',
            ];
            return;
        }

        // ── C. Формируем контент ─────────────────────────────────
        $content = $this->buildResourceContent($description, $url, $source, $category);

        // ── D. Генерируем alias ──────────────────────────────────
        $alias = $this->generateAlias($title);

        // ── E. Создаём modResource ───────────────────────────────
        /** @var modResource $resource */
        $resource = $this->modx->newObject(modResource::class);
        $resource->fromArray([
            'pagetitle'   => mb_substr($title, 0, 255),
            'longtitle'   => mb_substr($title, 0, 255),
            'alias'       => $alias,
            'description' => mb_substr($description, 0, 500),
            'content'     => $content,
            'parent'      => $parentId,
            'template'    => $templateId,
            'published'   => $published ? 1 : 0,
            'hidemenu'    => 1,
            'richtext'    => 1,
            'searchable'  => 1,
            'cacheable'   => 1,
            'isfolder'    => 0,
            'class_key'   => modResource::class,
            'context_key' => 'web',
            'createdon'   => time(),
            'createdby'   => $this->modx->user ? $this->modx->user->get('id') : 0,
        ], '', true);

        // ── F. Сохраняем ─────────────────────────────────────────
        if (!$resource->save()) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                "[SpookyApp:SaveThemes] Не удалось сохранить ресурс для темы #{$topicId} '{$title}'"
            );
            $this->errors[] = [
                'topic_id' => $topicId,
                'error'    => 'Failed to save resource',
            ];
            return;
        }

        $resourceId  = $resource->get('id');
        $resourceUrl = $this->modx->makeUrl($resourceId, '', '', 'full');

        // ── G. Обновляем статус темы (если есть поле status) ─────
        $topic->set('status', 'exported');
        $topic->save();

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[SpookyApp:SaveThemes] Тема #{$topicId} → ресурс #{$resourceId} '{$title}'"
        );

        // ── H. Добавляем в результат ─────────────────────────────
        $this->created[] = [
            'topic_id'    => $topicId,
            'resource_id' => $resourceId,
            'pagetitle'   => $title,
            'url'         => $resourceUrl ?: '',
            'published'   => $published,
        ];
    }

    /**
     * Сформировать HTML-контент ресурса из данных темы.
     *
     * Структура:
     * - Описание (если есть)
     * - Блок метаданных: источник, категория, ссылка
     *
     * @param string $description Описание темы
     * @param string $url         URL источника
     * @param string $source      Название источника (mobileapi, newsapi, etc.)
     * @param string $category    Категория темы
     * @return string HTML-контент
     */
    private function buildResourceContent(
        string $description,
        string $url,
        string $source,
        string $category
    ): string {
        $parts = [];

        // Описание
        if (!empty($description)) {
            $parts[] = '<p>' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        // Блок метаданных
        $meta = [];

        if (!empty($source)) {
            $meta[] = '<strong>Источник API:</strong> '
                . htmlspecialchars($source, ENT_QUOTES, 'UTF-8');
        }

        if (!empty($category)) {
            $meta[] = '<strong>Категория:</strong> '
                . htmlspecialchars($category, ENT_QUOTES, 'UTF-8');
        }

        if (!empty($url)) {
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $meta[] = '<strong>Источник:</strong> '
                . '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">'
                . $safeUrl . '</a>';
        }

        if (!empty($meta)) {
            $parts[] = '<hr>';
            $parts[] = '<div class="topic-source-meta">';
            $parts[] = '<p><em>' . implode('<br>', $meta) . '</em></p>';
            $parts[] = '</div>';
        }

        // Маркер автогенерации
        $parts[] = '<!-- Generated by SpookyApp TopicFinder: '
            . date('Y-m-d H:i:s') . ' -->';

        return implode("\n", $parts);
    }

    /**
     * Сгенерировать уникальный alias из заголовка.
     *
     * Транслитерирует, приводит к lowercase, убирает спецсимволы.
     * При дубликате — добавляет суффикс -2, -3, ...
     *
     * @param string $title Заголовок
     * @return string Уникальный alias
     */
    private function generateAlias(string $title): string
    {
        // Базовая транслитерация (MODX built-in)
        $alias = $this->modx->filterPathSegment($title);

        // Fallback: ручная обработка если MODX вернул пустоту
        if (empty($alias)) {
            $alias = mb_strtolower(trim($title));
            $alias = (string) preg_replace('/[^a-z0-9а-яё\-]+/u', '-', $alias);
            $alias = (string) preg_replace('/-+/', '-', $alias);
            $alias = trim($alias, '-');
        }

        // Ограничиваем длину
        if (mb_strlen($alias) > 100) {
            $alias = mb_substr($alias, 0, 100);
            $alias = rtrim($alias, '-');
        }

        // Fallback для пустого alias
        if (empty($alias)) {
            $alias = 'topic-' . time();
        }

        // Проверяем уникальность
        $baseAlias  = $alias;
        $counter    = 1;
        $maxRetries = 50;

        while ($counter <= $maxRetries) {
            $existing = $this->modx->getObject(modResource::class, ['alias' => $alias]);
            if (!$existing) {
                break;
            }
            $counter++;
            $alias = $baseAlias . '-' . $counter;
        }

        return $alias;
    }
}

return SpookyAppTopicFinderSaveThemesProcessor::class;