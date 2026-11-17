<?php

declare(strict_types=1);

namespace App\Domain\Growth\Links;

/**
 * The short code at the end of a wa.me link's pre-filled text: "EX-" + 6 characters from an alphabet
 * without I, L, O, 0 and 1, written as "… (Ref: EX-7PK9M2)". Only a code at the very end of the message
 * counts. The stored message never changes; agents and chatbots read it without the code.
 */
final class LinkCode
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const TERMINAL = '/[\s\x{200E}\x{200F}]*[(\[（]?[\s\x{200E}\x{200F}]*(?:Ref[:：][\s]*)?\bEX-([ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6})\b[\s\x{200E}\x{200F}]*[)\]）]?[\s\x{200E}\x{200F}.!?]*$/u';

    public static function generate(): string
    {
        $code = 'EX-';
        for ($i = 0; $i < 6; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    public static function append(string $message, string $code): string
    {
        $message = rtrim($message);

        return ($message === '' ? '' : $message.' ')."(Ref: {$code})";
    }

    /**
     * @return array{code: string, clean: string}|null
     */
    public static function extract(?string $text): ?array
    {
        if ($text === null || $text === '' || mb_strlen($text) > 4096) {
            return null;
        }
        if (preg_match(self::TERMINAL, $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return ['code' => 'EX-'.$m[1][0], 'clean' => rtrim(substr($text, 0, $m[0][1]))];
    }

    public static function clean(?string $text): ?string
    {
        $found = self::extract($text);

        return $found === null ? $text : $found['clean'];
    }
}
