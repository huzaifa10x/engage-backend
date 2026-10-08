<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Models\SalesLead;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Super Admin → Inquiries: demo and contact requests sent from the public website. */
final class InquiryController extends Controller
{
    private const TOPICS = ['demo', 'contact', 'enterprise', 'trial'];

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('inquiries/Index', [
            'inquiries' => $this->query($filters)->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString()
                ->through(fn (SalesLead $lead) => [
                    'id' => $lead->id, 'name' => $lead->name, 'email' => $lead->email, 'company' => $lead->company, 'phone' => $lead->phone,
                    'team_size' => $lead->team_size, 'topic' => $lead->topic, 'message' => $lead->message, 'source' => $lead->source,
                    'status' => $lead->status, 'admin_note' => $lead->admin_note,
                    'created_at' => $lead->created_at?->toIso8601String(), 'handled_at' => $lead->handled_at?->toIso8601String(),
                ]),
            'filters' => $filters,
            'counts' => [
                'new' => SalesLead::query()->where('status', 'new')->count(),
                'spam' => SalesLead::query()->where('status', 'spam')->count(),
                'contacted' => SalesLead::query()->where('status', 'contacted')->count(),
                'closed' => SalesLead::query()->where('status', 'closed')->count(),
                'last_7_days' => SalesLead::query()->where('created_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }

    public function update(Request $request, SalesLead $inquiry, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', SalesLead::STATUSES)],
            'admin_note' => ['nullable', 'string', 'max:4000'],
        ]);
        $before = $inquiry->only(['status', 'admin_note']);

        $inquiry->fill($data);
        if ($inquiry->isDirty('status')) {
            $handled = $data['status'] !== 'new';
            $inquiry->forceFill(['handled_at' => $handled ? now() : null, 'handled_by_admin_id' => $handled ? $request->user('admin')?->getKey() : null]);
        }
        $inquiry->save();
        $audit->record('inquiry.updated', null, before: $before, after: $data, meta: ['inquiry_id' => $inquiry->id, 'email' => $inquiry->email]);

        return back()->with('success', 'Inquiry updated.');
    }

    /** The filtered list as a spreadsheet. Cells are guarded against formula injection. */
    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        $filters = $this->filters($request);
        $audit->record('inquiries.exported', null, meta: $filters);
        $safe = fn (?string $v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'".$v : (string) $v;

        return response()->streamDownload(function () use ($filters, $safe): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Received', 'Name', 'Email', 'Company', 'Phone', 'Numbers / clients', 'Type', 'Status', 'Sent from', 'Message', 'Internal note']);
            $this->query($filters)->orderByDesc('created_at')->chunk(500, function ($leads) use ($out, $safe): void {
                foreach ($leads as $lead) {
                    fputcsv($out, array_map($safe, [
                        (string) $lead->created_at?->toDateTimeString(), $lead->name, $lead->email, $lead->company, $lead->phone, $lead->team_size,
                        $lead->topic, $lead->status, $lead->source, $lead->message, $lead->admin_note,
                    ]));
                }
            });
            fclose($out);
        }, 'inquiries-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{q: string, status: string, topic: string} */
    private function filters(Request $request): array
    {
        $status = $request->string('status')->toString();
        $topic = $request->string('topic')->toString();

        return [
            'q' => mb_substr(trim($request->string('q')->toString()), 0, 100),
            'status' => in_array($status, SalesLead::STATUSES, true) ? $status : '',
            'topic' => in_array($topic, self::TOPICS, true) ? $topic : '',
        ];
    }

    /**
     * @param  array{q: string, status: string, topic: string}  $filters
     * @return Builder<SalesLead>
     */
    private function query(array $filters): Builder
    {
        return SalesLead::query()
            // Caught-by-the-bot-trap entries stay out of the way unless that status is chosen.
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']), fn ($q) => $q->where('status', '!=', 'spam'))
            ->when($filters['topic'] !== '', fn ($q) => $q->where('topic', $filters['topic']))
            ->when($filters['q'] !== '', function ($q) use ($filters): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
                $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('company', 'ilike', $like));
            });
    }
}
