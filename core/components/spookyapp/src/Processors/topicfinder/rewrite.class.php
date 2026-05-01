<?php

declare(strict_types=1);

namespace SpookyApp\Processors\TopicFinder;

use MODX\Revolution\modX;
use MODX\Revolution\Processors\Processor;
use SpookyApp\Model\SpookyAppTopic;
use SpookyApp\Services\API\YandexAPIService;
use SpookyApp\Services\Cache\CacheService;
use Throwable;

/**
 * Процессор рерайта темы через Yandex GPT.
 *
 * Получает тему из БД (по id или topic_id), отправляет заголовок и описание
 * в Yandex GPT для генерации уникального контента, сохраняет результат
 * обратно в тему (metadata.rewrite) и опционально создаёт черновик ресурса MODX.
 *
 * ═══════════════════════════════════════════════════════════════
 * Параметры запроса:
 * ═══════════════════════════════════════════════════════════════
 *
 * @property int    $id              ID записи в таблице spookyapp_topics (PK)
 * @property string $topic_id        Альтернатива: уникальный topic_id ('tmdb_movie_12345')
 *                                   Обязателен один из: id или topic_id
 *
 * @property string $mode            Режим рерайта (default: 'article')
 *                                   'article'  — полноценная статья (заголовок + лид + текст)
 *                                   'news'     — короткая новость (заголовок + 2-3 абзаца)
 *                                   'social'   — пост для соцсетей (короткий, с эмодзи)
 *                                   'seo'      — SEO-оптимизированный текст (description + keywords)
 *                                   'title'    — только рерайт заголовка (несколько вариантов)
 *                                   'custom'   — произвольный промпт (передать в &prompt)
 *
 * @property string $prompt          Пользовательский промпт (только для mode=custom)
 *                                   Плейсхолдеры: {title}, {description}, {source}, {category}, {url}
 *
 * @property string $tone            Тон текста (default: 'neutral')
 *                                   'neutral', 'formal', 'casual', 'enthusiastic', 'analytical'
 *
 * @property string $language        Язык генерации (default: 'ru')
 *                                   'ru', 'en'
 *
 * @property int    $min_length      Минимальная длина текста в символах (default: 500)
 * @property int    $max_length      Максимальная длина текста в символах (default: 5000)
 *
 * @property float  $temperature     Температура (креативность) GPT (default: 0.6)
 *                                   0.0 = строго по фактам, 1.0 = максимум креативности
 *
 * @property bool   $save_to_topic   Сохранить результат в metadata темы (default: true)
 * @property bool   $create_draft    Создать черновик ресурса MODX (default: false)
 * @property int    $parent_id       ID родительского ресурса для черновика (default: 0)
 * @property int    $template_id     ID шаблона для черновика (default: из настроек)
 *
 * @property bool   $force           Перегенерировать даже если рерайт уже есть (default: false)
 *
 * ═══════════════════════════════════════════════════════════════
 * Ответ (success):
 * ═══════════════════════════════════════════════════════════════
 * ```json
 * {
 *   "success": true,
 *   "message": "Рерайт выполнен успешно",
 *   "object": {
 *     "topic_id": "tmdb_movie_12345",
 *     "mode": "article",
 *     "original": {
 *       "title": "Original Title",
 *       "description": "Original description..."
 *     },
 *     "rewrite": {
 *       "title": "Переписанный заголовок",
 *       "lead": "Лид-абзац...",
 *       "content": "Полный текст статьи...",
 *       "meta_description": "SEO-описание...",
 *       "meta_keywords": "ключевое, слово, фраза",
 *       "tags": ["тег1", "тег2"]
 *     },
 *     "stats": {
 *       "original_length": 150,
 *       "rewrite_length": 2340,
 *       "tokens_used": 1250,
 *       "model": "yandexgpt/latest",
 *       "duration_sec": 4.21,
 *       "cached": false
 *     },
 *     "draft_resource_id": null,
 *     "saved_to_topic": true
 *   }
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════
 * Примеры вызова:
 * ═══════════════════════════════════════════════════════════════
 *
 * ```php
 * // Рерайт в статью:
 * $response = $modx->runProcessor('topicfinder/rewrite', [
 *     'id'   => 42,
 *     'mode' => 'article',
 *     'tone' => 'enthusiastic',
 * ], ['processors_path' => $corePath . 'processors/']);
 *
 * // Короткая новость по topic_id:
 * $response = $modx->runProcessor('topicfinder/rewrite', [
 *     'topic_id' => 'newsapi_abc123',
 *     'mode'     => 'news',
 * ], ['processors_path' => $corePath . 'processors/']);
 *
 * // SEO-тексты:
 * $response = $modx->runProcessor('topicfinder/rewrite', [
 *     'id'   => 42,
 *     'mode' => 'seo',
 *     'tone' => 'analytical',
 * ], ['processors_path' => $corePath . 'processors/']);
 *
 * // Кастомный промпт:
 * $response = $modx->runProcessor('topicfinder/rewrite', [
 *     'id'     => 42,
 *     'mode'   => 'custom',
 *     'prompt' => 'Напиши обзор фильма "{title}" в стиле Антона Долина. Описание: {description}',
 * ], ['processors_path' => $corePath . 'processors/']);
 *
 * // С созданием черновика ресурса:
 * $response = $modx->runProcessor('topicfinder/rewrite', [
 *     'id'           => 42,
 *     'mode'         => 'article',
 *     'create_draft' => true,
 *     'parent_id'    => 5,
 *     'template_id'  => 2,
 * ], ['processors_path' => $corePath . 'processors/']);
 * ```
 *
 * @package SpookyApp
 * @subpackage Processors\TopicFinder
 */
class Rewrite extends Processor
{
    // ─── Режимы рерайта ──────────────────────────────────────────
    private const MODE_ARTICLE = 'article';
    private const MODE_NEWS    = 'news';
    private const MODE_SOCIAL  = 'social';
    private const MODE_SEO     = 'seo';
    private const MODE_TITLE   = 'title';
    private const MODE_CUSTOM  = 'custom';

    private const ALLOWED_MODES = [
        self::MODE_ARTICLE,
        self::MODE_NEWS,
        self::MODE_SOCIAL,
        self::MODE_SEO,
        self::MODE_TITLE,
        self::MODE_CUSTOM,
    ];

    // ─── Тон текста ──────────────────────────────────────────────
    private const ALLOWED_TONES = [
        'neutral', 'formal', 'casual', 'enthusiastic', 'analytical',
    ];

    private const TONE_INSTRUCTIONS = [
        'neutral'       => 'Пиши в нейтральном информационном стиле.',
        'formal'        => 'Пиши в деловом, официальном стиле. Избегай разговорных выражений.',
        'casual'        => 'Пиши в разговорном, дружелюбном стиле. Обращайся к читателю на «ты».',
        'enthusiastic'  => 'Пиши с энтузиазмом, ярко и эмоционально. Используй восклицания.',
        'analytical'    => 'Пиши в аналитическом стиле. Приводи факты, сравнения, выводы.',
    ];

    // ─── Языки ───────────────────────────────────────────────────
    private const ALLOWED_LANGUAGES = ['ru', 'en'];

    // ─── Лимиты ──────────────────────────────────────────────────
    private const MIN_LENGTH_MIN     = 50;
    private const MIN_LENGTH_MAX     = 2000;
    private const MAX_LENGTH_MIN     = 200;
    private const MAX_LENGTH_MAX     = 15000;
    private const TEMPERATURE_MIN    = 0.0;
    private const TEMPERATURE_MAX    = 1.0;
    private const DEFAULT_MIN_LENGTH = 500;
    private const DEFAULT_MAX_LENGTH = 5000;
    private const DEFAULT_TEMPERATURE = 0.6;

    // ─── Метки источников ────────────────────────────────────────
    private const SOURCE_LABELS = [
        'newsapi'     => 'NewsAPI',
        'reddit'      => 'Reddit',
        'tmdb'        => 'TMDB',
        'rawg'        => 'RAWG (Games)',
        'github'      => 'GitHub',
        'mobileapi'   => 'MobileApi.dev',
        'flashlive'   => 'FlashLive Sports',
        'ibu'         => 'IBU Biathlon',
        'apifootball' => 'API-Football',
    ];

    /** @var string */
    public $languageTopics = ['spookyapp:default'];

    /** @var float */
    private float $startTime;

    /** @var YandexAPIService */
    private YandexAPIService $gptService;

    /**
     * Инициализация: autoload, xPDO-пакет, сервисы.
     *
     * @return bool|string
     */
    public function initialize()
    {
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

        // Регистрируем xPDO-пакет
        $this->modx->addPackage('SpookyApp\\Model', $corePath . 'src/Model/');

        // Инициализируем Yandex GPT сервис
        $cache = new CacheService($this->modx);
        $this->gptService = new YandexAPIService($this->modx, $cache);

        return parent::initialize();
    }

    /**
     * Основная логика: загрузка темы → формирование промпта → GPT → парсинг → сохранение.
     *
     * @return array|string
     */
    public function process()
    {
        // ══════════════════════════════════════════════════════════
        // 1. Загружаем тему из БД
        // ══════════════════════════════════════════════════════════
        $topic = $this->loadTopic();

        if ($topic === null) {
            return $this->failure('Тема не найдена. Укажите корректный id или topic_id.');
        }

        // ══════════════════════════════════════════════════════════
        // 2. Проверяем, нет ли уже готового рерайта (если не force)
        // ══════════════════════════════════════════════════════════
        $params = $this->parseParams();
        $force  = $params['force'];

        if (!$force) {
            $existingRewrite = $this->getExistingRewrite($topic, $params['mode']);
            if ($existingRewrite !== null) {
                return $this->success('Рерайт уже существует (используйте force=true для перегенерации)', [
                    'topic_id'  => $topic->get('topic_id'),
                    'mode'      => $params['mode'],
                    'original'  => $this->getOriginalData($topic),
                    'rewrite'   => $existingRewrite,
                    'stats'     => [
                        'cached'       => true,
                        'duration_sec' => $this->getDuration(),
                    ],
                    'draft_resource_id' => null,
                    'saved_to_topic'    => false,
                ]);
            }
        }

        // ══════════════════════════════════════════════════════════
        // 3. Формируем системный и пользовательский промпт
        // ══════════════════════════════════════════════════════════
        $systemPrompt = $this->buildSystemPrompt($topic, $params);
        $userPrompt   = $this->buildUserPrompt($topic, $params);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[TopicFinder:Rewrite] Start. topic_id=' . $topic->get('topic_id')
            . ', mode=' . $params['mode']
            . ', tone=' . $params['tone']
            . ', lang=' . $params['language']
        );

        // ══════════════════════════════════════════════════════════
        // 4. Отправляем в Yandex GPT
        // ══════════════════════════════════════════════════════════
        // Рассчитываем лимит токенов: ~2 символа на токен для русского текста,
        // добавляем запас для JSON-обёртки (+500), ограничиваем максимумом 8000.
        $maxTokens = min((int)ceil($params['max_length'] / 2) + 500, 8000);

        try {
            $rawText = $this->gptService->sendGPTRequest(
                $systemPrompt,
                $userPrompt,
                'pro',
                [
                    'temperature' => $params['temperature'],
                    'maxTokens'   => $maxTokens,
                ]
            );
        } catch (Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[TopicFinder:Rewrite] GPT error: ' . $e->getMessage()
            );

            return $this->failure(
                'Ошибка Yandex GPT: ' . $e->getMessage()
            );
        }

        // sendGPTRequest возвращает строку; ошибки начинаются с '[Error:'
        if (str_starts_with($rawText, '[Error:')) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[TopicFinder:Rewrite] GPT returned error: ' . $rawText
            );
            return $this->failure($rawText);
        }

        $tokensUsed = 0;
        $modelId    = 'yandexgpt/latest';

        if (empty(trim($rawText))) {
            return $this->failure('Yandex GPT вернул пустой ответ');
        }

        // ══════════════════════════════════════════════════════════
        // 5. Парсим ответ GPT в структурированный формат
        // ══════════════════════════════════════════════════════════
        $rewriteData = $this->parseGptResponse($rawText, $params['mode']);

        // ══════════════════════════════════════════════════════════
        // 6. Сохраняем в metadata темы
        // ══════════════════════════════════════════════════════════
        $savedToTopic = false;
        if ($params['save_to_topic']) {
            $savedToTopic = $this->saveRewriteToTopic($topic, $rewriteData, $params);
        }

        // ══════════════════════════════════════════════════════════
        // 7. Создаём черновик ресурса (опционально)
        // ══════════════════════════════════════════════════════════
        $draftResourceId = null;
        if ($params['create_draft']) {
            $draftResourceId = $this->createDraftResource($topic, $rewriteData, $params);
        }

        // ══════════════════════════════════════════════════════════
        // 8. Обновляем статус темы
        // ══════════════════════════════════════════════════════════
        if ($savedToTopic && $topic->get('status') === SpookyAppTopic::STATUS_NEW) {
            $topic->set('status', SpookyAppTopic::STATUS_APPROVED);
            $topic->save();
        }

        if ($draftResourceId && $topic->get('status') !== SpookyAppTopic::STATUS_PUBLISHED) {
            $topic->set('status', SpookyAppTopic::STATUS_IN_PROGRESS);
            $topic->save();
        }

        // ══════════════════════════════════════════════════════════
        // 9. Формируем ответ
        // ══════════════════════════════════════════════════════════
        $duration = $this->getDuration();

        $originalData = $this->getOriginalData($topic);

        $message = 'Рерайт выполнен успешно';
        if ($draftResourceId) {
            $message .= ', создан черновик #' . $draftResourceId;
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[TopicFinder:Rewrite] Done in {$duration}s. topic_id="
            . $topic->get('topic_id') . ', tokens=' . $tokensUsed
        );

        return $this->success($message, [
            'topic_id' => $topic->get('topic_id'),
            'mode'     => $params['mode'],
            'original' => $originalData,
            'rewrite'  => $rewriteData,
            'stats'    => [
                'original_length' => mb_strlen(
                    ($originalData['title'] ?? '') . ' ' . ($originalData['description'] ?? '')
                ),
                'rewrite_length'  => mb_strlen($rewriteData['content'] ?? $rewriteData['title'] ?? ''),
                'tokens_used'     => $tokensUsed,
                'model'           => $modelId,
                'duration_sec'    => $duration,
                'cached'          => false,
            ],
            'draft_resource_id' => $draftResourceId,
            'saved_to_topic'    => $savedToTopic,
        ]);
    }

    // ═════════════════════════════════════════════════════════════
    // Загрузка темы
    // ═════════════════════════════════════════════════════════════

    /**
     * Загрузить тему из БД по id или topic_id.
     *
     * @return SpookyAppTopic|null
     */
    private function loadTopic(): ?SpookyAppTopic
    {
        $id      = (int)$this->getProperty('id', 0);
        $topicId = trim((string)$this->getProperty('topic_id', ''));

        if ($id > 0) {
            /** @var SpookyAppTopic|null $topic */
            $topic = $this->modx->getObject(SpookyAppTopic::class, $id);
            if ($topic) {
                return $topic;
            }
        }

        if (!empty($topicId)) {
            /** @var SpookyAppTopic|null $topic */
            $topic = $this->modx->getObject(SpookyAppTopic::class, ['topic_id' => $topicId]);
            if ($topic) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * Получить оригинальные данные темы.
     *
     * @param SpookyAppTopic $topic
     * @return array
     */
    private function getOriginalData(SpookyAppTopic $topic): array
    {
        return [
            'title'       => (string)$topic->get('title'),
            'description' => (string)$topic->get('description'),
            'source'      => (string)$topic->get('source'),
            'category'    => (string)$topic->get('category'),
            'url'         => (string)$topic->get('url'),
            'score'       => (float)$topic->get('score'),
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // Парсинг параметров
    // ═════════════════════════════════════════════════════════════

    /**
     * Разобрать и валидировать параметры.
     *
     * @return array
     */
    private function parseParams(): array
    {
        // Mode
        $mode = mb_strtolower(trim((string)$this->getProperty('mode', self::MODE_ARTICLE)));
        if (!in_array($mode, self::ALLOWED_MODES, true)) {
            $mode = self::MODE_ARTICLE;
        }

        // Tone
        $tone = mb_strtolower(trim((string)$this->getProperty('tone', 'neutral')));
        if (!in_array($tone, self::ALLOWED_TONES, true)) {
            $tone = 'neutral';
        }

        // Language
        $language = mb_strtolower(trim((string)$this->getProperty('language', 'ru')));
        if (!in_array($language, self::ALLOWED_LANGUAGES, true)) {
            $language = 'ru';
        }

        // Lengths
        $minLength = (int)$this->getProperty('min_length', self::DEFAULT_MIN_LENGTH);
        $minLength = max(self::MIN_LENGTH_MIN, min(self::MIN_LENGTH_MAX, $minLength));

        $maxLength = (int)$this->getProperty('max_length', self::DEFAULT_MAX_LENGTH);
        $maxLength = max(self::MAX_LENGTH_MIN, min(self::MAX_LENGTH_MAX, $maxLength));

        if ($maxLength < $minLength) {
            $maxLength = $minLength * 3;
        }

        // Temperature
        $temperature = (float)$this->getProperty('temperature', self::DEFAULT_TEMPERATURE);
        $temperature = max(self::TEMPERATURE_MIN, min(self::TEMPERATURE_MAX, $temperature));

        // Booleans
        $saveToTopic = $this->toBool($this->getProperty('save_to_topic', true));
        $createDraft = $this->toBool($this->getProperty('create_draft', false));
        $force       = $this->toBool($this->getProperty('force', false));

        // Draft params
        $parentId = max(0, (int)$this->getProperty('parent_id', 0));
        $templateId = (int)$this->getProperty(
            'template_id',
            $this->modx->getOption('spookyapp.default_template', null, 0)
        );

        // Custom prompt
        $prompt = trim((string)$this->getProperty('prompt', ''));

        return [
            'mode'          => $mode,
            'tone'          => $tone,
            'language'      => $language,
            'min_length'    => $minLength,
            'max_length'    => $maxLength,
            'temperature'   => $temperature,
            'save_to_topic' => $saveToTopic,
            'create_draft'  => $createDraft,
            'force'         => $force,
            'parent_id'     => $parentId,
            'template_id'   => $templateId,
            'prompt'        => $prompt,
        ];
    }

    /**
     * Преобразовать значение в bool.
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
    // Проверка существующего рерайта
    // ═════════════════════════════════════════════════════════════

    /**
     * Проверить, есть ли уже готовый рерайт в metadata темы.
     *
     * @param SpookyAppTopic $topic
     * @param string         $mode
     * @return array|null Данные рерайта или null
     */
    private function getExistingRewrite(SpookyAppTopic $topic, string $mode): ?array
    {
        $metadata = $topic->getMetadataArray();
        $rewrites = $metadata['_rewrites'] ?? [];

        if (isset($rewrites[$mode]) && !empty($rewrites[$mode]['content'] ?? $rewrites[$mode]['title'] ?? '')) {
            return $rewrites[$mode];
        }

        return null;
    }

    // ═════════════════════════════════════════════════════════════
    // Формирование промптов
    // ═════════════════════════════════════════════════════════════

    /**
     * Системный промпт для Yandex GPT.
     *
     * Определяет роль, стиль и формат ответа.
     *
     * @param SpookyAppTopic $topic
     * @param array          $params
     * @return string
     */
    private function buildSystemPrompt(SpookyAppTopic $topic, array $params): string
    {
        $lang = $params['language'] === 'en' ? 'английском' : 'русском';
        $toneInstruction = self::TONE_INSTRUCTIONS[$params['tone']] ?? self::TONE_INSTRUCTIONS['neutral'];
        $category = $topic->get('category');
        $source = $topic->get('source');
        $sourceLabel = self::SOURCE_LABELS[$source] ?? ucfirst($source);

        $categoryContext = $this->getCategoryContext($category);

        $base = "Ты — опытный редактор и копирайтер для информационного сайта. "
            . "Специализация: {$category}. "
            . "{$categoryContext} "
            . "{$toneInstruction} "
            . "Пиши на {$lang} языке. "
            . "Текст должен быть уникальным, не копируй исходный материал дословно. "
            . "Добавляй ценность: контекст, аналитику, интересные факты. "
            . "Не упоминай себя как ИИ. Не используй фразы вроде «в данной статье», «рассмотрим». ";

        // Дополнительные инструкции в зависимости от режима
        switch ($params['mode']) {
            case self::MODE_ARTICLE:
                $base .= "\n\nФормат ответа — строго JSON:\n"
                    . "{\n"
                    . '  "title": "Уникальный заголовок статьи",' . "\n"
                    . '  "lead": "Лид-абзац (1-2 предложения, интрига)",' . "\n"
                    . '  "content": "Полный текст статьи с HTML-разметкой (<h2>, <h3>, <p>, <ul>, <li>, <strong>). '
                    . "Минимум {$params['min_length']} символов, максимум {$params['max_length']} символов.\"," . "\n"
                    . '  "meta_description": "SEO description до 160 символов",' . "\n"
                    . '  "meta_keywords": "ключевое, слово, фраза",' . "\n"
                    . '  "tags": ["тег1", "тег2", "тег3"]' . "\n"
                    . "}";
                break;

            case self::MODE_NEWS:
                $base .= "\n\nФормат ответа — строго JSON:\n"
                    . "{\n"
                    . '  "title": "Цепляющий заголовок новости",' . "\n"
                    . '  "lead": "Лид (кто, что, где, когда — 1 предложение)",' . "\n"
                    . '  "content": "Текст новости (2-3 абзаца, HTML: <p>). Коротко и по делу.",' . "\n"
                    . '  "meta_description": "SEO description до 160 символов",' . "\n"
                    . '  "tags": ["тег1", "тег2"]' . "\n"
                    . "}";
                break;

            case self::MODE_SOCIAL:
                $base .= "\n\nФормат ответа — строго JSON:\n"
                    . "{\n"
                    . '  "title": "Короткий заголовок",' . "\n"
                    . '  "content": "Текст поста для соцсетей (до 500 символов). Используй эмодзи. Добавь призыв к действию.",' . "\n"
                    . '  "hashtags": ["#хештег1", "#хештег2", "#хештег3"]' . "\n"
                    . "}";
                break;

            case self::MODE_SEO:
                $base .= "\n\nФормат ответа — строго JSON:\n"
                    . "{\n"
                    . '  "title": "SEO-заголовок (60-70 символов, с ключевым словом)",' . "\n"
                    . '  "h1": "H1 заголовок (может отличаться от title)",' . "\n"
                    . '  "meta_description": "Meta description (150-160 символов)",' . "\n"
                    . '  "meta_keywords": "ключевое, слово, ключевая фраза",' . "\n"
                    . '  "content": "SEO-оптимизированный текст с подзаголовками (<h2>, <h3>). '
                    . "Минимум {$params['min_length']} символов.\"," . "\n"
                    . '  "tags": ["тег1", "тег2", "тег3"]' . "\n"
                    . "}";
                break;

            case self::MODE_TITLE:
                $base .= "\n\nФормат ответа — строго JSON:\n"
                    . "{\n"
                    . '  "titles": [' . "\n"
                    . '    "Вариант 1: информационный",' . "\n"
                    . '    "Вариант 2: интригующий",' . "\n"
                    . '    "Вариант 3: SEO-оптимизированный",' . "\n"
                    . '    "Вариант 4: для соцсетей",' . "\n"
                    . '    "Вариант 5: кликбейтный (но не ложный)"' . "\n"
                    . "  ]\n"
                    . "}";
                break;

            case self::MODE_CUSTOM:
                $base .= "\n\nОтветь строго в формате JSON. "
                    . "Структура зависит от запроса пользователя, но обязательно включи поле \"content\".";
                break;
        }

        return $base;
    }

    /**
     * Пользовательский промпт (контент темы).
     *
     * @param SpookyAppTopic $topic
     * @param array          $params
     * @return string
     */
    private function buildUserPrompt(SpookyAppTopic $topic, array $params): string
    {
        $title       = (string)$topic->get('title');
        $description = (string)$topic->get('description');
        $source      = (string)$topic->get('source');
        $sourceLabel = self::SOURCE_LABELS[$source] ?? ucfirst($source);
        $category    = (string)$topic->get('category');
        $url         = (string)$topic->get('url');
        $metadata    = $topic->getMetadataArray();

        // Для custom-режима — подставляем плейсхолдеры
        if ($params['mode'] === self::MODE_CUSTOM && !empty($params['prompt'])) {
            return strtr($params['prompt'], [
                '{title}'       => $title,
                '{description}' => $description,
                '{source}'      => $sourceLabel,
                '{category}'    => $category,
                '{url}'         => $url,
            ]);
        }

        // Собираем контекст
        $parts = [];
        $parts[] = "Заголовок: {$title}";

        if (!empty($description)) {
            $parts[] = "Описание: {$description}";
        }

        $parts[] = "Источник: {$sourceLabel}";
        $parts[] = "Категория: {$category}";

        if (!empty($url)) {
            $parts[] = "Ссылка: {$url}";
        }

        // Дополнительные данные из metadata
        $extraContext = $this->extractMetadataContext($metadata, $source);
        if (!empty($extraContext)) {
            $parts[] = "Дополнительно: {$extraContext}";
        }

        $contextBlock = implode("\n", $parts);

        // Инструкция в зависимости от режима
        switch ($params['mode']) {
            case self::MODE_ARTICLE:
                $instruction = "Напиши полноценную статью на основе этой темы. "
                    . "Добавь аналитику, контекст, интересные факты. "
                    . "Объём: {$params['min_length']}–{$params['max_length']} символов.";
                break;

            case self::MODE_NEWS:
                $instruction = "Напиши короткую новость. 2-3 абзаца. "
                    . "Первый абзац — ответ на вопросы: кто, что, где, когда.";
                break;

            case self::MODE_SOCIAL:
                $instruction = "Напиши пост для социальных сетей. Кратко, ёмко, с эмодзи.";
                break;

            case self::MODE_SEO:
                $instruction = "Создай полный SEO-пакет: title, description, keywords и текст. "
                    . "Текст должен быть оптимизирован под поисковые запросы по этой теме.";
                break;

            case self::MODE_TITLE:
                $instruction = "Придумай 5 вариантов заголовков: "
                    . "информационный, интригующий, SEO, для соцсетей, кликбейтный.";
                break;

            default:
                $instruction = "Обработай эту тему.";
        }

        return "{$instruction}\n\n--- ИСХОДНЫЕ ДАННЫЕ ---\n{$contextBlock}";
    }

    /**
     * Контекст категории для системного промпта.
     *
     * @param string $category
     * @return string
     */
    private function getCategoryContext(string $category): string
    {
        $contexts = [
            'IT'       => 'Ты разбираешься в IT, программировании, стартапах, технологических трендах.',
            'Gadgets'  => 'Ты эксперт по гаджетам, смартфонам, ноутбукам, носимой электронике.',
            'Games'    => 'Ты игровой журналист. Разбираешься в играх, геймдизайне, индустрии.',
            'Cinema'   => 'Ты кинокритик. Разбираешься в кино, сериалах, анимации, стриминг-индустрии.',
            'Sports'   => 'Ты спортивный журналист. Разбираешься в различных видах спорта, турнирах, спортсменах.',
            'Science'  => 'Ты научный журналист. Объясняешь сложные вещи простым языком.',
        ];

        return $contexts[$category] ?? 'Ты универсальный журналист.';
    }

    /**
     * Извлечь дополнительный контекст из metadata.
     *
     * @param array  $metadata
     * @param string $source
     * @return string
     */
    private function extractMetadataContext(array $metadata, string $source): string
    {
        $parts = [];

        // Общие поля
        if (!empty($metadata['vote_average'])) {
            $parts[] = "Рейтинг: {$metadata['vote_average']}/10";
        }
        if (!empty($metadata['popularity'])) {
            $parts[] = "Популярность: {$metadata['popularity']}";
        }

        // Специфичные для источников
        switch ($source) {
            case 'tmdb':
                if (!empty($metadata['genres'])) {
                    $genres = is_array($metadata['genres'])
                        ? implode(', ', $metadata['genres'])
                        : (string)$metadata['genres'];
                    $parts[] = "Жанры: {$genres}";
                }
                if (!empty($metadata['release_date'])) {
                    $parts[] = "Дата выхода: {$metadata['release_date']}";
                }
                break;

            case 'rawg':
                if (!empty($metadata['platforms'])) {
                    $platforms = is_array($metadata['platforms'])
                        ? implode(', ', $metadata['platforms'])
                        : (string)$metadata['platforms'];
                    $parts[] = "Платформы: {$platforms}";
                }
                if (!empty($metadata['genres'])) {
                    $genres = is_array($metadata['genres'])
                        ? implode(', ', $metadata['genres'])
                        : (string)$metadata['genres'];
                    $parts[] = "Жанры: {$genres}";
                }
                break;

            case 'github':
                if (!empty($metadata['stars'])) {
                    $parts[] = "GitHub Stars: {$metadata['stars']}";
                }
                if (!empty($metadata['language'])) {
                    $parts[] = "Язык: {$metadata['language']}";
                }
                break;

            case 'reddit':
                if (!empty($metadata['upvotes'])) {
                    $parts[] = "Upvotes: {$metadata['upvotes']}";
                }
                if (!empty($metadata['subreddit'])) {
                    $parts[] = "Subreddit: r/{$metadata['subreddit']}";
                }
                break;

            case 'flashlive':
            case 'ibu':
            case 'apifootball':
                if (!empty($metadata['teams'])) {
                    $teams = is_array($metadata['teams'])
                        ? implode(' vs ', $metadata['teams'])
                        : (string)$metadata['teams'];
                    $parts[] = "Участники: {$teams}";
                }
                if (!empty($metadata['league'])) {
                    $parts[] = "Лига: {$metadata['league']}";
                }
                if (!empty($metadata['score_result'])) {
                    $parts[] = "Счёт: {$metadata['score_result']}";
                }
                break;

            case 'mobileapi':
                if (!empty($metadata['brand'])) {
                    $parts[] = "Бренд: {$metadata['brand']}";
                }
                if (!empty($metadata['specs'])) {
                    $specs = is_array($metadata['specs'])
                        ? implode(', ', $metadata['specs'])
                        : (string)$metadata['specs'];
                    $parts[] = "Характеристики: {$specs}";
                }
                break;
        }

        return implode('. ', $parts);
    }

    // ═════════════════════════════════════════════════════════════
    // Парсинг ответа GPT
    // ═════════════════════════════════════════════════════════════

    /**
     * Распарсить ответ GPT в структурированный массив.
     *
     * GPT должен вернуть JSON, но иногда оборачивает его в ```json...```.
     * Обрабатываем оба случая. Если JSON невалидный — пытаемся извлечь текст.
     *
     * @param string $rawText Сырой ответ от GPT
     * @param string $mode    Режим рерайта
     * @return array
     */
    private function parseGptResponse(string $rawText, string $mode): array
    {
        // Убираем markdown code fences если есть
        $cleaned = $rawText;
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $cleaned, $m)) {
            $cleaned = $m[1];
        }

        $cleaned = trim($cleaned);

        // Пробуем декодировать JSON
        $decoded = json_decode($cleaned, true);

        if (is_array($decoded)) {
            // Успешный JSON — нормализуем структуру
            return $this->normalizeRewriteData($decoded, $mode);
        }

        // JSON невалидный — пробуем извлечь JSON из текста
        if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $rawText, $jsonMatch)) {
            $decoded = json_decode($jsonMatch[0], true);
            if (is_array($decoded)) {
                return $this->normalizeRewriteData($decoded, $mode);
            }
        }

        // Не удалось распарсить JSON — возвращаем как plain text
        $this->modx->log(
            modX::LOG_LEVEL_WARN,
            '[TopicFinder:Rewrite] GPT response is not valid JSON, treating as plain text'
        );

        return [
            'title'            => '',
            'lead'             => '',
            'content'          => $rawText,
            'meta_description' => '',
            'meta_keywords'    => '',
            'tags'             => [],
            '_raw'             => true,
        ];
    }

    /**
     * Нормализовать данные рерайта (привести к единой структуре).
     *
     * @param array  $data
     * @param string $mode
     * @return array
     */
    private function normalizeRewriteData(array $data, string $mode): array
    {
        $result = [
            'title'            => (string)($data['title'] ?? $data['h1'] ?? ''),
            'lead'             => (string)($data['lead'] ?? ''),
            'content'          => (string)($data['content'] ?? $data['text'] ?? ''),
            'meta_description' => (string)($data['meta_description'] ?? $data['description'] ?? ''),
            'meta_keywords'    => (string)($data['meta_keywords'] ?? $data['keywords'] ?? ''),
            'tags'             => [],
        ];

        // Tags
        if (isset($data['tags']) && is_array($data['tags'])) {
            $result['tags'] = array_values(array_filter(
                array_map('trim', $data['tags'])
            ));
        }

        // Режим title — отдельная обработка
        if ($mode === self::MODE_TITLE && isset($data['titles']) && is_array($data['titles'])) {
            $result['titles'] = array_values(array_filter(
                array_map('trim', $data['titles'])
            ));
            // Первый вариант — в title
            if (!empty($result['titles']) && empty($result['title'])) {
                $result['title'] = $result['titles'][0];
            }
        }

        // Режим social — hashtags
        if ($mode === self::MODE_SOCIAL && isset($data['hashtags']) && is_array($data['hashtags'])) {
            $result['hashtags'] = array_values(array_filter(
                array_map('trim', $data['hashtags'])
            ));
        }

        // H1 для SEO
        if ($mode === self::MODE_SEO && !empty($data['h1'])) {
            $result['h1'] = (string)$data['h1'];
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // Сохранение рерайта в metadata темы
    // ═════════════════════════════════════════════════════════════

    /**
     * Сохранить результат рерайта в metadata темы.
     *
     * Хранит в metadata._rewrites[mode] = { ...rewriteData, _generated_at, _params }
     *
     * @param SpookyAppTopic $topic
     * @param array          $rewriteData
     * @param array          $params
     * @return bool
     */
    private function saveRewriteToTopic(SpookyAppTopic $topic, array $rewriteData, array $params): bool
    {
        $metadata = $topic->getMetadataArray();
        $now = date('Y-m-d H:i:s');

        // Инициализируем _rewrites если нет
        if (!isset($metadata['_rewrites'])) {
            $metadata['_rewrites'] = [];
        }

        // Сохраняем рерайт с метаданными генерации
        $metadata['_rewrites'][$params['mode']] = array_merge($rewriteData, [
            '_generated_at' => $now,
            '_params'       => [
                'mode'        => $params['mode'],
                'tone'        => $params['tone'],
                'language'    => $params['language'],
                'temperature' => $params['temperature'],
            ],
        ]);

        // Счётчик рерайтов
        $metadata['_rewrite_count'] = ($metadata['_rewrite_count'] ?? 0) + 1;
        $metadata['_last_rewrite'] = $now;

        $topic->set('metadata', json_encode($metadata, JSON_UNESCAPED_UNICODE));
        $topic->set('updated_at', $now);

        return $topic->save();
    }

    // ═════════════════════════════════════════════════════════════
    // Создание черновика ресурса MODX
    // ═════════════════════════════════════════════════════════════

    /**
     * Создать черновик (неопубликованный ресурс) в MODX.
     *
     * @param SpookyAppTopic $topic
     * @param array          $rewriteData
     * @param array          $params
     * @return int|null ID ресурса или null при ошибке
     */
    private function createDraftResource(SpookyAppTopic $topic, array $rewriteData, array $params): ?int
    {
        $title   = $rewriteData['title'] ?: $topic->get('title');
        $content = $rewriteData['content'] ?? '';
        $lead    = $rewriteData['lead'] ?? '';

        if (empty($content) && empty($title)) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[TopicFinder:Rewrite] Skipping draft creation: empty content and title'
            );
            return null;
        }

        // Генерируем alias
        $alias = $this->generateAlias($title);

        // Проверяем, нет ли уже ресурса с таким alias
        $existing = $this->modx->getObject(\MODX\Revolution\modResource::class, ['alias' => $alias]);
        if ($existing) {
            $alias .= '-' . $topic->get('id');
        }

        try {
            /** @var \MODX\Revolution\modResource $resource */
            $resource = $this->modx->newObject(\MODX\Revolution\modResource::class);
            $resource->fromArray([
                'pagetitle'   => $title,
                'longtitle'   => $rewriteData['h1'] ?? $title,
                'alias'       => $alias,
                'description' => $rewriteData['meta_description'] ?? '',
                'introtext'   => $lead,
                'content'     => $content,
                'parent'      => $params['parent_id'],
                'template'    => $params['template_id'],
                'published'   => 0, // Черновик — не опубликован!
                'hidemenu'    => 1,
                'searchable'  => 0,
                'class_key'   => \MODX\Revolution\modDocument::class,
                'richtext'    => 1,
            ]);

            if (!$resource->save()) {
                $this->modx->log(
                    modX::LOG_LEVEL_ERROR,
                    '[TopicFinder:Rewrite] Failed to save draft resource'
                );
                return null;
            }

            $resourceId = (int)$resource->get('id');

            // Сохраняем keywords в TV или в поле ресурса
            if (!empty($rewriteData['meta_keywords'])) {
                // Если есть TV keywords — используем его
                try {
                    $resource->setTVValue('keywords', $rewriteData['meta_keywords']);
                } catch (Throwable $e) {
                    // TV не существует — записываем в description
                    $this->modx->log(
                        modX::LOG_LEVEL_WARN,
                        '[TopicFinder:Rewrite] TV "keywords" not found, skipping'
                    );
                }
            }

            // Сохраняем связь topic → resource в metadata
            $metadata = $topic->getMetadataArray();
            $metadata['_draft_resource_id'] = $resourceId;
            $metadata['_draft_created_at'] = date('Y-m-d H:i:s');
            $topic->set('metadata', json_encode($metadata, JSON_UNESCAPED_UNICODE));
            $topic->save();

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                "[TopicFinder:Rewrite] Created draft resource #{$resourceId} for topic "
                . $topic->get('topic_id')
            );

            return $resourceId;

        } catch (Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[TopicFinder:Rewrite] Draft creation error: ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Сгенерировать alias из заголовка.
     *
     * Транслитерация + slugify.
     *
     * @param string $title
     * @return string
     */
    private function generateAlias(string $title): string
    {
        // Таблица транслитерации
        $translitMap = [
            'а' => 'a',  'б' => 'b',  'в' => 'v',  'г' => 'g',  'д' => 'd',
            'е' => 'e',  'ё' => 'yo', 'ж' => 'zh', 'з' => 'z',  'и' => 'i',
            'й' => 'y',  'к' => 'k',  'л' => 'l',  'м' => 'm',  'н' => 'n',
            'о' => 'o',  'п' => 'p',  'р' => 'r',  'с' => 's',  'т' => 't',
            'у' => 'u',  'ф' => 'f',  'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch','ъ' => '',   'ы' => 'y',  'ь' => '',
            'э' => 'e',  'ю' => 'yu', 'я' => 'ya',
        ];

        $slug = mb_strtolower(trim($title));

        // Транслитерация
        $slug = strtr($slug, $translitMap);

        // Заменяем не-буквенно-цифровые на дефис
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        // Убираем крайние дефисы
        $slug = trim($slug, '-');

        // Ограничиваем длину
        if (mb_strlen($slug) > 100) {
            $slug = mb_substr($slug, 0, 100);
            $slug = rtrim($slug, '-');
        }

        return $slug ?: 'topic-' . time();
    }

    // ═════════════════════════════════════════════════════════════
    // Утилиты
    // ═════════════════════════════════════════════════════════════

    /**
     * Время выполнения процессора.
     *
     * @return float
     */
    private function getDuration(): float
    {
        return round(microtime(true) - $this->startTime, 2);
    }
}

return Rewrite::class;
