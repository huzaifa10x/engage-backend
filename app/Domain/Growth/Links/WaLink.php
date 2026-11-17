<?php

declare(strict_types=1);

namespace App\Domain\Growth\Links;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A wa.me link (and its QR code) or a website button for one number, with a source name. Its
 * pre-filled text ends with a short code, "… (Ref: EX-7PK9M2)", so the first message of a chat
 * started from it says where it came from.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $phone_number_id
 * @property string $kind link | website_button
 * @property string $name
 * @property string $code
 * @property string $message
 * @property bool $asks_consent
 * @property ?array{position?: string, label?: string} $button
 * @property ?string $created_by_membership_id
 * @property ?Carbon $archived_at
 * @property ?Carbon $created_at
 * @property ?PhoneNumber $phoneNumber
 */
class WaLink extends Model
{
    use BelongsToTenant, HasUuids;

    public const KIND_LINK = 'link';

    public const KIND_WEBSITE_BUTTON = 'website_button';

    protected $attributes = ['kind' => self::KIND_LINK, 'asks_consent' => false];

    protected $fillable = ['tenant_id', 'phone_number_id', 'kind', 'name', 'code', 'message', 'asks_consent', 'button', 'created_by_membership_id', 'archived_at'];

    protected function casts(): array
    {
        return ['asks_consent' => 'boolean', 'button' => 'array', 'archived_at' => 'datetime'];
    }

    /** @return BelongsTo<PhoneNumber, $this> */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    /** The text the customer's WhatsApp opens with: the message, then the code. */
    public function prefilledText(): string
    {
        return LinkCode::append($this->message, $this->code);
    }

    /** https://wa.me/<number>?text=<pre-filled text> */
    public function url(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($this->phoneNumber->e164 ?? $this->phoneNumber?->display_phone_number));

        return $digits === '' || $digits === null ? null : 'https://wa.me/'.$digits.'?text='.rawurlencode($this->prefilledText());
    }
}
