<?php

declare(strict_types=1);

namespace SpookyApp\Services\Cache;

use MODX\Revolution\modX;
use PDO;
use Throwable;

/**
 * Сервис кеширования API ответов.
 *
 * Работает с таблицей modx_spookyapp_cache.
 * Автоматически очищает просроченные записи при чтении.
 *
 * Структура таблицы:
 * - `cache_key` VARCHAR(191) PRIMARY KEY
 * - `data` LONGTEXT NOT NULL
 * - `ttl` INT UNSIGNED NOT NULL DEFAULT 3600
 * - `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 * - `expires_at` DATETIME NOT NULL
 */
class CacheService
{
    private const TABLE_NAME = 'sitespk_spookyapp_cache';
    private const DEFAULT_TTL = 3600;

    private modX $modx;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    /**
     * Получить значение из кеша по ключу.
     *
     * Автоматически удаляет просроченные записи перед чтением.
     *
     * @param string $key Ключ кеша
     * @return mixed|null Декодированные данные или null, если кеш не найден / просрочен
     */
    public function get(string $key): mixed
    {
        $this->clearExpired();

        $table = self::TABLE_NAME;
        $sql = "SELECT `data` FROM `{$table}` WHERE `cache_key` = :key AND `expires_at` > NOW() LIMIT 1";

        try {
            $stmt = $this->modx->prepare($sql);
            if (!$stmt) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[CacheService] Не удалось подготовить запрос GET для ключа: {$key}");
                return null;
            }

            $stmt->bindValue(':key', $key, PDO::PARAM_STR);
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->modx->log(modX::LOG_LEVEL_DEBUG, "[CacheService] Промах кеша для ключа: {$key}");
                return null;
            }

            $this->modx->log(modX::LOG_LEVEL_DEBUG, "[CacheService] Попадание кеша для ключа: {$key}");

            return json_decode($row['data'], true);
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[CacheService] Ошибка при чтении кеша для ключа '{$key}': {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Записать значение в кеш.
     *
     * Если ключ уже существует, запись перезаписывается.
     *
     * @param string $key  Ключ кеша
     * @param mixed  $data Данные для кеширования (будут сериализованы в JSON)
     * @param int    $ttl  Время жизни в секундах (по умолчанию 3600)
     * @return bool Успешность операции
     */
    public function set(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
    {
        $table = self::TABLE_NAME;
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $sql = "INSERT INTO `{$table}` (`cache_key`, `data`, `ttl`, `created_at`, `expires_at`)
                VALUES (:key, :data, :ttl, NOW(), DATE_ADD(NOW(), INTERVAL :ttl_interval SECOND))
                ON DUPLICATE KEY UPDATE
                    `data` = VALUES(`data`),
                    `ttl` = VALUES(`ttl`),
                    `created_at` = NOW(),
                    `expires_at` = VALUES(`expires_at`)";

        try {
            $stmt = $this->modx->prepare($sql);
            if (!$stmt) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[CacheService] Не удалось подготовить запрос SET для ключа: {$key}");
                return false;
            }

            $stmt->bindValue(':key', $key, PDO::PARAM_STR);
            $stmt->bindValue(':data', $encoded, PDO::PARAM_STR);
            $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
            $stmt->bindValue(':ttl_interval', $ttl, PDO::PARAM_INT);
            $result = $stmt->execute();

            if ($result) {
                $this->modx->log(modX::LOG_LEVEL_DEBUG, "[CacheService] Записан кеш для ключа: {$key} (TTL: {$ttl}с)");
            } else {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[CacheService] Не удалось записать кеш для ключа: {$key}");
            }

            return $result;
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[CacheService] Ошибка при записи кеша для ключа '{$key}': {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Удалить запись кеша по ключу.
     *
     * @param string $key Ключ кеша
     * @return bool Успешность операции
     */
    public function delete(string $key): bool
    {
        $table = self::TABLE_NAME;
        $sql = "DELETE FROM `{$table}` WHERE `cache_key` = :key";

        try {
            $stmt = $this->modx->prepare($sql);
            if (!$stmt) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, "[CacheService] Не удалось подготовить запрос DELETE для ключа: {$key}");
                return false;
            }

            $stmt->bindValue(':key', $key, PDO::PARAM_STR);
            $result = $stmt->execute();

            if ($result) {
                $this->modx->log(modX::LOG_LEVEL_INFO, "[CacheService] Удалён кеш для ключа: {$key}");
            }

            return $result;
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[CacheService] Ошибка при удалении кеша для ключа '{$key}': {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Полная очистка всей таблицы кеша.
     *
     * @return bool Успешность операции
     */
    public function clear(): bool
    {
        $table = self::TABLE_NAME;
        $sql = "TRUNCATE TABLE `{$table}`";

        try {
            $result = $this->modx->exec($sql);

            $this->modx->log(modX::LOG_LEVEL_WARN, '[CacheService] Таблица кеша полностью очищена');

            return $result !== false;
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[CacheService] Ошибка при полной очистке кеша: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Удалить все просроченные записи из таблицы кеша.
     *
     * Вызывается автоматически при каждом чтении (get).
     *
     * @return void
     */
    private function clearExpired(): void
    {
        $table = self::TABLE_NAME;
        $sql = "DELETE FROM `{$table}` WHERE `expires_at` <= NOW()";

        try {
            $stmt = $this->modx->prepare($sql);
            if ($stmt && $stmt->execute()) {
                $deletedCount = $stmt->rowCount();
                if ($deletedCount > 0) {
                    $this->modx->log(modX::LOG_LEVEL_DEBUG, "[CacheService] Очищено просроченных записей: {$deletedCount}");
                }
            }
        } catch (Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[CacheService] Ошибка при очистке просроченных записей: {$e->getMessage()}");
        }
    }
}