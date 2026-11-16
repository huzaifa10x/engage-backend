<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;

/** User-facing onboarding / channel errors with a stable error code. */
final class WhatsappException extends DomainException
{
    /** @param array<string, mixed> $extra */
    public function __construct(string $message, private readonly ErrorCode $error, private readonly int $httpStatus = 422, private readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('WhatsApp onboarding is not configured on this environment.', ErrorCode::WhatsappNotConfigured, 503);
    }

    public static function wabaOwnedByAnotherWorkspace(): self
    {
        return new self('This WhatsApp Business Account is already connected to another 10X Engage workspace.', ErrorCode::WabaAlreadyConnected, 409);
    }

    /**
     * The WABA (and so the number) is still subscribed to another provider's app — or to our own
     * app from another 10X Engage environment ($sameApp).
     *
     * @param  list<array{id: string, name: ?string, link: ?string}>  $apps
     */
    public static function subscribedToAnotherApp(array $apps, bool $sameApp = false): self
    {
        return new self(self::subscribedElsewhereMessage($apps, $sameApp), ErrorCode::NumberSubscribedElsewhere, 409, ['apps' => $apps, 'same_app' => $sameApp]);
    }

    /** @param list<array{id: string, name: ?string, link: ?string}> $apps */
    public static function subscribedElsewhereMessage(array $apps, bool $sameApp = false): string
    {
        $names = array_values(array_filter(array_map(fn (array $app) => $app['name'], $apps)));
        $suffix = $names === [] ? '' : ' ('.implode(', ', $names).')';

        if ($sameApp) {
            return "This WhatsApp number is already connected to another 10X Engage environment{$suffix}. Disconnect it there first, then connect it here again.";
        }

        return "This WhatsApp number is already registered with another application{$suffix}. Disconnect it from that application first, then connect it to 10X Engage again.";
    }

    public static function signupInvalid(string $message = 'This signup session has expired. Start again from Connect WhatsApp.'): self
    {
        return new self($message, ErrorCode::SignupSessionInvalid, 410);
    }

    public static function coexistenceUnavailable(): self
    {
        return new self('Connecting an existing WhatsApp Business app number is not available on this workspace yet.', ErrorCode::FeatureNotAvailable, 403);
    }

    /**
     * Meta refused the call. A permission error (code 200–299, or 10) gets a plain-language
     * message and a "reconnect" action instead of Meta's own text: the business has to grant
     * 10X Engage access to this WhatsApp account again through Connect WhatsApp.
     */
    public static function meta(string $message, ?int $code, ?string $fbtrace, ?int $subcode = null): self
    {
        if (self::isPermissionError($code)) {
            return new self(
                'Meta hasn’t given 10X Engage permission to manage this WhatsApp account. Reconnect it: open Connect WhatsApp, sign in with the Facebook account that manages this business, choose the same business and WhatsApp account, and allow every permission it asks for.',
                ErrorCode::MetaPermissionMissing,
                403,
                array_filter(['action' => 'reconnect', 'meta_code' => $code, 'meta_subcode' => $subcode, 'fbtrace_id' => $fbtrace], fn ($v) => $v !== null),
            );
        }

        return new self($message, ErrorCode::MetaApiError, 502, array_filter(['meta_code' => $code, 'fbtrace_id' => $fbtrace]));
    }

    /** Graph API permission errors: 10 (permission denied) and 200–299 (permission missing). */
    public static function isPermissionError(?int $code): bool
    {
        return $code === 10 || ($code !== null && $code >= 200 && $code <= 299);
    }

    public function errorCode(): ErrorCode
    {
        return $this->error;
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->extra;
    }
}
