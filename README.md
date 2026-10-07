# Private Advisory Platform

Public site, private access applications, paid consultations and gatherings, and an admin panel for a private executive advisory and consciousness practice.

| | |
| --- | --- |
| `frontend/` | React 19, TypeScript, Vite, Tailwind v4, Framer Motion, TanStack Query. Public site and `/admin` |
| `backend/` | Core PHP 8.2 MVC API (`/api/v1`), MySQL 8, worker and scheduler (`bin/console`) |
| `docs/` | [Architecture](docs/architecture.md) · [API](docs/api.md) · [Database](docs/database.md) · [Integrations and payments](docs/integrations.md) · [Security](docs/security.md) · [Deployment](docs/deployment.md) · [Design system](docs/design-system.md) |

## Local development

Prerequisites: PHP 8.2+ (extensions in `backend/composer.json`), Composer 2, MySQL 8, Node 20+.

```bash
# Backend
cd backend
composer install
cp .env.example .env                 # local only; fill in DB_* and paste the keys printed below
php bin/console keys:generate
php bin/console migrate --seed
php bin/console admin:create --email=you@example.com --name="Your Name"
composer serve                       # API on http://127.0.0.1:8080
php bin/console worker               # second terminal: email, jobs, reminders
php bin/console schedule:run         # run periodically (cron every minute in real environments)

# Frontend
cd frontend
npm ci
npm run dev                          # http://localhost:5173, proxies /api and /media to the API
```

Without payment credentials, paid appointment types and gatherings show as unavailable for online payment; everything else works. With `MAIL_DRIVER=log`, emails are written to `backend/storage/logs/mail.log`.

## Tests

```bash
cd backend
vendor/bin/phpunit --testsuite unit           # no database needed
vendor/bin/phpunit --testsuite integration    # MySQL; see below
vendor/bin/phpunit                            # both

cd frontend
npx tsc -b && npx oxlint src && npm run build
```

The integration suite runs the real services against MySQL with a test-only fake payment gateway (`backend/tests/Support/FakeGateway.php`). No gateway credentials are used. It covers:

* duplicate webhooks, idempotency keys, invalid signatures, amount and currency mismatches and reconciliation;
* failed payments, retries with the other gateway, duplicate captures refunded, late payments after expiry or after the slot was re-booked;
* event seat quotas, waitlists, multi-currency pricing with tax, registration windows, held and expired seats, cancellations with policy refunds, waitlist promotion, and cancelling a whole event;
* a six-process race for the last seat (exactly one wins).

To enable it, create a dedicated empty database whose name ends in `_test` and a user for it, then copy `backend/.env.testing.example` to `backend/.env.testing` and fill in the password. The suite drops and recreates every table in that database on each run, and refuses to run against any database whose name doesn't end in `_test`. Without `.env.testing` the integration tests are skipped.

## Status and next steps

Implemented and tested locally: applications, appointment booking with holds and expiry, events with quotas, waitlists, tax and refunds, the Razorpay and Stripe adapters with routing by currency and country, retries, reconciliation and refunds, the admin panel and reports.

Outstanding before the platform can be called fully functional:

1. **Payment credentials and accounts.** Razorpay is the only active gateway; Stripe is disabled because there is no Stripe account for India. Still needed: live Razorpay keys (after KYC and website approval), a live webhook, and International Payments approval before `RAZORPAY_CURRENCIES` lists anything other than INR. A complete Razorpay test-mode payment has not been confirmed yet. See [integrations.md](docs/integrations.md#go-live-prerequisites).
2. **Google Workspace (Gmail, Calendar, Meet).** The code is in place. Still needed: an OAuth client ID and secret in the server environment, the consent screen published (or the account added as a test user, which means reconnecting every 7 days), and an admin pressing **Connect Google** under Admin → Integrations → Google Workspace. Until then, Meet links and calendar invitations are not created and email uses the SMTP or log driver.
3. **Email.** SMTP credentials and a verified sending domain.
4. **Deployment.** Production is cPanel shared hosting at https://www.hansterahiansh.com. The release kit is in `deploy/`, and the procedure (SSL, database, secrets, cron, rollback) is in [deployment.md](docs/deployment.md#0-production-on-cpanel).
5. **Content.** The practitioner name "Hansmuniji" is an assumption. The biography and qualifications are empty and must be entered in the admin from verifiable sources. Legal pages (privacy, terms, cancellation and refund, cookies) need review by a qualified professional; nothing here claims legal or regulatory compliance.
6. **Images.** Several session photographs show a second person; confirm their consent before publishing.
7. **Demo data.** Local seed data, including event prices, consists of placeholders and must not be deployed as real offers.
