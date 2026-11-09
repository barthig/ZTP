<?php
declare(strict_types=1);

use Lab1\Data\{Database, PhraseRepository, ProductRepository};
use Lab1\Domain\{PhraseValidator, ProductValidator, Text};
use Lab1\Services\{PhraseService, ProductService};

require dirname(__DIR__) . '/src/bootstrap.php';

$database = Database::open(getenv('DB_PATH') ?: dirname(__DIR__) . '/data/products.sqlite');
$products = new ProductRepository($database);
$phrases = new PhraseRepository($database);
$productService = new ProductService($products, $phrases, new ProductValidator());
$phraseService = new PhraseService($phrases, new PhraseValidator());

$examples = [
    ['name' => 'Laptop123', 'category' => 'Elektronika', 'price' => 3499.99, 'quantity' => 8],
    ['name' => 'Smartfon5', 'category' => 'Elektronika', 'price' => 1899.00, 'quantity' => 12],
    ['name' => 'Monitor27', 'category' => 'Elektronika', 'price' => 899.99, 'quantity' => 5],
    ['name' => 'KsiazkaPHP', 'category' => 'Książki', 'price' => 79.90, 'quantity' => 15],
    ['name' => 'Algorytmy', 'category' => 'Książki', 'price' => 119.00, 'quantity' => 7],
    ['name' => 'CzystyKod', 'category' => 'Książki', 'price' => 89.00, 'quantity' => 0],
    ['name' => 'BluzaBasic', 'category' => 'Odzież', 'price' => 149.99, 'quantity' => 10],
    ['name' => 'KoszulkaM', 'category' => 'Odzież', 'price' => 49.90, 'quantity' => 25],
    ['name' => 'JeansClassic', 'category' => 'Odzież', 'price' => 199.00, 'quantity' => 6],
];

$createdProducts = 0;
$createdPhrases = 0;
$database->beginTransaction();
try {
    foreach (['podrobka', 'zakaz'] as $phrase) {
        if ($phrases->findByKey(Text::key($phrase)) === null) {
            $phraseService->create((object) ['phrase' => $phrase]);
            $createdPhrases++;
        }
    }
    foreach ($examples as $example) {
        if ($products->findByName(Text::key($example['name'])) !== null) {
            continue;
        }
        $product = $productService->create((object) $example);
        $createdProducts++;
        // Only newly seeded products receive example changes; reruns preserve edited data.
        if ($example['name'] === 'Laptop123') {
            $example['price'] = 3299.99;
            $productService->update($product['id'], (object) $example);
            $example['quantity'] = 6;
            $productService->update($product['id'], (object) $example);
        }
    }
    $database->commit();
    printf("Dodano produkty: %d, dodano frazy: %d.\n", $createdProducts, $createdPhrases);
    printf("Łącznie produkty: %d, frazy: %d.\n", count($products->list()), count($phrases->list()));
} catch (Throwable $error) {
    $database->rollBack();
    fwrite(STDERR, "Nie zapisano danych przykładowych: {$error->getMessage()}\n");
    exit(1);
}
