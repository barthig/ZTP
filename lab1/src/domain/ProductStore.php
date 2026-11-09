<?php
declare(strict_types=1);

namespace Lab1\Domain;

interface ProductStore
{
    public function list(): array;
    public function get(int $id): ?array;
    public function findByName(string $key): ?array;
    public function create(ProductData $product): array;
    public function update(int $id, ProductData $product): array;
    public function delete(int $id): void;
    public function history(int $id): array;
}
