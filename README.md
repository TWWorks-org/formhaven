# FormHaven

FormHaven is a form backend. Point an HTML form at it: it stores each submission, filters spam and emails you. An AI coding agent can set it up end to end over the REST API or the MCP server.

There are two ways to use it:

- **Hosted:** sign up at [formhaven.net](https://formhaven.net) and skip everything below.
- **Self-hosted:** run this code on your own server. The rest of this file is for you.

## What you get

- A form address per form. A plain HTML form posts to it, or JavaScript posts JSON and gets JSON back.
- Each submission stored in your own SQLite database and emailed to you. You reply straight to the visitor when the form has an `email` field.
- Spam filtering: a hidden `_gotcha` honeypot field, your own list of spam words, and an optional proof-of-work bot check (one script line on the page, no third-party captcha).
- Several recipients per form. Each one confirms by a link before they get anything.
- An outgoing webhook per form (HTTPS), retried when the receiver is down.
- CSV export of a form's submissions.
- Retention per form: keep submissions 1 to 365 days, or keep none and only email them.
- The paragraph your site's privacy policy needs, in English, Dutch and German.
- Daily mail limits, overall and per form, so a flooded form cannot use up your SMTP quota. Mail over a limit is kept and can be sent again later.
- A REST API and an MCP server with the same settings as the dashboard, plus `POST /api/v1/start`, which lets an agent reserve a form that you then activate with one click.

## Run it yourself

### Requirements

- **PHP 8.4.1 or newer** (the locked Symfony 8 packages need it), with these extensions: bcmath, ctype, curl, dom, fileinfo, filter, mbstring, openssl, pdo_sqlite, session, tokenizer, xml and xmlwriter. After step 2, `composer check-platform-reqs` lists any that are missing.
- **Composer 2.**
- **SQLite 3.** The app needs only PHP's pdo_sqlite extension. The `sqlite3` command line tool is useful for backups.
- **Node.js 20 or newer and npm**, only to build the CSS and JavaScript once per update. Tested with Node 22.
- **A process that keeps the queue worker running**, such as systemd. Every email goes through the queue.
- **Cron**, for the scheduler.
- **A mail account that can send over SMTP.** Any provider works.
- **A web server with PHP-FPM**, such as Caddy or nginx, and a domain with HTTPS.

### Install

These steps assume Linux, a folder at `/var/www/formhaven` and PHP-FPM running as `www-data`. Change those to match your server.

1. Get the code:

   ```bash
   git clone https://github.com/TWWorks-org/formhaven.git /var/www/formhaven
   cd /var/www/formhaven
   ```

2. Install the PHP packages:

   ```bash
   composer install --no-dev --optimize-autoloader
   composer check-platform-reqs --no-dev
   ```

3. Create your settings file and its encryption key:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. Open `.env` and set at least these lines:

   ```ini
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://forms.example.com

   MAIL_MAILER=smtp
   MAIL_HOST=smtp.example.com
   MAIL_PORT=587
   MAIL_USERNAME=you@example.com
   MAIL_PASSWORD=your-smtp-password
   MAIL_FROM_ADDRESS=forms@example.com
   ```

   - `APP_URL` is the address people open, with `https://`. Every link in an email is built from it. Pages build their links from the address the browser asked for, so let your web server answer only on this domain (the Caddy example in step 11 does).
   - `.env.example` ships with `MAIL_MAILER=log`. With that, or with wrong SMTP details, no mail goes out at all: not your submissions, and not the email that confirms your account, so you cannot get past the sign-up. With `log`, each email is written to `storage/logs/laravel.log` instead, which is handy for a first try.
   - `MAIL_FROM_ADDRESS` must be an address your SMTP account is allowed to send from.
   - Port 587 works with `MAIL_SCHEME` left at `null`. For port 465, set `MAIL_SCHEME=smtps`.

5. Create the database:

   ```bash
   touch database/database.sqlite
   php artisan migrate --force
   ```

6. Build the CSS and JavaScript:

   ```bash
   npm ci
   npm run build
   ```

7. Cache the settings and routes:

   ```bash
   php artisan optimize
   ```

   Run this again after every change to `.env`, then restart the queue worker. Until you do, the old settings stay in use.

8. Let PHP-FPM write to the folders it needs. SQLite writes a journal file next to the database, so the whole `database/` folder must be writable:

   ```bash
   sudo chown -R www-data:www-data storage bootstrap/cache database
   ```

9. Start the queue worker under systemd. Without a running worker no email is sent: submissions are saved, and their emails wait in the queue. Save this as `/etc/systemd/system/formhaven-queue.service`:

   ```ini
   [Unit]
   Description=FormHaven queue worker
   After=network.target

   [Service]
   User=www-data
   WorkingDirectory=/var/www/formhaven
   ExecStart=/usr/bin/php artisan queue:work --sleep=3 --max-time=3600
   Restart=always
   RestartSec=5

   [Install]
   WantedBy=multi-user.target
   ```

   Then:

   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable --now formhaven-queue
   ```

   Each kind of job sets its own retries, so the worker needs no `--tries`.

10. Add the scheduler to the crontab of the same user (`sudo crontab -u www-data -e`):

    ```
    * * * * * cd /var/www/formhaven && php artisan schedule:run >> /dev/null 2>&1
    ```

    It deletes submissions past their retention every night, sends the Monday summary email and clears expired keys. `GET /up/scheduler` answers 200 while it runs and 503 once it has missed ten minutes, so an uptime monitor can watch it. `GET /up` checks the app itself.

11. Point your web server at the `public/` folder and pass PHP requests to PHP-FPM. A minimal Caddy site:

    ```
    forms.example.com {
        root * /var/www/formhaven/public
        php_fastcgi unix//run/php/php8.4-fpm.sock
        file_server
        encode zstd gzip
    }
    ```

    Caddy gets the HTTPS certificate itself. `caddy/Caddyfile.example` is the hosted service's own file: it adds security headers and blocks paths such as `/.env`, which you can copy, but it also trusts Cloudflare's addresses, which you should leave out unless your site is behind Cloudflare. For nginx, follow [Laravel's nginx example](https://laravel.com/docs/12.x/deployment#nginx).

### First form

1. Open `APP_URL`. It sends you to the login page. Choose **Sign up** and create your account.
2. Open the confirmation email and press its link. You cannot reach the dashboard before that.
3. Registration is now closed: nobody else can make an account on your install. To let others sign up, set `ALLOW_REGISTRATION=true` in `.env` and run `php artisan optimize` again.
4. Create a form on the dashboard. Its **Setup** tab shows the HTML to paste into your site, with the form address already filled in. It looks like this:

   ```html
   <form action="https://forms.example.com/f/YOUR_FORM_ID" method="POST">
     <input type="email" name="email" required>
     <textarea name="message" required></textarea>
     <input type="text" name="_gotcha" style="display:none" tabindex="-1" autocomplete="off">
     <button type="submit">Send</button>
   </form>
   ```

5. Press **Send a test submission** on the form's page, or send the form from your site. The email arrives within seconds once the worker runs. If nothing arrives, check `storage/logs/laravel.log` and `sudo systemctl status formhaven-queue`.

### Try it on your own computer first

Do steps 1 to 6 with two changes: run `composer install` without `--no-dev` if you also want to run the tests, and in step 4 set only `APP_URL=http://localhost:8000`, leaving `MAIL_MAILER=log` as it is. Then run these in two terminals:

```bash
php artisan serve
php artisan queue:work
```

Open http://localhost:8000. The confirmation link and every submission email appear in `storage/logs/laravel.log`.

To run the tests: `php artisan test`.

### Production checklist

- `APP_ENV=production` and `APP_DEBUG=false`. With debug on, an error page shows your code and server details to anyone.
- HTTPS on `APP_URL`.
- The queue worker runs under systemd (or another supervisor) and starts again after a reboot.
- The cron line for `schedule:run` is in place, and `GET /up/scheduler` answers 200.
- Backups of `database/database.sqlite`. It holds every account, form and kept submission. Copy it with `sqlite3 database/database.sqlite ".backup '/path/to/copy.sqlite'"`, which is safe while the app runs. A good nightly job makes that `.backup` copy, runs `sqlite3 /path/to/copy.sqlite 'PRAGMA integrity_check;'` on the copy and keeps it only when the answer is `ok`, gzips it, keeps the newest 7 copies, and sends a copy off the server, so losing the server does not lose your data.
- Optional: put your own address in `OWNER_EMAILS` to get the **Admin** page with every account on the install.

### Updating

```bash
cd /var/www/formhaven
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci
npm run build
php artisan optimize
sudo systemctl restart formhaven-queue
```

Docker images are not provided.

## Settings

All settings live in `.env`. These are the ones you are likely to touch. After a change, run `php artisan optimize` and restart the queue worker.

| Variable | What it does | Default |
|---|---|---|
| `APP_URL` | The address of your install. Every link in an email is built from it. | `http://localhost` |
| `APP_ENV` | `production` on a live server. | `local` |
| `APP_DEBUG` | Shows error details in the browser. Keep it `false` on a live server. | `true` in `.env.example` |
| `APP_NAME` | The name in page titles and as the default sender name. | `FormHaven` |
| `FORMHAVEN_MODE` | `self_hosted` runs a private form backend with no billing or limits per account. `hosted` is what formhaven.net runs. | `self_hosted` |
| `ALLOW_REGISTRATION` | The first account can always be made. After that, others can sign up only when this is `true`. | `false` |
| `OWNER_EMAILS` | Comma-separated addresses that see the Admin page. They also get the notice when the daily mail limit for all forms is reached. When it is empty, that notice goes to the first confirmed account. | empty |
| `MAIL_MAILER` | `smtp` to send mail, `log` to write it to `storage/logs/laravel.log`. | `log` |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | Your SMTP account. | `127.0.0.1`, `2525`, none |
| `MAIL_SCHEME` | `smtps` for port 465. Leave it `null` for port 587. | `null` |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | The sender of every email. | `hello@example.com`, `APP_NAME` |
| `SUPPORT_EMAIL` | The contact address in `/.well-known/security.txt` and in advice about failed deliveries. | `MAIL_FROM_ADDRESS` |
| `MAIL_BUDGET_NOTIFICATIONS` | Submission emails a day across all forms (UTC day). Emails over it are kept and can be sent again. | `70` |
| `MAIL_BUDGET_PER_FORM` | Submission emails a day for one form. | `25` |
| `MAIL_BUDGET_SUMMARIES` | Weekly summary emails a day. The rest go on a later run. | `40` |
| `MAIL_BUDGET_NOTICES` | "A limit was reached" emails a day. | `6` |
| `SETUP_KEY_DAYS` | Days a setup key from `POST /api/v1/start` keeps working after you activate the form. | `30` |
| `WEBHOOK_BLOCK_APP_URL_IPS` | Refuses webhooks that point at your own server, found from `APP_URL`. Behind a proxy such as Cloudflare, set it to `false` and list the server's own addresses below. | `true` |
| `WEBHOOK_BLOCKED_IPS` | More IPs or CIDR ranges webhooks may not reach, comma-separated. | empty |
| `OAUTH_KNOWN_REDIRECT_HOSTS` | MCP clients that sign in with OAuth. Others still work, and their consent screen shows a warning. | `claude.ai` |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Shows "Continue with Google" on the login page. Redirect URI: `APP_URL/auth/google/callback`. | empty (button hidden) |
| `LEGAL_ENTITY`, `LEGAL_COUNTRY_EN`, `LEGAL_HOSTING_PROVIDER`, `LEGAL_HOSTING_PLACE_EN`, `LEGAL_MAILER` | Optional. Who runs the install, where it stores data ("Place, Country") and who sends its mail, as the privacy-policy paragraph should name them. `_NL` and `_DE` versions exist for the other two languages. Left empty, the paragraph says the site owner runs its own install. | empty |
| `DB_DATABASE` | Path to the SQLite file. | `database/database.sqlite` |
| `LOG_LEVEL` | How much goes into `storage/logs/laravel.log`. | `debug` |

The remaining variables in `.env.example` (Stripe prices, analytics, IndexNow and the operator's legal pages) belong to hosted mode, which is what formhaven.net runs. A self-hosted install can leave them empty.

## Know when your form stops sending mail

Each form can report to [DidMyFormSend](https://didmyformsend.com), a separate paid service by the same maker, which alerts you when the form's emails stop going out. It stays off until you paste a key from DidMyFormSend into the form's settings, and removing the key stops it. With a key saved, after each submission FormHaven sends didmyformsend.com whether its emails went out, the mail service's name, the failure kind if one failed, the time, an event id, and your notification addresses as their first letter and domain plus a SHA-256 hash. Nothing a visitor wrote is sent, and neither is the form's name or your site's address.

## For AI agents

On every install, relative to `APP_URL`:

- `/llms.txt`: how to set up and change forms, in plain text written for agents.
- `/api`: the REST API index (JSON). Send an API key from the dashboard's **API keys** page as `Authorization: Bearer KEY`.
- `POST /api/v1/start`: reserves a form with no account. The site owner activates it with one click. On a self-hosted install with registration closed, this needs an API key of the existing account.
- `/mcp`: the MCP server over HTTP. It takes the same API key, or OAuth sign-in for clients that support it. For Claude Code:

  ```bash
  claude mcp add --transport http formhaven https://forms.example.com/mcp --header "Authorization: Bearer YOUR_API_KEY"
  ```

## Security

To report a vulnerability, see [SECURITY.md](SECURITY.md).

## Contributing

FormHaven is open source and developed by its maker, so issues and pull requests are closed. You are free to use, change and fork it under the licence. If you found a bug or have a fix, send it as a security report or by email to hello@formhaven.net.

## Licence

FormHaven's own code is licensed under the GNU Affero General Public License, version 3 only (AGPL-3.0-only); the full text is in [LICENSE](LICENSE). If you run a changed version as a service that other people use over a network, you must offer those users the source code of your version. That source is then under the same licence.

The Composer and npm dependencies keep their own licences. One of them is not open source: the UI components come from the free `livewire/flux` package, which Composer installs from its publisher. It is not part of this repository, and it is under its publisher's own terms.

The icons in `resources/views/flux/icon/` are from [Lucide](https://lucide.dev), under the ISC licence.

"FormHaven" and the logo identify the maker's hosted service, so a fork or a public hosted copy should use its own name.
