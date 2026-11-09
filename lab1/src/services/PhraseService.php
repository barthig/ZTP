<?php
declare(strict_types=1);

namespace Lab1\Services;

use Lab1\Domain\{ApiError, PhraseStore, PhraseValidator};

final readonly class PhraseService
{
    public function __construct(private PhraseStore $phrases, private PhraseValidator $validator) {}

    public function list(): array { return $this->phrases->list(); }

    public function get(int $id): array
    {
        return $this->phrases->get($id) ?? throw ApiError::notFound('Fraza');
    }

    public function create(mixed $input): array
    {
        $phrase = $this->validator->validate($input);
        if ($this->phrases->findByKey($phrase['phraseKey']) !== null) {
            throw new ApiError(409, 'Fraza już istnieje.', ['phrase' => 'Fraza musi być unikalna.']);
        }
        return $this->phrases->create($phrase);
    }

    public function delete(int $id): void
    {
        $this->get($id);
        $this->phrases->delete($id);
    }
}
