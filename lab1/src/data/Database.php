<?php
declare(strict_types=1);

namespace Lab1\Data;

use PDO;
use RuntimeException;

final class Database
{
    public static function open(string $path): PDO
    {
        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Nie można utworzyć katalogu bazy danych.');
            }
        }
        $database = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $database->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;');
        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Nie można odczytać schematu bazy danych.');
        }
        $database->exec($schema);
        return $database;
    }
}
