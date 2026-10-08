<?php

/**
 * PSR-4 autoloader for the Chandan Digital AI for NVIDIA package.
 *
 * @since 1.0.0
 *
 * @package ChandanDigital\NvidiaAi
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'ChandanDigital\\NvidiaAi\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);

    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
