<?php
declare(strict_types=1);

namespace Lab1\Domain;

interface PhraseStore
{
    public function list(): array;
    public function get(int $id): ?array;
    public function findByKey(string $key): ?array;
    public function create(array $phrase): array;
    public function delete(int $id): void;
}
