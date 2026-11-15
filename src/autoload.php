<?php

/** PSR-4 autoloader: class ZX\Spectrum\Folder\Name lives in src/Folder/Name.php. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'ZX\\Spectrum\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
