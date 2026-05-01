<?php
/**
 * Ручной autoloader для SpookyApp (PSR-4)
 */

spl_autoload_register(function ($class) { 
    // Проверяем, начинается ли класс с нашего пространства имен
    $prefix = 'SpookyApp\\';
    $baseDir = __DIR__ . '/src/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        // Если нет, то это не наш класс
        return;
    }

    // Получаем относительный путь к классу
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    // Если файл существует, подключаем его
    if (file_exists($file)) {
        require $file;
    }
});