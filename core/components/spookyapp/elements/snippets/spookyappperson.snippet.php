<?php
// filepath: core/components/spookyapp/elements/snippets/spookyappperson.snippet.php

/**
 * SpookyApp — Person Snippet
 *
 * ═══════════════════════════════════════════════════════════════
 * Вывод информации о персоне (актёр, режиссёр).
 *
 * Использование:
 *   [[SpookyAppPerson? &id=`287` &type=`tmdb`]]
 *   [[SpookyAppPerson? &id=`50` &type=`db`]]
 *
 * Параметры:
 *   &id       (int, required)  — ID персоны (tmdb_id или chunk_id)
 *   &type     (string, 'db')   — 'tmdb' или 'db'
 *   &template (string, '')     — кастомный chunk
 *   &nocache  (bool, false)    — пропустить кеш
 *
 * @var modX $modx
 * @var array $scriptProperties
 *
 * @package SpookyApp
 * @since   1.0.0
 * ═══════════════════════════════════════════════════════════════
 */

use SpookyApp\Snippets\PersonSnippet;

$corePath = $modx->getOption(
    'spookyapp.core_path',
    null,
    MODX_CORE_PATH . 'components/spookyapp/'
);

$autoload = $corePath . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (!class_exists(PersonSnippet::class)) {
    $modx->log(MODX_LOG_LEVEL_ERROR, '[SpookyApp] Класс PersonSnippet не найден. Проверьте autoload.');
    return '';
}

$snippet = new PersonSnippet($this->modx, $scriptProperties ?? []);
return $snippet->run();