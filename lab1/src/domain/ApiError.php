<?php
declare(strict_types=1);

namespace Lab1\Domain;

use RuntimeException;

final class ApiError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $resource = 'Produkt'): self
    {
        return new self(404, "{$resource} nie istnieje.");
    }
}
