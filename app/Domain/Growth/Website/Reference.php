<?php

declare(strict_types=1);

namespace App\Domain\Growth\Website;

/**
 * The website reference code (Technical Specification v1.2 §1): "XG-" + 10 characters from an alphabet
 * without I, L, O, 0 and 1, made by the visitor's browser at the moment of the tap and written at the end
 * of the WhatsApp message: "… (Ref: XG-7PK9M2Q8TD)" or, in Arabic, "… (المرجع: XG-7PK9M2Q8TD)".
 *
 * Only a valid code at the very end of the message counts (a code in the middle, or two codes, is not
 * one). The stored message is never changed: clean() is for display and for chatbots (when the
 * workspace's strip flag is on). The code is a public claim, never proof of a person; it is stored only
 * as an HMAC digest.
 */
final class Reference
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const PATTERN = '/\bXG-([ABCDEFGHJKMNPQRSTUVWXYZ23456789]{10})\b/u';

    /** The terminal clause: optional separator, then "(Ref: XG-…)", "(المرجع: XG-…)", "(XG-…)" or a bare "XG-…" (any short label; bidi marks allowed). */
    private const TERMINAL = '/[\s\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]*[\-–—·|,]?[\s\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]*(?:[(\[（][\s\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]*(?:[\p{L}\s\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]{1,24}[:：][\s\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]*)?XG-([ABCDEFGHJKMNPQRSTUVWXYZ23456789]{10})[\s\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]*[)\]）]|(?:[\p{L}\s\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]{1,24}[:：][\s\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]*)?XG-([ABCDEFGHJKMNPQRSTUVWXYZ23456789]{10}))[\s\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}.!?。]*$/u';

    /**
     * The code at the end of the message and the message without it.
     *
     * @return array{token: string, clean: string}|null null when there is no valid terminal code, or more than one code
     */
    public static function extract(?string $text): ?array
    {
        if ($text === null || $text === '' || mb_strlen($text) > 4096) {
            return null;
        }
        if (preg_match_all(self::PATTERN, $text) !== 1) {
            return null; // none, or two codes: never guess which one
        }
        if (preg_match(self::TERMINAL, $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null; // the code is not at the end
        }
        $token = 'XG-'.($m[1][0] !== '' ? $m[1][0] : $m[2][0]);
        $clean = rtrim(substr($text, 0, $m[0][1]));

        return ['token' => $token, 'clean' => $clean];
    }

    /** The message as people and chatbots read it: without a valid terminal code. */
    public static function clean(?string $text): ?string
    {
        $found = self::extract($text);

        return $found === null ? $text : $found['clean'];
    }

    public static function valid(string $token): bool
    {
        return preg_match('/^XG-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{10}$/', $token) === 1;
    }

    /** How a code is stored: an HMAC with the application key, as hex (bytea in SQL via decode(?, 'hex')). */
    public static function digest(string $token): string
    {
        return hash_hmac('sha256', strtoupper($token), (string) config('app.key'));
    }
}
