<?php

declare(strict_types=1);

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modX;

/**
 * SpookyAppChunkGeneratorVoiceoverProcessor — синтез речи.
 *
 * ═══════════════════════════════════════════════════════════════
 * Генерирует аудиофайл из текста через YandexAPIService (SpeechKit).
 * Используется для озвучки описаний фильмов, игр, товаров и т.д.
 *
 * Параметры:
 *   - text (string):     Текст для озвучки
 *   - voice (string):    Голос: jane|zahar|alena|filipp|ermil|omazh (default jane)
 *   - filename (string): Имя файла (optional, auto-generated)
 *   - speed (string):    Скорость речи: 0.1-3.0 (default 1.0)
 *   - emotion (string):  Эмоция: neutral|good|evil (default neutral)
 *
 * Возвращает:
 *   - success: true/false
 *   - file_path: относительный путь к аудио файлу
 *   - file_url: URL для доступа
 *   - file_size: размер файла в байтах
 *   - duration_estimate: приблизительная длительность (секунды)
 * ═══════════════════════════════════════════════════════════════
 *
 * @package SpookyApp
 * @subpackage Processors\ChunkGenerator
 */
class SpookyAppChunkGeneratorVoiceoverProcessor extends Processor
{
    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constants                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var string Директория для аудиофайлов (относительно MODX_BASE_PATH) */
    private const AUDIO_DIR = 'assets/spookyapp/audio/voiceover/';

    /** @var int Максимальная длина текста (Yandex SpeechKit: 5000 символов) */
    private const MAX_TEXT_LENGTH = 5000;

    /** @var array<string> Допустимые голоса */
    private const VALID_VOICES = [
        'jane', 'zahar', 'alena', 'filipp', 'ermil', 'omazh',
    ];

    /** @var array<string> Допустимые эмоции */
    private const VALID_EMOTIONS = [
        'neutral', 'good', 'evil',
    ];

    /** @var float Средняя скорость чтения (слов в минуту) для оценки длительности */
    private const AVG_WORDS_PER_MINUTE = 150.0;

    /** @var string Класс модуля */
    public $classKey = 'SpookyAppChunkGeneratorVoiceover';

    /** @var string Лексикон */
    public $languageTopics = ['spookyapp:chunkgenerator'];

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Initialize                                             ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Инициализация процессора.
     *
     * @return bool|string true при успехе, строка с ошибкой
     */
    public function initialize(): bool|string
    {
        $autoload = MODX_CORE_PATH . 'components/spookyapp/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        $text = trim((string)$this->getProperty('text', ''));
        if (empty($text)) {
            return $this->modx->lexicon('spookyapp.chunkgenerator.err_text_required')
                ?: 'Parameter "text" is required';
        }

        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return ($this->modx->lexicon('spookyapp.chunkgenerator.err_text_too_long')
                ?: 'Text is too long. Maximum: ') . self::MAX_TEXT_LENGTH . ' characters';
        }

        return parent::initialize();
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Process                                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Основная логика процессора.
     *
     * @return array Результат выполнения
     */
    public function process(): array
    {
        $text     = trim((string)$this->getProperty('text'));
        $voice    = $this->validateEnum(
            trim((string)$this->getProperty('voice', 'jane')),
            self::VALID_VOICES,
            'jane'
        );
        $filename = trim((string)$this->getProperty('filename', ''));
        $speed    = max(0.1, min(3.0, (float)$this->getProperty('speed', 1.0)));
        $emotion  = $this->validateEnum(
            trim((string)$this->getProperty('emotion', 'neutral')),
            self::VALID_EMOTIONS,
            'neutral'
        );

        // ── Генерируем имя файла ─────────────────────────────
        if (empty($filename)) {
            $filename = 'vo_' . md5($text . $voice . $speed . $emotion)
                . '_' . date('Ymd_His');
        }

        // Убираем расширение если есть (автодобавим .ogg)
        $filename = pathinfo($filename, PATHINFO_FILENAME);
        $filename .= '.ogg';

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[ChunkGenerator:Voiceover] voice=' . $voice
            . ' speed=' . $speed
            . ' emotion=' . $emotion
            . ' text=' . mb_strlen($text) . ' chars'
            . ' filename=' . $filename
        );

        try {
            // ── Вызываем Yandex SpeechKit ────────────────────
            // synthesizeSpeech сам создаёт директории, кеширует файлы
            // и возвращает относительный путь к аудиофайлу (или null)
            $service = $this->getYandexService();

            $relPath = $service->synthesizeSpeech($text, $voice, [
                'speed'   => (string)$speed,
                'emotion' => $emotion,
                'format'  => 'oggopus',
            ]);

            if (empty($relPath)) {
                return $this->failure(
                    $this->modx->lexicon('spookyapp.chunkgenerator.err_voiceover_empty')
                        ?: 'Speech synthesis returned empty result'
                );
            }

            $absPath  = MODX_BASE_PATH . $relPath;
            $fileSize = file_exists($absPath) ? (int)filesize($absPath) : 0;

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                '[ChunkGenerator:Voiceover] Ready: ' . $relPath
                . ' (' . $this->formatBytes($fileSize) . ')'
            );

            return $this->success('', [
                'file_path'         => $relPath,
                'file_url'          => $this->modx->getOption('site_url') . $relPath,
                'file_size'         => $fileSize,
                'duration_estimate' => $this->estimateDuration($text, $speed),
            ]);

        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[ChunkGenerator:Voiceover] Error: ' . $e->getMessage()
            );
            return $this->failure(
                $this->modx->lexicon('spookyapp.chunkgenerator.err_voiceover_failed')
                    ?: 'Voiceover failed: ' . $e->getMessage()
            );
        }
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Helpers                                       ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Оценить приблизительную длительность аудио.
     *
     * @param string $text  Текст
     * @param float  $speed Скорость речи
     *
     * @return int Приблизительная длительность в секундах
     */
    private function estimateDuration(string $text, float $speed): int
    {
        $wordCount = str_word_count($text, 0);
        // Для кириллицы str_word_count работает некорректно
        if ($wordCount === 0) {
            $wordCount = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
        }

        $minutes = $wordCount / (self::AVG_WORDS_PER_MINUTE * $speed);

        return max(1, (int)round($minutes * 60));
    }

    /**
     * Форматировать размер файла.
     *
     * @param int $bytes Размер в байтах
     *
     * @return string Форматированный размер
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * Валидация значения enum.
     *
     * @param string        $value   Проверяемое значение
     * @param array<string> $allowed Допустимые значения
     * @param string        $default Значение по умолчанию
     *
     * @return string Валидное значение
     */
    private function validateEnum(string $value, array $allowed, string $default): string
    {
        $lower = strtolower($value);
        if (in_array($lower, $allowed, true)) {
            return $lower;
        }
        return $default;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Service Factory                               ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Получить экземпляр YandexAPIService.
     *
     * @return \SpookyApp\Services\API\YandexAPIService
     */
    private function getYandexService(): \SpookyApp\Services\API\YandexAPIService
    {
        $cache = new \SpookyApp\Services\Cache\CacheService($this->modx);

        return new \SpookyApp\Services\API\YandexAPIService($this->modx, $cache);
    }
}

return 'SpookyAppChunkGeneratorVoiceoverProcessor';