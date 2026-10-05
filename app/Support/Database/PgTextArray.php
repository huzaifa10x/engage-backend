<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Postgres text[] ⇄ list<string>.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class PgTextArray implements CastsAttributes
{
    /** @return list<string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        $inner = trim((string) $value, '{}');
        if ($inner === '') {
            return [];
        }

        return array_map(fn (?string $v) => stripcslashes((string) $v), str_getcsv($inner, ',', '"', '\\'));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $items = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), (array) $value), fn (string $v) => $v !== '')));

        return '{'.implode(',', array_map(fn (string $v) => '"'.addcslashes($v, '"\\').'"', $items)).'}';
    }
}
