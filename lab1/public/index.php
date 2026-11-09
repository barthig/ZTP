<?php
declare(strict_types=1);

use Lab1\Application;
use Lab1\Domain\ApiError;
use Lab1\Http\Response;

ini_set('display_errors', '0');

// Apache serves static assets directly; the PHP development server needs this handoff.
if (PHP_SAPI === 'cli-server' && in_array(explode('?', $_SERVER['REQUEST_URI'], 2)[0],
    ['/assets/app.js', '/assets/style.css'], true)) {
    return false;
}

try {
    require dirname(__DIR__) . '/src/bootstrap.php';
    $databasePath = getenv('DB_PATH') ?: dirname(__DIR__) . '/data/products.sqlite';
    Application::router($databasePath)->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'])->send();
} catch (ApiError $error) {
    (new Response(['message' => $error->getMessage(), 'errors' => (object) $error->errors], $error->status))->send();
} catch (Throwable $error) {
    error_log((string) $error);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'Wewnętrzny błąd serwera.', 'errors' => (object) []], JSON_UNESCAPED_UNICODE);
}
