<?php
declare(strict_types=1);

namespace Lab1\Http;

final readonly class Response
{
    public function __construct(
        public mixed $body = null,
        public int $status = 200,
        public array $headers = [],
        public string $contentType = 'application/json; charset=utf-8',
    ) {}

    public function send(): void
    {
        $content = $this->status === 204 ? '' : ($this->contentType === 'text/html; charset=utf-8'
            ? (string) $this->body : json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        if ($this->status !== 204) {
            header('Content-Type: ' . $this->contentType);
        }
        echo $content;
    }
}
