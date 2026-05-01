<?php
// filepath: core/components/spookyapp/elements/snippets/spookyappmovie.snippet.php

/**
 * SpookyApp — Movie Snippet
 *
 * ═══════════════════════════════════════════════════════════════
 * Вывод информации о фильме.
 *
 * Использование:
 *   [[SpookyAppMovie? &id=`123` &type=`tmdb`]]
 *   [[SpookyAppMovie? &id=`456` &type=`db`]]
 *   [[SpookyAppMovie? &id=`789` &type=`tmdb` &template=`myCustomMovieChunk`]]
 *
 * Параметры:
 *   &id       (int, required)  — ID фильма (tmdb_id или chunk_id из БД)
 *   &type     (string, 'db')   — 'tmdb' (прямой запрос) или 'db' (из кеша/БД)
 *   &template (string, '')     — кастомный chunk-шаблон
 *   &nocache  (bool, false)    — пропустить кеш
 *
 * @var modX $modx
 * @var array $scriptProperties
 *
 * @package SpookyApp
 * @since   1.0.0
 * ═══════════════════════════════════════════════════════════════
 */

use SpookyApp\Snippets\MovieSnippet;

// ── Autoload ────────────────────────────────────────────────────
$corePath = $modx->getOption(
    'spookyapp.core_path',
    null,
    MODX_CORE_PATH . 'components/spookyapp/'
);

$autoload = $corePath . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// ── Run ─────────────────────────────────────────────────────────
if (!class_exists(MovieSnippet::class)) {
    $modx->log(MODX_LOG_LEVEL_ERROR, '[SpookyApp] Класс MovieSnippet не найден. Проверьте autoload.');
    return '';
}

$snippet = new MovieSnippet($this->modx, $scriptProperties ?? []);
return $snippet->run();