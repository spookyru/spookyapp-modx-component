<?php

declare(strict_types=1);

use MODX\Revolution\Processors\Processor;
use SpookyApp\Model\SpookyAppChunk;

/**
 * Процессор получения списка сохранённых чанков из БД.
 *
 * Параметры:
 *   - type (string):   Тип чанка (movie|tv|game|device|product) — фильтр
 *   - search (string): Поиск по title
 *   - sort (string):   Поле сортировки (default: created_at)
 *   - dir (string):    Направление ASC|DESC (default: DESC)
 *   - limit (int):     Кол-во записей (default: 20)
 *   - start (int):     Offset (default: 0)
 *
 * @package SpookyApp
 * @subpackage Processors\ChunkGenerator
 */
class SpookyAppChunkGeneratorGetListProcessor extends Processor
{
    private const VALID_TYPES = ['movie', 'tv', 'game', 'device', 'product', 'person', 'sport'];
    private const ALLOWED_SORT = ['id', 'type', 'title', 'created_at', 'updated_at'];
    private const MAX_LIMIT = 100;
    private const DEFAULT_LIMIT = 20;

    public $languageTopics = ['spookyapp:default'];

    public function initialize(): bool|string
    {
        $autoload = MODX_CORE_PATH . 'components/spookyapp/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        // Регистрируем xPDO-пакет (идемпотентно; prefix берётся из конфига MODX)
        $this->modx->addPackage(
            'SpookyApp\\Model',
            MODX_CORE_PATH . 'components/spookyapp/src/Model/'
        );

        return parent::initialize();
    }

    public function process(): array
    {
        $type   = trim((string)$this->getProperty('type', ''));
        $search = trim((string)$this->getProperty('search', ''));
        $sort   = trim((string)$this->getProperty('sort', 'created_at'));
        $dir    = strtoupper(trim((string)$this->getProperty('dir', 'DESC')));
        $limit  = min(self::MAX_LIMIT, max(1, (int)$this->getProperty('limit', self::DEFAULT_LIMIT)));
        $start  = max(0, (int)$this->getProperty('start', 0));

        if (!in_array($sort, self::ALLOWED_SORT, true)) {
            $sort = 'created_at';
        }
        if (!in_array($dir, ['ASC', 'DESC'], true)) {
            $dir = 'DESC';
        }

        // Строим запрос через xPDO — prefix подставляется автоматически из конфига MODX
        $c = $this->modx->newQuery(SpookyAppChunk::class);

        if (!empty($type) && in_array($type, self::VALID_TYPES, true)) {
            $c->where(['type' => $type]);
        }

        if (!empty($search)) {
            $c->where(['title:LIKE' => '%' . $search . '%']);
        }

        $total = $this->modx->getCount(SpookyAppChunk::class, $c);

        $c->sortby($sort, $dir);
        $c->limit($limit, $start);

        /** @var SpookyAppChunk[] $collection */
        $collection = $this->modx->getCollection(SpookyAppChunk::class, $c);

        $results = [];
        foreach ($collection as $chunk) {
            $row = $chunk->toArray();
            if (!empty($row['data']) && is_string($row['data'])) {
                $row['data'] = json_decode($row['data'], true) ?: [];
            }
            $results[] = $row;
        }

        //return $this->outputArray($results, $total);
        @session_write_close();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        die($this->modx->toJSON([
            'success' => true,
            'results' => $results,
            'total'   => $total,
        ]));
    }
}

return SpookyAppChunkGeneratorGetListProcessor::class;

