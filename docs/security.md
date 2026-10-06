# Security Architecture

## 1. Threat model (summary)

| Asset | Threats | Primary controls |
| --- | --- | --- |
| Confidential applications (executive identity, objectives) | DB dump, SQLi, insider over-access, log leakage | Field-level AES-256-GCM, prepared statements only, `applications.view_confidential` permission, audit log on every decrypt-view, log redaction |
| Admin accounts | Credential stuffing, session theft, CSRF | Argon2id, progressive throttling + lockout, optional TOTP, HttpOnly/Secure/SameSite=Strict `__Host-` cookie, CSRF header, idle+absolute session expiry |
| Payments | Forged success callbacks, replayed webhooks, amount tampering | Server-side amount computation, signature verification, gateway re-fetch, `webhook_events` uniqueness, idempotent state transitions |
| Google OAuth tokens | Theft from DB | Encrypted at rest, minimal scope (`calendar.events`), `state` parameter bound to admin session |
| Private audio | Hotlinking / path disclosure | Files outside web root, HMAC-signed expiring URLs, no storage paths in API |
| Uploaded media | Malicious files, polyglots | Extension + `finfo` MIME allowlist, size limits, GD re-encode (strips metadata/EXIF GPS), random filenames, no execution in upload dir |
| Client manage links | Enumeration | 128-bit random reference + 256-bit token (stored as SHA-256), expiry, rate limit |

## 2. Controls in the codebase

| Control | Where |
| --- | --- |
| Environment boot guard (live keys only in production, no debug in production, HTTPS `APP_URL`) | `app/Core/Env.php` → `EnvironmentGuard` |
| Security headers: CSP, HSTS, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `X-Frame-Options: DENY`, COOP/CORP | `app/Middleware/SecurityHeaders.php` (API) and Nginx snippet in `docs/deployment.md` (frontend) |
| CORS allowlist (exact origins, credentials only for admin origin) | `app/Middleware/Cors.php`, `CORS_ALLOWED_ORIGINS` |
| JSON body size limit, strict content type | `app/Middleware/JsonBody.php` |
| Rate limiting (fixed window, HMAC-keyed) — login 5/15 min per email+IP, applications 5/h per IP, booking 20/h, password reset 3/h | `app/Middleware/RateLimit.php`, `app/Security/RateLimiter.php` |
| Progressive lockout: 5 failures → 15 min lock, doubling to 24 h | `app/Services/Auth/AuthService.php` |
| Password policy: ≥ 12 chars, checked against common-password list, Argon2id (`memory_cost` 64 MB, `time_cost` 4) | `app/Security/PasswordHasher.php` |
| Sessions: 256-bit token, SHA-256 at rest, 30-min idle, 12-h absolute, rotation on login/refresh/privilege change | `app/Services/Auth/SessionService.php` |
| CSRF: synchronizer token per session, constant-time compare, required for non-GET admin calls | `app/Middleware/Csrf.php` |
| RBAC: permission slugs checked per route | `app/Middleware/Authorize.php`, `app/Security/Rbac.php` |
| Field encryption + blind index + key rotation | `app/Security/Crypto.php`, `app/Security/BlindIndex.php`, `bin/console keys:rotate` |
| HTML sanitisation of rich text (allowlist) on write | `app/Security/HtmlSanitizer.php` (HTMLPurifier) |
| Output encoding — React escapes by default; sanitized HTML rendered only from admin-authored fields | `frontend/src/components/RichText.tsx` |
| Webhook verification (Razorpay HMAC, Stripe timestamped signature) | `app/Integrations/Payments/*` |
| Audit logging (logins, permission changes, decrypt-views, approvals, refunds, settings changes) | `app/Security/AuditLogger.php` |
| Log redaction (password, token, secret, signature, card, *_enc, email, phone keys) | `app/Core/Logger.php` |
| Generic errors in production; full traces only in local | `app/Core/ErrorHandler.php` |
| Initial admin via CLI only; no default credentials | `bin/console admin:create` |

## 3. Encryption strategy

See `database.md §4`. Key material:

* `ENCRYPTION_KEYS` — comma-separated `version:base64(32 bytes)`; generated with `php bin/console keys:generate`.
* `BLIND_INDEX_KEY` — separate 32-byte key; rotating it requires `keys:reindex`.
* `APP_KEY` — HMAC key for signed URLs and rate-limit keys.
* Keys are never stored in the database or repository. In production they should come from the platform secret manager (AWS Secrets Manager, GCP Secret Manager, Doppler, Vault) and be backed up separately from database backups. **Losing the encryption keys means losing access to encrypted data.**

## 4. Security checklist

Pre-launch (each item must be ticked on staging, then production):

- [ ] `APP_ENV=production`, `APP_DEBUG=false`; boot guard passes
- [ ] TLS 1.2+ only, HSTS preload-ready, HTTP→HTTPS redirect
- [ ] `.env.*` files outside web root, mode `0600`, owned by deploy user
- [ ] Web root is `backend/public` only; `storage/` not web-accessible
- [ ] Database user has only `SELECT, INSERT, UPDATE, DELETE` on the app schema; migrations run with a separate migration user
- [ ] MySQL not exposed publicly; TLS required for remote connections
- [ ] Encryption keys generated fresh for production (never reuse staging keys) and escrowed offline
- [ ] Super admin created via `admin:create`, TOTP enabled for all admin roles
- [ ] CORS allowlist contains only production origins
- [ ] CSP reviewed — only `self`, Razorpay checkout, Stripe, Google Analytics (if consented)
- [ ] Razorpay/Stripe webhooks configured with production secrets; test event delivered and deduplicated
- [ ] Google OAuth consent screen verified; redirect URI matches production
- [ ] Backups encrypted, restore tested
- [ ] `composer audit` and `npm audit --omit=dev` clean (or risk-accepted)
- [ ] Error monitoring receives events without PII
- [ ] Penetration test / independent review completed
- [ ] Privacy policy, terms and refund policy reviewed by qualified counsel

Ongoing: monthly dependency updates, quarterly key rotation review, quarterly access review of admin roles, audit-log review for `view_confidential` events.

## 5. Critical assumptions

1. TLS terminates at a trusted reverse proxy; `TRUSTED_PROXIES` is set so client IPs are derived correctly.
2. A single practitioner calendar is the bookable resource (overlap is checked across all appointment types). Multiple practitioners would require a `practitioner_id` on slots.
3. The host clock is NTP-synchronised (TOTP, signed URLs, webhook tolerance).
4. Email is not end-to-end encrypted; emails therefore contain references and links, never confidential application content.
5. Compliance (DPDP Act 2023, GDPR/UK GDPR for EU/UK clients, UAE PDPL) is designed for but **not certified**; a qualified legal review is required before launch.
