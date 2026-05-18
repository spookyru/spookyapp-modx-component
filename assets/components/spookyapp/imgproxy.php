<?php
/**
 * SpookyApp Image Proxy — imgproxy.php
 *
 * Fetches TMDB images on behalf of the browser so that IP-based
 * blocks (HTTP 451) are bypassed via the Amsterdam relay server.
 *
 * URL format:
 *   /assets/components/spookyapp/imgproxy.php?p=/t/p/w500/abc.jpg
 *
 * Security:
 *   - Only accepts paths starting with /t/p/ (TMDB image paths)
 *   - No arbitrary URL fetching — domain is hardcoded to image.tmdb.org
 *   - If proxy_enabled=1, fetches through Amsterdam relay (server-to-server)
 *   - If proxy_enabled=0, fetches directly from image.tmdb.org
 */

declare(strict_types=1);

// ── Validate input ────────────────────────────────────────────────────────────

$path = isset($_GET['p']) ? (string)$_GET['p'] : '';

// Must be a valid TMDB image path: /t/p/{size}/{filename}
// Only allow alphanumeric, hyphens, underscores, slashes, dots in filename
if (!preg_match('#^/t/p/[a-z0-9]+/[a-zA-Z0-9_./-]+$#', $path)) {
    http_response_code(400);
    exit;
}

// ── Read proxy settings from MODX config (lightweight, no full MODX boot) ────

$rootPath = dirname(__DIR__, 3) . '/'; // assets/components/spookyapp/ → site root

$proxyEnabled = false;
$proxyUrl     = '';
$proxySecret  = '';

$configCore = $rootPath . 'config.core.php';
if (file_exists($configCore)) {
    /** @noinspection PhpIncludeInspection */
    require_once $configCore; // defines MODX_CORE_PATH
}

if (defined('MODX_CORE_PATH') && file_exists(MODX_CORE_PATH . 'config/config.inc.php')) {
    /** @noinspection PhpIncludeInspection */
    require_once MODX_CORE_PATH . 'config/config.inc.php';
    // $database_server, $dbase, $database_user, $database_password, $table_prefix are now set

    try {
        $dsn = 'mysql:host=' . $database_server . ';dbname=' . $dbase . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $database_user, $database_password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 3,
        ]);

        $keys = ['spookyapp.proxy_enabled', 'spookyapp.proxy_url', 'spookyapp.proxy_secret'];
        $in   = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare(
            "SELECT `key`, `value` FROM `{$table_prefix}system_settings` WHERE `key` IN ({$in})"
        );
        $stmt->execute($keys);
        $settings = array_column($stmt->fetchAll(), 'value', 'key');

        $proxyEnabled = (bool)($settings['spookyapp.proxy_enabled'] ?? false);
        $proxyUrl     = (string)($settings['spookyapp.proxy_url']     ?? '');
        $proxySecret  = (string)($settings['spookyapp.proxy_secret']  ?? '');
    } catch (\Throwable $e) {
        // DB error — fall back to direct fetch
        $proxyEnabled = false;
    }
}

// ── Concurrency limiter ───────────────────────────────────────────────────────
// Ограничиваем число одновременных запросов к TMDB через прокси (защита от 499).
// Максимум $maxConcurrent параллельных cURL-запросов; остальные ждут до 8 сек.

$maxConcurrent = 3;
$lockDir       = sys_get_temp_dir();
$lockFP        = null;
$lockIdx       = null;
$lockDeadline  = time() + 8;

do {
    for ($i = 0; $i < $maxConcurrent; $i++) {
        $lf = $lockDir . '/spookyapp_img_' . $i . '.lock';
        $fp = @fopen($lf, 'c');
        if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
            $lockFP  = $fp;
            $lockIdx = $i;
            break 2;
        }
        if ($fp) {
            fclose($fp);
        }
    }
    usleep(200000); // 200ms
} while (time() < $lockDeadline);

if ($lockFP === null) {
    http_response_code(503);
    header('Retry-After: 5');
    header('Content-Type: text/plain');
    exit('Image proxy busy, retry shortly');
}

register_shutdown_function(static function () use ($lockFP): void {
    flock($lockFP, LOCK_UN);
    fclose($lockFP);
});

// ── Build fetch URL ───────────────────────────────────────────────────────────

$imageUrl   = 'https://image.tmdb.org' . $path;
$headers    = [];
$fetchUrl   = $imageUrl;

if ($proxyEnabled && $proxyUrl !== '' && $proxySecret !== '') {
    // Route through Amsterdam relay: /tmdb-img + path
    $fetchUrl  = rtrim($proxyUrl, '/') . '/tmdb-img' . $path;
    $headers[] = 'X-Proxy-Secret: ' . $proxySecret;
}

// ── Fetch image via cURL ──────────────────────────────────────────────────────

/**
 * Fetch a URL via cURL. Returns [body, httpCode, contentType] or [false, 0, ''] on curl error.
 */
$curlFetch = static function (string $url, array $httpHeaders) use ($imageUrl): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'SpookyApp-ImageProxy/1.0',
        CURLOPT_HTTPHEADER     => $httpHeaders,
    ]);
    $body     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctRaw    = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$body, $httpCode, $ctRaw];
};

$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];

$isValidImageResponse = static function ($body, int $httpCode, string $ctRaw) use ($allowedTypes): bool {
    if ($body === false || $httpCode < 200 || $httpCode >= 300) {
        return false;
    }
    $ct = strtolower(trim(explode(';', $ctRaw)[0]));
    return in_array($ct, $allowedTypes, true);
};

// ── Fetch (proxy first, fall back to direct) ──────────────────────────────────

[$body, $httpCode, $ctRaw] = $curlFetch($fetchUrl, $headers);

// If proxy was used and failed, retry directly against image.tmdb.org
if (!$isValidImageResponse($body, $httpCode, $ctRaw) && $fetchUrl !== $imageUrl) {
    [$body, $httpCode, $ctRaw] = $curlFetch($imageUrl, []);
}

// ── Log request + response ────────────────────────────────────────────────────
$logFile = $rootPath . 'core/cache/logs/imgproxy.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logLine = date('[Y-m-d H:i:s]')
    . ' GET ' . $fetchUrl
    . ' → HTTP ' . $httpCode
    . ' size=' . strlen((string)$body)
    . ($fetchUrl !== $imageUrl ? ' [via proxy]' : ' [direct]')
    . PHP_EOL;
@file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

// ── Validate response ─────────────────────────────────────────────────────────

if (!$isValidImageResponse($body, $httpCode, $ctRaw)) {
    http_response_code(502);
    exit;
}

$ct = strtolower(trim(explode(';', $ctRaw)[0]));

// ── Save to local disk cache ──────────────────────────────────────────────────
// Saves to {modxRoot}/images/remote/tmdb/{size}/{filename} so that
// ImageCacheService can detect the file on next request and return a local path.

if (preg_match('#^/t/p/([a-z0-9]+)/([a-zA-Z0-9_./-]+)$#', $path, $pm)) {
    $cSize     = $pm[1];
    $cFilename = basename($pm[2]);
    if (preg_match('/^[a-zA-Z0-9_.-]+$/', $cFilename)) {
        $cacheDir  = rtrim($rootPath, '/') . '/images/remote/tmdb/' . $cSize;
        $cacheFile = $cacheDir . '/' . $cFilename;
        if (!file_exists($cacheFile)) {
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            if (is_dir($cacheDir)) {
                @file_put_contents($cacheFile, $body);
            }
        }
    }
}

// ── Stream image to browser ───────────────────────────────────────────────────

header('Content-Type: ' . $ct);
header('Content-Length: ' . strlen($body));
header('Cache-Control: public, max-age=604800, immutable'); // 7 days
header('X-Content-Type-Options: nosniff');

echo $body;
