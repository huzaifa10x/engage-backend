<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Support\Carbon;

/** One stored entry.change, with the WABA / number it resolved to (inside the tenant context). */
final readonly class WebhookChange
{
    /** @param array<string, mixed> $value */
    public function __construct(
        public string $id,
        public string $field,
        public ?string $wabaId,
        public array $value,
        public Carbon $occurredAt,
        public ?WabaAccount $waba,
        public ?PhoneNumber $number,
    ) {}

    /** Finds a number of this WABA by display number (quality / name webhooks carry only that). */
    public function numberByDisplay(?string $display): ?PhoneNumber
    {
        if ($this->number !== null || $this->waba === null || $display === null) {
            return $this->number;
        }

        $digits = preg_replace('/\D+/', '', $display);

        return $this->waba->phoneNumbers()->get()
            ->first(fn (PhoneNumber $n) => preg_replace('/\D+/', '', (string) $n->display_phone_number) === $digits);
    }
}
