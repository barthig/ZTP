<?php
declare(strict_types=1);

namespace Lab1\Http;

use Lab1\Services\PhraseService;

final readonly class PhraseController
{
    public function __construct(private PhraseService $service) {}

    public function routes(): array
    {
        return [
            ['GET', '#\A/api/v1/forbidden-phrases\z#', fn () => new Response($this->service->list()), false],
            ['POST', '#\A/api/v1/forbidden-phrases\z#', fn ($id, $body) => $this->create($body), true],
            ['GET', '#\A/api/v1/forbidden-phrases/(\d+)\z#', fn ($id) => new Response($this->service->get($id)), false],
            ['DELETE', '#\A/api/v1/forbidden-phrases/(\d+)\z#', fn ($id) => $this->delete($id), false],
        ];
    }

    private function create(mixed $body): Response
    {
        $phrase = $this->service->create($body);
        return new Response($phrase, 201, ['Location' => '/api/v1/forbidden-phrases/' . $phrase['id']]);
    }

    private function delete(int $id): Response
    {
        $this->service->delete($id);
        return new Response(status: 204);
    }
}
