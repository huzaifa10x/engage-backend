<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Enums\OnboardingType;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * The same WhatsApp chat history can be imported into two workspaces: the ids WhatsApp gives its
 * messages are unique per workspace, not platform-wide. (A number that was connected to one
 * workspace, disconnected, and connected to another brings the same history, with the same ids.)
 */
final class HistoryAcrossWorkspacesTest extends TestCase
{
    use InteractsWithWhatsapp;

    private function workspace(string $wabaId, string $phoneId, string $display): Tenant
    {
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'pro');
        $this->connectNumber($tenant, $wabaId, $phoneId, $display, OnboardingType::Coexistence);

        return $tenant;
    }

    private function history(string $wabaId, string $phoneId, string $display): void
    {
        $messages = [];
        foreach ([1, 2, 3] as $i) {
            $messages[] = ['from' => '971501234567', 'id' => "wamid.SHARED{$i}", 'timestamp' => (string) (1739230000 + $i), 'type' => 'text', 'text' => ['body' => "Old message {$i}"], 'history_context' => ['status' => 'READ']];
        }
        $this->postWebhook($this->webhookBody('history', [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => preg_replace('/\D+/', '', $display), 'phone_number_id' => $phoneId],
            'history' => [['metadata' => ['phase' => 1, 'chunk_order' => 1, 'progress' => 100], 'threads' => [['id' => '971501234567', 'messages' => $messages]]]],
        ], $wabaId))->assertOk();
        $this->artisan('engage:coexistence:import', ['--now' => true]);
    }

    private function count(Tenant $tenant): int
    {
        return (int) $this->tenantContext()->run($tenant, fn () => Message::query()->where('wamid', 'like', 'wamid.SHARED%')->count());
    }

    public function test_history_with_ids_another_workspace_already_holds_is_still_imported(): void
    {
        $this->configureMeta();
        $first = $this->workspace('102290129340398', '106540352242922', '+971 58 549 6310');
        $second = $this->workspace('202290129340398', '206540352242922', '+971 58 000 0002');

        $this->history('102290129340398', '106540352242922', '+971 58 549 6310');
        $this->assertSame(3, $this->count($first));

        // The same messages, same ids, arrive for the second workspace: stored there too, not dropped.
        $this->history('202290129340398', '206540352242922', '+971 58 000 0002');
        $this->assertSame(3, $this->count($second));
        $this->assertSame(3, $this->count($first));

        // Within one workspace a repeat is still ignored.
        $this->history('202290129340398', '206540352242922', '+971 58 000 0002');
        $this->assertSame(3, $this->count($second));
    }
}
