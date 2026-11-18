<?php

declare(strict_types=1);

namespace App\Domain\Developer;

use App\Domain\Developer\Models\ApiKey;
use Illuminate\Support\Facades\Context;

/**
 * The API key making the current request, if any (set by AuthenticateApiKey). Kept in Laravel's
 * hidden request context, so it never leaks into logs and is gone when the request ends.
 */
final class ApiCaller
{
    private const KEY = 'api_key';

    public static function set(ApiKey $key): void
    {
        Context::addHidden(self::KEY, ['id' => $key->id, 'name' => $key->name, 'scopes' => $key->scopes]);
    }

    /** @return ?array{id: string, name: string, scopes: list<string>} */
    public static function current(): ?array
    {
        $key = Context::getHidden(self::KEY);

        return is_array($key) ? $key : null;
    }

    public static function allows(string $scope): bool
    {
        return in_array($scope, self::current()['scopes'] ?? [], true);
    }
}
