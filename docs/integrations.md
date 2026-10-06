# Integrations — Payments, Google Calendar/Meet, Email

All integrations are real implementations against the vendors' documented REST APIs. They activate when credentials are provided; without credentials the admin "Integrations" screen shows them as **Not configured** and dependent features degrade safely (paid appointment types and paid gatherings cannot be reserved, and the public pages say so).

> **Status:** the Razorpay and Stripe adapters have been exercised only by the automated test suite (against a test-only fake gateway) and by local runs without credentials. They have **not** yet been run against a Razorpay or Stripe sandbox. Treat payments as unverified until the checklist in [Go-live prerequisites](#go-live-prerequisites) is complete on staging.

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
* `payments.routing` (admin → Settings → Payments) is an ordered list of allowed gateways per currency. Default: INR → Razorpay then Stripe; USD/AED/GBP → Stripe then Razorpay.
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

A gateway counts as configured only when its credentials **and** webhook secret are set (Razorpay: key ID, key secret, webhook secret; Stripe: secret key and webhook secret, plus the publishable key for the client). The boot guard (`app/Core/EnvironmentGuard.php`) refuses to start production with test keys, or any other environment with live keys. These values belong in `.env.staging` / `.env.production` outside the web root or in the platform's secret manager. They are never stored in admin settings, which reject secret-like keys, and never sent to the frontend; only the Razorpay key ID and Stripe publishable key are public.

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

1. Razorpay account in the right mode, with International Payments activated if any non-INR currency is listed in `RAZORPAY_CURRENCIES`.
2. Stripe account activated, with the AED/GBP/USD presentment currencies available for its country.
3. Keys and webhook secrets injected for the environment (test on staging, live on production).
4. Webhooks registered at the URLs above with the listed events.
5. `php bin/console payments:check` passes.
6. On staging, one end-to-end test-mode payment per gateway and currency, including a failed card, a retry with the other gateway, a partial refund and a full refund, confirmed in both the admin and the gateway dashboard.
7. Admin → Settings → Payments routing reviewed.

## 2. Google Calendar & Meet

* OAuth 2.0 Web Server flow (offline access, `prompt=consent` to guarantee a refresh token). Scope: `https://www.googleapis.com/auth/calendar.events`.
* Admin clicks **Connect Google Calendar** → `POST /admin/integrations/google/connect` returns the Google consent URL → Google → `/admin/integrations/google/callback?code&state`. `state` is short-lived, HMAC-signed and bound to the admin's live session (CSRF protection).
* Tokens stored encrypted in `calendar_integrations`; access token refreshed 2 minutes before expiry. Revocation (`invalid_grant`) marks the integration `needs_reauth` and alerts admins.
* Event creation: `POST /calendars/{calendarId}/events?conferenceDataVersion=1&sendUpdates=all` with `conferenceData.createRequest.requestId = appointment.reference` (Google deduplicates by `requestId`, so retries never create two meetings). The event `id` is also derived deterministically from the reference, so a retried insert returns `409` and we fetch the existing event instead.
* Reminders: `reminders.useDefault=false`, overrides built from enabled reminder settings (popup + email).
* Updates: reschedule → `PATCH` start/end; cancellation → `DELETE` with `sendUpdates=all`.
* Failure handling: calendar work runs in the job queue (`calendar.sync`) with exponential backoff. After a successful payment the appointment stays `confirmed` with `calendar_sync_status=failed` until a retry succeeds; admins are emailed and can press **Sync again** on the appointment. Clients are never re-charged.

Credentials required: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `GOOGLE_CALENDAR_ID` (default `primary`). Use separate OAuth clients (and ideally separate Google accounts/calendars) for staging and production.

Setup: Google Cloud Console → new project → enable **Google Calendar API** → OAuth consent screen (Internal if Workspace, else External, which needs Google's verification for the calendar scope before non-test users can connect) → Credentials → OAuth client ID (Web) → authorised redirect URI `{API_URL}/api/v1/admin/integrations/google/callback`. Until an admin completes the connection, confirmed Google Meet appointments tell the client that their private link will be shared before the session, and the calendar job keeps retrying (then shows as failed in the admin).

## 3. Email

* `Mailer` interface with an SMTP implementation (PHPMailer) — works with Postmark, Amazon SES, SendGrid, Mailgun, Resend (SMTP relay). Provider-specific API mailers can be added behind the same interface.
* Every email goes through the `notifications` outbox (recipient + variables encrypted), sent by the worker with retries (5 attempts, backoff). Status: `queued → sending → sent | failed`; provider message ID stored.
* Templates (`email_templates`) are editable in admin with `{{variable}}` placeholders (HTML-escaped on render) and a branded layout. Seeded slugs:
  * Applications: `application_received`, `application_draft_resume`, `application_approved`, `application_rejected`, `application_info_requested`, `application_invited`.
  * Appointments: `appointment_reserved`, `appointment_payment_request`, `appointment_confirmation`, `appointment_meeting_details`, `appointment_reminder`, `appointment_rescheduled`, `appointment_cancelled`.
  * Payments: `payment_confirmation`, `payment_failed`, `refund_initiated`, `refund_confirmation`.
  * Gatherings: `event_registration_confirmation`, `event_payment_request`, `event_waitlisted`, `event_waitlist_offer`, `event_registration_cancelled`, `event_reminder`.
  * Admin and account: `admin_new_application`, `admin_new_booking`, `admin_integration_failure`, `admin_invitation`, `password_reset`, `privacy_request_verification`.

Credentials required: `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` (`tls`), `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_ADMIN_ADDRESS`. In local development, `MAIL_DRIVER=log` writes rendered emails to `storage/logs/mail.log` (recipients redacted). Admin → Integrations → **Send test email** checks delivery.

## 4. Analytics

Google Analytics 4 loads **only after explicit consent** (cookie banner, consent stored in `localStorage` and recorded server-side as an anonymous consent record). Events sent: `page_view`, `service_view`, `application_start`, `application_submit`, `booking_start`, `payment_success`, `event_registration`. No names, emails, free-text or references are ever sent. `VITE_GA_MEASUREMENT_ID` enables it.
