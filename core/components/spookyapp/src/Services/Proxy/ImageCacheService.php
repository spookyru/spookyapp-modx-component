<?php

declare(strict_types=1);

namespace SpookyApp\Services\Proxy;

/**
 * ImageCacheService — скачивает и кэширует изображения TMDB на локальный диск.
 *
 * Путь кэша: {modxRoot}/images/remote/tmdb/{size}/{filename}
 * Web-путь:  /images/remote/tmdb/{size}/{filename}
 *
 * Поддерживаемые входные форматы URL:
 *   1. Прямой TMDB URL:   https://image.tmdb.org/t/p/w500/abc.jpg
 *   2. Старый imgproxy:   /assets/components/spookyapp/imgproxy.php?p=%2Ft%2Fp%2Fw500%2Fabc.jpg
 *   3. Уже кэшированный: /images/remote/tmdb/w500/abc.jpg  (возвращается как есть)
 *
 * Изображения скачиваются через ProxyConfig (Amsterdam relay если включён).
 * При ошибке загрузки возвращает оригинальный URL.
 */
final class ImageCacheService
{
    private const CACHE_WEB_PREFIX = '/images/remote/tmdb';

    private ProxyConfig $proxy;
    private string $cacheDir; // абсолютный путь к корню кэша (без trailing slash)

    private const ALLOWED_MIME = [
        'image/jpeg', 'image/jpg', 'image/png',
        'image/webp', 'image/gif', 'image/svg+xml',
    ];

    public function __construct(ProxyConfig $proxy, string $modxRoot)
    {
        $this->proxy    = $proxy;
        $this->cacheDir = rtrim($modxRoot, '/') . '/images/remote/tmdb';
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Вернуть локальный веб-путь если файл уже закэширован, иначе imgproxy URL.
     *
     * НЕ скачивает файл — только проверяет наличие на диске.
     * Реальное скачивание и сохранение происходит в imgproxy.php (ленивый кеш).
     *
     * @param string $url Любой из поддерживаемых форматов (см. выше)
     * @return string     Локальный веб-путь или imgproxy URL
     */
    public function cache(string $url): string
    {
        // 1. Уже локальный кэшированный путь
        if (str_starts_with($url, self::CACHE_WEB_PREFIX . '/')) {
            return $url;
        }

        // 2. Прямой TMDB URL
        if (str_contains($url, '://image.tmdb.org/')) {
            return $this->resolveFromPath(parse_url($url, PHP_URL_PATH) ?? '', $url);
        }

        // 3. Старый imgproxy URL — извлекаем TMDB path из параметра p=
        if (str_contains($url, 'imgproxy.php?p=')) {
            $pos  = strpos($url, '?p=');
            $path = urldecode(substr($url, $pos + 3));
            return $this->resolveFromPath($path, 'https://image.tmdb.org' . $path);
        }

        return $url;
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Проверить наличие файла на диске. Если есть — вернуть локальный путь.
     * Если нет — вернуть imgproxy URL (файл будет скачан и сохранён позже).
     *
     * @param string $tmdbPath TMDB path вида /t/p/w500/abc.jpg
     * @param string $tmdbUrl  Полный TMDB URL (для формирования imgproxy fallback)
     */
    private function resolveFromPath(string $tmdbPath, string $tmdbUrl): string
    {
        if (!preg_match('#^/t/p/([a-z0-9]+)/([a-zA-Z0-9_./-]+)$#', $tmdbPath, $m)) {
            return $this->proxy->rewriteImageForBrowser($tmdbUrl);
        }

        $size     = $m[1];
        $filename = basename($m[2]);

        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $filename)) {
            return $this->proxy->rewriteImageForBrowser($tmdbUrl);
        }

        $localFile = $this->cacheDir . '/' . $size . '/' . $filename;
        $webPath   = self::CACHE_WEB_PREFIX . '/' . $size . '/' . $filename;

        // Файл уже скачан imgproxy.php ранее — возвращаем локальный путь для Thumb3x
        if (file_exists($localFile) && filesize($localFile) > 0) {
            return $webPath;
        }

        // Файла нет — возвращаем imgproxy URL. imgproxy.php скачает и сохранёт на диск.
        return $this->proxy->rewriteImageForBrowser($tmdbUrl);
    }
}
