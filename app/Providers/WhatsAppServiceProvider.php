<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Webhooks\Handlers\AccountUpdateHandler;
use App\Domain\Webhooks\Handlers\CoexistenceSyncHandler;
use App\Domain\Webhooks\Handlers\ContactUpdatesHandler;
use App\Domain\Webhooks\Handlers\MessageEchoesHandler;
use App\Domain\Webhooks\Handlers\MessagesHandler;
use App\Domain\Webhooks\Handlers\PhoneNumberHealthHandler;
use App\Domain\Webhooks\Handlers\WabaCapabilityHandler;
use App\Domain\Webhooks\WebhookProcessor;
use App\Infrastructure\Meta\Fake\FakeMeta;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Secrets\DatabaseSecretStore;
use App\Infrastructure\Secrets\Keyring;
use App\Infrastructure\Secrets\SecretStore;
use Illuminate\Support\ServiceProvider;

final class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Keyring::class, fn ($app) => new Keyring(
            config('engage.secrets.keys'),
            (string) config('app.key'),
            $app->isProduction(),
        ));

        $this->app->singleton(SecretStore::class, DatabaseSecretStore::class);

        $this->app->singleton(GraphClient::class, fn () => GraphClient::fromConfig());

        // Add a handler here when a module starts owning a webhook field (messages, templates, ...).
        $this->app->singleton(WebhookProcessor::class, fn ($app) => new WebhookProcessor($app->make(TenantContext::class), [
            $app->make(AccountUpdateHandler::class),
            $app->make(PhoneNumberHealthHandler::class),
            $app->make(WabaCapabilityHandler::class),
            $app->make(CoexistenceSyncHandler::class),
            $app->make(MessagesHandler::class),
            $app->make(MessageEchoesHandler::class),
            $app->make(ContactUpdatesHandler::class),
        ]));
    }

    public function boot(): void
    {
        if (FakeMeta::enabled()) {
            FakeMeta::register();
        }
    }
}
