<?php
declare(strict_types=1);

namespace Lab1\Http;

use Lab1\Services\ProductService;

final readonly class ProductController
{
    public function __construct(private ProductService $service) {}

    public function routes(): array
    {
        return [
            ['GET', '#\A/api/v1/products\z#', fn () => new Response($this->service->list()), false],
            ['POST', '#\A/api/v1/products\z#', fn ($id, $body) => $this->create($body), true],
            ['GET', '#\A/api/v1/products/(\d+)\z#', fn ($id) => new Response($this->service->get($id)), false],
            ['PUT', '#\A/api/v1/products/(\d+)\z#', fn ($id, $body) => new Response($this->service->update($id, $body)), true],
            ['DELETE', '#\A/api/v1/products/(\d+)\z#', fn ($id) => $this->delete($id), false],
            ['GET', '#\A/api/v1/products/(\d+)/history\z#', fn ($id) => new Response($this->service->history($id)), false],
        ];
    }

    private function create(mixed $body): Response
    {
        $product = $this->service->create($body);
        return new Response($product, 201, ['Location' => '/api/v1/products/' . $product['id']]);
    }

    private function delete(int $id): Response
    {
        $this->service->delete($id);
        return new Response(status: 204);
    }
}
