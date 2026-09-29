# Business Continuity, DR & Operational Resilience

[← Enterprise Audit](../enterprise-audit.md)

**Score 31 / 100** — Unfit · maturity **L1** · weight 3%

Measured against an enterprise level-5 bar, continuity is essentially absent: there is no automated, verified, off-site or encrypted backup (the only mention is a one-line local mysqldump in DEPLOYMENT.md:15 that cannot run on the SSH-less host), a planned `DROP TABLE IF EXISTS` re-import into production has no pre-import dump or restore test (DEPLOY_LOG.md:43-46), RTO/RPO are undefined, every layer is a single point of failure (one cPanel account holding API+client+.env+logs, one MySQL, one developer under three git identities on a personal GitHub remote, an n8n digest that lives on a laptop with the admin password), nothing is monitored, no scheduled task exists (routes/console.php has only `inspire`), production is 68 commits / 12+ routes / 2 migrations behind HEAD, parents have no working password-recovery path in production, and there are zero runbooks or per-role guides. What keeps it above the floor: attendance writes are idempotent+transactional with an explicit 409 conflict protocol (a real foundation for offline sync), deletion was replaced by deactivate+token-revocation so operator error cannot destroy history, a disciplined dated DEPLOY_LOG, a secrets-free and well-reasoned production env template, `.htaccess` fencing of the Laravel tree, APP_KEY with a tiny blast radius (no Crypt usage), and 178 test methods that make an upgrade/restore verifiable. Net: 31/100, CMMI level 1 (ad hoc) — deployment is repeatable, but backup/restore/monitoring/incident processes do not exist.

## What is already strong

- Attendance persistence is built for retry and conflict: unique (student_id,date) index (backend/database/migrations/2024_01_01_000030_create_attendances_table.php:22 `$table->unique(['student_id', 'date']);`), `updateOrCreate` inside `DB::transaction` (backend/app/Http/Controllers/Api/AttendanceController.php:102-115) and an explicit 409 `conflicts` payload that the UI turns into an Arabic confirm dialog (AttendanceController.php:93-98; frontend-html/teacher/attendance.html:84-99). Replaying a queued batch after an outage is safe — the single best asset for an offline-first mobile client.
- xlsx fingerprint import is transactional and non-destructive for computed absences: whole import in one transaction (AttendanceImportController.php:97 `DB::transaction(function () use (`), computed absences use `firstOrCreate` so they never overwrite an existing row (AttendanceImportController.php:360-372), 5 MB cap and Arabic error rows (lines 21-25). This is a genuine outage-tolerant capture path for centers with a device.
- Deletion replaced by deactivation everywhere with immediate token revocation — operator mistakes cannot erase history and compromised accounts can be cut off instantly: `User::recordPasswordChange()` revokes all tokens (backend/app/Models/User.php:81 `$this->tokens()->delete();`), center deactivation revokes every member's tokens (CenterController.php:266-269), teacher deactivation likewise (TeacherController.php:211).
- A real, dated deploy ledger exists: backend/DEPLOY_LOG.md records per-commit hash, changed files, upload method and status, and enumerates the server files that must never be overwritten (DEPLOY_LOG.md:13-19). Rare discipline for a solo project and the seed of a change-management process.
- Production environment template is secrets-free and each choice is justified for the SSH-less host (backend/.env.production.example:36-39 daily log rotation because no CLI, :49-62 file sessions/cache and sync queue, :17-21 explicit warning never to reuse the dev APP_KEY). ProductionSeeder reads the admin password from `ADMIN_INITIAL_PASSWORD` and refuses <8 chars (backend/database/seeders/ProductionSeeder.php:24-25) — no hardcoded production credential.
- The Laravel tree under the shared web root is fenced: backend/.htaccess:10-19 denies all HTTP access (both Apache 2.2/2.4 syntaxes) and only public/.htaccess:3-11 re-grants — `.env`, `storage/logs` and `vendor` are not web-readable even on shared hosting.
- APP_KEY rotation has a tiny blast radius: no `Crypt::`/`encrypt(` usage anywhere in app/ (grep returned nothing); Sanctum tokens are SHA-256 hashed and passwords bcrypt, so a key rotation only invalidates file sessions/CSRF that the Bearer API does not use.
- Health endpoint already exists (`health: '/up'` in backend/bootstrap/app.php:12) — an uptime monitor can be pointed at it today.
- Timezone is pinned in code, not env (backend/config/app.php:70 `'timezone' => 'Africa/Tripoli'`) and the n8n digest pins the same zone (n8n/mutqin-daily-attendance-digest.json:287) — 'today' logic cannot drift when restored on a UTC host.
- Regression safety net for upgrades and restores: 38 Feature + 2 Unit test files, 178 test methods (grep count), covering the role/ability matrix, ownership, OTP, import, status toggles and messaging (backend/tests/Feature).

## Level-5 target state

Continuity is a written, rehearsed capability rather than an aspiration: RTO/RPO targets are declared per growth phase (RPO ≤1 h / RTO ≤2 h once the Flutter app is live; ≤15 min / 30 min beyond 50 centers), encrypted daily+hourly backups of the database and .env go off-site automatically with retention, and a timed restore drill runs monthly with its result logged. The stack can be rebuilt on a second host from git + vault + dump by any of at least two named maintainers, secrets live in an organisational vault with rotation owners, deploys are one automated path with a version endpoint, pre-deploy dump and rollback recipe, and uptime/error/disk monitoring pages two people. Field operation is designed for outages: the mobile client captures attendance, memorization and tests offline with idempotent, conflict-aware sync and documented precedence rules, and centers know exactly what to do (and whom to call) during an outage. Recurring operator tasks — credential handover, password resets for every role without SMS, attendance correction and batch revert, account lockout, key rotation, pruning, framework upgrades — each have a runbook, and a capacity/cost model states the center count at which the hosting tier must change.

## What the Flutter team must know

The mobile team inherits a backend with no safety net, so several things must be settled before sprint 1. (1) Version target: production is 68 commits behind HEAD and lacks ≥12 routes (`/manager/parents*`, `/manager/students/{id}/teacher`, `/manager/teachers/{id}/performance`, `/manager/reports/*`, `/manager/centers`, `POST /manager/student-requests`, `/centers/{id}/*`) and 2 migrations — either production is deployed first or the app is built against a pinned deployed tag; add `GET /api/version` and check it at startup. (2) Data safety: nothing the app writes is backed up; do not run a parent/teacher beta on real data until finding `no-backup-no-restore` is closed. (3) Retry semantics: only `POST /attendance` is safe to replay (unique student+date, 409 with `data.conflicts` → resend with `confirm:true`); `POST /memorizations`, `/weekly-tests`, `/teacher|parent/messages/{student}` and `/manager/student-requests` duplicate on retry — the app must not auto-retry them until the server accepts a client UUID/Idempotency-Key, or must dedupe locally. (4) Offline-first is mandatory for attendance/memorization/tests in Libyan centers (recurrent power/connectivity loss); default attendance state must be 'unrecorded', not 'present'; queue ≥72 h; use `GET /backend/public/up` as the connectivity probe before flushing. (5) Auth lifecycle: Sanctum tokens expire after 7 days and are revoked on password change, account deactivation or center deactivation — a queued batch must survive a 401 and re-authenticate without dropping items; every login creates a new server token (no refresh endpoint). (6) No delta/sync endpoints (`?since=`), no ETags, no push — notifications are DB rows polled by the web client every 60 s; budget polling frequency (N users / 60 s hits the shared host's process ceiling around ~10k open sessions) or wait for FCM. (7) Parent password reset has no delivery channel in production — ship an in-app 'contact your center' path and expect support load. (8) Rate limits are per IP (login 10/min, OTP 5/min); Libyan mobile carriers use CGNAT, so many parents share one IP — coordinate limits and surface `Retry-After`. (9) Dates are Africa/Tripoli server-side; send `Y-m-d` in local time. (10) CORS is irrelevant for native but `CORS_ALLOWED_ORIGINS` must include any Flutter-web origin.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [No production backup exists, is scheduled, verified, off-site or restorable; a DROP-TABLE re-import into prod is planned without a pre-import dump](#no-backup-no-restore) | 🔴 critical<br>_reviewers → high_ | ✅ confirmed | NOW | M |
| [No RTO/RPO, no business-impact analysis, no definition of tolerable outage for the centers](#rto-rpo-undefined) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Bus factor = 1: single developer under three git identities, personal GitHub remote, all credentials held by one person, admin password handed over in chat, deploy artifacts on a laptop](#bus-factor-one-credentials) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Nothing is monitored: no uptime check, no error tracking, no log shipping, logs kept 14 days on the box at error level only](#no-monitoring-no-alerting) | 🟠 high | ✅ confirmed | NOW | S |
| [Parent (and admin) password recovery is non-functional in production: OTP is log-only at info level (suppressed by LOG_LEVEL=error), no SMS, and no admin/manager endpoint can set a parent's password](#parent-password-recovery-dead-in-prod) | 🟠 high | ✅ confirmed | NOW | M |
| [No offline capability and no retry-safety: attendance pre-selects 'حاضر' and unsaved marks are lost on a failed request; memorization/test/message creates duplicate on retry; only xlsx import survives an outage](#offline-capture-absent) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NOW | L |
| [Production runs code 68 commits behind HEAD (12+ routes, 2 migrations missing); deploy method is contradictory (manual File Manager vs .cpanel.yml git deploy); migrations are hand-applied SQL with no code/schema check and no rollback procedure](#deploy-drift-schema-coupling) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [One shared cPanel account (API + static client + .env + logs) and one MySQL instance with SSH disabled; no staging, no standby, no rebuild procedure](#single-host-spof-no-dr) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | L |
| [Laravel v11.51.0 is six months past its security-fix end (2026-03-12) and the host cannot run composer, with no documented vendor-upgrade procedure](#laravel-11-past-security-eol) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | M |
| [Unauthenticated `/public/stats` and `/public/demo-accounts` hit the database on every call with no cache and no rate limit; no global API limiter exists](#public-endpoints-uncached-unthrottled) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [The only automated operational alert (daily attendance digest) is an n8n workflow meant to run on a personal machine with the admin password in a Set node, creating a new admin token every day and failing silently](#n8n-digest-laptop-spof) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [No scheduled maintenance at all: expired Sanctum tokens, OTP rows, notifications, messages and file-cache/throttle files grow forever; no cron is configured on the host](#no-scheduler-no-pruning) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Re-importing an xlsx silently overwrites manager corrections (leaving a stale corrected_by audit) and a bad import cannot be reverted as a batch — recovery is one manual click per record](#import-overwrites-corrections-no-batch-undo) | 🟡 medium | ✅ confirmed | NEXT | M |
| [No runbooks for recurring operator tasks, no support/on-call path, no per-role onboarding; backend README is stock Laravel and the frontend README/page guide are stale](#no-runbooks-no-support-path) | 🟡 medium | ✅ confirmed | NEXT | M |
| [No capacity or cost model: host limits undocumented, no load numbers, and the N+1 weekly report puts a measurable ceiling on the shared host](#no-cost-capacity-model) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |

### No production backup exists, is scheduled, verified, off-site or restorable; a DROP-TABLE re-import into prod is planned without a pre-import dump

<a id="no-backup-no-restore"></a>

`no-backup-no-restore` · 🔴 critical (reviewers → high) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `DEPLOYMENT.md:15`, `backend/DEPLOY_LOG.md:6-9`, `backend/DEPLOY_LOG.md:43-46`, `backend/routes/console.php:6-8`, `.gitignore:2`

**Evidence**

```text
DEPLOYMENT.md:15 is the only backup mention in the repository: `| 7 | نسخ احتياطي لقاعدة البيانات | جدولة mysqldump يومياً ... مثال: mysqldump -u root mutqin_db > backup-$(date +%F).sql |` — it names the LOCAL dev DB (`mutqin_db`, user root), not production `[redacted-db-name]`, and needs a shell the host does not provide: DEPLOY_LOG.md:6-7 `SSH ... معطَّل من المستضيف (Connection refused على 22) — عملياً بلا SSH / CLI / artisan / composer / git`. DEPLOY_LOG.md:45 plans `استيراد mutqin-clean-2026-09-11.sql مباشرة (يحوي DROP TABLE IF EXISTS لكل جدول)` with no pre-import backup, no restore test, no checksum. The only dumps referenced live at `C:\mutqin-deploy\...` (DEPLOY_LOG.md:43,52) — a laptop path outside VCS (absent on the audited machine). routes/console.php:6-8 contains only the `inspire` command: no scheduled dump. `.gitignore:2 *.zip` — archives are excluded from git, so no backup artifact is versioned. `git log` shows zero commits mentioning backup/restore/dump apart from the seeding log entry.
```

**Why it matters**

A host failure, account suspension, ransomware on the shared box, a bad phpMyAdmin import or a single wrong click deletes every center's attendance, memorization and test history with no recovery path. The data is about minors (DEPLOYMENT.md:15 itself says 'بيانات قُصّر — لا تفريط'). Once a Flutter app pushes real data from dozens of centers, the unrecoverable blast radius grows daily.

**Recommendation**

Within days: (1) take a manual full dump via phpMyAdmin (Export → SQL, `--single-transaction` equivalent) and store it encrypted (gpg/7z-AES) in two places outside the laptop before ANY further import; (2) enable the host's cPanel Backup (JetBackup/Backup Wizard) daily and download weekly; (3) add a cPanel Cron Job (available without SSH) `mysqldump --single-transaction --routines --triggers -u$U -p$P [redacted-db-name] | gzip | openssl enc -aes-256-cbc -pbkdf2 -pass file:~/.bk.key > ~/backups/mutqin-$(date +\%F-\%H%M).sql.gz.enc` with retention 7 daily / 4 weekly / 12 monthly and an off-site push (rclone to Backblaze B2/Drive) — keep the key in the vault, not on the host; (4) back up `.env` and `storage/` alongside; (5) monthly restore drill: import the latest dump into `mutqin_test` (or a throwaway cPanel DB), compare `SELECT COUNT(*)` per table and `CHECKSUM TABLE`, log the result and elapsed time in DEPLOY_LOG.md; (6) make 'pre-deploy dump taken: yes/no + filename' a mandatory line of every DEPLOY_LOG entry.

<details><summary>Verification — 2 independent reviewers</summary>

**Upheld** · confidence 85% · corrected severity: high

All cited evidence is confirmed verbatim. DEPLOYMENT.md:15 is the only backup mention in the repo (grep across *.php/*.md/*.bat/*.json/*.yml outside vendor returns just that line), and it targets the local dev DB `mutqin_db` with user root, not `[redacted-db-name]`. backend/DEPLOY_LOG.md header states SSH is refused by the host (no CLI/artisan/composer), and the e7c50f9 (2026-09-11, status pending) and c80e8f5 entries both instruct importing a `DROP TABLE IF EXISTS` dump via phpMyAdmin with no pre-import dump, checksum or restore step. routes/console.php holds only `inspire`; no `Schedule::` calls anywhere in backend/routes or backend/app; composer.json has no backup package (no spatie/laravel-backup); .env.production.example has no backup keys; no app/Console/Commands directory. `C:\mutqin-deploy` does not exist on this machine, so the referenced dumps are indeed unversioned laptop-only artifacts, and `.gitignore` excludes *.zip. No test, middleware, or config mitigates a backup gap (it is operational, not code). So the finding is factually correct and not mitigated in-repo.

However the severity is overstated for the current state: per DEPLOY_LOG.md the production DB currently contains only the 2026-09-07 demo dataset (status done), and the pending DROP-TABLE import (e7c50f9) is the ProductionSeeder "empty DB" state (477 athman rows + one admin). There is no real center/minor data in production yet, so today a bad import or host loss destroys only reproducible demo/seed data (ProductionSeeder.php and LibyanDataSeeder.php are in the repo and can regenerate it). The auditor's impact statement about losing "every center's attendance, memorization and test history" describes a future state. Additionally cPanel hosts typically expose a Backup/JetBackup facility, but nothing in the repo documents that it is enabled, so I cannot credit it as a mitigation. The gap is real and must be closed before real data arrives (which the log says is imminent), so I rate it high rather than critical; it would legitimately become critical the moment real center data is loaded.

```text
Confirmed: DEPLOYMENT.md:15 (only backup mention; names dev DB mutqin_db/root); backend/DEPLOY_LOG.md:3-7 (cPanel, DB [redacted-db-name], SSH refused, no CLI); backend/DEPLOY_LOG.md:43-46 (e7c50f9 pending: import mutqin-clean-2026-09-11.sql containing DROP TABLE IF EXISTS, no pre-import dump); backend/DEPLOY_LOG.md:52-55 (c80e8f5 same pattern); backend/routes/console.php:6-8 (inspire only, no Schedule); .gitignore:2 (*.zip). Additional: backend/composer.json has no backup package; no `Schedule::` in backend/routes or backend/app; `C:\mutqin-deploy` absent on the audited machine. Mitigating context: DEPLOY_LOG.md:34-37 shows production currently holds the reproducible 2026-09-07 demo DB, and backend/database/seeders/ProductionSeeder.php can regenerate the pending "empty" state — no real minor data is in production yet.
```

---

**Upheld** · confidence 80% · corrected severity: high

Evidence verified: DEPLOYMENT.md:15 is the only backup mention in the whole repo (grep over md/php/bat/json/yml finds nothing else), and it names the local dev DB `mutqin_db` with root, not `[redacted-db-name]`. backend/routes/console.php contains only `inspire` (no scheduled dump), .gitignore excludes *.zip, DEPLOY_LOG.md confirms SSH is disabled by the host and that deployment is phpMyAdmin/File Manager only. The DROP TABLE import (e7c50f9, status pending) is planned with no pre-import dump line. C:\mutqin-deploy does not exist on this machine. No test, middleware, or code layer can mitigate an operational backup gap. However, the CRITICAL rating overstates present-day materiality: per DEPLOY_LOG.md the production DB currently holds only seeded DEMO data (mutqin-demo-2026-09-07-clean.sql, reproducible from LibyanDataSeeder/ProductionSeeder), and the pending DROP-TABLE import is explicitly the wipe that prepares the DB for real data — so at the time of audit there is nothing irreplaceable to lose and the 'pre-import dump' concern is moot for this specific import. The finding also cannot rule out host-side (cPanel/JetBackup) backups, which are common on Libyan Spider shared hosting but are unverifiable from the repo. The gap becomes critical only once real center data lands; today it is a high-priority pre-production readiness item, consistent with the fact that DEPLOYMENT.md itself already lists backups as a mandatory pre-production step that has simply not been actioned for the real host.

```text
DEPLOYMENT.md:15 (only backup mention; targets dev DB `mutqin_db`/root). backend/DEPLOY_LOG.md:3-7 (cPanel-only, SSH refused, DB `[redacted-db-name]`). backend/DEPLOY_LOG.md:27-33 (initial published state = demo DB mutqin-demo-2026-09-07-clean.sql, status done). backend/DEPLOY_LOG.md:35-42 (e7c50f9 pending: import mutqin-clean-2026-09-11.sql with DROP TABLE IF EXISTS; production still holds demo data only, so this import destroys reproducible demo data, not real records). backend/routes/console.php:6-8 (inspire only, no schedule). .gitignore:2 (*.zip). `ls C:\mutqin-deploy` -> No such file or directory on audited machine. Mitigating context: DEPLOYMENT.md:15 already lists backups as a mandatory pre-production step; ProductionSeeder.php/LibyanDataSeeder.php can regenerate current prod state.
```

</details>

### No RTO/RPO, no business-impact analysis, no definition of tolerable outage for the centers

<a id="rto-rpo-undefined"></a>

`rto-rpo-undefined` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `DEPLOYMENT.md:1-43`, `backend/DEPLOY_LOG.md:1-25`, `CLAUDE.md:1-30`

**Evidence**

```text
grep -i 'RTO|RPO|restore|استعادة|incident|uptime|monitor' across all tracked *.md returns only DEPLOYMENT.md:12 (SMS) and :15 (mysqldump). DEPLOYMENT.md's pre-production checklist (lines 7-18) has no continuity item beyond the one-line dump; DEPLOY_LOG.md defines the deploy ritual but no recovery ritual. No document states how long a center may be without the system or how much data may be lost.
```

**Why it matters**

Without targets nobody can size backups, hosting, or the offline window the mobile app must tolerate; every design decision (hourly vs daily dumps, VPS vs shared host, queue depth in Flutter) is currently a guess, and an incident will be handled ad hoc under pressure.

**Recommendation**

Write a one-page BC policy now with tiered targets: Phase A (≤10 centers): RPO 24 h (nightly dump), RTO 8 h (manual rebuild from git + dump), degraded mode = paper roll / fingerprint xlsx retained at center and re-imported (import is idempotent). Phase B (10-50 centers, Flutter live): RPO 1 h (hourly dump of hot tables or host binlog), RTO 2 h (scripted rebuild on VPS, DNS TTL 300). Phase C (50+): RPO ≤15 min (replica), RTO 30 min (warm standby), availability objective 99.5 % during center hours (Sat-Thu, Africa/Tripoli). Publish the mobile offline window (≥72 h queue) derived from RPO. Review quarterly.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

Core factual claim confirmed: no tracked document defines RTO, RPO, tolerable outage, or a recovery procedure. `git grep -i 'RTO|RPO|restore|استعادة|incident|uptime|monitor|backup|نسخ احتياط|mysqldump'` over all *.md/*.bat/*.php/*.json returns only DEPLOYMENT.md:12 (OTP password 'استعادة') and DEPLOYMENT.md:15 (one-line daily mysqldump suggestion); the other hits are unrelated identifiers (startOfWeek, assertOk, rememberToken). No scheduler exists (backend/routes/console.php has only the `inspire` stub; no app/Console commands), no backup script, no health endpoint. Worse than the auditor states: backend/DEPLOY_LOG.md:6-9 records that the actual production host (Libyan Spider cPanel) has SSH disabled, so the `mysqldump` cron in DEPLOYMENT.md:15 cannot even be run as written on the real host — backups would have to come from cPanel's own backup feature or manual phpMyAdmin exports, and neither is documented.

However the finding is overstated in several ways, so severity should be lowered rather than the finding refuted:
1. Partial RTO mitigation exists de facto: DEPLOY_LOG.md:35-37 and :45 document a full rebuild path (re-zip whole tree upload via File Manager + import a self-contained SQL dump with DROP TABLE IF EXISTS via phpMyAdmin), and :13-19 list the server-only files (.env, storage/*) that must survive. That is an undocumented-as-such but repeatable recovery ritual.
2. The auditor's degraded-mode assumption is correct and verifiable: fingerprint xlsx import is idempotent — AttendanceImportController.php:300 uses Attendance::updateOrCreate and :360 firstOrCreate, backed by the unique (student_id,date) index in migrations/2024_01_01_000030_create_attendances_table.php:22. Paper/xlsx retained at the center can be re-imported later without duplicates.
3. Phase B/C recommendations reference a Flutter mobile app and an offline queue (">=72 h") — there is no Flutter/Dart code, no pubspec, no service worker, no offline/queue logic anywhere in the repo (grep offline|navigator.onLine|serviceWorker|queue in frontend-html/js returns nothing; only a PWA manifest exists). Those tiers are speculative for a product that does not exist yet.
4. Scale context: DEPLOY_LOG.md:39-46 shows production is being reset to an empty DB with a single admin (one pilot center at most), on shared hosting. The realistic blast radius today is one center's attendance/memorization records, with paper fallback.
5. Cited line ranges are imprecise: DEPLOY_LOG.md is 100 lines (not 1-25) and CLAUDE.md:1-30 contains nothing relevant.

Net: a real governance/documentation gap (no stated RPO/RTO, no scheduled backup actually executable on the real host, no written restore steps), but for a single-center pilot on cPanel with an existing rebuild ritual and idempotent re-import, 'high' overrates it. Medium.

```text
DEPLOYMENT.md:15 — sole continuity item, a suggested daily `mysqldump` cron. backend/DEPLOY_LOG.md:6-9 — production host has SSH disabled ("Connection refused على 22 — عملياً بلا SSH / CLI / artisan / composer / git"), so that cron cannot run on the real host; no cPanel-backup or phpMyAdmin-export procedure is documented anywhere. backend/DEPLOY_LOG.md:13-19 — server-only files that must survive a redeploy (.env, storage/*). backend/DEPLOY_LOG.md:35-37,45,54 — de facto full-rebuild path (whole-tree zip upload + self-contained SQL with DROP TABLE IF EXISTS imported via phpMyAdmin) exists but is framed as deployment, not recovery. backend/routes/console.php:6 — only the default `inspire` command; no scheduler/backup job. backend/app/Http/Controllers/Api/AttendanceImportController.php:300 (updateOrCreate) and :360 (firstOrCreate) + backend/database/migrations/2024_01_01_000030_create_attendances_table.php:22 unique(student_id,date) — re-import of retained xlsx is idempotent (valid degraded mode). No Flutter/Dart/offline-queue code exists in the repo (git ls-files has no pubspec/*.dart; frontend-html/js has no serviceWorker/navigator.onLine/queue) — Phase B/C mobile-offline recommendations are speculative. Auditor's cited ranges DEPLOY_LOG.md:1-25 (file is 100 lines) and CLAUDE.md:1-30 do not contain the relevant material.
```

</details>

### Bus factor = 1: single developer under three git identities, personal GitHub remote, all credentials held by one person, admin password handed over in chat, deploy artifacts on a laptop

<a id="bus-factor-one-credentials"></a>

`bus-factor-one-credentials` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/DEPLOY_LOG.md:23`, `backend/DEPLOY_LOG.md:43-45`, `backend/DEPLOY_LOG.md:52`, `n8n/mutqin-daily-attendance-digest.json:43-44`, `CLAUDE.md:12`

**Evidence**

```text
`git log --format='%an <%ae>' | sort | uniq -c` → `87 muad3719-crypto <muad_3719@limu.edu.ly>`, `82 muad03 <muad.st03@gmail.com>`, `55 MUTQEN <claudecombo@gmail.com>` (non-overlapping months: one person, three identities); remote `origin https://github.com/muad03/MUTQIN.git` (personal account, no org, no CI: no .github/). 223 of 224 commits carry `Co-Authored-By: Claude ...` → institutional knowledge lives in CLAUDE.md, which has drifted (CLAUDE.md:25 says '20 feature-test files', actual 38; CLAUDE.md:220 says weekly-tests have no update endpoint, but routes/api.php:164 has `update` and WeeklyTestUpdateTest exists; MessageController, AdminUserController, n8n/, .cpanel.yml, manifest are undocumented). DEPLOY_LOG.md:23 `حالة الرفع يغيّرها صاحب المشروع وحده بعد الرفع الفعلي`; :45 `كلمة مرور الأدمن الأولية سُلِّمت لصاحب المشروع في المحادثة`; :43/:52 dumps at `C:\mutqin-deploy\` (not in VCS, absent on this machine); CLAUDE.md:12 'a stale nested backend/.git was retired and backed up' — location never recorded. n8n json:43-44 `"name": "password", "value": "PUT_PASSWORD_HERE"` — admin password intended to sit in a Set node on someone's machine (n8n/README.md:20-22). No inventory says who else can rotate cPanel, DB, GitHub, SMTP (none exists — MAIL_MAILER=log) or the future SMS key.
```

**Why it matters**

If the developer is unavailable for a week (illness, travel, loss of laptop) nobody can deploy, restore, reset an admin password, rotate a leaked credential, or even find the last dump. For dozens of centers this is an existential dependency, and it directly threatens a 2-week Flutter sprint that needs backend fixes on demand.

**Recommendation**

This week: create an organisation vault (Bitwarden/1Password Teams) holding cPanel, DB user, APP_KEY, admin@mutqin.ly, GitHub, registrar/DNS, n8n, and future SMS/SMTP keys, each with an owner + a named second person who can rotate it; move the repo to a GitHub organisation with two owners and branch protection; commit a `docs/OPERATIONS.md` credential/rotation register (no secrets, only where they live and who rotates); put deploy artifacts (dumps, vendor.zip) in an off-site versioned bucket, not `C:\mutqin-deploy`; standardise on one git identity; fix CLAUDE.md drift so a second maintainer can trust it. Within 8 weeks: onboard a second maintainer who performs one deploy and one restore drill end-to-end.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 72% · corrected severity: medium

Cited evidence verified: `git log` gives exactly 87/82/55 commits across muad3719-crypto, muad03 and MUTQEN; remote is the personal `https://github.com/muad03/MUTQIN.git`; no `.github/`; 223 of 224 commits carry `Co-Authored-By: Claude`. backend/DEPLOY_LOG.md:23 says deploy status is changed by "صاحب المشروع وحده"; :45 records the initial admin password being handed over in chat; :43 and :52 reference dumps at `C:\mutqin-deploy\...` which do not exist on this machine and are not in VCS. n8n json:43-44 has `"password": "PUT_PASSWORD_HERE"` and n8n/README.md:20-22 says the password sits in plain text. CLAUDE.md drift confirmed: line 25 says 20 feature-test files (38 present), line 220 says weekly-tests have no update endpoint while routes/api.php:164 includes `update` and WeeklyTestUpdateTest.php exists; `.cpanel.yml` and MessageController/AdminUserController are not in CLAUDE.md. No docs/OPERATIONS.md or credential register exists anywhere. One factual error in the auditor's evidence: the three identities are NOT in non-overlapping months — MUTQEN and muad3719-crypto both commit in 2026-06 and 2026-07, muad03 and muad3719-crypto both in 2026-08 — so "one person, three identities" is an inference, not proven by dates (it could equally hint at two people, which would weaken the bus-factor=1 claim). Nevertheless DEPLOY_LOG's own wording establishes a single owner controlling deploys. Partial mitigations exist that the finding undersells: DEPLOY_LOG.md:1-16 records host (Libyan Spider cPanel, user `[redacted-cpanel-user]`, PHP 8.3, web root, DB name `[redacted-db-name]`, no-SSH manual-upload procedure) and DEPLOYMENT.md lists key rotation and a daily mysqldump requirement, so a second person with cPanel access could reconstruct the deploy path — but no evidence that backups are actually scheduled or that anyone else holds cPanel/GitHub credentials. Severity is overstated: the production DB per DEPLOY_LOG e7c50f9 (status pending) is being reset to an empty state with only the admin account, and the previous demo was a single center — "existential dependency for dozens of centers" describes a future state, not today. Real but medium at present; would become high once real data from multiple centers is live.

```text
git log by month shows identity overlap (not disjoint): MUTQEN 2026-06 (15), 2026-07 (40); muad3719-crypto 2026-06 (4), 2026-07 (56), 2026-08 (27); muad03 2026-08 (1), 2026-09 (81). backend/DEPLOY_LOG.md:1-9 documents host/user/DB/manual cPanel deploy procedure (partial knowledge-transfer mitigation). DEPLOYMENT.md:15 mandates daily mysqldump but no evidence it is scheduled. backend/DEPLOY_LOG.md:36-46 (e7c50f9, status pending): production DB is an empty seed with only admin@mutqin.ly — no real multi-center data live yet. backend/tests/Feature contains 38 files incl. WeeklyTestUpdateTest.php; backend/routes/api.php:164 `->only(['index','store','show','update'])` contradicts CLAUDE.md:220. `.cpanel.yml` exists at repo root, undocumented in CLAUDE.md.
```

</details>

### Nothing is monitored: no uptime check, no error tracking, no log shipping, logs kept 14 days on the box at error level only

<a id="no-monitoring-no-alerting"></a>

`no-monitoring-no-alerting` · 🟠 high · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/bootstrap/app.php:12`, `backend/.env.production.example:36-39`, `backend/config/logging.php:68-73`, `backend/composer.json:7-24`

**Evidence**

```text
bootstrap/app.php:12 `health: '/up',` exists but no monitor, cron or document references it. .env.production.example:38-39 `LOG_CHANNEL=daily` / `LOG_LEVEL=error`; config/logging.php:72 `'days' => env('LOG_DAILY_DAYS', 14)` → 14-day local retention, nothing off-box. composer.json:7-13 has no Sentry/Flare/Telescope/Pulse in `require` (spatie/flare-client-php appears only as a dev transitive of ignition). No Slack/mail channel is configured (`LOG_SLACK_WEBHOOK_URL` unset), and MAIL_MAILER=log (.env.production.example:66) means even a critical-error mail could not be sent. No disk/quota alert, no synthetic login check.
```

**Why it matters**

Outages and silent data-loss bugs are discovered by teachers and parents, not operators; forensic evidence for an incident older than 14 days is gone; on shared hosting a runaway process or full disk (unpruned tokens/logs/cache files) will take the API down with nobody notified.

**Recommendation**

Now (≤1 day): external uptime monitor (UptimeRobot/BetterStack) on `https://mutqin.ly/backend/public/up` and on a synthetic `POST /api/auth/login` with a dedicated inactive-privilege monitor account, alerting by SMS/Telegram to two people; enable cPanel resource-usage and disk alerts. Next: add an error tracker (Sentry Laravel SDK or Flare) with APP_ENV tagging, raise LOG_LEVEL to `warning`, ship daily logs off-box with the backup job, and hold a 15-minute weekly ops review of errors, 4xx/5xx counts and disk growth.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: high

Every cited line exists and means what the auditor says. backend/bootstrap/app.php:12 registers the framework health route `/up`, and a repo-wide grep (php/md/bat/json/example, vendor excluded) finds no other reference to `/up`, uptime, monitor, Sentry, Flare, or health — DEPLOYMENT.md's pre-production checklist (rows 1–9) covers debug, key, CORS, SMS, Apache, demo accounts, mysqldump backup, HTTPS, tests, but nothing about monitoring or alerting; backend/DEPLOY_LOG.md likewise. .env.production.example:38-39 sets `LOG_CHANNEL=daily` / `LOG_LEVEL=error`; config/logging.php:72 gives 14-day rotation with no off-box channel; `LOG_SLACK_WEBHOOK_URL` is set nowhere; .env.production.example:66 `MAIL_MAILER=log` and the comment explicitly states there is no SMTP on the host. composer.json `require` is only framework/sanctum/tinker/mpdf/phpspreadsheet — no error tracker; ignition is dev-only. bootstrap/app.php `withExceptions` block is empty (no custom reporting hook). Additionally, routes/console.php contains only the default `inspire` command, there is no app/Console, and no `Schedule::` anywhere — so `sanctum:prune-expired` is never run (config/sanctum.php:55 expires tokens after 10080 min but rows accumulate), reinforcing the "unpruned tokens/logs" growth point. There are no tests or frontend guards relevant to operational monitoring. The finding is factually correct and unmitigated. Severity calibration: for a small single-tenant Arabic school system on shared cPanel hosting with a tiny team, the practical mitigations are cheap external ones (cPanel resource alerts, a free uptime monitor), and the product has no SLA — but the impact reasoning (outages found by parents/teachers, forensic logs gone after 14 days, disk-full with nobody notified) holds. Keeping the auditor's "high" is defensible; I would not downgrade it since a full-disk or DB-down event with no notification path directly threatens continuity of a system holding minors' data. Kept at high.

```text
backend/bootstrap/app.php:12 `health: '/up'` (only reference in repo; withExceptions block at :22-24 is empty — no custom reporting). backend/.env.production.example:38-39 `LOG_CHANNEL=daily` / `LOG_LEVEL=error`; :66 `MAIL_MAILER=log` with comment "لا SMTP على هذه الاستضافة". backend/config/logging.php:72 `'days' => env('LOG_DAILY_DAYS', 14)`. backend/composer.json:7-13 require = framework, sanctum, tinker, mpdf, phpspreadsheet only. backend/routes/console.php: only the default `inspire` command; no `Schedule::` anywhere and no app/Console — `sanctum:prune-expired` never scheduled despite config/sanctum.php:55 `'expiration' => 10080`. DEPLOYMENT.md:8-19 pre-production checklist has a mysqldump row (:15) but no monitoring/alerting/log-shipping row; backend/DEPLOY_LOG.md has none either.
```

</details>

### Parent (and admin) password recovery is non-functional in production: OTP is log-only at info level (suppressed by LOG_LEVEL=error), no SMS, and no admin/manager endpoint can set a parent's password

<a id="parent-password-recovery-dead-in-prod"></a>

`parent-password-recovery-dead-in-prod` · 🟠 high · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:216-220`, `backend/app/Http/Controllers/Api/AuthController.php:125-160`, `backend/.env.production.example:39`, `backend/routes/api.php:20-21`, `backend/routes/api.php:106-119`

**Evidence**

```text
AuthController.php:216-220 `protected function sendOtp(User $user, string $otp): void { Log::info("OTP password-reset for user #{$user->id} ...: {$otp}"); // TODO(SMS): SmsGateway::send(...) }` — the only delivery is an info-level log line, which the production template drops (`LOG_LEVEL=error`, .env.production.example:39; config/logging.php:71 `'level' => env('LOG_LEVEL', 'debug')`). AuthController.php:153-156 exposes `dev_otp` only when `app()->environment('local')`. Meanwhile the user is told `فستُسلَّم شفرة التحقّق` (:126-129). Password-set paths that exist: manager→teacher (CenterManagerController.php:422-430), admin→manager (ManagerManagementController.php:126-134), teacher self (TeacherProfileController.php:74). grep of routes/api.php for 'password' → only lines 20, 21, 137: there is no route by which an admin or manager can reset a parent's or admin's password. DEPLOYMENT.md:12 acknowledges: 'بدونها لا يمكن لولي الأمر/المحفّظ استعادة كلمة المرور ذاتياً في الإنتاج'.
```

**Why it matters**

Every locked-out parent (the largest user population, and one of the four Flutter roles) becomes a developer ticket requiring a bcrypt hash edit in phpMyAdmin — unscalable, error-prone, and a privacy risk; a locked-out admin is a full-system lockout. The SMS provider, once added, becomes a hard dependency with no fallback.

**Recommendation**

Now: add `PUT /manager/parents/{id}/password` (parents of the manager's own center, via ParentResolver scoping) and `PUT /admin/users/{id}/password` (admin, any role), both calling `recordPasswordChange('admin')` so tokens are revoked and the change is audited; show a 'contact your center' path in the app's forgot-password screen when SMS is not configured. Next: integrate the SMS gateway behind an interface with a circuit breaker and a manager-relayed OTP fallback (the manager sees the code for their own parents), and write runbook R2 'reset a parent password' with the identity-verification steps.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 90% · corrected severity: high

All cited evidence verified verbatim. AuthController.php:216-220 sendOtp() only calls Log::info (TODO SMS); .env.production.example:39 sets LOG_LEVEL=error so even the log line is dropped in production; dev_otp is gated on app()->environment('local') (:153). forgotPasswordRequest/Verify are restricted to role in ['parent','teacher'] (:132, :184), so an admin cannot self-reset at all. Grep of routes/api.php for 'password' yields only lines 20, 21, 137 (teacher self-service). Existing password-set paths cover admin->teacher (TeacherController@update :169-171), admin->manager (ManagerManagementController@update :126-134), manager->teacher (CenterManagerController@update :422-430) and teacher self; none reaches a parent. ParentResolver only accepts guardian_password when creating a NEW parent account (line 73-92); the existing-parent branch (fill name/phone/id_number) never touches password, and StudentController@update does not provide a reset either. DEPLOYMENT.md:12 explicitly acknowledges parents/teachers cannot self-recover in production without SMS. OtpResetTest exists but only exercises the local dev_otp path — it cannot mitigate the missing delivery channel. Minor correction to the auditor's framing: manager password reset IS covered (admin->manager), so the uncovered populations are exactly parents and the admin, not managers. The finding is real and accurately described; severity high is appropriate given parents are the largest role, the admin lockout has no in-app recovery, and the product ships a production env template that silently defeats the only delivery mechanism. Not downgraded because it is a documented, known gap on a pre-production checklist — the auditor's point is precisely that the checklist item has no in-product fallback.

```text
backend/app/Http/Controllers/Api/AuthController.php:132 and :184 — `User::whereIn('role', ['parent', 'teacher'])` excludes admin (and center_manager) from OTP recovery entirely; :216-220 sendOtp() is Log::info only. backend/app/Support/ParentResolver.php:60-92 — password only set on new-parent creation; existing-parent branch (fill name/phone/id_number, save) never updates password, so the admin/manager student-create/update flows cannot reset a locked-out parent. backend/app/Http/Controllers/Api/TeacherController.php:169-171 — admin CAN reset teacher passwords; ManagerManagementController.php:126-134 — admin CAN reset manager passwords; so the gap is exactly parent + admin (managers are covered, contrary to the finding title's parenthetical scope). backend/routes/api.php:20,21,137 — only password routes. backend/tests/Feature/OtpResetTest.php — covers OTP logic via dev_otp (local env) only, no delivery-channel coverage.
```

</details>

### No offline capability and no retry-safety: attendance pre-selects 'حاضر' and unsaved marks are lost on a failed request; memorization/test/message creates duplicate on retry; only xlsx import survives an outage

<a id="offline-capture-absent"></a>

`offline-capture-absent` · 🟠 high (reviewers → low) · ✅ confirmed · **NOW** · effort L (1–2 weeks)

**Files:** `frontend-html/js/config.js:30`, `frontend-html/teacher/attendance.html:42`, `frontend-html/teacher/attendance.html:83-85`, `frontend-html/js/api.js:44-48`, `backend/app/Http/Controllers/Api/MemorizationController.php:175`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:72`, `backend/app/Http/Controllers/Api/MessageController.php:172`

**Evidence**

```text
config.js:30 `// ... لا service worker (لا عمل دون اتصال بعدُ عمداً)` — deliberate; `find . -iname 'sw.js'` → none; manifest.webmanifest has no offline scope. attendance.html:42 `const cur = att[id] || 'present';` (every unrecorded student is pre-marked present); :83-85 `} catch (e) { ... if (!conflicts) { UI.toast(e.message, 'danger'); return; }` — no draft, no queue, no retry; api.js:44-48 wraps fetch with no timeout/AbortController and throws a generic 'تعذّر الاتصال بالخادم'. Only `attendances` has a unique key (migration 2024_01_01_000030:22); MemorizationController.php:175 `Memorization::create([`, WeeklyTestController.php:72 `WeeklyTest::create([`, MessageController.php:172 `Message::create([` are plain inserts with no client id/Idempotency-Key (grep for 'idempotency|uuid|since|ETag' in controllers → nothing). AttendanceImportController is transactional and idempotent (lines 97, 300, 360) but applies only to centers with a fingerprint device.
```

**Why it matters**

During the recurrent power/connectivity outages of the Libyan operating context a teacher loses everything typed since the last successful save; a hasty re-save after reconnect writes false 'present' rows for absent students; a timed-out POST that actually succeeded creates duplicate memorization/test/message rows when retried. Today the centers lose: all in-session manual attendance and memorization/test entries not yet saved, and the ability to view anything (no cached reads). A Flutter client that merely mirrors this behaviour will fail in the field.

**Recommendation**

Lock the offline policy before Flutter sprint 1: (a) mobile is offline-first for attendance, memorization, weekly tests and messages with a durable local queue (≥72 h) and read caches of student lists/surah reference; (b) backend adds idempotency: client-generated `client_uuid` unique column on memorizations/weekly_tests/messages/student_requests (or an `Idempotency-Key` middleware keyed per token), returning the original row on replay; (c) conflict rules written down: attendance = existing 409/confirm protocol with `corrected_by` audit on override; precedence manager correction > device import > teacher manual (today import silently overwrites corrections — see import finding); memorization = (student, date, surah) duplicate → 409 with the existing record; (d) default attendance state must be 'unrecorded', not 'present', with an explicit 'الكل حاضر' action; (e) add `?since=` delta endpoints and `updated_at` on list payloads; (f) web client: add a minimal service worker for the app shell and a localStorage draft of the attendance grid as a stop-gap.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 78% · corrected severity: low

Quoted evidence exists and is accurately located: config.js:30 explicitly defers offline support; no sw.js; api.js:44-48 has no timeout/AbortController; attendance.html:42 pre-selects 'present'; MemorizationController:175, WeeklyTestController:72, MessageController:172 are plain creates with no client id/Idempotency-Key; only attendances has a unique key (student_id,date). So the core absence-of-offline/idempotency claim is factually correct. However several load-bearing sub-claims are wrong or exaggerated: (1) "unsaved marks are lost on a failed request" is false — attendance.html:83-85 only shows a toast and returns; the radio grid is untouched, so the teacher's marks survive and a second click on حفظ resends them. Marks are lost only on page reload/close/power cut, which is a normal web-app property, not a defect. (2) "hasty re-save writes false 'present' rows" requires a reload first; and the store path (AttendanceController:57-115) is an upsert under a unique key with a 409+confirm protocol for changing any already-saved status (covered by tests/Feature/AttendanceDuplicationTest.php), plus the UI offers explicit 'الكل حاضر'/'الكل غائب' buttons. So attendance is already retry-safe and duplicate-proof; the 'present' default is a deliberate UX choice, not a resilience bug. (3) Weekly-test duplication is an explicitly documented design decision (WeeklyTestController:62-66: repeated tests on the same day are intentionally allowed, each is an independent record) — the auditor's "duplicate on retry" is by design there. (4) All create paths disable the submit button during the in-flight request (ui.js:196-204 formModal; teacher/parent messages.html:98-104), so accidental double-submit is blocked; the remaining duplicate window is only "server committed but response never arrived", which without a client timeout only occurs on a true mid-response connection drop — real but narrow, and memorization records are visible and deletable by the teacher. (5) "Only xlsx import survives an outage" is misleading: no operation survives an outage in the sense of being queued; import is merely idempotent on replay, as attendance manual save also is. (6) Offline-first mobile/Flutter requirements are not a stated requirement anywhere in the repo; the finding is largely a roadmap recommendation framed as a high-severity defect. Net: real gap (no idempotency key on memorization/messages, no fetch timeout, no drafts/offline) but the concrete data-loss/duplication scenarios are mostly mitigated or by-design; downgrade to low.

```text
frontend-html/teacher/attendance.html:80-85 — catch block only toasts; DOM radio state preserved, so marks are NOT lost on a failed request (only on reload). backend/app/Http/Controllers/Api/AttendanceController.php:57-115 — Attendance::updateOrCreate under unique(student_id,date) with 409+confirm on status change; retry of manual save is idempotent (tests/Feature/AttendanceDuplicationTest.php). frontend-html/teacher/attendance.html:66-67 — explicit 'الكل حاضر'/'الكل غائب' actions already exist. backend/app/Http/Controllers/Api/WeeklyTestController.php:62-66 — duplicate weekly tests are a documented, deliberate design decision. frontend-html/js/ui.js:196-204 and frontend-html/teacher/messages.html:98-104, frontend-html/parent/messages.html:98-104 — submit buttons disabled while request in flight (double-submit guard). Remaining genuine gaps: api.js:44-48 no timeout; MemorizationController.php:175 and MessageController.php:172 plain inserts with no client_uuid/Idempotency-Key; config.js:30 offline deliberately deferred.
```

</details>

### Production runs code 68 commits behind HEAD (12+ routes, 2 migrations missing); deploy method is contradictory (manual File Manager vs .cpanel.yml git deploy); migrations are hand-applied SQL with no code/schema check and no rollback procedure

<a id="deploy-drift-schema-coupling"></a>

`deploy-drift-schema-coupling` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/DEPLOY_LOG.md:8-9`, `backend/DEPLOY_LOG.md:21-22`, `backend/DEPLOY_LOG.md:35-37`, `backend/DEPLOY_LOG.md:46`, `.cpanel.yml:5-7`, `backend/routes/api.php:59-61`

**Evidence**

```text
`git rev-list --count fbe25fc..HEAD` → 68 (fbe25fc is the only entry marked `حالة الرفع: done`, DEPLOY_LOG.md:37; 7 of 8 entries are `pending`). `git diff --stat fbe25fc..HEAD` → 80 files, +4990/-1488; new routes not in production include `PUT /manager/students/{id}/teacher`, `/manager/parents`, `/manager/parents/search`, `/manager/teachers/{id}/performance`, `/manager/reports/{center,teacher,student}`, `/manager/centers`, `POST /manager/student-requests`, `/centers/{id}/{stats,teachers,students}` (routes/api.php:59-61,66,76-78,86,88,103-105); missing migrations `2026_09_08_100000_make_target_teacher_nullable_on_student_requests.php`, `2026_09_11_100000_add_parent_code_sequence.php`. DEPLOY_LOG.md:8-9 `النشر يدوي حصراً عبر cPanel File Manager ... لا شيء يصل الخادم من تلقاء نفسه` vs commit 9831f1c (2026-09-11) adding .cpanel.yml 'نشر تلقائي من Git Version Control في cPanel' — the log has no entry for it. DEPLOY_LOG.md:21-22 `الهجرات تُرفق بـSQL خام (لا artisan migrate على الخادم)`; .cpanel.yml:5-7 rsyncs files and deletes bootstrap cache but runs no migration, composer or version check.
```

**Why it matters**

A Flutter client built against HEAD will 404 on production; code and schema can silently diverge (a deployed controller referencing a column that the hand-applied SQL missed = 500s for all centers); there is no rollback beyond re-uploading an older zip with no matching DB state; the contradictory deploy story means a second person cannot know which path is live.

**Recommendation**

Now: decide and document ONE deploy path (cPanel Git Version Control with .cpanel.yml is the better one: no SSH needed) and update DEPLOY_LOG.md header accordingly; deploy HEAD to production before the mobile team codes against it; add `GET /api/version` returning git hash, app version and `SELECT COUNT(*) FROM migrations` so drift is visible in one call; make every deploy entry require 'pre-deploy dump: <file>' and 'schema SQL applied: yes/no'. Next: a staging cPanel subdomain (or local mirror) where the SQL + code pair is rehearsed; tag releases; a rollback recipe = previous tag + pre-deploy dump.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

The auditor's quoted evidence exists exactly as cited (DEPLOY_LOG.md header lines 8-9/21-22, fbe25fc the only `done` entry, `git rev-list --count fbe25fc..HEAD` = 68, 80 files / +4990/-1488, .cpanel.yml added in 9831f1c with no log entry, both migrations listed). But the headline factual claim — that PRODUCTION runs fbe25fc, 68 commits behind, with 12+ routes missing — is wrong: it treats the stale DEPLOY_LOG as ground truth instead of checking the server. I probed the live API at https://mutqin.ly/backend/public/api with `Accept: application/json` (unauthenticated GETs; Laravel returns 401 for an existing protected route and 404 for a non-existent one): `/manager/centers` (added a07327d, 09-08), `/centers/1/stats` (dc0556d, 09-11 08:37), `/manager/teachers/1/performance` (9d3c1cb, 08:51), `/manager/reports/center|teacher/1|student/1` (0ca4274, 08:59), `/manager/student-requests`, `/manager/parents/search` all return 401 (present); `PUT /manager/students/1/teacher` (542ebaa) returns 401 (present). Only `/manager/parents` (fd4a2e2, 09-11 14:28) returns 404, and `manager/parents.html` is 404. Production login.html has no demo panel (b2501ae, 09:33 applied) but `POST /auth/login {}` still returns the pre-a1cb65d message «البريد الإلكتروني مطلوب» (a1cb65d 11:58 not applied). So production sits at ≈9831f1c/b2501ae — precisely the commit that added .cpanel.yml — strongly indicating the cPanel Git deploy DID run and is the live path, and the log header is simply stale. Real drift is `git rev-list --count 9831f1c..HEAD` = 22 commits, 46 files, +1325/-131, 1 missing route (`GET /manager/parents`), login-by-code (a1cb65d) missing, and 1 migration (2026_09_11 parent code sequence — a single idempotent `INSERT INTO code_sequences`, whose SQL is also documented in the migration docblock and folded into the pending clean dump per the e7c50f9 log entry). The 2026_09_08 migration's routes are live, so it was presumably applied. What survives: DEPLOY_LOG contradicts reality (says manual-only, marks 7/8 entries pending, has no .cpanel.yml entry); no /version endpoint; no code/schema check; migrations hand-applied; no rollback recipe; HEAD (09-11 15:43) not deployed 3 days later. That is a genuine but modest operational-hygiene gap for a one-person shared-hosting deploy, not a "12+ routes 404 for the mobile team" situation. The specific 500-risk claim (deployed controller referencing missing column) is speculative — the only pending schema change is a sequence row, and the parent-code path is reached only when creating a new parent. Downgrade to medium: the recommendation (document one path, add version endpoint, log honestly) still applies. I could not verify production DB state directly (no credentials, read-only mandate).

```text
Live probe 2026-09-14 of https://mutqin.ly/backend/public/api (Accept: application/json): GET /manager/centers → 401, /centers/1/stats → 401, /manager/teachers/1/performance → 401, /manager/reports/center → 401, /manager/reports/teacher/1 → 401, /manager/reports/student/1 → 401, /manager/parents/search → 401, PUT /manager/students/1/teacher → 401 (all routes exist on prod); GET /manager/parents → 404 (only missing route; introduced fd4a2e2 2026-09-11 14:28); /api/nonexistent-xyz → 404 (control). https://mutqin.ly/login.html contains 0 'demo' mentions (b2501ae applied). POST /auth/login {} returns "البريد الإلكتروني مطلوب" = pre-a1cb65d AuthController (a1cb65d 11:58 not applied). Production therefore ≈ 9831f1c (10:53, the .cpanel.yml commit) — `git rev-list --count 9831f1c..HEAD` = 22, `git diff --stat 9831f1c..HEAD` = 46 files, +1325/-131 (not 68 / 80 files / +4990). Pending migration backend/database/migrations/2026_09_11_100000_add_parent_code_sequence.php:17-22 is a single idempotent INSERT into code_sequences (raw SQL given at lines 11-13; also included in the pending dump per DEPLOY_LOG.md:39-41). Still valid: DEPLOY_LOG.md:8-9 manual-only claim vs .cpanel.yml:1-7 and the matching prod snapshot; no log entry for 9831f1c; no /api/version route in backend/routes/api.php.
```

</details>

### One shared cPanel account (API + static client + .env + logs) and one MySQL instance with SSH disabled; no staging, no standby, no rebuild procedure

<a id="single-host-spof-no-dr"></a>

`single-host-spof-no-dr` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort L (1–2 weeks)

**Files:** `backend/DEPLOY_LOG.md:3-9`, `backend/DEPLOY_LOG.md:13-19`, `.cpanel.yml:4-7`, `backend/.env.production.example:41-47`

**Evidence**

```text
DEPLOY_LOG.md:3-5 `المستضيف: Libyan Spider — لوحة cPanel · المستخدم [redacted-cpanel-user] ... جذر الويب: /home/[redacted-cpanel-user]/public_html/ (الواجهة فيه مباشرة + public_html/backend/ شجرة لارافيل) ... القاعدة: [redacted-db-name]`; :6-7 SSH refused; :13-19 lists `.env`, `bootstrap/cache/`, `storage/framework/*`, `storage/logs/` as hand-created server-only files 'لا تُدهس ولا تُحذف'. .cpanel.yml:4 `export DEPLOYPATH=/home/[redacted-cpanel-user]/public_html` — a single target. No staging environment, no second host, no infrastructure-as-code, no documented steps to rebuild from zero. The production DB engine/version is not recorded anywhere (DEPLOYMENT.md:43 mentions MariaDB 10.4 for local XAMPP only).
```

**Why it matters**

Any host-side event (LVE suspension for CPU abuse, disk full from unpruned logs/tokens, provider outage, billing lapse, account compromise) is a 100 % outage for every center simultaneously, and because `.env` (APP_KEY, DB creds) exists only on that host, even the code cannot be brought up elsewhere without re-creating secrets by hand. Recovery time is unbounded because nobody has ever rebuilt the stack.

**Recommendation**

Write and rehearse a cold-rebuild runbook: git clone → build `vendor/` locally on the same PHP minor and zip → `.env` from the vault → import latest dump → smoke test `/up` + one login per role; time it and record it as the measured RTO. Keep a 'cold standby package' (vendor.zip + .env template + last dump) off-site. Store `.env` values in the vault. Record host limits (PHP version, DB engine/version, LVE entry-process/CPU/IO limits, disk quota). Before exceeding ~10 centers, move to a VPS or managed MySQL where cron/CLI/binlog backups are first-class; keep DNS TTL ≤300 s so a failover is fast.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

The quoted evidence is accurate: backend/DEPLOY_LOG.md:3-9 documents a single Libyan Spider cPanel account (user [redacted-cpanel-user], PHP 8.3) hosting frontend + Laravel tree + DB `[redacted-db-name]`, with SSH refused and deployment done purely by hand via File Manager; :13-19 lists .env, bootstrap/cache and storage/* as hand-created server-only files; .cpanel.yml:4-7 rsyncs to the single DEPLOYPATH. No staging host, standby, IaC, or step-by-step cold-rebuild runbook exists in the repo. So the finding is factually correct and cannot be refuted on correctness grounds.

However it is overstated and several partial mitigations exist that the auditor omits:
- Code is fully in git; backend/.env.production.example (61 lines) is a complete, commented template of every .env key, so rebuilding .env is a documented fill-in exercise, not guesswork.
- APP_KEY loss is not data-destroying: the app has no `encrypted` casts and no Crypt:: usage (grep of app/ returns nothing); passwords are bcrypt, Sanctum tokens are sha256-hashed, sessions/cache are file-based and disposable. A new APP_KEY only invalidates tokens/sessions.
- Off-host DB dumps already exist and are logged (DEPLOY_LOG.md:43, :52 — C:\mutqin-deploy\*.sql, full dumps with DROP TABLE IF EXISTS), and DEPLOYMENT.md:15 mandates at least daily mysqldump. This is a de facto cold-standby package, albeit ad hoc and untimed.
- LOG_CHANNEL=daily (.env.production.example:37-39) already addresses the 'disk full from unpruned logs' scenario; Sanctum tokens expire after 7 days (config/sanctum.php:55), though expired rows are not pruned (no sanctum:prune schedule) — a slow-growth concern, not an acute one.
- Product context: a small Libyan single-tenant Quran-center system with, per the log, one center and ~50 students at the time of writing; a cPanel shared host with provider-level backups (JetBackup is standard on cPanel) is a proportionate choice at this scale. Recovery is not 'unbounded' — the pieces (git, template, dumps) are all present; what is missing is a written, rehearsed procedure and a recorded RTO.

Net: real single-point-of-failure with no rehearsed rebuild, but the impact claims (secrets irrecoverable, unbounded RTO) are exaggerated. Severity should be medium, primarily a documentation/operations gap (write and time the runbook, note host limits/DB version, confirm provider backups).

```text
backend/DEPLOY_LOG.md:3-9, :13-19 (single cPanel host, SSH disabled, hand-created server-only files) — confirmed. .cpanel.yml:4-7 (single DEPLOYPATH) — confirmed. Mitigations: backend/.env.production.example:1-61 is a full .env template (APP_KEY at :21 with instructions, LOG_CHANNEL=daily at :39, SESSION_DRIVER=file :51, CACHE_STORE=file :58); backend/DEPLOY_LOG.md:43 and :52 record full off-host SQL dumps at C:\mutqin-deploy\; DEPLOYMENT.md:15 mandates daily mysqldump; no `encrypted` casts or Crypt:: usage anywhere in backend/app so APP_KEY rotation loses nothing but live tokens; config/sanctum.php:55 tokens expire in 7 days. Gaps that remain: no written/timed rebuild runbook, no sanctum:prune schedule, production DB engine/version unrecorded (DEPLOYMENT.md:43 is local MariaDB 10.4 only).
```

</details>

### Laravel v11.51.0 is six months past its security-fix end (2026-03-12) and the host cannot run composer, with no documented vendor-upgrade procedure

<a id="laravel-11-past-security-eol"></a>

`laravel-11-past-security-eol` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/composer.lock:1133-1134`, `backend/composer.json:9`, `backend/DEPLOY_LOG.md:6-7`, `.cpanel.yml:6`

**Evidence**

```text
composer.lock:1133-1134 `"name": "laravel/framework", "version": "v11.51.0"`; composer.json:9 `"laravel/framework": "^11.0"`. Laravel 11 bug fixes ended 2025-09-03 and security fixes 2026-03-12; today is 2026-09-14. DEPLOY_LOG.md:6-7: no composer on the server; .cpanel.yml:6 excludes `vendor` from deploy, so any dependency update means rebuilding vendor locally (PHP 8.2.12 platform pin, composer.json:69, vs PHP 8.3 on the host, DEPLOY_LOG.md:3) and uploading a zip — a procedure that appears nowhere.
```

**Why it matters**

A framework/Sanctum/mPDF/PhpSpreadsheet CVE cannot be patched by an upgrade path anyone has written down, and the longer the gap grows the riskier the jump (L11→L12 breaking changes). A public mobile app increases the API's exposure exactly when patches stop.

**Recommendation**

Plan the Laravel 12 upgrade in the next 8 weeks using the 178-test suite as the gate; document runbook R9 'vendor bundle deploy' (build with the host's PHP minor via `composer install --no-dev --optimize-autoloader` under `platform.php=8.3.x`, zip vendor/, upload+extract via File Manager, delete bootstrap/cache/*.php); subscribe to GitHub security advisories for the five runtime packages; record the framework EOL date in the BC policy with a quarterly review.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

All quoted evidence verified. backend/composer.lock:1133-1134 pins laravel/framework v11.51.0; composer.json:9 constrains ^11.0 (never allows L12); composer.json config.platform.php = 8.2.12 with platform-check=false. backend/DEPLOY_LOG.md:3-9 states the host runs PHP 8.3, SSH is refused, and there is "no SSH / CLI / artisan / composer / git" on the server; deployment is manual via cPanel File Manager. .cpanel.yml:6 rsyncs backend/ with --exclude='vendor', so vendor is never deployed by the pipeline. Laravel 11 security support did end 2026-03-12, so the framework is ~6 months past security EOL. Searched DEPLOYMENT.md, DEPLOY_LOG.md and README.md: no vendor rebuild/upload procedure exists (only the php.ini zip/gd note at DEPLOYMENT.md:35 and a boost dev-require at README.md:37). The finding is factually correct and not mitigated in code (no code mitigation is possible for this class of issue).

However it is over-rated at high: (1) no concrete unpatched CVE against v11.51.0 is cited — the risk is latent, not an exploitable defect today; (2) of the five runtime packages, only laravel/framework is EOL — laravel/sanctum v4.3.2, mpdf v8.3.1 and phpoffice/phpspreadsheet 5.8.0 (lock:1407-1408, 2444-2445, 3136-3137) are current, supported lines that install on both L11 and L12; (3) the "riskier the jump" claim is exaggerated — Laravel 12 was deliberately a minimal-breaking-change release, and the ^11.0 → ^12.0 bump is small; (4) the 8.2.12 platform pin vs PHP 8.3 host is not a runtime blocker (platform-check is disabled and 8.2-compatible packages run on 8.3); (5) DEPLOY_LOG.md:31-34 shows the team already performed a full-package zip upload including vendor (mutqin-upload.zip, 2026-09-07), so the capability exists — only the runbook is missing. The test suite has 173 test methods (not 178) in tests/Feature, which remains a usable gate. Real, documentation/lifecycle gap; corrected to medium.

```text
backend/composer.lock:1133-1134 laravel/framework v11.51.0 (EOL security 2026-03-12); backend/composer.json:9 "^11.0", :68-70 platform php 8.2.12, platform-check false; backend/DEPLOY_LOG.md:3,6-9 PHP 8.3 host, no SSH/composer, manual cPanel upload; DEPLOY_LOG.md:31-34 prior full-package zip upload (vendor included) shows the procedure was done once but never documented; .cpanel.yml:6 --exclude='vendor'. Mitigating: composer.lock:1407-1408 sanctum v4.3.2, :2444-2445 mpdf v8.3.1, :3136-3137 phpspreadsheet 5.8.0 are all current; tests/Feature contains 173 test methods (not 178).
```

</details>

### Unauthenticated `/public/stats` and `/public/demo-accounts` hit the database on every call with no cache and no rate limit; no global API limiter exists

<a id="public-endpoints-uncached-unthrottled"></a>

`public-endpoints-uncached-unthrottled` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/DashboardController.php:124-134`, `backend/routes/api.php:16-23`, `backend/app/Providers/AppServiceProvider.php:1`, `backend/bootstrap/app.php:14-21`

**Evidence**

```text
DashboardController.php:129-131 `'centers' => Center::where('is_active', true)->count(), 'users' => User::count(), 'students' => Student::where('is_active', true)->count()` — three live COUNTs per hit; `grep -rn 'Cache::|->remember(' app/` → no results. routes/api.php:16-21 attach `throttle` only to login and the two OTP routes; :22-23 the public routes have none; `grep -rn 'RateLimiter|throttle' app/Providers bootstrap/app.php` → nothing, so no `api` limiter is defined for the authenticated group either.
```

**Why it matters**

On a shared host with LVE limits, a trivial loop of requests to a public endpoint (or a misbehaving mobile build) exhausts the PHP process quota and takes every center offline — an availability failure that needs no credentials.

**Recommendation**

Cache `publicStats` for 5-10 minutes (`Cache::remember`, file store is fine), add `throttle:60,1` to the public group and a global `throttle:api` (e.g. 120/min per token or IP) via `RateLimiter::for('api')`, and return `Retry-After` so the mobile client can back off.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted evidence is accurate: routes/api.php:22-23 register /public/stats and /public/demo-accounts with no middleware; DashboardController::publicStats (lines 124-134) runs three live COUNT queries; grep over app/, routes/, bootstrap/ finds no Cache::/remember() usage and no RateLimiter::for definition, so there is no 'api' limiter for the auth:sanctum group and the only throttles are on login and the two OTP routes. bootstrap/app.php registers only the four role aliases; AppServiceProvider::boot is empty. No test covers the public routes' throttling (only AuthLoginTest touches throttle). So the finding is real. However it is over-rated: (1) demoAccounts short-circuits to an empty array with zero DB access when APP_ENV != local and APP_DEBUG is false (DashboardController.php:104-106), so in production only publicStats touches the DB; (2) the three queries are COUNT(*) on small tables (single-country, handful of centers) — trivially cheap; (3) the described DoS (exhausting the PHP process quota) applies to any route in a Laravel app because the framework boots before throttle middleware runs — a throttle:60,1 or Cache::remember reduces DB work but does not materially protect the PHP process quota, and the same attacker can hit /up, /api/auth/login (already throttled, still boots PHP) or a 404 route. Rate limiting and caching are reasonable hygiene, but the availability impact is not distinctively enabled by these two endpoints. Downgrade to low.

```text
backend/routes/api.php:22-23 — public routes with no middleware (confirmed). backend/app/Http/Controllers/Api/DashboardController.php:104-106 — demoAccounts returns `['success'=>true,'data'=>[]]` without any query unless app()->environment('local') or config('app.debug'), so in production it does not hit the DB (partial mitigation the auditor omitted). DashboardController.php:129-131 — three COUNT queries in publicStats (confirmed, but cheap indexed counts). backend/bootstrap/app.php:14-20 and backend/app/Providers/AppServiceProvider.php:19-22 — no RateLimiter::for('api') and no global throttle (confirmed). backend/.env.example:40 CACHE_STORE=database — a cache store is configured and available, but unused in app/.
```

</details>

### The only automated operational alert (daily attendance digest) is an n8n workflow meant to run on a personal machine with the admin password in a Set node, creating a new admin token every day and failing silently

<a id="n8n-digest-laptop-spof"></a>

`n8n-digest-laptop-spof` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `n8n/README.md:19-25`, `n8n/mutqin-daily-attendance-digest.json:32`, `n8n/mutqin-daily-attendance-digest.json:43-44`, `n8n/mutqin-daily-attendance-digest.json:87-88`

**Evidence**

```text
n8n/README.md:19-22 `set apiBase (default http://localhost:9090), email, password, and sendTo. The password sits in plain text for demo convenience`; json:32 `"value": "http://localhost:9090"`; :43-44 the admin password field; :87-88 a `POST /api/auth/login` node runs every day and there is no logout node (`grep -c logout` → 0) so a 7-day admin token accumulates per run; README:23 requires an SMTP credential although production has none (MAIL_MAILER=log). No dead-man's switch: if the laptop is off at 20:00 nothing reports the missed run.
```

**Why it matters**

The one control that catches 'teacher forgot to record attendance' depends on a laptop being on, holds the highest-privilege credential outside the vault, and its failure is invisible. At dozens of centers the digest also goes to a single `sendTo` address rather than each center manager.

**Recommendation**

Replace with a server-side artisan command (`attendance:digest`) scheduled via cPanel Cron → `php artisan schedule:run`, sending to each center manager through a configured transactional mail/SMS provider; if n8n stays, host it on a server, use an n8n credential, a least-privilege digest account (not admin), add a logout node, and register the run with a dead-man's-switch (healthchecks.io ping) that alerts when 20:00 passes without a run.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The cited evidence is accurate: n8n/README.md:19-22 documents plaintext admin password in the Set node (json:43-44, default email admin@mutqin.ly at :38, sendTo single address at :50, apiBase localhost at :32). The workflow has a POST /api/auth/login node (json:70) and no logout node; AuthController::login (line 64) calls createToken without revoking prior tokens, sanctum expiration is 10080 min (7 days), and there is no sanctum:prune-expired or any scheduled command (routes/console.php has only the default 'inspire'; no app/Console/Commands). So one admin-ability '*' token accumulates per daily run and stays valid 7 days — confirmed. MAIL_MAILER=log in both .env.example and .env.production.example, so no transactional mail exists on the server side. No dead-man's switch anywhere. Mitigations that soften the finding: (1) the password field is a placeholder ('PUT_PASSWORD_HERE'), the commit is explicitly labelled 'credentials as placeholders', and the README tells the operator to replace it with an n8n credential before production — so the repo itself leaks nothing; (2) the workflow is a bolt-on demo utility, not referenced by any deployment doc, launcher, or app code — the product does not depend on it, and the claim that it is 'the only automated operational alert' is true simply because the app has no scheduler at all, which is the more fundamental gap; (3) 'meant to run on a personal machine' is inferred from the localhost default and a Docker note, not stated — n8n could equally be hosted. Token accumulation is real but bounded (7 tokens live at any time) and low-impact. Overall the finding is factually correct but describes an optional demo workflow with documented hardening steps, so 'medium' overstates it; 'low' is appropriate, with the substantive residual point being that the core app has no server-side scheduled digest/alerting and no token pruning.

```text
n8n/mutqin-daily-attendance-digest.json:38 default email admin@mutqin.ly; :44 password placeholder "PUT_PASSWORD_HERE" (not a real secret); :50 single sendTo; :70 login node; no emailSend logout node in file. backend/app/Http/Controllers/Api/AuthController.php:64 createToken without revoking existing tokens. backend/config/sanctum.php:55 expiration 10080 (7 days). backend/routes/console.php: only 'inspire' command — no scheduler, no sanctum:prune-expired, no digest command. backend/.env.example:50 and backend/.env.production.example:66 MAIL_MAILER=log. git commit 1a2d09b "chore(n8n): daily attendance digest workflow (credentials as placeholders)". No reference to n8n from README/DEPLOYMENT/launcher — it is an optional add-on, not a product dependency.
```

</details>

### No scheduled maintenance at all: expired Sanctum tokens, OTP rows, notifications, messages and file-cache/throttle files grow forever; no cron is configured on the host

<a id="no-scheduler-no-pruning"></a>

`no-scheduler-no-pruning` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/routes/console.php:6-8`, `backend/app/Http/Controllers/Api/AuthController.php:66`, `backend/config/sanctum.php:55`, `backend/.env.production.example:57-58`, `backend/app/Http/Controllers/Api/NotificationController.php:20`

**Evidence**

```text
routes/console.php:6-8 defines only `Artisan::command('inspire', ...)`; `find app/Console` → empty; no `Schedule::` anywhere. AuthController.php:66 `$token = $user->createToken('auth_token', $abilities)->plainTextToken;` on every login (and every n8n run) and nothing calls `sanctum:prune-expired`; config/sanctum.php:55 `'expiration' => 10080` only invalidates, never deletes. OtpReset rows are deleted only on the next request by the same user (AuthController.php:139,191,204). NotificationController.php:20 `->limit(30)` reads the newest but nothing prunes; messages table has no retention (migration 2026_08_22). .env.production.example:57-58 `CACHE_STORE=file` — throttle keys (login/OTP per IP) become files under storage/framework/cache/data that the file driver never garbage-collects without CLI.
```

**Why it matters**

On a shared host with a disk quota, unbounded growth of `personal_access_tokens` (one row per login × thousands of parents), notifications and cache files eventually fills the quota → 500s for everyone; large token tables also slow every authenticated request (token lookup) and backups.

**Recommendation**

Add a cPanel Cron Job `* * * * * /usr/local/bin/php /home/[redacted-cpanel-user]/public_html/backend/artisan schedule:run` (no SSH required) and in routes/console.php schedule `sanctum:prune-expired --hours=24` daily, `model:prune` with `Prunable` on OtpReset (>1 day), notifications (>90 days read), and the backup/digest commands; document retention periods in the BC policy (attendance/memorization/tests: indefinite; messages: 24 months; notifications: 90 days; logs: 30 days off-box).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The factual core holds: backend/routes/console.php contains only the `inspire` command, `app/Console` does not exist, and no `Schedule::`, `prune`, `Prunable` or `sanctum:prune-expired` reference exists anywhere in app/, routes/, config/ or bootstrap/. AuthController::login (line 66) calls `createToken` on every login without deleting prior tokens; logout (line 91) deletes only the current token; the frontend on 401 (api.js:50-57) clears localStorage but the expired row stays in `personal_access_tokens`. The n8n digest (n8n/README.md:13, workflow json line 70) logs in daily and never calls /auth/logout, so it leaks one token row per day. DEPLOYMENT.md / DEPLOY_LOG.md / README.md contain no cron or retention guidance. .env.production.example:58 indeed sets CACHE_STORE=file.

However the impact is overstated on several points: (1) OTP rows are NOT unbounded — `OtpReset::where('user_id')->delete()` before create (AuthController:139) and delete on verify/expiry (191, 204) cap the table at one row per user who ever requested a reset; (2) Sanctum resolves tokens by primary-key id from the `id|hash` format and `expires_at` is indexed (migration 2026_06_19, line 21), so a large token table does not measurably slow authenticated requests; (3) deactivation paths already purge tokens (User.php:81, TeacherController:211, CenterController:268, CenterManagerController:483, ManagerManagementController:174); (4) Laravel's file cache store deletes expired entries on read, and per-IP throttle keys for a single-country, few-thousand-user product are tiny; (5) realistic growth (weekly logins by a few thousand users) is on the order of tens of MB per year — a hygiene issue, not a near-term disk-quota outage. The missing cron/scheduler and pruning is a genuine operational gap, but medium is too high for this product scale.

```text
backend/routes/console.php:1-8 (only `inspire`; no Schedule); `app/Console` absent; no `prune`/`Prunable`/`Schedule::` in app/, routes/, config/, bootstrap/. AuthController.php:66 createToken per login, :91 logout deletes only currentAccessToken. OtpReset bounded to one row per user: AuthController.php:139 (`OtpReset::where('user_id')->delete()` before create), :191 and :204 — refutes the "OTP rows grow forever" sub-claim. Token revocation on deactivate/password change: app/Models/User.php:81, TeacherController.php:211, CenterController.php:268, CenterManagerController.php:483, ManagerManagementController.php:174. personal_access_tokens has indexed expires_at (database/migrations/2026_06_19_072223_create_personal_access_tokens_table.php:21) and Sanctum looks up by PK — "slows every authenticated request" is exaggerated. n8n/mutqin-daily-attendance-digest.json:70 logs in daily with no logout call (one leaked token row/day). DEPLOYMENT.md, DEPLOY_LOG.md, README.md: no cron/retention documentation.
```

</details>

### Re-importing an xlsx silently overwrites manager corrections (leaving a stale corrected_by audit) and a bad import cannot be reverted as a batch — recovery is one manual click per record

<a id="import-overwrites-corrections-no-batch-undo"></a>

`import-overwrites-corrections-no-batch-undo` · 🟡 medium · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/AttendanceImportController.php:300-312`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:360-372`, `backend/app/Http/Controllers/Api/CenterManagerController.php:580-585`, `backend/database/migrations/2026_07_18_120000_add_correction_audit_to_attendances.php:18-20`

**Evidence**

```text
AttendanceImportController.php:300-312 `Attendance::updateOrCreate(['student_id'=>..., 'date'=>...], [... 'status' => $status, 'notes' => 'حضور مستورد من جهاز البصمة' ..., 'imported_at' => now()])` — the update array does not check or reset `corrected_by`/`corrected_at`, so a row a manager corrected (CenterManagerController.php:582-584 sets `corrected_by`, `corrected_at`) is overwritten by a later import while still claiming to be 'corrected'. Computed absences (lines 360-372) use `firstOrCreate` and carry no batch identifier; grep for 'batch|import_id' in migrations → only Laravel's job_batches. The only correction path is `PUT /manager/attendance/{id}/status` (routes/api.php:72), one record at a time.
```

**Why it matters**

A wrong-date or wrong-center file at a 300-student center creates hundreds of false absences that must be fixed one by one; a subsequent correct import can undo human corrections without trace. This is also the unresolved 'who wins' rule the mobile sync will inherit.

**Recommendation**

Add an `attendance_imports` table (id, uploader, center, file hash, counts) and `import_id` on attendances; expose `POST /manager/attendance/imports/{id}/revert` that deletes rows created by that batch and restores previous statuses for updated ones (store `previous_status`); skip rows with `corrected_at` unless `force=true`, and clear/append audit when overriding. Document precedence: manager correction > device import > teacher manual.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Traced the code; the auditor's claims are factually accurate. (1) AttendanceImportController.php:300-313 `updateOrCreate` keyed on (student_id, date) unconditionally writes status/notes/time/imported_at and neither checks nor clears `corrected_by`/`corrected_at`; both columns are in Attendance::$fillable (Attendance.php:18-19) so a corrected row keeps its `corrected_by/at` while its status is replaced by the device value — attendanceIndex (CenterManagerController.php:547-548) then reports `source: fingerprint` plus a `corrected_at`, i.e. a misleading audit. (2) Computed absences at :360-372 use `firstOrCreate`, which correctly does not overwrite existing rows, but a file with a wrong date creates one absent row per in-scope active student for that date, with no batch id anywhere (grep for batch/import_id/previous_status in app/ and migrations returns nothing). (3) The only remediation is `PUT /manager/attendance/{id}/status` (routes/api.php:72); there is no attendance destroy route for any role (routes 70-72, 153-156), so a bad import cannot be deleted or reverted, only re-labelled record by record. (4) Tests: ManagerAttendanceReviewTest covers correction audit fields only; no test exercises import-after-correction. No frontend guard (ui.js runAttendanceImport shows a summary modal only). Mitigations noted: the migration comment explicitly records a deliberate 'light audit, no previous status' decision, and the import runs in one transaction so partial imports do not occur. But that decision does not address re-import silently overriding human corrections, which is a real correctness/integrity defect. Severity medium is appropriate: no security impact, small single-country deployments, but a wrong-date import at a 300-student center is plausible and its cleanup is genuinely per-record with no reversal path.

```text
backend/app/Http/Controllers/Api/AttendanceImportController.php:300-313 (updateOrCreate overwrites status without checking corrected_at); :360-372 (firstOrCreate absences, no batch id); backend/app/Models/Attendance.php:9-20 (corrected_by/at fillable, never reset by import); backend/app/Http/Controllers/Api/CenterManagerController.php:547-548 (attendanceIndex exposes source=fingerprint alongside stale corrected_at); backend/routes/api.php:70-72,153-156 (no attendance destroy/revert route for any role); backend/database/migrations/2026_07_18_120000_add_correction_audit_to_attendances.php:10-11 (documented decision: previous status not stored); backend/tests/Feature/ManagerAttendanceReviewTest.php:82-83 (only test touching corrected_by/at; no import-after-correction test).
```

</details>

### No runbooks for recurring operator tasks, no support/on-call path, no per-role onboarding; backend README is stock Laravel and the frontend README/page guide are stale

<a id="no-runbooks-no-support-path"></a>

`no-runbooks-no-support-path` · 🟡 medium · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/README.md:10-22`, `frontend-html/README.md:19-26`, `دليل-محتوى-الصفحات.md:5`, `DEPLOYMENT.md:5-18`, `backend/app/Http/Controllers/Api/ManagerManagementController.php:88`

**Evidence**

```text
backend/README.md:10 'About Laravel ... Laravel is a web application framework' (unmodified skeleton). frontend-html/README.md:19 `الـ API يسمح بكل المصادر (allowed_origins: ['*'])` (false since CORS_ALLOWED_ORIGINS, config/cors.php:14-16) and :21 demo password `password` (seeders use `[redacted-demo-password]`/`[redacted-demo-password]`; CLAUDE.md says `[redacted-demo-password]`). دليل-محتوى-الصفحات.md:5 describes `layouts/app.blade.php` pages that no longer exist. The UI paths for operator tasks exist (admin/managers.html + explicit password on create ManagerManagementController.php:88; manager/attendance-review.html; status toggles) but no document tells an operator how to: create a center manager and hand over the password, reset a parent's password, correct/re-import attendance, deactivate a compromised account, rotate APP_KEY, prune data, upgrade the framework, or whom a center calls when the site is down.
```

**Why it matters**

Every operational question routes to the single developer; a new admin or a second maintainer cannot operate the system; incident response has no defined first responder or escalation; onboarding dozens of center managers and hundreds of teachers/parents without guides will generate a support load the team cannot absorb.

**Recommendation**

Create `docs/runbooks/` with the index: R1 create center + manager + credential handover (in person / vault-shared link, forced change on first login); R2 reset parent password (after the new endpoint); R3 reset teacher password (manager UI); R4 correct fingerprint attendance; R5 re-import after a bad xlsx (limits until batch revert exists); R6 deactivate a compromised account (status toggle → tokens revoked); R7 rotate APP_KEY (edit .env via File Manager, delete bootstrap/cache/*.php, note: no Crypt usage so only file sessions die); R8 prune tokens/OTP/notifications; R9 vendor/framework upgrade without composer on host; R10 deploy + rollback; R11 restore from backup; R12 outage communication to centers; plus one-page role guides (admin, manager, teacher, parent) with screenshots (screenshots/ already has 36). Define support tiers: center manager → system admin → developer, with a WhatsApp/Telegram channel and response targets.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 82% · corrected severity: medium

All quoted evidence verified. backend/README.md:10-22 is the unmodified Laravel skeleton ("About Laravel..."). frontend-html/README.md:19 still says the API allows all origins (`allowed_origins: ['*']`) while backend/config/cors.php:14-16 reads CORS_ALLOWED_ORIGINS with a closed default of ['http://localhost:8080']; README.md:21 lists demo password `password` and accounts teacher1@/parent1@ that the current seeder (DatabaseSeeder -> LibyanDataSeeder, password consts `[redacted-demo-password]` at lines 40-43) does not create; ExtraDataSeeder uses `[redacted-demo-password]` and is no longer called; CLAUDE.md says `[redacted-demo-password]` — three conflicting passwords across docs. دليل-محتوى-الصفحات.md:5 references `layouts/app.blade.php` and `public/css/mutqin.css`, neither of which exists in the static frontend-html client. DEPLOYMENT.md:11 (item 3) is likewise stale — it instructs replacing `allowed_origins => ['*']` which was already replaced by the env-driven config. There is no docs/runbooks/ directory and no document covering: creating a manager and handing over the password (ManagerManagementController.php:88 confirms the explicit password is hashed on create with no forced first-login change), parent password reset (only phone OTP with no SMS gateway), attendance correction/re-import procedure, compromised-account response, APP_KEY rotation, data pruning, DB restore, rollback, or support/escalation contacts. Partial mitigations the auditor did not credit: backend/DEPLOY_LOG.md is a real, detailed deploy runbook for the cPanel host (files never to overwrite, SQL-only migrations, per-commit upload method/status — effectively covers R9/R10 deploy-without-composer), and DEPLOYMENT.md covers backup scheduling (mysqldump), APP_DEBUG/APP_KEY, HTTPS, and pre-deploy test runs. The n8n/README.md is accurate for its scope. These narrow the gap (deploy/backup are documented) but do not cover restore, rollback, operator task runbooks, role onboarding, or a support path. The finding is factually correct and the stale/contradictory docs are a genuine operational risk; medium severity is appropriate for a single-developer product that is about to onboard real centers (DEPLOY_LOG shows a production DB was reset to a single admin account on 2026-09-11 for real data).

```text
Confirmed: backend/README.md:10-12 stock Laravel text; frontend-html/README.md:19 `allowed_origins: ['*']` vs backend/config/cors.php:14-16 env-driven closed default; frontend-html/README.md:21 password `password` + teacher1@/parent1@ accounts vs backend/database/seeders/LibyanDataSeeder.php:40-43 `[redacted-demo-password]` (the seeder actually run by DatabaseSeeder.php:19) vs ExtraDataSeeder.php:35 `[redacted-demo-password]` (no longer called, DatabaseSeeder.php:13) vs CLAUDE.md `[redacted-demo-password]`; دليل-محتوى-الصفحات.md:5 `layouts/app.blade.php` and :11 `public/css/mutqin.css` do not exist; DEPLOYMENT.md:11 item 3 also stale (tells operator to replace `allowed_origins => ['*']`, already done). Additional partial mitigation not cited by auditor: backend/DEPLOY_LOG.md:1-22 (host constraints, protected server files, SQL-only migrations, per-commit deploy method) covers deploy procedure; DEPLOYMENT.md:15 covers mysqldump backup scheduling; .cpanel.yml provides an rsync deploy definition. No restore, rollback, operator-task runbooks, role guides, or support/escalation path exist anywhere in the repo (find *.md: only CLAUDE.md, DEPLOYMENT.md, DEPLOY_LOG.md, the two READMEs, n8n/README.md, دليل-محتوى-الصفحات.md).
```

</details>

### No capacity or cost model: host limits undocumented, no load numbers, and the N+1 weekly report puts a measurable ceiling on the shared host

<a id="no-cost-capacity-model"></a>

`no-cost-capacity-model` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/ReportController.php:91-111`, `frontend-html/js/layout.js:257`, `backend/app/Services/ReportService.php:133-146`, `backend/database/seeders/LibyanDataSeeder.php:17`

**Evidence**

```text
ReportController.php:97-111 runs, per active student, `Attendance::where('student_id', $student->id)...->get()` and `Memorization::where(...)->count()` → 1 + 2N queries for the admin scope (5,000 students → 10,001 queries) and returns the full Student model per row. layout.js:257 `setInterval(() => load(true), 60000);` — every open session polls `/notifications` each minute. ReportService.php:140-144 uses `whereMonth/whereYear` (non-sargable scans over the attendances table). LibyanDataSeeder.php:17 documents the reference center profile: 1 center, 5 teachers, 50 students. Nowhere are the host's PHP `max_execution_time`, LVE entry-process/CPU limits, disk quota, bandwidth, SMS price per OTP, push cost or storage growth written down. (Note for fairness: no frontend page currently calls `/reports/weekly` — grep returns nothing — but it is a live teacher-gated endpoint the mobile client may adopt.)
```

**Why it matters**

Derived model (assumptions: 50 students/center, ~1.5 ms/query on the shared box, 30 s PHP limit, ~20 LVE entry processes): the admin weekly report exceeds 5 s at ~35 centers (1,700 students, 3,400 queries, ~2 MB JSON) and hard-times-out around ~200 centers; attendances grow ~250 rows/student/year (5,000 students → ~1.25 M rows/yr, ~200 MB with indexes) making the whereMonth scans degrade sooner; notification polling reaches the ~20-process ceiling at roughly 10,000 simultaneously open sessions (≈17 req/s) — at which point every center gets 508 errors. None of this is budgeted, so the failure point will be discovered in production.

**Recommendation**

Write `docs/CAPACITY.md` with the measured host limits (ask Libyan Spider for LVE numbers), the growth model above, and a cost sheet (hosting tier, SMS per OTP × expected resets/month, push notifications, off-site backup storage, error tracking, monitoring). Run a load test against a copy seeded at 20/50/100 centers before onboarding beyond 10; fix the N+1 in `weekly()` and the whereMonth scans (date-range predicates + composite indexes); move the polling to a cheaper unread-count endpoint or push.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted code is accurate: ReportController::weekly() (lines 97-111) does issue 2 queries per active student (Attendance ->get() and Memorization ->count()) and returns the full Student model per row; layout.js:257 polls /notifications every 60 s; ReportService::centerData uses whereMonth/whereYear (20 such call sites across app/); LibyanDataSeeder documents a 1-center/5-teacher/50-student profile; and no capacity/cost document exists anywhere (DEPLOYMENT.md has no capacity, LVE or max_execution_time content). So the finding is not factually wrong. However the impact model is materially overstated: (1) `/reports/weekly` has no caller in frontend-html (only the route at api.php:166), so the N+1 is currently dead from the product's perspective; the teacher scope, which is the only non-admin path, is bounded by one teacher's students (~10). (2) attendances carries a unique index (student_id, date) from the create migration, so each per-student whereBetween query in weekly() is an index range seek, and the whereIn('student_id', $ids)->whereMonth(...) pattern in centerData is driven by the student_id index — it is a filter over the center's rows, not a full-table scan as the "non-sargable scans over the attendances table" phrasing implies. (3) The notification poll is 2 small indexed queries per minute per open tab; the "10,000 simultaneously open sessions" ceiling is irrelevant for a single-country product whose reference profile is one center with 50 students and a handful of staff. The genuinely valid residue is a documentation/planning gap (no written host limits, no load numbers, no cost sheet) plus a latent N+1 in an unused endpoint. That is a low-severity operational-hygiene item, not medium.

```text
backend/app/Http/Controllers/Api/ReportController.php:97-111 — N+1 confirmed (Attendance::where(student_id)->get() + Memorization::where(student_id)->count() per student), but `grep -rn "reports/weekly" frontend-html` returns nothing; the only reference is the route at backend/routes/api.php:166 (teacher gate). backend/database/migrations/2024_01_01_000030_create_attendances_table.php:22 — `$table->unique(['student_id', 'date'])` means every per-student date-range query and the whereIn(student_id)+whereMonth queries in backend/app/Services/ReportService.php:139-144 are index-driven, not table scans. backend/app/Http/Controllers/Api/NotificationController.php:20-37 — the polled endpoint is two queries (latest 30 + unread count) per call. No docs/CAPACITY.md; DEPLOYMENT.md contains no capacity/LVE/max_execution_time content (grep confirmed).
```

</details>

## Measured facts

| Metric | Value |
|---|---|
| Backup automation / restore procedures / restore drills found | 0 / 0 / 0 (only DEPLOYMENT.md:15 one-line local mysqldump suggestion) |
| Scheduled tasks defined (routes/console.php, app/Console) | 0 (only the stock `inspire` command) |
| Monitoring / error-tracking / log-shipping integrations | 0 / 0 / 0; health endpoint `/up` present but unmonitored |
| Production log retention and level (template) | 14 days local, LOG_LEVEL=error (.env.production.example:38-39; config/logging.php:72) |
| Commits since the only deploy marked done (fbe25fc, 2026-09-07) | 68 commits, 80 files, +4990/-1488; ≥12 new routes and 2 migrations not in production |
| DEPLOY_LOG entries by status | 8 entries: 1 done, 7 pending |
| Git identities / total commits / AI co-authored | 3 identities (87 / 82 / 55 commits) = 224 commits; 223 carry a Claude Co-Authored-By trailer; remote is a personal GitHub account; no CI |
| Hosting | Libyan Spider shared cPanel, user [redacted-cpanel-user], PHP 8.3, SSH disabled, single DB [redacted-db-name], manual File Manager deploys (+ contradictory .cpanel.yml) |
| Framework versions | laravel/framework v11.51.0 (security fixes ended 2026-03-12), sanctum v4.3.2, token expiration 10080 min |
| Idempotent write endpoints | 1 of 5 create paths (attendances via unique student_id+date); memorizations, weekly-tests, messages, student-requests are plain inserts |
| Password reset paths without SMS | teacher←manager, manager←admin, teacher self; parent: none; admin: none. OTP: 6 digits, 10 min, 5 attempts, delivery = Log::info only |
| Offline support in web client | 0 service workers, 0 offline caches; manifest only; attendance default 'present'; fetch has no timeout/retry |
| External runtime dependencies of the web client | cdn.jsdelivr.net referenced in 33 files (Bootstrap RTL), fonts.googleapis.com in theme.css |
| Tests | 38 Feature + 2 Unit files, 178 test methods (CLAUDE.md:25 says 20 files) |
| Surface | 19 API controllers, ~95 routes, 35 migrations, 33 HTML pages (admin 9, manager 9, teacher 9, parent 3, public 3) |
| Weekly report query count (admin scope) | 1 + 2N: 1,000 students → 2,001; 5,000 students → 10,001 (ReportController.php:97-111) |
| Derived capacity ceiling (assumptions stated in finding) | weekly report >5 s at ~35 centers (1,700 students), timeout ~200 centers; ~1.25 M attendance rows/yr at 5,000 students; polling ceiling ~10k open sessions |
| Import controls | xlsx ≤5 MB, transactional, updateOrCreate for file rows, firstOrCreate for computed absences, no batch id / no batch revert |
| Runbooks / per-role user guides / on-call definition | 0 / 0 / none |
| Deploy artifacts location | C:\mutqin-deploy\ (laptop path, not in VCS, absent on audited machine); retired backend/.git backup location undocumented |

## Auditor notes

DELIVERABLE — BC/DR PLAN SKELETON (to be committed as docs/BUSINESS_CONTINUITY.md):
1. Scope & assets: API (Laravel, /home/[redacted-cpanel-user]/public_html/backend), static client (public_html), MySQL [redacted-db-name], server-only .env (APP_KEY, DB creds), storage/logs, GitHub repo, n8n digest, DNS/TLS for mutqin.ly, fingerprint devices at centers.
2. RTO/RPO by phase: A (≤10 centers, now) RPO 24 h / RTO 8 h; B (10-50 centers, Flutter live) RPO 1 h / RTO 2 h; C (50+) RPO ≤15 min / RTO 30 min; availability objective 99.5 % during center hours (Sat-Thu, Africa/Tripoli — confirm hours with centers). Degraded mode: paper roll or fingerprint xlsx retained at the center and re-imported after recovery (import is idempotent); mobile queue ≥72 h.
3. Backup & restore procedure: WHAT = full DB dump (--single-transaction --routines --triggers) + .env + storage/app; HOW = cPanel Cron (no SSH needed) hourly hot-table dump + nightly full, gzip + AES-256 (key in vault, never on host), plus host-side JetBackup/Backup Wizard; WHERE = off-site bucket (B2/Drive via rclone) + weekly download by the second maintainer; RETENTION = 24 hourly / 7 daily / 4 weekly / 12 monthly; VERIFY = monthly restore into mutqin_test with per-table COUNT(*) and CHECKSUM TABLE compared to production, logged in DEPLOY_LOG.md; DRILL = quarterly cold rebuild on a fresh host (git clone → vendor.zip → .env from vault → import → smoke test /up + one login per role), elapsed time recorded as measured RTO; pre-deploy dump mandatory line in every DEPLOY_LOG entry.
4. SPOF / dependency register (component → failure → impact → mitigation → owner): (a) cPanel account [redacted-cpanel-user] → suspension/outage → total outage → off-site backups + rebuild runbook + VPS plan; (b) MySQL single instance → corruption/bad import → data loss since last dump → hourly dumps, request binlog from host; (c) server-only .env → lost with host → cannot boot anywhere → vault copy; (d) developer (bus factor 1, 3 identities, personal GitHub) → unavailable → no deploy/restore/support → vault + org repo + second maintainer; (e) n8n on a laptop with admin password → off → digest stops silently → server cron + dead-man switch + least-privilege account; (f) future SMS provider → outage → parents cannot reset → admin/manager reset endpoint + manager-relayed OTP; (g) cdn.jsdelivr.net / Google Fonts (33 pages) → blocked or slow international link → unstyled UI → self-host assets (low severity, not listed as a finding); (h) center power/internet → no capture → offline-first mobile + xlsx path; (i) fingerprint device → no file → manual attendance with explicit 'unrecorded' default; (j) DNS/TLS registrar & AutoSSL → expiry → outage → record registrar + renewal owner in vault; (k) GitHub personal account → loss → history loss → org + mirror.
5. Credential inventory (where it lives / who else can rotate — today the answer is 'nobody' for all): cPanel (owner only, DEPLOY_LOG.md:3,23), DB user (server .env only), APP_KEY (server .env only; rotation is low-impact — no Crypt usage), admin@mutqin.ly (handed over in chat DEPLOY_LOG.md:45; plaintext in n8n Set node), GitHub muad03 (personal), SMTP (none — MAIL_MAILER=log), SMS (not contracted), n8n instance (personal machine).
6. Offline-operation policy: reads cached per screen (students, surah reference, today's grid); writes queued (attendance, memorization, weekly tests, messages) with client UUIDs; precedence manager correction > device import > teacher manual; attendance conflicts via the existing 409/confirm protocol with corrected_by audit on override; memorization duplicates (student, date, surah) → 409 with existing record; queue survives 401 and re-auth; max offline window 7 days (token expiry) — design a silent re-login; what centers lose today: all unsaved in-session entries and all read access.
7. Runbook index (all currently missing): R1 create center + manager + credential handover; R2 reset parent password (needs new endpoint); R3 reset teacher password (manager UI); R4 correct fingerprint attendance; R5 re-import after bad xlsx; R6 deactivate compromised account (status toggle revokes tokens); R7 rotate APP_KEY; R8 prune tokens/OTP/notifications/cache; R9 vendor/framework upgrade without composer on host; R10 deploy + rollback; R11 restore from backup; R12 outage communication to centers; R13 per-role onboarding guides.

ADDITIONAL OBSERVATIONS NOT LISTED AS FINDINGS: (i) `Home photos/` (1.1 MB personal photos) and `backend/composer.phar` (3.5 MB) are committed — repo hygiene, not continuity. (ii) `تشغيل-المشروع.bat` still runs a non-existent `frontend/` artisan step (already noted in CLAUDE.md). (iii) Demo password drift: CLAUDE.md says `[redacted-demo-password]`, ExtraDataSeeder.php:35 uses `[redacted-demo-password]`, LibyanDataSeeder.php:40-43 uses `[redacted-demo-password]`, frontend README says `password`. (iv) The audited machine has no C:\\xampp\\php\\php.exe and no C:\\mutqin-deploy — consistent with the repo being cloned to a second machine that cannot run the documented workflows, which itself illustrates the bus-factor problem. (v) CORS default is closed when CORS_ALLOWED_ORIGINS is empty (config/cors.php:14-16) — correct, but a Flutter-web build needs its origin added.

DOC DRIFT (CLAUDE.md vs code) relevant to operations: CLAUDE.md:25 '20 feature-test files' (actual 38 + 2 unit); CLAUDE.md:220 'weekly-tests have no update endpoint' (routes/api.php:164 has update, WeeklyTestUpdateTest exists); undocumented: MessageController + messages table + teacher/parent messages pages, AdminUserController + admin/users.html, manager/parents.html + ManagerParentsTest, admin/profile.html, admin/center.html, manager/teacher.html, teacher/student.html, `PUT /manager/students/{id}/teacher`, `/centers/{id}/{stats,teachers,students}`, `/manager/teachers/{id}/performance`, `/manager/reports/{center,teacher,student}`, `/students/{id}/{details,day}`, manifest.webmanifest/PWA, n8n/ digest, .cpanel.yml, _handoff2/ design handoff, ProductionSeeder/LibyanDataSeeder, and the Libyan Spider hosting facts that live only in DEPLOY_LOG.md. Since 223/224 commits are AI-assisted and CLAUDE.md is the primary knowledge base, this drift is an operational risk, not a cosmetic one.

ASSUMPTIONS USED IN THE CAPACITY MODEL (unverifiable read-only): ~50 active students per center (LibyanDataSeeder profile), ~1.5 ms per indexed query on the shared box, PHP max_execution_time 30 s, ~20 LVE entry processes, ~100 ms per notification poll request. The absence of documented host limits is itself the finding; replace these with measured values from Libyan Spider.
