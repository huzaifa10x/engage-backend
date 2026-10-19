<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Services;

use App\Application\Crm\TagCatalog;
use App\Application\Messaging\SendMessage;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Integrations\Models\IntegrationRule;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConsentService;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * What happens when a connected store reports something:
 *   record it once → keep the customer as a contact → send the template chosen for that event.
 *
 * Sending goes through SendMessage like every other message, so opt-outs, template approval and
 * the number's sending speed apply here too. Marketing templates additionally need the contact's
 * opt-in, exactly as in Campaigns. Every outcome is written to the event, in plain words, so the
 * Activity list in the portal explains itself.
 *
 * All methods expect to run inside the integration's workspace (TenantContext::run).
 */
final class CommerceEvents
{
    /** A reminder goes out no sooner than this after the customer last touched the checkout. */
    public const MIN_ABANDONED_DELAY = 15;

    public const DEFAULT_ABANDONED_DELAY = 60;

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly SendMessage $send,
        private readonly ConsentService $consent,
        private readonly TagCatalog $tags,
    ) {}

    public function ingest(Integration $integration, StoreEvent $event): ?IntegrationEvent
    {
        if ($event->cancels !== []) {
            IntegrationEvent::query()->where('integration_id', $integration->id)->where('event', 'checkout_abandoned')->where('status', 'pending')
                ->whereIn('external_id', $event->cancels)->get()
                ->each(fn (IntegrationEvent $waiting) => $waiting->finish('cancelled', 'The customer completed the order, so no reminder was needed.'));
        }
        if ($event->event === null || $event->externalId === '') {
            return null;
        }

        $integration->forceFill(['last_event_at' => now()])->save();
        $rule = $this->rule($integration, $event->event);
        $waits = $event->event === 'checkout_abandoned';
        $due = $waits ? now()->addMinutes(max(self::MIN_ABANDONED_DELAY, $rule?->delay_minutes ?: self::DEFAULT_ABANDONED_DELAY)) : null;
        $data = $event->data + ['_country' => (string) $event->country, '_name' => (string) $event->name, '_email' => (string) $event->email, '_marketing' => $event->acceptsMarketing ? '1' : ''];

        $existing = IntegrationEvent::query()->where('integration_id', $integration->id)->where('event', $event->event)->where('external_id', $event->externalId)->first();
        if ($existing !== null) {
            // A checkout the customer is still working on: keep the latest details and restart the wait.
            if ($waits && $existing->status === 'pending') {
                $existing->forceFill(['data' => $data, 'phone' => $event->phone ?? $existing->phone, 'due_at' => $due])->save();
            }

            return null; // already handled: stores repeat their notifications
        }

        $id = (string) Str::uuid7();
        // "Insert unless it exists": two deliveries of the same event arriving together must not both send.
        $inserted = DB::table('integration_events')->insertOrIgnore([
            'id' => $id, 'tenant_id' => $integration->tenant_id, 'integration_id' => $integration->id, 'event' => $event->event, 'external_id' => mb_substr($event->externalId, 0, 191),
            'status' => 'pending', 'phone' => $event->phone !== null ? mb_substr($event->phone, 0, 32) : null, 'data' => json_encode($data), 'due_at' => $due, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted === 0) {
            return null;
        }
        /** @var IntegrationEvent $stored */
        $stored = IntegrationEvent::query()->findOrFail($id);

        if (! $waits) {
            $this->deliver($integration, $stored);
        }

        return $stored;
    }

    /** Sends the message for a recorded event (straight away for orders, when due for abandoned checkouts). */
    public function deliver(Integration $integration, IntegrationEvent $event): void
    {
        if ($event->status !== 'pending') {
            return;
        }
        try {
            if (! $this->entitlements->allows($this->context->tenant(), FeatureKey::Integrations)) {
                $event->finish('skipped', 'Integrations are not included in the current plan.');

                return;
            }
            if ($integration->status !== 'active') {
                $event->finish('skipped', 'The integration was paused.');

                return;
            }

            $data = (array) $event->data;
            $waId = StorePhone::normalize($event->phone, $data['_country'] ?? null, (string) $integration->setting('default_country_code', ''));
            if ($waId === null) {
                $event->finish('skipped', $event->phone === null ? 'The customer did not give a phone number.' : 'The phone number has no country code. Set a default country code for this store.');

                return;
            }

            $contact = $this->contact($integration, $waId, $data);
            $event->forceFill(['contact_id' => $contact->id])->save();

            $rule = $this->rule($integration, $event->event);
            if ($rule === null || ! $rule->enabled || $rule->template_name === null) {
                $event->finish('skipped', 'No message is switched on for this event. The customer was saved as a contact.');

                return;
            }

            $message = $this->send($integration, $rule, $contact, $data, 'integration:'.$event->id);
            $event->finish('sent', null, $message->id);
        } catch (Skip $skip) {
            $event->finish('skipped', $skip->getMessage());
        } catch (DomainException $refused) {
            // A business rule said no (opted out, template not approved, number disconnected, …).
            $event->finish('failed', $refused->getMessage());
        } catch (Throwable $e) {
            Log::error('Integration event could not be delivered', ['event' => $event->id, 'integration' => $integration->id, 'error' => $e->getMessage()]);
            $event->finish('failed', 'Something went wrong on our side. The message was not sent.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Skip|DomainException
     */
    public function send(Integration $integration, IntegrationRule $rule, Contact $contact, array $data, string $idempotencyKey): Message
    {
        $number = $this->number($rule);
        /** @var ?MessageTemplate $template */
        $template = MessageTemplate::query()->where('waba_account_id', $number->waba_account_id)->where('name', $rule->template_name)->where('language', $rule->template_language)->first();
        // Marketing needs an opt-in, order updates only need the customer not to have opted out.
        if (($blocker = $contact->campaignBlocker($template?->category)) !== null) {
            throw new Skip($blocker === 'No marketing opt-in' ? 'This is a marketing template and the customer has not opted in to marketing messages.' : $blocker.'.');
        }

        $variables = (array) $rule->variables;
        $fill = fn (mixed $text): string => $this->render((string) $text, $data);

        return $this->send->toContact($number, $contact, ['type' => 'template', 'template' => [
            'name' => (string) $rule->template_name,
            'language' => (string) $rule->template_language,
            'variables' => [
                'header' => array_map($fill, array_values((array) ($variables['header'] ?? []))),
                'body' => array_map($fill, array_values((array) ($variables['body'] ?? []))),
                'buttons' => array_map($fill, (array) ($variables['buttons'] ?? [])),
            ],
        ]], MessageOrigin::Automation, null, $idempotencyKey);
    }

    /**
     * Replaces {{field}} with the event's value. WhatsApp refuses an empty variable, so a value
     * the store did not send becomes a neutral word instead of failing the whole message.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(string $text, array $data): string
    {
        $out = (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $m) use ($data): string {
            $value = trim((string) ($data[$m[1]] ?? ''));

            return $value !== '' ? $value : ($m[1] === 'customer_first_name' || $m[1] === 'customer_name' ? 'there' : '');
        }, $text);
        $out = trim($out);

        return mb_substr($out !== '' ? $out : '-', 0, 1000);
    }

    /** @param array<string, mixed> $data */
    public function contact(Integration $integration, string $waId, array $data): Contact
    {
        /** @var ?Contact $contact */
        $contact = Contact::query()->withTrashed()->where('wa_id', $waId)->first();
        if ($contact === null) {
            $contact = Contact::query()->create(['wa_id' => $waId, 'source' => $integration->provider]);
        } elseif ($contact->trashed()) {
            $contact->restore();
        }

        // Fill in what we do not know yet; never overwrite what the team entered.
        $fill = array_filter(['name' => $contact->name === null ? ($data['_name'] ?? null) : null, 'email' => $contact->email === null ? ($data['_email'] ?? null) : null]);
        $tag = trim((string) $integration->setting('tag', ''));
        if ($tag !== '' && ! in_array($tag, (array) $contact->tags, true)) {
            try {
                $fill['tags'] = array_values(array_unique([...(array) $contact->tags, ...$this->tags->ensure([$tag])]));
            } catch (Throwable) {
                // The plan's tag limit is reached: the contact is still saved, just without the tag.
            }
        }
        if ($fill !== []) {
            $contact->fill($fill)->save();
        }

        // Only when the workspace chose to rely on the store's own marketing consent.
        if ($integration->setting('trust_store_consent') === true && ($data['_marketing'] ?? '') === '1' && ! $contact->isOptedOut()) {
            $this->consent->optIn($contact, 'integration', 'Accepted marketing at checkout ('.$integration->name.')');
        }

        return $contact;
    }

    private function rule(Integration $integration, string $event): ?IntegrationRule
    {
        return IntegrationRule::query()->where('integration_id', $integration->id)->where('event', $event)->first();
    }

    private function number(IntegrationRule $rule): PhoneNumber
    {
        if ($rule->phone_number_id !== null) {
            $number = PhoneNumber::query()->find($rule->phone_number_id);

            return $number ?? throw new Skip('The number chosen for this message no longer exists. Choose another one.');
        }
        $connected = PhoneNumber::query()->where('status', PhoneNumberStatus::Connected)->orderBy('created_at')->get();
        if ($connected->isEmpty()) {
            throw new Skip('No WhatsApp number is connected.');
        }

        /** @var PhoneNumber */
        return $connected->first();
    }
}
