<?php

declare(strict_types=1);

namespace SpookyApp\Services\Proxy;

use MODX\Revolution\modX;

/**
 * ProxyConfig — конфигурация прокси-сервера для API-запросов.
 *
 * Читает настройки из MODX (spookyapp.proxy_enabled, spookyapp.proxy_url,
 * spookyapp.proxy_secret) и предоставляет метод rewrite() для перезаписи
 * URL перед отправкой запроса через прокси.
 *
 * Архитектура прокси:
 * - nginx принимает запросы только с IP продакшен-сервера
 * - Каждый API-домен отображается на отдельный path-prefix на прокси
 * - Авторизация через заголовок X-Proxy-Secret
 * - Это НЕ универсальный VPN/прокси — только конкретные разрешённые домены
 *
 * Пример:
 *   https://api.themoviedb.org/3/movie/123 → http://1.2.3.4:8080/tmdb/3/movie/123
 *   + заголовок X-Proxy-Secret: <secret>
 */
final class ProxyConfig
{
    /**
     * Маппинг: хост оригинального API → path-prefix на прокси-сервере.
     *
     * Соответствует location-блокам в конфиге nginx на сервере.
     * Изменять здесь и в nginx-конфиге синхронно.
     */
    private const DOMAIN_MAP = [
        'api.themoviedb.org'                          => '/tmdb',
        'api.rawg.io'                                 => '/rawg',
        'api.reddit.com'                              => '/reddit-api',
        'oauth.reddit.com'                            => '/reddit-oauth',
        'www.reddit.com'                              => '/reddit',
        'games-details.p.rapidapi.com'                => '/rapidapi-games',
        'real-time-news-data.p.rapidapi.com'          => '/rapidapi-news',
        'sport.p.rapidapi.com'                        => '/rapidapi-sport',
        'api-football-v1.p.rapidapi.com'              => '/rapidapi-football',
        'mobile-phone-specs-database.p.rapidapi.com'  => '/rapidapi-phones',
        'mobile-phones2.p.rapidapi.com'               => '/rapidapi-phones2',
        'amazon23.p.rapidapi.com'                     => '/rapidapi-amazon',
        'thenewsapi.com'                              => '/thenewsapi',
        'newsdata.io'                                  => '/newsdata',
        'image.tmdb.org'                              => '/tmdb-img',
    ];

    private bool   $enabled;
    private string $baseUrl; // e.g. "http://1.2.3.4:8080" — без trailing slash
    private string $secret;  // значение заголовка X-Proxy-Secret

    private function __construct(bool $enabled, string $baseUrl, string $secret)
    {
        $this->enabled = $enabled;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->secret  = $secret;
    }

    /**
     * Создать ProxyConfig из настроек MODX.
     */
    public static function fromModx(modX $modx): self
    {
        $enabled = (bool)$modx->getOption('spookyapp.proxy_enabled', null, false);
        $baseUrl = (string)$modx->getOption('spookyapp.proxy_url', null, '');
        $secret  = (string)$modx->getOption('spookyapp.proxy_secret', null, '');

        return new self($enabled, $baseUrl, $secret);
    }

    /**
     * Активен ли прокси (enabled=true И указаны URL и secret).
     */
    public function isActive(): bool
    {
        return $this->enabled && $this->baseUrl !== '' && $this->secret !== '';
    }

    /**
     * Перезаписать URL для прохождения через прокси.
     *
     * Если прокси не активен, домен не в DOMAIN_MAP, или домен
     * относится к Яндексу (*.yandex.*, *.yandex.com, *.ya.ru) —
     * возвращает оригинал без изменений (прямой запрос).
     *
     * @return array{url: string, headers: list<string>}
     */
    public function rewrite(string $url): array
    {
        if (!$this->isActive()) {
            return ['url' => $url, 'headers' => []];
        }

        $parsed = parse_url($url);
        $host   = strtolower($parsed['host'] ?? '');

        // Яндекс всегда напрямую — не зависит от прокси-настроек
        if ($this->isYandexHost($host)) {
            return ['url' => $url, 'headers' => []];
        }

        if (!isset(self::DOMAIN_MAP[$host])) {
            return ['url' => $url, 'headers' => []];
        }

        $prefix   = self::DOMAIN_MAP[$host];
        $path     = $parsed['path']     ?? '/';
        $query    = isset($parsed['query'])    ? '?' . $parsed['query']    : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return [
            'url'     => $this->baseUrl . $prefix . $path . $query . $fragment,
            'headers' => ['X-Proxy-Secret: ' . $this->secret],
        ];
    }

    /**
     * Перезаписать URL изображения TMDB для отдачи браузеру через локальный imgproxy.
     *
     * Используется в процессорах перед отправкой данных на фронтенд:
     * https://image.tmdb.org/t/p/w500/abc.jpg
     *   → /assets/components/spookyapp/imgproxy.php?p=%2Ft%2Fp%2Fw500%2Fabc.jpg
     *
     * Если прокси не активен — возвращает оригинальный URL без изменений.
     */
    public function rewriteImageForBrowser(string $url): string
    {
        if (!$this->isActive()) {
            return $url;
        }

        if (!str_contains($url, '://image.tmdb.org/')) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '/';

        return '/assets/components/spookyapp/imgproxy.php?p=' . urlencode($path);
    }

    /**
     * Получить значение секрета (без имени заголовка).
     * Используется при настройке Guzzle-клиентов.
     */
    public function getSecretValue(): string
    {
        return $this->secret;
    }

    /**
     * Вернуть DOMAIN_MAP (для генерации nginx-конфига или дебага).
     *
     * @return array<string, string>
     */
    public static function getDomainMap(): array
    {
        return self::DOMAIN_MAP;
    }

    /**
     * Проверить, относится ли хост к Яндексу.
     * Яндекс-запросы всегда идут напрямую
     * и не блокируется по локации.
     */
    private function isYandexHost(string $host): bool
    {
        return str_ends_with($host, '.yandex.ru')
            || str_ends_with($host, '.yandex.com')
            || str_ends_with($host, '.yandex.net')
            || str_ends_with($host, '.ya.ru')
            || $host === 'yandex.ru'
            || $host === 'yandex.com'
            || $host === 'yandex.net'
            || $host === 'ya.ru';
    }
}
