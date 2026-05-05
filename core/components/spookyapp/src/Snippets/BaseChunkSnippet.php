<?php
// filepath: core/components/spookyapp/src/Snippets/BaseChunkSnippet.php

/**
 * SpookyApp — BaseChunkSnippet
 *
 * ═══════════════════════════════════════════════════════════════
 * Базовый класс для всех chunk-сниппетов SpookyApp.
 * Содержит общую логику:
 *   - Валидация параметров
 *   - Загрузка данных из БД (spookyapp_chunks) или API
 *   - Парсинг chunk-шаблона с подстановкой placeholders
 *   - Кеширование результата через modX cache
 *   - Логирование ошибок
 *
 * Наследники переопределяют:
 *   - getContentType()      → строка типа ('movie', 'tvshow', …)
 *   - getDefaultTemplate()  → имя chunk-шаблона по умолчанию
 *   - getServiceClass()     → FQCN сервиса API (TMDBService, RAWGService…)
 *   - getServiceMethod()    → метод сервиса для получения данных
 *   - prepareData(array)    → подготовка placeholders из сырых данных
 *
 * @package SpookyApp
 * @since   1.0.0
 * ═══════════════════════════════════════════════════════════════
 */

namespace SpookyApp\Snippets;

use MODX\Revolution\modX;
use SpookyApp\Model\SpookyAppChunk;
use xPDO\xPDO;

abstract class BaseChunkSnippet
{
    /** @var modX */
    protected modX $modx;

    /** @var array Параметры сниппета (scriptProperties) */
    protected array $props = [];

    /** @var string Префикс для кеш-ключей */
    protected string $cachePrefix = 'spookyapp/chunks/';

    /** @var int Время жизни кеша в секундах (24 часа) */
    protected int $cacheTtl = 86400;

    /* ═══════════════════════════════════════════════════════════
     *  Абстрактные методы — обязательны к реализации
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Тип контента (movie, tvshow, game, device, person, product).
     */
    abstract protected function getContentType(): string;

    /**
     * Имя chunk-шаблона по умолчанию (без расширения .chunk.tpl).
     * Например: 'spookyapp-movie' → ищется в elements/chunks/movie.chunk.tpl
     */
    abstract protected function getDefaultTemplate(): string;

    /**
     * Полное имя класса сервиса API.
     * Например: \SpookyApp\Services\TMDBService::class
     */
    abstract protected function getServiceClass(): string;

    /**
     * Метод сервиса для получения данных по ID.
     * Например: 'getMovie', 'getTVShow'
     */
    abstract protected function getServiceMethod(): string;

    /**
     * Подготовка плейсхолдеров из сырых данных API/БД.
     *
     * @param  array $raw Сырые данные
     * @return array      Ассоциативный массив placeholders
     */
    abstract protected function prepareData(array $raw): array;


    /* ═══════════════════════════════════════════════════════════
     *  Конструктор
     * ═══════════════════════════════════════════════════════════ */

    public function __construct(modX $modx, array $scriptProperties = [])
    {
        $this->modx  = $modx;
        $this->props = $scriptProperties;
    }


    /* ═══════════════════════════════════════════════════════════
     *  Основной метод — точка входа
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Выполнить сниппет и вернуть готовый HTML.
     *
     * @return string HTML или пустая строка при ошибке
     */
    public function run(): string
    {
        try {
            // ── 1. Валидация параметров ─────────────────────
            $id = $this->getParam('id', 0, 'int');
            if (empty($id)) {
                $this->log('Параметр &id обязателен', modX::LOG_LEVEL_WARN);
                return '';
            }

            $type     = $this->getParam('type', 'db', 'string');
            $template = $this->getParam('template', '', 'string');
            $noCache  = (bool) $this->getParam('nocache', false, 'bool');

            // ── 2. Попытка получить из кеша ─────────────────
            $cacheKey = $this->buildCacheKey($id, $type);

            if (!$noCache) {
                $cached = $this->getFromCache($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
            }

            // ── 3. Загрузка данных ──────────────────────────
            $data = $this->loadData($id, $type);
            if (empty($data)) {
                $this->log("Данные не найдены: type={$type}, id={$id}", modX::LOG_LEVEL_WARN);
                return '';
            }

            // ── 4. Подготовка placeholders ──────────────────
            $placeholders = $this->prepareData($data);
            if (empty($placeholders)) {
                $this->log("prepareData вернул пустой массив: id={$id}", modX::LOG_LEVEL_WARN);
                return '';
            }

            // ── 5. Парсинг chunk-шаблона ────────────────────
            $html = $this->renderChunk($placeholders, $template);

            // ── 6. Сохранить в кеш ──────────────────────────
            if (!$noCache && !empty($html)) {
                $this->saveToCache($cacheKey, $html);
            }

            return $html;

        } catch (\Throwable $e) {
            $this->log(
                "Ошибка в " . static::class . ": {$e->getMessage()}",
                modX::LOG_LEVEL_ERROR
            );
            return '';
        }
    }


    /* ═══════════════════════════════════════════════════════════
     *  Загрузка данных
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Загрузить данные — из БД или через API-сервис.
     *
     * @param  int|string $id
     * @param  string     $type  'db' или название источника ('tmdb', 'rawg', …)
     * @return array|null
     */
    protected function loadData($id, string $type): ?array
    {
        if ($type === 'db') {
            return $this->loadFromDatabase($id);
        }

        return $this->loadFromApi($id, $type);
    }

    /**
     * Загрузка из таблицы spookyapp_chunks.
     */
    protected function loadFromDatabase($id): ?array
    {
        /** @var SpookyAppChunk|null $chunk */
        $chunk = $this->modx->getObject(SpookyAppChunk::class, [
            'external_id'  => (string) $id,
            'content_type' => $this->getContentType(),
        ]);

        if (!$chunk) {
            // Пробуем по первичному ключу
            $chunk = $this->modx->getObject(SpookyAppChunk::class, (int) $id);
        }

        if (!$chunk) {
            return null;
        }

        $data = $chunk->get('data');
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Загрузка через API-сервис.
     */
    protected function loadFromApi($id, string $type): ?array
    {
        $serviceClass = $this->getServiceClass();
        $method        = $this->getServiceMethod();

        if (!class_exists($serviceClass)) {
            $this->log("Класс сервиса не найден: {$serviceClass}", modX::LOG_LEVEL_ERROR);
            return null;
        }

        /** @var \SpookyApp\Services\BaseApiService $service */
        $service = new $serviceClass($this->modx);

        if (!method_exists($service, $method)) {
            $this->log("Метод {$method}() не найден в {$serviceClass}", modX::LOG_LEVEL_ERROR);
            return null;
        }

        $result = $service->$method($id);

        if (is_array($result)) {
            return $result;
        }

        // Если сервис вернул объект с toArray()
        if (is_object($result) && method_exists($result, 'toArray')) {
            return $result->toArray();
        }

        return null;
    }


    /* ═══════════════════════════════════════════════════════════
     *  Рендеринг chunk-шаблона
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Отрендерить chunk с переданными placeholders.
     *
     * @param  array  $placeholders
     * @param  string $customTemplate  Кастомный chunk (если передан)
     * @return string HTML
     */
    protected function renderChunk(array $placeholders, string $customTemplate = ''): string
    {
        $chunkName = !empty($customTemplate) ? $customTemplate : $this->getDefaultTemplate();

        // 1) Используем pdoTools (Fenom + MODX теги), если доступен
        $pdoTools = $this->getPdoTools();
        if ($pdoTools) {
            $html = $pdoTools->getChunk($chunkName, $placeholders);
            if ($html !== false && $html !== null && $html !== '') {
                return (string) $html;
            }
        }

        // 2) Fallback: стандартный MODX getChunk (без Fenom)
        $html = $this->modx->getChunk($chunkName, $placeholders);
        if (!empty($html)) {
            return $html;
        }

        // 3) Файловый fallback
        $filePath = $this->resolveChunkFilePath($chunkName);
        if ($filePath && file_exists($filePath)) {
            $tpl = file_get_contents($filePath);
            return $this->parsePlaceholders($tpl, $placeholders);
        }

        $this->log("Chunk-шаблон не найден: {$chunkName}", modX::LOG_LEVEL_ERROR);
        return '';
    }

    /**
     * Получить экземпляр pdoTools (ModxPro\PdoTools\CoreTools).
     * Кешируется в static, чтобы не делать лишних обращений к DI.
     *
     * @return \ModxPro\PdoTools\CoreTools|null
     */
    protected function getPdoTools(): ?object
    {
        static $instance = null;
        static $tried    = false;

        if (!$tried) {
            $tried = true;
            try {
                $instance = $this->modx->services->get(\ModxPro\PdoTools\CoreTools::class);
            } catch (\Throwable $e) {
                $instance = null;
            }
        }

        return $instance;
    }

    /**
     * Определить путь к файлу chunk-шаблона.
     *
     * @param  string $chunkName
     * @return string|null
     */
    protected function resolveChunkFilePath(string $chunkName): ?string
    {
        $basePath = $this->modx->getOption(
            'spookyapp.core_path',
            null,
            MODX_CORE_PATH . 'components/spookyapp/'
        );

        // Варианты поиска:
        $candidates = [
            $basePath . 'elements/chunks/' . $chunkName . '.chunk.tpl',
            $basePath . 'elements/chunks/' . $chunkName . '.tpl',
            $basePath . 'elements/chunks/' . $chunkName,
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Ручная замена [[+key]] плейсхолдеров в строке шаблона.
     * Используется когда chunk загружен из файла, а не из БД MODX.
     *
     * @param  string $tpl
     * @param  array  $placeholders
     * @return string
     */
    protected function parsePlaceholders(string $tpl, array $placeholders): string
    {
        // Подготовка — плоские ключи
        foreach ($placeholders as $key => $value) {
            if (is_scalar($value) || is_null($value)) {
                $tpl = str_replace('[[+' . $key . ']]', (string) $value, $tpl);
            }
        }

        // Пропускаем через парсер MODX для обработки output-фильтров (:notempty, :date и т.д.)
        $this->modx->setPlaceholders($placeholders, 'spookyapp.');

        // Парсим MODX-теги
        $maxIterations = 10;
        $this->modx->getParser();
        $this->modx->parser->processElementTags('', $tpl, false, false, '[[', ']]', [], $maxIterations);
        $this->modx->parser->processElementTags('', $tpl, true, true, '[[', ']]', [], $maxIterations);

        return $tpl;
    }


    /* ═══════════════════════════════════════════════════════════
     *  Кеширование
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Сформировать ключ кеша.
     */
    protected function buildCacheKey($id, string $type): string
    {
        $contentType = $this->getContentType();
        return $this->cachePrefix . "{$contentType}/{$type}/{$id}";
    }

    /**
     * Получить значение из кеша MODX.
     *
     * @return string|null  null если кеш отсутствует
     */
    protected function getFromCache(string $key): ?string
    {
        $options = [
            xPDO::OPT_CACHE_KEY     => 'spookyapp',
            xPDO::OPT_CACHE_HANDLER => $this->modx->getOption('cache_resource_handler', null, 'xPDOFileCache'),
        ];

        $value = $this->modx->cacheManager->get($key, $options);
        return is_string($value) ? $value : null;
    }

    /**
     * Сохранить значение в кеш MODX.
     */
    protected function saveToCache(string $key, string $value): void
    {
        $options = [
            xPDO::OPT_CACHE_KEY     => 'spookyapp',
            xPDO::OPT_CACHE_HANDLER => $this->modx->getOption('cache_resource_handler', null, 'xPDOFileCache'),
            xPDO::OPT_CACHE_EXPIRES => $this->cacheTtl,
        ];

        $this->modx->cacheManager->set($key, $value, $this->cacheTtl, $options);
    }

    /**
     * Сбросить кеш для конкретного элемента.
     */
    public function clearCache($id, string $type = 'db'): void
    {
        $key = $this->buildCacheKey($id, $type);
        $options = [
            xPDO::OPT_CACHE_KEY => 'spookyapp',
        ];
        $this->modx->cacheManager->delete($key, $options);
    }


    /* ═══════════════════════════════════════════════════════════
     *  Утилиты
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Получить параметр сниппета с приведением типа.
     *
     * @param  string $name    Имя параметра
     * @param  mixed  $default Значение по умолчанию
     * @param  string $cast    Тип: 'int', 'string', 'bool', 'float'
     * @return mixed
     */
    protected function getParam(string $name, $default = null, string $cast = 'string')
    {
        $value = $this->props[$name] ?? $default;

        return match ($cast) {
            'int'    => (int) $value,
            'float'  => (float) $value,
            'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default  => (string) $value,
        };
    }

    /**
     * Логирование.
     *
     * @param string $message
     * @param int    $level  modX::LOG_LEVEL_*
     */
    protected function log(string $message, int $level = modX::LOG_LEVEL_ERROR): void
    {
        $this->modx->log($level, '[SpookyApp:' . $this->getContentType() . '] ' . $message);
    }

    /**
     * Безопасно получить вложенное значение из массива.
     *
     * @param  array      $data
     * @param  string     $path   Точечная нотация: 'credits.cast.0.name'
     * @param  mixed      $default
     * @return mixed
     */
    protected function getNestedValue(array $data, string $path, $default = '')
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (is_array($current) && array_key_exists($key, $current)) {
                $current = $current[$key];
            } else {
                return $default;
            }
        }

        return $current;
    }

    /**
     * Склеить массив объектов по ключу через разделитель.
     *
     * @param  array  $items     Массив ассоциативных массивов
     * @param  string $key       Ключ для извлечения
     * @param  string $separator Разделитель
     * @return string
     */
    protected function pluckAndJoin(array $items, string $key = 'name', string $separator = ', '): string
    {
        $values = array_column($items, $key);
        return implode($separator, array_filter($values));
    }

    /**
     * Обрезать массив до N элементов.
     */
    protected function limitArray(array $items, int $limit): array
    {
        return array_slice($items, 0, $limit);
    }
}