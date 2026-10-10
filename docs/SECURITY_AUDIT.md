# Tiwana ERP security audit

## Executive Summary

The application was reviewed and hardened in place. Authentication, company isolation, private files, and the Tally host restriction were kept. The pass added response headers, a practical content security policy, TOTP two-factor authentication that does not lock existing administrators out, a stronger password rule, tighter Tally address checks, spreadsheet formula escaping, and narrower API CORS.

This is not a claim that the system cannot be compromised. Production still depends on HTTPS, a non-debug environment, a least-privilege database account, and enrollment of privileged users in two-factor authentication.

## Scope

Laravel 13 / Vue 3 / Inertia ERP in this repository only. No global WAMP, PHP, MySQL, or Node changes. No git commit and no history rewrite.

## Security Baseline

Recorded before the hardening changes. Secret values are not shown.

| Item | Value |
| --- | --- |
| Branch | `main` |
| PHP | 8.3.6 (WAMP). The default shell `php` is older and was not used. |
| Laravel | 13.34.0 |
| Composer | 2.6.5 |
| Node | 20.18.0 |
| npm | 10.9.0 |
| Database driver | mysql, host 127.0.0.1, database `tiwanaerp` |
| APP_ENV | local |
| APP_DEBUG | true |
| APP_KEY | set (not printed, not rotated) |
| SESSION_DRIVER | database |
| SESSION_LIFETIME | 120 minutes |
| CACHE_STORE | database |
| QUEUE_CONNECTION | database |
| Filesystem disk | private |
| Mail | log. A mail password is present in the untracked local `.env` only. |
| CORS before | framework default `allowed_origins` of `*` with credentials disabled |
| Guards | web session; API uses Sanctum personal access tokens |
| Middleware | web (session, CSRF, Inertia, company context), `auth`, `company`, `project.access`, `feature` |

`.env` is not tracked. `.env.example` has an empty `APP_KEY` and empty database password.

## Findings

### SEC-001 — Test service-account private key in Git history

- Severity: High
- Area: Secrets / Git
- Description: Commit `b3c6dbb708483b5ed28a6b100a0208597c0cfc89` includes `storage/framework/testing-fcm.json`. The file contains a PKCS#8 private key labelled as a test Firebase service account.
- Risk: Anyone with the repository history can read that key. If it was ever registered with Google, it can call the matching cloud project.
- Remediation: Confirm the key was never a live Google credential. If it was, revoke it in Google Cloud and replace the fixture. History rewrite only after an explicit decision. Do not force-push as part of this pass.
- Status: Open. ROTATION REQUIRED if the key was ever live. The current file was not deleted because tests read it.
- Verification: `git log` name search. The key material is intentionally omitted here.

### SEC-002 — Tally accepted link-local and mapped public addresses

- Severity: High
- Area: SSRF / Tally
- Description: The previous check rejected only addresses that PHP classifies as public. Link-local metadata addresses such as `169.254.169.254`, and IPv4-mapped forms such as `::ffff:8.8.8.8`, were not rejected. HTTP redirects were followed.
- Risk: A saved Tally host could be aimed at cloud metadata or a public host via an encoding or redirect.
- Remediation: `TallyHostGuard` allows loopback and private LAN only, rejects link-local, unspecified, and multicast ranges, unwraps IPv4-mapped IPv6, and the XML client disables redirects.
- Status: Fixed
- Verification: `tests/Feature/Security/SecurityHardeningTest.php`

### SEC-003 — Spreadsheet formula injection in exports

- Severity: Medium
- Area: Exports
- Description: User-controlled text in report and BOQ exports could begin with `=`, `+`, `-`, or `@`.
- Risk: Excel or similar applications can treat that text as a formula.
- Remediation: Non-numeric text is prefixed with a single quote. Genuine numeric values are left unchanged.
- Status: Fixed
- Verification: unit-style assertions in the security test file.

### SEC-004 — Browser security headers and CORS were implicit

- Severity: Medium
- Area: Headers / CORS
- Description: Responses did not set frame, sniffing, referrer, or permissions headers. API CORS used a wildcard origin.
- Risk: Clickjacking, MIME sniffing, and unintended cross-origin API reads.
- Remediation: `SecurityHeaders` middleware and `config/cors.php`. Origins come from `CORS_ALLOWED_ORIGINS` or `APP_URL`. Credentials stay disabled. HSTS is sent only when `APP_ENV=production` and the request is HTTPS.
- Status: Fixed
- Verification: feature test and a live `GET /login` response on `127.0.0.1:8000`.

### SEC-005 — Password rule was shorter than the privileged-account target

- Severity: Medium
- Area: Passwords
- Description: New passwords were accepted at 8 characters.
- Risk: Easier guessing of newly issued passwords.
- Remediation: `Password::defaults()` is now at least 10 characters and must contain letters and numbers. Applied to user creation, company admin creation, profile change, and reset.
- Status: Fixed
- Verification: security test plus updated existing password tests. Existing passwords are not expired.

### SEC-006 — Privileged accounts had no second factor

- Severity: Medium
- Area: Authentication
- Description: Login was password-only.
- Risk: A stolen password is enough for a super admin, company admin, director, accountant, or Tally manager.
- Remediation: TOTP enrollment on the profile, with hashed recovery codes shown only when created. Confirmed users must pass a challenge. Existing users are not locked out; privileged users see a setup prompt until they enroll.
- Status: Fixed as opt-in with a persistent prompt. Mandatory cut-over is a remaining decision so current admins are not locked out.
- Verification: security tests for challenge, single-use recovery, and throttle. Browser profile shows the setup form.

### SEC-007 — npm `concurrently` / `shell-quote` advisory

- Severity: Critical (advisory), Low (runtime exposure)
- Area: Dependencies
- Description: `npm audit` reported GHSA-pqg4-j6r4-53mv in `shell-quote` via unused devDependency `concurrently`.
- Risk: The vulnerable API is not called by the ERP. The package was only a leftover starter dependency.
- Remediation: `concurrently` was removed. `npm audit` then reported 0 vulnerabilities. `npm audit fix --force` was not used.
- Status: Fixed
- Verification: `npm audit`

### SEC-008 — Production debug and secure-cookie defaults

- Severity: High if deployed as-is
- Area: Environment
- Description: The local `.env` correctly uses `APP_ENV=local` and `APP_DEBUG=true`. Session secure cookies were unset, so a production copy could send the session cookie over HTTP.
- Risk: Stack traces and session theft if this environment file is copied to production.
- Remediation: `config/session.php` defaults the secure flag to true when `APP_ENV=production`. `php artisan security:check` reports production readiness without printing secrets. Local `.env` was not switched to production.
- Status: Guarded. Production deployment must still set the values in the checklist.
- Verification: `security:check` source and session config. Local HTTP login still works.

### SEC-009 — Node.js is below Vite’s stated engine

- Severity: Low
- Area: Tooling
- Description: Node 20.18.0. Vite 8.3.2 asks for 20.19+ or 22.12+.
- Risk: Build warnings and possible future tool failures. The production build still succeeded.
- Remediation: Upgrade Node on the build machine. Global Node was not changed.
- Status: Open, manual
- Verification: `npm run build` warning

### SEC-010 — Content security policy allows inline scripts

- Severity: Low
- Area: CSP
- Description: Ziggy’s `@routes` directive emits an inline script, and styles use inline attributes. `script-src` and `style-src` therefore include `'unsafe-inline'`. `'unsafe-eval'` is not included.
- Risk: An injected inline script would not be blocked by CSP. Vue text interpolation still encodes chat and normal fields.
- Status: Accepted for compatibility. Enforced CSP otherwise limits scripts, images, frames, and objects to the application and the font host.
- Verification: Dashboard rendered 6 ApexCharts canvases with no CSP break. Login response includes `frame-ancestors 'self'` and `object-src 'none'`.

### SEC-011 — Local database account is administrative

- Severity: Medium (production)
- Area: Database
- Description: The local application uses the WAMP administrative database login. The password in `.env` is empty.
- Risk: On a reachable MySQL port, an empty administrative password is full database control. MySQL grants were not changed.
- Remediation: Production must use a dedicated user with only the privileges the ERP needs, a strong password, and no public bind.
- Status: Open, manual. Do not apply this to the developer WAMP instance automatically.

### SEC-012 — Email verification is not required for internal accounts

- Severity: Informational
- Area: Accounts
- Description: Company admins create users and mark them verified. Forcing verification would block those internal accounts.
- Recommendation: Keep the current model unless the business wants self-service email proof. Privileged actions already require an authenticated company member.
- Status: No code change

## Controls already in place

- Passwords use Laravel’s hashed cast and `Hash::check`. Login of inactive users returns the same failure as a bad password.
- Login is limited to 5 attempts per email and IP, then a temporary message, not a permanent ban.
- Login regenerates the session. Logout invalidates it.
- CSRF stays enabled for web state changes.
- Models are guarded. User creation uses validated fields, so forged `is_super_admin` and `company_id` are ignored.
- Company and project middleware remain. Super-admin `Gate::before` does not replace document state checks inside services.
- Chat messages render as text (`{{ message.body }}`), not `v-html`.
- Uploads go through `FileTypeGuard`. Known types are checked by detected MIME and signature. Other non-executable types are allowed. Executable and double extensions are blocked. Stored names are generated. See Large file uploads below.
- Private files are served by authorized controllers.
- API routes require Sanctum except `POST /api/v1/auth/login`, which is throttled. Tokens are revoked on logout, password change, and when a user has no remaining active company membership.
- Financial report exports keep the existing permission checks; this pass did not widen them.
- `composer audit`: no advisories.
- `composer.lock` and `package-lock.json` remain.

## Large file uploads

Generic attachments accept up to 1 GiB (1,073,741,824 bytes) through chunked, resumable uploads. One HTTP request carries one chunk. The default chunk is 1 MiB so it fits a PHP `upload_max_filesize` of 2M. Production can use a 10 MiB chunk after `upload_max_filesize` is 16M and `post_max_size` is 20M (Apache `LimitRequestBody` or Nginx `client_max_body_size` must match the chunk, not the whole file). Do not set the server limit to 1G. This repository does not edit `php.ini` or the web server.

Sessions live in `upload_sessions` and are limited to the current company and the user who started them. Chunks sit on the private disk under `uploads/tmp/{uuid}/`. The finished file uses a UUID name. The original filename is metadata only. Downloads send `Content-Disposition`, `X-Content-Type-Options: nosniff`, and `Cache-Control: private, no-store`. SHA-256 is streamed. Executable extensions, double extensions such as `invoice.pdf.php`, and content that starts like PHP, ELF, or a Windows executable are refused. An unknown business extension is stored as a private binary. That is not a malware verdict.

`FileSecurityScannerInterface` currently reports `not_configured`. Install ClamAV or an equivalent scanner before treating arbitrary uploads as inspected. `uploads:cleanup` runs hourly and removes abandoned chunks. It does not delete completed files.

Logo and favicon stay at 2 MB. Spreadsheet imports stay at 10 MB because they are not streamed. Camera diary photos stay at the smaller capture cap; a large diary photo uses the chunked uploader and skips the in-memory thumbnail above 12 MB.

The upload directory must stay outside the document root, and PHP must not be allowed to execute files there.

Preview uses the same private disk and the same parent-record check. Images, PDF, text, CSV, capped spreadsheets, video, and audio stream from `/files/{source}/{id}/stream`. Office files become a private PDF only when LibreOffice is installed locally. DWG and DXF use that same authorized stream. The browser parses the bytes locally. No drawing is sent to ShareCAD, Autodesk, Google, or any other preview service. There is no public drawing URL. ZIP listings do not extract the archive. A missing office converter leaves the original downloadable and does not mark the file safe.

Optional server CAD conversion, when a job still calls it, uses a fixed argument list. LibreDWG 0.13.4 is invoked as `dwg2SVG` plus the staged path `input.dwg`, and SVG is read from stdout. The original filename is never an argument. That path is not required to view a drawing. The converter path is not shown to users.

CSP allows `'wasm-unsafe-eval'` and `worker-src 'self'` so the local CAD worker and WebAssembly can start. `frame-src` stays `'self'`. `frame-src *` and `default-src *` are not used. Drawing streams keep `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`.

**LICENSE REVIEW REQUIRED.** `@flyfish-dev/cad-viewer` 0.8.2 is AGPL-3.0-only. This is not a claim that the dependency is safe for proprietary commercial redistribution. If that license is incompatible with how the ERP is distributed, replace the viewer behind `CadFileViewer.vue` with a commercially licensed self-hosted CAD SDK. License notices in the package must stay.

## Production checklist

- `APP_ENV=production`
- `APP_DEBUG=false`
- Unique `APP_KEY` (do not reuse or rotate the current key casually)
- HTTPS, and `SESSION_SECURE_COOKIE=true` (automatic when `APP_ENV=production` and the variable is omitted)
- `CORS_ALLOWED_ORIGINS` set to the real site origin
- `TRUSTED_PROXIES` set only to the reverse proxy addresses, never `*`
- Document root is `public/`
- Directory listing off
- `storage/` and `bootstrap/cache` writable by the app user; source not world-writable
- Queue worker and scheduler running
- Database user is not an administrator, database is not on the public internet, backups are outside the web root and encrypted offsite
- Privileged users enrolled in two-factor authentication
- No `.env`, SQL dumps, or `phpinfo` under the web root
- Node used for builds upgraded to 20.19+ or 22.12+
- `php artisan security:check` returns ok in the production environment

## Verification

- Security tests: 14 in `tests/Feature/Security/SecurityHardeningTest.php`
- Large-file tests: 11 in `tests/Feature/Uploads/LargeFileUploadTest.php`, plus the BOQ import cap test
- Full suite: 608 passed, 7176 assertions, 0 failed
- `npm run build`: exit 0, Vite 8.3.2, 1028 modules transformed, built in 9.24s. Warnings: Node engine, chunk size, plugin timings. No build error.
- `npm audit`: 0 vulnerabilities. `@flyfish-dev/cad-viewer` 0.8.2 is AGPL-3.0-only. LICENSE REVIEW REQUIRED.
- Built CAD runtime assets `GET /wasm/libredwg-web.wasm` and `GET /wasm/dwg-worker.js` returned HTTP 200 from `php artisan serve`, not only the Vite dev server.
- Browser at 1366 and 390: login, dashboard charts, profile two-factor setup, company settings, chat, and Tally settings loaded. Horizontal overflow at 390px was 0. HSTS was absent on local HTTP, as intended.
