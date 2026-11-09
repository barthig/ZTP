<?php
declare(strict_types=1);

namespace Lab1\Domain;

final readonly class ProductData
{
    public function __construct(
        public string $name,
        public string $nameKey,
        public string $category,
        public int $priceCents,
        public int $quantity,
    ) {}
}
