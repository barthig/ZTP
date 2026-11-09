<?php
declare(strict_types=1);

namespace Lab1\Http;

use JsonException;
use Lab1\Domain\{ApiError, ProductValidator};

final readonly class Router
{
    public function __construct(private array $routes) {}

    public function dispatch(string $method, string $uri): Response
    {
        $path = explode('?', $uri, 2)[0];
        if ($method === 'GET' && $path === '/') {
            return new Response(file_get_contents(dirname(__DIR__, 2) . '/public/dashboard.html'),
                contentType: 'text/html; charset=utf-8');
        }
        $matching = array_values(array_filter($this->routes, static fn ($route) => preg_match($route[1], $path) === 1));
        if ($matching === []) {
            throw new ApiError(404, 'Endpoint nie istnieje.');
        }
        foreach ($matching as [$verb, $pattern, $handler, $hasBody]) {
            if ($method !== $verb) {
                continue;
            }
            preg_match($pattern, $path, $matches);
            $id = isset($matches[1]) ? $this->parseId($matches[1]) : null;
            return $handler($id, $hasBody ? $this->readJson() : null);
        }
        return new Response(['message' => 'Niedozwolona metoda HTTP.', 'errors' => (object) []], 405,
            ['Allow' => implode(', ', array_column($matching, 0))]);
    }

    private function parseId(string $value): int
    {
        $value = ltrim($value, '0');
        $max = (string) ProductValidator::MAX_SAFE_INTEGER;
        if ($value === '' || strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
            throw new ApiError(400, 'Nieprawidłowy identyfikator.', ['id' => 'ID musi być dodatnią, bezpieczną liczbą całkowitą.']);
        }
        return (int) $value;
    }

    private function readJson(): mixed
    {
        $mediaType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($mediaType !== 'application/json') {
            throw new ApiError(415, 'Wymagany Content-Type: application/json.');
        }
        $body = file_get_contents('php://input', length: 65537);
        if ($body === false) {
            throw new ApiError(400, 'Nie można odczytać treści żądania.');
        }
        if (strlen($body) > 65536) {
            throw new ApiError(413, 'Treść żądania przekracza 64 KiB.');
        }
        try {
            return json_decode($body, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new ApiError(400, 'Nieprawidłowy JSON.', ['body' => 'Prześlij poprawny dokument JSON.']);
        }
    }
}
