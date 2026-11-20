<?php

declare(strict_types=1);

namespace App\Support\Meta;

/**
 * Plain-language, actionable explanations for the Cloud API error codes clients actually hit.
 * Meta's own `title` is kept; this adds what to do about it.
 */
final class MetaErrorCatalog
{
    /**
     * Meta's per-person marketing limit ("This message was not delivered to maintain healthy ecosystem
     * engagement"): Meta caps how many marketing messages one person receives. Says nothing about the
     * business: never retried, never counted as a failure of the number.
     */
    public const PER_PERSON_MARKETING_LIMIT = '131049';

    public const PER_PERSON_MARKETING_LIMIT_LABEL = 'Not delivered: Meta’s per-person marketing limit';

    /** The title we store for a refusal (ours for the per-person marketing limit, Meta's otherwise). */
    public static function title(int|string|null $code, ?string $metaTitle): ?string
    {
        return (string) $code === self::PER_PERSON_MARKETING_LIMIT ? self::PER_PERSON_MARKETING_LIMIT_LABEL : $metaTitle;
    }

    private const HINTS = [
        3 => 'The connected app is missing a permission. Reconnect the number from Channels.',
        10 => 'Permission denied by Meta. Reconnect the number from Channels and accept all requested permissions.',
        100 => 'Meta rejected a value in the message. Check the recipient number, the template variables and the attachment.',
        190 => 'The connection to this WhatsApp account has expired. Reconnect the number from Channels.',
        200 => 'Permission denied by Meta. Reconnect the number from Channels and accept all requested permissions.',
        368 => 'Meta has temporarily blocked this account for a policy violation. Check Account Quality in Meta Business Suite.',
        130429 => 'Too many messages were sent at once. The message is retried automatically.',
        130472 => 'Meta did not deliver this marketing message because the recipient is part of an experiment. This is decided by Meta.',
        131000 => 'Meta had a temporary problem. Try sending again.',
        131005 => 'Permission denied by Meta. Reconnect the number from Channels.',
        131008 => 'A required value is missing. Fill in every template variable and try again.',
        131009 => 'A value is not valid. Check the recipient number and the template variables.',
        131016 => 'WhatsApp is temporarily unavailable. Try again in a few minutes.',
        131021 => 'You cannot send a message to your own business number.',
        131026 => 'The message could not be delivered. The recipient may not be on WhatsApp, may not have accepted the latest WhatsApp terms, or uses an old app version.',
        131030 => 'This recipient is not on the allowed list. Test numbers can only message recipients added in the Meta app dashboard.',
        131031 => 'This WhatsApp Business Account is locked. Check Account Quality in Meta Business Suite.',
        131037 => 'The display name of this number must be approved before sending. Check it in WhatsApp Manager.',
        131042 => 'There is a payment problem on the WhatsApp Business Account. Add or fix the payment method in WhatsApp Manager, then send again.',
        131045 => 'This number is not registered correctly with the Cloud API. Reconnect it from Channels.',
        131047 => 'More than 24 hours have passed since the customer last replied. Send an approved template to restart the conversation.',
        131048 => 'Meta is limiting this number because of spam signals. Slow down and check the quality rating in WhatsApp Manager.',
        131049 => 'Meta limits how many marketing messages one person receives from all businesses. This person has had enough for now. It is not retried and does not affect your number’s quality.',
        131050 => 'The recipient has stopped marketing messages from this business.',
        131051 => 'This message type is not supported.',
        131052 => 'The attachment could not be processed by WhatsApp. Check the file type and size.',
        131053 => 'The attachment could not be uploaded to WhatsApp. Use a supported file type within the size limit (images 5 MB, video and audio 16 MB, documents 100 MB).',
        131056 => 'Too many messages were sent to this recipient in a short time. Wait a moment and try again.',
        131057 => 'The WhatsApp Business Account is in maintenance mode. Try again later.',
        // Not on Meta's public error page yet: wording to confirm against the first real occurrence.
        131062 => 'This customer can only be reached by their WhatsApp username for now. The message will go out once Meta shares their number or user ID.',
        131070 => 'Another app is handling this conversation (for example the WhatsApp Business app or another tool). Reply from there, or wait until it hands the chat back.',
        132000 => 'The number of variables does not match the template. Fill in exactly the variables the template defines.',
        132001 => 'This template does not exist in the selected language or is not approved yet. Sync templates and pick an approved one.',
        132005 => 'The filled-in template text is too long. Shorten the variable values.',
        132007 => 'The template content violates a WhatsApp policy. Edit the template in Templates.',
        132012 => 'A variable has the wrong format for this template. Check the variable values.',
        132015 => 'This template is paused because of low quality. Edit it or use another template.',
        132016 => 'This template has been disabled by Meta because of low quality. Use another template.',
        132068 => 'The flow attached to this template is blocked.',
        133000 => 'The number could not be deregistered. Try again.',
        133004 => 'WhatsApp is temporarily unavailable. Try again in a few minutes.',
        133005 => 'The two-step verification PIN is wrong. Reconnect the number from Channels.',
        133010 => 'This number is not registered with the Cloud API. Reconnect it from Channels.',
        135000 => 'Meta could not send the message. Check the message content and try again.',
        // Template management (subcodes of code 100)
        2388023 => 'A template with this name is being deleted. Wait up to 4 weeks or use a different name.',
        2388024 => 'A template with this name and language already exists. Use a different name or language.',
    ];

    /** Temporary errors: sending the same message again can work, so the inbox offers Retry for these only. */
    public const TEMPORARY = [1, 2, 4, 17, 32, 613, 80007, 130429, 131000, 131016, 131048, 131056, 131057, 133004];

    /** A short plain reason for the inbox, for the errors teammates meet most. */
    private const PLAIN = [
        130429 => 'Too many messages were sent from this number at once.',
        131000 => 'WhatsApp had a temporary problem.',
        131016 => 'WhatsApp is temporarily unavailable.',
        131026 => 'WhatsApp could not deliver this message to this customer.',
        131047 => 'More than 24 hours have passed since the customer last wrote, so only a template can be sent.',
        131048 => 'Meta is slowing this number down because of spam signals.',
        131049 => 'Meta’s limit on marketing messages for this person was reached.',
        131050 => 'The customer stopped marketing messages from you.',
        131056 => 'Too many messages were sent to this customer in a short time.',
        131062 => 'This customer can only be reached by their WhatsApp username for now.',
        131070 => 'Another app is handling this conversation.',
    ];

    public static function temporary(int|string|null $code): bool
    {
        return is_numeric($code) && in_array((int) $code, self::TEMPORARY, true);
    }

    public static function known(int|string|null $code): bool
    {
        return is_numeric($code) && (isset(self::HINTS[(int) $code]) || isset(self::PLAIN[(int) $code]));
    }

    public static function plain(int|string|null $code): ?string
    {
        return is_numeric($code) ? (self::PLAIN[(int) $code] ?? null) : null;
    }

    /** What the inbox offers: retry (temporary), template (131047), or none. */
    public static function action(int|string|null $code): ?string
    {
        return match (true) {
            (string) $code === '131047' => 'template',
            self::temporary($code) => 'retry',
            default => null,
        };
    }

    public static function hint(int|string|null $code, int|string|null $subcode = null): ?string
    {
        foreach ([$subcode, $code] as $candidate) {
            if ($candidate !== null && is_numeric($candidate) && isset(self::HINTS[(int) $candidate])) {
                return self::HINTS[(int) $candidate];
            }
        }

        return match ((string) $code) {
            'number_unavailable' => 'Reconnect the number from Channels.',
            'send_failed' => 'The message could not be sent. Try again; if it keeps failing, contact support.',
            default => null,
        };
    }
}
