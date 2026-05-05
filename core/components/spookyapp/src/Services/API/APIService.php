<?php

declare(strict_types=1);

namespace SpookyApp\Services\API;

use MODX\Revolution\modX;
use SpookyApp\Services\Cache\CacheService;
use SpookyApp\Services\Proxy\ProxyConfig;
//use Throwable;

/**
 * Базовый абстрактный класс для всех API-сервисов.
 *
 * Предоставляет единый интерфейс для HTTP-запросов,
 * кеширования и обработки ошибок.
 */
abstract class APIService
{
    protected modX $modx;
    protected CacheService $cache;
    protected ProxyConfig $proxy;

    public function __construct(modX $modx, CacheService $cache)
    {
        $this->modx  = $modx;
        $this->cache = $cache;
        $this->proxy = ProxyConfig::fromModx($modx);
    }

    /**
     * Выполнить GET-запрос к внешнему API.
     *
     * @param string               $url     Полный URL запроса
     * @param array<string, string> $headers Заголовки запроса
     * @param int                  $timeout Таймаут в секундах
     * @return array{success: bool, data: mixed, error: string|null}
     */
    protected function httpGet(string $url, array $headers = [], int $timeout = 12): array
    {
        return $this->httpRequest('GET', $url, $headers, null, $timeout);
    }

    /**
     * Выполнить POST-запрос с JSON body к внешнему API.
     *
     * @param string                $url     Полный URL запроса
     * @param array<string, string> $headers Заголовки запроса
     * @param array<string, mixed>  $body    Тело запроса (будет закодировано в JSON)
     * @param int                   $timeout Таймаут в секундах
     * @return array{success: bool, data: mixed, error: string|null}
     */
    protected function httpPostJson(string $url, array $headers = [], array $body = [], int $timeout = 30): array
    {
        $headers[] = 'Content-Type: application/json';
        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->httpRequest('POST', $url, $headers, $jsonBody, $timeout);
    }

    /**
     * Выполнить POST-запрос с form-urlencoded body к внешнему API.
     *
     * @param string                $url     Полный URL запроса
     * @param array<string, string> $headers Заголовки запроса
     * @param array<string, mixed>  $params  Параметры формы
     * @param int                   $timeout Таймаут в секундах
     * @return array{success: bool, data: mixed, raw: string|null, error: string|null}
     */
    protected function httpPostForm(string $url, array $headers = [], array $params = [], int $timeout = 30): array
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $postBody = http_build_query($params);

        return $this->httpRequest('POST', $url, $headers, $postBody, $timeout, false);
    }

    /**
     * Выполнить HTTP-запрос.
     *
     * @param string      $method   HTTP метод (GET, POST)
     * @param string      $url      Полный URL
     * @param array       $headers  Заголовки
     * @param string|null $body     Тело запроса
     * @param int         $timeout  Таймаут
     * @param bool        $decodeJson Декодировать ли ответ как JSON
     * @return array{success: bool, data: mixed, raw: string|null, error: string|null}
     */
    protected function httpRequest(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 30,
        bool $decodeJson = true
    ): array {
        // Применяем прокси-перезапись URL (если proxy_enabled=1 в настройках MODX)
        $originalUrl = $url;
        $proxyResult = $this->proxy->rewrite($url);
        $url         = $proxyResult['url'];
        $headers     = array_merge($headers, $proxyResult['headers']);

        $proxyApplied = ($url !== $originalUrl);
        $logUrl       = $this->maskSensitiveUrl($url);
        $startTime    = microtime(true);

        if ($proxyApplied) {
            $this->modx->log(modX::LOG_LEVEL_INFO,
                "[APIService][PROXY] → {$method} {$logUrl}" .
                " (original: " . $this->maskSensitiveUrl($originalUrl) . ", secret: " .
                (empty($proxyResult['headers']) ? 'NO HEADER — check proxy_secret setting' : 'OK') . ")");
        } elseif ($this->proxy->isActive()) {
            $this->modx->log(modX::LOG_LEVEL_WARN,
                "[APIService][PROXY] Domain NOT in map, going DIRECT: " .
                $this->maskSensitiveUrl($originalUrl));
        } else {
            $this->modx->log(modX::LOG_LEVEL_INFO, "[APIService] → {$method} {$logUrl}");
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,   // fail fast on unreachable hosts (was 10)
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SpookyApp/1.0',
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $elapsed = (int)round((microtime(true) - $startTime) * 1000);

        if ($response === false || !empty($curlError)) {
            $prefix = $proxyApplied ? '[APIService][PROXY]' : '[APIService]';
            $this->modx->log(modX::LOG_LEVEL_ERROR, "{$prefix} cURL ошибка для {$logUrl}: {$curlError}");
            return ['success' => false, 'data' => null, 'raw' => null, 'error' => $curlError];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $prefix = $proxyApplied ? '[APIService][PROXY]' : '[APIService]';
            $this->modx->log(modX::LOG_LEVEL_ERROR, "{$prefix} HTTP {$httpCode} ({$elapsed}ms) для {$logUrl}: " . mb_substr((string)$response, 0, 500));
            return ['success' => false, 'data' => null, 'raw' => (string)$response, 'error' => "HTTP {$httpCode}"];
        }

        $prefix = $proxyApplied ? '[APIService][PROXY]' : '[APIService]';
        $this->modx->log(modX::LOG_LEVEL_INFO, "{$prefix} ← HTTP {$httpCode} ({$elapsed}ms, " . strlen((string)$response) . " байт): {$logUrl}");
        $this->modx->log(modX::LOG_LEVEL_DEBUG, "[APIService] Response preview: " . mb_substr((string)$response, 0, 2000));

        if (!$decodeJson) {
            return ['success' => true, 'data' => null, 'raw' => (string)$response, 'error' => null];
        }

        $decoded = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[APIService] JSON decode ошибка для {$logUrl}: " . json_last_error_msg());
            return ['success' => false, 'data' => null, 'raw' => (string)$response, 'error' => 'JSON decode error: ' . json_last_error_msg()];
        }

        return ['success' => true, 'data' => $decoded, 'raw' => (string)$response, 'error' => null];
    }

    /**
     * Получить данные из кеша или выполнить запрос.
     *
     * @param string   $cacheKey Ключ кеша
     * @param int      $ttl      TTL в секундах
     * @param callable $fetcher  Функция для получения данных при промахе кеша
     * @return mixed
     */
    protected function cachedRequest(string $cacheKey, int $ttl, callable $fetcher): mixed
    {
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $this->modx->log(modX::LOG_LEVEL_DEBUG, "[APIService] Данные из кеша: {$cacheKey}");
            return $cached;
        }

        $data = $fetcher();

        if ($data !== null) {
            $this->cache->set($cacheKey, $data, $ttl);
        }

        return $data;
    }

    /**
     * Построить URL с query-параметрами.
     *
     * @param string               $baseUrl Базовый URL
     * @param array<string, mixed> $params  Query-параметры
     * @return string
     */
    protected function buildUrl(string $baseUrl, array $params = []): string
    {
        if (empty($params)) {
            return $baseUrl;
        }

        return $baseUrl . '?' . http_build_query($params);
    }

    /**
     * Получить значение из системных настроек MODX (с префиксом spookyapp.).
     *
     * @param string $key     Ключ без префикса (например: 'tmdb_bearer_token')
     * @param mixed  $default Значение по умолчанию
     * @return mixed
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->modx->getOption('spookyapp.' . $key, null, $default);
    }

    /**
     * Удобный алиас для логирования.
     *
     * @param string $message Сообщение
     * @param int    $level   Уровень (modX::LOG_LEVEL_*)
     */
    protected function log(string $message, int $level = modX::LOG_LEVEL_INFO): void
    {
        $this->modx->log($level, '[' . static::class . '] ' . $message);
    }

    /**
     * Замаскировать чувствительные параметры (API-ключи) в URL для логирования.
     */
    private function maskSensitiveUrl(string $url): string
    {
        return preg_replace(
            '/([?&](?:key|api_?key|api_?token|access_?token|token|rapidapi[-_]key|apikey)=)[^&]*/i',
            '$1***',
            $url
        ) ?? $url;
    }
}