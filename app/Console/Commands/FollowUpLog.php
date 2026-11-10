<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Automation\FollowUps;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Why did (or didn't) an alert fire for this customer? Prints, per matching chat: its state and
 * wait, the workspace's follow-up rules and what the plan includes, every scheduled follow-up with
 * its outcome, and the notifications sent for the chat.
 *
 *   php artisan engage:followups:log "Rahul"                     search every workspace
 *   php artisan engage:followups:log "Yash" --workspace="Healthy" one workspace (name contains)
 */
final class FollowUpLog extends Command
{
    protected $signature = 'engage:followups:log {search : Customer name or phone number} {--workspace= : Workspace name (contains)}';

    protected $description = 'Show the follow-up alerts and notifications of a customer\'s chats.';

    public function handle(TenantContext $context): int
    {
        $term = trim((string) $this->argument('search'));
        $digits = preg_replace('/\D+/', '', $term) ?? '';
        $tenants = $context->bypass(fn () => Tenant::query()
            ->when($this->option('workspace'), fn ($q, $w) => $q->where('name', 'ilike', '%'.$w.'%'))->get());
        $found = 0;

        foreach ($tenants as $tenant) {
            $context->run($tenant, function () use ($tenant, $term, $digits, &$found): void {
                $contacts = Contact::query()->where(fn ($w) => $w->where('name', 'ilike', "%{$term}%")->orWhere('profile_name', 'ilike', "%{$term}%")
                    ->when(strlen($digits) >= 5, fn ($w) => $w->orWhere('wa_id', 'like', "%{$digits}%")))->limit(10)->get();
                if ($contacts->isEmpty()) {
                    return;
                }
                $tz = $tenant->timezone ?: 'UTC';
                $t = fn ($v) => $v === null ? '—' : Carbon::parse($v)->setTimezone($tz)->format('d M H:i');
                $s = FollowUps::settings($tenant);
                $can = FollowUps::allowed($tenant);

                $this->newLine();
                $this->line("<options=bold>Workspace: {$tenant->name}</> (times in {$tz})");
                $this->line(sprintf('  Rules: remind agent %s after %d min · window alert %s %d min before close · escalation %s · plan includes team alerts: %s',
                    $s['reply_alert']['enabled'] ? 'ON' : 'off', $s['reply_alert']['minutes'],
                    $s['window_alert']['enabled'] ? 'ON' : 'off', $s['window_alert']['minutes_before'],
                    $s['escalation']['enabled'] ? 'ON' : 'off', $can['team'] ? 'yes' : 'NO (business hours not in plan: no team alerts)'));

                foreach ($contacts as $contact) {
                    foreach (Conversation::query()->where('contact_id', $contact->id)->orderByDesc('last_message_at')->limit(3)->get() as $c) {
                        $found++;
                        $this->newLine();
                        $this->line("<options=bold>{$contact->displayName()}</> {$contact->wa_id} · chat {$c->id}");
                        $this->line(sprintf('  status %s · reply state %s · needs reply since %s · last customer message %s · window closes %s · assigned %s · snoozed until %s',
                            $c->status->value, $c->getAttribute('reply_state') ?? '—', $t($c->getAttribute('needs_reply_since')), $t($c->last_inbound_at),
                            $t($c->window_expires_at), $c->assigned_membership_id ?? 'nobody', $t($c->getAttribute('snoozed_until'))));

                        $actions = DB::table('scheduled_actions')->where('conversation_id', $c->id)->orderBy('created_at')->get();
                        $this->line('  Scheduled follow-ups:'.($actions->isEmpty() ? ' none' : ''));
                        foreach ($actions as $a) {
                            $this->line(sprintf('    %-18s due %s · %-9s %s · decided %s', $a->kind, $t($a->due_at), $a->status, $a->result ?? '', $t($a->decided_at)));
                        }
                        $notes = DB::table('member_notifications as n')->join('tenant_memberships as m', 'm.id', '=', 'n.membership_id')
                            ->join('users as u', 'u.id', '=', 'm.user_id')->where('n.url', 'like', "%{$c->id}%")
                            ->orderBy('n.created_at')->get(['n.type', 'n.title', 'n.created_at', 'n.read_at', 'u.name']);
                        $this->line('  Notifications:'.($notes->isEmpty() ? ' none' : ''));
                        foreach ($notes as $n) {
                            $this->line(sprintf('    %s  %-15s to %s: %s%s', $t($n->created_at), $n->type, $n->name, $n->title, $n->read_at ? ' (read)' : ''));
                        }
                    }
                }
            });
        }

        if ($found === 0) {
            $this->components->warn("No chats found for \"{$term}\".");
        }

        return self::SUCCESS;
    }
}
