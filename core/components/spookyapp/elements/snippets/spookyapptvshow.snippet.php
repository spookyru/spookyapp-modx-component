<?php
// filepath: core/components/spookyapp/elements/snippets/spookyapptvshow.snippet.php

/**
 * SpookyApp — TV Show Snippet
 *
 * ═══════════════════════════════════════════════════════════════
 * Вывод информации о TV-сериале.
 *
 * Использование:
 *   [[SpookyAppTVShow? &id=`456` &type=`tmdb`]]
 *   [[SpookyAppTVShow? &id=`789` &type=`db`]]
 *
 * Параметры:
 *   &id       (int, required)  — ID сериала
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

use SpookyApp\Snippets\TVShowSnippet;

$corePath = $modx->getOption(
    'spookyapp.core_path',
    null,
    MODX_CORE_PATH . 'components/spookyapp/'
);

$autoload = $corePath . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (!class_exists(TVShowSnippet::class)) {
    $modx->log(MODX_LOG_LEVEL_ERROR, '[SpookyApp] Класс TVShowSnippet не найден. Проверьте autoload.');
    return '';
}

$snippet = new TVShowSnippet($this->modx, $scriptProperties ?? []);
return $snippet->run();