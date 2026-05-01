<?php

namespace SpookyApp\Model;

use xPDO\Om\xPDOSimpleObject;

/**
 * SpookyApp — SpookyAppMedia
 *
 * xPDO-модель для таблицы spookyapp_media.
 * Медиафайлы, привязанные к объектам контента (полиморфная связь).
 *
 * @property int    $id
 * @property string $object_type
 * @property int    $object_id
 * @property string $media_type
 * @property string $media_role
 * @property string $url
 * @property string $local_path
 * @property int    $width
 * @property int    $height
 * @property int    $filesize
 * @property string $alt
 * @property int    $sort_order
 * @property int    $createdon
 *
 * @package SpookyApp
 */
class SpookyAppMedia extends xPDOSimpleObject
{
    public const TYPE_IMAGE    = 'image';
    public const TYPE_VIDEO    = 'video';
    public const TYPE_AUDIO    = 'audio';
    public const TYPE_DOCUMENT = 'document';

    public const ROLE_POSTER     = 'poster';
    public const ROLE_BACKDROP   = 'backdrop';
    public const ROLE_SCREENSHOT = 'screenshot';
    public const ROLE_TRAILER    = 'trailer';
    public const ROLE_AUDIO      = 'audio';
    public const ROLE_THUMB      = 'thumb';

    /**
     * Скачан ли файл локально.
     */
    public function isLocal(): bool
    {
        $local = $this->get('local_path');
        return !empty($local) && file_exists($local);
    }

    /**
     * Получить актуальный URL (локальный или внешний).
     */
    public function getActiveUrl(string $baseUrl = ''): string
    {
        $local = $this->get('local_path');
        if (!empty($local)) {
            return rtrim($baseUrl, '/') . '/' . ltrim($local, '/');
        }
        return $this->get('url') ?? '';
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
