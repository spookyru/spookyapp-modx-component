<?php

namespace SpookyApp\Model;

use xPDO\Om\xPDOSimpleObject;

/**
 * SpookyApp — SpookyAppChunk
 *
 * xPDO-модель для таблицы {prefix}spookyapp_chunks.
 * Хранит сгенерированные чанки (HTML/MODX-код) для редактора контента.
 * Префикс БД подставляется xPDO автоматически (напр. sitespk_spookyapp_chunks).
 *
 * @property int    $id          Первичный ключ (AUTO_INCREMENT)
 * @property string $type        Тип контента (movie|tv|game|device|person|product|sport)
 * @property string $external_id Внешний ID (tmdb_id, rawg_slug и т.д.)
 * @property string $title       Название (для списков)
 * @property string $data        Данные в формате JSON
 * @property string $created_at  Дата создания (Y-m-d H:i:s)
 * @property string $updated_at  Дата обновления (Y-m-d H:i:s)
 *
 * @package SpookyApp
 */
class SpookyAppChunk extends xPDOSimpleObject
{
    public const TYPE_MOVIE   = 'movie';
    public const TYPE_TV      = 'tv';
    public const TYPE_GAME    = 'game';
    public const TYPE_DEVICE  = 'device';
    public const TYPE_PERSON  = 'person';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_SPORT   = 'sport';

    /**
     * Получить декодированные данные.
     */
    public function getDataArray(): array
    {
        $raw = $this->get('data');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }

    /**
     * Сохранить данные как JSON-строку.
     */
    public function setDataArray(array $data): void
    {
        $this->set('data', json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * Авто-обновляет created_at (при создании) и updated_at (всегда).
     * {@inheritdoc}
     */
    public function save($cacheFlag = null): bool
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->get('created_at')) {
            $this->set('created_at', $now);
        }
        $this->set('updated_at', $now);
        return parent::save($cacheFlag);
    }

    /** @return string[] */
    public static function getAvailableTypes(): array
    {
        return [
            self::TYPE_MOVIE, self::TYPE_TV, self::TYPE_GAME,
            self::TYPE_DEVICE, self::TYPE_PERSON, self::TYPE_PRODUCT, self::TYPE_SPORT,
        ];
    }
}
