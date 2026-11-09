<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/ApiClient.php';
if (!extension_loaded('curl')) {
    throw new RuntimeException('Testy wymagają rozszerzenia curl.');
}

const PRODUCTS = '/api/v1/products';
const PHRASES = '/api/v1/forbidden-phrases';
const PRODUCT = ['name' => 'Laptop123', 'category' => 'Elektronika', 'price' => 2499.99, 'quantity' => 4];

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nOczekiwano: ' . var_export($expected, true) . '\nOtrzymano: ' . var_export($actual, true));
    }
}
function check(bool $condition, string $message = 'Niespełniony warunek'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function createProduct(ApiClient $api, array $overrides = []): array
{
    $result = $api->request('POST', PRODUCTS, array_replace(PRODUCT, $overrides));
    same(201, $result['status']);
    return $result['body'];
}
function history(ApiClient $api, int $id): array
{
    $result = $api->request('GET', PRODUCTS . "/{$id}/history");
    same(200, $result['status']);
    return $result['body'];
}
$tests = [];

$tests['Given pusty katalog, When CRUD przez HTTP, Then poprawne stany i statusy'] = function (ApiClient $api): void {
    same([], $api->request('GET', PRODUCTS)['body']);
    $created = $api->request('POST', PRODUCTS, PRODUCT);
    same(201, $created['status']);
    $product = $created['body'];
    $path = PRODUCTS . '/' . $product['id'];
    same($path, $created['headers']['location']);
    same(['id' => $product['id'], ...PRODUCT], $product);
    same($product, $api->request('GET', $path)['body']);
    same([$product], $api->request('GET', PRODUCTS)['body']);
    $changed = ['name' => 'Koszula123', 'category' => 'Odzież', 'price' => 89.95, 'quantity' => 0];
    $updated = $api->request('PUT', $path, $changed);
    same(200, $updated['status']);
    same(['id' => $product['id'], ...$changed], $updated['body']);
    same($updated['body'], $api->request('GET', $path)['body']);
    $deleted = $api->request('DELETE', $path);
    same(204, $deleted['status']);
    same(null, $deleted['body']);
    same(404, $api->request('GET', $path)['status']);
    same([], $api->request('GET', PRODUCTS)['body']);
};

$invalid = [
    ['name', 'Ab', 'za krótka nazwa'], ['name', str_repeat('A', 21), 'za długa nazwa'],
    ['name', '', 'pusta nazwa'], ['name', 'Dwa Slowa', 'spacja'], ['name', 'Produkt-1', 'myślnik'],
    ['name', 'Produkt_1', 'podkreślenie'], ['name', 'Produkt!', 'interpunkcja'],
    ['name', 123, 'nazwa innego typu'], ['name', null, 'null zamiast nazwy'],
    ['category', 'Inna', 'nieznana kategoria'], ['category', ['Elektronika'], 'tablica zamiast kategorii'],
    ['category', (object) ['toString' => 'Elektronika'], 'obiekt zamiast kategorii'], ['category', null, 'brak kategorii'],
    ['price', '50', 'cena jako tekst'], ['price', null, 'brak ceny'], ['price', -1, 'ujemna cena'],
    ['price', 50.001, 'ułamkowy grosz'], ['price', 50.0000000001, 'ułamkowy grosz blisko kwoty'],
    ['price', 50.000000001, 'ułamkowy grosz w dawnej tolerancji'],
    ['quantity', -1, 'ujemna ilość'], ['quantity', 1.5, 'ułamkowa ilość'],
    ['quantity', '1', 'ilość jako tekst'], ['quantity', null, 'brak ilości'],
    ['quantity', 9007199254740992, 'zbyt duża ilość'],
];
foreach (['POST', 'PUT'] as $method) {
    foreach ($invalid as [$field, $value, $description]) {
        $tests["{$method}: {$description}"] = function (ApiClient $api) use ($method, $field, $value): void {
            $original = $method === 'PUT' ? createProduct($api) : null;
            $path = $original === null ? PRODUCTS : PRODUCTS . '/' . $original['id'];
            $response = $api->request($method, $path, array_replace(PRODUCT, [$field => $value]));
            same(400, $response['status']);
            check(isset($response['body']['errors'][$field]), "Brak błędu pola {$field}");
            if ($original !== null) {
                same($original, $api->request('GET', $path)['body']);
                same(1, count(history($api, $original['id'])));
            } else {
                same([], $api->request('GET', PRODUCTS)['body']);
            }
        };
    }
}

foreach ([['Elektronika', 50, 50000], ['Książki', 5, 500], ['Odzież', 10, 5000]] as [$category, $min, $max]) {
    foreach (['POST', 'PUT'] as $method) {
        $tests["{$method}: granice ceny kategorii {$category}"] = function (ApiClient $api) use ($method, $category, $min, $max): void {
            $original = $method === 'PUT' ? createProduct($api) : null;
            $path = $original === null ? PRODUCTS : PRODUCTS . '/' . $original['id'];
            foreach ([$min, $max, $min - 0.01, $max + 0.01] as $index => $price) {
                $response = $api->request($method, $path, array_replace(PRODUCT, ['name' => "Produkt{$index}", 'category' => $category, 'price' => $price]));
                same($index < 2 ? ($method === 'POST' ? 201 : 200) : 400, $response['status']);
                if ($index < 2) {
                    same($price, $response['body']['price']);
                } else {
                    check(isset($response['body']['errors']['price']));
                }
            }
        };
    }
}

$tests['Nazwy 3 i 20 znaków, polskie litery, cyfry oraz ilość zero'] = function (ApiClient $api): void {
    foreach (['Ab1', str_repeat('A', 20), 'Żółć123', '123'] as $name) {
        $product = createProduct($api, ['name' => $name, 'quantity' => 0]);
        same($name, $product['name']);
        same(0, $product['quantity']);
    }
};
$tests['PUT akceptuje granice nazwy i dokładne grosze'] = function (ApiClient $api): void {
    $product = createProduct($api);
    foreach ([['Ab1', 50.01], [str_repeat('A', 20), 2499.99], ['Żółć123', 49999.99]] as [$name, $price]) {
        $response = $api->request('PUT', PRODUCTS . '/' . $product['id'], array_replace(PRODUCT, ['name' => $name, 'price' => $price, 'quantity' => 0]));
        same(200, $response['status']);
        same($name, $response['body']['name']);
        same($price, $response['body']['price']);
        same(0, $response['body']['quantity']);
    }
};
$tests['Każde pojedyncze pole produktu tworzy wpis historii'] = function (ApiClient $api): void {
    $before = createProduct($api, ['price' => 100]);
    foreach ([['name', 'InnyLaptop'], ['price', 150.01], ['quantity', 0], ['category', 'Książki']] as [$field, $value]) {
        $body = $before;
        unset($body['id']);
        $body[$field] = $value;
        $updated = $api->request('PUT', PRODUCTS . '/' . $before['id'], $body);
        same(200, $updated['status']);
        $entries = history($api, $before['id']);
        $entry = $entries[array_key_last($entries)];
        same('UPDATE', $entry['operation']);
        same($before, $entry['before']);
        same($updated['body'], $entry['after']);
        $before = $updated['body'];
    }
    same(5, count(history($api, $before['id'])));
};
$tests['Unikalność nazw przy POST i PUT, wielkość liter i normalizacja Unicode'] = function (ApiClient $api): void {
    $first = createProduct($api, ['name' => 'Żółć123']);
    $second = createProduct($api, ['name' => 'Drugi123']);
    foreach (['Żółć123', 'żółć123', Normalizer::normalize('Żółć123', Normalizer::FORM_D)] as $name) {
        foreach ([['POST', PRODUCTS], ['PUT', PRODUCTS . '/' . $second['id']]] as [$method, $path]) {
            $response = $api->request($method, $path, array_replace(PRODUCT, ['name' => $name]));
            same(409, $response['status']);
            check(isset($response['body']['errors']['name']));
        }
    }
    same(200, $api->request('PUT', PRODUCTS . '/' . $first['id'], array_replace(PRODUCT, ['name' => 'żółć123']))['status']);
    same(1, count(history($api, $second['id'])));
};
$tests['POST i PUT wymagają wszystkich pól i odrzucają nieznane pola'] = function (ApiClient $api): void {
    $product = createProduct($api);
    foreach (['POST', 'PUT'] as $method) {
        $path = $method === 'POST' ? PRODUCTS : PRODUCTS . '/' . $product['id'];
        foreach (['name', 'category', 'price', 'quantity'] as $field) {
            $body = PRODUCT;
            unset($body[$field]);
            $response = $api->request($method, $path, $body);
            same(400, $response['status']);
            check(isset($response['body']['errors'][$field]));
        }
        $response = $api->request($method, $path, [...PRODUCT, 'id' => 123]);
        same(400, $response['status']);
        check(isset($response['body']['errors']['body']));
    }
};
$tests['Given dynamiczna fraza, When dodanie lub edycja produktu, Then blokada aż do usunięcia frazy'] = function (ApiClient $api): void {
    $product = createProduct($api, ['name' => 'SuperLaptop123']);
    same([], $api->request('GET', PHRASES)['body']);
    $created = $api->request('POST', PHRASES, ['phrase' => 'lApToP']);
    same(201, $created['status']);
    $path = PHRASES . '/' . $created['body']['id'];
    same($path, $created['headers']['location']);
    same($created['body'], $api->request('GET', $path)['body']);
    same([$created['body']], $api->request('GET', PHRASES)['body']);
    foreach ([['POST', PRODUCTS, 'LaptopNowy'], ['PUT', PRODUCTS . '/' . $product['id'], 'SuperLAPTOP123'],
        ['PUT', PRODUCTS . '/' . $product['id'], $product['name']]] as [$method, $url, $name]) {
        $response = $api->request($method, $url, array_replace(PRODUCT, ['name' => $name]));
        same(400, $response['status']);
        check(isset($response['body']['errors']['name']));
    }
    same(1, count(history($api, $product['id'])));
    same(200, $api->request('GET', PRODUCTS . '/' . $product['id'])['status']);
    same(204, $api->request('DELETE', $path)['status']);
    same(404, $api->request('GET', $path)['status']);
    same(200, $api->request('PUT', PRODUCTS . '/' . $product['id'], array_replace(PRODUCT, ['name' => $product['name']]))['status']);
    same(201, $api->request('POST', PRODUCTS, array_replace(PRODUCT, ['name' => 'LaptopNowy']))['status']);
};
$tests['Walidacja i unikalność fraz, Unicode i przycięcie spacji'] = function (ApiClient $api): void {
    foreach ([null, [], (object) [], ['phrase' => ''], ['phrase' => '  '], ['phrase' => 123],
        ['phrase' => str_repeat('x', 101)], ['phrase' => 'abc', 'other' => true]] as $body) {
        $response = $api->request('POST', PHRASES, $body);
        same(400, $response['status']);
        check(isset($response['body']['errors']['phrase']));
    }
    same(201, $api->request('POST', PHRASES, ['phrase' => ' ŻÓŁĆ '])['status']);
    same(409, $api->request('POST', PHRASES, ['phrase' => 'żółć'])['status']);
    $response = $api->request('POST', PRODUCTS, array_replace(PRODUCT, ['name' => Normalizer::normalize('SuperŻółć123', Normalizer::FORM_D)]));
    same(400, $response['status']);
    check(isset($response['body']['errors']['name']));
};
$tests['Pełna historia CREATE UPDATE DELETE, czas UTC i pominięcie pustej zmiany'] = function (ApiClient $api): void {
    $first = createProduct($api);
    $path = PRODUCTS . '/' . $first['id'];
    $second = ['name' => 'NowyLaptop', 'category' => 'Elektronika', 'price' => 3000, 'quantity' => 2];
    same(200, $api->request('PUT', $path, $second)['status']);
    $third = ['name' => 'Ksiazka123', 'category' => 'Książki', 'price' => 20.01, 'quantity' => 0];
    same(200, $api->request('PUT', $path, $third)['status']);
    same(200, $api->request('PUT', $path, $third)['status']);
    same(204, $api->request('DELETE', $path)['status']);
    $entries = history($api, $first['id']);
    same(['CREATE', 'UPDATE', 'UPDATE', 'DELETE'], array_column($entries, 'operation'));
    $states = [null, $first, ['id' => $first['id'], ...$second], ['id' => $first['id'], ...$third], null];
    foreach ($entries as $index => $entry) {
        same($first['id'], $entry['productId']);
        same($states[$index], $entry['before']);
        same($states[$index + 1], $entry['after']);
        check(strtotime($entry['changedAt']) !== false && str_ends_with($entry['changedAt'], 'Z'));
        if ($index > 0) {
            check($entry['id'] > $entries[$index - 1]['id']);
        }
    }
    check(createProduct($api)['id'] > $first['id'], 'ID nie może być użyte ponownie.');
};
$tests['Dane, historia i frazy przeżywają restart procesu PHP'] = function (ApiClient $api): void {
    $kept = createProduct($api);
    $deleted = createProduct($api, ['name' => 'DoUsuniecia']);
    same(204, $api->request('DELETE', PRODUCTS . '/' . $deleted['id'])['status']);
    same(201, $api->request('POST', PHRASES, ['phrase' => 'Zakaz'])['status']);
    $api->restart();
    same([$kept], $api->request('GET', PRODUCTS)['body']);
    same(1, count(history($api, $kept['id'])));
    same(['CREATE', 'DELETE'], array_column(history($api, $deleted['id']), 'operation'));
    same('Zakaz', $api->request('GET', PHRASES)['body'][0]['phrase']);
    same(400, $api->request('POST', PRODUCTS, array_replace(PRODUCT, ['name' => 'NowyZakaz123']))['status']);
};
$tests['Nieistniejące zasoby i nieprawidłowe identyfikatory'] = function (ApiClient $api): void {
    $root = $api->request('GET', '/');
    same(200, $root['status']);
    check(str_contains($root['headers']['content-type'], 'text/html'));
    check(str_contains($root['body'], 'id="product-form"'));
    foreach (['/assets/style.css', '/assets/app.js'] as $asset) {
        $response = $api->request('GET', $asset);
        same(200, $response['status']);
        check(is_string($response['body']) && strlen($response['body']) > 100);
    }
    foreach ([['GET', PRODUCTS . '/123'], ['PUT', PRODUCTS . '/123'], ['DELETE', PRODUCTS . '/123'],
        ['GET', PRODUCTS . '/123/history'], ['GET', PHRASES . '/123'], ['DELETE', PHRASES . '/123'],
        ['GET', '/api/v1/unknown']] as [$method, $path]) {
        $response = $method === 'PUT' ? $api->request($method, $path, PRODUCT) : $api->request($method, $path);
        same(404, $response['status']);
    }
    foreach ([PRODUCTS . '/0', PRODUCTS . '/9007199254740992', PHRASES . '/0'] as $path) {
        $response = $api->request('GET', $path);
        same(400, $response['status']);
        check(isset($response['body']['errors']['id']));
    }
};
$tests['Błędy JSON, typu dokumentu, Content-Type, metody i rozmiaru'] = function (ApiClient $api): void {
    same(400, $api->raw('POST', PRODUCTS, '{')['status']);
    same(400, $api->raw('POST', PRODUCTS, '')['status']);
    same(415, $api->raw('POST', PRODUCTS, '{}', ['Content-Type: text/plain'])['status']);
    same(415, $api->raw('POST', PRODUCTS, '{}', ['Content-Type:'])['status']);
    same(413, $api->raw('POST', PRODUCTS, json_encode(['name' => str_repeat('x', 70000)]))['status']);
    foreach ([null, [], 123, 'tekst'] as $body) {
        $response = $api->request('POST', PRODUCTS, $body);
        same(400, $response['status']);
        check(isset($response['body']['errors']['body']));
    }
    $response = $api->request('PATCH', PRODUCTS, (object) []);
    same(405, $response['status']);
    same('GET, POST', $response['headers']['allow']);
    same(PRODUCT['name'], createProduct($api)['name']);
};
$tests['Równoczesne żądania tworzą jeden produkt i jeden wpis historii'] = function (ApiClient $api): void {
    $results = $api->parallelCreate(PRODUCT, 8);
    same(1, count(array_filter($results, fn ($result) => $result['status'] === 201)));
    same(7, count(array_filter($results, fn ($result) => $result['status'] === 409)));
    $products = $api->request('GET', PRODUCTS)['body'];
    same(1, count($products));
    same(1, count(history($api, $products[0]['id'])));
};

$failed = 0;
$started = microtime(true);
foreach ($tests as $name => $test) {
    $api = null;
    $failure = null;
    try {
        $api = new ApiClient();
        $test($api);
    } catch (Throwable $error) {
        $failure = $error;
    } finally {
        try {
            $api?->close();
        } catch (Throwable $error) {
            $failure ??= $error;
        }
    }
    if ($failure === null) {
        echo "PASS {$name}\n";
    } else {
        $failed++;
        fwrite(STDERR, "FAIL {$name}\n{$failure}\n");
    }
}
printf("\nTesty: %d, zaliczone: %d, błędy: %d, czas: %.2f s\n", count($tests), count($tests) - $failed, $failed, microtime(true) - $started);
exit($failed === 0 ? 0 : 1);
