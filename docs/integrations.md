# Integrations — Payments, Google Workspace (Gmail, Calendar, Meet), Email

All integrations are real implementations against the vendors' documented REST APIs. They activate when credentials are provided; without credentials the admin "Integrations" screen shows them as **Not configured** and dependent features degrade safely (paid appointment types and paid gatherings cannot be reserved, and the public pages say so).

> **Status (production at www.hansterahiansh.com):** **Razorpay is the only active gateway.** Stripe is disabled because no Stripe account is available for India; leave every `STRIPE_*` variable empty. The Stripe adapter stays in the code: one sandbox payment (USD) was captured through its webhook locally and confirmed the booking.
>
> **Live since 7 Oct 2026:** live keys and the live webhook are configured on production (`PAYMENTS_ENABLED=true`, INR only). `payments:check` passes in live mode; a webhook signed with the server's secret is accepted and a forged one rejected. Not yet confirmed: a webhook delivered by Razorpay itself (proves the dashboard secret matches) and a completed payment. Both are covered by the first real payment, which needs the owner's approval. A Razorpay test-mode payment was never completed locally.

## 1. Payments

### Architecture

```
PaymentGateway (interface)                         app/Integrations/Payments
 ├─ createOrder(PaymentIntent): GatewayOrder        Razorpay Order / Stripe Checkout Session
 ├─ verifyClientPayload(array): VerifiedPayment     Razorpay signature / Stripe session lookup
 ├─ parseWebhook(rawBody, headers): WebhookEvent    throws InvalidSignature
 ├─ fetchByOrder(orderId): VerifiedPayment          reconciliation
 ├─ refund(paymentId, amount, currency, key, reason): GatewayRefund
 └─ healthCheck()                                   used by `bin/console payments:check`

GatewayRegistry  → configured gateways and the currencies each is enabled for (env)
PaymentRouter    → which gateways are offered for a currency + country, in order (admin settings)
PaymentService   → orders, retries, verification, refunds, reconciliation (row lock on the payment)
WebhookService   → dedupe by (gateway, event_id) in webhook_events, then delegates to PaymentService
```

Adding a gateway means implementing `PaymentGateway`, registering it in `GatewayRegistry`, and adding its webhook route; booking code does not change.

### Currency and country routing

* Supported currencies: **INR, USD, AED, GBP**. Prices are stored per currency in integer minor units (`appointment_type_prices`, `event_prices`). There is no currency conversion anywhere: a client pays exactly the stored price in the currency they choose.
* A gateway is offered for a currency only when it is configured **and** the currency is listed in `RAZORPAY_CURRENCIES` / `STRIPE_CURRENCIES`. Set these to what each merchant account is actually enabled for. Non-INR on Razorpay requires International Payments to be activated on the account; Stripe presentment currencies depend on the account's country and settings.
* `payments.routing` (admin → Settings → Payments) is an ordered list of allowed gateways per currency. A freshly seeded database routes every currency to Razorpay only. Without a stored setting, the code falls back to INR → Razorpay then Stripe, and USD/AED/GBP → Stripe then Razorpay.
* The booking and registration pages hide any currency that has no available gateway. Until Razorpay International Payments is approved and `RAZORPAY_CURRENCIES` lists them, USD, AED and GBP are not offered.
* `payments.country_routing` (same screen) optionally moves a gateway to the front for clients from a given country, e.g. `AE → stripe`. It can only reorder gateways already allowed for the currency.
* The booking forms ask for an optional country; the gateways endpoint returns gateways in routing order, and the client picks one.

### Flow

1. The client reserves a time (`POST /appointments`) or places (`POST /events/{slug}/reserve`). The server computes the amount from stored prices, including tax, and holds the slot or places.
2. `POST /appointments/{ref}/payment` or `/event-registrations/{ref}/payment` with `{ gateway }` and an `Idempotency-Key`. The hold is extended to `PAYMENT_HOLD_MINUTES`, a `payments` row is created (`created`), and a gateway order is created with the same idempotency key. Repeating the request returns the same order.
3. The client pays in the gateway's UI (Razorpay Checkout.js modal; Stripe hosted Checkout redirect).
4. Confirmation may arrive by either path, in any order:
   * Browser return → `POST /payments/verify` (Razorpay signature check; Stripe session retrieval with the secret key).
   * Gateway webhook → signature verified over the raw body, deduplicated by event ID.
5. `PaymentService` locks the payment row, checks that order ID, amount and currency match what was stored, records the capture once, and confirms the booking (appointments: books the slot, queues the calendar sync and confirmation emails). Later duplicates are no-ops.
6. Mismatched amount or currency: the payment goes to `reconciliation_required` and the booking is **not** confirmed. An admin reviews it on the payment's page and either accepts it or refunds it.

The frontend never decides that a payment succeeded. Its return handler only asks the server for the current status.

### Retries and switching gateway

* After a failed, cancelled or abandoned attempt, the client can try again (`POST /payments/{ref}/retry`). Each retry creates a **new** payment row and a **new** gateway order; an earlier order is never reused.
* If the client chooses a different gateway, the payment panel first explains that a new, separate checkout will be opened, that the earlier one will not be reused, and that any duplicate charge is refunded automatically to the original method. The system never switches gateway on its own.
* Retrying is refused once the booking is paid.

### Late, duplicate and conflicting payments

* **Duplicate capture** (the client completes two checkouts): the first capture confirms the booking; the second is refunded in full automatically when `payments.auto_refund_conflicts` is on (the default). Otherwise it is flagged for an admin.
* **Payment after the hold lapsed:** if the time or place is still free, the booking is confirmed. If someone else has taken it, the appointment moves to `payment_verification` (registrations stay closed), admins are alerted, and the payment is refunded automatically.
* **Missed webhooks:** every minute the scheduler asks the gateway about `created`/`pending` payments older than 20 minutes (up to 2 days old) and applies the result. Unpaid reservations are expired only after this reconciliation, and never while a checkout started in the last hour.

### Refunds

* Sources: admin refund (full or partial, reason required, `Idempotency-Key` header required), client cancellation inside the policy window, admin cancellation with refund, event cancellation (paid guests refunded in full), and the automatic conflict refunds above.
* Refunds are created with an idempotency key, so retries never refund twice. Automatic refunds run as `payment.refund` jobs in the worker, with backoff. A final failure alerts admins.
* Status: `refunds.pending → processed | failed`, updated from the gateway response and the `refund.*` webhooks. The payment becomes `partially_refunded` or `refunded`. A booking is marked refunded only when no other paid payment exists for it, so refunding a duplicate leaves the booking confirmed.
* Refund timing to the client's account depends on the gateway and the card issuer; no timing is promised in client-facing copy.

### Stored data

Payments store the gateway name, order/payment/refund identifiers, amount, currency, status, environment, receipt number and timestamps. Card numbers, CVVs and other payment credentials never reach this system: Razorpay Checkout and Stripe Checkout collect them on the gateway's own pages and frames.

### Credentials and webhooks

| Variable | Staging | Production |
| --- | --- | --- |
| `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET` | `rzp_test_…` | `rzp_live_…` |
| `RAZORPAY_WEBHOOK_SECRET` | Razorpay Dashboard (Test mode) → Webhooks | Live mode |
| `RAZORPAY_CURRENCIES` | currencies enabled on the account, e.g. `INR` or `INR,USD,AED,GBP` | same |
| `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY` | `sk_test_…`, `pk_test_…` | `sk_live_…`, `pk_live_…` |
| `STRIPE_WEBHOOK_SECRET` | `whsec_…` (test-mode endpoint) | `whsec_…` (live endpoint) |
| `STRIPE_CURRENCIES` | e.g. `USD,AED,GBP` | same |

A gateway counts as configured only when its credentials **and** webhook secret are set (Razorpay: key ID, key secret, webhook secret; Stripe: secret key and webhook secret). Stripe Checkout is a full-page redirect, so the browser never needs a Stripe key; `STRIPE_PUBLISHABLE_KEY` is optional and only lets `payments:check` warn when it is from a different mode than the secret key. The boot guard (`app/Core/EnvironmentGuard.php`) refuses to start production with test keys, or any other environment with live keys. These values belong in `.env.staging` / `.env.production` outside the web root or in the platform's secret manager. They are never stored in admin settings, which reject secret-like keys, and never sent to the frontend; only the Razorpay key ID is public.

A Stripe restricted key (`rk_test_…` / `rk_live_…`) may replace the secret key. It needs write access to **Checkout Sessions** and **Refunds**; `payments:check` also reads the account, so confirm that check passes with the restricted key on staging before using one in production.

Webhook endpoints (both paths are accepted; prefer the first):

| Gateway | URL | Events to subscribe |
| --- | --- | --- |
| Razorpay | `{API_URL}/api/v1/payments/razorpay/webhook` (alias `/payments/webhook/razorpay`) | `payment.captured`, `payment.failed`, `order.paid`, `refund.processed`, `refund.failed` |
| Stripe | `{API_URL}/api/v1/payments/stripe/webhook` (alias `/payments/webhook/stripe`) | `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `refund.created`, `refund.updated` |

Verify the configuration (read-only; exits non-zero on failure so a deploy can gate on it):

```bash
php bin/console payments:check
```

It reports, per gateway, whether it is configured, test or live mode, the account details the provider returns, the currencies enabled locally, and warnings when they don't match the account.

Local webhook testing: `stripe listen --forward-to 127.0.0.1:8080/api/v1/payments/stripe/webhook` (use the printed `whsec_…` as `STRIPE_WEBHOOK_SECRET`); for Razorpay, expose the local API through a tunnel and register it as a Test-mode webhook.

### Go-live prerequisites

Payments are not functional until each item is done for the target environment:

1. Razorpay account in the right mode. Live mode needs KYC and website approval to be complete (the website is reviewed once the site, pricing, contact and policy pages are public). International Payments must be activated before any non-INR currency is listed in `RAZORPAY_CURRENCIES`.
2. Stripe: not used in this deployment.
3. Keys and webhook secrets injected for the environment (test on staging, live on production). Live keys are generated in the Razorpay Dashboard in **Live mode** → Account & Settings → API keys. The secret is shown only once, so paste it straight into the server's environment file.
4. Webhooks registered at the URLs above with the listed events.
5. `php bin/console payments:check` passes.
6. On staging, one end-to-end test-mode payment per gateway and currency, including a failed card, a retry with the other gateway, a partial refund and a full refund, confirmed in both the admin and the gateway dashboard.
7. Admin → Settings → Payments routing reviewed.

## 2. Google Workspace: Gmail, Calendar and Meet

**Status:** covered by tests against mocked Google endpoints. Run locally against the real account hansterahiansh@gmail.com, where Test Gmail, Test Calendar and Test Meet passed. Production needs its own redirect URI and a published consent screen (see Google Cloud setup below).

One OAuth connection (`GoogleAccount`) serves all three services:

* **OAuth 2.0 web-server flow** with offline access and `prompt=consent`. Scopes (the minimum set): `calendar.events` (events and Meet conferences), `openid` and `email` (to show which account is connected). `gmail.send` (send only; no read access) is requested **only when `MAIL_DRIVER=gmail`**. With SMTP the Gmail row shows **Not used**. No service account is used: a service account cannot act for a personal Gmail account.
* **Connect:** Admin → Integrations → Google Workspace (`/admin/settings/integrations/google`) → **Connect Google** → `POST /admin/integrations/google/connect` returns the consent URL → Google → `GET /admin/integrations/google/callback#code&state` → `POST /admin/integrations/google/callback` `{code, state, error}`. The consent URL asks for `response_mode=fragment`: Google always appends `iss=https://accounts.google.com` and full scope URLs to the return, and the shared host's ModSecurity rejects any query value starting with `https://` (HTTP 406). The fragment never reaches the server; the callback serves a small page (CSP allows only its own hashed script) that posts just `code` and `state` back and then opens the admin page with `?google=<outcome>`. A query-string return (`?code&state`) is still accepted. The redirect URI registered with Google is unchanged. `state` is short-lived, HMAC-signed and bound to the admin's live session (CSRF protection). When `GOOGLE_ACCOUNT_EMAIL` is set, any other account is refused and its token revoked.
* **Tokens** are stored encrypted in `calendar_integrations` and never leave the server. The access token is refreshed 2 minutes before expiry, and a `401` is retried once with a fresh token. The scopes Google actually granted are stored, and refreshed on every token refresh, so a permission the user unticked shows as **Permission not granted**.
* **Revocation** (`invalid_grant`) marks the connection `needs_reauth`, pauses Gmail, Calendar and Meet work, shows a dashboard warning and queues one admin alert. If Gmail is also the mail driver, that alert can't be delivered until Google is reconnected, so the dashboard warning is the reliable signal.

**Calendar and Meet**

* Event creation: `POST /calendars/{calendarId}/events?conferenceDataVersion=1&sendUpdates=all`, with `conferenceData.createRequest.requestId` set to the booking reference. Google deduplicates by `requestId`, so retries never create two meetings. The event `id` is also derived from the reference, so a retried insert returns `409` and the existing event is fetched instead.
* Meet: a pending conference is polled briefly. If Google reports no conference, it is requested once more; if that also fails, the job retries.
* The client is the attendee and the connected account is the organiser. The event is private and guests can't see each other or invite others.
* The event description holds booking logistics only: service, appointment type, duration and format, client name, reference, payment status, and the manage link. It never includes application answers or notes, because guests can read it.
* Reminders: `reminders.useDefault=false`, with popup overrides from the reminder settings (default 24 h, 1 h and 15 min). Application email reminders run separately and skip cancelled, refunded and expired bookings.
* Updates: reschedule → `PATCH` start and end (same event ID); cancellation → `DELETE` with `sendUpdates=all`.
* Failure handling: calendar work runs in the job queue (`calendar.sync`) with exponential backoff. While Google is not connected, confirmed bookings wait with `calendar_sync_status=pending` instead of failing. Connecting Google re-queues every upcoming pending or failed booking. After a successful payment the appointment stays `confirmed` whatever happens to calendar sync; admins are emailed on final failure and can press **Sync again**. Clients are never re-charged.
* Gatherings and retreats are not synced to Google Calendar yet.

**Gmail**

* `MAIL_DRIVER=gmail` sends every outbox email through `users.messages.send` as the connected account. Messages appear in its Sent folder.
* The From address is always the connected account; `MAIL_FROM_NAME` sets the display name. Headers are UTF-8 encoded, and addresses containing line breaks are rejected.
* Gmail's daily sending limits apply (roughly 500 recipients a day for a personal Gmail account). Use SMTP through a transactional provider if volume grows.

**Admin page and tests**

* The page shows OAuth, Gmail, Calendar and Meet status, the connected account, the calendar, the last successful sync, the last error, the redirect URI to register, and the last 25 Google operations. It never shows the client secret, access token or refresh token.
* **Test Gmail** sends one message to the operations address (`notifications.admin_email` setting, else `MAIL_ADMIN_ADDRESS`, else the admin's own email).
* **Test Calendar** and **Test Meet** create a private, non-blocking, guest-free event tomorrow (with a Meet conference for the Meet test), then delete it without sending notifications.
* Test events carry a private marker. **Remove leftover test events** deletes any that a failed cleanup left behind and touches nothing else.
* Tests are rate-limited (20 an hour) and audited.
* **Integration log** (`integration_logs`): operation, outcome, HTTP status, error category, internal reference and duration only. It never holds tokens, secrets, addresses, message content or Google's error text. Entries are kept for 90 days.
* **Error categories** have administrator-friendly messages: invalid client, redirect URI mismatch, access denied (including "not a test user"), missing permission, revoked access, API not enabled, calendar not found, quota, Meet failure, network and Google outage. Public users never see Google errors.

Configuration: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` (secret; environment only), `GOOGLE_REDIRECT_URI`, `GOOGLE_CALENDAR_ID` (default `primary`), and optional `GOOGLE_ACCOUNT_EMAIL`. Refresh tokens are not configured in the environment; they come from the **Connect** button. Use a separate OAuth client per environment, or at least register each environment's redirect URI.

Google Cloud setup:
1. Create a project and enable the **Google Calendar API**, plus the **Gmail API** if `MAIL_DRIVER=gmail`.
2. Configure the OAuth consent screen as **External** (a personal Gmail account can't use Internal). Add the scopes above.
3. While the app is in **Testing**, add the account as a test user. Google expires Testing refresh tokens after 7 days, so expect to reconnect weekly.
4. To stop the weekly reconnect, set the publishing status to **In production**. That needs a public homepage and a published privacy policy on the authorised domain (`hansterahiansh.com`). `calendar.events` and `gmail.send` are sensitive scopes. Until Google verifies the app, the consent screen shows an "unverified app" warning that the admin can click through; submit for verification to remove it.
5. Create an OAuth client ID of type **Web application**, with the authorised redirect URI `{API_URL}/api/v1/admin/integrations/google/callback`. For production that is `https://www.hansterahiansh.com/api/v1/admin/integrations/google/callback`, and the authorised JavaScript origin is `https://www.hansterahiansh.com`.

## 3. Email

* `Mailer` interface with an SMTP implementation (PHPMailer) — works with Postmark, Amazon SES, SendGrid, Mailgun, Resend (SMTP relay). Provider-specific API mailers can be added behind the same interface.
* Every email goes through the `notifications` outbox (recipient + variables encrypted), sent by the worker with retries (5 attempts, backoff). Status: `queued → sending → sent | failed`; provider message ID stored.
* Templates (`email_templates`) are editable in admin with `{{variable}}` placeholders (HTML-escaped on render) and a branded layout. Seeded slugs:
  * Applications: `application_received`, `application_draft_resume`, `application_approved`, `application_rejected`, `application_info_requested`, `application_invited`.
  * Appointments: `appointment_reserved`, `appointment_payment_request`, `appointment_confirmation`, `appointment_meeting_details`, `appointment_reminder`, `appointment_rescheduled`, `appointment_cancelled`.
  * Payments: `payment_confirmation`, `payment_failed`, `refund_initiated`, `refund_confirmation`.
  * Gatherings: `event_registration_confirmation`, `event_payment_request`, `event_waitlisted`, `event_waitlist_offer`, `event_registration_cancelled`, `event_reminder`.
  * Admin and account: `admin_new_application`, `admin_new_booking`, `admin_new_event_registration`, `admin_payment_succeeded`, `admin_payment_failed`, `admin_integration_failure`, `admin_invitation`, `password_reset`, `privacy_request_verification`.

Drivers: `smtp`, `gmail` (Gmail API through the Google connection; see section 2) or `log` (local only). Production accepts `smtp` or `gmail`.

SMTP credentials: `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` (`tls` for STARTTLS on 587, `ssl` for 465), `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO` (optional Reply-To when the sender mailbox is unattended), `MAIL_ADMIN_ADDRESS`. Production sends as `noreply@hansterahiansh.com`, with replies and admin alerts going to `info@hansterahiansh.com`.

Production (www.hansterahiansh.com) uses the domain's own cPanel mailbox: `mail.hansterahiansh.com:465` (`ssl`), user and From `info@hansterahiansh.com`. The domain already publishes SPF (`a mx`), DKIM and DMARC (`p=none`) for that server. With the `gmail` driver, mail would instead come from the connected Gmail address.

Delivery is at-least-once. A send that fails is retried (1, 5, 15, 60 and 240 minutes) and never changes the state of a payment or booking. A message whose worker was killed mid-send (shared hosts stop long-running processes) is re-queued by the scheduler after 15 minutes. In local development, `MAIL_DRIVER=log` writes rendered emails to `storage/logs/mail.log` (recipients redacted). Admin → Integrations → **Send test email** checks delivery.

## 4. Analytics

Google Analytics 4 loads **only after explicit consent** (cookie banner, consent stored in `localStorage` and recorded server-side as an anonymous consent record). Events sent: `page_view`, `service_view`, `application_start`, `application_submit`, `booking_start`, `payment_success`, `event_registration`. No names, emails, free-text or references are ever sent. `VITE_GA_MEASUREMENT_ID` enables it.
