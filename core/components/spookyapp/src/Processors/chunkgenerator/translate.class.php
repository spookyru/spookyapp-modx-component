<?php

declare(strict_types=1);

use MODX\Revolution\Processors\Processor;

/**
 * SpookyAppChunkGeneratorTranslateProcessor — перевод текста.
 *
 * ═══════════════════════════════════════════════════════════════
 * Переводит текст через YandexAPIService.
 * Используется для перевода описаний, биографий и другого
 * контента из API на нужный язык.
 *
 * Параметры:
 *   - text (string):        Текст для перевода
 *   - source_lang (string): Язык оригинала (default 'en')
 *   - target_lang (string): Целевой язык (default 'ru')
 *
 * Возвращает:
 *   - success: true/false
 *   - translated_text: переведённый текст
 *   - source_lang: язык оригинала
 *   - target_lang: целевой язык
 *   - chars_count: количество символов
 * ═══════════════════════════════════════════════════════════════
 *
 * @package SpookyApp
 * @subpackage Processors\ChunkGenerator
 */
class SpookyAppChunkGeneratorTranslateProcessor extends Processor
{
    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constants                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var int Максимальная длина текста для перевода (символов) */
    private const MAX_TEXT_LENGTH = 10000;

    /** @var array<string> Поддерживаемые языки */
    private const SUPPORTED_LANGS = [
        'ru', 'en', 'de', 'fr', 'es', 'it', 'pt', 'ja', 'ko', 'zh',
        'ar', 'tr', 'pl', 'nl', 'sv', 'cs', 'fi', 'uk', 'be', 'kk',
    ];

    /** @var string Класс модуля */
    public $classKey = 'SpookyAppChunkGeneratorTranslate';

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
        $text       = trim((string)$this->getProperty('text'));
        $sourceLang = trim((string)$this->getProperty('source_lang', 'en'));
        $targetLang = trim((string)$this->getProperty('target_lang', 'ru'));

        // ── Валидация языков ─────────────────────────────────
        if (!in_array($sourceLang, self::SUPPORTED_LANGS, true)) {
            return $this->failure(
                ($this->modx->lexicon('spookyapp.chunkgenerator.err_lang_unsupported')
                    ?: 'Unsupported source language: ') . $sourceLang
            );
        }

        if (!in_array($targetLang, self::SUPPORTED_LANGS, true)) {
            return $this->failure(
                ($this->modx->lexicon('spookyapp.chunkgenerator.err_lang_unsupported')
                    ?: 'Unsupported target language: ') . $targetLang
            );
        }

        // ── Текст уже на целевом языке ──────────────────────
        if ($sourceLang === $targetLang) {
            return $this->success('', [
                'translated_text' => $text,
                'source_lang'     => $sourceLang,
                'target_lang'     => $targetLang,
                'chars_count'     => mb_strlen($text),
                'skipped'         => true,
            ]);
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[ChunkGenerator:Translate] ' . $sourceLang . ' → ' . $targetLang
            . ' (' . mb_strlen($text) . ' chars)'
        );

        try {
            $service = $this->getYandexService();

            /** @var mixed $result */
            $result = $service->translate($text, $sourceLang, $targetLang);

            // API может вернуть ['text' => string|array] или просто строку
            if (is_array($result) && isset($result['text'])) {
                $translatedText = is_array($result['text'])
                    ? implode(' ', $result['text'])
                    : (string)$result['text'];
            } elseif (is_string($result) && $result !== '') {
                $translatedText = $result;
            } else {
                return $this->failure(
                    $this->modx->lexicon('spookyapp.chunkgenerator.err_translate_failed')
                        ?: 'Translation returned empty result'
                );
            }

            $this->modx->log(
                modX::LOG_LEVEL_INFO,
                '[ChunkGenerator:Translate] Success: '
                . mb_strlen($text) . ' → ' . mb_strlen($translatedText) . ' chars'
            );

            return $this->success('', [
                'translated_text' => $translatedText,
                'source_lang'     => $sourceLang,
                'target_lang'     => $targetLang,
                'chars_count'     => mb_strlen($translatedText),
                'skipped'         => false,
            ]);

        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[ChunkGenerator:Translate] Error: ' . $e->getMessage()
            );
            return $this->failure(
                $this->modx->lexicon('spookyapp.chunkgenerator.err_translate_failed')
                    ?: 'Translation failed: ' . $e->getMessage()
            );
        }
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

return 'SpookyAppChunkGeneratorTranslateProcessor';