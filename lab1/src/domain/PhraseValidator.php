<?php
declare(strict_types=1);

namespace Lab1\Domain;

use stdClass;

final class PhraseValidator
{
    public function validate(mixed $input): array
    {
        if (!$input instanceof stdClass || !is_string($input->phrase ?? null)) {
            throw $this->invalid();
        }
        $phrase = preg_replace('/\A\s+|\s+\z/u', '', Text::normalize($input->phrase));
        if ($phrase === '' || mb_strlen($phrase, 'UTF-8') > 100
            || array_diff(array_keys(get_object_vars($input)), ['phrase']) !== []) {
            throw $this->invalid();
        }
        return ['phrase' => $phrase, 'phraseKey' => Text::key($phrase)];
    }

    private function invalid(): ApiError
    {
        return new ApiError(400, 'Nieprawidłowa fraza.', ['phrase' => 'Podaj frazę o długości 1–100 znaków w polu phrase.']);
    }
}
