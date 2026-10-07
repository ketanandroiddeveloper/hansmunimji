# API Specification — v1

Base URL: `{API_URL}/api/v1`. JSON in, JSON out (`Content-Type: application/json`), except media upload (`multipart/form-data`) and audio streaming (`audio/*`, HTTP Range).

## Conventions

* **Envelope** — success: `{ "data": …, "meta"?: … }`; error: `{ "error": { "code", "message", "fields"?, "request_id" } }`.
* **Status codes** — `200` OK, `201` Created, `204` No Content, `400` malformed, `401` unauthenticated, `403` forbidden / CSRF, `404`, `409` conflict (slot taken, invalid state transition), `422` validation, `429` rate limited (with `Retry-After`), `500` (generic message + `request_id`).
* **Pagination** — `?page=1&per_page=20` (max 100) → `meta: { page, per_page, total, total_pages }`.
* **Filtering/sorting** — `?status=submitted&q=…&sort=-created_at` (allowlisted columns only).
* **Admin auth** — HttpOnly `__Host-pa_session` cookie (Secure, SameSite=Strict) + `X-CSRF-Token` header on every state-changing request. The CSRF token is returned by `/auth/login` and `/auth/me`.
* **Client access tokens** — applicants and clients receive an unguessable reference plus a 256-bit access token (sent by email, never stored in plaintext). Send as `X-Access-Token`. Tokens expire (applications: 30 days; appointments: 7 days after appointment end).
* **Idempotency** — every endpoint that creates a booking or a gateway order (`POST /appointments`, `/appointments/{reference}/payment`, `/event-registrations/{reference}/payment`, `/payments/create-order`, `/payments/{reference}/retry`) accepts `Idempotency-Key` (16–64 characters of `[A-Za-z0-9-]`, e.g. a UUID). Repeating a key returns the original result instead of creating a second order. Admin refunds require it. Webhooks are deduplicated by gateway event ID.
* **Money** — always integer minor units (`amount_minor`, paise/cents/fils/pence) plus an ISO currency code (`INR`, `USD`, `AED`, `GBP`). Amounts are computed server-side from stored prices; the client never sends an amount and no currency conversion is ever applied.
* **Request IDs** — every response carries `X-Request-Id`.

## Public

| Method | Path | Description |
| --- | --- | --- |
| GET | `/health` | Liveness + DB check (no secrets) |
| GET | `/settings/public` | Site identity, contact, socials, currencies, feature flags, environment label |
| GET | `/pages` | Published pages (slug, title, type) |
| GET | `/pages/{slug}` | Page with structured `sections` and SEO |
| GET | `/practitioner` | Primary practitioner profile + published qualifications grouped by kind |
| GET | `/services` | Published services (summary fields) |
| GET | `/services/{slug}` | Service detail + active appointment types (public prices) + FAQs + SEO |
| GET | `/faqs?service={slug}` | Published FAQs |
| GET | `/testimonials` | Only authorized + published |
| GET | `/cities` | Active cities for in-person concierge |
| GET | `/events?city=&category=&when=upcoming` | Published events with `registration_state`, `prices` (each flagged `payable`), `tax`, seats remaining |
| GET | `/events/{slug}` | Event detail, plus registration window, waitlist flag and cancellation policy (`cancellation_window_hours`, `refund_on_cancel_percent`) |
| POST | `/events/{slug}/reserve` | Reserve places (alias: `/events/{slug}/registrations`). See below |
| GET | `/event-registrations/{reference}` | Registration status for the guest (`X-Access-Token`), including waitlist position, amounts and latest payment attempt (alias: `/events/registrations/{reference}`) |
| POST | `/event-registrations/{reference}/payment` | Start checkout for a reserved place (`X-Access-Token`, `Idempotency-Key`). Body `{ gateway }` |
| POST | `/event-registrations/{reference}/cancel` | Guest cancellation `{ reason? }`. Refund follows the event's policy; a freed place is offered to the waitlist |
| GET | `/blogs?category=&page=` | Published articles |
| GET | `/blogs/{slug}` | Article + author |
| GET | `/audio?category=` | Track list (metadata only; no file paths) |
| POST | `/audio/{slug}/stream` | Returns a signed URL valid 10 min if the caller may access the track |
| GET | `/audio/stream/{token}` | Streams the file (Range supported); token is HMAC-signed with expiry |
| POST | `/privacy/requests` | Data access / deletion request (verification email) |

Event reservation body: `{ name, email, phone?, country?, notes?, seats (1–4), currency?, consent_privacy }`. `currency` must be one of the event's prices (it may be omitted when there is only one). Seats are allocated under a row lock, so an event is never oversold:

* Places available, paid event: `pending_payment`, held for `PAYMENT_HOLD_MINUTES`. Response includes `access_token` (shown once), amounts and `hold_expires_at`.
* Places available, free event: `confirmed`.
* Full with waitlist enabled: `waitlisted`. When a place frees up, the earliest waitlisted guest is offered it automatically (setting `events.auto_promote_waitlist`) and has `events.waitlist_offer_hours` to pay.
* Full without waitlist: `409 sold_out`.
* Outside the registration window: `409 registration_not_open` / `registration_closed`.
* No configured gateway can take the chosen currency: `409 payments_unavailable` (no place is held).
* An open registration already exists for the email address: `409 already_registered`.

An expired hold can still be paid: checkout re-acquires the place if it is still free, otherwise it returns `409`. A payment that arrives after the place went to someone else is refunded automatically.

## Private access applications

| Method | Path | Description |
| --- | --- | --- |
| POST | `/applications/drafts` | Start a draft → `{ reference, resume_token }`; resume link emailed if `email` provided |
| GET | `/applications/drafts/{reference}` | Resume (requires `X-Access-Token: resume_token`) |
| PUT | `/applications/drafts/{reference}` | Save step data (partial) |
| POST | `/applications/drafts/{reference}/submit` | Validate all steps, record consent, submit |
| POST | `/applications` | One-shot submit (no draft) |
| GET | `/applications/{reference}` | Status only (`X-Access-Token`); never echoes confidential fields |

Application payload:

```json
{
  "full_name": "…", "email": "…", "phone": "+971…", "country": "AE", "city": "Dubai",
  "professional_background": "…", "designation": "…", "organization": "…",
  "consultation_type": "executive-mind-architecture",
  "core_objective": "…", "preferred_format": "google_meet|phone|in_person",
  "preferred_availability": "…", "referral_source": "referral|search|event|media|other",
  "referral_details": "…", "confidential_notes": "…",
  "consent_privacy": true, "consent_communications": true
}
```

## Appointments

| Method | Path | Description |
| --- | --- | --- |
| GET | `/appointments/types?service={slug}&invite={token}` | Bookable types (approval-gated types require a valid invite token) |
| GET | `/appointments/availability?type={slug}&from=YYYY-MM-DD&to=YYYY-MM-DD&timezone=Asia/Dubai` | Slots as UTC ISO-8601 + local labels |
| POST | `/appointments` | Reserve a time (alias: `/appointments/reserve`). Holds the slot for `SLOT_HOLD_MINUTES` and creates the appointment `pending_payment` (or `confirmed` if payment-exempt). Body: `type`, `starts_at` (UTC), `timezone`, `format`, `currency`, `name`, `email`, `phone`, `country?`, `notes`, `invite_token?`, `consent_privacy` → `{ reference, access_token, status, hold_expires_at, amount }`. `409 slot_unavailable` if taken |
| GET | `/appointments/{reference}` | Details for the client (`X-Access-Token`), including the latest payment attempt `{ reference, gateway, status }` |
| POST | `/appointments/{reference}/payment` | Start checkout (`X-Access-Token`, `Idempotency-Key`). Body `{ gateway }`. Re-holds the slot for `PAYMENT_HOLD_MINUTES`; `409 slot_unavailable` if someone else has taken it |
| POST | `/appointments/{reference}/reschedule` | `{ starts_at }` — within policy window; updates Calendar event; resets reminders |
| POST | `/appointments/{reference}/cancel` | `{ reason? }` — within policy window; triggers refund per policy |

Unpaid reservations become `expired` when their start time passes, or once the hold has been released for longer than `booking.unpaid_expiry_hours` (admin setting, default 24). A reservation with a checkout started in the last hour is never expired. A verified payment that arrives after expiry still confirms the appointment if the time is free; otherwise the appointment goes to `payment_verification` and the payment is refunded automatically.

## Payments

| Method | Path | Description |
| --- | --- | --- |
| GET | `/payments/gateways?currency=INR&country=AE` | Gateways that are configured and enabled for the currency, in routing order (country preference first), with public keys and `mode` (`test`/`live`) |
| POST | `/payments/create-order` | `{ appointment_reference \| registration_reference, access_token, gateway }` (generic form of the two `…/payment` endpoints) |
| GET | `/payments/{reference}` | One payment attempt for the client (`X-Access-Token` of its booking or registration) |
| POST | `/payments/{reference}/retry` | New checkout after a `failed`, `cancelled`, `created` or `pending` attempt, optionally `{ gateway }` to switch provider. Always creates a fresh gateway order; refused once the booking is paid |
| POST | `/payments/verify` | Browser return. Razorpay: `{ gateway, razorpay_order_id, razorpay_payment_id, razorpay_signature }`; Stripe: `{ gateway, session_id }`. Verified with the gateway server-side; the webhook confirms independently |
| POST | `/payments/stripe/webhook` | `Stripe-Signature` (timestamped HMAC, 5-minute tolerance). Alias: `/payments/webhook/stripe` |
| POST | `/payments/razorpay/webhook` | `X-Razorpay-Signature` HMAC-SHA256 over the raw body. Alias: `/payments/webhook/razorpay` |

Checkout responses: Razorpay `{ gateway, order_id, key_id, amount, currency, description, payment_reference }` (opened with Checkout.js); Stripe `{ gateway, checkout_url, session_id, payment_reference }` (redirect to Stripe Checkout). A booking is confirmed only after server-side verification: signature or session lookup, then order ID, amount and currency must match the stored payment. A mismatch puts the payment into reconciliation for an admin instead of confirming.

## Status reference

The brief's state names map onto the stored statuses as follows.

| Brief | Stored as |
| --- | --- |
| Payment `succeeded` | `payments.status = captured` (`partially_refunded` / `refunded` after refunds) |
| Payment `processing` | `pending`; the booking moves to `payment_verification` when a capture needs admin attention |
| Refund pending / processed / failed | `refunds.status = pending / processed / failed`, listed on the payment's admin page |
| Appointment `reserved` | `pending_payment` (slot held) |
| Appointment `meeting_created` / `meeting_failed` | `confirmed` with `calendar_sync_status = synced / failed` |
| Appointment `no_show` | `no_show` (admin action after the start time) |
| Registration `reserved` | `pending_payment` (place held until `hold_expires_at`) |
| Application `additional_info_required` | `info_requested` |
| Application `converted` | `converted` (an appointment was booked from the invitation) |

Every status change for applications, appointments, registrations and payments is written to `status_history` with its source (`client`, `admin`, `webhook`, `verify`, `scheduler`, `system`).

## Authentication (admin)

| Method | Path | Description |
| --- | --- | --- |
| POST | `/auth/login` | `{ email, password }` → user + csrf, or `{ two_factor_required: true, challenge }` |
| POST | `/auth/two-factor` | `{ challenge, code }` |
| POST | `/auth/logout` | Revoke session |
| POST | `/auth/refresh` | Rotate session token (sliding expiry) |
| GET | `/auth/me` | Current user, roles, permissions, csrf token |
| POST | `/auth/forgot-password` | Always `202` (no account enumeration) |
| POST | `/auth/reset-password` | `{ token, password }` |
| POST | `/auth/change-password` | `{ current_password, password }` |
| POST | `/auth/two-factor/setup` · `/enable` · `/disable` | TOTP enrolment (RFC 6238) |

## Admin (`/admin/*`, authenticated + permission-checked)

| Resource | Endpoints | Permission |
| --- | --- | --- |
| Dashboard | `GET /admin/dashboard` | `dashboard.view` |
| Applications | `GET /admin/applications`, `GET /{id}`, `POST /{id}/approve`, `/reject`, `/request-info`, `/invite`, `/archive`, `PATCH /{id}/notes`, `PATCH /{id}/status` | `applications.view`, `applications.view_confidential`, `applications.manage` |
| Appointments | `GET /admin/appointments`, `GET /admin/appointments/availability`, `GET /{id}` (includes payments and status history), `POST /admin/appointments` (manual), `PATCH /{id}` (`{ starts_at }` to reschedule, or `{ status: cancelled\|completed\|no_show, reason?, refund? }`), `POST /{id}/reschedule`, `/cancel` `{ reason?, refund? }` (omit `refund` to apply the type's policy), `/complete`, `/no-show`, `/resend-confirmation`, `/calendar-sync` | `appointments.view`, `appointments.manage` |
| Appointment types | CRUD `/admin/appointment-types`, `PUT /{id}/prices` | `appointments.configure` |
| Availability | CRUD `/admin/availability`, CRUD `/admin/unavailable-dates` | `appointments.configure` |
| Services | CRUD `/admin/services`, `/admin/service-categories` | `content.manage` |
| Pages | `GET/PUT /admin/pages/{slug}`, `POST /admin/pages` | `content.manage` |
| Practitioner | `GET/PUT /admin/practitioner`, CRUD `/admin/qualifications` | `content.manage` |
| Blogs | CRUD `/admin/blogs`, `/admin/blog-categories` | `content.manage` |
| Events | CRUD `/admin/events` (prices per currency, tax, quota, registration window, waitlist, cancellation policy), `GET /{id}/registrations`, `PUT /{id}/registrations/{registration}` `{ status, refund? }`, `GET /{id}/registrations/{registration}/history`. Setting an event to `cancelled` cancels every open registration and refunds paid guests in full | `events.manage` |
| Audio | CRUD `/admin/audio` (multipart upload) | `content.manage` |
| Media | `GET/POST /admin/media`, `PUT/DELETE /{id}`, `POST /{id}/replace` | `media.manage` |
| FAQs / Testimonials / Cities | CRUD | `content.manage` |
| SEO | CRUD `/admin/seo` | `seo.manage` |
| Email templates | `GET/PUT /admin/email-templates/{slug}`, `POST /{slug}/preview` | `settings.manage` |
| Payments | `GET /admin/payments?status=&gateway=&currency=&payable_type=&environment=&q=` (`q` matches payment, booking or registration reference, receipt or gateway ID), `GET /admin/payments/export` (CSV), `GET /{id}` (refunds, webhook events, history), `POST /{id}/refund` `{ amount_minor, reason }` with `Idempotency-Key`, `POST /{id}/accept-reconciliation` `{ confirm: true }` | `payments.view`, `payments.refund` |
| Reports | `GET /admin/reports?from=&to=` — appointments, applications, revenue by currency and gateway, payment outcomes, event registrations and revenue (current environment's payments only) | `reports.view` |
| Integrations | `GET /admin/integrations` (includes `google`: status, account, per-service status, missing scopes, recent safe log), `POST /admin/integrations/google/connect`, `GET /admin/integrations/google/callback` and `POST /admin/integrations/google/callback` (public, authenticated by the signed `state`; the POST takes `{code, state, error}` and returns `{redirect}`), `POST /admin/integrations/google/disconnect`, `POST /admin/integrations/google/test/{gmail\|calendar\|meet\|cleanup}` (Google failures return `502` with code `google_{category}` and an admin-friendly message), `POST /admin/integrations/jobs/{id}/retry`, `POST /admin/integrations/email/test` | `integrations.manage` |
| Settings | `GET/PUT /admin/settings` (non-secret keys only, e.g. `payments.routing`, `payments.country_routing`, `payments.auto_refund_conflicts`, `booking.unpaid_expiry_hours`, `events.auto_promote_waitlist`, `events.waitlist_offer_hours`, `events.reminder_offsets_minutes`) | `settings.manage` |
| Users & roles | CRUD `/admin/users`, `GET /admin/roles`, `PUT /admin/roles/{id}` | `users.manage` |
| Users & roles (actions) | `POST /admin/users/{id}/resend-invitation`, `/revoke-sessions`, `/reset-two-factor` | `users.manage` |
| Audit log | `GET /admin/audit-logs` | `audit.view` |

## Non-API endpoints

`GET /sitemap.xml`, `GET /robots.txt` (environment-aware: staging disallows everything).
