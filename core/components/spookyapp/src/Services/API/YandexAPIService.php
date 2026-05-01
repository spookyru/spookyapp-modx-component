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
 * Сервис для работы с Yandex Cloud API.
 *
 * Поддерживаемые сервисы:
 * 1. YandexGPT — генерация и рерайт текстов
 * 2. Yandex Translate — перевод текстов
 * 3. Yandex SpeechKit — синтез речи (TTS)
 *
 * API-ключи и folder_id загружаются из системных настроек MODX.
 */
class YandexAPIService extends APIService
{
    // ─── Системные настройки MODX ────────────────────────────────
    private const SETTING_API_KEY   = 'spookyapp.yandex_api_key';
    private const SETTING_FOLDER_ID = 'spookyapp.yandex_folder_id';

    // ─── YandexGPT ──────────────────────────────────────────────
    private const GPT_ENDPOINT = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';

    /** @var array<string, string> Маппинг режимов на URI модели */
    private const GPT_MODELS = [
        'pro'  => 'yandexgpt/latest',
        'lite' => 'yandexgpt-lite/latest',
    ];

    private const GPT_DEFAULT_TEMPERATURE = 0.1;
    private const GPT_DEFAULT_MAX_TOKENS  = 1000;

    // ─── Yandex Translate ────────────────────────────────────────
    private const TRANSLATE_ENDPOINT = 'https://translate.api.cloud.yandex.net/translate/v2/translate';
    private const TTL_TRANSLATE      = 2592000; // 30 дней

    // ─── Yandex SpeechKit ────────────────────────────────────────
    private const TTS_ENDPOINT = 'https://tts.api.cloud.yandex.net/speech/v1/tts:synthesize';

    /** @var array<string, string> Допустимые голоса */
    private const TTS_VOICES = [
        'jane'  => 'jane',   // female
        'zahar' => 'zahar',  // male
        'alena' => 'alena',  // female
        'filipp' => 'filipp', // male
    ];

    private const TTS_DEFAULT_SPEED   = '1.2';
    private const TTS_DEFAULT_EMOTION = 'good';
    private const TTS_DEFAULT_FORMAT  = 'oggopus';

    /** Базовая директория для аудио-файлов (относительно MODX_BASE_PATH) */
    private const AUDIO_BASE_DIR = 'assets/services/audio';

    // ─── System prompts ──────────────────────────────────────────
    private const PROMPT_REWRITE_BLOG = 'Ты — редактор IT блога. Перепиши текст в стиле понятного блог-поста. Сохрани все факты и техническую точность, но сделай текст живым, интересным и легко читаемым. Используй короткие абзацы. Не добавляй информацию, которой нет в оригинале.';

    private const PROMPT_REWRITE_NEWS = 'Ты — новостной редактор. Перепиши текст как краткую, информативную новостную заметку. Только факты, без воды. Максимум 3-4 абзаца.';

    private const PROMPT_REWRITE_SOCIAL = 'Ты — SMM-специалист. Перепиши текст для поста в социальных сетях. Коротко, цепляюще, с эмоджи где уместно. Максимум 280 символов.';

    /** @var array<string, string> Маппинг стилей рерайта на system prompts */
    private const REWRITE_STYLES = [
        'blog'   => self::PROMPT_REWRITE_BLOG,
        'news'   => self::PROMPT_REWRITE_NEWS,
        'social' => self::PROMPT_REWRITE_SOCIAL,
    ];

    private const PROMPT_TOPIC_IDEAS = <<<'PROMPT'
Ты — контент-стратег для IT-блога о теме "%s".

На основе текущих трендов и популярных тем, предложи идеи для статей блога.
Учитывай что аудитория — русскоязычные разработчики и IT-специалисты.

Верни ответ СТРОГО в формате JSON массива строк, без дополнительного текста:
["Идея 1", "Идея 2", "Идея 3"]

Текущие тренды:
%s
PROMPT;

    // ─── Транслитерация ──────────────────────────────────────────
    private const TRANSLIT_MAP = [
        'а' => 'a',  'б' => 'b',  'в' => 'v',  'г' => 'g',  'д' => 'd',
        'е' => 'e',  'ё' => 'yo', 'ж' => 'zh', 'з' => 'z',  'и' => 'i',
        'й' => 'y',  'к' => 'k',  'л' => 'l',  'м' => 'm',  'н' => 'n',
        'о' => 'o',  'п' => 'p',  'р' => 'r',  'с' => 's',  'т' => 't',
        'у' => 'u',  'ф' => 'f',  'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'shch', 'ъ' => '',  'ы' => 'y',  'ь' => '',
        'э' => 'e',  'ю' => 'yu', 'я' => 'ya',
    ];

    public function __construct(modX $modx, CacheService $cache)
    {
        parent::__construct($modx, $cache);
    }

    // ═════════════════════════════════════════════════════════════
    // 1. YandexGPT — генерация текста
    // ═════════════════════════════════════════════════════════════

    /**
     * Отправить запрос к YandexGPT.
     *
     * Синхронный запрос к completion API. Каждый запрос уникален — кеширование не применяется.
     *
     * @param string               $systemPrompt Системный промпт (роль/инструкция)
     * @param string               $userText     Текст пользователя
     * @param string               $mode         Режим модели: 'pro' или 'lite'
     * @param array<string, mixed> $options      Доп. параметры: temperature, maxTokens
     * @return string Текст ответа от GPT или строка с ошибкой
     */
    public function sendGPTRequest(
        string $systemPrompt,
        string $userText,
        string $mode = 'pro',
        array $options = []
    ): string {
        $folderId = $this->getFolderId();
        $apiKey = $this->getApiKey();

        if ($folderId === null || $apiKey === null) {
            return '[Error: Yandex API credentials not configured]';
        }

        // Валидация режима
        if (!isset(self::GPT_MODELS[$mode])) {
            $this->modx->log(modX::LOG_LEVEL_WARN, "[YandexAPI] Неизвестный режим GPT: {$mode}, используем 'pro'");
            $mode = 'pro';
        }

        $modelUri = "gpt://{$folderId}/" . self::GPT_MODELS[$mode];

        $temperature = (float)($options['temperature'] ?? self::GPT_DEFAULT_TEMPERATURE);
        $maxTokens = (int)($options['maxTokens'] ?? self::GPT_DEFAULT_MAX_TOKENS);

        $body = [
            'modelUri' => $modelUri,
            'completionOptions' => [
                'stream'      => false,
                'temperature' => $temperature,
                'maxTokens'   => (string)$maxTokens,
            ],
            'messages' => [
                [
                    'role' => 'system',
                    'text' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'text' => $userText,
                ],
            ],
        ];

        $headers = $this->getAuthHeaders($apiKey, $folderId);

        try {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                "[YandexAPI] GPT запрос: mode={$mode}, tokens={$maxTokens}, temp={$temperature}, text_len=" . mb_strlen($userText)
            );

            $response = $this->httpPostJson(self::GPT_ENDPOINT, $headers, $body, 60);

            if (!$response['success']) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[YandexAPI] GPT ошибка: {$response['error']}");
                return "[Error: GPT request failed — {$response['error']}]";
            }

            // Извлекаем текст из ответа
            $text = $this->extractGPTText($response['data']);
            if ($text === null) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[YandexAPI] GPT: не удалось извлечь текст из ответа');
                return '[Error: GPT response parsing failed]';
            }

            $this->modx->log(modX::LOG_LEVEL_DEBUG, '[YandexAPI] GPT ответ получен, длина: ' . mb_strlen($text));

            return $text;
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[YandexAPI] GPT исключение: {$e->getMessage()}");
            return "[Error: {$e->getMessage()}]";
        }
    }

    // ═════════════════════════════════════════════════════════════
    // 2. Рерайт текста через GPT
    // ═════════════════════════════════════════════════════════════

    /**
     * Переписать текст в заданном стиле через YandexGPT.
     *
     * @param string $text  Исходный текст для рерайта
     * @param string $style Стиль: 'blog', 'news', 'social'
     * @return string Переписанный текст или исходный с ошибкой
     */
    public function rewriteText(string $text, string $style = 'blog'): string
    {
        if (empty(trim($text))) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[YandexAPI] rewriteText: пустой текст');
            return $text;
        }

        $systemPrompt = self::REWRITE_STYLES[$style] ?? self::REWRITE_STYLES['blog'];

        $this->modx->log(modX::LOG_LEVEL_INFO, "[YandexAPI] Рерайт текста, стиль: {$style}, длина: " . mb_strlen($text));

        $result = $this->sendGPTRequest($systemPrompt, $text, 'pro', [
            'temperature' => 0.3,
            'maxTokens'   => 2000,
        ]);

        // Если GPT вернул ошибку, возвращаем оригинал
        if (str_starts_with($result, '[Error:')) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[YandexAPI] Рерайт не удался: {$result}");
            return $text;
        }

        return $result;
    }

    // ═════════════════════════════════════════════════════════════
    // 3. Генерация идей для статей
    // ═════════════════════════════════════════════════════════════

    /**
     * Сгенерировать идеи статей на основе трендов.
     *
     * @param array<int, string> $trends   Список текущих трендов/тем
     * @param string             $blogTheme Тематика блога (напр. "веб-разработка")
     * @return array<int, string> Массив идей или пустой массив при ошибке
     */
    public function generateTopicIdeas(array $trends, string $blogTheme): array
    {
        if (empty($trends)) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[YandexAPI] generateTopicIdeas: пустой список трендов');
            return [];
        }

        $trendsText = implode("\n", array_map(function (string $trend, int $i): string {
            return ($i + 1) . '. ' . $trend;
        }, $trends, array_keys($trends)));

        $systemPrompt = sprintf(self::PROMPT_TOPIC_IDEAS, $blogTheme, $trendsText);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[YandexAPI] Генерация идей: тема='{$blogTheme}', трендов=" . count($trends)
        );

        $result = $this->sendGPTRequest(
            $systemPrompt,
            "Предложи 5-10 идей для статей блога на основе этих трендов.",
            'pro',
            [
                'temperature' => 0.7,
                'maxTokens'   => 1500,
            ]
        );

        if (str_starts_with($result, '[Error:')) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[YandexAPI] Генерация идей не удалась: {$result}");
            return [];
        }

        return $this->parseGPTJsonArray($result);
    }

    // ═════════════════════════════════════════════════════════════
    // 4. Yandex Translate
    // ═════════════════════════════════════════════════════════════

    /**
     * Перевести текст через Yandex Translate API.
     *
     * Поддерживает одиночный текст и массив текстов (batch).
     * Переводы кешируются на 30 дней.
     *
     * @param string|array<int, string> $text       Текст(ы) для перевода
     * @param string                    $sourceLang Язык источника (по умолчанию 'en')
     * @param string                    $targetLang Целевой язык (по умолчанию 'ru')
     * @return string|array<int, string> Переведённый текст(ы) или оригинал при ошибке
     */
    public function translate(
        string|array $text,
        string $sourceLang = 'en',
        string $targetLang = 'ru'
    ): string|array {
        $isBatch = is_array($text);
        $texts = $isBatch ? $text : [$text];

        // Фильтруем пустые строки
        $texts = array_filter($texts, function (string $t): bool {
            return !empty(trim($t));
        });

        if (empty($texts)) {
            return $isBatch ? [] : '';
        }

        // Ключ кеша на основе текстов и языков
        $cacheKey = 'yandex_translate_' . md5(implode('|', $texts) . "_{$sourceLang}_{$targetLang}");

        $result = $this->cachedRequest($cacheKey, self::TTL_TRANSLATE, function () use ($texts, $sourceLang, $targetLang): ?array {
            return $this->executeTranslateRequest(array_values($texts), $sourceLang, $targetLang);
        });

        if ($result === null) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[YandexAPI] Translate: ошибка, возвращаем оригинал');
            return $text;
        }

        return $isBatch ? $result : ($result[0] ?? (is_string($text) ? $text : ''));
    }

    // ═════════════════════════════════════════════════════════════
    // 5. Yandex SpeechKit — синтез речи
    // ═════════════════════════════════════════════════════════════

    /**
     * Синтезировать речь из текста через Yandex SpeechKit.
     *
     * Аудиофайл сохраняется в assets/services/audio/{year}/{filename}.ogg.
     * Если файл уже существует, повторный запрос не выполняется.
     *
     * @param string               $text    Текст для озвучки (макс. ~5000 символов)
     * @param string               $voice   Голос: 'jane' (жен.), 'zahar' (муж.), 'alena', 'filipp'
     * @param array<string, mixed> $options Доп. параметры: speed, emotion, format
     * @return string|null Относительный путь к аудиофайлу или null при ошибке
     */
    public function synthesizeSpeech(
        string $text,
        string $voice = 'jane',
        array $options = []
    ): ?string {
        if (empty(trim($text))) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[YandexAPI] SpeechKit: пустой текст');
            return null;
        }

        $apiKey = $this->getApiKey();
        $folderId = $this->getFolderId();
        if ($apiKey === null || $folderId === null) {
            return null;
        }

        // Валидация голоса
        $voice = self::TTS_VOICES[$voice] ?? self::TTS_VOICES['jane'];

        $speed = (string)($options['speed'] ?? self::TTS_DEFAULT_SPEED);
        $emotion = (string)($options['emotion'] ?? self::TTS_DEFAULT_EMOTION);
        $format = (string)($options['format'] ?? self::TTS_DEFAULT_FORMAT);

        // Генерируем путь к файлу
        $filename = $this->getVoiceFilename($text);
        $year = date('Y');
        $relativePath = self::AUDIO_BASE_DIR . "/{$year}/{$filename}.ogg";
        $absolutePath = MODX_BASE_PATH . $relativePath;

        // Если файл уже существует — возвращаем путь
        if (file_exists($absolutePath)) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, "[YandexAPI] SpeechKit: файл уже существует: {$relativePath}");
            return $relativePath;
        }

        // Создаём директорию
        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[YandexAPI] SpeechKit: не удалось создать директорию: {$dir}");
            return null;
        }

        // Подготовка параметров запроса
        $params = [
            'text'      => $text,
            'lang'      => 'ru-RU',
            'voice'     => $voice,
            'speed'     => $speed,
            'emotion'   => $emotion,
            'format'    => $format,
            'folderId'  => $folderId,
        ];

        $headers = [
            'Authorization: Api-Key ' . $apiKey,
        ];

        try {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                "[YandexAPI] SpeechKit: voice={$voice}, speed={$speed}, emotion={$emotion}, text_len=" . mb_strlen($text)
            );

            $response = $this->httpPostForm(self::TTS_ENDPOINT, $headers, $params, 30);

            if (!$response['success']) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[YandexAPI] SpeechKit ошибка: {$response['error']}");
                return null;
            }

            $audioData = $response['raw'] ?? null;
            if (empty($audioData)) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[YandexAPI] SpeechKit: пустой ответ от API');
                return null;
            }

            // Проверяем, не вернулся ли JSON с ошибкой вместо аудио
            if (str_starts_with($audioData, '{')) {
                $errorData = json_decode($audioData, true);
                $errorMsg = $errorData['error_message'] ?? $errorData['message'] ?? 'Unknown error';
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[YandexAPI] SpeechKit API ошибка: {$errorMsg}");
                return null;
            }

            // Сохраняем аудио-файл
            $written = file_put_contents($absolutePath, $audioData);
            if ($written === false) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[YandexAPI] SpeechKit: не удалось записать файл: {$absolutePath}");
                return null;
            }

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                "[YandexAPI] SpeechKit: аудио сохранено в {$relativePath} (" . round($written / 1024, 1) . " KB)"
            );

            return $relativePath;
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[YandexAPI] SpeechKit исключение: {$e->getMessage()}");
            return null;
        }
    }

    // ═════════════════════════════════════════════════════════════
    // 6. Генерация имени файла из текста
    // ═════════════════════════════════════════════════════════════

    /**
     * Сгенерировать имя аудиофайла из текста.
     *
     * Транслитерирует кириллицу, очищает спецсимволы, обрезает длину.
     * Добавляет hash для уникальности.
     *
     * @param string $text Исходный текст
     * @return string Имя файла без расширения
     */
    public function getVoiceFilename(string $text): string
    {
        // Берём первые 50 символов для читаемости
        $snippet = mb_substr(trim($text), 0, 50);

        // Транслитерация
        $transliterated = $this->transliterate($snippet);

        // Очистка: только буквы, цифры, дефис
        $clean = preg_replace('/[^a-z0-9\-]/', '-', $transliterated) ?? '';
        $clean = preg_replace('/-{2,}/', '-', $clean) ?? '';
        $clean = trim($clean, '-');

        // Если после очистки пусто
        if (empty($clean)) {
            $clean = 'voice';
        }

        // Обрезаем до 40 символов
        $clean = substr($clean, 0, 40);

        // Добавляем короткий hash от полного текста для уникальности
        $hash = substr(md5($text), 0, 8);

        return "{$clean}-{$hash}";
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные методы: авторизация
    // ═════════════════════════════════════════════════════════════

    /**
     * Получить API-ключ из системных настроек.
     *
     * @return string|null
     */
    private function getApiKey(): ?string
    {
        $key = $this->modx->getOption(self::SETTING_API_KEY, null, '');
        if (empty($key)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[YandexAPI] Системная настройка '" . self::SETTING_API_KEY . "' не задана");
            return null;
        }
        return $key;
    }

    /**
     * Получить Folder ID из системных настроек.
     *
     * @return string|null
     */
    private function getFolderId(): ?string
    {
        $id = $this->modx->getOption(self::SETTING_FOLDER_ID, null, '');
        if (empty($id)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[YandexAPI] Системная настройка '" . self::SETTING_FOLDER_ID . "' не задана");
            return null;
        }
        return $id;
    }

    /**
     * Сформировать заголовки авторизации для Yandex API.
     *
     * @param string $apiKey   API-ключ
     * @param string $folderId Folder ID
     * @return array<int, string>
     */
    private function getAuthHeaders(string $apiKey, string $folderId): array
    {
        return [
            'Authorization: Api-Key ' . $apiKey,
            'x-folder-id: ' . $folderId,
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные методы: обработка ответов
    // ═════════════════════════════════════════════════════════════

    /**
     * Извлечь текст ответа из JSON YandexGPT.
     *
     * Структура ответа:
     * { "result": { "alternatives": [{ "message": { "text": "..." } }] } }
     *
     * @param mixed $data Декодированный JSON ответ
     * @return string|null Текст ответа или null
     */
    private function extractGPTText(mixed $data): ?string
    {
        if (!is_array($data)) {
            return null;
        }

        // Support both response formats:
        //  v1 (Yandex Cloud):  { "result": { "alternatives": [...] } }
        //  AI Studio (newer):  { "alternatives": [...] }
        $alternatives = $data['result']['alternatives'] ?? $data['alternatives'] ?? [];

        if (empty($alternatives)) {
            return null;
        }

        $text = $alternatives[0]['message']['text'] ?? null;

        if ($text === null || !is_string($text)) {
            return null;
        }

        return trim($text);
    }

    /**
     * Распарсить JSON-массив строк из текстового ответа GPT.
     *
     * GPT может обернуть JSON в markdown codeblock или добавить текст до/после.
     *
     * @param string $text Текстовый ответ от GPT
     * @return array<int, string> Массив строк
     */
    private function parseGPTJsonArray(string $text): array
    {
        // Убираем markdown codeblock
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $text) ?? $text;
        $cleaned = preg_replace('/\s*```\s*$/m', '', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        // Пробуем найти JSON массив в тексте
        if (preg_match('/\[[\s\S]*\]/', $cleaned, $matches)) {
            $cleaned = $matches[0];
        }

        try {
            $decoded = json_decode($cleaned, true);

            if (is_array($decoded) && !empty($decoded)) {
                // Фильтруем: оставляем только строки
                return array_values(array_filter($decoded, 'is_string'));
            }
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, "[YandexAPI] parseGPTJsonArray: JSON decode ошибка: {$e->getMessage()}");
        }

        // Fallback: разбиваем по строкам, убирая нумерацию
        $lines = explode("\n", $text);
        $ideas = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Убираем нумерацию: "1. ", "- ", "* "
            $line = preg_replace('/^[\d]+\.\s*/', '', $line) ?? $line;
            $line = preg_replace('/^[-*]\s*/', '', $line) ?? $line;
            $line = trim($line, ' "\'');

            if (!empty($line) && mb_strlen($line) > 10) {
                $ideas[] = $line;
            }
        }

        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[YandexAPI] parseGPTJsonArray: fallback parser, найдено идей: ' . count($ideas)
        );

        return $ideas;
    }

    /**
     * Выполнить запрос перевода к Yandex Translate API.
     *
     * @param array<int, string> $texts      Массив текстов для перевода
     * @param string             $sourceLang Язык источника
     * @param string             $targetLang Целевой язык
     * @return array<int, string>|null Массив переведённых текстов или null
     */
    private function executeTranslateRequest(
        array $texts,
        string $sourceLang,
        string $targetLang
    ): ?array {
        $apiKey = $this->getApiKey();
        $folderId = $this->getFolderId();

        if ($apiKey === null || $folderId === null) {
            return null;
        }

        $body = [
            'folderId'           => $folderId,
            'texts'              => $texts,
            'sourceLanguageCode' => $sourceLang,
            'targetLanguageCode' => $targetLang,
        ];

        $headers = $this->getAuthHeaders($apiKey, $folderId);

        try {
            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                "[YandexAPI] Translate: {$sourceLang}→{$targetLang}, текстов=" . count($texts)
            );

            $response = $this->httpPostJson(self::TRANSLATE_ENDPOINT, $headers, $body, 30);

            if (!$response['success']) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[YandexAPI] Translate ошибка: {$response['error']}");
                return null;
            }

            $translations = $response['data']['translations'] ?? [];
            if (empty($translations)) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[YandexAPI] Translate: пустой массив translations в ответе');
                return null;
            }

            $result = array_map(function (array $item): string {
                return (string)($item['text'] ?? '');
            }, $translations);

            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[YandexAPI] Translate: переведено ' . count($result) . ' текстов'
            );

            return $result;
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[YandexAPI] Translate исключение: {$e->getMessage()}");
            return null;
        }
    }

    // ═════════════════════════════════════════════════════════════
    // Приватные методы: утилиты
    // ═════════════════════════════════════════════════════════════

    /**
     * Транслитерировать кириллический текст в латиницу.
     *
     * @param string $text Исходный текст
     * @return string Транслитерированный текст
     */
    private function transliterate(string $text): string
    {
        $text = mb_strtolower($text);
        $result = '';

        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);
            $result .= self::TRANSLIT_MAP[$char] ?? $char;
        }

        return $result;
    }
}