# Deployment

Two deployables:

* **Frontend**: static files from `frontend/dist`, served by the web server.
* **Backend**: PHP 8.2+ API (`backend/public/index.php`) behind PHP-FPM, plus a long-running **worker** and a **cron** scheduler.

The recommended layout serves both from one HTTPS origin: the web server serves the SPA and proxies `/api/v1`, `/sitemap.xml`, `/robots.txt` and `/media` to PHP. The admin session cookie then stays first-party, and the frontend needs no `VITE_API_BASE`.

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
- [ ] Migrations applied (including 000008); `db:seed` run; first admin created with 2FA.
- [ ] Razorpay and Stripe live keys, webhook secrets and `*_CURRENCIES` set; webhooks registered; `payments:check` passes ([integrations.md](integrations.md#go-live-prerequisites)).
- [ ] The full payment checklist completed on staging in test mode.
- [ ] Google OAuth client verified for the calendar scope; calendar connected in Admin → Integrations.
- [ ] SMTP configured; test email received.
- [ ] Worker running under systemd; cron running every minute.
- [ ] Practitioner biography, qualifications and legal pages entered in the admin and reviewed by a qualified person.
- [ ] Backups scheduled and one restore tested.
