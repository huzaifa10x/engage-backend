<?php

declare(strict_types=1);

namespace App\Domain\Developer;

use App\Domain\Developer\Jobs\DeliverWebhook;
use App\Domain\Developer\Models\ApiKey;
use App\Domain\Developer\Models\WebhookEndpoint;

/**
 * THE description of public API v1. One source for three things:
 *
 *   • the documentation page          (GET /api/public/v1/spec, rendered by the portal at /docs/api)
 *   • the official Postman collection (GET /api/public/v1/postman.json)
 *   • the OpenAPI description         (GET /api/public/v1/openapi.json)
 *
 * It cannot drift from the code unnoticed: tests/Feature/Api/ApiDocumentationTest fails when a
 * route exists that is not described here, when something described here is not a route, when a
 * documented permission differs from the one the route enforces, and when a documented example
 * request or response no longer matches what the API really accepts and returns.
 *
 * So: change an endpoint → change its entry here in the same commit, or the build goes red.
 */
final class ApiSpec
{
    public const VERSION = 'v1';

    private const CONTACT = [
        'id' => '01a11637-76d8-7315-a1e6-a952a9445800', 'phone' => '+971501234567', 'name' => 'Sara Ahmed', 'email' => 'sara@example.com',
        'tags' => ['vip'], 'attributes' => ['city' => 'Dubai'], 'consent' => 'opted_in', 'created_at' => '2026-10-07T09:30:00+00:00',
    ];

    private const MESSAGE = [
        'id' => '01a11637-9c1e-70d2-8f6a-3b6f0c5d2e11', 'conversation_id' => '01a11637-8a40-72b1-9d3c-5e7f1a2b3c4d', 'phone_number_id' => '01a11630-1111-7000-8000-000000000001',
        'direction' => 'outbound', 'type' => 'text', 'status' => 'queued', 'text' => 'Your order is on its way', 'template' => null, 'media' => null,
        'whatsapp_message_id' => null, 'origin' => 'api', 'contact' => ['id' => '01a11637-76d8-7315-a1e6-a952a9445800', 'phone' => '+971501234567', 'name' => 'Sara Ahmed'],
        'error' => null, 'created_at' => '2026-10-07T09:31:00+00:00',
    ];

    private const CONVERSATION = [
        'id' => '01a11637-8a40-72b1-9d3c-5e7f1a2b3c4d', 'phone_number_id' => '01a11630-1111-7000-8000-000000000001', 'status' => 'open', 'assigned_to' => null,
        'unread_count' => 1, 'last_message_at' => '2026-10-07T09:31:00+00:00', 'contact' => self::CONTACT,
    ];

    /** @return array<string, mixed> */
    public static function document(?string $baseUrl = null): array
    {
        $base = rtrim($baseUrl ?? (string) config('app.url'), '/').'/api/public/'.self::VERSION;

        return [
            'title' => '10X Engage API',
            'version' => self::VERSION,
            'base_url' => $base,
            'introduction' => 'Send WhatsApp messages, manage contacts and follow conversations from your own systems. The API is JSON over HTTPS, works inside one workspace per key, and applies the same WhatsApp rules as the 10X Engage inbox.',
            'authentication' => [
                'summary' => 'Create a key in 10X Engage under Developer → API keys, and send it in the Authorization header of every request.',
                'header' => 'Authorization: Bearer eng_live_YOUR_KEY',
                'notes' => [
                    'A key belongs to one workspace. Nothing in a request can reach another workspace.',
                    'A key is shown once when created. Store it as a secret; if it leaks, revoke it and create a new one.',
                    'Each key has permissions (scopes). A request the key is not permitted to make is answered 403 insufficient_scope.',
                    'API access must be included in the workspace\'s plan.',
                ],
                'scopes' => collect(ApiKey::SCOPES)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
            ],
            'conventions' => [
                ['title' => 'Requests and responses', 'body' => 'Send JSON with "Content-Type: application/json" (file uploads use multipart/form-data). Every successful answer wraps its result in "data". Times are ISO 8601 in UTC. Phone numbers are in international format with a leading +.'],
                ['title' => 'Pagination', 'body' => 'List endpoints return up to "limit" items (default 25 or 50, maximum 100) and a "next_cursor". Pass it back as ?cursor= to get the next page; null means there are no more.'],
                ['title' => 'Idempotency', 'body' => 'Add an "Idempotency-Key" header (any unique text up to 128 characters, such as your order id) when sending a message. Repeating the request with the same key returns the original message instead of sending it again, so a retry after a timeout is safe.'],
                ['title' => 'Rate limits', 'body' => 'Requests are limited per workspace per minute, according to the plan. Every answer carries X-RateLimit-Limit and X-RateLimit-Remaining. Over the limit the API answers 429 with a Retry-After header in seconds; wait that long and retry.'],
                ['title' => 'Sending speed', 'body' => 'Messages are queued and sent to WhatsApp at the speed allowed for each phone number (lower for numbers shared with the WhatsApp Business app). You do not need to pace your requests; a 202 answer means the message is queued.'],
            ],
            'messaging_rules' => [
                'Text and media can only be sent within 24 hours of the customer\'s last message to you. Outside that window, send an approved template.',
                'Templates must be approved by WhatsApp and are sent by name and language.',
                'Messages to contacts who opted out are refused (422 contact_opted_out).',
                'A 202 answer means "queued", not "delivered". Follow the message with the message.* webhook events or GET /messages/{id}.',
            ],
            'errors' => [
                'format' => ['error' => ['code' => 'validation_failed', 'message' => 'The given data was invalid.', 'details' => ['fields' => ['to' => ['Use the international format, for example +971501234567.']]], 'request_id' => '01k2m3n4p5q6r7s8t9v0w1x2y3']],
                'summary' => 'Errors use the HTTP status and always carry a stable "code" your program can check and a "message" written for a person. "details" appears when there is more to say (for example which field is wrong). Quote "request_id" when you contact support.',
                'codes' => [
                    ['status' => 401, 'code' => 'missing_api_key', 'meaning' => 'No key was sent, or the Authorization header is malformed.'],
                    ['status' => 401, 'code' => 'invalid_api_key', 'meaning' => 'The key is wrong, revoked or expired.'],
                    ['status' => 403, 'code' => 'insufficient_scope', 'meaning' => 'The key does not have the permission this endpoint needs.'],
                    ['status' => 403, 'code' => 'feature_not_available', 'meaning' => 'The workspace\'s plan does not include this.'],
                    ['status' => 403, 'code' => 'workspace_unavailable', 'meaning' => 'The workspace is suspended or closed.'],
                    ['status' => 404, 'code' => 'not_found', 'meaning' => 'No such item in this workspace.'],
                    ['status' => 422, 'code' => 'validation_failed', 'meaning' => 'A field is missing or wrong. "details.fields" says which and why.'],
                    ['status' => 422, 'code' => 'window_closed', 'meaning' => 'The 24-hour window is closed: send an approved template instead.'],
                    ['status' => 422, 'code' => 'contact_opted_out', 'meaning' => 'The contact opted out of messages.'],
                    ['status' => 422, 'code' => 'number_unavailable', 'meaning' => 'The phone number is not connected.'],
                    ['status' => 429, 'code' => 'rate_limited', 'meaning' => 'Too many requests this minute. Retry after the number of seconds in Retry-After.'],
                    ['status' => 500, 'code' => 'server_error', 'meaning' => 'Something went wrong on our side. Safe to retry with the same Idempotency-Key.'],
                ],
            ],
            'groups' => self::groups(),
            'webhooks' => self::webhooks(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function groups(): array
    {
        $id = ['name' => 'id', 'type' => 'uuid', 'required' => true, 'description' => 'The item\'s id.'];
        $paging = [
            ['name' => 'limit', 'type' => 'integer', 'required' => false, 'description' => 'Items per page, 1 to 100.'],
            ['name' => 'cursor', 'type' => 'string', 'required' => false, 'description' => 'The next_cursor from the previous page.'],
        ];
        $note = [['name' => 'note', 'type' => 'string', 'required' => false, 'description' => 'Why or how consent was given, for the consent ledger. Up to 300 characters.']];

        return [
            ['name' => 'Account', 'description' => 'Check a key and look up what the workspace has.', 'endpoints' => [
                ['id' => 'me', 'method' => 'GET', 'path' => '/me', 'scope' => null, 'summary' => 'Check your key', 'description' => 'Returns the workspace and the key in use. The quickest way to confirm a key works.',
                    'response' => ['status' => 200, 'example' => ['data' => ['workspace' => ['id' => '01a11630-0000-7000-8000-000000000000', 'name' => 'Palm Estates'], 'api_key' => ['name' => 'CRM sync', 'prefix' => 'eng_live_aJ9p', 'scopes' => ['messages:send', 'contacts:read'], 'expires_at' => null]]]]],
                ['id' => 'phone-numbers', 'method' => 'GET', 'path' => '/phone-numbers', 'scope' => 'templates:read', 'summary' => 'List phone numbers', 'description' => 'Your WhatsApp numbers. Use a number\'s id as "from" when the workspace has more than one.',
                    'response' => ['status' => 200, 'example' => ['data' => [['id' => '01a11630-1111-7000-8000-000000000001', 'phone' => '+971 58 549 6310', 'name' => 'Palm Estates', 'status' => 'connected', 'quality' => 'GREEN']]]]],
                ['id' => 'templates', 'method' => 'GET', 'path' => '/templates', 'scope' => 'templates:read', 'summary' => 'List templates', 'description' => 'Your message templates. Only approved ones can be sent.',
                    'query' => [['name' => 'status', 'type' => 'string', 'required' => false, 'description' => 'Filter by status, for example approved, pending or rejected.']],
                    'response' => ['status' => 200, 'example' => ['data' => [['id' => '01a11631-2222-7000-8000-000000000002', 'name' => 'order_update', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED']]]]],
            ]],
            ['name' => 'Messages', 'description' => 'Send messages and read what was sent and received.', 'endpoints' => [
                ['id' => 'messages.send', 'method' => 'POST', 'path' => '/messages', 'scope' => 'messages:send', 'summary' => 'Send a message',
                    'description' => 'Sends a text, a media file or an approved template. Answers 202 when the message is queued for WhatsApp.',
                    'headers' => [['name' => 'Idempotency-Key', 'type' => 'string', 'required' => false, 'description' => 'Makes a retry safe: the same key never sends twice.']],
                    'body' => [
                        ['name' => 'to', 'type' => 'string', 'required' => 'one of to / contact_id', 'description' => 'The recipient\'s phone number in international format. A contact is created if it is new.'],
                        ['name' => 'contact_id', 'type' => 'uuid', 'required' => 'one of to / contact_id', 'description' => 'An existing contact, instead of "to".'],
                        ['name' => 'from', 'type' => 'uuid', 'required' => false, 'description' => 'The id of the phone number to send from. Needed only when the workspace has several.'],
                        ['name' => 'type', 'type' => 'string', 'required' => true, 'description' => 'text, template, image, video, audio or document.'],
                        ['name' => 'text', 'type' => 'string', 'required' => 'for type text', 'description' => 'The message, up to 4,096 characters. Emoji are supported.'],
                        ['name' => 'preview_url', 'type' => 'boolean', 'required' => false, 'description' => 'Show a preview for the first link in a text message.'],
                        ['name' => 'template.name', 'type' => 'string', 'required' => 'for type template', 'description' => 'The approved template\'s name.'],
                        ['name' => 'template.language', 'type' => 'string', 'required' => 'for type template', 'description' => 'Its language code, for example en or ar.'],
                        ['name' => 'template.variables.header', 'type' => 'array of strings', 'required' => false, 'description' => 'The value for the header variable, if the template has one.'],
                        ['name' => 'template.variables.body', 'type' => 'array of strings', 'required' => false, 'description' => 'Values for the body variables, in order: {{1}}, {{2}} …'],
                        ['name' => 'template.variables.buttons', 'type' => 'array of strings', 'required' => false, 'description' => 'Values for dynamic button URLs, in button order.'],
                        ['name' => 'media_id', 'type' => 'uuid', 'required' => 'for media: one of media_id / media_url', 'description' => 'A file uploaded with POST /media.'],
                        ['name' => 'media_url', 'type' => 'string', 'required' => 'for media: one of media_id / media_url', 'description' => 'A public https address of the file. We download it once and send it.'],
                        ['name' => 'caption', 'type' => 'string', 'required' => false, 'description' => 'Text shown with an image, video or document, up to 1,024 characters. Not for audio.'],
                        ['name' => 'filename', 'type' => 'string', 'required' => false, 'description' => 'The file name the customer sees for a document.'],
                    ],
                    'request' => ['example' => ['to' => '+971501234567', 'type' => 'text', 'text' => 'Your order is on its way'], 'more' => [
                        ['title' => 'Template', 'example' => ['to' => '+971501234567', 'type' => 'template', 'template' => ['name' => 'order_update', 'language' => 'en', 'variables' => ['body' => ['Sara', '#1001']]]]],
                        ['title' => 'Image from a URL', 'example' => ['to' => '+971501234567', 'type' => 'image', 'media_url' => 'https://example.com/receipts/1001.jpg', 'caption' => 'Your receipt']],
                        ['title' => 'Document uploaded earlier', 'example' => ['contact_id' => '01a11637-76d8-7315-a1e6-a952a9445800', 'type' => 'document', 'media_id' => '01a11638-5555-7000-8000-000000000005', 'filename' => 'Invoice-1001.pdf']],
                    ]],
                    'response' => ['status' => 202, 'example' => ['data' => self::MESSAGE]],
                    'errors' => ['window_closed', 'contact_opted_out', 'number_unavailable', 'validation_failed']],
                ['id' => 'media.upload', 'method' => 'POST', 'path' => '/media', 'scope' => 'messages:send', 'summary' => 'Upload a file',
                    'description' => 'Uploads a file once so it can be sent to many people by its id. Accepted: JPEG and PNG images (5 MB), MP4 and 3GP video (16 MB), AAC, AMR, MP3, M4A and OGG audio (16 MB), and PDF, Word, Excel, PowerPoint and text documents (100 MB). Counts towards the plan\'s media storage.',
                    'content_type' => 'multipart/form-data',
                    'body' => [['name' => 'file', 'type' => 'file', 'required' => true, 'description' => 'The file to upload.']],
                    'response' => ['status' => 201, 'example' => ['data' => ['id' => '01a11638-5555-7000-8000-000000000005', 'type' => 'document', 'mime_type' => 'application/pdf', 'filename' => 'Invoice-1001.pdf', 'size' => 48211]]],
                    'errors' => ['validation_failed']],
                ['id' => 'messages.show', 'method' => 'GET', 'path' => '/messages/{id}', 'scope' => 'messages:read', 'summary' => 'Get a message', 'description' => 'One message with its current status: queued, accepted, sent, delivered, read or failed (received for inbound).',
                    'path_params' => [$id], 'response' => ['status' => 200, 'example' => ['data' => ['status' => 'delivered', 'whatsapp_message_id' => 'wamid.HBgMOTcxNTAxMjM0NTY3FQIAERgS'] + self::MESSAGE]]],
                ['id' => 'conversations', 'method' => 'GET', 'path' => '/conversations', 'scope' => 'messages:read', 'summary' => 'List conversations', 'description' => 'Conversations, most recently active first.',
                    'query' => array_merge([['name' => 'status', 'type' => 'string', 'required' => false, 'description' => 'open or closed.']], $paging),
                    'response' => ['status' => 200, 'example' => ['data' => [self::CONVERSATION], 'next_cursor' => null]]],
                ['id' => 'conversations.messages', 'method' => 'GET', 'path' => '/conversations/{id}/messages', 'scope' => 'messages:read', 'summary' => 'List a conversation\'s messages', 'description' => 'Messages in one conversation, newest first.',
                    'path_params' => [$id], 'query' => $paging, 'response' => ['status' => 200, 'example' => ['data' => [self::MESSAGE], 'next_cursor' => null]]],
            ]],
            ['name' => 'Contacts', 'description' => 'Keep your contact list and consent records in step with your own systems.', 'endpoints' => [
                ['id' => 'contacts', 'method' => 'GET', 'path' => '/contacts', 'scope' => 'contacts:read', 'summary' => 'List or find contacts', 'description' => 'All contacts, newest first. Use ?phone= to look one up.',
                    'query' => array_merge([
                        ['name' => 'phone', 'type' => 'string', 'required' => false, 'description' => 'Exact phone number in international format.'],
                        ['name' => 'tag', 'type' => 'string', 'required' => false, 'description' => 'Only contacts with this tag.'],
                    ], $paging),
                    'response' => ['status' => 200, 'example' => ['data' => [self::CONTACT], 'next_cursor' => null]]],
                ['id' => 'contacts.show', 'method' => 'GET', 'path' => '/contacts/{id}', 'scope' => 'contacts:read', 'summary' => 'Get a contact', 'description' => 'One contact.',
                    'path_params' => [$id], 'response' => ['status' => 200, 'example' => ['data' => self::CONTACT]]],
                ['id' => 'contacts.create', 'method' => 'POST', 'path' => '/contacts', 'scope' => 'contacts:write', 'summary' => 'Create a contact', 'description' => 'Adds a contact. A number that already exists is refused (422); look it up with GET /contacts?phone= and update it instead.',
                    'body' => [
                        ['name' => 'phone', 'type' => 'string', 'required' => true, 'description' => 'International format, for example +971501234567.'],
                        ['name' => 'name', 'type' => 'string', 'required' => false, 'description' => 'Up to 190 characters.'],
                        ['name' => 'email', 'type' => 'string', 'required' => false, 'description' => 'A valid email address.'],
                        ['name' => 'tags', 'type' => 'array of strings', 'required' => false, 'description' => 'Up to 50 tags of at most 40 characters.'],
                        ['name' => 'attributes', 'type' => 'object', 'required' => false, 'description' => 'Your own fields, for example {"city": "Dubai"}. Up to 50.'],
                        ['name' => 'opted_in', 'type' => 'boolean', 'required' => false, 'description' => 'true records that the contact agreed to receive messages.'],
                    ],
                    'request' => ['example' => ['phone' => '+971501234567', 'name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'tags' => ['vip'], 'attributes' => ['city' => 'Dubai'], 'opted_in' => true]],
                    'response' => ['status' => 201, 'example' => ['data' => self::CONTACT]], 'errors' => ['validation_failed']],
                ['id' => 'contacts.update', 'method' => 'PATCH', 'path' => '/contacts/{id}', 'scope' => 'contacts:write', 'summary' => 'Update a contact', 'description' => 'Changes only the fields you send. "tags" and "attributes" replace the existing ones.',
                    'path_params' => [$id],
                    'body' => [
                        ['name' => 'name', 'type' => 'string', 'required' => false, 'description' => 'Up to 190 characters.'],
                        ['name' => 'email', 'type' => 'string', 'required' => false, 'description' => 'A valid email address.'],
                        ['name' => 'tags', 'type' => 'array of strings', 'required' => false, 'description' => 'Replaces the contact\'s tags.'],
                        ['name' => 'attributes', 'type' => 'object', 'required' => false, 'description' => 'Replaces the contact\'s own fields.'],
                    ],
                    'request' => ['example' => ['name' => 'Sara A.', 'tags' => ['vip', 'dubai']]],
                    'response' => ['status' => 200, 'example' => ['data' => ['name' => 'Sara A.', 'tags' => ['vip', 'dubai']] + self::CONTACT]], 'errors' => ['validation_failed']],
                ['id' => 'contacts.opt-in', 'method' => 'POST', 'path' => '/contacts/{id}/opt-in', 'scope' => 'contacts:write', 'summary' => 'Record opt-in', 'description' => 'Records that the contact agreed to receive messages. Written to the consent ledger.',
                    'path_params' => [$id], 'body' => $note, 'request' => ['example' => ['note' => 'Ticked the box at checkout']], 'response' => ['status' => 200, 'example' => ['data' => self::CONTACT]]],
                ['id' => 'contacts.opt-out', 'method' => 'POST', 'path' => '/contacts/{id}/opt-out', 'scope' => 'contacts:write', 'summary' => 'Record opt-out', 'description' => 'Records that the contact no longer wants messages. Sending to them is refused from that moment.',
                    'path_params' => [$id], 'body' => $note, 'request' => ['example' => ['note' => 'Asked by phone']], 'response' => ['status' => 200, 'example' => ['data' => ['consent' => 'opted_out'] + self::CONTACT]]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private static function webhooks(): array
    {
        $envelope = fn (string $event, array $data) => ['id' => 'evt_01k2m3n4p5q6r7s8t9v0w1x2y3', 'event' => $event, 'created_at' => '2026-10-07T09:32:00+00:00', 'workspace_id' => '01a11630-0000-7000-8000-000000000000', 'data' => $data];
        $inbound = ['direction' => 'inbound', 'status' => 'received', 'origin' => 'customer', 'text' => 'Is the villa still available?', 'whatsapp_message_id' => 'wamid.HBgMOTcxNTAxMjM0NTY3FQIAEhgU'] + self::MESSAGE;
        $examples = [
            'message.received' => $inbound,
            'message.sent' => ['status' => 'sent'] + self::MESSAGE,
            'message.delivered' => ['status' => 'delivered'] + self::MESSAGE,
            'message.read' => ['status' => 'read'] + self::MESSAGE,
            'message.failed' => ['status' => 'failed', 'error' => ['code' => '131026', 'message' => 'Message undeliverable']] + self::MESSAGE,
            'contact.created' => self::CONTACT,
            'contact.updated' => self::CONTACT,
            'contact.opted_in' => self::CONTACT + ['source' => 'api'],
            'contact.opted_out' => ['consent' => 'opted_out'] + self::CONTACT + ['source' => 'keyword'],
            'conversation.assigned' => ['assigned_to' => '01a11632-3333-7000-8000-000000000003'] + self::CONVERSATION,
            'conversation.closed' => ['status' => 'closed'] + self::CONVERSATION,
        ];

        return [
            'summary' => 'Instead of asking the API again and again, give us a URL (Developer → Webhooks) and we send a request to it when something happens in your workspace.',
            'delivery' => [
                'We send an HTTP POST with a JSON body. Answer with any 2xx status within 10 seconds; do slow work after answering.',
                'A failed delivery is retried after '.implode(', ', array_map(fn (int $s) => $s < 3600 ? ($s / 60).' min' : ($s / 3600).' h', DeliverWebhook::BACKOFF)).'.',
                'After '.WebhookEndpoint::DISABLE_AFTER_FAILURES.' failures in a row the endpoint is switched off and the workspace owners are emailed.',
                'The same event can arrive more than once. Use its "id" to ignore repeats.',
                'The URL must be a public https address.',
            ],
            'headers' => [
                ['name' => 'X-Engage-Event', 'description' => 'The event name, for example message.received.'],
                ['name' => 'X-Engage-Delivery', 'description' => 'The id of this delivery attempt series (the same across retries).'],
                ['name' => 'X-Engage-Signature', 'description' => 't=<unix time>,v1=<signature>. See "Verifying a webhook".'],
            ],
            'signature' => 'The signature is the HMAC-SHA256, as lowercase hex, of "<t>.<raw request body>" using the endpoint\'s signing secret (shown once when you create the endpoint). Recompute it and compare; also reject requests whose t is more than five minutes old.',
            'events' => collect(WebhookEndpoint::EVENTS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'example' => $envelope($key, $examples[$key] ?? [])])->values()->all(),
        ];
    }

    /**
     * Every endpoint as a flat list: [method, path, scope, entry].
     *
     * @return list<array{0: string, 1: string, 2: ?string, 3: array<string, mixed>}>
     */
    public static function endpoints(): array
    {
        $all = [];
        foreach (self::groups() as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                $all[] = [$endpoint['method'], $endpoint['path'], $endpoint['scope'], $endpoint + ['group' => $group['name']]];
            }
        }

        return $all;
    }
}
