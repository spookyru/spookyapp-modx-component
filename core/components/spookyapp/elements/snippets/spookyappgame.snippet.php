<?php
// filepath: core/components/spookyapp/elements/snippets/spookyappgame.snippet.php

/**
 * SpookyApp — Game Snippet
 *
 * ═══════════════════════════════════════════════════════════════
 * Вывод информации об игре.
 *
 * Использование:
 *   [[SpookyAppGame? &id=`3498` &type=`rawg`]]
 *   [[SpookyAppGame? &id=`123` &type=`db`]]
 *
 * Параметры:
 *   &id       (int, required)  — ID игры (rawg_id или chunk_id)
 *   &type     (string, 'db')   — 'rawg' или 'db'
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

use SpookyApp\Snippets\GameSnippet;

$corePath = $modx->getOption(
    'spookyapp.core_path',
    null,
    MODX_CORE_PATH . 'components/spookyapp/'
);

$autoload = $corePath . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (!class_exists(GameSnippet::class)) {
    $modx->log(MODX_LOG_LEVEL_ERROR, '[SpookyApp] Класс GameSnippet не найден. Проверьте autoload.');
    return '';
}

$snippet = new GameSnippet($this->modx, $scriptProperties ?? []);
return $snippet->run();