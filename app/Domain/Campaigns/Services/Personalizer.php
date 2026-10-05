<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Services;

use App\Domain\Messaging\Models\Contact;

/**
 * Fills per-contact tokens inside a template variable value:
 *   {{name}} {{first_name}} {{phone}} {{email}} {{attr.<field key>}}
 * with an optional fallback after a pipe: {{first_name|there}}.
 */
final class Personalizer
{
    private const TOKEN = '/\{\{\s*([a-z_]+(?:\.[a-z0-9_]+)?)\s*(?:\|([^}]*))?\}\}/i';

    public function resolve(string $value, Contact $contact): string
    {
        return trim((string) preg_replace_callback(self::TOKEN, function (array $m) use ($contact): string {
            $resolved = trim($this->token(strtolower($m[1]), $contact));

            return $resolved !== '' ? $resolved : trim($m[2] ?? '');
        }, $value));
    }

    public function hasTokens(string $value): bool
    {
        return preg_match(self::TOKEN, $value) === 1;
    }

    private function token(string $token, Contact $contact): string
    {
        $name = trim((string) ($contact->name ?? $contact->profile_name ?? ''));

        return match (true) {
            $token === 'name' => $name,
            $token === 'first_name' => (string) strtok($name, ' '),
            $token === 'phone' => $contact->wa_id !== null ? '+'.$contact->wa_id : '',
            $token === 'email' => (string) $contact->email,
            str_starts_with($token, 'attr.') => (string) (($contact->custom_fields ?? [])[substr($token, 5)] ?? ''),
            default => '',
        };
    }
}
