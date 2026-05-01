<?php

namespace SpookyApp\Model;

use xPDO\Om\xPDOSimpleObject;

/**
 * SpookyApp — SpookyAppCache
 *
 * xPDO-модель для таблицы spookyapp_cache.
 * Key-value хранилище для сырых API-ответов в БД.
 *
 * @property int    $id
 * @property string $cache_key
 * @property string $cache_group
 * @property string $data
 * @property int    $expires_at
 * @property int    $createdon
 *
 * @package SpookyApp
 */
class SpookyAppCache extends xPDOSimpleObject
{
    /**
     * Получить данные как массив.
     */
    public function getDataArray(): array
    {
        $raw = $this->get('data');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * Сохранить данные как JSON.
     */
    public function setDataArray(array $data): void
    {
        $this->set('data', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Истёк ли кеш.
     */
    public function isExpired(): bool
    {
        $expiresAt = (int) $this->get('expires_at');
        return $expiresAt > 0 && time() > $expiresAt;
    }

    /**
     * Установить TTL от текущего момента.
     */
    public function setTtl(int $seconds): void
    {
        $this->set('expires_at', time() + $seconds);
    }

    /** {@inheritdoc} */
    public function save($cacheFlag = null): bool
    {
        if (!$this->get('createdon')) {
            $this->set('createdon', time());
        }
        return parent::save($cacheFlag);
    }
}
