<?php
declare(strict_types=1);

namespace Lab1\Domain;

use stdClass;

final class ProductValidator
{
    private const CATEGORIES = [
        'Elektronika' => [50, 50000],
        'Książki' => [5, 500],
        'Odzież' => [10, 5000],
    ];
    public const MAX_SAFE_INTEGER = 9007199254740991;

    public function validate(mixed $input): ProductData
    {
        if (!$input instanceof stdClass) {
            throw new ApiError(400, 'Oczekiwano obiektu JSON.', ['body' => 'Podaj obiekt produktu.']);
        }
        $errors = [];
        $name = is_string($input->name ?? null) ? Text::normalize($input->name) : '';
        if (mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 20
            || preg_match('/\A[\p{L}\p{Nd}]+\z/u', $name) !== 1) {
            $errors['name'] = 'Nazwa musi mieć 3–20 znaków i zawierać wyłącznie litery lub cyfry.';
        }
        $category = $input->category ?? null;
        $range = is_string($category) ? (self::CATEGORIES[$category] ?? null) : null;
        if ($range === null) {
            $errors['category'] = 'Dozwolone kategorie: ' . implode(', ', array_keys(self::CATEGORIES)) . '.';
        }
        $price = $input->price ?? null;
        $numericPrice = (is_int($price) || is_float($price)) && is_finite((float) $price);
        if (!$numericPrice || abs($price) > 50000 || (float) $price !== round($price * 100) / 100.0) {
            $errors['price'] = 'Cena musi być liczbą w dozwolonym zakresie, z maksymalnie dwoma miejscami po przecinku.';
        } elseif ($range !== null && ($price < $range[0] || $price > $range[1])) {
            $errors['price'] = "Cena w kategorii {$category} musi wynosić od {$range[0]} do {$range[1]} PLN.";
        }
        $quantity = $input->quantity ?? null;
        if ((!is_int($quantity) && !is_float($quantity)) || !is_finite((float) $quantity)
            || $quantity < 0 || $quantity > self::MAX_SAFE_INTEGER || floor($quantity) !== (float) $quantity) {
            $errors['quantity'] = 'Ilość musi być nieujemną, bezpieczną liczbą całkowitą.';
        }
        $unknown = array_diff(array_keys(get_object_vars($input)), ['name', 'category', 'price', 'quantity']);
        if ($unknown !== []) {
            $errors['body'] = 'Nieznane pola: ' . implode(', ', $unknown) . '.';
        }
        if ($errors !== []) {
            throw new ApiError(400, 'Nieprawidłowe dane produktu.', $errors);
        }
        return new ProductData($name, Text::key($name), $category, (int) round($price * 100), (int) $quantity);
    }
}
