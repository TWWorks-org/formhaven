<?php

namespace App\Session;

use Illuminate\Session\DatabaseSessionHandler as Handler;

/**
 * Laravel's database sessions without the IP column filled in. Every visitor to the site gets a
 * session row, and none of the published texts lists an IP in a session: the privacy policy
 * names IPs only in server logs, the DPA names none for recipients. Before Laravel saw real IPs
 * (App\Http\Middleware\TrustProxies) this column only ever held Cloudflare's. Nothing reads it.
 */
class DatabaseSessionHandler extends Handler
{
    protected function ipAddress()
    {
        return null;
    }
}
