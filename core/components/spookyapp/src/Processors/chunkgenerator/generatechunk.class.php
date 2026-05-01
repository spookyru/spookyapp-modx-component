<?php

declare(strict_types=1);

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modChunk;
use MODX\Revolution\modX;
use SpookyApp\Services\ChunkCodeGeneratorService;

/**
 * SpookyAppChunkGeneratorGenerateChunkProcessor — генерация HTML чанка.
 *
 * ═══════════════════════════════════════════════════════════════
 * Генерирует HTML-код чанка на основе данных и шаблона.
 * Используется после получения детальной информации (getdetails).
 *
 * Параметры:
 *   - type (string):     Тип контента (movie|tv|person|game|device|product)
 *   - data (string/JSON): Данные для генерации
 *   - template (string):  Имя шаблона (optional, default = тип)
 *   - format (string):    Формат вывода: html|modx (optional, default html)
 *
 * Возвращает:
 *   - success: true/false
 *   - chunk_code: сгенерированный HTML
 *   - template_used: имя использованного шаблона
 * ═══════════════════════════════════════════════════════════════
 *
 * @package SpookyApp
 * @subpackage Processors\ChunkGenerator
 */
class SpookyAppChunkGeneratorGenerateChunkProcessor extends Processor
{
    // ╔═════════════════════════════════════════════════════════╗
    // ║  Constants                                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /** @var string Путь к шаблонам чанков */
    private const TEMPLATES_PATH = MODX_CORE_PATH . 'components/spookyapp/templates/chunks/';

    /** @var array<string, string> Маппинг типов на шаблоны по умолчанию */
    private const DEFAULT_TEMPLATES = [
        'movie'   => 'movie-card',
        'tv'      => 'tv-card',
        'person'  => 'person-card',
        'game'    => 'game-card',
        'device'  => 'device-card',
        'product' => 'product-card',
        'match'   => 'match-card',
        'team'    => 'team-card',
        'league'  => 'league-table',
        'biathlon_results' => 'biathlon-results',
    ];

    /** @var string Класс модуля */
    public $classKey = 'SpookyAppChunkGeneratorGenerateChunk';

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
        $corePath = MODX_CORE_PATH . 'components/spookyapp/';

        $autoload = $corePath . 'vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        // Also register the manual PSR-4 autoloader for SpookyApp classes
        $manualAutoload = $corePath . 'autoload.php';
        if (file_exists($manualAutoload)) {
            require_once $manualAutoload;
        }

        // Embed-codes mode: chunk_id is sufficient
        $chunkId = (int)$this->getProperty('chunk_id', 0);
        if ($chunkId > 0) {
            return parent::initialize();
        }

        // Normal HTML-generation mode: require type + data
        $type = trim((string)$this->getProperty('type', ''));
        if (empty($type)) {
            return $this->modx->lexicon('spookyapp.chunkgenerator.err_type_required')
                ?: 'Parameter "type" is required';
        }

        $data = $this->getProperty('data', '');
        if (empty($data)) {
            return $this->modx->lexicon('spookyapp.chunkgenerator.err_data_required')
                ?: 'Parameter "data" is required';
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
        // ── Embed-codes mode: generate all 3 formats for a saved chunk ──
        $chunkId = (int)$this->getProperty('chunk_id', 0);
        if ($chunkId > 0) {
            return $this->processEmbedCodes($chunkId);
        }

        $type     = trim((string)$this->getProperty('type'));
        $dataRaw  = $this->getProperty('data');
        $template = trim((string)$this->getProperty('template', ''));
        $format   = trim((string)$this->getProperty('format', 'html'));

        // ── Парсим данные ────────────────────────────────────
        $data = is_array($dataRaw)
            ? $dataRaw
            : json_decode((string)$dataRaw, true);

        if (!is_array($data) || empty($data)) {
            return $this->failure(
                $this->modx->lexicon('spookyapp.chunkgenerator.err_data_invalid')
                    ?: 'Invalid data format (expected JSON object)'
            );
        }

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            '[ChunkGenerator:GenerateChunk] type=' . $type
            . ' template=' . ($template ?: 'default')
            . ' format=' . $format
            . ' dataKeys=' . implode(',', array_keys($data))
        );

        try {
            // ── Определяем шаблон ────────────────────────────
            $templateName = !empty($template)
                ? $template
                : (self::DEFAULT_TEMPLATES[$type] ?? $type . '-card');

            // ── Генерируем HTML ──────────────────────────────
            $chunkCode = $this->renderTemplate($templateName, $type, $data, $format);

            return $this->success('', [
                'type'          => $type,
                'chunk_code'    => $chunkCode,
                'template_used' => $templateName,
                'data_keys'     => array_keys($data),
            ]);

        } catch (\Throwable $e) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                '[ChunkGenerator:GenerateChunk] Error: ' . $e->getMessage()
            );
            return $this->failure(
                $this->modx->lexicon('spookyapp.chunkgenerator.err_generate_failed')
                    ?: 'Chunk generation failed: ' . $e->getMessage()
            );
        }
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Template Rendering                            ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Рендерить шаблон чанка.
     *
     * Порядок поиска шаблона:
     * 1. Файл: {TEMPLATES_PATH}/{templateName}.tpl
     * 2. MODX Chunk: spookyapp-{templateName}
     * 3. Fallback: генерация по типу контента
     *
     * @param string $templateName Имя шаблона
     * @param string $type         Тип контента
     * @param array  $data         Данные для подстановки
     * @param string $format       Формат: html|modx
     *
     * @return string Сгенерированный HTML
     */
    private function renderTemplate(
        string $templateName,
        string $type,
        array $data,
        string $format
    ): string {
        // ── 1. Файл шаблона ─────────────────────────────────
        $templateFile = self::TEMPLATES_PATH . $templateName . '.tpl';
        if (file_exists($templateFile)) {
            $tpl = file_get_contents($templateFile);

            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[ChunkGenerator:GenerateChunk] Using file template: ' . $templateFile
            );

            return $this->processTemplate($tpl, $data, $format);
        }

        // ── 2. MODX Chunk ────────────────────────────────────
        $chunkName = 'spookyapp-' . $templateName;
        /** @var modChunk|null $chunk */
        $chunk = $this->modx->getObject(modChunk::class, ['name' => $chunkName]);
        if ($chunk) {
            $tpl = $chunk->getContent();

            $this->modx->log(
                modX::LOG_LEVEL_DEBUG,
                '[ChunkGenerator:GenerateChunk] Using MODX chunk: ' . $chunkName
            );

            return $this->processTemplate($tpl, $data, $format);
        }

        // ── 3. Fallback: генерация по типу ───────────────────
        $this->modx->log(
            modX::LOG_LEVEL_DEBUG,
            '[ChunkGenerator:GenerateChunk] Using fallback generator for type=' . $type
        );

        return $this->generateFallbackChunk($type, $data, $format);
    }

    /**
     * Подставить данные в шаблон.
     *
     * Заменяет плейсхолдеры {{key}} или [[+key]] на значения из $data.
     *
     * @param string $template Шаблон с плейсхолдерами
     * @param array  $data     Данные
     * @param string $format   Формат: html|modx
     *
     * @return string Обработанный HTML
     */
    private function processTemplate(string $template, array $data, string $format): string
    {
        $flattened = $this->flattenArray($data);

        foreach ($flattened as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $value = (string)$value;

            // {{key}} — универсальные плейсхолдеры
            $template = str_replace('{{' . $key . '}}', $value, $template);

            // [[+key]] — MODX плейсхолдеры
            $template = str_replace('[[+' . $key . ']]', $value, $template);
        }

        return $template;
    }

    /**
     * Преобразовать многомерный массив в плоский (dot notation).
     *
     * ['a' => ['b' => 'c']] → ['a.b' => 'c']
     *
     * @param array  $array  Массив
     * @param string $prefix Префикс ключа
     *
     * @return array<string, mixed>
     */
    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix !== '' ? $prefix . '.' . $key : (string)$key;

            if (is_array($value) && !empty($value)) {
                // Если индексированный массив — сохраняем как JSON и добавляем count
                if (array_keys($value) === range(0, count($value) - 1)) {
                    $result[$newKey] = json_encode($value, JSON_UNESCAPED_UNICODE);
                    $result[$newKey . '.count'] = count($value);
                    // Также разворачиваем первые элементы
                    foreach ($value as $i => $item) {
                        if ($i >= 10) {
                            break;
                        }
                        if (is_array($item)) {
                            $result = array_merge(
                                $result,
                                $this->flattenArray($item, $newKey . '.' . $i)
                            );
                        } else {
                            $result[$newKey . '.' . $i] = $item;
                        }
                    }
                } else {
                    $result = array_merge($result, $this->flattenArray($value, $newKey));
                }
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Сгенерировать fallback-чанк когда шаблон не найден.
     *
     * Создаёт базовый HTML с данными в зависимости от типа.
     *
     * @param string $type   Тип контента
     * @param array  $data   Данные
     * @param string $format Формат
     *
     * @return string HTML
     */
    private function generateFallbackChunk(string $type, array $data, string $format): string
    {
        $title       = $data['title'] ?? $data['name'] ?? 'Untitled';
        $description = $data['overview'] ?? $data['description'] ?? $data['biography'] ?? '';
        $image       = $data['poster'] ?? $data['image'] ?? $data['photo']
            ?? $data['backdrop'] ?? $data['image_url'] ?? '';
        $rating      = $data['rating'] ?? $data['vote_average'] ?? '';
        $year        = $data['release_date'] ?? $data['released']
            ?? $data['first_air_date'] ?? $data['birthday'] ?? '';

        if (!empty($year) && strlen($year) >= 4) {
            $year = substr($year, 0, 4);
        }

        $html = '<div class="spookyapp-chunk spookyapp-chunk--' . htmlspecialchars($type) . '">' . "\n";

        // ── Изображение ──────────────────────────────────────
        if (!empty($image)) {
            $html .= '  <div class="spookyapp-chunk__image">' . "\n";
            $html .= '    <img src="' . htmlspecialchars($image) . '" '
                . 'alt="' . htmlspecialchars($title) . '" loading="lazy">' . "\n";
            $html .= '  </div>' . "\n";
        }

        // ── Контент ──────────────────────────────────────────
        $html .= '  <div class="spookyapp-chunk__content">' . "\n";
        $html .= '    <h3 class="spookyapp-chunk__title">' . htmlspecialchars($title);
        if (!empty($year)) {
            $html .= ' <span class="spookyapp-chunk__year">(' . htmlspecialchars($year) . ')</span>';
        }
        $html .= '</h3>' . "\n";

        // ── Рейтинг ──────────────────────────────────────────
        if (!empty($rating)) {
            $html .= '    <div class="spookyapp-chunk__rating">'
                . '★ ' . htmlspecialchars((string)$rating) . '</div>' . "\n";
        }

        // ── Жанры ────────────────────────────────────────────
        if (!empty($data['genres']) && is_array($data['genres'])) {
            $html .= '    <div class="spookyapp-chunk__genres">'
                . htmlspecialchars(implode(', ', $data['genres'])) . '</div>' . "\n";
        }

        // ── Описание ─────────────────────────────────────────
        if (!empty($description)) {
            $html .= '    <div class="spookyapp-chunk__description">'
                . htmlspecialchars(mb_substr($description, 0, 500))
                . '</div>' . "\n";
        }

        // ── Актёры (для movie/tv) ────────────────────────────
        if (!empty($data['cast']) && is_array($data['cast'])) {
            $html .= '    <div class="spookyapp-chunk__cast">' . "\n";
            $html .= '      <h4>Актёры</h4>' . "\n";
            $html .= '      <ul>' . "\n";
            foreach (array_slice($data['cast'], 0, 5) as $actor) {
                $name = htmlspecialchars($actor['name'] ?? '');
                $character = htmlspecialchars($actor['character'] ?? '');
                $html .= '        <li>' . $name;
                if (!empty($character)) {
                    $html .= ' — <em>' . $character . '</em>';
                }
                $html .= '</li>' . "\n";
            }
            $html .= '      </ul>' . "\n";
            $html .= '    </div>' . "\n";
        }

        // ── Цена (для product) ───────────────────────────────
        if (!empty($data['price'])) {
            $html .= '    <div class="spookyapp-chunk__price">'
                . htmlspecialchars((string)$data['price'])
                . (!empty($data['currency']) ? ' ' . htmlspecialchars($data['currency']) : '')
                . '</div>' . "\n";
        }

        $html .= '  </div>' . "\n";
        $html .= '</div>';

        // ── MODX формат: обернуть в плейсхолдеры ────────────
        if ($format === 'modx') {
            $html = '<!-- Chunk Generator: ' . $type . ' -->' . "\n" . $html;
        }

        return $html;
    }

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Private: Embed Codes Mode                              ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Режим генерации кодов вставки: fetch из БД + все 3 формата.
     *
     * @param int $chunkId Первичный ключ в spookyapp_chunks
     * @return array
     */
    private function processEmbedCodes(int $chunkId): array
    {
        $service = new ChunkCodeGeneratorService($this->modx);

        $row = $service->fetchById($chunkId);

        if (!$row) {
            return $this->failure(
                $this->modx->lexicon('spookyapp.chunkgenerator.err_chunk_not_found')
                    ?: "Chunk #{$chunkId} not found in database"
            );
        }

        $embedCodes = $service->generateEmbedCodes($row);

        $this->modx->log(
            modX::LOG_LEVEL_INFO,
            "[ChunkGenerator:GenerateChunk] Embed codes generated for chunk_id={$chunkId}"
        );

        return $this->success('', [
            'chunk_id'    => $chunkId,
            'type'        => $row['type'] ?? '',
            'title'       => $row['title'] ?? '',
            'embed_codes' => $embedCodes,
        ]);
    }
}

return 'SpookyAppChunkGeneratorGenerateChunkProcessor';