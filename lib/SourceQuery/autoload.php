<?php
declare(strict_types=1);

// Manueller PSR-4-Autoloader für die SourceQuery-Library (das Projekt nutzt kein Composer).
// Bildet exakt die "xPaw\SourceQuery\"-Zuordnung aus composer.json auf den SourceQuery/-Ordner ab.
spl_autoload_register(static function (string $class): void {
    $prefix = 'xPaw\\SourceQuery\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/SourceQuery/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
