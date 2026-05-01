<?php
// filepath: core/components/spookyapp/elements/snippets/spookyappdevice.snippet.php

/**
 * SpookyApp — Device Snippet
 *
 * ═══════════════════════════════════════════════════════════════
 * Вывод информации об устройстве.
 *
 * Использование:
 *   [[SpookyAppDevice? &id=`100` &type=`db`]]
 *   [[SpookyAppDevice? &id=`samsung-galaxy-s24` &type=`gsmarena`]]
 *
 * Параметры:
 *   &id       (string|int, required)  — ID устройства
 *   &type     (string, 'db')          — 'db' или название источника API
 *   &template (string, '')            — кастомный chunk
 *   &nocache  (bool, false)           — пропустить кеш
 *
 * @var modX $modx
 * @var array $scriptProperties
 *
 * @package SpookyApp
 * @since   1.0.0
 * ═══════════════════════════════════════════════════════════════
 */

use SpookyApp\Snippets\DeviceSnippet;

$corePath = $modx->getOption(
    'spookyapp.core_path',
    null,
    MODX_CORE_PATH . 'components/spookyapp/'
);

$autoload = $corePath . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (!class_exists(DeviceSnippet::class)) {
    $modx->log(MODX_LOG_LEVEL_ERROR, '[SpookyApp] Класс DeviceSnippet не найден. Проверьте autoload.');
    return '';
}

    $snippet = new DeviceSnippet($this->modx, $scriptProperties ?? []);
return $snippet->run();