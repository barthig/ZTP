<?php
declare(strict_types=1);

namespace Lab1\Data;

use Lab1\Domain\ApiError;
use Lab1\Domain\PhraseStore;
use PDO;
use PDOException;

final readonly class PhraseRepository implements PhraseStore
{
    public function __construct(private PDO $database) {}

    public function list(): array
    {
        return $this->database->query('SELECT id, phrase FROM forbidden_phrases ORDER BY id')->fetchAll();
    }

    public function get(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT id, phrase FROM forbidden_phrases WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public function findByKey(string $key): ?array
    {
        $statement = $this->database->prepare('SELECT id FROM forbidden_phrases WHERE phrase_key = ?');
        $statement->execute([$key]);
        return $statement->fetch() ?: null;
    }

    public function create(array $phrase): array
    {
        try {
            $statement = $this->database->prepare('INSERT INTO forbidden_phrases(phrase, phrase_key) VALUES (?, ?)');
            $statement->execute([$phrase['phrase'], $phrase['phraseKey']]);
        } catch (PDOException $error) {
            if (str_contains($error->getMessage(), 'UNIQUE constraint failed: forbidden_phrases.phrase_key')) {
                throw new ApiError(409, 'Fraza już istnieje.', ['phrase' => 'Fraza musi być unikalna.']);
            }
            throw $error;
        }
        return $this->get((int) $this->database->lastInsertId());
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare('DELETE FROM forbidden_phrases WHERE id = ?');
        $statement->execute([$id]);
    }
}
