<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Data deletion status · 10X Engage</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #F5F6F8; color: #0F172A; }
        main { max-width: 520px; margin: 64px auto; padding: 0 20px; }
        .card { background: #fff; border: 1px solid #E5E8EF; border-radius: 10px; padding: 28px; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { color: #334155; line-height: 1.55; margin: 8px 0; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; margin: 18px 0 0; font-size: 14px; }
        dt { color: #64748B; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .status { font-weight: 600; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>Data deletion request</h1>
        @if ($deletion)
            <p>
                @if ($deletion->status === 'completed')
                    Your request has been completed. 10X Engage no longer holds the Facebook account data linked to this request.
                @else
                    We received your request and are processing it. This usually finishes within a few minutes.
                @endif
            </p>
            <dl>
                <dt>Confirmation code</dt><dd><code>{{ $deletion->confirmation_code }}</code></dd>
                <dt>Status</dt><dd class="status">{{ ucfirst($deletion->status) }}</dd>
                <dt>Requested</dt><dd>{{ $deletion->requested_at->toDayDateTimeString() }} UTC</dd>
                @if ($deletion->completed_at)
                    <dt>Completed</dt><dd>{{ $deletion->completed_at->toDayDateTimeString() }} UTC</dd>
                @endif
            </dl>
        @else
            <p>We could not find a request with the code <code>{{ $code ?: '—' }}</code>. Check the link Facebook gave you, or contact support@10xdigital.ae.</p>
        @endif
    </div>
</main>
</body>
</html>
