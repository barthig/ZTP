<?php
declare(strict_types=1);

namespace Lab1\Domain;

use Normalizer;

final class Text
{
    public static function normalize(string $value): string
    {
        return Normalizer::normalize($value, Normalizer::FORM_C);
    }

    public static function key(string $value): string
    {
        return mb_strtolower(self::normalize($value), 'UTF-8');
    }
}
