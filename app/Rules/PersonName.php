<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A person's name: letters in any language, with spaces, dots, apostrophes and hyphens between them
 * ("Waqar", "Sara Al-Mansoori", "O'Brien", "J. Smith", "محمد علي"). No digits or other symbols.
 */
final class PersonName implements ValidationRule
{
    public const PATTERN = "/^\\p{L}[\\p{L}\\p{M}]*(?:[ .'’\\-]+[\\p{L}\\p{M}]+)*\\.?$/u";

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        if ($name === '' || preg_match(self::PATTERN, $name) !== 1) {
            $fail('Use letters only for the name (spaces, dots, apostrophes and hyphens are fine).');
        }
    }
}
