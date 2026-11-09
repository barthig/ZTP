<?php
declare(strict_types=1);

namespace Lab1\Data;

use Lab1\Domain\ApiError;
use Lab1\Domain\ProductData;
use Lab1\Domain\ProductStore;
use PDO;
use PDOException;

final readonly class ProductRepository implements ProductStore
{
    private const SELECT = 'SELECT id, name, category, price_cents / 100.0 AS price, quantity FROM products';

    public function __construct(private PDO $database) {}

    public function list(): array
    {
        return $this->database->query(self::SELECT . ' ORDER BY id')->fetchAll();
    }

    public function get(int $id): ?array
    {
        $statement = $this->database->prepare(self::SELECT . ' WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public function findByName(string $key): ?array
    {
        $statement = $this->database->prepare('SELECT id FROM products WHERE name_key = ?');
        $statement->execute([$key]);
        return $statement->fetch() ?: null;
    }

    public function create(ProductData $product): array
    {
        $this->write('INSERT INTO products(name, name_key, category, price_cents, quantity) VALUES (?, ?, ?, ?, ?)', $product);
        return $this->get((int) $this->database->lastInsertId());
    }

    public function update(int $id, ProductData $product): array
    {
        $this->write('UPDATE products SET name = ?, name_key = ?, category = ?, price_cents = ?, quantity = ? WHERE id = ?', $product, $id);
        return $this->get($id) ?? throw ApiError::notFound();
    }

    private function write(string $sql, ProductData $product, ?int $id = null): void
    {
        $values = [$product->name, $product->nameKey, $product->category, $product->priceCents, $product->quantity];
        if ($id !== null) {
            $values[] = $id;
        }
        try {
            $this->database->prepare($sql)->execute($values);
        } catch (PDOException $error) {
            if (str_contains($error->getMessage(), 'UNIQUE constraint failed: products.name_key')) {
                throw new ApiError(409, 'Produkt o tej nazwie już istnieje.', ['name' => 'Nazwa produktu musi być unikalna.']);
            }
            throw $error;
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare('DELETE FROM products WHERE id = ?');
        $statement->execute([$id]);
    }

    public function history(int $id): array
    {
        $statement = $this->database->prepare('SELECT id, product_id AS productId, operation, changed_at AS changedAt,
            before_json, after_json FROM product_history WHERE product_id = ? ORDER BY id');
        $statement->execute([$id]);
        return array_map(static function (array $entry): array {
            $entry['before'] = $entry['before_json'] === null ? null : json_decode($entry['before_json'], true, flags: JSON_THROW_ON_ERROR);
            $entry['after'] = $entry['after_json'] === null ? null : json_decode($entry['after_json'], true, flags: JSON_THROW_ON_ERROR);
            unset($entry['before_json'], $entry['after_json']);
            return $entry;
        }, $statement->fetchAll());
    }
}
