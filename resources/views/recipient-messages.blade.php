{{--
    A recipient's read-only page of a form's messages. Usually the agency's client reads it, so, like
    their emails, it carries the owner's sender name and none of our branding. Plain inline CSS in the
    palette of the recipient pages (components/visitor-page.blade.php): no app bundle, no cookies.
    The answers use the same partial as the notification email, so they read the same in both places.
--}}
@php
    $days = $form->retention_days.' '.\Illuminate\Support\Str::plural('day', $form->retention_days);
    $plural = fn (int $n) => number_format($n).' '.($n === 1 ? 'message' : 'messages');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Messages: {{ $form->name }}</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #fafafa; color: #171717; padding: 40px 16px 48px; box-sizing: border-box; }
        .page { width: 100%; max-width: 42rem; margin: 0 auto; display: flex; flex-direction: column; gap: 16px; }
        .card { box-sizing: border-box; background: #fff; border: 1px solid #e5e5e5; border-radius: 16px; padding: 24px; }
        h1 { margin: 0 0 6px; font-size: 24px; line-height: 1.3; font-weight: 600; overflow-wrap: anywhere; }
        h2 { margin: 0 0 4px; font-size: 17px; line-height: 1.4; font-weight: 600; overflow-wrap: anywhere; }
        p { margin: 0; color: #404040; line-height: 1.6; }
        .muted { font-size: 14px; color: #525252; overflow-wrap: anywhere; }
        .counts { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 18px; }
        .count { flex: 1 1 10rem; border: 1px solid #e5e5e5; border-radius: 12px; padding: 12px 14px; }
        .count b { display: block; font-size: 24px; font-weight: 600; color: #171717; font-variant-numeric: tabular-nums; }
        .pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .pager a { background: #171717; color: #fff; padding: 11px 18px; border-radius: 10px; font-weight: 500; text-decoration: none; }
        .note { font-size: 13px; line-height: 1.5; color: #737373; text-align: center; }
    </style>
</head>
<body>
    <div class="page">
        <header class="card">
            <h1>{{ $form->name }}</h1>
            <p class="muted">Messages sent through this form, newest first. {{ $maker }} shares this page with {{ $recipient->email }}.</p>
            <div class="counts">
                @foreach ($counts as $month)
                    <div class="count">
                        <b>{{ number_format($month['count']) }}</b>
                        <span class="muted">{{ $month['label'] }}{{ $month['partial'] ? ' (messages are kept '.$days.')' : '' }}</span>
                    </div>
                @endforeach
            </div>
        </header>

        @if ($messages === null)
            <section class="card">
                <h2>Messages are not kept for this form</h2>
                <p>Each message is emailed, then its answers are deleted, so they cannot be shown here. The counts above still show how many came in.</p>
            </section>
        @elseif ($messages->isEmpty() && $messages->total() > 0)
            <section class="card">
                <h2>No messages on this page</h2>
                <p><a href="{{ $messages->url(1) }}" style="color: inherit;">Go to the newest messages</a></p>
            </section>
        @elseif ($messages->isEmpty())
            <section class="card">
                @if ($expired)
                    <h2>No messages from the last {{ $days }}</h2>
                    <p>Messages are deleted after {{ $days }}, so older ones are no longer here.</p>
                @elseif ($form->messages_total > 0)
                    <h2>No messages stored right now</h2>
                    <p>New messages show here as they come in.</p>
                @else
                    <h2>No messages yet</h2>
                    <p>New messages show here as they come in.</p>
                @endif
            </section>
        @else
            @foreach ($messages as $submission)
                <article class="card">
                    @if ($submission->isForgotten())
                        <h2>Answers not kept</h2>
                        <p class="muted"><time datetime="{{ $submission->created_at->toIso8601String() }}">{{ $submission->created_at->format('j M Y, H:i') }} UTC</time></p>
                        <p style="margin-top: 10px;">The form was set to keep no answers when this message came in.</p>
                    @else
                        @php($from = $submission->senderLabel())
                        <h2>{{ $from ? 'From '.$from : 'Message' }}</h2>
                        <p class="muted" style="margin-bottom: 8px;"><time datetime="{{ $submission->created_at->toIso8601String() }}">{{ $submission->created_at->format('j M Y, H:i') }} UTC</time>@if ($submission->sourcePage()) | {{ $submission->sourcePage() }}@endif</p>
                        @include('mail.partials.fields', ['fields' => $submission->mailAnswers()])
                        @if ($submission->replyToAddress())
                            <p class="muted" style="margin-top: -8px;">Reply to <a href="mailto:{{ $submission->replyToAddress() }}" style="color: inherit;">{{ $submission->replyToAddress() }}</a></p>
                        @endif
                    @endif
                </article>
            @endforeach

            @if ($messages->hasPages())
                <nav class="pager" aria-label="Pages">
                    <span>@if ($messages->previousPageUrl())<a href="{{ $messages->previousPageUrl() }}" rel="prev">Newer</a>@endif</span>
                    <span class="muted">Page {{ $messages->currentPage() }} of {{ $messages->lastPage() }}</span>
                    <span>@if ($messages->nextPageUrl())<a href="{{ $messages->nextPageUrl() }}" rel="next">Older</a>@endif</span>
                </nav>
            @endif
        @endif

        <p class="note">
            @if ($form->store_submissions)
                {{ \App\Support\Operator::storedInEu() ? 'Stored in the EU, deleted' : 'Deleted' }} after {{ $days }}.
            @else
                Not kept: the answers are deleted once the email is sent.
            @endif
        </p>
    </div>
    <script>
        // Show each time in the reader's own time zone; without JavaScript the UTC time stays.
        document.querySelectorAll('time[datetime]').forEach(function (el) {
            var d = new Date(el.getAttribute('datetime'));
            if (!isNaN(d)) el.textContent = d.toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        });
    </script>
</body>
</html>
