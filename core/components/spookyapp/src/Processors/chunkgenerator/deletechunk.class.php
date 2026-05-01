<?php

declare(strict_types=1);

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modX;
use SpookyApp\Model\SpookyAppChunk;

/**
 * SpookyAppChunkGeneratorDeleteChunkProcessor — удаление чанка из БД.
 *
 * Параметры:
 *   - id (int, required): Первичный ключ записи в spookyapp_chunks
 *
 * @package SpookyApp
 * @subpackage Processors\ChunkGenerator
 */
class SpookyAppChunkGeneratorDeleteChunkProcessor extends Processor
{
    public $languageTopics = ['spookyapp:chunkgenerator'];

    public function initialize(): bool|string
    {
        $autoload = MODX_CORE_PATH . 'components/spookyapp/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        $this->modx->addPackage(
            'SpookyApp\\Model',
            MODX_CORE_PATH . 'components/spookyapp/src/Model/'
        );

        $id = (int)$this->getProperty('id', 0);
        if ($id <= 0) {
            return 'Parameter "id" is required and must be > 0';
        }

        return parent::initialize();
    }

    public function process(): array
    {
        $id = (int)$this->getProperty('id');

        /** @var SpookyAppChunk|null $chunk */
        $chunk = $this->modx->getObject(SpookyAppChunk::class, $id);

        if (!$chunk) {
            return $this->failure('Chunk #' . $id . ' not found');
        }

        $title = $chunk->get('title');
        $type  = $chunk->get('type');

        if (!$chunk->remove()) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[ChunkGenerator:DeleteChunk] Failed to remove chunk id=' . $id);
            return $this->failure('Failed to delete chunk #' . $id);
        }

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[ChunkGenerator:DeleteChunk] Deleted chunk id=' . $id
            . ' type=' . $type . ' title="' . mb_substr($title, 0, 50) . '"');

        return $this->success('', ['id' => $id, 'title' => $title]);
    }
}

return 'SpookyAppChunkGeneratorDeleteChunkProcessor';
