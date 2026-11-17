<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Chatbots\Jobs\RunChatbot;
use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Compliance\ComplianceSettings;
use App\Domain\Conversions\Jobs\RecordAdClick;
use App\Domain\Conversions\Services\AdClicks;
use App\Domain\Feedback\FeedbackRequests;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Growth\Jobs\RecordMessageEvidence;
use App\Domain\Growth\Links\LinkCode;
use App\Domain\Growth\Links\RecordLinkChat;
use App\Domain\Growth\Website\Reference;
use App\Domain\Growth\Website\WebsiteMatcher;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Events\MessageStored;
use App\Domain\Messaging\Jobs\DownloadInboundMedia;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Media;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Support\Queue\QueueName;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single write path for messages that come FROM Meta: customer messages, WhatsApp Business
 * app echoes and imported history. Idempotent on wamid — webhook retries and replays never
 * duplicate a message.
 */
final class MessageRecorder
{
    public function __construct(
        private readonly InboundMessageParser $parser,
        private readonly ConversationTracker $conversations,
        private readonly ConsentService $consent,
    ) {}

    /**
     * Growth Hub: where the message came from (a Meta ad referral, a website code) is worked out in the background,
     * after the chat and the chatbot have the message. Never allowed to affect the message itself.
     */
    private function queueAttribution(Message $message): void
    {
        // Conversions to Meta: the ad click (ctwa_clid) is kept for every chat that starts from an ad,
        // whatever the Growth Hub settings. Never allowed to affect the message.
        try {
            if (AdClicks::referral($message) !== null) {
                RecordAdClick::dispatch($message->id)->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning('Ad click was not queued.', ['message_id' => $message->id, 'error' => $e->getMessage()]);
        }

        // A chat from one of the workspace's wa.me links, QR codes or website buttons ("… (Ref: EX-…)").
        try {
            if (LinkCode::extract($message->body) !== null) {
                RecordLinkChat::dispatch($message->id)->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning('Link chat was not queued.', ['message_id' => $message->id, 'error' => $e->getMessage()]);
        }

        try {
            $tenant = app(TenantContext::class)->tenantOrNull();
            // A Meta ad referral, a website code, or a tracked website's greeting without its code.
            $proof = isset(((array) ($message->content ?? []))['referral']) || Reference::extract($message->body) !== null
                || ($tenant !== null && WebsiteMatcher::greetingSite($tenant->id, (string) $message->getAttribute('phone_number_id'), $message->body) !== null);
            if ($tenant !== null && $proof && GrowthSettings::for($tenant)->enabled) {
                RecordMessageEvidence::dispatch($message->id)->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning('Growth Hub attribution was not queued.', ['message_id' => $message->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $raw  one Cloud API message object
     */
    public function record(PhoneNumber $number, Contact $contact, array $raw, MessageOrigin $origin): ?Message
    {
        $wamid = isset($raw['id']) ? (string) $raw['id'] : null;
        $existing = $wamid !== null ? Message::query()->where('wamid', $wamid)->first() : null;
        if ($existing !== null) {
            // History sends media content later under the placeholder's wamid.
            if ($existing->type === 'media_placeholder' && in_array($raw['type'] ?? null, InboundMessageParser::MEDIA_TYPES, true)) {
                $this->upgradePlaceholder($existing, $number, $raw);
            }
            // Meta can deliver a message twice: deduplicate on the message ID, but keep the ad referral
            // if this copy has one the stored copy doesn't (attribution is idempotent per message).
            $content = (array) ($existing->content ?? []);
            if (isset($raw['referral']) && is_array($raw['referral']) && ! isset($content['referral']) && $existing->direction === Message::INBOUND) {
                $existing->forceFill(['content' => $content + ['referral' => $raw['referral']]])->save();
                $this->queueAttribution($existing);
            }

            return null; // already stored
        }

        $type = (string) ($raw['type'] ?? '');

        // Edits and revokes change an existing message rather than adding one.
        if ($type === 'edit' || $type === 'revoke') {
            $this->applyEditOrRevoke($raw, $type);

            return null;
        }

        $parsed = $this->parser->parse($raw);
        $inbound = $origin === MessageOrigin::Customer || ($origin === MessageOrigin::History && ! $this->isFromBusiness($raw, $number));
        $at = isset($raw['timestamp']) && is_numeric($raw['timestamp']) ? Carbon::createFromTimestamp((int) $raw['timestamp']) : now();
        $conversation = $this->conversations->forContact($number, $contact);

        $media = null;
        if ($parsed['media'] !== null && $parsed['media']['meta_media_id'] !== null) {
            $media = Media::query()->create([
                'direction' => $inbound ? 'inbound' : 'outbound',
                'meta_media_id' => $parsed['media']['meta_media_id'],
                'mime_type' => $parsed['media']['mime_type'],
                'sha256' => $parsed['media']['sha256'],
                'filename' => $parsed['media']['filename'],
                'uploaded_to_phone_number_id' => $number->id,
                'status' => 'pending',
            ]);
        }

        try {
            /** @var Message $message */
            $message = DB::transaction(fn () => Message::query()->create([ // savepoint: wamid race
                'conversation_id' => $conversation->id,
                'phone_number_id' => $number->id,
                'contact_id' => $contact->id,
                'direction' => $inbound ? Message::INBOUND : Message::OUTBOUND,
                'origin' => $origin,
                'type' => $parsed['type'],
                'status' => $inbound ? MessageStatus::Received : $this->historyStatus($raw),
                'wamid' => $wamid,
                'context_wamid' => $parsed['context_wamid'],
                'body' => $parsed['body'],
                'content' => $parsed['content'] ?: null,
                'media_id' => $media?->id,
                'meta_timestamp' => $at,
                'sent_at' => $inbound ? null : $at,
            ]));
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return null; // concurrent delivery of the same wamid
            }
            throw $e;
        }

        $this->conversations->apply($message);

        if ($inbound && $origin === MessageOrigin::Customer) {
            $contact->forceFill(['last_inbound_at' => $at])->save();
            $this->applyConsentKeyword($contact, $message);
            Conversation::query()->whereKey($message->conversation_id)->whereNotNull('snoozed_until')->update(['snoozed_until' => null]);
            $conversation = Conversation::query()->with('contact')->find($message->conversation_id);
            // The number's chatbot answers in its own job, after this message is in the inbox. While
            // it handles the conversation, the generic auto-reply and auto-routing stay out of its way
            // (the bot routes to a person itself when it hands off).
            // A tap on a CSAT rating (Good / OK / Poor): saved and thanked; no chatbot, auto-reply or routing.
            $rating = FeedbackRequests::isAnswer($message);
            if ($rating) {
                app(FeedbackRequests::class)->answer($message);
            }
            $bot = ! $rating && $conversation !== null && app(Chatbots::class)->wantsMessage($conversation);
            if ($rating) {
                // nothing else answers a rating
            } elseif ($bot) {
                RunChatbot::dispatch($message->id)->afterCommit();
            } else {
                app(AutoReply::class)->handle($message, $contact);
                if ($conversation !== null) {
                    app(InboxTools::class)->route($conversation);
                }
            }
            $this->queueAttribution($message);
        }

        // Imported history is old news: no realtime push per message (thousands of them would make
        // every open inbox reload over and over) and its files are fetched on the slow lane, so an
        // import can never flood Meta's media API or the browsers. Live messages are unchanged.
        $history = $origin === MessageOrigin::History;

        if ($media !== null) {
            $download = DownloadInboundMedia::dispatch($media->id)->afterCommit();
            if ($history) {
                $download->onQueue(QueueName::Sync->value);
            }
        }

        if (! $history) {
            MessageStored::dispatch($message, true);
        }

        return $message;
    }

    /** @param array<string, mixed> $raw */
    private function upgradePlaceholder(Message $message, PhoneNumber $number, array $raw): void
    {
        $parsed = $this->parser->parse($raw);
        $media = Media::query()->create([
            'direction' => $message->direction,
            'meta_media_id' => $parsed['media']['meta_media_id'] ?? null,
            'mime_type' => $parsed['media']['mime_type'] ?? null,
            'sha256' => $parsed['media']['sha256'] ?? null,
            'filename' => $parsed['media']['filename'] ?? null,
            'uploaded_to_phone_number_id' => $number->id,
            'status' => 'pending',
        ]);
        $message->forceFill(['type' => $parsed['type'], 'body' => $parsed['body'], 'content' => $parsed['content'] ?: null, 'media_id' => $media->id])->save();

        $history = $message->origin === MessageOrigin::History;
        if ($media->meta_media_id !== null) {
            $download = DownloadInboundMedia::dispatch($media->id)->afterCommit();
            if ($history) {
                $download->onQueue(QueueName::Sync->value);
            }
        }
        if (! $history) {
            MessageStored::dispatch($message, false);
        }
    }

    private function applyConsentKeyword(Contact $contact, Message $message): void
    {
        $intent = $this->consent->intentFor($message);
        if ($intent === null) {
            return;
        }
        $text = $message->body;
        // Any answer ends a pending consent question.
        if ($contact->consent_requested_at !== null) {
            $contact->forceFill(['consent_requested_at' => null])->save();
        }
        // A tapped button (our in-chat "Subscribe" request) is recorded apart from a typed keyword.
        $source = $message->type === 'text' ? 'keyword' : 'in_chat_button';
        // The exact words: the consent question the customer answered (with the button tapped), or the keyword.
        $wording = $source === 'in_chat_button'
            ? ComplianceSettings::for(app(TenantContext::class)->tenantOrNull())->consentRequestText.' ['.trim((string) $text).']'
            : trim((string) $text);

        if ($intent === 'decline') {
            $this->consent->recordDecline($contact, $source, $text, $message->id, $wording);

            return; // nothing is sent back: "No thanks" is an answer, not a request
        }

        if ($intent === 'opt_out') {
            $changed = $this->consent->optOut($contact, $source, $text, $message->id, wording: $wording);
        } else {
            $changed = $this->consent->optIn($contact, $source, $text, $message->id, wording: $wording);
            // An explicit Subscribe also lifts an earlier "stop marketing" (Meta preference or a bot block).
            if ($contact->marketing_opted_out) {
                $this->consent->setMarketingPreference($contact, false, $text, $source);
                $changed = true;
            }
        }

        // An opt-out ends any chatbot conversation at once: the bot never answers STOP.
        if ($changed && $intent === 'opt_out' && ($conversation = Conversation::query()->find($message->conversation_id)) !== null) {
            app(Chatbots::class)->stop($conversation, 'opted_out');
        }

        // Confirm once, when the state really changed — never an auto-reply loop.
        if ($changed) {
            app(ConsentAutoReply::class)->confirm($message, $intent);
        }
    }

    /** @param array<string, mixed> $raw */
    private function applyEditOrRevoke(array $raw, string $type): void
    {
        $originalId = $raw[$type]['original_message_id'] ?? null;
        $original = is_string($originalId) ? Message::query()->where('wamid', $originalId)->first() : null;
        if ($original === null) {
            return;
        }

        if ($type === 'revoke') {
            $original->forceFill(['status' => MessageStatus::Deleted, 'revoked_at' => now(), 'body' => null])->save();
        } else {
            $edited = $this->parser->parse((array) ($raw['edit']['message'] ?? []));
            $original->forceFill(['body' => $edited['body'] ?? $original->body, 'edited_at' => now()])->save();
        }

        MessageStored::dispatch($original, false);
    }

    /**
     * History messages: `from` is the business number when the business sent it.
     *
     * @param  array<string, mixed>  $raw
     */
    private function isFromBusiness(array $raw, PhoneNumber $number): bool
    {
        $from = preg_replace('/\D+/', '', (string) ($raw['from'] ?? ''));

        return $from !== '' && $from === preg_replace('/\D+/', '', (string) $number->display_phone_number);
    }

    /** @param array<string, mixed> $raw */
    private function historyStatus(array $raw): MessageStatus
    {
        $status = strtolower((string) ($raw['history_context']['status'] ?? 'sent'));

        return MessageStatus::fromWebhook($status === 'error' ? 'failed' : $status) ?? MessageStatus::Sent;
    }
}
