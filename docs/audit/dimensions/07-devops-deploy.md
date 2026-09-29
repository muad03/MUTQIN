# DevOps, Deployment & Operations

[← Enterprise Audit](../enterprise-audit.md)

**Score 41 / 100** — Significant risk · maturity **L1** · weight 7% · auditor scored 31, judge calibrated to 41

Operations are ad-hoc and largely manual: production is a cPanel shared host with SSH disabled (backend/DEPLOY_LOG.md:6-9), so there is no artisan/composer/git on the server, migrations are hand-run SQL in phpMyAdmin from files that live outside the repo, and the only deploy automation (.cpanel.yml, added 2026-09-11) contradicts the still-current runbook that says deploys are 'manual exclusively'. There is no CI (0 workflows for 178 tests), no staging, no monitoring/error tracking/alerting, no evidence of automated backups of a database holding minors' PII, no rollback path, no scheduler, and no release identity: 71 commits have landed since the only deploy marked 'done', 7 of 8 DEPLOY_LOG entries are 'pending', and the framework (Laravel v11.51.0, lock frozen since 2026-06-25) passed its security-fix EOL on 2026-03-12. Genuine credits: a thoughtful .env.production.example with rationale per key, CORS closed-by-default, a two-layer .htaccess fence, fail-safe config defaults, clean git history (no .env/logs/dumps ever committed), a health route, and a per-commit deploy log discipline. That lifts it above 'zero process' but leaves it well inside 'unfit' for a 24/7 public mobile app across dozens of centers.

> **Calibration:** All three critical findings were downgraded (two to medium, backups to high) so no critical survived, yet the score sat in 'unfit' while testing-quality (63) and process-maturity (54) scored the same no-CI/manual-deploy evidence far higher; two confirmed highs (no backups of minors' PII, EOL framework) and unknown release identity justify the bottom of 'significant risk', not below it.

## What is already strong

- Production env template is deliberate and well-reasoned: backend/.env.production.example:12-15 forces APP_ENV=production/APP_DEBUG=false, :38-39 LOG_CHANNEL=daily + LOG_LEVEL=error, :62 QUEUE_CONNECTION=sync with a documented 'no queue worker on shared hosting' rationale, :77 CORS_ALLOWED_ORIGINS placeholder; every key carries an Arabic comment explaining why.
- CORS is closed by default, not open: backend/config/cors.php:14-17 `allowed_origins => array_values(array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS',''))))) ?: ['http://localhost:8080']` and :24 `supports_credentials => false` — forgetting the env var fails loudly rather than opening the API.
- Fail-safe framework defaults: backend/config/app.php:29 `'env' => env('APP_ENV', 'production')` and :42 `'debug' => (bool) env('APP_DEBUG', false)`; dev-only behaviour is gated on environment('local') (AuthController.php:153) not APP_DEBUG.
- Two-layer Apache fence for a Laravel tree that must live under the web root: backend/.htaccess:10-19 `Require all denied` (with 2.2 fallback) and backend/public/.htaccess:3-11 `Require all granted` — explicitly documented as 'the fence' for shared hosting.
- Secrets hygiene in git is clean: `git log --all --diff-filter=A -- '*/.env'` returns nothing, no *.log/*.sql/*.zip ever committed, root .gitignore:2 excludes `*.zip`, backend/.gitignore:3-5 excludes .env/.env.backup/.env.production; ProductionSeeder.php:24-27 refuses to run without an explicit ADMIN_INITIAL_PASSWORD (no silent generation).
- Runtime-detected API base URL removes a manual deploy step: frontend-html/js/config.js:10-12 switches between `http://localhost:9090/api` and the relative `/backend/public/api` by hostname, so the same static bundle works in dev and prod without editing.
- A health endpoint exists for uptime probes: backend/bootstrap/app.php:12 `health: '/up'` (Laravel 11 built-in) — reachable at /backend/public/up.
- Deploy-log discipline: backend/DEPLOY_LOG.md records per-commit what changed, which files, whether upload is needed, the upload method, and status; the header (:13-19) enumerates server-only files that must never be overwritten — a real (if manual) runbook.
- Strong regression net available to a future pipeline: 38 feature-test files + 2 unit files, 178 test methods (`grep -rhoE 'public function test' tests/ | wc -l` = 178), all using RefreshDatabase against a dedicated `mutqin_test` DB (phpunit.xml:26-27).
- No unbounded file-storage growth from uploads: the xlsx import reads the PHP temp file directly (AttendanceImportController.php:29-33 `$file->getRealPath()`) and never persists it; `grep -rn 'Storage::' app/` returns nothing, so no storage:link symlink is needed on the shared host.

## Level-5 target state

Production runs on a host with a real CLI (VPS or managed Laravel hosting) behind a stable `api.mutqin.ly/v1` origin, with `staging` as a first-class environment mirroring it. Every push runs the full 178-test suite plus `composer audit` in CI; a green build produces a tagged, immutable release that deploys atomically (releases/ + symlink, `migrate --force`, `config:cache`, smoke test, one-command rollback) and exposes its version at `/api/public/version`. Backups are automated daily with off-site copies and a rehearsed restore; a scheduler prunes tokens/OTPs/notifications and runs digests; error tracking, uptime checks, log shipping and quota alerts page the on-call engineer before parents notice. Secrets live only in the host's secret store, TLS/HSTS/security headers are enforced at the edge, dependencies are on a supported Laravel major with automated update PRs, and the runbook (DEPLOYMENT.md) is generated/validated from the same pipeline so it can never drift.

## What the Flutter team must know

1) Base URL: today the only production API origin is `https://mutqin.ly/backend/public/api/...` (frontend-html/js/config.js:12) — do not hard-code it in the app; put it behind a remote-config/flavor and push the backend team to publish `https://api.mutqin.ly/v1` before the first store build, because the current path will change when hosting is fixed. 2) Environments: there is no staging; you will be pointed either at production (children's PII) or at a developer's XAMPP `php artisan serve` (single-threaded, serializes concurrent requests). Insist on a staging host with seeded demo data (ProductionSeeder + LibyanDataSeeder) before integration. 3) Version negotiation: no `/version` or min-app-version endpoint exists and the deployed commit is unknown (71 commits since last confirmed deploy); build a forced-update screen against a `GET /api/public/version` endpoint the backend must add. 4) Auth/ops semantics: tokens last 7 days (config/sanctum.php:55) and are revoked en masse on password change or account/center deactivation → any request may return 401; login is throttled 10/min per IP (routes/api.php:17) which can false-positive behind carrier NAT — surface the 429 gracefully and back off; there is no maintenance-mode contract, so handle 503/HTML responses defensively. 5) Error envelope: unhandled 500s and 404/405s come from Laravel's default handler as `{\"message\": ...}` without `success` (bootstrap/app.php:22-24 registers no renderer) — parse both shapes. 6) Enforce HTTPS-only networking in the app (no HSTS on the server yet); TLS pinning is not advisable because AutoSSL certificates rotate every ~90 days. 7) Observability: the server has no error tracking, so the app must ship its own crash/network-error telemetry (Sentry/Crashlytics) — it will be the first signal of backend outages. 8) Offline: the web PWA deliberately has no service worker (config.js:30); the API has no ETag/If-Modified-Since support, so design client caching yourself and expect polling (web bell polls /notifications every 60 s, layout.js:257) rather than push.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [No CI/CD: tests never run in a pipeline, deploys are hand-uploaded or a manual cPanel 'Deploy HEAD', and the two documented deploy mechanisms contradict each other](#no-cicd-manual-deploy) | 🔴 critical<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [No automated database or storage backups — DEPLOYMENT.md prescribes a mysqldump cron the host cannot run](#no-db-backups) | 🔴 critical<br>_reviewers → high_ | ✅ confirmed | NOW | S |
| [No monitoring, alerting, uptime checks or error tracking; production logs are error-level daily files readable only via File Manager](#no-monitoring-error-tracking) | 🔴 critical<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Only two environments (developer XAMPP and production); no staging, and the production API lives at a structure-leaking, host-layout-dependent path with no versioning](#no-staging-unstable-api-host) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NOW | M |
| [What is actually running in production is unknowable: 71 commits since the only deploy marked 'done', 7/8 log entries 'pending', no version tag, no /version endpoint](#release-identity-unknown) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Framework is past security EOL and dependencies are frozen: Laravel v11.51.0 (security fixes ended 2026-03-12), composer.lock unchanged since 2026-06-25, no dependency audit](#laravel-11-eol-frozen-deps) | 🟠 high | ✅ confirmed | NEXT | L |
| [Schema changes are applied as hand-written SQL in phpMyAdmin from files outside the repo; the migrations table is not maintained and there is no rollback](#migrations-hand-run-sql) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | M |
| [Rate limiting covers only the 3 auth endpoints, keyed per IP with no proxy trust configuration; authenticated API has no limiter](#rate-limit-per-ip-only) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [HTTPS is not enforced or asserted anywhere in the repo (no redirect, no HSTS, no forceScheme, no security headers); it depends entirely on an unverified cPanel setting](#https-not-enforced-no-security-headers) | 🟡 medium | ✅ confirmed | NOW | S |
| [In-place `rsync --delete` deploy with no releases/symlink layout, no post-deploy cache rebuild, and no way to enter maintenance mode](#no-rollback-no-maintenance-mode) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [No scheduler or cron: expired Sanctum tokens, stale OTP rows and notifications are never pruned; the only recurring job is an external, demo-grade n8n workflow](#no-scheduler-no-pruning) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Entire Laravel tree (.env, storage/logs, vendor) sits under public_html guarded only by .htaccess, and rsync ships dev artifacts (composer.phar 3.5 MB, tests/, check_excel.php, DEPLOY_LOG.md, .env.production.example) to production](#laravel-tree-in-webroot-dev-artifacts-deployed) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Plaintext OTP and phone number are written to the application log at info level in every environment; only LOG_LEVEL=error hides it in production, contrary to the docs](#otp-and-phone-logged-plaintext) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Static frontend has no cache-busting or caching policy, and depends on two third-party CDNs with no SRI or fallback](#frontend-no-cache-busting-external-deps) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Operational documentation contradicts the code: CORS, API URL, demo credentials, test counts, deploy mechanism, launcher paths and the backend README are stale](#ops-docs-drift) | 🟡 medium | ✅ confirmed | NEXT | S |

### No CI/CD: tests never run in a pipeline, deploys are hand-uploaded or a manual cPanel 'Deploy HEAD', and the two documented deploy mechanisms contradict each other

<a id="no-cicd-manual-deploy"></a>

`no-cicd-manual-deploy` · 🔴 critical (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/DEPLOY_LOG.md:6-9`, `.cpanel.yml:4-7`, `backend/DEPLOY_LOG.md:21-23`, `backend/tests/Feature`

**Evidence**

```text
`git ls-files | grep -iE '.github|gitlab-ci|Jenkins|docker'` returns nothing (`ls .github` → 'no .github'). backend/DEPLOY_LOG.md:6-9: 'SSH ... disabled by the host (Connection refused on 22) — practically no SSH / CLI / artisan / composer / git. Deployment is manual exclusively via cPanel File Manager (Upload + Extract ... Edit to paste single files). Nothing reaches the server by itself.' Yet .cpanel.yml (commit 9831f1c, 2026-09-11) rsyncs `frontend-html/` → `public_html/` and `backend/` → `public_html/backend/` with `--delete`, and the DEPLOY_LOG header/entries were never updated to reflect it (17 commits after the last log entry 788ee03). 178 test methods exist but nothing executes them before a deploy.
```

**Why it matters**

Any commit can reach production untested; the deploy path itself is ambiguous (File Manager paste vs rsync --delete), so a File-Manager hot-fix can be silently reverted by the next rsync. With a Flutter app depending on API stability, an unverified regression ships to every phone at once with no gate.

**Recommendation**

Add a GitHub Actions workflow (MySQL 8 service, PHP 8.3, `composer install --no-dev`-then-dev, `php artisan test`) on every push/PR to master; make the cPanel Git deploy the single path and mark File-Manager edits forbidden in DEPLOY_LOG.md; have the workflow tag a release and post-deploy curl `/backend/public/up` + `/api/public/stats` as a smoke test.

<details><summary>Verification — 2 independent reviewers</summary>

**Upheld** · confidence 80% · corrected severity: medium

The factual evidence holds. `git ls-files` shows no .github/, GitLab CI, Jenkins, or Docker files — the only deploy-related tracked file is `.cpanel.yml`. No local git hooks either (`.git/hooks` has only samples). `backend/tests/Feature` contains 38 test files with ~173 test methods, and nothing in the repo executes them automatically. `backend/DEPLOY_LOG.md:6-9` does state SSH is disabled and deployment is "manual exclusively via cPanel File Manager... nothing reaches the server by itself", while `.cpanel.yml` (commit 9831f1c, 2026-09-11, after the last log entry 788ee03; 17 commits since) rsyncs `frontend-html/` → `public_html/` and `backend/` → `public_html/backend/` with `--delete`. No documentation file (DEPLOY_LOG.md, DEPLOYMENT.md, README) mentions .cpanel.yml/rsync/Git Version Control, so the contradiction is real and the log's "every commit = an entry here" discipline lapsed.

However, the severity is overstated. Mitigations/context: (1) the cPanel Git deploy is not push-triggered CI — it still requires a human to click Deploy HEAD (or pull) in cPanel, so "any commit can reach production" is inaccurate; a manual human gate remains, just not an automated test gate. (2) `.cpanel.yml` explicitly excludes `.env`, `vendor`, `storage`, `bootstrap/cache` and the `backend` subtree from the frontend rsync, so the server-only files the log lists as "never overwrite" are protected; the revert risk is limited to File-Manager edits of tracked files, which is a process hazard rather than a defect. (3) The team demonstrably runs the suite locally before commits (DEPLOY_LOG entries cite "119 tests green"), and the host offers no SSH/artisan, so a CI pipeline could only test, not deploy — the recommended post-deploy smoke test would still be manual. (4) The "Flutter app" dependency is not evidenced anywhere in this repo. This is a genuine process/operations gap (no automated test gate, stale and contradictory deploy documentation) but not a code correctness or security defect; for a small single-country team with a manual deploy click it rates medium, not critical.

```text
.cpanel.yml:4-7 — rsync -a --delete for frontend-html/ → $DEPLOYPATH/ (excluding backend, .well-known, cgi-bin) and backend/ → $DEPLOYPATH/backend/ (excluding vendor, .env, storage, bootstrap/cache); committed 9831f1c (2026-09-11), after last DEPLOY_LOG entry 788ee03 (17 commits since). backend/DEPLOY_LOG.md:6-9 still says manual File Manager only; grep for "cpanel.yml|rsync|Git Version" in DEPLOY_LOG.md/DEPLOYMENT.md/README.md returns nothing. `git ls-files | grep -iE '.github|gitlab-ci|jenkins|docker'` → empty; .git/hooks has only *.sample. backend/tests/Feature: 38 files, ~173 test methods, none wired to any automated runner. Note cPanel Git deploy is manually triggered (Deploy HEAD), not push-triggered, so a human gate remains.
```

---

**Upheld** · confidence 80% · corrected severity: medium

Facts verified: no CI config tracked (git ls-files has no .github/gitlab-ci/Jenkins/docker; `ls .github` fails); .cpanel.yml (commit 9831f1c, 2026-09-11) rsyncs with --delete as quoted; backend/DEPLOY_LOG.md:6-9 still states deployment is exclusively manual via File Manager and the last log entry is 788ee03 with 17 commits since; 38 Feature test files / ~173 test methods exist and nothing in .cpanel.yml runs them. So the finding is real. But 'critical' is not proportionate under a materiality lens: (1) The .cpanel.yml already establishes a single, reproducible Git-driven deploy path and its excludes (vendor, .env, storage, bootstrap/cache, .well-known) protect exactly the server-only files the log says must never be overwritten — the contradiction is a stale documentation header, not two live competing mechanisms. (2) The host (Libyan Spider shared cPanel, SSH disabled) cannot run artisan/composer, so migrations are delivered as raw SQL by hand regardless; a CI pipeline would gate code but could not automate the deploy/migration steps the auditor implies. (3) Tests are demonstrably run locally before pushes — DEPLOY_LOG entries cite green test counts ('119 اختباراً خضراء') and 3 of the 17 post-log commits are test(...) commits — so 'nothing executes them' overstates: they are executed, just not enforced by automation. (4) The impact claim about a Flutter app depending on API stability is unsupported by the repo (no flutter/pubspec/.dart files and no doc mention). (5) This is a single-developer, single-country deployment on shared hosting; the risk is process/operational (unenforced test gate, possible silent revert of an uncommitted File-Manager hot-fix), not a data-exposure or privilege defect and not mitigated by nothing. Recommendation (GitHub Actions + update DEPLOY_LOG to mark cPanel Git as the sole path) is sound and cheap, but the severity should be medium.

```text
.cpanel.yml:4-7 — rsync --delete with --exclude='vendor' --exclude='.env' --exclude='storage' --exclude='bootstrap/cache' (server-only files listed at backend/DEPLOY_LOG.md:13-19 are protected, so rsync cannot clobber .env/storage; only uncommitted code hot-fixes are at risk). backend/DEPLOY_LOG.md:6-9 header is stale (predates 9831f1c 2026-09-11 'إضافة .cpanel.yml — نشر تلقائي من Git Version Control'); 17 commits since last entry 788ee03. backend/DEPLOY_LOG.md:58 records local test runs ('119 اختباراً خضراء'); git log 788ee03..HEAD contains 3 test(...) commits. No Flutter client exists in the repo (git ls-files has no flutter/pubspec/.dart; no md mentions) — impact claim unsupported. 38 files in backend/tests/Feature, ~173 test methods, none invoked by .cpanel.yml.
```

</details>

### No automated database or storage backups — DEPLOYMENT.md prescribes a mysqldump cron the host cannot run

<a id="no-db-backups"></a>

`no-db-backups` · 🔴 critical (reviewers → high) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `DEPLOYMENT.md:15`, `backend/DEPLOY_LOG.md:6-7`, `backend/routes/console.php:1-8`

**Evidence**

```text
DEPLOYMENT.md:15: 'Schedule mysqldump daily at minimum for mutqin_db (minors' data — no compromise). Example: mysqldump -u root mutqin_db > backup-$(date +%F).sql'. But backend/DEPLOY_LOG.md:6-7 states there is no SSH/CLI on the production host, so that command cannot be scheduled there. No backup script, no restore procedure, no off-site target, no retention policy anywhere in the repo; `grep -rn 'Schedule::' routes app bootstrap | wc -l` = 0. The only 'backups' referenced are ad-hoc local dumps at `C:\mutqin-deploy\*.sql` (DEPLOY_LOG.md:43, :52) outside version control.
```

**Why it matters**

The system stores children's names, national IDs, guardian phones and attendance. A hosting failure, a bad phpMyAdmin import (DEPLOY_LOG.md:45 imports a dump that 'contains DROP TABLE IF EXISTS for every table'), or ransomware means unrecoverable loss. Scaling to dozens of centers multiplies the blast radius.

**Recommendation**

Before public launch: enable and verify the cPanel account-level backup (or Libyan Spider's managed backup) with daily DB dumps and 30-day retention; add an off-site copy (e.g. a cPanel cron `mysqldump | gzip` pushed to S3-compatible storage or emailed); document and rehearse a restore into `mutqin_test`; record RPO/RTO targets in DEPLOYMENT.md.

<details><summary>Verification — 2 independent reviewers</summary>

**Upheld** · confidence 85% · corrected severity: high

The cited evidence is accurate. DEPLOYMENT.md row 7 ("نسخ احتياطي لقاعدة البيانات") prescribes a daily mysqldump with a shell example only; backend/DEPLOY_LOG.md header states SSH is disabled by the host ("Connection refused على 22 — عملياً بلا SSH / CLI / artisan / composer / git"), so that command is not runnable on the production host as written. backend/routes/console.php contains only the default `inspire` command; grep for `Schedule::`/`schedule(` across routes/app/bootstrap returns nothing; composer.json has no backup package (only spatie/laravel-ignition); there is no .github/CI, no backup/restore script, no retention or RPO/RTO text anywhere in the repo. The only dumps referenced are ad-hoc local files under C:\mutqin-deploy\ used for seeding/resetting the prod DB via phpMyAdmin, which are not backups of live data. However, the finding conflates "not in the repo" with "does not exist": cPanel hosts (Libyan Spider) typically provide account-level backups and a cron UI (which can run `mysqldump` without SSH), and the finding cannot confirm from the repo that these are absent. Also the DB is currently near-empty/pre-launch per DEPLOY_LOG (clean dump pending import). The absence of any documented, verified backup+restore procedure for a minors' PII system is a real gap, but it is an operational/documentation deficiency rather than a demonstrated data-loss defect; severity should be high, not critical, and the recommendation should note cPanel cron as a viable no-SSH mechanism.

```text
DEPLOYMENT.md:15 (table row 7 — mysqldump example only, no schedule/retention/restore); backend/DEPLOY_LOG.md:6-7 (SSH disabled by host, no CLI/cron-via-shell); backend/DEPLOY_LOG.md:43,45,52 (only ad-hoc local dumps at C:\mutqin-deploy\*.sql, used as seed/reset imports with DROP TABLE, not as backups); backend/routes/console.php:1-8 (only default inspire command, no scheduler); backend/composer.json:23 (no backup package). Unconfirmed from repo: whether cPanel account backups or a cPanel-cron mysqldump are configured on the host — the repo neither documents nor rules this out.
```

---

**Upheld** · confidence 80% · corrected severity: high

Evidence verified: DEPLOYMENT.md:15 is a pre-production checklist row telling the operator to schedule mysqldump; backend/DEPLOY_LOG.md:6-9 confirms Libyan Spider cPanel with SSH refused and deployment purely via File Manager/phpMyAdmin; routes/console.php has only the default 'inspire' command and there are zero Schedule:: calls; the only dumps referenced live at C:\mutqin-deploy\*.sql outside the repo (DEPLOY_LOG.md:43,52) and no .sql or backup/cron script is git-tracked. No mitigation exists anywhere in the repo (no scheduler, no docs of cPanel backup, no restore rehearsal). So the gap is real and, for a system holding minors' PII, must be closed before launch.

However, 'critical' is overstated on materiality grounds: (1) The auditor's core assertion that 'the host cannot run' a scheduled mysqldump is wrong — cPanel exposes a Cron Jobs UI that does not require SSH, and cPanel accounts on shared hosts (Libyan Spider included) ship with account-level Backup/Backup Wizard (often JetBackup). The repo simply does not document whether it is enabled — this is an unverified/undocumented operational control, not a demonstrated absence. (2) The production database currently holds essentially no live data: DEPLOY_LOG.md:39-46 (e7c50f9) describes the 'clean' dump with only the athman index and one admin account, and every deploy entry except the very first is 'pending', so there is presently nothing irrecoverable to lose; the exposure begins at real-data onboarding. (3) The item is already tracked as a mandatory pre-production step (DEPLOYMENT.md section 1 'إلزامية'), i.e. a known open ops task rather than a hidden defect. (4) This is a documentation/operations gap with no code fix in scope; no test or middleware can mitigate it, and none was expected. Net: a genuine, launch-blocking operational gap whose blast radius today is near zero and whose remedy is a cPanel setting plus a documented restore drill — 'high' (must-fix-before-real-data) rather than 'critical'.

```text
DEPLOYMENT.md:15 — checklist row 7 prescribes daily mysqldump (only backup mention in the repo; `grep -rni backup|mysqldump|cron` across *.md/*.php/*.sh/*.bat hits only this line). backend/DEPLOY_LOG.md:6-9 — SSH refused, deploy via cPanel File Manager/phpMyAdmin only (but cPanel Cron Jobs and account Backup UI remain available without SSH — auditor's 'cannot be scheduled' is inaccurate). backend/DEPLOY_LOG.md:39-46 — production DB reset to empty (athman index + admin only); lines 46,55,64,72,83,92,100 all 'حالة الرفع: pending', only line 37 'done' — no real production data yet. backend/routes/console.php:1-8 — only the default 'inspire' command; zero Schedule:: in routes/app/bootstrap. `git ls-files | grep -iE '\.sql$|backup|cron'` = empty — no tracked dump or backup/restore script.
```

</details>

### No monitoring, alerting, uptime checks or error tracking; production logs are error-level daily files readable only via File Manager

<a id="no-monitoring-error-tracking"></a>

`no-monitoring-error-tracking` · 🔴 critical (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/config/logging.php:21`, `backend/.env.production.example:38-39`, `backend/composer.json:7-24`, `backend/bootstrap/app.php:22-24`

**Evidence**

```text
composer.json has no sentry/bugsnag/flare/nightwatch/telescope/pulse (`grep -n 'sentry|bugsnag|flare|nightwatch|telescope|pulse' composer.json` → only require-dev `spatie/laravel-ignition`). config/logging.php offers slack/papertrail channels but .env.production.example:38-39 selects `LOG_CHANNEL=daily` / `LOG_LEVEL=error` with the comment 'without CLI there is no way to empty a bloated single file'. bootstrap/app.php:22-24 `->withExceptions(function (Exceptions $exceptions): void { // })` — no reporting hook. Only 5 `Log::` calls exist in app/ (`grep -rn 'Log::' app | wc -l` = 5). The `/up` health route (bootstrap/app.php:12) is referenced by no monitor and does not check the DB.
```

**Why it matters**

With 24/7 mobile users, the team learns about outages and 500s from angry parents. No MTTR data, no error rate, no request latency, no disk/DB-size alerts on a shared host with hard quotas.

**Recommendation**

Wire an error tracker with a free tier (Sentry via `sentry/sentry-laravel`, or Laravel Nightwatch) through `withExceptions` and the `daily` stack; add an external uptime monitor (UptimeRobot/BetterStack) on `/backend/public/up` and on `GET /api/public/stats` (exercises the DB); route critical logs to a Slack/Telegram webhook via the existing `slack` channel; add a DB-size and disk-quota check.

<details><summary>Verification — 2 independent reviewers</summary>

**Upheld** · confidence 85% · corrected severity: medium

Every quoted fact checks out: backend/bootstrap/app.php:22-24 has an empty withExceptions closure and only `health: '/up'` at line 12 (Laravel's built-in route, which does not touch the DB); config/logging.php:21 defaults to env LOG_CHANNEL; .env.production.example:36-39 sets LOG_CHANNEL=daily / LOG_LEVEL=error with the quoted comment about no CLI; composer.json only has require-dev spatie/laravel-ignition (line 23) and no sentry/bugsnag/flare/nightwatch/telescope/pulse; `grep -rn 'Log::' app` returns 5 hits, of which one (User.php:63) is actually `PasswordChangeLog::class`, so there are only 4 real Log calls (2 error in AttendanceImportController, 1 info in AuthController OTP, 1 warning in InAppNotification). No mitigation exists elsewhere: DEPLOYMENT.md, backend/DEPLOY_LOG.md, README.md, the n8n/ folder (only an attendance-digest workflow) and _handoff2 contain no uptime monitor, Slack/Telegram alerting, or error tracker configuration. `publicStats` (DashboardController:124-134) does hit the DB and would serve as a DB-exercising probe, but nothing points a monitor at it. The finding is therefore real and unmitigated. However, 'critical' is overrated: this is a pure operability/observability gap with no confidentiality, integrity, or data-loss consequence; the product is a small single-country center-management tool with a small team, daily rotated error logs do exist and are reachable via the host File Manager, and the frontend surfaces API errors to users. Detection latency and MTTR are the impact — appropriate for 'medium' (arguably 'high' if 24/7 mobile parent usage is confirmed as a hard requirement, but that requirement is asserted, not evidenced in the repo).

```text
backend/bootstrap/app.php:12 (`health: '/up'` — framework default, no DB check) and :22-24 (empty withExceptions); backend/config/logging.php:21; backend/.env.production.example:36-39 (LOG_CHANNEL=daily, LOG_LEVEL=error); backend/composer.json:23 (only spatie/laravel-ignition, require-dev). Actual Log:: calls are 4, not 5 — app/Http/Controllers/Api/AttendanceImportController.php:37,326; app/Http/Controllers/Api/AuthController.php:218; app/Notifications/InAppNotification.php:60 (app/Models/User.php:63 is `PasswordChangeLog::class`, a false grep hit). No monitoring references in DEPLOYMENT.md, backend/DEPLOY_LOG.md, or n8n/ (which contains only mutqin-daily-attendance-digest.json). backend/app/Http/Controllers/Api/DashboardController.php:124-134 publicStats queries centers/users/students and is a suitable DB-exercising probe target.
```

---

**Upheld** · confidence 80% · corrected severity: medium

Factual evidence checks out: config/logging.php:21 default channel from env; .env.production.example selects LOG_CHANNEL=daily / LOG_LEVEL=error with the quoted comment; bootstrap/app.php:22-24 has an empty withExceptions hook and health: '/up' at line 12; composer.json line 23 only has spatie/laravel-ignition in require-dev; exactly 5 Log:: usages in app/ (one of them, User.php:63, is actually the passwordChangeLogs relation, so 4 real log calls). DEPLOYMENT.md and DEPLOY_LOG.md mention no uptime monitor or error tracker; the host is Libyan Spider cPanel with SSH disabled, so File Manager is indeed the only log access. The gap is real and not mitigated by any middleware, test, or frontend layer. However, "critical" is not justified on materiality grounds. (1) This is an availability/observability gap, not a security or data-integrity defect — no data is lost or exposed; users see a failed request in the frontend (api.js parses the envelope and surfaces errors). (2) The product is a small, single-country Quran-center admin tool, freshly deployed with an emptied DB (DEPLOY_LOG), low traffic, with human center managers in the loop; a 500 delays a parent seeing progress, it does not endanger anyone. The "24/7 mobile users" and "hard quota" claims are not evidenced in the repo. (3) Partial mitigations exist: daily-rotated error logs are configured and readable; the framework /up health endpoint already exists so an external monitor is a five-minute zero-code addition; the n8n workflow (n8n/mutqin-daily-attendance-digest.json) logs in and calls the API daily at 20:00 and would visibly fail if the API were down, acting as a crude daily liveness probe. (4) The recommendation itself (Sentry free tier + UptimeRobot) is low-effort configuration, which is characteristic of a medium-severity operational hygiene finding, not a critical one. Reclassify as medium: worth doing before scaling real user load, but not a release blocker.

```text
backend/bootstrap/app.php:12 (health: '/up' exists) and :22-24 (empty withExceptions); backend/.env.production.example:36-39 (LOG_CHANNEL=daily, LOG_LEVEL=error); backend/composer.json:23 (only spatie/laravel-ignition, require-dev); real Log:: calls are 4, not 5 — app/Models/User.php:63 is the passwordChangeLogs() relation, not a log call; backend/DEPLOY_LOG.md:1,19,67 (Libyan Spider cPanel, SSH disabled, logs at public_html/backend/storage/logs/); n8n/README.md:13 (daily 20:00 scheduled API call — incidental liveness check); DEPLOYMENT.md has no monitoring item in the pre-production checklist.
```

</details>

### Only two environments (developer XAMPP and production); no staging, and the production API lives at a structure-leaking, host-layout-dependent path with no versioning

<a id="no-staging-unstable-api-host"></a>

`no-staging-unstable-api-host` · 🟠 high (reviewers → low) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `frontend-html/js/config.js:10-12`, `backend/.env.example:2`, `backend/.env.production.example:12`, `backend/routes/api.php:1-30`, `.cpanel.yml:5-6`

**Evidence**

```text
frontend-html/js/config.js:10-12: `API_BASE_URL = (hostname === 'localhost' || hostname === '127.0.0.1') ? 'http://localhost:9090/api' : '/backend/public/api'` — the public API base is literally `https://mutqin.ly/backend/public/api/...`, exposing the Laravel directory layout and coupling the URL to the cPanel folder structure. There is no `/v1` prefix anywhere in routes/api.php. Environments are `APP_ENV=local` (.env.example:2) and `production` (.env.production.example:12) only; DEPLOY_LOG.md, DEPLOYMENT.md and .cpanel.yml mention no staging host, and CLAUDE.md's dev instructions require XAMPP + single-threaded `php artisan serve`.
```

**Why it matters**

A Flutter client will hard-code the base URL; any later move of the Laravel tree (or to a VPS with `public/` as DocumentRoot) breaks every installed app until a store update propagates. Without staging, the mobile team either develops against production data (children's PII) or against one developer's laptop; QA of role flows (four roles) cannot happen safely.

**Recommendation**

Before the Flutter team starts: (1) publish a stable API origin — `https://api.mutqin.ly` (cPanel subdomain whose DocumentRoot is `backend/public`) — and prefix routes with `/v1` (keep `/api/*` as an alias for the web client); (2) stand up `staging.mutqin.ly` + `api-staging` on the same cPanel account with its own DB and `APP_ENV=staging`, seeded via ProductionSeeder + LibyanDataSeeder; (3) document base URLs per environment in DEPLOYMENT.md.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted evidence is accurate: frontend-html/js/config.js:10-12 selects 'http://localhost:9090/api' on localhost and the relative '/backend/public/api' otherwise; routes/api.php has no version prefix; only APP_ENV=local (.env.example:2) and production (.env.production.example:12) exist; .cpanel.yml:5-6 rsyncs backend/ into public_html/backend/ so the API really is served at https://<domain>/backend/public/api; DEPLOYMENT.md and backend/DEPLOY_LOG.md mention no staging host. So the finding is factually correct. However the HIGH severity rests on a hypothetical: there is no Flutter/mobile client anywhere in the repo or docs (grep for flutter/mobile finds nothing), and the only existing consumer resolves its base URL at runtime relative to the page origin and ships with the frontend — a move of the Laravel tree is a one-line edit to config.js deployed together with the site, with no "installed app" to update. The structure-leaking path is mitigated: backend/.htaccess denies all HTTP access to the Laravel tree outside public/ (Require all denied / Deny from all), so knowing the layout exposes nothing beyond the fact that Laravel is used. Lack of /v1 versioning is normal for a first-party single-client API. The missing staging environment is a real operational gap for a system holding children's PII (QA of four-role flows happens against dev laptops or prod), but for a small team on shared cPanel hosting it is a hygiene/roadmap item, not a high-severity defect. Corrected severity: low; raise to medium if and when a mobile client is actually planned.

```text
frontend-html/js/config.js:10-12 (relative '/backend/public/api' resolved per-origin at runtime, deployed with the client — no hard-coded absolute URL); frontend-html/js/api.js:9,45 (single consumer of API_BASE_URL); backend/.htaccess:11-20 (Require all denied on the whole Laravel tree, only public/ is served — layout exposure is harmless); .cpanel.yml:5-6 (backend rsynced under public_html/backend confirms the path); DEPLOYMENT.md:13 (documents changing API_BASE_URL when DocumentRoot moves to backend/public); no Flutter/mobile client or plan exists in the repo (grep across *.md returns nothing).
```

</details>

### What is actually running in production is unknowable: 71 commits since the only deploy marked 'done', 7/8 log entries 'pending', no version tag, no /version endpoint

<a id="release-identity-unknown"></a>

`release-identity-unknown` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/DEPLOY_LOG.md:27-100`, `backend/routes/api.php`, `backend/config/app.php`

**Evidence**

```text
`git rev-list --count f868880..HEAD` = 71 (commits since the initial published state, DEPLOY_LOG.md:27-37, the only entry with 'حالة الرفع: done'). DEPLOY_LOG.md:46, :55, :64, :72, :83, :92, :100 all read 'حالة الرفع: pending'. `git rev-list --count 788ee03..HEAD` = 17 commits after the last log entry with no entry at all. `git tag` shows a single tag `requests-restructure-2026-09-08`; `grep -rniE 'app_version|APP_VER|"version"' frontend-html/js backend/config backend/routes backend/app` returns nothing — the API does not expose its build.
```

**Why it matters**

Incident triage cannot start from 'which commit is live'; the mobile team cannot know which API contract the server honours; a forced-update / minimum-app-version flow (standard for public apps) has no server-side anchor.

**Recommendation**

Add `GET /api/public/version` returning `{app_version, git_sha, min_mobile_version}` from a file written at deploy time (the .cpanel.yml can `git rev-parse HEAD > backend/VERSION`); tag every deploy `vYYYY.MM.DD-n`; make DEPLOY_LOG status transitions part of the release workflow (or replace the log with GitHub Releases).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Quoted evidence verified: `git rev-list --count f868880..HEAD` = 71, `788ee03..HEAD` = 17, only tag is `requests-restructure-2026-09-08`, DEPLOY_LOG.md line 37 is the sole 'done' with lines 46/55/64/72/83/92/100 all 'pending', and no version/git_sha/APP_VERSION string exists anywhere in backend/routes, backend/app, backend/config, or frontend-html/js; the only public routes are /public/stats and /public/demo-accounts (routes/api.php:22-23). No `backend/VERSION` file exists. So the factual core stands: nothing in the running system self-identifies its build. However the finding is over-rated and partly mis-framed. (1) The 'mobile team' / forced-update impact is speculative — there is no mobile client in this repo; the only consumer is the co-deployed static frontend-html shipped in the same rsync/zip. (2) The recommended fix is infeasible as written: DEPLOY_LOG.md:4-9 states the host (Libyan Spider cPanel) has SSH disabled and no git/artisan/composer on the server; deployment is manual File Manager upload, so `.cpanel.yml` running `git rev-parse HEAD` will not execute there (the existing .cpanel.yml is effectively dormant). (3) The 'pending' statuses are by design an owner-maintained field (DEPLOY_LOG.md:23 — only the project owner flips it after the actual upload), so 'pending' may mean 'not yet flipped' rather than 'not deployed'; the log cannot prove either way, which is itself the point, but the auditor treats it as proof of 71 undeployed commits. (4) Single-developer, single-server, manually-deployed project with a small operational footprint: lack of a /version endpoint is a real operability gap but not high severity. Corrected to medium: a static `backend/VERSION` committed per release plus a trivial `GET /api/public/version` reading it (or `config('app.version')` from .env) is the workable mitigation given the no-CLI host.

```text
backend/DEPLOY_LOG.md:4-9 — host has SSH disabled, no git/artisan/composer on server; deploy is manual cPanel File Manager upload, so the recommended `.cpanel.yml` `git rev-parse HEAD > backend/VERSION` step cannot run. backend/DEPLOY_LOG.md:23 — status field is flipped manually by the owner only, so 'pending' is not proof of non-deployment. .cpanel.yml (repo root) — only rsync tasks, no version stamping. backend/routes/api.php:22-23 — only public routes are /public/stats and /public/demo-accounts; no version endpoint. No mobile client exists in the repo, so the 'mobile team / min_mobile_version' impact is hypothetical.
```

</details>

### Framework is past security EOL and dependencies are frozen: Laravel v11.51.0 (security fixes ended 2026-03-12), composer.lock unchanged since 2026-06-25, no dependency audit

<a id="laravel-11-eol-frozen-deps"></a>

`laravel-11-eol-frozen-deps` · 🟠 high · ✅ confirmed · **NEXT** · effort L (1–2 weeks)

**Files:** `backend/composer.lock:1133-1134`, `backend/composer.json:9`, `backend/composer.json:60`, `backend/composer.json:68-70`

**Evidence**

```text
composer.lock:1133-1134 `"name": "laravel/framework", "version": "v11.51.0"`; composer.json:9 `"laravel/framework": "^11.0"`. Laravel's published support table (laravel.com/docs/12.x/releases, fetched 2026-09-14): 'Laravel 11 — Security Fixes Until March 12th, 2026'; Laravel 13 is current. `git log -- composer.lock` shows one commit: `be47c33 2026-06-25 Initial commit`. composer.json:60 `"platform-check": false` and :68-70 pins `"platform": {"php": "8.2.12"}` while DEPLOY_LOG.md:3 says production runs PHP 8.3 — the runtime check that would catch a mismatch is disabled. Vendor cannot be updated on the server (no composer, .cpanel.yml:6 `--exclude='vendor'`), so every dependency bump is a multi-MB zip re-upload via File Manager.
```

**Why it matters**

Any CVE in Laravel 11, Sanctum 4.3.2, mPDF 8.3.1 or PhpSpreadsheet 5.8.0 (a parser exposed to user-uploaded xlsx) stays unpatched; the update path itself is manual and error-prone, so patches will lag further once a mobile app increases exposure.

**Recommendation**

Upgrade to Laravel 12 (documented as a maintenance release: 'most Laravel applications may upgrade ... without changing any application code') then 13 within the next cycle; add `composer audit` + Dependabot to CI; plan the vendor-upload procedure (or move to a host with composer) so patches can ship in hours, not days.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 90% · corrected severity: high

Every cited fact checks out and the finding is, if anything, under-evidenced. backend/composer.lock:1133-1134 pins laravel/framework v11.51.0 (sanctum v4.3.2 at :1407-1408, mpdf v8.3.1 at :2444-2445, phpspreadsheet 5.8.0 at :3136-3137); composer.json:9 requires "^11.0"; `git log -- composer.lock composer.json` shows a single commit be47c33 (2026-06-25). composer.json:60 has "platform-check": false and :68-70 pins php 8.2.12 while backend/DEPLOY_LOG.md:3 states production is PHP 8.3. The deployment situation is worse than the auditor described: DEPLOY_LOG.md:6-9 says SSH is disabled by the host, so there is no composer/artisan/git on the server at all and deployment is exclusively manual zip upload via cPanel File Manager (.cpanel.yml at repo root is therefore not even executed; its vendor exclusion is moot). Laravel 11's security window did end 2026-03-12 per the published support table, so the framework is past security EOL today (2026-09-14). I ran `composer audit --locked` (read-only) against the lockfile: it reports 40 advisories across 12 packages, including laravel/framework itself (CVE-2026-48019 CRLF injection in the default `email` validation rule, high; plus a signed-URL path-confusion advisory), phpoffice/phpspreadsheet 5.8.0 (three HIGH: CVE-2026-59931/59932/59933), guzzle/psr7, symfony/http-kernel (high), symfony/mime (high), league/commonmark (multiple high). No mitigation exists in-repo: no vendor directory is committed (backend/.gitignore:23), no CI, no .github, no audit step; DEPLOYMENT.md only lists a pre-prod checklist without dependency hygiene. Partial mitigations that temper exploitability: AttendanceImportController.php:21 validates `mimes:xlsx` before IOFactory::load (:33), so the XLS/OLE and Gnumeric memory-exhaustion CVEs are not reachable via that upload; the API sends no email and does not render Markdown, so mailer/mime/commonmark advisories are mostly latent; upload endpoints are authenticated (teacher/manager gates). Those reduce immediate exploitability but do not address the structural problem (EOL framework, no patch path, already-present advisories including one in the framework's own validation rule used by AuthController and user-creation flows). Severity high stands; a small Arabic-only deployment does not change the fact that the codebase currently ships with 40 known advisories and no way to patch quickly.

```text
backend/composer.lock:1133-1134 laravel/framework v11.51.0; :1407-1408 laravel/sanctum v4.3.2; :2444-2445 mpdf/mpdf v8.3.1; :3136-3137 phpoffice/phpspreadsheet 5.8.0. backend/composer.json:9 "laravel/framework": "^11.0"; :60 "platform-check": false; :68-70 "platform": {"php": "8.2.12"}. `git log -- composer.lock composer.json` -> single commit be47c33 2026-06-25. backend/DEPLOY_LOG.md:3 "PHP 8.3"; :6-9 SSH disabled by host, no CLI/artisan/composer/git on server, deployment exclusively manual via cPanel File Manager (so .cpanel.yml:6 is not even the active path). backend/.gitignore:23 "/vendor" (vendor not versioned; no vendor dir present locally either). No .github/ or CI config anywhere in the repo. `php composer.phar audit --locked` (run 2026-09-14): "Found 40 security vulnerability advisories affecting 12 packages" — laravel/framework (CVE-2026-48019 CRLF injection in default email rule, high; Temporary Signed URL Path Confusion, medium), phpoffice/phpspreadsheet (CVE-2026-59931/59932/59933, all high), symfony/http-kernel CVE-2026-45075 (high), symfony/mime CVE-2026-45067 (high), guzzlehttp/guzzle CVE-2026-69246 (high), league/commonmark (8 high). Partial mitigation: backend/app/Http/Controllers/Api/AttendanceImportController.php:21 `mimes:xlsx` gate before IOFactory::load at :33 blocks the XLS/OLE and Gnumeric reader paths; uploads are behind teacher/manager auth.
```

</details>

### Schema changes are applied as hand-written SQL in phpMyAdmin from files outside the repo; the migrations table is not maintained and there is no rollback

<a id="migrations-hand-run-sql"></a>

`migrations-hand-run-sql` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/DEPLOY_LOG.md:21-22`, `backend/DEPLOY_LOG.md:40-46`, `backend/database/migrations/2026_09_08_100000_make_target_teacher_nullable_on_student_requests.php`, `backend/database/migrations/2026_09_11_100000_add_parent_code_sequence.php`

**Evidence**

```text
DEPLOY_LOG.md:21-22: 'Migrations are attached as raw SQL (no artisan migrate on the server). New .env keys are listed explicitly and added to the server manually.' DEPLOY_LOG.md:43: the SQL lives at `C:\mutqin-deploy\mutqin-clean-2026-09-11.sql` — `git ls-files | grep -E '\.sql$'` returns nothing, so schema artifacts are not versioned. Two migrations were added after the last 'done' deploy (`git log --diff-filter=A --since=2026-09-06 -- backend/database/migrations` → a07327d, cb260ec). The 09-11 procedure (DEPLOY_LOG.md:45) is 'import the dump (contains DROP TABLE IF EXISTS for every table)' — a full replace, not a migration.
```

**Why it matters**

Every schema change is a manual, untested, irreversible SQL edit on live data; a typo or a half-applied ALTER leaves production diverged from the 35 tracked migrations with no `migrations`-table record, so future tooling cannot reason about state. This is the single largest source of data-loss risk once real centers are live.

**Recommendation**

Get a CLI: ask Libyan Spider to enable cPanel Terminal/SSH (or move the API to a small VPS/Forge/Ploi). Until then, make .cpanel.yml call a one-shot, token-protected PHP deploy script that runs `Artisan::call('migrate --force')` and `config:cache`, and commit generated SQL (`php artisan migrate --pretend`) per release alongside a `down` script; always dump before migrating.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

The core factual claim holds: backend/DEPLOY_LOG.md:5-8 and 21-22 state the host has SSH disabled and migrations are applied as raw SQL via phpMyAdmin, and `git ls-files | grep '\.sql$'` returns nothing (the referenced C:\mutqin-deploy directory does not even exist on this machine now, so the dump artifacts are unversioned and already lost locally). However several parts of the evidence are overstated or wrong: (1) "the migrations table is not maintained" is not supported — the 09-10 and 09-11 procedures are full dumps produced by `migrate:fresh --seed` locally (DEPLOY_LOG.md:57-58, 47-49), which include the `migrations` table with all 35 rows; the schema in those dumps is exactly what the tracked migrations produce, and the 09-11 entry explicitly says it supersedes the hand-SQL for the two post-deploy migrations. (2) The raw SQL for both cited migrations IS versioned — each migration file carries the equivalent statement in its docblock (2026_09_08...:12, 2026_09_11...:10-11), and both have working `down()` methods. (3) The repo has a root `.cpanel.yml` (commit 9831f1c) doing rsync deploys from cPanel Git Version Control, so code deploy is no longer purely manual; a `php backend/artisan migrate --force` task could be added there directly (if PHP CLI is reachable in the deploy hook) — the recommendation of a token-protected web script is one option, not the only one. (4) Impact is prospective: per DEPLOY_LOG.md:46-52 the production DB currently holds demo data or the empty 09-11 baseline (status pending) — no real center data exists yet, so a DROP+import today is a legitimate reset, not data loss. The finding is a real operational/process gap (no migration automation, no versioned SQL/rollback artifacts, no automated backup) that becomes serious once real data lands, but it is not a current code defect and is partly mitigated. Medium, not high.

```text
backend/DEPLOY_LOG.md:5-8 (SSH disabled, no artisan on server); backend/DEPLOY_LOG.md:21-22 (raw-SQL migration policy); backend/DEPLOY_LOG.md:44-49 (09-11 full dump from migrate:fresh — includes migrations table, supersedes hand SQL for both new migrations; status pending, DB not yet live); backend/database/migrations/2026_09_08_100000_make_target_teacher_nullable_on_student_requests.php:12 and 2026_09_11_100000_add_parent_code_sequence.php:10-11 (equivalent raw SQL versioned in docblocks; both have down()); .cpanel.yml:1-6 (git-based rsync deploy already exists — no migrate step, could host one); DEPLOYMENT.md:15 (mysqldump backup only recommended, not automated); C:\mutqin-deploy does not exist locally — dump artifacts unversioned and not recoverable from repo.
```

</details>

### Rate limiting covers only the 3 auth endpoints, keyed per IP with no proxy trust configuration; authenticated API has no limiter

<a id="rate-limit-per-ip-only"></a>

`rate-limit-per-ip-only` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/routes/api.php:17`, `backend/routes/api.php:20-21`, `backend/bootstrap/app.php:14-21`, `backend/.env.production.example:58`

**Evidence**

```text
routes/api.php:17 `->middleware('throttle:10,1'); // ... 10 attempts/minute per IP`, :20 `throttle:5,1`, :21 `throttle:10,1`. `grep -rn 'RateLimiter::for' app bootstrap routes` and `grep -rn 'trustProxies' bootstrap app config` both return nothing; bootstrap/app.php:14-21 registers only the four role aliases. 94 route definitions exist, 3 are throttled. The limiter's backing store in production is `CACHE_STORE=file` (.env.production.example:58).
```

**Why it matters**

Libyan mobile carriers use carrier-grade NAT: hundreds of app users can share one public IP, so 10 logins/minute/IP produces false lockouts on launch day or after a token-revoking password change. Conversely, authenticated endpoints (reports, PDF generation via mPDF, xlsx parsing) have no per-user limit on a shared host with a single PHP-FPM pool. If a CDN/WAF (e.g. Cloudflare) is ever placed in front, all traffic collapses to a handful of IPs without `trustProxies`.

**Recommendation**

Define `RateLimiter::for('login', fn($r) => [Limit::perMinute(10)->by(strtolower($r->input('email')).'|'.$r->ip()), Limit::perMinute(200)->by($r->ip())])`; add `throttleApi()` (e.g. 120/min by user id) for the authenticated group; configure `$middleware->trustProxies(at: ...)` the day a proxy is introduced; load-test login from one IP before launch.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 75% · corrected severity: low

Evidence verified. backend/routes/api.php:17,20,21 are the only `throttle:` usages; bootstrap/app.php registers only the four role aliases — no `throttleApi()`, no `RateLimiter::for(...)`, no `trustProxies`. I checked Laravel 11.x framework source (composer.lock pins v11.51.0): `Middleware::getMiddlewareGroups()` adds `'throttle:'.$this->apiLimiter` to the `api` group ONLY when `throttleApi()` was called, and `ApplicationBuilder` defines no default `api` limiter — so the ~95 authenticated routes truly have no rate limit. With an unauthenticated request, `ThrottleRequests::resolveRequestSignature` keys on domain|IP, so login is IP-keyed as claimed; `AuthController::login` has no per-email lockout (the only attempt counter is the OTP `attempts >= 5` at AuthController.php:190, which is for password reset, not login). tests/Feature/AuthLoginTest.php:34-46 confirms 10-then-429 behaviour but does not cover per-account keying. So the finding is factually correct. However it is over-rated: (1) the missing `trustProxies` is currently the SAFE default (X-Forwarded-For spoofing cannot bypass the limiter; no proxy exists and DEPLOYMENT.md prescribes plain Apache/nginx), so that part is speculative future-proofing; (2) the authenticated-endpoint gap is reachable only by holders of a valid Sanctum token (admins, managers, teachers, parents of a small closed user base), i.e. an insider-availability concern, not an exposure; (3) the CGNAT false-lockout scenario is plausible for Libyan carriers but the user population per center is small, the 429 clears in 60 s, and successful logins are rare per user (7-day tokens). Net: real hardening item (add a per-email+IP composite login limiter and `throttleApi()`), but a low-severity operational/availability issue rather than medium.

```text
backend/routes/api.php:17,20,21 — sole throttle middleware usages (IP-keyed since request is unauthenticated). backend/bootstrap/app.php:14-21 — withMiddleware only registers aliases; no throttleApi()/trustProxies. Laravel 11.x Illuminate/Foundation/Configuration/Middleware.php getMiddlewareGroups(): 'api' group contains 'throttle:'.$this->apiLimiter only when set — confirms authenticated routes are unlimited. backend/app/Http/Controllers/Api/AuthController.php:190 — only attempt counter is OTP reset (`attempts >= 5`), no login lockout per account. backend/tests/Feature/AuthLoginTest.php:34-46 — test_login_is_throttled_after_10_attempts asserts 429 on 11th attempt (single IP, single email; does not distinguish keying). DEPLOYMENT.md:13 — production target is direct Apache/nginx, no reverse proxy/CDN planned, so absent trustProxies is currently the correct (stricter) configuration.
```

</details>

### HTTPS is not enforced or asserted anywhere in the repo (no redirect, no HSTS, no forceScheme, no security headers); it depends entirely on an unverified cPanel setting

<a id="https-not-enforced-no-security-headers"></a>

`https-not-enforced-no-security-headers` · 🟡 medium · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `frontend-html/js/config.js:12`, `backend/public/.htaccess:13-37`, `backend/app/Providers/AppServiceProvider.php:20-23`, `DEPLOYMENT.md:16`

**Evidence**

```text
DEPLOYMENT.md:16 lists 'HTTPS — TLS certificate mandatory — tokens are sent in the Authorization header' as a checklist item, but: `ls frontend-html/.htaccess` → 'no frontend-html/.htaccess'; backend/public/.htaccess:13-37 contains only Laravel's stock rewrite rules (no `RewriteCond %{HTTPS} off`, no `Strict-Transport-Security`, no `X-Content-Type-Options`/`X-Frame-Options`/CSP); AppServiceProvider.php:20-23 `boot()` is empty (no `URL::forceScheme('https')`); `grep -rn 'forceScheme|secure' app config/app.php bootstrap` yields only the stock comment. config.js:12 uses a scheme-relative path, so a user who types `http://mutqin.ly` transmits the Bearer token in clear.
```

**Why it matters**

Bearer tokens (7-day lifetime, admin ability `*`) can be captured on the first plain-HTTP visit; Flutter builds must reject cleartext, but the server offers no HSTS to protect the web client; missing headers weaken the PWA against clickjacking/MIME sniffing.

**Recommendation**

Add a root `.htaccess` in frontend-html with `RewriteCond %{HTTPS} !=on → 301 https`, `Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"`, `X-Content-Type-Options nosniff`, `X-Frame-Options DENY`, `Referrer-Policy strict-origin-when-cross-origin`; verify cPanel 'Force HTTPS Redirect' and AutoSSL renewal; record the check in DEPLOYMENT.md.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

All quoted evidence checks out. frontend-html/.htaccess does not exist; the only .htaccess files are backend/.htaccess (a pure "Require all denied" fence) and backend/public/.htaccess (stock Laravel rewrite rules plus an explicit "Require all granted" block) — neither contains a RewriteCond %{HTTPS} redirect, an HSTS header, or any X-Content-Type-Options / X-Frame-Options / CSP / Referrer-Policy header. AppServiceProvider::boot() is empty (no URL::forceScheme), bootstrap/app.php registers only the four role aliases (no global security-header middleware, no TrustProxies/HTTPS enforcement), and a repo-wide grep for forceScheme/Strict-Transport/X-Frame/nosniff hits only stock config comments (config/app.php:96, config/session.php:163-172). APP_URL defaults to http://localhost in .env.example and config/app.php:55. config.js:12 builds the production API base as the scheme-relative path '/backend/public/api', so requests inherit whatever scheme the page was loaded with. DEPLOYMENT.md:16 merely lists "HTTPS — TLS certificate mandatory" as a checklist item with no verification step or config artifact. No feature test covers transport security (none can — it is a web-server concern). Mitigations exist only outside the repo (cPanel Force-HTTPS/AutoSSL), which the auditor correctly labels unverified. Minor nuance the auditor overstates: because the browser origin includes the scheme, a token stored under the https origin's localStorage is NOT sent when the user types http:// — the leak occurs only if the user logs in and works over plain HTTP, or via SSL-stripping on an un-HSTS'd domain. That keeps the practical exposure at medium rather than high; the finding is otherwise accurate and not mitigated in code.

```text
frontend-html/.htaccess: absent (ls confirms). backend/.htaccess:10-18 = Require all denied only. backend/public/.htaccess:1-11 = Require all granted; :13-37 = stock Laravel rewrites, no HTTPS RewriteCond and no Header directives. backend/app/Providers/AppServiceProvider.php:20-23 boot() empty. backend/bootstrap/app.php:14-20 only role middleware aliases. backend/config/app.php:55 'url' => env('APP_URL','http://localhost'); backend/.env.example:5 APP_URL=http://localhost. frontend-html/js/config.js:10-12 scheme-relative '/backend/public/api' in production. DEPLOYMENT.md:16 checklist row only. Nuance: localStorage is per-origin (scheme included), so a token saved on https://host is not transmitted on an http://host visit; exposure requires an all-HTTP session or SSL-strip MITM (no HSTS).
```

</details>

### In-place `rsync --delete` deploy with no releases/symlink layout, no post-deploy cache rebuild, and no way to enter maintenance mode

<a id="no-rollback-no-maintenance-mode"></a>

`no-rollback-no-maintenance-mode` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `.cpanel.yml:5-7`, `backend/public/index.php:11-13`, `backend/.env.production.example:32`

**Evidence**

```text
.cpanel.yml:5-6 rsync `--delete` straight into `$DEPLOYPATH` (frontend first, backend second — not atomic), :7 `/bin/rm -f $DEPLOYPATH/backend/bootstrap/cache/*.php` clears config/route caches but nothing regenerates them (no `php artisan config:cache`/`route:cache` possible), so production boots from raw config + .env on every request. public/index.php:11-13 honours `storage/framework/maintenance.php`, but `php artisan down` is unavailable (no CLI) and no documented manual equivalent exists. No previous-release directory is kept.
```

**Why it matters**

A bad deploy cannot be reverted faster than a full re-deploy of an older commit; during the two-rsync window the new frontend can call the old API; there is no user-facing maintenance response for the mobile app during risky operations (e.g. the DROP-TABLE reimports).

**Recommendation**

Adopt a `releases/<sha>` + `current` symlink layout in .cpanel.yml (rsync into a new dir, then `ln -sfn`), keep the last 3 releases, and document a manual maintenance toggle (upload a prepared `storage/framework/maintenance.php` returning the JSON envelope with 503 + Retry-After so Flutter can show a proper screen).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The cited evidence exists verbatim (.cpanel.yml:5-7 two rsync --delete passes then rm -f bootstrap/cache/*.php; public/index.php:11-13 honours storage/framework/maintenance.php; .env.production.example sets APP_MAINTENANCE_DRIVER=file). But several of the auditor's conclusions are wrong or overstated. (1) "clears config/route caches but nothing regenerates them" is a non-issue, not a defect: per backend/DEPLOY_LOG.md:6-8 the host has no SSH/CLI at all, so config:cache/route:cache have never been run and those files never exist on the server; the rm -f is a correct safeguard against a stale cache, and booting from raw config + .env is Laravel's default (perf-only, ~ms, not correctness). Laravel's packages.php/services.php manifests regenerate automatically on first request when missing. (2) "cannot be reverted faster than a full re-deploy of an older commit" — with cPanel Git Version Control the repo clone lives on the server, so reverting is pushing/resetting to a previous commit and clicking Deploy HEAD; the whole "deploy" is a 7-line rsync taking seconds. A releases/symlink layout would save essentially nothing for a small single-server app, and DEPLOY_LOG shows the team actually deploys mostly by File Manager edit/upload anyway. (3) The non-atomic window between the two rsyncs is a few seconds on a low-traffic Libyan center system. (4) The real residual gap is real but small: no documented manual maintenance toggle (uploading a prepared storage/framework/maintenance.php via File Manager would work, since index.php:11-13 requires it before vendor autoload), and the frontend api.js has no 503 handling — relevant because DEPLOY_LOG documents live phpMyAdmin imports with DROP TABLE IF EXISTS (e.g. e7c50f9, c80e8f5 entries) performed against the live DB with no maintenance window. That justifies a low-severity documentation/ops note, not medium.

```text
.cpanel.yml:5-7 (two rsync --delete passes, rm -f bootstrap/cache/*.php); backend/DEPLOY_LOG.md:6-9 (SSH/CLI/artisan/composer/git all unavailable on host — so config/route caches were never generated, rm is a no-op safeguard; deploys are manual via File Manager); backend/DEPLOY_LOG.md entries e7c50f9 and c80e8f5 (live SQL imports containing DROP TABLE IF EXISTS with no maintenance window documented); backend/public/index.php:11-13 (maintenance.php honoured — a manual upload via File Manager would work but is undocumented); frontend-html/js/api.js (no 503/maintenance handling).
```

</details>

### No scheduler or cron: expired Sanctum tokens, stale OTP rows and notifications are never pruned; the only recurring job is an external, demo-grade n8n workflow

<a id="no-scheduler-no-pruning"></a>

`no-scheduler-no-pruning` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/routes/console.php:1-8`, `backend/app/Http/Controllers/Api/AuthController.php:63`, `backend/config/sanctum.php:55`, `n8n/mutqin-daily-attendance-digest.json:31-44`, `n8n/README.md:19-22`

**Evidence**

```text
routes/console.php contains only the stock `inspire` command; `grep -rn 'Schedule::' routes app bootstrap | wc -l` = 0. Every login creates a new token (AuthController.php:63 `$user->createToken('auth_token', $abilities)`), tokens expire after `10080` minutes (sanctum.php:55) but `sanctum:prune-expired` is never scheduled. The n8n workflow logs in daily as `admin@mutqin.ly` (json:31-44, password placeholder `PUT_PASSWORD_HERE`, apiBase `http://localhost:9090`) and never calls /auth/logout — one more admin-ability token per day; README.md:21-22 admits 'The password sits in plain text for demo convenience'.
```

**Why it matters**

Unbounded growth of `personal_access_tokens`, `otp_resets`, `notifications` and `messages` on a shared-host DB quota; the digest depends on a full-privilege admin credential held outside the system with no service-account or API-key concept; nothing in-app can run on a schedule (reminders, digests, backups).

**Recommendation**

Add a cPanel cron `* * * * * /usr/local/bin/php /home/[redacted-cpanel-user]/public_html/backend/artisan schedule:run` (cPanel cron works without SSH) and schedule `sanctum:prune-expired --hours=24`, OTP/notification pruning and the DB dump; replace the n8n admin login with a dedicated least-privilege service user + long-lived named token stored in an n8n credential.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Evidence verified as stated: backend/routes/console.php has only the stock `inspire` command; grep for `Schedule::`/`->schedule`/`withSchedule`/`prune` across routes, app, bootstrap and config returns nothing; sanctum.php:55 sets `'expiration' => 10080`; AuthController.php:63 calls `$user->createToken(...)` on every login with no prior `$user->tokens()->delete()`, and logout (line 91) deletes only the current token. The n8n workflow (json:31-44, :70) logs in as admin@mutqin.ly with a plain-text password placeholder and never calls /auth/logout; README.md:19-22 admits this. No mitigation was found: DEPLOYMENT.md has no cron/schedule:run/prune mention, no notification pruning code exists, and a `messages` table migration does exist. Partial mitigations that reduce (not eliminate) impact: expired tokens are rejected by Sanctum regardless of pruning (so this is storage growth, not a security hole); OtpReset rows for a given user are deleted on each new request and on successful verify (AuthController.php:139, 204), so `otp_resets` growth is bounded per-user and effectively small — the auditor overstated that table; deactivation and password change revoke all tokens. Given the small scale (a few centers, tens of users, one login per session, one n8n token/day), unbounded growth is slow — years to matter on a typical shared-host quota. The finding is factually correct but its practical impact for this product is low-to-medium; the n8n admin-credential design is the more meaningful part and is essentially an operational/hardening note for a workflow explicitly labelled demo-grade. Severity is better rated low.

```text
backend/routes/console.php:1-8 (only `inspire`); grep 'Schedule::|withSchedule|prune' over backend/routes, app, bootstrap, config = 0 hits; backend/config/sanctum.php:55 `'expiration' => 10080`; backend/app/Http/Controllers/Api/AuthController.php:63 createToken on every login, :91 logout deletes only currentAccessToken, no `$user->tokens()->delete()` at login. Mitigation for otp_resets: AuthController.php:139 and :204 delete all OtpReset rows per user on new request / successful verify, so that table does not grow unboundedly (auditor overstated). n8n/mutqin-daily-attendance-digest.json:31-44 (admin@mutqin.ly, PUT_PASSWORD_HERE), :70 login URL, no logout call in the workflow or attendance-digest.code.js; n8n/README.md:19-22 plain-text password admission. DEPLOYMENT.md contains no cron/schedule:run guidance. backend/database/migrations/2026_08_22_100000_create_messages_table.php confirms a messages table exists with no pruning.
```

</details>

### Entire Laravel tree (.env, storage/logs, vendor) sits under public_html guarded only by .htaccess, and rsync ships dev artifacts (composer.phar 3.5 MB, tests/, check_excel.php, DEPLOY_LOG.md, .env.production.example) to production

<a id="laravel-tree-in-webroot-dev-artifacts-deployed"></a>

`laravel-tree-in-webroot-dev-artifacts-deployed` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `.cpanel.yml:6`, `backend/.htaccess:10-19`, `backend/DEPLOY_LOG.md:4`, `backend/composer.phar`, `backend/check_excel.php:1-9`, `frontend-html/README.md`

**Evidence**

```text
DEPLOY_LOG.md:4: web root `/home/[redacted-cpanel-user]/public_html/` with the Laravel tree at `public_html/backend/`. .cpanel.yml:6 excludes only `vendor`, `.env`, `storage`, `bootstrap/cache`, so `git ls-files backend` items such as `composer.phar` (3,565,131 bytes), `check_excel.php`, `generate_test_excel.php`, `attendance_test.xlsx`, `tests/**`, `phpunit.xml`, `DEPLOY_LOG.md`, `.env.example`, `.env.production.example` are all copied into the web tree. The sole protection is backend/.htaccess:10-19 `Require all denied`; if the host ever sets `AllowOverride None` or the file is lost in a File-Manager edit, `/backend/.env` and `/backend/storage/logs/laravel-*.log` become downloadable. .cpanel.yml:5 also publishes `frontend-html/README.md` at `https://mutqin.ly/README.md` (lists demo account emails, internals).
```

**Why it matters**

Defence-in-depth is one config directive deep for the file containing DB credentials and APP_KEY; dev tooling and internal deployment notes are on the production box; a public README leaks account naming conventions.

**Recommendation**

Prefer a DocumentRoot that is `backend/public` (cPanel subdomain `api.mutqin.ly` → `/home/[redacted-cpanel-user]/api/public`) with the tree outside public_html; meanwhile extend .cpanel.yml excludes (`tests`, `composer.phar`, `*.md`, `*.xlsx`, `check_excel.php`, `generate_test_excel.php`, `.env*`, `phpunit.xml`, `README.md`) and add `-e stat` checks; move `composer.phar` out of the repo (document install path instead).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 70% · corrected severity: low

The factual core holds. `.cpanel.yml:5-6` is exactly as quoted: frontend-html/ rsyncs to `public_html/` (no exclude for README.md) and backend/ rsyncs to `public_html/backend/` excluding only vendor/.env/storage/bootstrap/cache. Commit 9831f1c confirms it was added for cPanel "Git Version Control" auto-deploy, and DEPLOY_LOG.md:4 confirms the Laravel tree lives at `public_html/backend/`. `git ls-files backend` really does include composer.phar (3,565,131 bytes), check_excel.php, generate_test_excel.php, attendance_test.xlsx, phpunit.xml, DEPLOY_LOG.md, .env.example, .env.production.example and tests/**; none are excluded, and the initial manual upload ("re-zip الحزمة الكاملة الأولى", DEPLOY_LOG.md:35-36) shipped the same tree. So dev artifacts are on the production box and the only HTTP barrier for `.env`/`storage/logs` is backend/.htaccess.

However the finding overstates the fragility and one sub-claim is moot:
1. The .htaccess guard is stronger than "one directive deep": it carries both Apache 2.4 (`Require all denied`) and 2.2 (`Order/Deny`) forms, is tracked in git and re-laid by every rsync (`--delete` restores it if lost in a File-Manager edit), and public/.htaccess explicitly re-grants only `public/`. Moreover, the application itself depends on `AllowOverride` being honoured (Laravel's rewrite to index.php lives in public/.htaccess) — if the host switched to `AllowOverride None`, the API would visibly break rather than silently exposing files. cPanel shared hosts uniformly ship AllowOverride All for this reason.
2. The `Require all denied` also blocks direct execution of check_excel.php / generate_test_excel.php and download of composer.phar/xlsx, so the dev artifacts are dead weight rather than an attack surface while the guard stands.
3. The "README leaks demo account emails" point is negligible: demo accounts are a deliberate public feature (`GET /api/public/demo-accounts`, shown on login.html), and the README's table is stale anyway (password `password`, `teacher1@`, CORS `*` — all wrong for production), so it reveals nothing beyond what the login page already displays.

Net: a real but ordinary shared-hosting hygiene issue (no DocumentRoot at backend/public, dev files and 3.5 MB phar shipped, internal deploy log on the box). The recommendations (extend excludes, drop composer.phar from the repo, prefer a public/ docroot) are sound, but the concrete exposure risk relies on a hypothetical host misconfiguration that would also break the app, so low rather than medium.

```text
.cpanel.yml:5 `rsync -a --delete --exclude='backend' --exclude='.well-known' --exclude='cgi-bin' frontend-html/ $DEPLOYPATH/` — frontend-html/README.md is published (content stale: says password `password`, `teacher1@mutqin.ly`, CORS `*`; demo accounts are already public via /api/public/demo-accounts, so no real leak). .cpanel.yml:6 excludes only vendor/.env/storage/bootstrap/cache — confirmed. backend/.htaccess:10-19 dual-syntax deny (mod_authz_core + mod_access_compat), tracked in git and restored by rsync --delete on every deploy; backend/public/.htaccess:1-11 re-grants only public/ and hosts the Laravel rewrite that itself requires AllowOverride to be honoured. `git ls-files backend` confirms composer.phar (3,565,131 B), check_excel.php, generate_test_excel.php, attendance_test.xlsx, phpunit.xml, DEPLOY_LOG.md, .env.example, .env.production.example, tests/** are all deployed. DEPLOY_LOG.md:4 confirms `public_html/backend/` layout; DEPLOY_LOG.md:35-36 confirms initial full-tree zip upload.
```

</details>

### Plaintext OTP and phone number are written to the application log at info level in every environment; only LOG_LEVEL=error hides it in production, contrary to the docs

<a id="otp-and-phone-logged-plaintext"></a>

`otp-and-phone-logged-plaintext` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:216-219`, `backend/.env.production.example:39`, `DEPLOYMENT.md:37`

**Evidence**

```text
AuthController.php:218 `Log::info("OTP password-reset for user #{$user->id} (phone {$user->phone}): {$otp}");` inside `sendOtp()` with no environment guard (the `environment('local')` check at :153 covers only the HTTP response field `dev_otp`). DEPLOYMENT.md:37 claims the OTP appears 'in storage/logs/laravel.log — local environment only', which the code does not enforce. It is invisible in production solely because .env.production.example:39 sets `LOG_LEVEL=error`; the moment an operator lowers the level to debug to troubleshoot, live password-reset codes and PII land in a file under the web root.
```

**Why it matters**

A password-reset bypass (read the log, reset any teacher/parent account) and a PII leak are one env-var change away; the log sits at `public_html/backend/storage/logs/` behind a single .htaccess rule. Mobile adoption will make OTP the primary recovery path for parents.

**Recommendation**

Remove the OTP and phone from the log line (log `user_id` and a masked phone only), gate any secret-bearing debug output on `app()->environment('local')`, and correct DEPLOYMENT.md:37. When the SMS gateway is wired, log provider message-id, never the code.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted evidence is accurate. backend/app/Http/Controllers/Api/AuthController.php:216-220 `sendOtp()` unconditionally calls `Log::info("OTP password-reset for user #{$user->id} (phone {$user->phone}): {$otp}")`; the only `app()->environment('local')` guard (line 153) applies to the `dev_otp` response field, not the log. `Log::info` is the only non-warning/error log call in app/, so this is the sole secret-bearing log line. config/logging.php gates every channel on `env('LOG_LEVEL','debug')`, so the line is suppressed in production only because .env.production.example:39 sets `LOG_LEVEL=error` (and .env.example:21 ships `LOG_LEVEL=debug`). DEPLOYMENT.md:37 does claim the log entry is 'local only', which the code does not enforce — the doc is factually wrong. No feature test asserts anything about logging (OtpResetTest/PhoneNormalizationTest do not fake Log).

Mitigations that reduce severity but do not eliminate it: (1) backend/.htaccess is a deny-all (`Require all denied` / `Deny from all`) for the whole Laravel tree, so the log is not reachable over HTTP on Apache — the auditor's 'behind a single .htaccess rule' is correct, and exposure requires file-level access (cPanel file manager, backups, shared-host neighbours) rather than a URL. (2) Production default LOG_LEVEL=error hides it out of the box. (3) OTP is 10-minute, 5-attempt, and admin accounts are excluded from the flow (line 132). (4) The stored OTP is hashed, so the log is indeed the only recoverable copy, but exploiting it needs both an operator lowering LOG_LEVEL and log-file access. Note also that with no SMS gateway, the log line is currently the *only* delivery path outside `local`, so an operator enabling info logging in production to make OTP reset work at all is a realistic (not hypothetical) scenario — which supports the finding.

Real defect (CWE-532 logging of a credential + PII, plus an inaccurate deployment doc), but the exploit chain needs two preconditions (non-default LOG_LEVEL and server-file access), the HTTP path is already blocked, and admin accounts are out of scope. Downgrade from medium to low.

```text
backend/app/Http/Controllers/Api/AuthController.php:153 (`if (app()->environment('local'))` guards only `$neutral['dev_otp']`), :218 (`Log::info(... (phone {$user->phone}): {$otp}")` with no guard); backend/config/logging.php:64,71 (`'level' => env('LOG_LEVEL','debug')`); backend/.env.example:21 `LOG_LEVEL=debug`; backend/.env.production.example:38-39 `LOG_CHANNEL=daily`/`LOG_LEVEL=error`; DEPLOYMENT.md:37 inaccurate 'local only' claim for the log; mitigation: backend/.htaccess lines 11-19 deny all HTTP access to the Laravel tree including storage/logs; AuthController.php:132 excludes admin from OTP reset.
```

</details>

### Static frontend has no cache-busting or caching policy, and depends on two third-party CDNs with no SRI or fallback

<a id="frontend-no-cache-busting-external-deps"></a>

`frontend-no-cache-busting-external-deps` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/login.html:8-9`, `frontend-html/login.html:99-103`, `frontend-html/admin/dashboard.html:7-17`, `frontend-html/css/theme.css:6`, `frontend-html/js/config.js:28-44`

**Evidence**

```text
login.html:99-103 `<script src="js/config.js">`…`<script src="js/pages/login.js">` — `grep -rn '\?v=' --include=*.html --include=*.js frontend-html` returns nothing (no query-string or hashed filenames), and there is no frontend `.htaccess` setting `Cache-Control`. 33 references to `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css` and theme.css:6 `@import url('https://fonts.googleapis.com/css2?family=Amiri...&family=Cairo...')`; `grep -rn 'integrity=' frontend-html | wc -l` = 0. config.js:28-44 injects PWA 'standalone' meta on every page, which makes home-screen installs cache more aggressively.
```

**Why it matters**

After each deploy (there were 46 commits on 2026-09-11 alone) users get new HTML with a stale heuristically-cached ui.js/layout.js, producing 'undefined' pages until a hard refresh; if jsDelivr or Google Fonts is unreachable (regional outages are common), the RTL layout collapses and fonts fall back — and nothing in the app degrades gracefully.

**Recommendation**

Version assets on deploy (`?v=<git sha>` injected by .cpanel.yml with `sed`, or hashed filenames), set `Cache-Control: no-cache` for HTML and `immutable, max-age=31536000` for versioned assets in a frontend `.htaccess`; self-host bootstrap.rtl.min.css and the two font families (or add SRI + local fallback).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Core facts verified: frontend-html/login.html:8 loads bootstrap.rtl.min.css from jsDelivr (33 occurrences across pages, 0 `integrity=` attributes), theme.css:6 @imports Google Fonts, login.html:99-103 loads js/*.js with no `?v=` (grep count 0), no `.htaccess` anywhere in frontend-html, and .cpanel.yml just rsyncs frontend-html/ into public_html with no versioning step. So the finding is not factually wrong. However, several parts are exaggerated or incorrect: (1) config.js:28-44 explicitly injects only manifest/meta tags and the comment states "لا service worker" — without a service worker, standalone/home-screen mode does NOT change HTTP caching behavior at all; that claim is wrong. (2) Without Cache-Control, Apache/cPanel emits Last-Modified+ETag and browsers apply heuristic freshness (~10% of file age); rsync after a fresh cPanel git checkout gives changed files a recent mtime, so the stale window for just-changed files is short, and a plain F5 (not a hard refresh) revalidates subresources. (3) The "undefined pages" example is misattributed — commit 37313bf was a payload/role bug fixed in code, not a cache issue. (4) Only Bootstrap CSS is loaded (no bootstrap.bundle.js, no `new bootstrap.` in js/), so a jsDelivr outage degrades styling/RTL grid but all app JS and API calls keep working; Google Fonts failure just falls back to system fonts via the font-family stack — that is graceful degradation by definition. For a small single-country internal tool with no build step, this is an operational hygiene gap, not a medium-risk defect. Downgrade to low; recommendation (asset versioning + .htaccess cache headers, optionally self-hosting Bootstrap RTL CSS) remains reasonable.

```text
frontend-html/login.html:8 (CDN CSS, no integrity); frontend-html/login.html:99-103 (unversioned js/*.js); frontend-html/css/theme.css:6 (Google Fonts @import); frontend-html/js/config.js:30 comment "لا service worker" and :36-44 (only manifest/meta injection — no effect on HTTP caching); .cpanel.yml:4 (rsync -a, no versioning); no frontend-html/.htaccess exists; grep for bootstrap.bundle/`new bootstrap.` in frontend-html/js returns nothing (CSS-only Bootstrap dependency).
```

</details>

### Operational documentation contradicts the code: CORS, API URL, demo credentials, test counts, deploy mechanism, launcher paths and the backend README are stale

<a id="ops-docs-drift"></a>

`ops-docs-drift` · 🟡 medium · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `DEPLOYMENT.md:11`, `DEPLOYMENT.md:13`, `frontend-html/README.md:8`, `frontend-html/README.md:19-27`, `backend/README.md:1-58`, `backend/DEPLOY_LOG.md:8-9`, `تشغيل-المشروع.bat:23-27`, `CLAUDE.md`

**Evidence**

```text
DEPLOYMENT.md:11 'config/cors.php: replace allowed_origins => ['*']' — cors.php:14-17 already reads CORS_ALLOWED_ORIGINS and defaults closed. DEPLOYMENT.md:13 'update frontend-html/js/config.js (API_BASE_URL)' — config.js:10-12 is now runtime-detected. frontend-html/README.md:8 `const API_BASE_URL = 'http://localhost:9090/api';`, :19 'the API allows all origins (allowed_origins: ['*'])', :21-27 demo password `password` with `teacher1@mutqin.ly` — seeders use `[redacted-demo-password]` (LibyanDataSeeder.php:40-43) and `[redacted-demo-password]` (ExtraDataSeeder.php:35). backend/README.md:1-58 is the untouched stock Laravel README ('Laravel is a web application framework…'). DEPLOY_LOG.md:8-9 'deployment is manual exclusively … nothing reaches the server by itself' vs .cpanel.yml auto-deploy. The .bat:23 `cd /d C:\xampp\htdocs\MUTQENQ\backend` and :27 `cd … \frontend && php artisan serve --port=9091` — `frontend/` is not tracked (`git ls-files | grep '^frontend/'` → nothing) and the repo lives at C:\Users\HP\SRS\MUTQIN. CLAUDE.md says '20 feature-test files' — `ls backend/tests/Feature | wc -l` = 38; says demo password `[redacted-demo-password]` — LibyanDataSeeder uses `[redacted-demo-password]`.
```

**Why it matters**

The runbook is the on-call engineer's only tool on a host without shell access; a wrong runbook (e.g. re-opening CORS to `*`, editing the wrong config, following the File-Manager path while rsync --delete is armed) directly causes outages or security regressions, and misleads a new mobile-team backend developer on day one.

**Recommendation**

Rewrite DEPLOYMENT.md as the single ops runbook (environments, URLs, deploy steps, rollback, backup/restore, monitoring, on-call), replace backend/README.md with project-specific setup, delete or fix the .bat and frontend README, and add a CI lint that fails when CLAUDE.md test/route counts drift (simple script comparing `ls tests/Feature | wc -l`).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Every cited fact checks out on disk. (1) DEPLOYMENT.md line 11 (row 3) instructs replacing `allowed_origins => ['*']`, but backend/config/cors.php:14-17 already builds allowed_origins from CORS_ALLOWED_ORIGINS with a closed default of ['http://localhost:8080'] and never '*'. (2) DEPLOYMENT.md line 13 says to edit API_BASE_URL in config.js; frontend-html/js/config.js:10-12 now runtime-detects (localhost → :9090, otherwise '/backend/public/api'), and config.js's own header comment (line 3) still says "change API_BASE_URL" — also stale. (3) frontend-html/README.md:8 shows the old hardcoded URL, :19 claims CORS allows all origins, :21-27 lists password `password` and `teacher1@mutqin.ly` — no seeder contains teacher1@; DatabaseSeeder.php:19 calls LibyanDataSeeder whose passwords are `[redacted-demo-password]` (lines 40-43), ExtraDataSeeder uses `[redacted-demo-password]` (line 35). DEPLOYMENT.md:17 also says `password`. CLAUDE.md:32 says `[redacted-demo-password]` — wrong for the primary seeder. (4) backend/README.md is the untouched 58-line stock Laravel README. (5) backend/DEPLOY_LOG.md:7-9 states deployment is manual exclusively and nothing reaches the server by itself, yet .cpanel.yml (commit 9831f1c, same day 2026-09-11, titled 'auto-deploy from Git Version Control in cPanel') runs rsync -a --delete into public_html — a genuine operational contradiction: files hand-edited via File Manager per the log would be wiped on the next Git-triggered deploy. Whether the host actually has Git Version Control wired to this repo cannot be verified from the repo, but the two documents cannot both be right. (6) تشغيل-المشروع.bat:23,27 use C:\xampp\htdocs\MUTQENQ\... and a `frontend/` dir; `git ls-files | grep '^frontend/'` returns nothing (CLAUDE.md already acknowledges this staleness). (7) `ls backend/tests/Feature | wc -l` = 38 vs CLAUDE.md's '20 feature-test files'. Mitigations found: cors.php and demoAccounts() (DashboardController:105) are already secure in code, so the stale docs cannot by themselves re-open CORS — the auditor's impact line about 're-opening CORS to *' is exaggerated since the runbook tells the reader to restrict, not open. CLAUDE.md test-count and demo-password drift affects developers, not production. The one item with real outage potential is the DEPLOY_LOG vs .cpanel.yml rsync --delete contradiction. Net: finding is factually correct; medium is defensible mainly on that item, though the CORS/impact framing is overstated.

```text
backend/config/cors.php:14-17 — allowed_origins from env CORS_ALLOWED_ORIGINS, default ['http://localhost:8080'], never '*' (so DEPLOYMENT.md:11 is stale but cannot cause a CORS regression). frontend-html/js/config.js:3 header comment 'غيّر API_BASE_URL' also stale alongside :10-12 runtime detection. backend/database/seeders/DatabaseSeeder.php:19 calls LibyanDataSeeder (password [redacted-demo-password], lines 40-43); ExtraDataSeeder.php:35 [redacted-demo-password]; no seeder defines teacher1@/parent1@ (frontend-html/README.md:24-26 fabricated). DEPLOYMENT.md:17 row 8 says seeder password is `password` — wrong. .cpanel.yml committed 9831f1c on 2026-09-11 (rsync -a --delete) vs backend/DEPLOY_LOG.md:8-9 'manual exclusively' updated same day (788ee03) — direct contradiction. backend/tests/Feature = 38 files vs CLAUDE.md:22 '20'. git ls-files shows no frontend/ dir; .bat:23,27 paths point to C:\xampp\htdocs\MUTQENQ.
```

</details>

## Measured facts

| Metric | Value |
|---|---|
| Tracked files in repo | 320 (git ls-files); pack size 9.92 MiB; ~10 MB of binaries (screenshots 4.9M, _handoff2 3.3M, 'Home photos' 1.1M, gallery 1.1M) |
| Commits | 224 total; 71 since the only deploy marked done (f868880); 17 after the last DEPLOY_LOG entry (788ee03); 46 on 2026-09-11 alone |
| DEPLOY_LOG entries by status | 8 entries: 1 done, 7 pending |
| CI workflows / Dockerfiles / IaC / release tags | 0 / 0 / 0 / 1 tag (requests-restructure-2026-09-08) |
| Tests | 38 feature files + 2 unit files; 178 test methods; all RefreshDatabase on MySQL `mutqin_test`; executed in no pipeline |
| Migrations | 35 files; 2 added after the last confirmed deploy; 0 SQL artifacts tracked in git |
| Framework / key deps | laravel/framework v11.51.0 (security EOL 2026-03-12), sanctum v4.3.2, mpdf v8.3.1, phpspreadsheet 5.8.0; composer.lock last changed 2026-06-25; composer platform pinned php 8.2.12 vs prod PHP 8.3; platform-check disabled |
| Routes vs throttled routes | 94 route definitions (5 apiResource) — 3 throttled (login 10/min, otp request 5/min, otp verify 10/min); 0 RateLimiter::for definitions; 0 trustProxies |
| Scheduled tasks / queue jobs / mail | 0 Schedule:: entries; 0 ShouldQueue/dispatch; 0 Mail:: — QUEUE=sync, MAIL=log in prod template |
| Logging calls | 5 Log:: calls in app/ (1 writes plaintext OTP + phone at info level); prod template LOG_CHANNEL=daily, LOG_LEVEL=error, 14-day retention |
| Production env template | 28 keys in .env.production.example; CACHE=file, SESSION=file, FILESYSTEM=local, QUEUE=sync |
| Frontend external dependencies | 33 refs to cdn.jsdelivr.net (bootstrap 5.3.3 RTL CSS), 1 Google Fonts @import, 0 integrity attributes, 0 cache-busting params, 0 frontend .htaccess |
| Dev artifacts shipped to prod by .cpanel.yml | composer.phar (3,565,131 B), tests/ (40 files), check_excel.php, generate_test_excel.php, attendance_test.xlsx, phpunit.xml, DEPLOY_LOG.md, .env.example, .env.production.example, frontend README.md |
| Secrets in git history | 0 (.env, *.log, *.sql, *.zip never committed); demo seeder passwords present by design ([redacted-demo-password] / [redacted-demo-password]) |

## Auditor notes

Additional material observations not promoted to findings (to avoid silent drops): (a) The `/up` health route (bootstrap/app.php:12) returns 200 without touching the DB, so it cannot detect a MySQL outage — use `/api/public/stats` for probes. (b) `QUEUE_CONNECTION=sync` is correct today (zero queued jobs), but the planned SMS OTP gateway (AuthController.php:216-219 TODO) will then block the HTTP request on the provider's latency with no retry — revisit when a queue/cron exists. (c) Whether the vendor tree uploaded on 2026-09-07 was built with `--no-dev` is unverifiable from the repo; if dev packages (ignition, collision, pail, sail) are on the server they are inert with APP_DEBUG=false but enlarge the attack surface. (d) There is no local dev containerization: CLAUDE.md/DEPLOYMENT.md require XAMPP at `C:\\xampp\\php\\php.exe`, manual `zip`/`gd` php.ini toggles, `composer.phar` committed as a workaround, and tests assume MySQL root with an empty password (config/database.php:53-54 defaults) — onboarding a second backend developer or a CI runner is a multi-hour manual process; a docker-compose (php-fpm 8.3 + mysql 8 + nginx) would fix both dev and CI (effort M, timing next). (e) No infrastructure-as-code or server inventory beyond DEPLOY_LOG.md:13-19's list of hand-created directories; the .env on the server is the only copy of production secrets with no documented rotation for APP_KEY/DB password. (f) `تشغيل-المشروع.bat` is stale on two counts (`C:\\xampp\\htdocs\\MUTQENQ` path; `frontend/` artisan step for a directory that is not in git) — delete or rewrite (S, later). (g) Production DB engine/version is undocumented (DEPLOYMENT.md:43 mentions MariaDB 10.4 only for local); `config/database.php:60 'strict' => true` behaviour differs across MariaDB/MySQL versions — record it. (h) The n8n digest (n8n/README.md, workflow json) embeds no real secrets (placeholder password, localhost apiBase, `no-reply@mutqin.ly` sender) but is demo-only and has never targeted production. (i) Repo carries ~10 MB of unreferenced binaries ('Home photos/', screenshots/, _handoff2/) — not deployed by .cpanel.yml but slows every clone/CI checkout; move to LFS or docs storage (S, later). (j) Unhandled exceptions bypass the `{success,message,data,errors}` envelope because `withExceptions` is empty — an ops-visible contract gap for any client. Doc-drift specifics are in finding ops-docs-drift; CLAUDE.md additionally omits MessageController/messages table, AdminUserController, ManagerParentsTest, the PWA manifest, n8n/, _handoff2/ and .cpanel.yml, and misstates the test count (20 vs 38).
