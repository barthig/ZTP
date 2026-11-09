<?php
declare(strict_types=1);

namespace Lab1;

use Lab1\Data\{Database, PhraseRepository, ProductRepository};
use Lab1\Domain\{PhraseValidator, ProductValidator};
use Lab1\Http\{PhraseController, ProductController, Router};
use Lab1\Services\{PhraseService, ProductService};

final class Application
{
    public static function router(string $databasePath): Router
    {
        $database = Database::open($databasePath);
        $products = new ProductRepository($database);
        $phrases = new PhraseRepository($database);
        $productController = new ProductController(new ProductService($products, $phrases, new ProductValidator()));
        $phraseController = new PhraseController(new PhraseService($phrases, new PhraseValidator()));
        return new Router([...$productController->routes(), ...$phraseController->routes()]);
    }
}
