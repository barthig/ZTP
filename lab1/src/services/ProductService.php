<?php
declare(strict_types=1);

namespace Lab1\Services;

use Lab1\Domain\{ApiError, PhraseStore, ProductData, ProductStore, ProductValidator, Text};

final readonly class ProductService
{
    public function __construct(
        private ProductStore $products,
        private PhraseStore $phrases,
        private ProductValidator $validator,
    ) {}

    public function list(): array { return $this->products->list(); }

    public function get(int $id): array
    {
        return $this->products->get($id) ?? throw ApiError::notFound();
    }

    private function validate(mixed $input, ?int $id = null): ProductData
    {
        $product = $this->validator->validate($input);
        $duplicate = $this->products->findByName($product->nameKey);
        if ($duplicate !== null && $duplicate['id'] !== $id) {
            throw new ApiError(409, 'Produkt o tej nazwie już istnieje.', ['name' => 'Nazwa produktu musi być unikalna.']);
        }
        foreach ($this->phrases->list() as $phrase) {
            if (str_contains($product->nameKey, Text::key($phrase['phrase']))) {
                throw new ApiError(400, 'Niedozwolona nazwa produktu.', ['name' => 'Nazwa zawiera zabronioną frazę.']);
            }
        }
        return $product;
    }

    public function create(mixed $input): array { return $this->products->create($this->validate($input)); }

    public function update(int $id, mixed $input): array
    {
        $this->get($id);
        return $this->products->update($id, $this->validate($input, $id));
    }

    public function delete(int $id): void
    {
        $this->get($id);
        $this->products->delete($id);
    }

    public function history(int $id): array
    {
        $history = $this->products->history($id);
        if ($history === [] && $this->products->get($id) === null) {
            throw ApiError::notFound();
        }
        return $history;
    }
}
