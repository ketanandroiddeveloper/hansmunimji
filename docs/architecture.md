# System Architecture

## 1. Goals and constraints

| Concern | Decision |
| --- | --- |
| Audience | HNWI / UHNWI clients, executives, invited guests. Discretion > conversion tactics. |
| Confidentiality | Application and appointment PII encrypted at rest (AES-256-GCM, versioned keys). Nothing confidential is indexed, logged or sent to analytics. |
| Deployability | Frontend (static SPA + prerendered HTML) and backend (PHP API) deploy independently. |
| Scale | Modular monolith. One MySQL primary, horizontally scalable stateless PHP-FPM nodes, background worker processes. No microservices. |
| Integrations | Razorpay, Stripe, Google Calendar/Meet, SMTP-compatible transactional email. All behind interfaces. |

## 2. High-level topology

```mermaid
flowchart LR
    subgraph Client
        B[Browser]
    end
    subgraph Edge
        CDN[CDN / Nginx static<br/>frontend/dist]
    end
    subgraph App["API tier (stateless)"]
        API[PHP-FPM<br/>public/index.php]
        W[Worker<br/>bin/console worker]
        S[Scheduler<br/>bin/console schedule:run<br/>(cron every minute)]
    end
    DB[(MySQL 8<br/>primary)]
    FS[(Private storage<br/>storage/private)]
    PUB[(Public media<br/>storage/public → /media)]

    B -->|HTML/JS/CSS| CDN
    B -->|/api/v1 JSON, HTTPS| API
    API --> DB
    API --> FS
    API --> PUB
    W --> DB
    S --> DB
    W -->|Calendar API| G[Google]
    W -->|SMTP / API| E[Email provider]
    API -->|Orders / refunds| P[Razorpay / Stripe]
    P -->|Webhooks| API
```

* **Frontend** — React 19 + TypeScript + Vite. Public site and admin panel live in one codebase, but the admin is a separately code-split bundle under `/admin` that is never prerendered, carries `noindex`, and is disallowed in `robots.txt`.
* **Prerendering** — `npm run build:prerender` builds the SPA, then `scripts/prerender.mjs` loads every route in the backend sitemap in headless Chrome against the target environment's API and writes `dist/<route>/index.html` with full body markup, title, meta, canonical, Open Graph and JSON-LD. Routes that render `noindex` are skipped, and the admin is never rendered. The untouched SPA shell is kept as `dist/app-shell.html`, which the web server serves for every other path. In the browser, `main.tsx` keeps the snapshot visible while the live app renders off-screen, then swaps it in; entrance animations stay off on that first view so nothing blinks. A snapshot served for the wrong path is discarded. The admin triggers a rebuild hook after publishing (see `deployment.md`).
* **Backend** — Core PHP 8.2+ with a small purpose-built kernel (`app/Core`): PSR-4 autoloading, a router with route groups and middleware, request/response objects, a DI container, PDO database layer, validator, and migrator. Business logic lives in `app/Services`, persistence in `app/Repositories`, HTTP concerns in `app/Controllers` and `app/Middleware`.
* **Background processing** — a database-backed job queue (`jobs`, `notifications`, `reminder_jobs`). `bin/console worker` drains the queue (email, calendar sync, automatic refunds, appointment reminders, retries with exponential backoff). `bin/console schedule:run` (cron, every minute) releases expired slot holds, reconciles pending payments with the gateways, expires unpaid appointments and event holds, sends event reminders and applies data-retention policies. A queue in MySQL is sufficient at this scale and avoids an extra moving part; the `Queue` interface allows Redis/SQS later.

## 3. Request lifecycle (API)

```
public/index.php
  → Env::load()  (refuses to boot if APP_ENV/ DB / payment keys are inconsistent, e.g. live keys in staging)
  → Kernel → Middleware pipeline:
        RequestId → SecurityHeaders → Cors (allowlist) → JsonBody (size limit)
        → RateLimit (per route group) → [Authenticate → Csrf → Authorize(permission)]
  → Router → Controller → Service → Repository → PDO (prepared statements)
  → Response (JSON envelope)  → ErrorHandler (structured errors, no internals in production)
```

Response envelope:

```json
{ "data": { }, "meta": { "page": 1, "per_page": 20, "total": 132 } }
{ "error": { "code": "validation_failed", "message": "…", "fields": { "email": ["…"] }, "request_id": "…" } }
```

## 4. Module map

| Module | Backend | Frontend |
| --- | --- | --- |
| Content (pages, services, practitioner, FAQs, testimonials, legal) | `ContentService`, `PageRepository`, `ServiceRepository` | `pages/*`, `features/services` |
| Private access applications | `ApplicationService` (encryption, state machine, invite tokens) | `features/applications` (multi-step form, save & resume) |
| Appointments | `AvailabilityService` (pure slot engine), `BookingService` (holds, state machine), `ReminderService` | `features/appointments` (type → date → slot → details → payment) |
| Payments | `PaymentGateway` interface, `RazorpayGateway`, `StripeGateway`, `PaymentService`, `WebhookService` | `features/payments` (Razorpay Checkout / Stripe Checkout redirect) |
| Google | `GoogleOAuth`, `GoogleCalendarClient`, `CalendarSyncService` | admin `Integrations` screen |
| Email | `Mailer` interface, `SmtpMailer`, `TemplateRenderer`, `NotificationService` | admin `Email templates` |
| Events | `EventService`, `EventRegistrationService` | `features/events` |
| Audio | `AudioService` (signed, expiring stream URLs; Range support) | `features/audio` (custom player) |
| Media | `MediaService` (MIME sniffing, re-encode, AVIF/WebP variants, private disk) | admin media library |
| Admin & RBAC | `AuthService`, `SessionService`, `TotpService`, `Rbac`, `AuditLogger` | `admin/*` |
| SEO | `SitemapController`, `RobotsController`, `seo_metadata` | `components/seo`, prerender |

## 5. Page and feature breakdown

### Public site

| Route | Purpose | Key components | Data |
| --- | --- | --- | --- |
| `/` | Editorial landing | Hero (arched portrait, headline, Apply CTA), Practitioner intro, Disciplines (5 services), Philosophy & method, Featured audio, Upcoming gatherings, Confidentiality band, Inquiry CTA | `pages/home`, services, audio (featured), events (upcoming), settings |
| `/practitioner` | About the practitioner | Biography, qualifications (admin-managed only), expertise, philosophy, Vedic & psychological approach, experience timeline, gallery, publications/interviews/speaking | `pages/about`, practitioner profile, qualifications, gallery media |
| `/practice` | Services index | Discipline list with concise summaries (GEO) | services |
| `/practice/:slug` | Service detail | Summary, body, formats, durations, pricing (if public), eligibility, FAQs, CTA (book / apply / inquire based on `booking_mode`) | service + appointment types + FAQs |
| `/private-access` | Multi-step application | 5 steps, step indicator, Zod validation, encrypted save-and-resume via emailed link | POST/PUT applications |
| `/private-access/status/:reference` | Applicant status (token-gated) | Minimal status view, no PII echoed | GET application (token) |
| `/consultation` | Booking flow | Type → calendar → slots (client TZ) → details → review → payment | availability, appointments, payments |
| `/consultation/:reference` | Manage booking (token-gated) | Details, Meet link (once confirmed), reschedule, cancel per policy | appointment (token) |
| `/library` | Meditation & audio | Category filter, featured, custom accessible player, gated tracks | audio tracks, signed stream URLs |
| `/gatherings` | Retreats & events | Upcoming by city, category filter | events |
| `/gatherings/:slug` | Event detail | Dates in local TZ, venue, seats, registration/application | event + registration |
| `/journal`, `/journal/:slug` | Articles | Author attribution, Article JSON-LD | blogs |
| `/legal/:slug` | Privacy, terms, cancellation & refund, cookies, confidentiality, data retention | Rich text | pages (legal) |
| `/privacy/requests` | Data access/deletion request | Email-verified request flow | data_requests |

### Admin (`/admin`, authenticated, RBAC)

Dashboard · Applications (review, approve, reject, request info, invite to book) · Appointments (calendar/list, filters, manual booking, reschedule, cancel, resend confirmation, regenerate Meet) · Appointment types (pricing per currency, availability rules, policies, tax) · Availability (weekly hours, unavailable dates) · Services & categories · Pages (Home/About sections, legal) · Practitioner & qualifications · Journal · Events & registrations · Audio library · Media library · FAQs · Testimonials (authorization-gated) · SEO metadata · Email templates · Payments & refunds · Reports · Integrations (Google connect, health) · Settings · Users & roles · Audit log.

## 6. Environments

| | Local | Staging | Production |
| --- | --- | --- | --- |
| `APP_ENV` | `local` | `staging` | `production` |
| Payment keys | test | test (`rzp_test_`, `sk_test_`) | live (`rzp_live_`, `sk_live_`) |
| Boot guard | — | refuses live keys | refuses test keys, `APP_DEBUG=true`, non-HTTPS `APP_URL` |
| Banner | "LOCAL" ribbon | "STAGING" ribbon on site + admin | none on site; admin shows "PRODUCTION" badge |
| Indexing | noindex | noindex (header + robots) | indexable public routes |

Secrets are loaded from `.env.<environment>` files outside the web root (or injected by the platform's secret manager). See `deployment.md` and `backend/.env.example`.

## 7. Implementation roadmap

| Phase | Scope | Status |
| --- | --- | --- |
| 1. Planning | Architecture, ERD, API contracts, design system, security & integration plans | This document set |
| 2. Frontend | Design system, homepage, public pages, application & booking UIs | Implemented (iterating) |
| 3. Backend & admin | Migrations, auth/RBAC, versioned APIs, CMS, application & appointment management | Implemented (iterating) |
| 4. Payments & integrations | Razorpay, Stripe, Google OAuth/Calendar/Meet, email queue, reminders | Implemented; needs credentials to activate |
| 5. SEO/GEO/performance | Prerender, JSON-LD, sitemap, image pipeline, CWV budget | Implemented; measure on staging |
| 6. Testing & deployment | PHPUnit, Vitest, E2E plan, Nginx config, backups | Unit/integration suites in repo; E2E against staging credentials |

Known follow-ups are tracked in the README "Status & next steps" section.
