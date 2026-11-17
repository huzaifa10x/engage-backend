<?php

declare(strict_types=1);

namespace App\Application\Growth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Growth\Links\LinkCode;
use App\Domain\Growth\Links\WaLink;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** wa.me links, QR codes and website buttons, per number (Channels). */
final class ManageLinks
{
    public const POSITIONS = ['bottom_right', 'bottom_left'];

    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data validated */
    public function save(?WaLink $link, array $data): WaLink
    {
        $kind = $link->kind ?? (($data['kind'] ?? WaLink::KIND_LINK) === WaLink::KIND_WEBSITE_BUTTON ? WaLink::KIND_WEBSITE_BUTTON : WaLink::KIND_LINK);
        $number = PhoneNumber::query()->find($data['phone_number_id'] ?? $link?->phone_number_id);
        if ($number === null) {
            throw ValidationException::withMessages(['phone_number_id' => 'Choose a WhatsApp number of this workspace.']);
        }
        $name = trim((string) ($data['name'] ?? ($kind === WaLink::KIND_WEBSITE_BUTTON ? 'Website button' : '')));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Give the link a source name, e.g. "Shop window" or "Instagram bio".']);
        }
        $message = trim((string) ($data['message'] ?? ''));
        $asksConsent = (bool) ($data['asks_consent'] ?? false);
        if ($asksConsent && $message === '') {
            throw ValidationException::withMessages(['message' => 'Write the words the customer sends to agree, e.g. "Yes, send me offers on WhatsApp".']);
        }
        if (LinkCode::extract($message) !== null || str_contains($message, 'XG-')) {
            throw ValidationException::withMessages(['message' => 'Leave the reference code out: it is added for you.']);
        }

        $button = null;
        if ($kind === WaLink::KIND_WEBSITE_BUTTON) {
            $position = (string) ($data['button']['position'] ?? $link->button['position'] ?? 'bottom_right');
            $button = [
                'position' => in_array($position, self::POSITIONS, true) ? $position : 'bottom_right',
                'label' => mb_substr(trim((string) ($data['button']['label'] ?? $link->button['label'] ?? 'Chat with us')), 0, 30) ?: 'Chat with us',
            ];
        }

        $attributes = ['phone_number_id' => $number->id, 'name' => $name, 'message' => $message, 'asks_consent' => $asksConsent, 'button' => $button];
        if ($link !== null) {
            $link->fill($attributes)->save();
            $this->audit->record('wa_link.updated', $link, after: ['name' => $link->name]);

            return $link;
        }

        // A new code until it is unique in the workspace (31^6 ≈ 887 million: a clash is rare).
        for ($i = 0; ; $i++) {
            try {
                $link = DB::transaction(fn () => WaLink::query()->create($attributes + [
                    'kind' => $kind, 'code' => LinkCode::generate(), 'created_by_membership_id' => $this->context->membership()?->id,
                ]));
                break;
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505' || $i >= 4) {
                    throw $e;
                }
            }
        }
        $this->audit->record('wa_link.created', $link, after: ['name' => $link->name, 'kind' => $link->kind]);

        return $link;
    }

    /** Archived links keep counting chats (a printed QR code is still out there), but leave the list. */
    public function archive(WaLink $link): void
    {
        $link->forceFill(['archived_at' => now()])->save();
        $this->audit->record('wa_link.archived', $link, meta: ['name' => $link->name]);
    }

    /** A copy-paste floating WhatsApp button for the client's website: plain HTML, no script. */
    public static function snippet(WaLink $link): ?string
    {
        $url = $link->url();
        if ($url === null) {
            return null;
        }
        $side = ($link->button['position'] ?? 'bottom_right') === 'bottom_left' ? 'left' : 'right';
        $label = htmlspecialchars((string) ($link->button['label'] ?? 'Chat with us'), ENT_QUOTES | ENT_HTML5);
        $href = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5);
        $icon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 20l1.3-3.9A8 8 0 1 1 8 19.1L4 20z" stroke="#fff" stroke-width="2" stroke-linejoin="round"/></svg>';

        return "<!-- engageX WhatsApp button: {$link->code} -->\n"
            ."<a href=\"{$href}\" target=\"_blank\" rel=\"noopener\" data-engagex-button=\"{$link->code}\" "
            ."style=\"position:fixed;bottom:20px;{$side}:20px;z-index:2147483000;display:inline-flex;align-items:center;gap:8px;"
            .'padding:12px 18px;border-radius:999px;background:#25D366;color:#fff;font:600 15px/1.2 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
            ."text-decoration:none;box-shadow:0 4px 14px rgba(0,0,0,.2)\">{$icon}<span>{$label}</span></a>";
    }
}
