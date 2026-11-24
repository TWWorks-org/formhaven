{{--
    Self-hosted Umami, configured in config/services.php (App\Support\Umami).
    With no website id or script URL, nothing below renders.
    Page views, load times (data-performance) and JavaScript errors (data-errors):
    no session recorder and no account ID. Signups are sent by the server
    (App\Support\Umami), because they finish after the visitor is signed in.
    Included in the public and sign-in layouts only. data-domains keeps local
    and staging hosts silent; data-do-not-track honours the browser's Do Not Track.

    Three guards, so nothing about a customer reaches the analytics database:
    - nothing loads while someone is signed in (the privacy policy says so);
    - pages whose URL carries a one-time value (a {token} or {hash} route
      parameter such as reset-password/{token}, or a signed URL) load no tracker;
    - everywhere else the query string is dropped before sending, except utm_*
      campaign tags (OAuth state, codes and emails never leave the browser).
      Error reports skip this hook: the tracker already cuts their URL to the
      path and scrubs emails and tokens from the message.
--}}
@php
    $umamiRoute = request()->route();
    $umamiScript = \App\Support\Umami::scriptUrl();
    $umamiSkip = $umamiScript === null
        || auth()->check()
        || request()->has('signature')
        || ($umamiRoute && array_intersect($umamiRoute->parameterNames(), ['token', 'hash']) !== []);
@endphp
@unless ($umamiSkip)
    <script>
        window.umamiBeforeSend = function (type, payload) {
            try {
                if (type !== 'error' && payload && payload.url) {
                    var u = new URL(payload.url, location.href);
                    var keep = new URLSearchParams();
                    u.searchParams.forEach(function (v, k) { if (k.indexOf('utm_') === 0) keep.append(k, v); });
                    u.search = keep.toString();
                    payload.url = u.toString();
                }
            } catch (e) {}
            return payload;
        };
    </script>
    <script defer src="{{ $umamiScript }}" data-website-id="{{ \App\Support\Umami::websiteId() }}" data-domains="{{ \App\Support\Umami::domain() }}" data-do-not-track="true" data-performance="true" data-errors="true" data-before-send="umamiBeforeSend"></script>
@endunless
