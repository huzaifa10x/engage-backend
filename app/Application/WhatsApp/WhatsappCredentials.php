<?php

declare(strict_types=1);

namespace App\Application\WhatsApp;

use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
use App\Domain\WhatsApp\Models\MetaAccessToken;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Secrets\SecretStore;
use App\Support\Api\ErrorCode;

/** Resolves the business token for a WABA. The only reader of token secrets. */
final class WhatsappCredentials
{
    public function __construct(
        private readonly SecretStore $secrets,
        private readonly TenantContext $context,
    ) {}

    public function tokenFor(WabaAccount $waba): string
    {
        /** @var MetaAccessToken|null $token */
        $token = $this->context->bypass(fn () => MetaAccessToken::query()->find($waba->access_token_id));

        if ($token === null || ! $token->isUsable()) {
            throw new WhatsappException('The connection to this WhatsApp account has expired. Reconnect it from Channels.', ErrorCode::MetaApiError, 409);
        }

        return $this->secrets->get($token->secret_id);
    }
}
