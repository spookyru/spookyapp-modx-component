<?php
// filepath: core/components/spookyapp/src/Model/SpookyAppTopic.php

declare(strict_types=1);

namespace SpookyApp\Model;

use xPDO\Om\xPDOSimpleObject;

/**
 * SpookyApp — SpookyAppTopic
 *
 * ═══════════════════════════════════════════════════════════════
 * xPDO-модель для таблицы spookyapp_topics.
 * Хранит темы/новости из внешних источников (RSS, API, парсинг).
 *
 * Поля:
 *   id            — PK, auto-increment
 *   topic_id      — уникальный составной ID ('tmdb_movie_12345')
 *   source        — источник: newsapi, reddit, tmdb, rawg, github…
 *   title         — заголовок
 *   url           — ссылка на оригинал
 *   description   — краткое описание
 *   category      — категория: IT, Games, Cinema, Sports…
 *   published_at  — дата публикации в источнике (datetime)
 *   score         — рейтинг релевантности (decimal 10,2)
 *   metadata      — JSON с дополнительными данными
 *   status        — статус обработки (0-5, см. STATUS_*)
 *   assigned_to   — ID пользователя MODX, которому назначена тема
 *   notes         — заметки редактора
 *   created_at    — дата создания записи
 *   updated_at    — дата последнего обновления
 *
 * @package SpookyApp
 * @since   1.0.0
 * ═══════════════════════════════════════════════════════════════
 *
 * @property int         $id
 * @property string      $topic_id
 * @property string      $source
 * @property string      $title
 * @property string      $url
 * @property string      $description
 * @property string      $category
 * @property string|null $published_at
 * @property float       $score
 * @property string      $metadata
 * @property int         $status
 * @property int         $assigned_to
 * @property string      $notes
 * @property string      $created_at
 * @property string|null $updated_at
 */
class SpookyAppTopic extends xPDOSimpleObject
{
    /* ═══════════════════════════════════════════════════════════
     *  Статусы обработки
     * ═══════════════════════════════════════════════════════════ */

    /** Новая — ещё не просмотрена */
    public const STATUS_NEW         = 0;

    /** Одобрена — прошла проверку, готова к работе */
    public const STATUS_APPROVED    = 1;

    /** В работе — рерайт/черновик создан */
    public const STATUS_IN_PROGRESS = 2;

    /** Опубликована — ресурс MODX создан и опубликован */
    public const STATUS_PUBLISHED   = 3;

    /** Отклонена — не подходит */
    public const STATUS_REJECTED    = 4;

    /** Архивная — устаревшая, автоматически архивирована */
    public const STATUS_ARCHIVED    = 5;

    /* ═══════════════════════════════════════════════════════════
     *  Справочники статусов
     * ═══════════════════════════════════════════════════════════ */

    /** Человекочитаемые метки статусов */
    public const STATUS_LABELS = [
        self::STATUS_NEW         => 'Новая',
        self::STATUS_APPROVED    => 'Одобрена',
        self::STATUS_IN_PROGRESS => 'В работе',
        self::STATUS_PUBLISHED   => 'Опубликована',
        self::STATUS_REJECTED    => 'Отклонена',
        self::STATUS_ARCHIVED    => 'Архив',
    ];

    /** CSS-классы для бейджей статусов (Bootstrap) */
    public const STATUS_CSS = [
        self::STATUS_NEW         => 'badge-secondary',
        self::STATUS_APPROVED    => 'badge-success',
        self::STATUS_IN_PROGRESS => 'badge-primary',
        self::STATUS_PUBLISHED   => 'badge-info',
        self::STATUS_REJECTED    => 'badge-danger',
        self::STATUS_ARCHIVED    => 'badge-dark',
    ];

    /** Иконки статусов (Fontawesome) */
    public const STATUS_ICONS = [
        self::STATUS_NEW         => 'fa-circle',
        self::STATUS_APPROVED    => 'fa-check-circle',
        self::STATUS_IN_PROGRESS => 'fa-spinner',
        self::STATUS_PUBLISHED   => 'fa-globe',
        self::STATUS_REJECTED    => 'fa-times-circle',
        self::STATUS_ARCHIVED    => 'fa-archive',
    ];

    /* ═══════════════════════════════════════════════════════════
     *  Пороги score
     * ═══════════════════════════════════════════════════════════ */

    /** Score, выше которого тема считается «крупной» (major) */
    public const SCORE_MAJOR_THRESHOLD = 50.0;

    /** Score, выше которого тема считается «вирусной» */
    public const SCORE_VIRAL_THRESHOLD = 80.0;


    /* ═══════════════════════════════════════════════════════════
     *  Методы работы со статусом
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Получить человекочитаемую метку статуса.
     *
     * @return string
     */
    public function getStatusLabel(): string
    {
        $status = (int) $this->get('status');
        return self::STATUS_LABELS[$status] ?? 'Неизвестно';
    }

    /**
     * Получить CSS-класс бейджа для текущего статуса.
     *
     * @return string
     */
    public function getStatusCss(): string
    {
        $status = (int) $this->get('status');
        return self::STATUS_CSS[$status] ?? 'badge-light';
    }

    /**
     * Получить иконку FontAwesome для текущего статуса.
     *
     * @return string
     */
    public function getStatusIcon(): string
    {
        $status = (int) $this->get('status');
        return self::STATUS_ICONS[$status] ?? 'fa-question-circle';
    }

    /**
     * Проверить, является ли статус финальным (нельзя изменить).
     *
     * @return bool
     */
    public function isFinalStatus(): bool
    {
        return in_array((int) $this->get('status'), [
            self::STATUS_PUBLISHED,
            self::STATUS_REJECTED,
            self::STATUS_ARCHIVED,
        ], true);
    }


    /* ═══════════════════════════════════════════════════════════
     *  Методы работы с metadata (JSON)
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Получить metadata как массив.
     *
     * @return array
     */
    public function getMetadataArray(): array
    {
        $raw = $this->get('metadata');

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Установить metadata из массива (кодирует в JSON).
     *
     * @param  array $data
     * @return void
     */
    public function setMetadataArray(array $data): void
    {
        $this->set('metadata', json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * Получить одно значение из metadata по ключу.
     *
     * Поддерживает точечную нотацию: 'credits.cast.0.name'
     *
     * @param  string $key     Ключ или путь через точку
     * @param  mixed  $default Значение по умолчанию
     * @return mixed
     */
    public function getMetaValue(string $key, $default = null)
    {
        $metadata = $this->getMetadataArray();
        $keys = explode('.', $key);
        $current = $metadata;

        foreach ($keys as $k) {
            if (!is_array($current) || !array_key_exists($k, $current)) {
                return $default;
            }
            $current = $current[$k];
        }

        return $current;
    }

    /**
     * Слить новые данные в metadata (array_merge).
     *
     * @param  array $data Данные для слияния
     * @return void
     */
    public function mergeMetadata(array $data): void
    {
        $existing = $this->getMetadataArray();
        $this->setMetadataArray(array_merge($existing, $data));
    }


    /* ═══════════════════════════════════════════════════════════
     *  Вспомогательные методы определения значимости
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Является ли тема «крупной» (high score).
     *
     * Тема считается крупной если:
     * - score >= SCORE_MAJOR_THRESHOLD, или
     * - в metadata явно установлен флаг is_major = true
     *
     * @return bool
     */
    public function isMajor(): bool
    {
        if ((float) $this->get('score') >= self::SCORE_MAJOR_THRESHOLD) {
            return true;
        }

        return (bool) $this->getMetaValue('is_major', false);
    }

    /**
     * Является ли тема «вирусной» (очень высокий score).
     *
     * @return bool
     */
    public function isViral(): bool
    {
        return (float) $this->get('score') >= self::SCORE_VIRAL_THRESHOLD;
    }

    /**
     * Получить количество источников, подтвердивших тему.
     *
     * Берётся из metadata.cross_source_count (устанавливается TopicScoringService).
     * Если не задано — считается 1 (только исходный источник).
     *
     * @return int
     */
    public function getCrossSourceCount(): int
    {
        return max(1, (int) $this->getMetaValue('cross_source_count', 1));
    }

    /**
     * Устарела ли тема (cached_at / created_at старше TTL).
     *
     * @param  int $ttl Допустимый возраст в секундах (по умолчанию 24 часа)
     * @return bool
     */
    public function isStale(int $ttl = 86400): bool
    {
        $createdAt = $this->get('created_at');
        if (empty($createdAt)) {
            return true;
        }

        $timestamp = is_numeric($createdAt) ? (int) $createdAt : strtotime($createdAt);
        if ($timestamp === false || $timestamp === 0) {
            return true;
        }

        return (time() - $timestamp) > $ttl;
    }


    /* ═══════════════════════════════════════════════════════════
     *  Статические справочники
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Получить список всех допустимых статусов.
     *
     * @return int[]
     */
    public static function getAvailableStatuses(): array
    {
        return [
            self::STATUS_NEW,
            self::STATUS_APPROVED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_PUBLISHED,
            self::STATUS_REJECTED,
            self::STATUS_ARCHIVED,
        ];
    }

    /**
     * Проверить допустимость значения статуса.
     *
     * @param  int $status
     * @return bool
     */
    public static function isValidStatus(int $status): bool
    {
        return array_key_exists($status, self::STATUS_LABELS);
    }


    /* ═══════════════════════════════════════════════════════════
     *  Хук save() — автоматические timestamps
     * ═══════════════════════════════════════════════════════════ */

    /**
     * {@inheritdoc}
     *
     * Автоматически проставляет created_at при создании.
     */
    public function save($cacheFlag = null): bool
    {
        $now = date('Y-m-d H:i:s');

        if (empty($this->get('created_at'))) {
            $this->set('created_at', $now);
        }

        return parent::save($cacheFlag);
    }
}