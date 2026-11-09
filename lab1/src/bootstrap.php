<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Lab1\\')) {
        return;
    }
    $parts = explode('\\', substr($class, strlen('Lab1\\')));
    if (count($parts) > 1) {
        $parts[0] = strtolower($parts[0]);
    }
    $path = __DIR__ . '/' . implode('/', $parts) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

foreach (['pdo_sqlite', 'mbstring', 'intl'] as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException("Włącz rozszerzenie PHP: {$extension}. Sprawdź README.md.");
    }
}
if (PHP_INT_SIZE < 8) {
    throw new RuntimeException('Wymagane PHP 64-bit.');
}
