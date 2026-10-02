<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConsentService;
use App\Domain\Messaging\Services\ConversationTracker;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Models\MetaAccessToken;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Secrets\SecretStore;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * LOCAL DEVELOPMENT ONLY. Gives the demo workspace a "connected" WhatsApp number, contacts and
 * realistic conversations so every screen (inbox, contacts, channels, dashboard) has content.
 * Combined with META_FAKE=true, sending from these chats works offline.
 * Idempotent: skipped when the workspace already has a WhatsApp account.
 */
final class DemoWorkspaceSeeder extends Seeder
{
    private PhoneNumber $number;

    private ?TenantMembership $owner = null;

    public function run(TenantContext $context, SecretStore $secrets, ConversationTracker $tracker, ConsentService $consent): void
    {
        $tenant = $context->bypass(function (): ?Tenant {
            $user = User::query()->where('email', 'owner@engage.test')->first();

            return $user?->last_active_tenant_id ? Tenant::query()->find($user->last_active_tenant_id) : null;
        });

        if ($tenant === null) {
            return;
        }

        $context->run($tenant, function () use ($context, $secrets, $tracker, $consent) {
            if (WabaAccount::query()->exists()) {
                return; // already seeded
            }

            $this->owner = TenantMembership::query()->oldest()->first();

            $token = MetaAccessToken::query()->create([
                'secret_id' => $secrets->put('meta.business_token', 'EAA-local-demo-token', $context->id()),
                'meta_app_id' => 'local-fake-app',
            ]);
            $waba = WabaAccount::query()->create([
                'waba_id' => '100000000000001', 'access_token_id' => $token->id, 'name' => 'Demo Store',
                'business_name' => 'Demo Store LLC', 'currency' => 'USD', 'status' => WabaStatus::Connected,
                'is_subscribed_to_webhooks' => true, 'connected_at' => now()->subDays(10),
            ]);
            $this->number = PhoneNumber::query()->create([
                'waba_account_id' => $waba->id, 'phone_number_id' => '100000000000002', 'display_phone_number' => '+971 50 000 0001',
                'verified_name' => 'Demo Store', 'name_status' => 'APPROVED', 'status' => PhoneNumberStatus::Connected,
                'onboarding_type' => OnboardingType::NewNumber, 'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K',
                'code_verification_status' => 'VERIFIED', 'platform_type' => 'CLOUD_API',
                'capabilities' => PhoneNumber::capabilitiesFor(OnboardingType::NewNumber), 'registered_at' => now()->subDays(10),
            ]);

            // Open window, agent replied, customer waiting for an answer (1 unread).
            $this->thread($tracker, ['name' => 'Sara Ahmed', 'wa_id' => '971501234567'], [
                ['in', 180, 'Hi! Do you have the blue sneakers in size 42?'],
                ['out', 175, 'Hi Sara! Yes, size 42 is in stock 👟', 'read'],
                ['in', 172, "Great, what's the price?"],
                ['out', 170, 'AED 349 — and delivery within Dubai is free.', 'read'],
                ['in', 8, "Perfect, I'll take them. Can you deliver tomorrow?"],
            ], assignToOwner: true);

            // Two unanswered messages, unassigned.
            $this->thread($tracker, ['name' => 'Fatima Noor', 'wa_id' => '971563456789'], [
                ['in', 32, 'Hello, do you ship to Abu Dhabi?'],
                ['in', 31, 'And how long does delivery take?'],
            ]);

            // Username user (no phone number shared) — business-scoped user ID only.
            $this->thread($tracker, ['profile_name' => 'Layla', 'username' => 'layla.designs', 'bsuid' => 'AE.48151623420000000001'], [
                ['in', 65, 'Hello 👋 I saw your ad on Instagram'],
                ['out', 60, 'Hi Layla! Welcome — what are you looking for today?', 'delivered'],
            ]);

            // Window closed (last customer message > 24h ago) → only templates allowed.
            $this->thread($tracker, ['name' => 'Priya Sharma', 'wa_id' => '971585678901', 'email' => 'priya@example.com'], [
                ['in', 1620, 'Can I return an item I bought last week?'],
                ['out', 1600, 'Of course — returns are free within 14 days. Please send your order number.', 'read'],
                ['in', 1590, '#10423'],
            ]);

            // Business-initiated template, customer replied, conversation closed.
            $omar = $this->thread($tracker, ['name' => 'Omar Khalid', 'wa_id' => '971552345678'], [
                ['tpl', 2900, 'Hi Omar, your order #10388 has shipped and arrives tomorrow.', 'read', 'order_update'],
                ['in', 2890, 'Thanks!'],
                ['out', 2885, "You're welcome, enjoy! 🙌", 'read'],
            ]);
            $omar->forceFill(['status' => ConversationStatus::Closed, 'closed_at' => now()->subDays(2)])->save();

            // A failed delivery (shows the red error state).
            $this->thread($tracker, ['name' => 'Hassan Ali', 'wa_id' => '971507777777'], [
                ['in', 300, 'Is the store open on Friday?'],
                ['out', 290, 'Yes, 10am to 10pm on Fridays.', 'failed'],
            ]);

            // Template sent, never answered.
            $this->thread($tracker, ['name' => 'John Miller', 'wa_id' => '447700900123'], [
                ['tpl', 8640, 'Welcome to Demo Store, John! Reply here any time if you need help.', 'delivered', 'welcome_message'],
            ]);

            // Opted out with STOP.
            $ali = $this->thread($tracker, ['name' => 'Ali Hassan', 'wa_id' => '971504567890'], [
                ['tpl', 4400, 'Weekend sale: 30% off all sneakers until Sunday!', 'read', 'weekend_promo'],
                ['in', 4380, 'STOP'],
            ]);
            $consent->optOut(Contact::query()->findOrFail($ali->contact_id), 'keyword', 'STOP');

            // Contacts without conversations (contacts page, "start conversation" flow).
            foreach ([
                ['Mariam Saleh', '971509990001', 'opted_in'],
                ['Rashid Khan', '971509990002', 'unknown'],
                ['Noura Al Mansoori', '971509990003', 'opted_in'],
                ['David Chen', '6591234567', 'unknown'],
            ] as [$name, $waId, $state]) {
                Contact::query()->create([
                    'name' => $name, 'wa_id' => $waId, 'source' => 'manual', 'consent_state' => $state,
                    'opted_in_at' => $state === 'opted_in' ? now()->subDays(20) : null,
                ]);
            }
        });
    }

    /**
     * @param  array<string, string>  $who
     * @param  list<array{0: string, 1: int, 2: string, 3?: string, 4?: string}>  $messages  [kind, minutes ago, text, status, template]
     */
    private function thread(ConversationTracker $tracker, array $who, array $messages, bool $assignToOwner = false): Conversation
    {
        $contact = Contact::query()->create($who + ['source' => 'inbound']);
        $conversation = $tracker->forContact($this->number, $contact);
        $lastInbound = null;

        foreach ($messages as $m) {
            [$kind, $minutesAgo, $text] = $m;
            $at = Carbon::now()->subMinutes($minutesAgo);
            $inbound = $kind === 'in';
            $status = $inbound ? MessageStatus::Received : MessageStatus::from($m[3] ?? 'read');

            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'phone_number_id' => $this->number->id,
                'contact_id' => $contact->id,
                'direction' => $inbound ? Message::INBOUND : Message::OUTBOUND,
                'origin' => $inbound ? MessageOrigin::Customer : MessageOrigin::Agent,
                'type' => $kind === 'tpl' ? 'template' : 'text',
                'status' => $status,
                'wamid' => 'wamid.DEMO'.Str::upper(Str::random(24)),
                'body' => $text,
                'template' => $kind === 'tpl' ? ['name' => $m[4] ?? 'demo_template', 'language' => 'en'] : null,
                'sent_by_membership_id' => $inbound ? null : $this->owner?->id,
                'meta_timestamp' => $at,
                'sent_at' => $inbound ? null : $at,
                'delivered_at' => in_array($status, [MessageStatus::Delivered, MessageStatus::Read], true) ? $at->copy()->addSeconds(3) : null,
                'read_at' => $status === MessageStatus::Read ? $at->copy()->addMinutes(1) : null,
                'failed_at' => $status === MessageStatus::Failed ? $at->copy()->addSeconds(2) : null,
                'error_code' => $status === MessageStatus::Failed ? '131026' : null,
                'error_title' => $status === MessageStatus::Failed ? 'Message undeliverable' : null,
            ]);
            $message->setAttribute('created_at', $at)->save();

            $tracker->apply($message);
            $lastInbound = $inbound ? $at : $lastInbound;
        }

        // Unread = customer messages after the last reply (the tracker counts every inbound).
        $unread = 0;
        foreach (array_reverse($messages) as $m) {
            if ($m[0] !== 'in') {
                break;
            }
            $unread++;
        }

        $conversation->refresh()->forceFill([
            'unread_count' => $unread,
            'assigned_membership_id' => $assignToOwner ? $this->owner?->id : null,
        ])->save();
        $contact->forceFill(['last_inbound_at' => $lastInbound])->save();

        return $conversation;
    }
}
