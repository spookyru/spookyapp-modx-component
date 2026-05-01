<?php
// filepath: core/components/spookyapp/elements/snippets/spookyappproduct.snippet.php

/**
 * SpookyApp — Product Snippet
 *
 * ═══════════════════════════════════════════════════════════════
 * Вывод информации о товаре.
 *
 * Использование:
 *   [[SpookyAppProduct? &id=`200` &type=`db`]]
 *
 * Параметры:
 *   &id       (int, required)  — ID товара в spookyapp_chunks
 *   &type     (string, 'db')   — 'db' или API
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

use SpookyApp\Snippets\ProductSnippet;

$corePath = $modx->getOption(
    'spookyapp.core_path',
    null,
    MODX_CORE_PATH . 'components/spookyapp/'
);

$autoload = $corePath . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (!class_exists(ProductSnippet::class)) {
    $modx->log(MODX_LOG_LEVEL_ERROR, '[SpookyApp] Класс ProductSnippet не найден. Проверьте autoload.');
    return '';
}

$snippet = new ProductSnippet($this->modx, $scriptProperties ?? []);
return $snippet->run();