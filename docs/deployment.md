# Deployment

Two deployables:

* **Frontend**: static files from `frontend/dist`, served by the web server.
* **Backend**: PHP 8.2+ API (`backend/public/index.php`) behind PHP-FPM, plus a long-running **worker** and a **cron** scheduler.

The recommended layout serves both from one HTTPS origin: the web server serves the SPA and proxies `/api/v1`, `/sitemap.xml`, `/robots.txt` and `/media` to PHP. The admin session cookie then stays first-party, and the frontend needs no `VITE_API_BASE`.

**Production (https://www.hansterahiansh.com) runs on BigRock cPanel shared hosting (Apache + PHP).** Follow [section 0](#0-production-on-cpanel). Sections 4 and 5 (Nginx, systemd) apply only to a VPS.

## 0. Production on cPanel

Nothing here needs Node.js on the server: the frontend is built locally into static files. The deploy kit lives in `deploy/`: `build-release.sh`, `cpanel/activate.sh`, `cpanel/rollback.sh`, `cpanel/env.production.template` and the `cpanel/public_html/` web-root files.

### Layout (account home directory)

```
~/public_html/                    web root: SPA build, .htaccess, api.php, .user.ini, media → shared/storage/public
~/advisory/releases/<name>/       one directory per release (backend + public_html + deploy scripts)
~/advisory/current                symlink to the live release
~/advisory/shared/.env.production secrets, chmod 600 (never under public_html)
~/advisory/shared/storage/        logs, uploads, private audio: kept across releases
~/advisory/backups/               database dump and web-root archive taken before every activation
```

`public_html/.htaccess` forces HTTPS on `www.hansterahiansh.com`, sends `/api/*`, `/sitemap.xml` and `/robots.txt` to `api.php`, and serves everything else as the SPA. It also sets the security headers (CSP allowing Razorpay Checkout), blocks dotfiles, source maps and executable files under `/media`, and leaves `/.well-known/` reachable for AutoSSL. `api.php` sets `APP_ENV=production` and `ENV_PATH=~/advisory/shared`, then loads `~/advisory/current/backend/public/index.php`.

### The production server (BigRock, confirmed 7 Oct 2026)

| Item | Value |
| --- | --- |
| SSH | `hanstebd@sh00020.bigrock.com` (IP `66.116.229.124`), port 22, key authentication only. Host key ED25519 `SHA256:TLlxkQ54o4w8+9CKifyBlxxJU169oNa8+2B6RaIS/DM` |
| Home directory | `/home2/hanstebd` (not `/home`) |
| PHP | the domain runs `ea-php84` (LSAPI); the default `php` CLI is 8.3. `activate.sh` reads the version from cPanel's handler block in `.htaccess` and uses `/usr/local/bin/ea-php84`, and the cron jobs call it explicitly |
| Database | Percona Server 8.0.46. Database `hanstebd_hansterahiansh`; app user `hanstebd_app` (SELECT, INSERT, UPDATE, DELETE), migration user `hanstebd_mig` (ALL on that database). The older user `hanstebd_hansterahiansh` is not used |
| Mail | `noreply@hansterahiansh.com` sends (SMTP on `mail.hansterahiansh.com:465`); `MAIL_REPLY_TO` and `MAIL_ADMIN_ADDRESS` are `info@hansterahiansh.com` |
| SSL | Let's Encrypt for `hansterahiansh.com` and `*.hansterahiansh.com` (AutoSSL renews it) |
| Web root | `public_html` must stay readable by Apache: owner `hanstebd`, group `nobody`, mode 750 (cPanel's default, which cPanel restores itself). If the group is ever lost, mode 755 is the fallback the account can set; deploys no longer change the group. `public_html/test_user` is an FTP account's home and is never touched by a deploy; the cPanel PHP handler block in `.htaccess` is kept on every publish |

### Server requirements to confirm in cPanel (once)

| Item | Where | Needed |
| --- | --- | --- |
| PHP version for the domain | MultiPHP Manager | 8.2 or newer, with `openssl`, `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `curl` (PHP Extensions / `php -m`). The handler must run PHP as the account user (PHP-FPM, suPHP or LSAPI) |
| PHP CLI for cron and SSH | Terminal: `php -v`, or `/opt/cpanel/ea-php82/root/usr/bin/php` | same version as the domain; set `PHP_BIN` if `php` differs |
| Database server | phpMyAdmin home page, or `deploy:check` | **MySQL 8.0 or newer**. The schema uses `utf8mb4_0900_ai_ci` and `SKIP LOCKED`; MariaDB and MySQL 5.7 are not supported |
| SSL | SSL/TLS Status → Run AutoSSL | valid certificate for `hansterahiansh.com` and `www.hansterahiansh.com` |
| Shell access | SSH Access / Terminal | needed to run `activate.sh` and to create the first admin |
| Cron | Cron Jobs | every-minute jobs allowed |

### One-time setup

1. **SSL:** run AutoSSL for `hansterahiansh.com` and `www`, and wait until both certificates are valid.
2. **Database:** cPanel → MySQL Databases. Create the database (e.g. `<user>_advisory`) and two users: an application user with SELECT, INSERT, UPDATE and DELETE, and a migration user with ALL PRIVILEGES on that database only. Generate both passwords with cPanel's generator.
3. **Mailbox:** `info@hansterahiansh.com` (cPanel → Email Accounts). Its password goes into `MAIL_PASSWORD`.
4. **Environment file:** upload `deploy/cpanel/env.production.template` to `~/advisory/shared/.env.production` and run `chmod 600` on it. Fill in every blank value on the server itself, typing or pasting each secret from its source (cPanel, Razorpay Dashboard, Google Cloud Console). Generate the application keys there with `php bin/console keys:generate` (from an extracted release's `backend/`), and keep a copy of the keys in a password manager.
5. **Razorpay (Live mode):** Razorpay creates live keys only after it has approved the website, so go live in two phases:
   * **Phase 1:** deploy with `PAYMENTS_ENABLED=false` and the Razorpay values empty. The site, private requests and free bookings work; paid sessions and gatherings say online payment is unavailable. Then submit `https://www.hansterahiansh.com` under Account & Settings → Websites & API keys, once the pricing, contact and policy pages are published.
   * **Phase 2 (after approval):** generate live keys, then create a webhook at `https://www.hansterahiansh.com/api/v1/payments/razorpay/webhook` with a new secret and the events `payment.captured`, `payment.failed`, `order.paid`, `refund.processed` and `refund.failed`. Set `PAYMENTS_ENABLED=true`, fill in the three Razorpay values, and run `activate.sh` again (or just `deploy:check` and `payments:check`).
6. **Google:** add the production redirect URI `https://www.hansterahiansh.com/api/v1/admin/integrations/google/callback` to the OAuth client, and publish the consent screen ([integrations.md](integrations.md#2-google-workspace-gmail-calendar-and-meet)).

### Each release

```bash
# Local machine, from a clean, committed working tree
deploy/build-release.sh          # runs the tests, lint, typecheck and production build, scans for secrets,
                                 # and writes build/advisory-<stamp>-<commit>.tar.gz plus a .sha256 file

# Upload the archive to ~/advisory/incoming/ (scp/rsync over SSH, or File Manager), then on the server:
cd ~/advisory/incoming && sha256sum -c advisory-<…>.tar.gz.sha256 && tar -xzf advisory-<…>.tar.gz
advisory-<…>/deploy/activate.sh                       # preflight, backups; lists pending migrations and stops
CONFIRM_MIGRATIONS=1 ~/advisory/releases/advisory-<…>/deploy/activate.sh   # after reviewing them: migrate, seed, go live
```

`activate.sh` changes nothing until every check passes. In order, it:

* runs `deploy:check` (PHP, extensions, MySQL version, storage, every required variable by name) and `payments:check` (live Razorpay credentials);
* dumps the database and archives the current web root into `~/advisory/backups/`;
* shows any pending migrations and applies them only with `CONFIRM_MIGRATIONS=1`;
* runs the idempotent `db:seed`;
* switches the `current` symlink and publishes the web root, keeping `.well-known`, `cgi-bin` and `media`;
* calls `/api/v1/health` and the home page.

**First administrator** (once, on the server):

```bash
cd ~/advisory/current/backend && APP_ENV=production ENV_PATH=~/advisory/shared /usr/local/bin/ea-php84 bin/console admin:create --email=<owner email> --name="<name>"
```

The password is typed at the prompt and never shown. Sign in at `/admin` and enrol two-factor authentication.

### Cron (cPanel → Cron Jobs, every minute)

Shared hosting does not allow a supervised long-running worker, so the worker runs one pass a minute. Emails therefore leave within about a minute.

```
* * * * * cd /home2/hanstebd/advisory/current/backend && APP_ENV=production ENV_PATH=/home2/hanstebd/advisory/shared /usr/local/bin/ea-php84 bin/console schedule:run >> /home2/hanstebd/advisory/shared/storage/logs/cron.log 2>&1
* * * * * cd /home2/hanstebd/advisory/current/backend && APP_ENV=production ENV_PATH=/home2/hanstebd/advisory/shared /usr/local/bin/ea-php84 bin/console worker --once >> /home2/hanstebd/advisory/shared/storage/logs/cron.log 2>&1
```

These are installed on the production account (`crontab -l`).

Use the full PHP CLI path if `php` on the server is not the 8.2+ binary. Overlapping runs are safe: queued emails and jobs are claimed with row locks, each reminder is claimed before it is queued, and emails left in `sending` by a killed process are re-queued after 15 minutes.

### Rollback

* **Code and web root:** `~/advisory/current/deploy/rollback.sh` lists releases, and `rollback.sh <release>` switches to one. It preserves the logs, leaves the database alone and checks `/api/v1/health`. Migrations are additive, so the previous release runs on the newer schema.
* **First deployment** (no previous release): restore the archived web root with the command `activate.sh` prints (extract `~/advisory/backups/public_html-<stamp>.tar.gz` to a new directory, then `rsync -rlpt --delete` it over `~/public_html`).
* **Database:** restore `~/advisory/backups/db-<stamp>.sql.gz` only if a release damaged data, after taking a fresh dump of the current state. Never restore automatically: a restore discards every booking and payment made since the dump.

### Environment variables

Server-only. The frontend build has no secrets: `VITE_APP_ENV` and `VITE_SITE_URL` are public and set by `build-release.sh`, and the Razorpay key ID reaches the browser only inside API responses.

| Variable | Purpose | Required | Secret | Where it comes from |
| --- | --- | --- | --- | --- |
| `APP_ENV`, `APP_DEBUG` | `production`, `false` | yes | no | template (also forced by `api.php`) |
| `APP_URL`, `FRONTEND_URL`, `ADMIN_URL`, `CORS_ALLOWED_ORIGINS` | `https://www.hansterahiansh.com` (+`/admin`) | yes | no | template |
| `STORAGE_PATH` | `~/advisory/shared/storage` (absolute path) | yes | no | account home path |
| `PRACTICE_TIMEZONE` | `Asia/Kolkata` | yes | no | template |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` | application database user | yes | no | cPanel → MySQL Databases |
| `DB_PASSWORD` | application user password | yes | **yes** | cPanel → MySQL Databases |
| `DB_MIGRATION_USERNAME`, `DB_MIGRATION_PASSWORD` | DDL user for `migrate` | recommended | password **yes** | cPanel → MySQL Databases |
| `APP_KEY`, `BLIND_INDEX_KEY`, `ENCRYPTION_KEYS`, `ENCRYPTION_ACTIVE_KEY` | sessions, signed URLs, field encryption | yes | **yes** | `bin/console keys:generate` on the server |
| `SESSION_SECURE_COOKIE`, `ADMIN_REQUIRE_TWO_FACTOR` | `true` (enforced) | yes | no | template |
| `PAYMENTS_ENABLED` | `false` in phase 1, `true` once live keys exist | yes | no | template |
| `RAZORPAY_KEY_ID` | live key ID `rzp_live_…` (public identifier) | phase 2 | no | Razorpay Live → API keys |
| `RAZORPAY_KEY_SECRET` | live key secret | phase 2 | **yes** | Razorpay Live → API keys (shown once) |
| `RAZORPAY_WEBHOOK_SECRET` | live webhook signature secret | phase 2 | **yes** | the value set when creating the live webhook |
| `RAZORPAY_CURRENCIES` | `INR` (more only after International Payments approval) | yes | no | template |
| `STRIPE_*` | disabled: leave empty | no | — | — |
| `GOOGLE_CLIENT_ID` | OAuth web client | yes | no | Google Cloud → Credentials |
| `GOOGLE_CLIENT_SECRET` | OAuth web client secret | yes | **yes** | Google Cloud → Credentials |
| `GOOGLE_REDIRECT_URI` | `https://www.hansterahiansh.com/api/v1/admin/integrations/google/callback` | yes | no | template |
| `GOOGLE_CALENDAR_ID`, `GOOGLE_ACCOUNT_EMAIL` | `primary`, `hansterahiansh@gmail.com` | yes | no | template |
| `MAIL_DRIVER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME` | `smtp`, `mail.hansterahiansh.com`, `465`, `ssl`, `noreply@hansterahiansh.com` | yes | no | template |
| `MAIL_REPLY_TO` | `info@hansterahiansh.com`, so client replies reach a monitored mailbox | recommended | no | template |
| `MAIL_PASSWORD` | `info@` mailbox password | yes (smtp) | **yes** | cPanel → Email Accounts |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_ADMIN_ADDRESS` | sender and operations address | yes | no | template |

## 1. Environments

| | Staging | Production |
| --- | --- | --- |
| `APP_ENV` | `staging` | `production` |
| Database | its own database and users | its own database and users |
| Payment keys | test (`rzp_test_`, `sk_test_`) with test-mode webhooks | live (`rzp_live_`, `sk_live_`) with live webhooks |
| Google OAuth | separate OAuth client and calendar | production OAuth client and calendar |
| Indexing | `X-Robots-Tag: noindex` on every API response, `robots.txt` disallows all, frontend renders `noindex` | public routes indexable |
| Indicator | "STAGING" ribbon on site and admin | admin shows a "PRODUCTION" badge |

`app/Core/EnvironmentGuard.php` refuses to boot when the configuration doesn't fit the environment: live keys outside production; test keys, `APP_DEBUG=true` or a non-HTTPS URL in production. Never copy production data into staging, and never use live payment credentials in automated tests.

### Secrets

* Backend secrets live in `.env.staging` / `.env.production` **outside the web root** (mode `0600`, owned by the deploy user), or are injected by the platform's secret manager. `backend/.env.example` lists every variable.
* Set `APP_ENV` in the process environment of PHP-FPM, the worker and cron. When `APP_ENV` is injected, `.env.{APP_ENV}` is loaded from `ENV_PATH` (default: the backend root) and must declare the same `APP_ENV`.
* Generate keys once per environment with `php bin/console keys:generate` and store them in the secret manager. Losing `ENCRYPTION_KEYS` makes encrypted data unrecoverable; rotate with `keys:rotate`.
* Frontend `VITE_*` variables are compiled into public JavaScript and must never hold secrets (`frontend/.env.example`).

## 2. Server requirements

* PHP 8.2+ with `openssl`, `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `json`; `pcntl` is recommended for graceful worker shutdown.
* MySQL 8 (utf8mb4). Application user: `SELECT, INSERT, UPDATE, DELETE` only. A separate migration user (`DB_MIGRATION_USERNAME`) with DDL rights is used only by `bin/console migrate`.
* Composer 2, Node 20+ (build only), Nginx (or equivalent), TLS certificate.
* Optional, for prerendering: Chromium (`CHROME_PATH`).

## 3. Release steps

```bash
# Backend
cd backend
composer install --no-dev --optimize-autoloader
mysqldump --single-transaction "$DB_DATABASE" > "/backups/pre-release-$(date +%F-%H%M).sql"
APP_ENV=production php bin/console migrate          # uses DB_MIGRATION_* credentials
APP_ENV=production php bin/console db:seed          # idempotent; never overwrites edited content
APP_ENV=production php bin/console payments:check   # non-zero exit if a configured gateway fails

# Frontend (production mode by default; use --mode staging for staging)
cd ../frontend
npm ci
npm run build                 # or: npm run build:prerender (needs VITE_SITE_URL, PRERENDER_API_ORIGIN, CHROME_PATH)
rsync -a --delete dist/ /srv/advisory/frontend/

# Reload
sudo systemctl reload php8.2-fpm
sudo systemctl restart advisory-worker
```

`migrate:rollback` is refused in production; to undo a release, restore the pre-release backup.

### This release

* Migrations `2026_10_04_000007_booking_payment_lifecycle` and `2026_10_04_000008_refund_idempotency_key_length` must run. **000008 fixes a defect**: refund idempotency keys were longer than the `refunds.idempotency_key` column, so automatic and admin refunds failed to save. Deploy it before any refund is attempted.
* New admin settings with safe defaults: `payments.country_routing`, `booking.unpaid_expiry_hours`, `events.auto_promote_waitlist`, `events.waitlist_offer_hours`, `events.reminder_offsets_minutes`. Review them in Admin → Settings.
* Webhook URLs: `/api/v1/payments/stripe/webhook` and `/api/v1/payments/razorpay/webhook`. The earlier `/payments/webhook/{gateway}` paths still work.
* **Stripe defect fixed:** the Stripe client was created with an option that `stripe/stripe-php` v16 rejects, so every Stripe API call (checkout, verification, refunds, `payments:check`) would have failed. Any Stripe SDK error now surfaces as a "provider unavailable" response instead of a server error.
* Google Workspace:
  * Migration `2026_10_06_000009_integration_logs` must run, and `db:seed` adds three admin email templates (payment received, payment failed, new event registration).
  * The Google scopes now include `gmail.send`. An existing connection shows **Permission not granted** for Gmail until an admin reconnects.
  * `MAIL_DRIVER=gmail` is accepted in production. Optional: `GOOGLE_ACCOUNT_EMAIL`.
  * In production, `GOOGLE_REDIRECT_URI` must use HTTPS.
  * The OAuth callback now returns to `/admin/settings/integrations/google`.
* Production hardening for shared hosting (no new migrations):
  * `gmail.send` is requested only when `MAIL_DRIVER=gmail`.
  * Emails stuck in `sending` are re-queued by `schedule:run` after 15 minutes.
  * Appointment reminders are claimed atomically, so overlapping cron runs can't send one twice.
  * New read-only commands `deploy:check` and `migrate:status`.
  * A fresh database seeds `payments.routing` with Razorpay for every currency. Existing settings are not changed.

### First administrator

No administrator is seeded. Create the first one interactively on the server:

```bash
APP_ENV=production php bin/console admin:create --email=owner@example.com --name="Owner" --password-stdin
```

Two-factor enrolment is mandatory before the admin can be used outside local development.

## 4. Nginx

Example for a single origin. Adjust paths, server name and PHP-FPM socket.

```nginx
# /etc/nginx/snippets/advisory-headers.conf
add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(self \"https://checkout.razorpay.com\" \"https://api.razorpay.com\")" always;
add_header Cross-Origin-Opener-Policy "same-origin-allow-popups" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self' https://checkout.razorpay.com https://www.googletagmanager.com; frame-src https://api.razorpay.com https://checkout.razorpay.com; connect-src 'self' https://api.razorpay.com https://lumberjack.razorpay.com https://www.google-analytics.com https://*.google-analytics.com https://www.googletagmanager.com; img-src 'self' data: https:; media-src 'self' blob:; style-src 'self' 'unsafe-inline'; font-src 'self'; form-action 'self' https://checkout.stripe.com; object-src 'none'; base-uri 'self'; frame-ancestors 'none'" always;
```

```nginx
server {
    listen 443 ssl http2;
    server_name example.com;
    # ssl_certificate / ssl_certificate_key …

    root /srv/advisory/frontend;
    include snippets/advisory-headers.conf;
    client_max_body_size 64m;          # audio uploads in the admin

    # API, sitemap and robots → PHP (the API sets its own security headers)
    location ~ ^/(api/|sitemap\.xml$|robots\.txt$) {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /srv/advisory/backend/public/index.php;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_read_timeout 60s;
    }

    # Public media uploaded through the admin (private audio is never served from here)
    location /media/ {
        alias /srv/advisory/backend/storage/public/;
        include snippets/advisory-headers.conf;
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files $uri =404;
    }

    # Fingerprinted build assets
    location /assets/ {
        include snippets/advisory-headers.conf;
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files $uri =404;
    }

    # Admin: never indexed, never prerendered
    location /admin {
        include snippets/advisory-headers.conf;
        add_header X-Robots-Tag "noindex, nofollow" always;
        add_header Cache-Control "no-store";
        try_files /app-shell.html /index.html =404;
    }

    # Prerendered page if one exists, otherwise the SPA shell
    location / {
        add_header Cache-Control "no-cache";
        include snippets/advisory-headers.conf;
        try_files $uri $uri/index.html /app-shell.html /index.html =404;
    }

    # Never serve dotfiles or source maps
    location ~ /\.(?!well-known) { deny all; }
    location ~ \.map$ { deny all; }
}

server {
    listen 80;
    server_name example.com;
    return 301 https://$host$request_uri;
}
```

Notes:

* Nginx drops inherited `add_header` directives inside any `location` that sets its own, which is why each location re-includes the snippet.
* `app-shell.html` exists only after `npm run build:prerender`; without prerendering, `index.html` is the shell.
* `same-origin-allow-popups` lets Razorpay open bank or 3-D Secure windows. Stripe Checkout is a full-page redirect.
* Check the CSP on staging with a real test-mode Razorpay checkout and adjust it to Razorpay's current guidance if the browser console reports blocked resources. Remove the Google Analytics hosts if `VITE_GA_MEASUREMENT_ID` is not used.
* On staging, also add `add_header X-Robots-Tag "noindex, nofollow" always;` to the snippet, and protect the host with HTTP basic auth or an IP allowlist if it shouldn't be public. Exempt the payment webhook paths and the Google OAuth callback, which the providers call directly. (Staging builds already render `noindex` on every page.)
* PHP-FPM pool: set `env[APP_ENV] = production` (and `env[ENV_PATH] = /etc/advisory` if the env file lives there), and keep `backend/` outside `root`.
* Set `TRUSTED_PROXIES` when a load balancer or CDN sits in front, so rate limits see the real client IP.

## 5. Worker and scheduler

The worker sends queued email, runs jobs (calendar sync, automatic refunds, rebuild hook) and sends appointment reminders. It exits on its own every hour, and immediately if the database connection drops, so it must run under a supervisor that restarts it.

```ini
# /etc/systemd/system/advisory-worker.service
[Unit]
Description=Private Advisory worker
After=network-online.target mysql.service

[Service]
User=advisory
WorkingDirectory=/srv/advisory/backend
Environment=APP_ENV=production
ExecStart=/usr/bin/php bin/console worker --sleep=5
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
```

The scheduler must run **every minute**. Among other things, it releases expired holds, reconciles pending payments with the gateways, expires unpaid reservations and holds, sends event reminders, and applies retention rules.

```cron
* * * * * cd /srv/advisory/backend && APP_ENV=production /usr/bin/php bin/console schedule:run >> /var/log/advisory/schedule.log 2>&1
```

If either one stops, payments still confirm (through webhooks and browser verification), but emails, calendar events, refunds, reminders and expiries are delayed. Monitor both.

### Prerender rebuild hook

With prerendering, published content changes only reach the static HTML on the next build. Set `FRONTEND_REBUILD_HOOK_URL` to a CI or deploy endpoint that runs `npm run build:prerender` and publishes `dist/`. The worker POSTs `{"reason":"content_updated"}` to it after admin content edits. If it is unset, snapshots refresh on the next deploy; the live app always fetches current content in the meantime.

## 6. Backups and monitoring

* Nightly `mysqldump --single-transaction`, encrypted, kept off-server. Back up `storage/private` (audio) and `storage/public` (media) as well.
* Restore-test a backup on staging regularly, with production data excluded or anonymised.
* Watch `storage/logs/` (structured, no secrets or confidential content), the admin Integrations page (gateway, Google and email health, failed jobs), and `payments:check` after any credential change.

## 7. Go-live checklist

- [ ] Production secrets injected; `APP_DEBUG=false`; HTTPS URLs for `APP_URL` and `FRONTEND_URL`.
- [ ] Migrations applied (including 000008 and 000009); `db:seed` run; first admin created with 2FA.
- [ ] Razorpay and Stripe live keys, webhook secrets and `*_CURRENCIES` set; webhooks registered; `payments:check` passes ([integrations.md](integrations.md#go-live-prerequisites)).
- [ ] The full payment checklist completed on staging in test mode.
- [ ] Google OAuth app published (homepage and privacy policy on the production domain; verification for `calendar.events` and `gmail.send` if Google asks); production redirect URI registered; Google connected in Admin → Integrations → Google Workspace; Test Gmail, Test Calendar and Test Meet pass.
- [ ] SMTP configured; test email received.
- [ ] Worker running under systemd; cron running every minute.
- [ ] Practitioner biography, qualifications and legal pages entered in the admin and reviewed by a qualified person.
- [ ] Backups scheduled and one restore tested.
