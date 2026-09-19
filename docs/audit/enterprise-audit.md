# MUTQEN Enterprise Audit

> Repository `SRS/MUTQIN (master)` at commit `37313bf` (224 commits) · audited 2026-09-14 · 21 dimensions · 310 findings · 262 adversarial verifications
>
> Interactive edition: https://claude.ai/code/artifact/41d2fb1c-d210-4708-8b50-f91079ea64af · companions: [Flutter Blueprint](flutter-blueprint.md) · [Architecture Atlas](architecture-atlas.md)

## Overall: **57 / 100** — Significant risk · average maturity L2.0 of 5

The 17 core engineering dimensions calibrate to **60**. The 4 areas the completeness critic added afterwards — [Data Privacy, Child Protection & Store Compliance](dimensions/09-data-privacy-child-protection.md) (33), [Threat Model, Permissions Matrix & Tenant-Isolation Assurance](dimensions/17-threat-model-permissions-matrix.md) (46), [Business Continuity, DR & Operational Resilience](dimensions/13-business-continuity-operations.md) (31), [Legal, IP & Open-Source Licensing](dimensions/18-legal-ip-licensing.md) (34) — are where an enterprise bar bites hardest and pull the weighted overall to **57**.

### Read this first

MUTQEN is a solo-built Laravel 11 REST API (~7,000 app LOC, MySQL) with a hand-written vanilla-JS Arabic/RTL client, serving four roles (admin, center manager, teacher, parent) for Quran-memorization centers. For a one-person project it shows unusual discipline: a dual role + Sanctum-ability authorization model that is small, correct and pinned by 178 feature tests that log in through the real login endpoint; domain rules extracted to single-source classes (a verified 114-surah juz table driving reverse-order progress, race-safe display-code reservation, guardian de-duplication with race recovery); transactions on every multi-write path; Arabic 422 messages; a coherent emerald/gold brand carried through to PDFs and a PWA manifest; and a commit history where 223 of 224 commits explain their rationale. It has also grown beyond its own documentation — parent-teacher messaging, admin user management, manager parent views, n8n digests — none of which CLAUDE.md records. When independent verifiers re-tested every medium-plus finding, the overwhelming majority of 'critical' and 'high' defects were downgraded: the code has few active bugs.

Measured against enterprise maturity level 5, the calibrated weighted score is about 60 (maturity 2-3) — the exact boundary between 'significant risk' and 'functional but needs real work'. The deficit is institutional rather than defect-driven. There is no CI, no code review, no staging, no release identity, and production is a shared cPanel host without SSH where migrations are pasted as hand-written SQL, the live database is still the demo dump with a repo-documented shared password (wipe pending), 71 commits have not shipped, no backups of a database holding minors' data exist, and the framework passed its security EOL in March 2026. Architecturally it is a thin-framework monolith: 70% of code lives in controllers, there are no API Resources, FormRequests, Policies, enums, lang files or a global exception renderer, so framework-rendered errors escape the response envelope, date-only columns serialize UTC-shifted, and list endpoints change shape on a query flag. Level 5 is not a near-term target; a realistic goal is level 3 (defined, gated, documented) within a quarter, which the existing test suite makes achievable because the regression net already exists and merely needs a pipeline around it.

The two-week Flutter priority sharpens this. A mobile client freezes whatever contract exists on the day it ships, and every current contract defect (envelope escapes, UTC dates, polymorphic pagination, web .html paths inside notifications, no push, no versioning) would become a coordinated app-store release to fix later. The sprint should therefore spend its first two to three days hardening the API contract — exception renderer, Y-m-d date casts, one paginated list shape, an exported OpenAPI file, parent/manager password self-service — and the remaining days building screens against that stabilised surface, while in parallel the owner imports the pending clean database, rotates the admin password, schedules backups, adds the privacy/terms pages the store will demand, and turns on a CI run of the existing tests. Everything else (domain layer, indexes and N+1 fixes, Laravel 12 upgrade, push notifications, staging, design-token consolidation) belongs to the weeks after the app is in testers' hands.

### How NOW / NEXT / LATER was decided

The split follows one test: would the Flutter client or the store listing freeze this into a contract, or would a public launch be embarrassed by it? Anything the mobile app will call and cache in its own code (response envelope, date formats, list shape, auth self-service, notification payload shape, an exported OpenAPI file), anything a reviewer or a parent would see on day one (privacy/terms pages, demo credentials in production, photo consent), and anything whose absence turns a routine incident into a disaster (backups, a CI run of the existing tests) is NOW and must land inside the two-week sprint, restricted to S/M-effort items so screen-building still gets most of the fortnight. NEXT covers structural debt that raises maturity from 2 to 3 without changing what the app sees: the domain/service layer, API Resources and Policies, enums, indexes and N+1 removal, the Laravel 12 upgrade, a staging environment and release tagging, hand-run SQL replaced by a real migration path, CLAUDE.md and runbook refresh, lang/ar, and the FCM device-token endpoint designed so push can be added without an app update. LATER is new capability and level-4/5 instrumentation — push delivery itself, an SMS gateway, generic audit log, queues, dark mode, design-token consolidation, frontend test tooling, analytics and DORA metrics — valuable, but none of it is cheaper to do before the app ships than after.

### Verification at a glance

- **247** findings confirmed by independent reviewers
- **0** disputed (kept; severity may be lower)
- **2** refuted and removed from the roadmap (still readable in each section)
- **61** informational (low severity, not sent to review)

## What to do now — the 2-week sprint cut

81 findings are tagged NOW, but many are the same defect seen from different angles (four auditors hit the empty exception handler, three hit the missing CI, three hit parent/manager self-service). De-duplicated and cut to what actually blocks or de-risks a four-role Flutter client, the backend/process work is 16 items — 11 of them under a day. They fit alongside app development if the backend work runs in parallel with the Flutter scaffold (see the Blueprint's day-by-day plan).

### Week 1 — make the API a contract the app can trust

| # | Work item | Why now | Effort | Owner | Source findings |
|---|---|---|---|---|---|
| 1 | **Rotate or remove the demo accounts on production and import the clean database** | The only critical security finding: the live host still runs the demo dump with a shared password that is written in a public GitHub repo. | S | ops | [Security](dimensions/01-security-auth.md#prod-demo-credentials-live) · [Engineering](dimensions/15-process-maturity.md#public-repo-exposes-hosting-details) |
| 2 | **Global JSON exception renderer + force-JSON on /api** | Today 401/404/422/429/500 fall through to Laravel defaults: English text, model class names leaked, no `success` key, and a 500 when the Accept header is missing. One `withExceptions()` block fixes all four auditors' findings. | S | backend | [API](dimensions/02-api-contract-mobile.md#framework-errors-escape-envelope) · [Backend](dimensions/03-backend-architecture.md#empty-exception-handler-leaks-and-breaks-envelope) · [Localization](dimensions/21-i18n-localization.md#no-exception-renderer-english-defaults) · [Security](dimensions/01-security-auth.md#error-envelope-and-model-name-leak) |
| 3 | **One date contract: `date:Y-m-d` casts for date-only columns, ISO-8601 for datetimes** | Date-only fields currently serialize as UTC midnight shifted to the previous day; the weekly-test edit form already silently rewinds dates by one day. | S | backend | [API](dimensions/02-api-contract-mobile.md#date-only-fields-utc-shifted) |
| 4 | **One list shape: every list endpoint returns `{data, meta}`; retire `?all=1` or give it the same shape** | The web client papers over two shapes in 13 places; a typed Dart client cannot. | M | backend | [API](dimensions/02-api-contract-mobile.md#pagination-shape-polymorphic) · [Frontend](dimensions/12-frontend-code.md#api-dual-response-shape-in-client) · [Performance](dimensions/10-performance-scale.md#unbounded-all-flag-and-fixed-page-sizes) |
| 5 | **Self-service account endpoints for every role: `GET /me`, `PUT /me/phone`, `POST /me/password`; forgot-password covers managers** | Parents and center managers cannot change or recover a password at all — a store-published app cannot ship without it. | M | backend | [Security](dimensions/01-security-auth.md#parent-manager-no-credential-self-service) · [API](dimensions/02-api-contract-mobile.md#self-service-gaps-manager-parent) · [Web](dimensions/08-web-app-ui.md#self-service-profile-missing-manager-parent) |
| 6 | **Generate and commit an OpenAPI 3.1 spec (Scramble) + envelope contract tests** | The Dart data layer, mocks and DTOs are generated from it; a test that fails when a route leaves the spec stops silent contract drift. | M | backend | [Documentation](dimensions/11-documentation.md#no-api-reference-openapi) · [API](dimensions/02-api-contract-mobile.md#no-openapi-contract) · [Testing](dimensions/04-testing-quality.md#no-api-contract-tests) |
| 7 | **Hot-path indexes + fix the weekly-report N+1** | Every dashboard filters on unindexed columns; the admin weekly report issues two queries per active student (10,001 at 5,000 students). | S | backend | [Database](dimensions/06-database-schema.md#missing-hot-path-indexes) · [Performance](dimensions/10-performance-scale.md#weekly-report-n-plus-1) · [Performance](dimensions/10-performance-scale.md#non-sargable-date-filters-and-missing-date-indexes) |
| 8 | **CI on every push (GitHub Actions: MySQL service, `php artisan test`) and branch protection on master** | 178 tests exist and nothing runs them before a deploy; with an app in the stores, an untested regression reaches every phone at once. | S | process | [Testing](dimensions/04-testing-quality.md#no-ci-quality-gate) · [DevOps](dimensions/07-devops-deploy.md#no-cicd-manual-deploy) · [Engineering](dimensions/15-process-maturity.md#no-ci-no-review-gate) |
| 9 | **Automated database backups with a tested restore** | No automated backup of a database holding minors' data; the runbook prescribes a cron the host cannot run — use cPanel's backup feature or a daily export job. | S | ops | [DevOps](dimensions/07-devops-deploy.md#no-db-backups) |

### Week 2 — mobile-specific capabilities and store readiness

| # | Work item | Why now | Effort | Owner | Source findings |
|---|---|---|---|---|---|
| 1 | **Device registration + FCM push for the existing notification types** | Notifications are database-only with 60-second web polling; a phone needs push for request approvals, memorization and test events. | M | backend | [Messaging](dimensions/19-messaging-notifications.md#no-push-no-device-tokens) · [API](dimensions/02-api-contract-mobile.md#no-push-or-device-token-endpoints) |
| 2 | **Typed notification payloads (`type`, `student_id`, `request_id`) instead of HTML page paths** | The payload's `link` is `manager/requests.html?...` — meaningless to an app that needs to deep-link. | S | backend | [Messaging](dimensions/19-messaging-notifications.md#notification-payload-web-links-not-deep-links) · [Backend](dimensions/03-backend-architecture.md#notification-links-couple-api-to-web-pages) |
| 3 | **Message ownership by `sender_id`; thread keyed by (student, teacher)** | A reassigned teacher currently inherits and can read the predecessor's private parent conversation — fix before exposing messaging on phones. | M | backend | [Messaging](dimensions/19-messaging-notifications.md#thread-mine-by-role-misattribution) · [Domain](dimensions/05-domain-rules.md#message-thread-keyed-by-student-leaks-across-teacher-change) · [Security](dimensions/01-security-auth.md#message-history-visible-to-new-teacher) |
| 4 | **Parent progress KPI (juz completed / 30, current surah) in `GET /parent/students/{id}`** | The product's headline number is never shown to parents; the app's home screen is built around it. | S | backend | [Web](dimensions/08-web-app-ui.md#parent-no-progress-kpi) · [Reports](dimensions/20-reports-pdf.md#parent-no-progress-report) |
| 5 | **Token lifecycle for phones: expiry + abilities in the login response, per-device token names, logout-all, `GET /health`, `GET /app-version`** | A 7-day hard expiry with no refresh and no force-update endpoint cannot be managed once binaries are in the wild. | M | backend | [API](dimensions/02-api-contract-mobile.md#token-lifecycle-not-mobile-shaped) · [Security](dimensions/01-security-auth.md#mobile-session-model-gaps) · [API](dimensions/02-api-contract-mobile.md#no-health-version-endpoints) |
| 6 | **Throttle the authenticated API (per-user) and disable `/public/demo-accounts` outright** | Heavy report/PDF endpoints have no limit; the demo-accounts endpoint dumps every user whenever APP_DEBUG is on. | S | backend | [Performance](dimensions/10-performance-scale.md#no-api-rate-limiting) · [DevOps](dimensions/07-devops-deploy.md#rate-limit-per-ip-only) · [Security](dimensions/01-security-auth.md#debug-gated-user-directory) |
| 7 | **Privacy policy and terms pages (Arabic), linked from the landing page and the app** | Apple and Google both require a privacy policy URL for an app handling minors' data; none exists. | S | content | [Landing](dimensions/16-web-landing.md#no-legal-pages) |

> **Deliberately deferred:** Deliberately NOT in the sprint although auditors tagged them NOW: API versioning (add `/api/v1` when the first breaking change is planned, not before), the full Resources/FormRequests refactor (L — do it context by context from week 3), staging environment (needs a host decision), OpenAPI-driven refactors beyond generation, design-token consolidation on the web (the app gets its own theme), and documentation rewrites beyond a corrected CLAUDE.md + root README.

## Scorecard

Bands: 90–100 enterprise-ready · 75–89 solid with gaps · 60–74 needs real work · 40–59 significant risk · <40 unfit. Overall = Σ(weight × calibrated score) ÷ Σ(weight).

| Dimension | Weight | Auditor | Calibrated | Band | Maturity | Findings | Confirmed | Refuted |
|---|---:|---:|---:|---|---|---:|---:|---:|
| [Security & Authentication](dimensions/01-security-auth.md) | 14% | 66 | **64** | Needs real work | L3 | 15 | 11 | 0 |
| [API Contract & Mobile Readiness](dimensions/02-api-contract-mobile.md) | 12% | 55 | **60** | Needs real work | L2 | 15 | 14 | 0 |
| [Backend Architecture & Code Quality](dimensions/03-backend-architecture.md) | 10% | 61 | **65** | Needs real work | L2 | 15 | 14 | 0 |
| [Testing & Quality Gates](dimensions/04-testing-quality.md) | 9% | 63 | **63** | Needs real work | L2 | 14 | 11 | 0 |
| [Domain Model & Business Rules](dimensions/05-domain-rules.md) | 8% | 64 | **64** | Needs real work | L3 | 15 | 13 | 0 |
| [Database Schema & Data Integrity](dimensions/06-database-schema.md) | 7% | 64 | **66** | Needs real work | L3 | 15 | 8 | 1 |
| [DevOps, Deployment & Operations](dimensions/07-devops-deploy.md) | 7% | 31 | **41** | Significant risk | L1 | 15 | 15 | 0 |
| [Web App UI/UX (role dashboards & pages)](dimensions/08-web-app-ui.md) | 6% | 63 | **63** | Needs real work | L3 | 15 | 11 | 0 |
| [Data Privacy, Child Protection & Store Compliance](dimensions/09-data-privacy-child-protection.md) | 5% | 33 | **33** | Unfit | L1 | 15 | 14 | 0 |
| [Performance & Scalability](dimensions/10-performance-scale.md) | 5% | 50 | **56** | Significant risk | L2 | 15 | 13 | 0 |
| [Documentation & Knowledge Management](dimensions/11-documentation.md) | 4% | 44 | **50** | Significant risk | L2 | 14 | 10 | 0 |
| [Frontend Code Quality (JS/HTML)](dimensions/12-frontend-code.md) | 4% | 58 | **61** | Needs real work | L2 | 15 | 12 | 0 |
| [Business Continuity, DR & Operational Resilience](dimensions/13-business-continuity-operations.md) | 3% | 31 | **31** | Unfit | L1 | 15 | 15 | 0 |
| [Design System & Brand Identity](dimensions/14-design-system.md) | 3% | 58 | **58** | Significant risk | L2 | 14 | 10 | 0 |
| [Engineering Process Maturity (CMMI lens)](dimensions/15-process-maturity.md) | 3% | 54 | **54** | Significant risk | L2 | 15 | 10 | 0 |
| [Landing Page & Public Surface](dimensions/16-web-landing.md) | 3% | 54 | **54** | Significant risk | L2 | 15 | 11 | 0 |
| [Threat Model, Permissions Matrix & Tenant-Isolation Assurance](dimensions/17-threat-model-permissions-matrix.md) | 3% | 46 | **46** | Significant risk | L2 | 15 | 15 | 0 |
| [Legal, IP & Open-Source Licensing](dimensions/18-legal-ip-licensing.md) | 2% | 34 | **34** | Unfit | L1 | 13 | 8 | 0 |
| [Messaging & Notifications](dimensions/19-messaging-notifications.md) | 2% | 52 | **57** | Significant risk | L2 | 15 | 12 | 0 |
| [Reports & PDF Subsystem](dimensions/20-reports-pdf.md) | 2% | 61 | **61** | Needs real work | L2 | 15 | 11 | 0 |
| [Localization, Arabic & Regional Rules](dimensions/21-i18n-localization.md) | 1% | 56 | **56** | Significant risk | L2 | 15 | 9 | 1 |
| **Overall** | 113% | 55 | **57** | Significant risk | L2.0 | 310 | 247 | 2 |

<details><summary>Calibration notes from the cross-dimension judge</summary>

Pattern across the audit: adversarial verification downgraded almost every critical/high to medium or low (only five highs/criticals survived system-wide: prod demo credentials [security-auth], no DB backups and Laravel 11 EOL [devops-deploy], no CI/review and manual SQL deploy [process-maturity], gallery photos of minors [web-landing]). Several auditors nevertheless left scores anchored to their pre-verification severities; the adjustments above move those dimensions (api-contract 55→60, performance 50→56, messaging 52→57, documentation 44→50, frontend-code 58→61, devops 31→41) toward the band their surviving findings support. Weighted overall shifts from 57.5 to ~59.9 — the codebase sits on the 60 boundary between 'significant risk' and 'functional but needs real work'.

Same-evidence inconsistencies harmonised: (1) The empty withExceptions() handler (verified at bootstrap/app.php:22-24) was corrected to medium in api-contract and i18n, low in backend-architecture and informational in security-auth — treated here as one medium, S-effort fix because it simultaneously breaks the envelope, leaks model names and emits English. (2) 'No CI' appears in five dimensions with corrected severities from medium to high; devops at 31 vs testing at 63 on this evidence was the largest outlier and is the main reason for the devops lift. (3) Laravel v11.51.0 past security EOL (verified in composer.lock) was corrected to medium by backend-architecture but held at high by devops, and security-auth never listed it — judged high, and security-auth trimmed two points for the omission. (4) The message-thread privacy leak (sender_role-derived 'mine') is timed 'now' by domain-rules and messaging but 'next' by security-auth — harmonised to now (confirmed bug, S effort). (5) Parent/manager credential self-service is cited in five dimensions (api, security, web-ui, landing, messaging); it is double-weighted across the audit but deliberately not deflated because it is the most-cited confirmed functional gap for a public mobile app. (6) CLAUDE.md drift is cited in six-plus dimensions; it is one afternoon of work that will lift several scores at once. (7) Test-count discrepancies (38/39/40 files, 173/178 methods) are auditor tooling noise, not disagreement. (8) The date-only UTC-shift bug was caught only by api-contract; web-app-ui and frontend-code missed it despite it being visible in teacher/weekly-tests.html — kept as a confirmed NOW item. Security-auth was the only dimension where verification confirmed the critical unchanged and it was held near its original score; documentation was lifted because it scored below process-maturity while having strictly fewer surviving high findings.

</details>

## Top risks & quick wins

### Seven risks that matter most

1. security-auth: Production still runs the 2026-09-07 demo database whose shared password is documented in the repo, and the clean-dump import (e7c50f9) is marked pending in DEPLOY_LOG.md — anyone who has read the repo can log in as admin today.
2. devops-deploy: There are no automated backups of a MySQL database holding minors' PII, no monitoring or error tracking, and what is running in production is unknowable (1 of 8 deploy entries done, 71 commits unshipped) on a cPanel host with no SSH, so a data-loss or outage event has no recovery path.
3. devops-deploy / backend-architecture: Laravel v11.51.0 is past its security-fix end-of-life (2026-03-12) with composer.lock frozen since June and no composer audit, so any framework CVE is unpatched for the mobile launch.
4. api-contract-mobile: Framework-rendered 401/404/422/429/500 responses bypass the {success,message,data,errors} envelope, five date-only columns serialize as UTC-shifted datetimes (already showing a day-off bug in teacher/weekly-tests.html), and list endpoints flip between paginator and array — a Flutter client built this fortnight will bake all three into its contract.
5. security-auth / api-contract-mobile: Parents and center managers have no password-change or recovery path in production (OTP is teacher/parent-only, has no SMS delivery, and its dev code is local-only), so a parent who loses a phone with a 7-day token cannot revoke it and a manager who forgets a password is locked out until an admin intervenes.
6. messaging-notifications / domain-rules: Message threads are keyed by student and 'mine' is derived from sender_role rather than sender_id, so a reassigned teacher inherits the previous teacher's private conversation with the parent and sees it attributed to themselves — a confirmed privacy defect in a product handling children's data.
7. web-landing: The public gallery ships byte-identical photos of identifiable minors downloaded from Facebook/Google Images/news sites with no consent or attribution record, and the product has no privacy policy or terms — a legal exposure that also blocks app-store submission of the Flutter app.

### Seven quick wins (≤ 1 day each)

1. Import the pending clean SQL dump (mutqin-clean-2026-09-11.sql) into production via phpMyAdmin and rotate the admin password — removes the only surviving critical finding in under an hour.
2. One-day API contract hardening: register a JSON exception renderer in bootstrap/app.php withExceptions() that wraps 401/403/404/405/422/429/500 in the envelope with Arabic messages and forces JSON for /api/*, and cast the five date-only Eloquent columns to 'date:Y-m-d' — closes findings in api-contract, backend-architecture, security-auth and i18n at once and fixes the visible day-off bug.
3. Add a GitHub Actions workflow that spins up a MySQL service, runs `php artisan test` and `composer audit` on every push and pull request — turns the existing 178-test suite into the project's first quality gate and protects the Flutter contract from regressions.
4. Add one migration with the hot-path indexes (attendances.date, memorizations.date and surah_name, weekly_tests.exam_date, students (center_id,is_active), users (role,is_active), notifications read_at) and rewrite ReportController::weekly's per-student loop as two GROUP BY queries — removes the confirmed N+1 and full scans before mobile traffic arrives.
5. Fix MessageController so 'mine' compares sender_id to the caller and thread visibility starts at the current teacher's assignment (or archives prior-teacher messages) — a small change that closes a confirmed cross-teacher privacy leak.
6. Schedule daily off-host database backups through cPanel's Backup Wizard (or a remote automated export) with 30-day retention and perform one documented restore into the mutqin_test database — the only protection for minors' data the project currently lacks.
7. Publish static privacy-policy and terms pages linked from the landing footer and login page, and replace or obtain written consent for the downloaded gallery photos of children — required for app-store submission and the cheapest legal exposure to remove.

## Now · Next · Later

**NOW** = must land inside the 2-week Flutter sprint. **NEXT** = weeks 3–8, while the app is in beta. **LATER** = the enterprise hardening programme. Refuted findings are excluded; _(reviewers → x)_ shows where independent reviewers rated the severity differently.

### Now — 109 findings (2-week Flutter sprint)

**Security & Authentication**

- 🔴 critical · S · [Production database still holds demo accounts with a repo-documented shared password (cleanup marked pending)](dimensions/01-security-auth.md#prod-demo-credentials-live)
- 🟠 high · M · [Parents and center managers cannot change or recover their password; parents keep the staff-chosen password forever](dimensions/01-security-auth.md#parent-manager-no-credential-self-service) _(reviewers → medium)_
- 🟡 medium · M · [Token model is not ready for multi-device mobile use: one fixed 7-day token, no per-device revocation, no logout-everywhere, no refresh](dimensions/01-security-auth.md#mobile-session-model-gaps) _(reviewers → low)_
- 🟡 medium · S · [Public /api/public/demo-accounts returns every user's name, email and role whenever APP_DEBUG is true — a gate the codebase itself documents as unsafe](dimensions/01-security-auth.md#debug-gated-user-directory)
- ⚪ low · S · [Unhandled exceptions bypass the {success,message,data,errors} contract and leak internal model class names](dimensions/01-security-auth.md#error-envelope-and-model-name-leak)

**DevOps, Deployment & Operations**

- 🔴 critical · S · [No CI/CD: tests never run in a pipeline, deploys are hand-uploaded or a manual cPanel 'Deploy HEAD', and the two documented deploy mechanisms contradict each other](dimensions/07-devops-deploy.md#no-cicd-manual-deploy) _(reviewers → medium)_
- 🔴 critical · S · [No automated database or storage backups — DEPLOYMENT.md prescribes a mysqldump cron the host cannot run](dimensions/07-devops-deploy.md#no-db-backups) _(reviewers → high)_
- 🔴 critical · S · [No monitoring, alerting, uptime checks or error tracking; production logs are error-level daily files readable only via File Manager](dimensions/07-devops-deploy.md#no-monitoring-error-tracking) _(reviewers → medium)_
- 🟠 high · M · [Only two environments (developer XAMPP and production); no staging, and the production API lives at a structure-leaking, host-layout-dependent path with no versioning](dimensions/07-devops-deploy.md#no-staging-unstable-api-host) _(reviewers → low)_
- 🟠 high · S · [What is actually running in production is unknowable: 71 commits since the only deploy marked 'done', 7/8 log entries 'pending', no version tag, no /version endpoint](dimensions/07-devops-deploy.md#release-identity-unknown) _(reviewers → medium)_
- 🟡 medium · S · [Rate limiting covers only the 3 auth endpoints, keyed per IP with no proxy trust configuration; authenticated API has no limiter](dimensions/07-devops-deploy.md#rate-limit-per-ip-only) _(reviewers → low)_
- 🟡 medium · S · [HTTPS is not enforced or asserted anywhere in the repo (no redirect, no HSTS, no forceScheme, no security headers); it depends entirely on an unverified cPanel setting](dimensions/07-devops-deploy.md#https-not-enforced-no-security-headers)

**Performance & Scalability**

- 🔴 critical · S · [Admin weekly report issues 2 queries per active student (10,001 queries at 5,000 students)](dimensions/10-performance-scale.md#weekly-report-n-plus-1) _(reviewers → medium)_
- 🟠 high · S · [20 whereMonth/whereYear + 4 whereDate filters on tables with no date index → full scans on the largest tables](dimensions/10-performance-scale.md#non-sargable-date-filters-and-missing-date-indexes) _(reviewers → low)_
- 🟠 high · S · [Authenticated API has no rate limiter — heavy report/PDF endpoints can be hammered without limit](dimensions/10-performance-scale.md#no-api-rate-limiting) _(reviewers → medium)_
- 🟡 medium · M · [`?all=1` returns entire tables with eager-loaded relations; no per_page parameter or cap; several list endpoints are unpaginated](dimensions/10-performance-scale.md#unbounded-all-flag-and-fixed-page-sizes) _(reviewers → low)_
- 🟡 medium · S · [Frequently filtered low-selectivity/lookup columns lack indexes (users.role/is_active, attendances.status/date, students composite, notifications.read_at)](dimensions/10-performance-scale.md#missing-indexes-role-status-progress) _(reviewers → low)_
- 🟡 medium · S · [Production runs without config/route cache or documented OPcache/autoloader optimization; the deploy script deletes bootstrap/cache and never regenerates it](dimensions/10-performance-scale.md#no-deploy-time-optimization) _(reviewers → low)_

**Landing Page & Public Surface**

- 🔴 critical · S · [No privacy policy or terms anywhere on the public surface (blocks app-store submission for a minors'-data product)](dimensions/16-web-landing.md#no-legal-pages) _(reviewers → medium)_
- 🟠 high · M · [Forgot-password page promises an SMS code that is never sent in production, and excludes managers/admins](dimensions/16-web-landing.md#forgot-password-dead-end) _(reviewers → medium)_
- 🟡 medium · S · [Dead public endpoint /api/public/demo-accounts still dumps every user's name+email+role whenever APP_DEBUG=true, unthrottled and untested](dimensions/16-web-landing.md#demo-accounts-endpoint-debug-gated)

**Documentation & Knowledge Management**

- 🔴 critical · L · [No machine-readable API contract (OpenAPI/Postman) for ~107 endpoints; response shapes only discoverable by reading PHP](dimensions/11-documentation.md#no-api-reference-openapi) _(reviewers → medium)_
- 🟠 high · S · [CLAUDE.md (the only architecture reference) is 60 commits stale: 20 endpoints, 8 pages, 2 controllers, 1 table missing and ≥12 statements now false](dimensions/11-documentation.md#claude-md-drift-60-commits) _(reviewers → medium)_
- 🟠 high · S · [No root README; backend/README.md is Laravel boilerplate; .env.example and the launcher .bat send a new developer down a broken path](dimensions/11-documentation.md#no-root-readme-onboarding-broken) _(reviewers → low)_
- 🟡 medium · S · [No CHANGELOG, one git tag in 224 commits, no API version or deprecation policy](dimensions/11-documentation.md#no-changelog-versioning-policy) _(reviewers → low)_
- 🟡 medium · S · [backend/.env.example contradicts the project (sqlite, English locale, no CORS origin) while .env.production.example is correct](dimensions/11-documentation.md#env-example-defaults-wrong) _(reviewers → low)_

**Messaging & Notifications**

- 🔴 critical · L · [No push channel (FCM/APNs) and no device-token storage — in-app DB channel only](dimensions/19-messaging-notifications.md#no-push-no-device-tokens) _(reviewers → medium)_
- 🟠 high · M · [OTP password reset has no delivery path in production (SMS TODO; log line below LOG_LEVEL; dev_otp local-only)](dimensions/19-messaging-notifications.md#otp-no-production-delivery) _(reviewers → medium)_
- 🟠 high · S · [Message ownership derived from sender_role, not sender_id — reassigned teacher inherits predecessor's messages as 'mine' and reads the parent's history with the former teacher](dimensions/19-messaging-notifications.md#thread-mine-by-role-misattribution) _(reviewers → medium)_
- 🟠 high · S · [Notification payload carries HTML page paths as `link` and an inconsistent `ref_id` — no deep-link contract for mobile](dimensions/19-messaging-notifications.md#notification-payload-web-links-not-deep-links) _(reviewers → low)_
- 🟡 medium · S · [Notifications capped at 30 and threads at 100 with no cursor; no unread-count endpoints; thread list unsorted](dimensions/19-messaging-notifications.md#no-pagination-no-unread-aggregates) _(reviewers → low)_

**Engineering Process Maturity (CMMI lens)**

- 🔴 critical · S · [Zero CI, zero pull requests, zero code review — every commit is a direct push to master](dimensions/15-process-maturity.md#no-ci-no-review-gate) _(reviewers → high)_
- 🔴 critical · M · [Production deploy is manual cPanel file-pasting with hand-written SQL migrations; prod is 68 commits behind master](dimensions/15-process-maturity.md#manual-sql-deploy-prod-lag) _(reviewers → high)_
- 🟠 high · S · [No releases, no CHANGELOG, no semantic versioning, one ad-hoc tag](dimensions/15-process-maturity.md#no-release-process) _(reviewers → medium)_
- 🟠 high · S · [Single developer under three git identities; issue tracker unused; two orphaned unmerged branches](dimensions/15-process-maturity.md#single-dev-three-identities-no-tracker) _(reviewers → medium)_
- 🟠 high · S · [CLAUDE.md and DEPLOYMENT.md have drifted from the code (undocumented subsystems, wrong counts, obsolete instructions)](dimensions/15-process-maturity.md#docs-drift-claude-md) _(reviewers → medium)_
- 🟠 high · S · [GitHub repository is public and commits hosting internals (cPanel user, DB name, server paths) and demo credentials](dimensions/15-process-maturity.md#public-repo-exposes-hosting-details) _(reviewers → low)_
- 🟡 medium · S · [Conventional-commit prefixes adopted only since 2026-08-22 and inconsistently (29%); mixed English/Arabic; 31% of subjects exceed 72 chars](dimensions/15-process-maturity.md#conventional-commits-partial) _(reviewers → low)_

**Data Privacy, Child Protection & Store Compliance**

- 🔴 critical · M · [No privacy notice, terms, consent capture or lawful-basis artefact exists anywhere (store submission blocker)](dimensions/09-data-privacy-child-protection.md#no-privacy-notice-consent-lawful-basis) _(reviewers → medium)_
- 🟠 high · M · ['Never hard-delete' policy has no retention schedule, no pseudonymisation path and unbounded growth tables — contradicts store data-deletion expectations and storage-limitation duties](dimensions/09-data-privacy-child-protection.md#no-erasure-or-retention-policy-never-delete) _(reviewers → medium)_
- 🟠 high · S · [Center managers can enumerate every parent's full national ID system-wide by 3-digit prefix](dimensions/09-data-privacy-child-protection.md#manager-parent-search-national-id-enumeration) _(reviewers → medium)_
- 🟠 high · M · [Guardianship is asserted by an unverified phone number typed by staff, who also choose the parent's password; a mistyped number links a child to a stranger's account](dimensions/09-data-privacy-child-protection.md#guardian-identity-unverified-phone-link-staff-set-password) _(reviewers → medium)_
- 🟠 high · S · [Landing page publishes 11 identifiable children's photos that are byte-identical raw downloads from Facebook CDN, Telegram and Google Images, with no consent, release or attribution](dimensions/09-data-privacy-child-protection.md#child-photos-scraped-without-consent-on-public-landing)
- 🟡 medium · S · [Plaintext OTP and guardian phone are written to the application log at info level](dimensions/09-data-privacy-child-protection.md#otp-and-phone-logged-plaintext)
- 🟡 medium · S · [Unauthenticated /public/demo-accounts dumps every user's name, email and role whenever APP_DEBUG is true — a weaker gate than the one the OTP code itself warns against](dimensions/09-data-privacy-child-protection.md#demo-accounts-directory-gated-on-app-debug)
- 🟡 medium · S · [The public GitHub repository discloses the production admin login, the universal password of a dataset recorded as deployed, the cPanel user, DB name and host layout](dimensions/09-data-privacy-child-protection.md#public-repo-discloses-admin-login-demo-password-hosting-metadata)

**Business Continuity, DR & Operational Resilience**

- 🔴 critical · M · [No production backup exists, is scheduled, verified, off-site or restorable; a DROP-TABLE re-import into prod is planned without a pre-import dump](dimensions/13-business-continuity-operations.md#no-backup-no-restore) _(reviewers → high)_
- 🟠 high · S · [No RTO/RPO, no business-impact analysis, no definition of tolerable outage for the centers](dimensions/13-business-continuity-operations.md#rto-rpo-undefined) _(reviewers → medium)_
- 🟠 high · M · [Bus factor = 1: single developer under three git identities, personal GitHub remote, all credentials held by one person, admin password handed over in chat, deploy artifacts on a laptop](dimensions/13-business-continuity-operations.md#bus-factor-one-credentials) _(reviewers → medium)_
- 🟠 high · S · [Nothing is monitored: no uptime check, no error tracking, no log shipping, logs kept 14 days on the box at error level only](dimensions/13-business-continuity-operations.md#no-monitoring-no-alerting)
- 🟠 high · M · [Parent (and admin) password recovery is non-functional in production: OTP is log-only at info level (suppressed by LOG_LEVEL=error), no SMS, and no admin/manager endpoint can set a parent's password](dimensions/13-business-continuity-operations.md#parent-password-recovery-dead-in-prod)
- 🟠 high · L · [No offline capability and no retry-safety: attendance pre-selects 'حاضر' and unsaved marks are lost on a failed request; memorization/test/message creates duplicate on retry; only xlsx import survives an outage](dimensions/13-business-continuity-operations.md#offline-capture-absent) _(reviewers → low)_
- 🟠 high · M · [Production runs code 68 commits behind HEAD (12+ routes, 2 migrations missing); deploy method is contradictory (manual File Manager vs .cpanel.yml git deploy); migrations are hand-applied SQL with no code/schema check and no rollback procedure](dimensions/13-business-continuity-operations.md#deploy-drift-schema-coupling) _(reviewers → medium)_
- 🟡 medium · S · [Unauthenticated `/public/stats` and `/public/demo-accounts` hit the database on every call with no cache and no rate limit; no global API limiter exists](dimensions/13-business-continuity-operations.md#public-endpoints-uncached-unthrottled) _(reviewers → low)_

**Legal, IP & Open-Source Licensing**

- 🔴 critical · S · [11 downloaded third-party photos of identifiable minors published on the landing page and in the public repo with no licence, credit or guardian consent](dimensions/18-legal-ip-licensing.md#child-photos-unlicensed-no-consent)
- 🟠 high · S · [No LICENSE or ownership statement anywhere, while composer.json and README advertise MIT inherited from the Laravel skeleton; multiple contributor identities with no assignment](dimensions/18-legal-ip-licensing.md#no-license-false-mit-declaration) _(reviewers → medium)_
- 🟠 high · M · [Zero privacy-policy / terms-of-use / consent pages for a product holding minors' identity, guardian phone, attendance and fingerprint data — a hard app-store blocker](dimensions/18-legal-ip-licensing.md#no-privacy-policy-or-terms) _(reviewers → medium)_
- 🟡 medium · S · [Public GitHub repo publishes production hosting internals (cPanel user, DB name, server layout, deploy procedure) and a personal desktop screenshot](dimensions/18-legal-ip-licensing.md#public-repo-exposes-hosting-internals)

**API Contract & Mobile Readiness**

- 🟠 high · S · [401/404/405/422/429/500 rendered by Laravel bypass the envelope; unauthenticated requests without Accept: application/json are redirected, not 401](dimensions/02-api-contract-mobile.md#framework-errors-escape-envelope) _(reviewers → medium)_
- 🟠 high · S · [Date-only columns serialize as UTC ISO datetimes shifted to the previous day; date formats are mixed across endpoints](dimensions/02-api-contract-mobile.md#date-only-fields-utc-shifted) _(reviewers → medium)_
- 🟠 high · M · [List responses switch between paginator object and plain array (?all=1), between {} and [] when empty, with hard-coded page sizes and no per_page](dimensions/02-api-contract-mobile.md#pagination-shape-polymorphic) _(reviewers → medium)_
- 🟠 high · L · [No OpenAPI/Postman/API-Resource contract artefact; CLAUDE.md is the only route documentation and has drifted from the code](dimensions/02-api-contract-mobile.md#no-openapi-contract) _(reviewers → medium)_
- 🟠 high · M · [Center managers have no forgot-password and no change-password path; parents cannot change their password while logged in (/profile is teacher-gated)](dimensions/02-api-contract-mobile.md#self-service-gaps-manager-parent) _(reviewers → medium)_
- 🟡 medium · S · [All routes live under /api with no version segment; a shipped mobile client would pin an un-versioned contract](dimensions/02-api-contract-mobile.md#no-api-versioning) _(reviewers → low)_
- 🟡 medium · M · [Login returns no expiry or abilities, no device name, no refresh or logout-all; 7-day hard expiry forces weekly re-login; bad credentials are 422](dimensions/02-api-contract-mobile.md#token-lifecycle-not-mobile-shaped) _(reviewers → low)_

**Backend Architecture & Code Quality**

- 🟠 high · L · [No API Resources/FormRequests: responses are raw Eloquent dumps mixed with ad-hoc arrays, envelope violated in 3 places](dimensions/03-backend-architecture.md#no-typed-api-contract-resources) _(reviewers → medium)_
- 🟠 high · S · [No global exception renderer: 404/500/401 fall through to Laravel defaults (English, leaks model class names, no `success` key)](dimensions/03-backend-architecture.md#empty-exception-handler-leaks-and-breaks-envelope) _(reviewers → low)_
- 🟡 medium · S · [Check-then-write invariants are not locked or idempotent: double approve creates duplicate students, two admins can create two primaries](dimensions/03-backend-architecture.md#unlocked-check-then-write-races) _(reviewers → low)_
- 🟡 medium · S · [No lang/ directory: rules without custom messages return English locally and the literal key (e.g. 'validation.email') in production where APP_LOCALE=ar](dimensions/03-backend-architecture.md#no-i18n-layer-validation-falls-back-to-english-or-raw-keys)
- 🟡 medium · S · [Notification payloads embed web-page paths ('manager/requests.html?...') instead of typed references](dimensions/03-backend-architecture.md#notification-links-couple-api-to-web-pages) _(reviewers → low)_
- 🟡 medium · S · [Unversioned, unnamed routes with manual findOrFail and inconsistent parameter naming](dimensions/03-backend-architecture.md#no-api-versioning-no-route-binding) _(reviewers → low)_
- 🟡 medium · S · [No CI, no Pint config, no static analysis, stock README/.env.example; deployment is manual rsync via .cpanel.yml](dimensions/03-backend-architecture.md#no-quality-gates-pint-phpstan-ci)

**Database Schema & Data Integrity**

- 🟠 high · S · [No non-FK indexes on the columns every dashboard, review screen and report filters/sorts on](dimensions/06-database-schema.md#missing-hot-path-indexes) _(reviewers → medium)_
- 🟡 medium · S · [student_requests.national_id is VARCHAR(12) while students.national_id was widened to VARCHAR(32) — foreign-student transfers can 500](dimensions/06-database-schema.md#request-national-id-width-mismatch) _(reviewers → low)_

**Domain Model & Business Rules**

- 🟠 high · M · [Messaging threads are keyed by student only, so a new teacher inherits (and 'owns') the previous teacher's conversation with the parent](dimensions/05-domain-rules.md#message-thread-keyed-by-student-leaks-across-teacher-change) _(reviewers → medium)_
- 🟡 medium · S · [PUT /centers/{id} accepts is_active from the client and skips the token-revocation cascade; centers have no status audit](dimensions/05-domain-rules.md#center-update-bypasses-deactivation-cascade) _(reviewers → low)_
- 🟡 medium · S · [Phone numbers are normalized on some writes but not others, and PhoneNumber never validates the Libyan shape](dimensions/05-domain-rules.md#phone-normalization-inconsistent-and-unvalidated) _(reviewers → low)_
- 🟡 medium · S · [The 'attendance percentage' has two different definitions (API vs n8n digest) and the role of 'late' is implicit](dimensions/05-domain-rules.md#attendance-rate-formula-divergence) _(reviewers → low)_

**Testing & Quality Gates**

- 🟠 high · S · [No CI pipeline anywhere; deploy is an ungated rsync](dimensions/04-testing-quality.md#no-ci-quality-gate) _(reviewers → medium)_
- 🟠 high · M · [34 of 107 API routes (31.8%) have zero test hits; 3 more are status-only](dimensions/04-testing-quality.md#routes-untested-32pct) _(reviewers → medium)_
- 🟠 high · S · [Five existing ownership/scope guards have no test — the security model's 'only guard' is missing here](dimensions/04-testing-quality.md#ownership-guards-untested) _(reviewers → medium)_
- 🟠 high · M · [Response shapes are asserted ad hoc; no schema/contract snapshot for the envelope Flutter will depend on](dimensions/04-testing-quality.md#no-api-contract-tests) _(reviewers → medium)_
- 🟡 medium · S · [Suite cannot be executed from this checkout; test-environment docs have drifted](dimensions/04-testing-quality.md#suite-not-runnable-from-clean-clone) _(reviewers → low)_

**Web App UI/UX (role dashboards & pages)**

- 🟠 high · M · [Parent pages never show memorization progress (juz X/30) — the product's headline KPI](dimensions/08-web-app-ui.md#parent-no-progress-kpi) _(reviewers → medium)_
- 🟠 high · M · [Center managers and parents have no profile page and no API to change password/phone](dimensions/08-web-app-ui.md#self-service-profile-missing-manager-parent) _(reviewers → medium)_
- 🟡 medium · S · [Secondary text, hints, table headers and bottom-nav labels fail WCAG AA contrast](dimensions/08-web-app-ui.md#contrast-below-aa)

**Design System & Brand Identity**

- 🟠 high · M · [Design tokens exist in :root but pages hardcode hex and inline styles instead](dimensions/14-design-system.md#tokens-defined-not-consumed) _(reviewers → medium)_
- 🟠 high · S · [Handoff bundle and theme.css contradict each other (and the handoff contradicts itself)](dimensions/14-design-system.md#two-sources-of-truth) _(reviewers → low)_
- 🟠 high · S · [Logo mark exists in six divergent inline variants and the SVG wordmark depends on a non-embedded font; no PNG app icons](dimensions/14-design-system.md#logo-not-an-asset) _(reviewers → medium)_
- 🟡 medium · S · [Several core tokens fail WCAG AA where they are used for text](dimensions/14-design-system.md#contrast-failures-in-tokens) _(reviewers → low)_
- 🟡 medium · S · [Fonts are a render-blocking Google Fonts @import with generic fallbacks; PDFs do not use the brand fonts](dimensions/14-design-system.md#font-delivery-and-bundling) _(reviewers → low)_

**Reports & PDF Subsystem**

- 🟠 high · S · [Admin reports exist only as PDF bytes; no JSON endpoints for center/teachers/at-risk/overview](dimensions/20-reports-pdf.md#admin-reports-pdf-only) _(reviewers → low)_
- 🟠 high · S · [Parent role has no memorization-progress report: no juz progress, no report page, no PDF](dimensions/20-reports-pdf.md#parent-no-progress-report) _(reviewers → medium)_
- 🟡 medium · S · [Only 1 of 9 PDF routes tested; teacher ownership guard on student report (JSON and PDF) has no test](dimensions/20-reports-pdf.md#report-test-coverage-thin)

**Localization, Arabic & Regional Rules**

- 🟠 high · S · [No Laravel lang/ar files while production sets APP_LOCALE=ar — uncovered validation rules render as raw 'validation.*' keys](dimensions/21-i18n-localization.md#no-lang-files-raw-keys-in-prod) _(reviewers → medium)_
- 🟠 high · S · [No API exception-rendering layer: default English 401/404/422-summary messages leak and break the {success,...} envelope](dimensions/21-i18n-localization.md#no-exception-renderer-english-defaults) _(reviewers → medium)_
- 🟠 high · M · [Arabic display strings are persisted as domain enum values (teacher type, test result) and compared as literals across both tiers](dimensions/21-i18n-localization.md#arabic-strings-as-db-enums) _(reviewers → low)_
- 🟡 medium · M · [API speaks only Arabic prose — no stable error/message codes and no Accept-Language handling; tests pin exact Arabic copy](dimensions/21-i18n-localization.md#no-error-codes-no-locale-negotiation) _(reviewers → low)_
- 🟡 medium · S · [Arabic-Indic digit normalization is partial (phone + search only) and copy-pasted 7 times; identity fields, login codes and OTP reject ٠-٩ input](dimensions/21-i18n-localization.md#arabic-indic-digits-partial) _(reviewers → low)_
- 🟡 medium · S · [In-app notifications persist Arabic prose, web .html links and server-formatted relative time — not consumable by a localized mobile client](dimensions/21-i18n-localization.md#notifications-persist-prose-and-web-links) _(reviewers → low)_

**Threat Model, Permissions Matrix & Tenant-Isolation Assurance**

- 🟠 high · M · [No threat model, risk register, abuse-case list, incident-response plan or vulnerability-disclosure channel exists](dimensions/17-threat-model-permissions-matrix.md#no-security-governance-artefacts) _(reviewers → medium)_
- 🟠 high · M · [Any center manager can enumerate every parent's national ID system-wide, then overwrite that parent's name and recovery phone and attach a child to them, receiving the full parent record](dimensions/17-threat-model-permissions-matrix.md#manager-parent-directory-enumeration-and-overwrite)
- 🟠 high · M · [Security model is prevention-only: no login audit, no failed-login counter per account, no alert on privilege changes, no actor on password-change log](dimensions/17-threat-model-permissions-matrix.md#no-security-event-logging-or-detection) _(reviewers → medium)_
- 🟠 high · M · [Login identifiers are sequential public codes, passwords are min 6 with no policy or MFA, throttling is per-IP only, and teacher tokens carry the admin wildcard ability](dimensions/17-threat-model-permissions-matrix.md#enumerable-login-ids-no-lockout-weak-passwords-wildcard-teacher-tokens) _(reviewers → medium)_
- 🟠 high · M · [Parent accounts cannot be disabled or reset by anyone; parents and managers have no in-app password/phone change; managers have no recovery path at all](dimensions/17-threat-model-permissions-matrix.md#parent-manager-account-lifecycle-gaps) _(reviewers → medium)_
- 🟡 medium · M · [Parent↔teacher message history is keyed by student only, so a reassigned or transferred student hands the whole private thread to the new teacher — including across centers](dimensions/17-threat-model-permissions-matrix.md#message-thread-inherited-on-reassignment) _(reviewers → low)_
- 🟡 medium · S · [Unauthenticated /api/public/demo-accounts dumps every user's name/email/role whenever APP_DEBUG=true — and the frontend no longer uses it](dimensions/17-threat-model-permissions-matrix.md#public-demo-accounts-orphan-endpoint)
- 🟡 medium · M · [28 of 107 routes have no test and 24 more have no negative assertion; the matrix has whole cells with no proof of denial](dimensions/17-threat-model-permissions-matrix.md#tenant-isolation-negative-test-gaps) _(reviewers → low)_

**Frontend Code Quality (JS/HTML)**

- 🟡 medium · M · [Client papers over two API list shapes (?all=1 array vs paginator) in 13 places - the contract the Flutter team will inherit](dimensions/12-frontend-code.md#api-dual-response-shape-in-client) _(reviewers → low)_

### Next — 151 findings (weeks 3–8)

**Backend Architecture & Code Quality**

- 🟠 high · L · [Business logic lives in 4,934-LOC controllers; only one service class exists](dimensions/03-backend-architecture.md#fat-controllers-no-domain-layer) _(reviewers → medium)_
- 🟠 high · M · [Laravel 11.x (v11.51.0 locked) is past end of security support; PHP pinned to 8.2](dimensions/03-backend-architecture.md#laravel-11-eol-dependency-line) _(reviewers → medium)_
- 🟠 high · M · [Ownership/scope authorization hand-rolled at 20 call sites with three different 403 shapes; no Policies](dimensions/03-backend-architecture.md#authorization-copy-pasted-no-policies) _(reviewers → medium)_
- 🟡 medium · M · [Roles, teacher type, attendance status, test result, request status are bare strings (Arabic literals included) with zero enums](dimensions/03-backend-architecture.md#magic-strings-no-enums) _(reviewers → low)_
- 🟡 medium · L · [No jobs/queues/events: xlsx import and mPDF rendering run synchronously in the request; production is QUEUE_CONNECTION=sync on shared cPanel](dimensions/03-backend-architecture.md#no-async-boundary-heavy-work-in-request) _(reviewers → low)_
- 🟡 medium · M · [Half of ReportService (and ReportController::weekly) is N+1 and loads whole months of rows into PHP memory](dimensions/03-backend-architecture.md#report-service-n-plus-one) _(reviewers → low)_
- 🟡 medium · S · [Alternate update routes bypass the audited status/identity paths (center is_active via PUT, teacher center_id & email via PUT)](dimensions/03-backend-architecture.md#parallel-write-paths-bypass-audit) _(reviewers → low)_

**Database Schema & Data Integrity**

- 🟠 high · M · [Every business invariant is enforced only in PHP; the database accepts violating rows](dimensions/06-database-schema.md#invariants-app-only) _(reviewers → medium)_
- 🟡 medium · S · [Cascade deletes remain on history and audit tables even though the product policy is 'never hard-delete'](dimensions/06-database-schema.md#cascade-deletes-contradict-no-delete-policy) _(reviewers → low)_
- 🟡 medium · M · [Audit is ad-hoc who/when columns without before-values; no generic audit trail for minors' data](dimensions/06-database-schema.md#no-audit-log-table) _(reviewers → low)_
- 🟡 medium · M · [Backup/restore is a single manual mysqldump suggestion; no retention, restore drill, or PITR](dimensions/06-database-schema.md#backup-restore-story) _(reviewers → low)_
- 🟡 medium · M · [Progress and reports are computed from raw rows in PHP; several endpoints load unbounded result sets](dimensions/06-database-schema.md#derived-state-and-volume-assumptions) _(reviewers → low)_
- 🟡 medium · S · [Moving a teacher to another center leaves their students assigned to a teacher outside the students' center](dimensions/06-database-schema.md#teacher-center-move-orphans-students) _(reviewers → low)_
- ⚪ low · S · [personal_access_tokens grows unbounded: every login inserts a token and nothing prunes expired ones](dimensions/06-database-schema.md#token-table-unbounded-growth)

**Domain Model & Business Rules**

- 🟠 high · M · [One-primary-per-center is implemented three ways, locked in only one path, with no DB constraint](dimensions/05-domain-rules.md#primary-teacher-rule-triplicated-and-racy) _(reviewers → low)_
- 🟠 high · S · [One-manager-per-center and one-primary-per-center use different active semantics; reactivation bypasses both](dimensions/05-domain-rules.md#singleton-rules-inconsistent-and-reactivation-bypass) _(reviewers → medium)_
- 🟠 high · M · [Transfer approval is not serialized, re-validates too little, has no cancel state and no decision audit](dimensions/05-domain-rules.md#transfer-approve-unlocked-underrevalidated-no-cancel) _(reviewers → medium)_
- 🟡 medium · M · [Fingerprint import silently overwrites manual and manager-corrected attendance; manual changes have no audit; no late/schedule rule](dimensions/05-domain-rules.md#attendance-source-precedence-undefined)
- 🟡 medium · L · [Domain vocabulary is string literals (incl. Arabic literals in DB enums); zero PHP enums, FormRequests or Policies](dimensions/05-domain-rules.md#no-enums-value-objects-or-request-objects) _(reviewers → low)_
- 🟡 medium · M · [students.age is a static integer that never advances; birth_date exists but is unused](dimensions/05-domain-rules.md#student-age-static-birthdate-dead) _(reviewers → low)_
- 🟡 medium · M · [Teacher center change orphans students; update paths accept client email; admin teacher deactivation ignores primary and students](dimensions/05-domain-rules.md#teacher-move-and-email-edit-break-invariants)
- 🟡 medium · M · [Memorization is the one record still hard-deleted, has no update, no audit, and unvalidated hizb/eighth/future date/inactive student](dimensions/05-domain-rules.md#memorization-hard-delete-no-update-weak-guards) _(reviewers → low)_

**DevOps, Deployment & Operations**

- 🟠 high · L · [Framework is past security EOL and dependencies are frozen: Laravel v11.51.0 (security fixes ended 2026-03-12), composer.lock unchanged since 2026-06-25, no dependency audit](dimensions/07-devops-deploy.md#laravel-11-eol-frozen-deps)
- 🟠 high · M · [Schema changes are applied as hand-written SQL in phpMyAdmin from files outside the repo; the migrations table is not maintained and there is no rollback](dimensions/07-devops-deploy.md#migrations-hand-run-sql) _(reviewers → medium)_
- 🟡 medium · M · [In-place `rsync --delete` deploy with no releases/symlink layout, no post-deploy cache rebuild, and no way to enter maintenance mode](dimensions/07-devops-deploy.md#no-rollback-no-maintenance-mode) _(reviewers → low)_
- 🟡 medium · S · [No scheduler or cron: expired Sanctum tokens, stale OTP rows and notifications are never pruned; the only recurring job is an external, demo-grade n8n workflow](dimensions/07-devops-deploy.md#no-scheduler-no-pruning) _(reviewers → low)_
- 🟡 medium · S · [Entire Laravel tree (.env, storage/logs, vendor) sits under public_html guarded only by .htaccess, and rsync ships dev artifacts (composer.phar 3.5 MB, tests/, check_excel.php, DEPLOY_LOG.md, .env.production.example) to production](dimensions/07-devops-deploy.md#laravel-tree-in-webroot-dev-artifacts-deployed) _(reviewers → low)_
- 🟡 medium · S · [Plaintext OTP and phone number are written to the application log at info level in every environment; only LOG_LEVEL=error hides it in production, contrary to the docs](dimensions/07-devops-deploy.md#otp-and-phone-logged-plaintext) _(reviewers → low)_
- 🟡 medium · S · [Static frontend has no cache-busting or caching policy, and depends on two third-party CDNs with no SRI or fallback](dimensions/07-devops-deploy.md#frontend-no-cache-busting-external-deps) _(reviewers → low)_
- 🟡 medium · S · [Operational documentation contradicts the code: CORS, API URL, demo credentials, test counts, deploy mechanism, launcher paths and the backend README are stale](dimensions/07-devops-deploy.md#ops-docs-drift)

**Performance & Scalability**

- 🟠 high · M · [PDF report data builders fan out per teacher / per student / per center and load whole month row-sets into PHP](dimensions/10-performance-scale.md#report-service-per-teacher-fanout) _(reviewers → medium)_
- 🟠 high · M · [Memorization progress is recomputed from every raw memorization row on every request (admin scope = whole system)](dimensions/10-performance-scale.md#progress-recomputed-from-raw-rows) _(reviewers → low)_
- 🟠 high · M · [Fingerprint xlsx import loads the whole sheet and runs 3–5 queries per row plus 2 per student-per-date inside one transaction](dimensions/10-performance-scale.md#xlsx-import-per-row-queries-single-transaction) _(reviewers → medium)_
- 🟡 medium · S · [No caching anywhere — dashboard counters, public stats, surah list and pick-lists are recomputed on every request](dimensions/10-performance-scale.md#zero-caching-of-dashboard-and-reference-data) _(reviewers → low)_
- 🟡 medium · S · [60-second notification polling in every tab + Sanctum last_used_at write per request + tokens never pruned or revoked on re-login](dimensions/10-performance-scale.md#polling-token-write-amplification) _(reviewers → low)_
- ⚪ low · M · [List and dashboard responses embed full related models (teacher User, Center) and there are no API Resources](dimensions/10-performance-scale.md#payload-shaping-full-models)

**Web App UI/UX (role dashboards & pages)**

- 🟠 high · M · [Accessibility is essentially unimplemented: no dialog semantics, focus management, ESC, label association, live regions or icon labels](dimensions/08-web-app-ui.md#a11y-dialogs-focus-labels) _(reviewers → medium)_
- 🟠 high · S · [Teacher memorization and weekly-tests lists silently show only the first 15 records (paginated API, no load-more)](dimensions/08-web-app-ui.md#teacher-lists-truncated-first-page) _(reviewers → medium)_
- 🟡 medium · S · [Four pages hard-code a 2-column grid outside .mq-card, causing horizontal scroll at phone widths](dimensions/08-web-app-ui.md#mobile-2col-grid-overflow)
- 🟡 medium · M · [Dashboards and detail pages render nothing until the API answers and show only a 3-second toast on failure; no skeletons anywhere](dimensions/08-web-app-ui.md#dashboards-blank-no-loading-no-retry) _(reviewers → low)_
- 🟡 medium · S · [Attendance marking pre-selects 'حاضر' for every unrecorded student with no 'not marked' state](dimensions/08-web-app-ui.md#attendance-default-present-tristate) _(reviewers → low)_
- 🟡 medium · M · [Memorization entry asks for hand-typed juz/pages, uses a 114-option plain select, and the class-wide juz progress table was removed](dimensions/08-web-app-ui.md#teacher-memorization-workflow-gaps) _(reviewers → low)_
- ⚪ low · S · [Teacher mobile bottom nav wastes a prime slot on 'ملفّي الشخصي' while memorization and tests hide behind 'المزيد'](dimensions/08-web-app-ui.md#bottom-nav-priority-teacher)
- ⚪ low · S · [Assorted consistency gaps: stat-card styles, date formats, missing confirmation on approve, double-submit on 4 forms, dual student-detail paths](dimensions/08-web-app-ui.md#inconsistent-patterns-confirmations-dates)

**Frontend Code Quality (JS/HTML)**

- 🟠 high · M · [80% of application JavaScript is inline in HTML pages (4,254 of 5,310 lines)](dimensions/12-frontend-code.md#inline-js-80-percent) _(reviewers → medium)_
- 🟠 high · L · [Core UI patterns (paged list, debounced search, modals, field helpers) are duplicated across 7-12 pages instead of living in ui.js](dimensions/12-frontend-code.md#copy-paste-page-patterns) _(reviewers → medium)_
- 🟠 high · M · [No automated verification of any kind for the frontend: no linter, no unit tests, no e2e, no build](dimensions/12-frontend-code.md#zero-tests-zero-lint-zero-build) _(reviewers → medium)_
- 🟡 medium · S · [Bootstrap 5.3.3 RTL CSS loaded on all 33 pages with zero usage and no SRI; fonts pulled via render-blocking CSS @import from Google](dimensions/12-frontend-code.md#cdn-no-sri-dead-bootstrap-google-fonts) _(reviewers → low)_
- 🟡 medium · S · [No cache-busting on 161 script tags / 33 stylesheet links - rsync deploys can pair new HTML with stale shared JS](dimensions/12-frontend-code.md#no-cache-busting-on-assets) _(reviewers → low)_
- 🟡 medium · S · [api.js has no timeout, no retry, undifferentiated 403/419/429/5xx handling, 401 redirect races the throw, and openPdf bypasses the wrapper](dimensions/12-frontend-code.md#api-wrapper-missing-resilience) _(reviewers → low)_
- 🟡 medium · M · [7-day Bearer token in localStorage, with inline scripts/842 inline styles making a mitigating CSP impossible and no refresh/rotation](dimensions/12-frontend-code.md#token-localstorage-no-csp-no-refresh) _(reviewers → low)_
- 🟡 medium · S · [No global error handler or client telemetry - frontend failures in production are invisible](dimensions/12-frontend-code.md#no-client-error-capture) _(reviewers → low)_
- 🟡 medium · S · [API base URL chosen by hostname sniff with a hardcoded production path; no staging/env injection; README documents a stale constant](dimensions/12-frontend-code.md#config-by-hostname-sniff) _(reviewers → low)_
- ⚪ low · S · [Residual output-handling inconsistencies: one unescaped innerHTML of server text, 5 double-escape sites, unencoded id in one API path, error keys used raw in selectors](dimensions/12-frontend-code.md#residual-escaping-inconsistencies)
- ⚪ low · S · [Dead UI and stale documentation: unwired 'remember me', removed demo-accounts panel still documented, README/CLAUDE.md/launcher describe a client that no longer exists](dimensions/12-frontend-code.md#dead-ui-and-frontend-doc-drift)

**Landing Page & Public Surface**

- 🟠 high · M · [Landing has no lead-capture path: only 'login' CTAs, contact is plain text, and the footer brands the platform as a single center](dimensions/16-web-landing.md#no-acquisition-cta) _(reviewers → low)_
- 🟠 high · S · [Gallery photos of identifiable minors are byte-identical copies of files downloaded from Facebook/Google Images/news sites with no attribution or consent record](dimensions/16-web-landing.md#gallery-photos-provenance)
- 🟡 medium · S · [233 KB Bootstrap RTL CSS is render-blocking on all three public pages yet no Bootstrap class is used; fonts load through a serial @import with no preconnect or SRI](dimensions/16-web-landing.md#unused-bootstrap-and-font-chain) _(reviewers → low)_
- 🟡 medium · S · [1.08 MB of unoptimized JPEGs; largest is 2560x1440 served into a 751x235 slot; no srcset/WebP; one image is upscaled](dimensions/16-web-landing.md#gallery-images-unoptimized) _(reviewers → low)_
- 🟡 medium · S · [No Open Graph/Twitter cards, canonical, favicon, robots.txt, sitemap or structured data; heading levels skip](dimensions/16-web-landing.md#seo-social-meta-missing) _(reviewers → low)_
- 🟡 medium · S · [PWA/home-screen icon set is SVG-only with a webfont-dependent wordmark; iOS and Android install paths degrade](dimensions/16-web-landing.md#pwa-manifest-icons) _(reviewers → low)_
- 🟡 medium · S · [On phones the login form sits below a full-height brand panel, and the nav login pill wraps to two lines](dimensions/16-web-landing.md#login-mobile-layout) _(reviewers → low)_
- 🟡 medium · S · [No .htaccess for the static root: no HSTS/CSP/X-Frame-Options, no caching, and README.md with stale credentials is deployed to public_html](dimensions/16-web-landing.md#static-host-no-headers-readme-exposed) _(reviewers → low)_
- ⚪ low · S · ['تذكّرني' checkbox is decorative — never read; the token is always persisted in localStorage](dimensions/16-web-landing.md#remember-me-dead-control)
- ⚪ low · S · [Accessibility gaps on the three public pages: unlabeled inputs on forgot-password, missing autocomplete hints, emoji-only toggle, alerts without role](dimensions/16-web-landing.md#public-forms-a11y-gaps)
- ⚪ low · S · [Docs, screenshots and the content guide describe a login demo panel, Blade pages and CORS settings that no longer exist](dimensions/16-web-landing.md#doc-drift-public-surface)

**Documentation & Knowledge Management**

- 🟠 high · S · [DEPLOY_LOG.md promises an entry per commit but has none for the last 18 commits, 6/8 entries are 'pending', and its 'manual-only deploy' premise is contradicted by .cpanel.yml](dimensions/11-documentation.md#deploy-runbook-abandoned-and-contradicted) _(reviewers → medium)_
- 🟡 medium · S · [Blade-era and three-role documents are still shipped as if current (page guide, frontend README, 36 screenshots)](dimensions/11-documentation.md#obsolete-docs-not-retired) _(reviewers → low)_
- 🟡 medium · M · [Architecture decisions live only as 18 scattered inline 'قرار معتمد' comments; some comments contradict each other](dimensions/11-documentation.md#no-adr-log-inline-decisions-contradict) _(reviewers → low)_
- 🟡 medium · M · [No ERD or data dictionary; schema semantics (vestigial/unreliable/overlapping columns) exist only as prose in CLAUDE.md](dimensions/11-documentation.md#no-erd-data-dictionary) _(reviewers → low)_
- 🟡 medium · M · [No end-user documentation for any of the four roles (manager onboarding, teacher workflows, parent app help, fingerprint-import guide)](dimensions/11-documentation.md#no-user-manuals-per-role) _(reviewers → low)_
- ⚪ low · S · [No CONTRIBUTING.md, PR template, CODEOWNERS or CI — nothing defines how tests, style and docs are kept green](dimensions/11-documentation.md#no-contributing-ci-docs-gate)

**Reports & PDF Subsystem**

- 🟠 high · M · [Attendance %, teacher counts and 'late' semantics are computed differently across report paths](dimensions/20-reports-pdf.md#metric-definitions-inconsistent) _(reviewers → medium)_
- 🟡 medium · S · [Student PDF fetches memorizations but never renders them; no juz progress in any PDF](dimensions/20-reports-pdf.md#student-pdf-omits-memorization)
- 🟡 medium · M · [Four older report paths still issue per-row queries (N+1) despite fixes elsewhere](dimensions/20-reports-pdf.md#n-plus-one-legacy-paths) _(reviewers → low)_
- 🟡 medium · S · [PDFs are Bearer-gated inline responses with no signed/shareable URL; web opener is popup-blocked on iOS](dimensions/20-reports-pdf.md#pdf-mobile-consumption) _(reviewers → low)_
- 🟡 medium · M · [At-risk definition ignores memorization stagnation and unrecorded students; thresholds are global constants](dimensions/20-reports-pdf.md#at-risk-definition-narrow) _(reviewers → low)_
- 🟡 medium · S · [Teacher performance ranking uses a hidden composite formula and includes inactive teachers](dimensions/20-reports-pdf.md#teacher-ranking-formula-undocumented) _(reviewers → low)_
- 🟡 medium · M · [Report aggregations are re-implemented in four controllers outside ReportService](dimensions/20-reports-pdf.md#aggregation-duplicated-outside-service) _(reviewers → low)_
- ⚪ low · S · [Five report JSON endpoints have no UI consumer and two return raw Eloquent models](dimensions/20-reports-pdf.md#orphan-and-unstable-json-report-endpoints)
- ⚪ low · S · [month/year query parameters are cast but never validated; label and data can disagree](dimensions/20-reports-pdf.md#no-period-validation)

**Data Privacy, Child Protection & Store Compliance**

- 🟠 high · M · [No named data owner/DPO, no DPIA/record of processing, no breach-notification or staff-confidentiality procedure for a register of minors](dimensions/09-data-privacy-child-protection.md#no-governance-owner-dpia-breach-procedure) _(reviewers → medium)_
- 🟡 medium · M · [Six external processors/data flows carry children's or guardians' data with no inventory, contract or documented safeguards](dimensions/09-data-privacy-child-protection.md#no-processor-inventory-or-agreements) _(reviewers → low)_
- 🟡 medium · S · [Parent-teacher message threads are keyed only by student, so a newly assigned teacher (or a re-linked guardian) reads the entire prior private conversation](dimensions/09-data-privacy-child-protection.md#message-thread-inherited-by-successor-teacher-or-parent)
- 🟡 medium · M · [No audit trail of who viewed, searched or exported a child's record; PDFs carry no confidentiality marking](dimensions/09-data-privacy-child-protection.md#no-access-audit-trail-for-child-records-and-exports)
- 🟡 medium · M · [Raw Eloquent models leak fields beyond each role's need: User::$hidden omits id_number/password metadata, teachers receive the child's national ID, managers see full guardian IDs, parents receive teacher notes not shown in their UI](dimensions/09-data-privacy-child-protection.md#over-broad-model-serialization-and-role-minimisation-gaps)
- 🟡 medium · L · [National IDs, phones and message bodies sit in plaintext on a shared cPanel MySQL with unencrypted mysqldump backups copied to a developer laptop](dimensions/09-data-privacy-child-protection.md#plaintext-identifiers-shared-hosting-unencrypted-backups) _(reviewers → low)_
- ⚪ low · S · [Google Fonts and jsDelivr receive IP/UA/referrer for every parent, teacher and manager page view; no SRI, CSP or referrer policy](dimensions/09-data-privacy-child-protection.md#third-party-cdn-fonts-leak-parent-browsing)

**Business Continuity, DR & Operational Resilience**

- 🟠 high · L · [One shared cPanel account (API + static client + .env + logs) and one MySQL instance with SSH disabled; no staging, no standby, no rebuild procedure](dimensions/13-business-continuity-operations.md#single-host-spof-no-dr) _(reviewers → medium)_
- 🟠 high · M · [Laravel v11.51.0 is six months past its security-fix end (2026-03-12) and the host cannot run composer, with no documented vendor-upgrade procedure](dimensions/13-business-continuity-operations.md#laravel-11-past-security-eol) _(reviewers → medium)_
- 🟡 medium · S · [The only automated operational alert (daily attendance digest) is an n8n workflow meant to run on a personal machine with the admin password in a Set node, creating a new admin token every day and failing silently](dimensions/13-business-continuity-operations.md#n8n-digest-laptop-spof) _(reviewers → low)_
- 🟡 medium · S · [No scheduled maintenance at all: expired Sanctum tokens, OTP rows, notifications, messages and file-cache/throttle files grow forever; no cron is configured on the host](dimensions/13-business-continuity-operations.md#no-scheduler-no-pruning) _(reviewers → low)_
- 🟡 medium · M · [Re-importing an xlsx silently overwrites manager corrections (leaving a stale corrected_by audit) and a bad import cannot be reverted as a batch — recovery is one manual click per record](dimensions/13-business-continuity-operations.md#import-overwrites-corrections-no-batch-undo)
- 🟡 medium · M · [No runbooks for recurring operator tasks, no support/on-call path, no per-role onboarding; backend README is stock Laravel and the frontend README/page guide are stale](dimensions/13-business-continuity-operations.md#no-runbooks-no-support-path)
- 🟡 medium · M · [No capacity or cost model: host limits undocumented, no load numbers, and the N+1 weekly report puts a measurable ceiling on the shared host](dimensions/13-business-continuity-operations.md#no-cost-capacity-model) _(reviewers → low)_

**Legal, IP & Open-Source Licensing**

- 🟠 high · M · [mPDF (GPL-2.0-only) is a hard in-process dependency of nine PDF routes; the combined bundle has already been conveyed to the client's hosting account under contradictory MIT/proprietary signals](dimensions/18-legal-ip-licensing.md#mpdf-gpl2-copyleft-in-process) _(reviewers → low)_
- 🟡 medium · M · [The mark مُتقِن is spelled four ways in Latin, attributed to a single center in the footer, implies Awqaf supervision, and has no owner, notice or registration](dimensions/18-legal-ip-licensing.md#brand-trademark-ownership-unclear) _(reviewers → low)_
- 🟡 medium · S · [No THIRD-PARTY-NOTICES, SBOM or licence gate; PDF font actually embedded for Arabic is chosen at runtime by mPDF and its licence is not on record](dimensions/18-legal-ip-licensing.md#no-sbom-third-party-notices) _(reviewers → low)_
- 🟡 medium · S · [223 of 224 commits are AI co-authored and the design system is a Claude Design export, but no authorship/ownership policy or account-holder record exists](dimensions/18-legal-ip-licensing.md#ai-assisted-authorship-undocumented) _(reviewers → low)_
- ⚪ low · S · [Brand fonts are hot-linked from Google Fonts with no self-hosted copies or OFL text; the logo icon relies on live Amiri text](dimensions/18-legal-ip-licensing.md#fonts-hotlinked-no-ofl-notice)

**API Contract & Mobile Readiness**

- 🟡 medium · L · [Notifications are database-only with 60s web polling; no FCM/APNs device registration, no unread badge push, notification links are web HTML paths and ref_id is not exposed](dimensions/02-api-contract-mobile.md#no-push-or-device-token-endpoints) _(reviewers → low)_
- 🟡 medium · M · [Errors carry only Arabic human strings — no stable machine-readable code; business-rule failures reuse 422/403/404 inconsistently](dimensions/02-api-contract-mobile.md#errors-arabic-strings-only) _(reviewers → low)_
- 🟡 medium · M · [Several endpoints return the whole table (or all rows for admin) with no cap, and one report is N+1 per student](dimensions/02-api-contract-mobile.md#unbounded-list-payloads) _(reviewers → low)_
- 🟡 medium · L · [Raw Eloquent models are returned as `data`, leaking schema columns and PII and making the same entity appear with different shapes per endpoint](dimensions/02-api-contract-mobile.md#raw-eloquent-serialization) _(reviewers → low)_
- 🟡 medium · S · [No lang/ar directory: any rule without a hand-written message falls back to English; no Accept-Language handling](dimensions/02-api-contract-mobile.md#validation-messages-mixed-language)
- 🟡 medium · S · [No JSON health check with DB probe, no app-version/force-update or server-time endpoint](dimensions/02-api-contract-mobile.md#no-health-version-endpoints) _(reviewers → low)_
- 🟡 medium · M · [Create endpoints other than attendance have no idempotency key or natural uniqueness, so mobile retries duplicate records](dimensions/02-api-contract-mobile.md#write-idempotency-missing) _(reviewers → low)_

**Security & Authentication**

- 🟡 medium · S · [OTP codes and phone numbers are written in plaintext to the application log](dimensions/01-security-auth.md#otp-plaintext-in-logs)
- 🟡 medium · M · [6-character minimum with no complexity/breach check, on predictable login identifiers, with IP-only throttle and no per-account lockout](dimensions/01-security-auth.md#weak-password-policy-predictable-ids)
- 🟡 medium · S · [No security headers anywhere (CSP, HSTS, X-Frame-Options, nosniff, Referrer-Policy) and bearer token lives in localStorage](dimensions/01-security-auth.md#no-security-headers)
- 🟡 medium · L · [No general audit log: who created/edited students, teachers, centers, memorizations, or who logged in from where, is not recorded](dimensions/01-security-auth.md#no-audit-trail-or-login-audit)
- 🟡 medium · S · [Any center manager can enumerate every parent's national ID number system-wide via a 3-digit prefix search](dimensions/01-security-auth.md#manager-cross-center-parent-national-id-enumeration) _(reviewers → low)_
- 🟡 medium · M · [Parent↔teacher message threads are keyed by student only, so a newly assigned teacher inherits the full private history with the previous teacher](dimensions/01-security-auth.md#message-history-visible-to-new-teacher)
- 🟡 medium · M · [No CI pipeline, no composer audit, no static analysis — dependency and regression safety rely on a developer remembering to run tests locally](dimensions/01-security-auth.md#no-ci-dependency-audit)
- ⚪ low · S · [Several endpoints pass unvalidated fields straight to the database or to Carbon, turning bad input into 500s instead of 422s](dimensions/01-security-auth.md#unvalidated-inputs-cause-500s)

**Testing & Quality Gates**

- 🟡 medium · S · [8 of 9 PDF report endpoints untested (mPDF + gd extension dependency)](dimensions/04-testing-quality.md#pdf-endpoints-untested) _(reviewers → low)_
- 🟡 medium · M · [No static analysis (phpstan/larastan) and Pint is installed but unconfigured and never run](dimensions/04-testing-quality.md#no-static-analysis-or-lint-gate)
- 🟡 medium · M · [Only one real unit-test file; core Support classes rely solely on slow HTTP feature tests or have no tests](dimensions/04-testing-quality.md#unit-test-scarcity) _(reviewers → low)_
- 🟡 medium · S · [No line/branch coverage is measured or thresholded; no mutation testing](dimensions/04-testing-quality.md#no-coverage-measurement) _(reviewers → low)_
- 🟡 medium · S · [Saturday→Friday week logic and /reports/weekly have no tests](dimensions/04-testing-quality.md#week-boundary-and-weekly-report-untested) _(reviewers → low)_

**Design System & Brand Identity**

- 🟡 medium · M · [No dark theme or colour-scheme handling anywhere](dimensions/14-design-system.md#no-dark-mode) _(reviewers → low)_
- 🟡 medium · S · [Bootstrap 5 RTL CSS is loaded on all 33 pages but no Bootstrap class is used; docs still call it a Bootstrap client](dimensions/14-design-system.md#bootstrap-dead-weight-doc-drift) _(reviewers → low)_
- 🟡 medium · M · [No typographic, spacing, radius, elevation, motion or z-index scales -- values are ad hoc](dimensions/14-design-system.md#no-type-space-radius-scales) _(reviewers → low)_
- 🟡 medium · M · [Components specified in the handoff are missing from theme.css; dialogs and alerts are unbranded or inline](dimensions/14-design-system.md#component-gaps-vs-handoff) _(reviewers → low)_
- 🟡 medium · M · [Iconography is split between a 20-glyph SVG line set and 129 emoji used as icons](dimensions/14-design-system.md#iconography-emoji-vs-svg) _(reviewers → low)_
- ⚪ low · S · [theme.css carries two overlapping token namespaces with conflicting 'muted'/'ink'/'shadow' values](dimensions/14-design-system.md#duplicate-token-namespaces)
- ⚪ low · S · [No in-repo design documentation, usage rules or component catalogue; CLAUDE.md is a one-liner and the handoff README is agent-oriented](dimensions/14-design-system.md#no-design-documentation)
- ⚪ low · S · [Icon-only controls lack accessible names; ARIA is nearly absent](dimensions/14-design-system.md#a11y-semantics-in-components)

**Messaging & Notifications**

- 🟡 medium · S · [Opening a thread (GET) bulk-marks all incoming messages as read — no explicit read endpoint, no per-message receipts](dimensions/19-messaging-notifications.md#read-marking-on-get-side-effect) _(reviewers → low)_
- 🟡 medium · M · [60-second unconditional polling for the bell; open message threads never refresh; no visibility/ETag optimisation](dimensions/19-messaging-notifications.md#polling-only-no-realtime-no-backoff) _(reviewers → low)_
- 🟡 medium · M · [No per-user rate limit on sending, no report/block, no manager oversight — spam amplifies into notifications](dimensions/19-messaging-notifications.md#no-anti-abuse-rate-limit-moderation)
- 🟡 medium · L · [Communication matrix is parent↔teacher per student only — no manager↔parent/teacher channel and no center announcements](dimensions/19-messaging-notifications.md#no-manager-channel-no-broadcast) _(reviewers → low)_
- 🟡 medium · M · [Catalogue misses the highest-value parent events: absence/late, teacher change, transfer of own child, student deactivation, test update](dimensions/19-messaging-notifications.md#notification-catalogue-gaps) _(reviewers → low)_
- 🟡 medium · S · [sendSafe swallows all Throwables and logs at warning — below production LOG_LEVEL=error, so notification loss is truly silent](dimensions/19-messaging-notifications.md#sendsafe-silent-loss-in-production) _(reviewers → low)_
- 🟡 medium · S · [No tests for parent-facing notification types, message_received, GET /notifications or read-all; docs omit messaging entirely](dimensions/19-messaging-notifications.md#test-coverage-gaps-and-doc-drift) _(reviewers → low)_

**Engineering Process Maturity (CMMI lens)**

- 🟡 medium · S · [15 MiB of tracked files: composer.phar, orphaned screenshots, duplicated photos, a design handoff bundle and stray dev scripts](dimensions/15-process-maturity.md#repo-hygiene-binaries) _(reviewers → low)_
- 🟡 medium · S · [.cpanel.yml rsyncs tests, dev scripts, composer.phar and DEPLOY_LOG into the production webroot](dimensions/15-process-maturity.md#cpanel-deploy-ships-dev-artifacts) _(reviewers → low)_
- 🟡 medium · M · [Zero automated tests for 5,248 lines of frontend JS; no static analysis or formatter enforced for PHP](dimensions/15-process-maturity.md#no-frontend-tests-no-static-analysis)
- ⚪ low · S · [Root-level launcher and Arabic page guide still describe the retired Blade application](dimensions/15-process-maturity.md#stale-root-artifacts-blade-era)
- ⚪ low · S · [No root README; backend/README.md is the untouched Laravel boilerplate](dimensions/15-process-maturity.md#no-root-readme-onboarding)

**Localization, Arabic & Regional Rules**

- 🟡 medium · L · [Web client hardcodes ~1,660 Arabic literals/text nodes across 33 pages with no string table, glossary or i18n scaffolding](dimensions/21-i18n-localization.md#frontend-no-string-table) _(reviewers → low)_
- 🟡 medium · S · [PDF reports use DejaVu Sans for Arabic (not the Amiri/Cairo brand fonts) and the generic Carbon 'ar' locale instead of 'ar_LY'](dimensions/21-i18n-localization.md#pdf-fonts-and-carbon-ar-locale) _(reviewers → low)_
- ⚪ low · S · [Regional constants (week start, day/month names, phone country code, ID regex) are scattered literals rather than one configuration](dimensions/21-i18n-localization.md#regional-rules-scattered)
- ⚪ low · S · [Fingerprint import contract is bound to exact Arabic header/status words and assumes DD/MM/YYYY without validating the parsed date](dimensions/21-i18n-localization.md#import-contract-locale-bound)

**Threat Model, Permissions Matrix & Tenant-Isolation Assurance**

- 🟡 medium · S · [Fingerprint xlsx import echoes the name and code of students from other centers, letting any teacher or manager dump the system-wide roster](dimensions/17-threat-model-permissions-matrix.md#xlsx-import-cross-center-name-oracle)
- 🟡 medium · M · [n8n digest logs in as the human admin with a plaintext password, holds a 7-day '*' token it never revokes, and exports minors' national IDs and guardian phones daily](dimensions/17-threat-model-permissions-matrix.md#automation-uses-human-admin-wildcard-token) _(reviewers → low)_
- 🟡 medium · S · [A center manager can silently set any of their teachers' passwords and free-form emails, then read parent↔teacher messages as that teacher; the audit log records it as 'admin'](dimensions/17-threat-model-permissions-matrix.md#manager-teacher-password-reset-no-actor-audit) _(reviewers → low)_
- 🟡 medium · S · [Weekly-test edits are owned by the authoring teacher (not the current one) so a former teacher — even in another center after transfer — can rewrite results; memorization hard-deletes and grade rewrites leave no audit](dimensions/17-threat-model-permissions-matrix.md#weekly-test-and-memorization-write-ownership-drift-no-grade-audit) _(reviewers → low)_
- 🟡 medium · S · [Generic admin PUTs bypass the deactivation and tenant invariants: PUT /centers/{id} flips is_active without revoking member tokens, and moving a teacher to another center leaves their students (and message threads) behind](dimensions/17-threat-model-permissions-matrix.md#admin-update-paths-bypass-lifecycle-invariants) _(reviewers → low)_
- 🟡 medium · S · [Phone is the single recovery factor but is changeable without re-authentication or verification, and the plaintext OTP is written to the log unconditionally](dimensions/17-threat-model-permissions-matrix.md#otp-recovery-path-weaknesses) _(reviewers → low)_
- 🟡 medium · M · [Exactly one super-admin account, created only by seeder, with no MFA, no OTP recovery, no second admin path and its initial password handed over in chat](dimensions/17-threat-model-permissions-matrix.md#single-admin-no-mfa-no-break-glass)

### Later — 48 findings (enterprise programme)

**Domain Model & Business Rules**

- 🟡 medium · M · [Weekly-test questions store the thumn as free text, not an athman reference; athman index has 477 of 480 rows](dimensions/05-domain-rules.md#weekly-test-thumn-free-text-athman-incomplete) _(reviewers → low)_
- ⚪ low · S · [Legacy 'add' requests with a new guardian can no longer be approved (ParentResolver now requires a password)](dimensions/05-domain-rules.md#legacy-add-approval-dead-end)
- ⚪ low · S · [Juz completeness counts only surahs that *start* in the juz; portions extending in from the previous surah are ignored](dimensions/05-domain-rules.md#juz-completeness-starting-surah-semantics)

**Testing & Quality Gates**

- 🟡 medium · L · [Zero automated tests for the 33-page web client; QA is manual screenshots](dimensions/04-testing-quality.md#no-frontend-or-e2e-tests)
- ⚪ low · S · [Several tests depend on wall-clock date, MySQL strict mode, or the login throttle](dimensions/04-testing-quality.md#flaky-tolerances-and-env-coupling)
- ⚪ low · S · [Only a boilerplate UserFactory (unused); CreatesCoreData is the de-facto builder and generates non-Libyan phone numbers](dimensions/04-testing-quality.md#factories-absent-builder-quirks)
- ⚪ low · M · [DisplayCode atomicity and PrimaryTeacherRule lock are only tested sequentially](dimensions/04-testing-quality.md#concurrency-only-sequential)

**Performance & Scalability**

- 🟡 medium · M · [PDF generation is synchronous mPDF inside the request; no queue worker exists](dimensions/10-performance-scale.md#synchronous-mpdf-no-queue) _(reviewers → low)_
- 🟡 medium · M · [Unified Arabic search wraps every row in 9 nested REPLACE() calls across 4 columns + 2 correlated subqueries, then paginate() repeats it for COUNT(*)](dimensions/10-performance-scale.md#normalized-like-search-full-scan) _(reviewers → low)_
- ⚪ low · S · [Frontend depends on jsDelivr + Google Fonts via CSS @import, ships un-versioned assets, and has no cache/compression headers or optimized gallery images](dimensions/10-performance-scale.md#frontend-delivery-third-party-and-cache-headers)

**Web App UI/UX (role dashboards & pages)**

- 🟡 medium · L · [Nine hand-rolled overlay modals and 661 inline style attributes duplicate the shared UI layer](dimensions/08-web-app-ui.md#duplicated-modals-inline-styles) _(reviewers → low)_
- 🟡 medium · M · [Admin has no student detail view and no in-page analytics — only PDFs; manager PDFs are locked to the current month](dimensions/08-web-app-ui.md#admin-cannot-drill-into-student-reports-pdf-only) _(reviewers → low)_
- ⚪ low · M · [No sortable columns; five pages search client-side over unpaginated or partial lists; admin students lacks center/teacher filters](dimensions/08-web-app-ui.md#no-sorting-client-search-filters)
- ⚪ low · M · [Bootstrap RTL CSS is loaded on all 30 pages but unused, fonts are @import-blocked, and the PWA has no offline shell](dimensions/08-web-app-ui.md#perf-unused-bootstrap-fonts-no-offline)

**Frontend Code Quality (JS/HTML)**

- 🟡 medium · M · [842 inline style attributes and 529 hardcoded hex colours bypass the 23 design tokens defined in theme.css](dimensions/12-frontend-code.md#inline-styles-bypass-tokens) _(reviewers → low)_
- 🟡 medium · M · [Accessibility is skin-deep: 4 of 68 labels bound with for=, no roles/aria-modal/focus trap on custom modals, 63 buttons without type](dimensions/12-frontend-code.md#a11y-gaps-in-dynamic-ui) _(reviewers → low)_
- ⚪ low · S · [PWA is a manifest only: no service worker, SVG-only icon (no PNG 192/512, invalid apple-touch-icon), start_url login.html](dimensions/12-frontend-code.md#pwa-manifest-without-installability-or-offline)

**Reports & PDF Subsystem**

- 🟡 medium · L · [PDF generation is synchronous per request; no caching, queue, or scheduler exists](dimensions/20-reports-pdf.md#sync-pdf-no-cache-no-queue) _(reviewers → low)_
- ⚪ low · M · [No xlsx/csv export and no in-app scheduled/e-mailed reports; only an external n8n digest with a divergent formula](dimensions/20-reports-pdf.md#no-export-no-scheduling)
- ⚪ low · S · [PDFs use mPDF's bundled fallback font instead of brand fonts and have no page numbers or repeating header](dimensions/20-reports-pdf.md#pdf-typography-pagination)

**Localization, Arabic & Regional Rules**

- 🟡 medium · S · [Count phrases use a single Arabic form regardless of number (Arabic has 6 CLDR plural categories)](dimensions/21-i18n-localization.md#naive-arabic-pluralization) _(reviewers → low)_
- ⚪ low · M · [No Hijri (Umm al-Qura) calendar anywhere for a Quran-memorization product](dimensions/21-i18n-localization.md#no-hijri-calendar)
- ⚪ low · S · [RTL/bidi handled via 33 inline `direction:ltr` duplications and physical CSS properties; brand fonts loaded by render-blocking external @import](dimensions/21-i18n-localization.md#rtl-css-inline-duplication-and-fonts)
- ⚪ low · S · [Parent-facing copy assumes a son ('ابنك') while the ID rule accepts girls; CLAUDE.md misstates the gender constraint and the email scheme](dimensions/21-i18n-localization.md#gendered-copy-and-doc-drift)

**API Contract & Mobile Readiness**

- ⚪ low · M · [PDF reports are bearer-only inline responses with no signed/temporary URL alternative and no JSON fallback on failure](dimensions/02-api-contract-mobile.md#pdf-requires-bearer-no-signed-url)

**Backend Architecture & Code Quality**

- ⚪ low · S · [Dead scaffolding and unused models: Vite/Tailwind assets, test-xlsx scripts in backend root, Revision/TajweedEvaluation, boilerplate tests, misplaced docblocks](dimensions/03-backend-architecture.md#dead-code-and-stray-artifacts)

**Database Schema & Data Integrity**

- ⚪ low · M · [Arabic display strings are used as stored enum codes and compared in SQL/PHP](dimensions/06-database-schema.md#arabic-strings-as-enum-codes)
- ⚪ low · M · [Historical cruft: dead birth_date with a static age, untrusted memorizations.juz/hizb/eighth, VARCHAR time and phones](dimensions/06-database-schema.md#schema-cruft-birthdate-age-juz)
- ⚪ low · S · [MySQL-specific SQL in migrations and code prevents SQLite-backed fast tests and pins the engine](dimensions/06-database-schema.md#mysql-only-constructs)
- ⚪ low · S · [ExtraDataSeeder has no environment guard or transaction and CLAUDE.md still advertises it with stale credentials](dimensions/06-database-schema.md#seeder-guardrails-and-doc-drift)
- ⚪ low · M · [Surah reference lives in a PHP constant and athman in an xlsx; memorizations.surah_name is free text with no FK](dimensions/06-database-schema.md#reference-data-not-normalized)

**Security & Authentication**

- ⚪ low · M · [n8n attendance digest logs in daily as the human admin with a plaintext password and never revokes the '*' token it mints](dimensions/01-security-auth.md#automation-runs-as-human-admin)
- ⚪ low · S · [Rate limiting keys on raw IP with no TrustProxies, and is_active is only enforced via token deletion rather than per request](dimensions/01-security-auth.md#throttle-proxy-and-active-check-gaps)

**Landing Page & Public Surface**

- ⚪ low · S · [/public/stats is unthrottled and uncached, counts inactive users, and the two hardcoded fallback sets on the page contradict each other](dimensions/16-web-landing.md#public-stats-uncached-inconsistent)

**Design System & Brand Identity**

- ⚪ low · S · [Layout uses physical (right/left) properties; RTL is assumed rather than expressed logically](dimensions/14-design-system.md#rtl-physical-properties)

**Documentation & Knowledge Management**

- ⚪ low · M · [Comments explain intent well but carry almost no machine-readable contracts (@param/@return, strict_types, return types)](dimensions/11-documentation.md#phpdoc-contract-tags-absent)
- ⚪ low · S · [No stated documentation language policy: English CLAUDE.md, Arabic ops docs, boilerplate English README, mixed commits](dimensions/11-documentation.md#language-strategy-undefined)
- ⚪ low · S · [Unexplained artifacts committed at repo/backend root: personal photos, scratch PHP scripts, test xlsx, 3.3 MB design bundle](dimensions/11-documentation.md#repo-hygiene-unexplained-artifacts)

**Messaging & Notifications**

- ⚪ low · M · [Daily attendance digest lives only in an external n8n workflow (email to one address, admin password login, not deployed, app has no mailer/scheduler)](dimensions/19-messaging-notifications.md#digest-outside-app-n8n-email-only)
- ⚪ low · M · [No notification preferences (per-type opt-out, quiet hours) and notification text is pre-rendered Arabic stored in the DB](dimensions/19-messaging-notifications.md#no-preferences-no-structured-i18n)
- ⚪ low · S · [Notifications and messages grow unbounded — no pruning, archival or retention decision](dimensions/19-messaging-notifications.md#no-retention-policy)

**Engineering Process Maturity (CMMI lens)**

- ⚪ low · S · [Retired nested backend/.git is said to be 'backed up' but the backup location is undocumented](dimensions/15-process-maturity.md#nested-git-backup-untraceable)
- ⚪ low · M · [Architecture decisions live only in commit bodies and CLAUDE.md prose — no ADRs, no decision index](dimensions/15-process-maturity.md#decisions-only-in-commit-bodies)
- ⚪ low · M · [No measurement of engineering flow (DORA), no retrospectives, no SLOs — nothing to move from level 2 to 4](dimensions/15-process-maturity.md#no-metrics-no-retros)

**Legal, IP & Open-Source Licensing**

- ⚪ low · S · [_handoff2 Claude Design export redistributes an unlicensed vendor runtime and 3.3 MB of design/scratch files in the product repo](dimensions/18-legal-ip-licensing.md#handoff-bundle-provenance)
- ⚪ low · S · [n8n attendance digest is within the Sustainable Use License today but the boundary is undocumented](dimensions/18-legal-ip-licensing.md#n8n-sustainable-use-scope)
- ⚪ low · S · [Thumn index and surah→juz table have no recorded source edition or verification](dimensions/18-legal-ip-licensing.md#quran-data-provenance-unrecorded)
- ⚪ low · S · [Canonical docs omit or misstate the files that create legal/ops exposure](dimensions/18-legal-ip-licensing.md#doc-drift-legal-surface)

## Method & rubric

- One independent auditor per dimension read the code (not the docs) and scored 0–100 against the bar *“enterprise-grade, CMMI level 5 — what a demanding CTO requires before scaling to dozens of centers and shipping a public mobile app.”*
- Every medium-or-higher finding went to an adversarial reviewer instructed to refute it (two reviewers with different lenses for critical findings). Findings any reviewer refuted are removed from the roadmap but kept visible.
- A calibration judge compared all scores for consistency; a completeness critic asked what an enterprise audit normally covers that was missing, and those areas were audited in a second round.
- Reviewers were deliberately *not* told a Flutter app is planned, to keep them skeptical — where a reviewer wrote “no mobile client exists” and lowered a severity, read the auditor's severity as the mobile-aware one and “reviewers →” as the pure code-as-it-is view.
- Environment caveat: the audited checkout had no `vendor/`, no `.env` and no PHP runtime, so the test suite was read, not executed.
- Effort: S < 1 day · M 1–3 days · L 1–2 weeks · XL > 2 weeks.
- This Markdown edition is **redacted** for a public repository: live credentials, the hosting account and database names are replaced with `[redacted-…]`. The interactive edition is unredacted and private.

## Coverage critique

Read summary-for-judge.json (17 dimensions, ~230 findings) and skimmed the repo read-only (git ls-files, composer.lock licences, DEPLOYMENT.md, DEPLOY_LOG.md, n8n/README.md and workflow JSON, AdminUserController, parent/child.html, migrations, asset folders). The existing dimensions are unusually thorough on engineering concerns; the gaps are the governance/compliance disciplines an enterprise 'level 5' audit adds on top of them. Material and essentially uncovered: (1) privacy/child-protection as a program (data map, consent, retention vs never-delete, processor agreements, and the App Store / Google Play account-deletion mandates that collide with the product's never-delete decision) — only fragments appeared (privacy-policy-as-store-blocker, OTP in logs, no audit trail, gallery photos); (2) threat model, risk register, incident response and the roles/permissions matrix as a product artefact — security findings were found ad hoc; tenant-isolation testing is partially covered by ManagerReportsScopeTest/ManagerParentsTest etc. but not as a full matrix; (3) business continuity/DR (RTO/RPO, restore drills, SPOF/bus factor, offline operation for Libyan outages, runbooks, cost/capacity model) — devops listed backup/monitoring/deploy symptoms and process-maturity noted the single developer, but nobody framed continuity; (4) legal/IP/licensing — a concrete, verifiable conflict exists: composer.json declares MIT while mpdf/mpdf is GPL-2.0-only and the repo ships an on-prem launcher; plus unlicensed downloaded photos of children and no root LICENSE/ownership statement. Grazed but adequately represented as sub-findings rather than needing a new dimension: WCAG accessibility (8+ findings across web-app-ui, frontend-code, design-system, web-landing — a formal WCAG 2.2 AA conformance pass would still be a legitimate separate deliverable, weight ~2, if the panel wants it); product analytics/KPI governance (no telemetry, attendance-rate defined two ways, at-risk thresholds hard-coded — touched by reports-pdf, domain-rules and process-maturity's 'no DORA/SLOs'); documentation/onboarding (documentation dimension covers user-guide absence; the operational consequence is folded into the BC/DR brief); dependency EOL (covered by backend-architecture/devops). Mobile-store compliance and children's-photo consent were folded into the privacy and legal areas rather than listed separately. Weights are relative to the 17 dimensions summing to 100 (privacy 5 on par with performance-scale; threat model and BC/DR 3 each on par with web-landing/design-system; licensing 2 on par with reports-pdf).

The critic named these areas as missing from the first round; each was then audited:

- [Data Privacy, Child Protection & Store Compliance](dimensions/09-data-privacy-child-protection.md) — weight 5
- [Threat Model, Permissions Matrix & Tenant-Isolation Assurance](dimensions/17-threat-model-permissions-matrix.md) — weight 3
- [Business Continuity, DR & Operational Resilience](dimensions/13-business-continuity-operations.md) — weight 3
- [Legal, IP & Open-Source Licensing](dimensions/18-legal-ip-licensing.md) — weight 2

