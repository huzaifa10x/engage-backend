<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Push\WebPush;
use Illuminate\Console\Command;

/** Prints a new VAPID key pair for phone notifications, to paste into .env once. */
final class GeneratePushKeys extends Command
{
    protected $signature = 'engage:push:keys';

    protected $description = 'Generate the VAPID key pair for phone notifications (Web Push).';

    public function handle(): int
    {
        [$public, $private] = WebPush::generateKeys();
        $this->line('Add these to .env (keep them; changing them turns notifications off on every phone):');
        $this->line("VAPID_PUBLIC_KEY={$public}");
        $this->line("VAPID_PRIVATE_KEY={$private}");

        return self::SUCCESS;
    }
}
