<?php

declare(strict_types=1);

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modX;

/**
 * SaveSpecsOverrides — сохранение пользовательских правок спецификаций устройства.
 *
 * Использует прямые PDO-запросы к таблице spookyapp_cache, так как фактическая
 * структура таблицы (cache_key PK, data, ttl, created_at, expires_at DATETIME)
 * не совпадает с метаданными xPDO-модели.
 *
 * Ключ хранения: 'device_specs_overrides::{external_id}'
 *
 * Параметры:
 *   - external_id (string):  Внешний ID устройства (slug)
 *   - overrides   (string):  JSON {SectionTitle: {Key: "new value"}}
 *                            Пустая строка "" удаляет поле из правок.
 *
 * @package SpookyApp
 * @subpackage Processors\ChunkGenerator
 */
class SpookyAppChunkGeneratorSaveSpecsOverridesProcessor extends Processor
{
    public $classKey = 'SpookyAppChunkGeneratorSaveSpecsOverrides';
    public $languageTopics = ['spookyapp:chunkgenerator'];

    private const KEY_PREFIX = 'device_specs_overrides::';

    // ── Helpers ──────────────────────────────────────────────

    private function getTable(): string
    {
        $prefix = $this->modx->getOption('table_prefix', null, 'sitespk_');
        return $prefix . 'spookyapp_cache';
    }

    private function loadExisting(string $cacheKey): array
    {
        $table = $this->getTable();
        $sql   = "SELECT `data` FROM `{$table}` WHERE `cache_key` = :key LIMIT 1";
        try {
            $stmt = $this->modx->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bindValue(':key', $cacheKey, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['data'])) {
                return [];
            }
            $decoded = json_decode($row['data'], true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[SaveSpecsOverrides] loadExisting error: ' . $e->getMessage());
            return [];
        }
    }

    private function upsert(string $cacheKey, array $data): bool
    {
        $table   = $this->getTable();
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // expires_at far future — permanent override, never auto-deleted by CacheService
        $sql = "INSERT INTO `{$table}` (`cache_key`, `data`, `ttl`, `created_at`, `expires_at`)
                VALUES (:key, :data, 0, NOW(), '2099-12-31 23:59:59')
                ON DUPLICATE KEY UPDATE
                    `data`       = VALUES(`data`),
                    `ttl`        = 0,
                    `expires_at` = '2099-12-31 23:59:59'";
        try {
            $stmt = $this->modx->prepare($sql);
            if (!$stmt) {
                return false;
            }
            $stmt->bindValue(':key',  $cacheKey, PDO::PARAM_STR);
            $stmt->bindValue(':data', $encoded,  PDO::PARAM_STR);
            return $stmt->execute();
        } catch (\Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[SaveSpecsOverrides] upsert error: ' . $e->getMessage());
            return false;
        }
    }

    // ── Initialize ───────────────────────────────────────────

    public function initialize(): bool|string
    {
        $externalId = trim((string)$this->getProperty('external_id', ''));
        if (empty($externalId)) {
            return 'Parameter "external_id" is required';
        }
        $overridesRaw = $this->getProperty('overrides', null);
        if ($overridesRaw === null || $overridesRaw === '') {
            return 'Parameter "overrides" is required';
        }
        return parent::initialize();
    }

    // ── Process ──────────────────────────────────────────────

    public function process(): array
    {
        $externalId   = trim((string)$this->getProperty('external_id'));
        $overridesRaw = $this->getProperty('overrides');

        // Парсим входящие правки
        $incoming = is_array($overridesRaw)
            ? $overridesRaw
            : json_decode((string)$overridesRaw, true);

        if (!is_array($incoming)) {
            return $this->failure('Invalid overrides format (expected JSON object)');
        }

        $cacheKey = self::KEY_PREFIX . $externalId;

        // Загружаем существующие правки
        $existing = $this->loadExisting($cacheKey);

        // Мержим: incoming поверх existing
        // Пустое значение "" → удалить ключ из правок
        $savedCount = 0;
        foreach ($incoming as $section => $keyvals) {
            if (!is_array($keyvals)) {
                continue;
            }
            if (!isset($existing[$section]) || !is_array($existing[$section])) {
                $existing[$section] = [];
            }
            foreach ($keyvals as $key => $val) {
                $val = (string)$val;
                if ($val === '') {
                    unset($existing[$section][$key]);
                } else {
                    $existing[$section][$key] = $val;
                    $savedCount++;
                }
            }
            if (empty($existing[$section])) {
                unset($existing[$section]);
            }
        }

        // Сохраняем
        if (!$this->upsert($cacheKey, $existing)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[SaveSpecsOverrides] Failed to save overrides for: ' . $externalId);
            return $this->failure('Failed to save overrides to database');
        }

        $this->modx->log(modX::LOG_LEVEL_INFO,
            '[SaveSpecsOverrides] Saved ' . $savedCount . ' overrides for: ' . $externalId);

        return $this->success('Specs overrides saved', [
            'saved_count' => $savedCount,
            'external_id' => $externalId,
            'overrides'   => $existing,
        ]);
    }
}

return 'SpookyAppChunkGeneratorSaveSpecsOverridesProcessor';
