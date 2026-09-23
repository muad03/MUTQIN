# Documentation & Knowledge Management

[← Enterprise Audit](../enterprise-audit.md)

**Score 50 / 100** — Significant risk · maturity **L2** · weight 4% · auditor scored 44, judge calibrated to 50

The repository has one genuinely strong architecture brief (CLAUDE.md, 29.6 KB) plus a good pre-production checklist, an excellent annotated .env.production.example, a release-log template, and unusually rich Arabic rationale comments in code (72% of public methods commented, 18 inline "قرار معتمد" decision markers). That is why it is not <40. But against an enterprise bar (arc42/C4 + ADRs + OpenAPI + runbooks + user manuals) it fails on almost every structural axis: there is no root README, no docs/ directory, zero ADR/OpenAPI/ERD/diagram/CHANGELOG/CONTRIBUTING files, no CI, backend/README.md is untouched Laravel boilerplate, backend/.env.example still says sqlite/en, and two docs (دليل-محتوى-الصفحات.md, frontend-html/README.md) describe a Blade-era three-role product that no longer exists. The single authoritative doc has drifted 60 commits: ~20 of ~107 endpoints (19%) are undocumented, 8 of 29 role pages are missing, and at least 12 statements are now false (test count, demo password, login-email scheme, login-by-code, parent codes, weekly-test update/destroy, frontend/ dir, revisions read, manager teacher toggle). The deploy runbook (DEPLOY_LOG.md) promises an entry per commit but has none for the last 18 commits and is contradicted by the later-added .cpanel.yml auto-deploy. Maturity 2 (repeatable): a documenting habit clearly exists and recurs, but there is no defined process, owner, or check that keeps docs true, so they decay within days.

> **Calibration:** The sole critical (no OpenAPI) was corrected to medium and no high survived, yet it scored 10 points below process-maturity which kept two highs on overlapping doc-drift evidence; a 29.6 KB architecture brief, 72% commented methods and an exemplary .env.production.example support a mid-band 50, not a near-'unfit' 44.

## What is already strong

- CLAUDE.md is a high-signal architecture brief: it explains the load-bearing dual role+token-ability security model (CLAUDE.md:38-55), domain invariants (one primary teacher per center, SurahReference as source of truth, Saturday→Friday week, Africa/Tripoli timezone, activate/deactivate instead of delete) and a full route/controller/model reference — far better than most Laravel repos of this size.
- backend/.env.production.example (4 KB) documents WHY each production value is chosen for a no-SSH shared host, e.g. `LOG_CHANNEL=daily` '# بلا CLI لا سبيل لتفريغ ملف واحد متضخّم' and `QUEUE_CONNECTION=sync` '(المشروع أصلاً لا يستعمل طوابير — فحص موثّق)' — placeholders only, zero secrets.
- backend/DEPLOY_LOG.md defines a real release-note template per commit (ما تغيّر / الملفات / يحتاج رفع؟ / طريقة الرفع / حالة الرفع) and a list of server files that must never be overwritten — a runbook seed most projects lack.
- DEPLOYMENT.md is a concrete Arabic pre-production checklist (APP_DEBUG, key, CORS, SMS gateway integration point `AuthController::sendOtp()`, backups 'بيانات قُصّر — لا تفريط', HTTPS, tests green).
- Code-level documentation is strong for the language it uses: 133/184 public methods (72%) carry a docblock or comment; class headers state business rules and authorization boundaries, e.g. app/Http/Controllers/Api/MessageController.php:12-20 ('ولي الأمر يراسل محفّظ كل ابن من أبنائه فقط … غيره 403 … الأدمن ليس طرفاً'); migrations carry the raw-SQL equivalent for the no-artisan server (2026_09_11_100000_add_parent_code_sequence.php:8-10).
- Architecture decisions are recorded at the point of use: 18 inline 'قرار معتمد' (approved decision) markers across routes/api.php:68,95,99,118,139,162, controllers and migrations — the raw material for an ADR log already exists.
- n8n/README.md is a good integration doc: it states the route used and why (`GET /api/attendance` not `/report`), the gate ('must be admin or teacher'), Docker `host.docker.internal` pitfall, and timezone pinning to Africa/Tripoli.
- Commit history is descriptive: 224 commits with 1,414 non-empty body lines explaining rationale (e.g. 37313bf body: 'الباك بلا تغيير: TeacherMiddleware يبقى يقبل الأدمن عبر API (قرار معتمد)'); 64 use conventional prefixes.
- The 178 feature/unit test methods act as an executable specification of the security matrix, and tests/Concerns/CreatesCoreData.php:9-12 documents that tokens are minted through the real `/api/auth/login` so Sanctum abilities are tested as in production.
- Operational data contracts are documented where the operator sees them: frontend-html/manager/attendance.html:84-87 shows the xlsx header order and explains '«رقم الطالب» = الرقم في جهاز البصمة، وهو الجزء الرقمي من كود الطالب (S121 → 121)'.

## Level-5 target state

A docs/ tree that is the single, versioned source of truth and is partially generated so it cannot drift: docs/architecture (arc42-style context/containers/components with Mermaid C4 diagrams and the dual role+ability model), docs/adr/ (MADR, ~12 backfilled decisions), docs/api/openapi.yaml (generated from routes, checked in CI, published as Redoc and consumed by the Flutter codegen), docs/data/ (ERD + dictionary with authoritative-field flags), docs/ops/ (runbook, releases keyed by SemVer tags, backup/restore, incident), docs/user/ (Arabic manuals per role plus in-app help), and CONTRIBUTING/SECURITY/CHANGELOG at the root. A CI job fails any PR that adds a route without a spec entry or changes a schema without a dictionary update, and the release process produces a changelog automatically. Language policy, glossary, and ownership are explicit; stale material is archived, not silently kept.

## What the Flutter team must know

The mobile team has no contract to build from: no OpenAPI, no response-shape or error-code reference, and the only architecture doc (CLAUDE.md) is wrong on things the app must implement — the login field accepts a display code (T1/CA1/P1) as well as email (AuthController.php:36), generated login emails are `{latin}_{code}@mutqin.ly` not `.centeradmin@`, parents now carry P-codes, weekly tests are updatable but not deletable (DELETE → 405), and the parent↔teacher messaging feature (6 endpoints, `message_received` notifications) is entirely undocumented. Day-1 documents the Flutter team needs before writing code: (1) docs/api/openapi.yaml with the `{success,message,data,errors}` envelope, 401 (token revoked on password change/deactivation — the app must handle forced logout), 403 role/scope semantics, 422 Arabic field errors, and both list shapes (paginator vs `?all=1`); (2) an auth guide: one login endpoint, Sanctum abilities per role (`parent`/`manager`/`*`), 7-day expiry (config/sanctum.php:55 `10080`), which endpoints each role may call (the four middleware aliases), and that admins must not be routed to teacher screens (commit 37313bf); (3) a data dictionary with enum literals in Arabic (`quality`, `status`, `type='محفظ أساسي'`, `result='ناجح'`), date format `Y-m-d`, Africa/Tripoli 'today', Saturday-first week, phone normalization to `09xxxxxxxx`, Western digits only; (4) the display-code semantics (S/T/CA/C/P, never client-generated, `/next-code` is preview only); (5) the API versioning/deprecation policy and CHANGELOG so store-published clients can be kept compatible; (6) the notification types and their deep-link targets (`manager/requests.html`, etc.) so push/in-app routing can be mirrored; (7) the OTP reset flow and the fact that no SMS gateway exists (dev_otp only in local). Without these, the mobile team will reverse-engineer 19 PHP controllers and the drift measured here will recur in Dart.

## Findings — 14 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [No machine-readable API contract (OpenAPI/Postman) for ~107 endpoints; response shapes only discoverable by reading PHP](#no-api-reference-openapi) | 🔴 critical<br>_reviewers → medium_ | ✅ confirmed | NOW | L |
| [CLAUDE.md (the only architecture reference) is 60 commits stale: 20 endpoints, 8 pages, 2 controllers, 1 table missing and ≥12 statements now false](#claude-md-drift-60-commits) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [No root README; backend/README.md is Laravel boilerplate; .env.example and the launcher .bat send a new developer down a broken path](#no-root-readme-onboarding-broken) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [DEPLOY_LOG.md promises an entry per commit but has none for the last 18 commits, 6/8 entries are 'pending', and its 'manual-only deploy' premise is contradicted by .cpanel.yml](#deploy-runbook-abandoned-and-contradicted) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | S |
| [No CHANGELOG, one git tag in 224 commits, no API version or deprecation policy](#no-changelog-versioning-policy) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [backend/.env.example contradicts the project (sqlite, English locale, no CORS origin) while .env.production.example is correct](#env-example-defaults-wrong) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Blade-era and three-role documents are still shipped as if current (page guide, frontend README, 36 screenshots)](#obsolete-docs-not-retired) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Architecture decisions live only as 18 scattered inline 'قرار معتمد' comments; some comments contradict each other](#no-adr-log-inline-decisions-contradict) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [No ERD or data dictionary; schema semantics (vestigial/unreliable/overlapping columns) exist only as prose in CLAUDE.md](#no-erd-data-dictionary) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [No end-user documentation for any of the four roles (manager onboarding, teacher workflows, parent app help, fingerprint-import guide)](#no-user-manuals-per-role) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [No CONTRIBUTING.md, PR template, CODEOWNERS or CI — nothing defines how tests, style and docs are kept green](#no-contributing-ci-docs-gate) | ⚪ low | ℹ️ informational | NEXT | S |
| [Comments explain intent well but carry almost no machine-readable contracts (@param/@return, strict_types, return types)](#phpdoc-contract-tags-absent) | ⚪ low | ℹ️ informational | LATER | M |
| [No stated documentation language policy: English CLAUDE.md, Arabic ops docs, boilerplate English README, mixed commits](#language-strategy-undefined) | ⚪ low | ℹ️ informational | LATER | S |
| [Unexplained artifacts committed at repo/backend root: personal photos, scratch PHP scripts, test xlsx, 3.3 MB design bundle](#repo-hygiene-unexplained-artifacts) | ⚪ low | ℹ️ informational | LATER | S |

### No machine-readable API contract (OpenAPI/Postman) for ~107 endpoints; response shapes only discoverable by reading PHP

<a id="no-api-reference-openapi"></a>

`no-api-reference-openapi` · 🔴 critical (reviewers → medium) · ✅ confirmed · **NOW** · effort L (1–2 weeks)

**Files:** `backend/routes/api.php:16-173`, `backend/composer.json:7-13`, `frontend-html/admin/students.html:40`, `backend/app/Http/Controllers/Api/StudentController.php:132`, `frontend-html/js/api.js:50-74`

**Evidence**

```text
`find . -iname 'openapi*' -o -iname 'swagger*' -o -iname '*.postman*'` → 0 files; composer.json has no scribe/l5-swagger/scramble. Routes: 89 explicit `Route::` lines + 5 apiResource (≈107 endpoints). Undocumented dual list shape: StudentController.php:132 `if ($request->has('all') && $request->all == 1)` returns a bare array, otherwise a paginator, so every page defensively does `res.data.data || res.data || []` (admin/students.html:40). DEPLOYMENT.md:41 itself lists 'توحيد شكل ردود `?all=1` مقابل المرقّمة' as unresolved.
```

**Why it matters**

A Flutter team for four roles cannot generate DTOs, mock servers, or contract tests; they must reverse-engineer 19 controllers (7,072 PHP LOC) and Arabic 422 error keys. Every silent backend change (e.g. weekly-tests DELETE→405, update added) becomes a runtime crash in a store-published app. This is the single largest documentation blocker for mobile.

**Recommendation**

Generate an OpenAPI 3.1 spec from the routes (dedoc/scramble or knuckleswtf/scribe, both Laravel-11 compatible), commit it as docs/api/openapi.yaml, and add a feature test that fails when a route is missing from the spec. Document the envelope `{success,message,data,errors}`, the 401/403/422 semantics, the paginator vs `?all=1` shapes, date formats (Y-m-d, Africa/Tripoli), phone normalization (`09xxxxxxxx`), and Western-digit rule. Ship a Postman/Bruno collection with one login per role.

<details><summary>Verification — 2 independent reviewers</summary>

**Upheld** · confidence 85% · corrected severity: medium

Core facts verified: no openapi/swagger/postman/bruno files anywhere outside vendor; backend/composer.json has no scribe/l5-swagger/scramble; backend/README.md is the stock Laravel boilerplate. routes/api.php has 99 `Route::` lines (auditor said 89) + 5 apiResource. StudentController.php:132 does `if ($request->has('all') && $request->all == 1)` returning a bare collection vs paginator, and the frontend does `res.data.data || res.data || []` (15 occurrences, admin/students.html:40 confirmed). DEPLOYMENT.md:42 lists unifying `?all=1` vs paginated shapes as open. The auditor's drift example is also real: routes/api.php:164 registers weekly-tests `only(['index','store','show','update'])` while CLAUDE.md still documents "index/store/destroy/show" and says there is no update endpoint — so the human docs are already out of sync with the code. However the severity is inflated: (1) the "Flutter team for four roles" is hypothetical — nothing in the repo indicates a mobile client exists or is planned; the only consumer is the first-party static frontend in the same repo, versioned together with the backend, so contract drift is caught in the same commit. (2) CLAUDE.md contains a fairly complete human-readable route table with gates and handlers, plus the envelope, 422/Arabic semantics, phone normalization, and Western-digit rule — much of what the auditor wants documented already exists, just not machine-readable. (3) 38 feature tests exercise the endpoints and do act as a partial contract guard for the security-critical routes. This is a real documentation gap that becomes important only if a second client is built; for a small-team single-client Arabic product it is a medium-level maintainability issue, not critical.

```text
backend/routes/api.php: 99 `Route::` lines (not 89) + 5 apiResource. backend/routes/api.php:164 `Route::apiResource('weekly-tests', ...)->only(['index','store','show','update'])` contradicts CLAUDE.md ("index/store/destroy/show", "no update endpoint") — concrete evidence of doc drift. StudentController.php:132-137 dual shape confirmed; frontend `res.data.data || res.data || []` pattern appears 15 times; DEPLOYMENT.md:42 (not :41) lists it as open. backend/README.md is unmodified Laravel boilerplate. No mobile/Flutter project or plan exists in the repo; the only API consumer is frontend-html/ in the same git repository. Mitigations: CLAUDE.md route/gate table + envelope/422/normalization rules; 38 feature tests in backend/tests/Feature.
```

---

**Upheld** · confidence 85% · corrected severity: medium

Facts confirmed: no openapi/swagger/postman/bruno/http files anywhere outside vendor; backend/composer.json requires only framework/sanctum/tinker/mpdf/phpspreadsheet (no scribe/l5-swagger/scramble); routes/api.php has 99 `Route::` lines plus 5 apiResource; StudentController.php:132 does `if ($request->has('all') && $request->all == 1)` returning a bare collection vs paginator; admin/students.html:40 does `res.data.data || res.data || []` and the pattern appears ~30 times in frontend-html; DEPLOYMENT.md:41 lists unifying `?all=1` vs paginated as an open item; backend/README.md is the stock Laravel boilerplate.

Materiality, however, does not support "critical":
1. The entire impact narrative hinges on "a Flutter team for four roles". There is zero evidence of a mobile/Flutter roadmap anywhere in the repo (grep for flutter/mobile/جوال/android across md/php/html/json returns no real hit). The only API consumer is frontend-html/, co-versioned in the same unified git repository, so "silent backend change becomes a runtime crash in a store-published app" is hypothetical, not an existing enterprise exposure.
2. The auditor's own example of a "silent change" (weekly-tests DELETE→405, update added) was not silent: routes/api.php:162-164 carries an explicit approved-decision comment, and tests/Feature/WeeklyTestUpdateTest.php:83-89 pins DELETE to 405 and :40 pins update semantics. 38 feature-test files (RoleMatrixTest, OwnershipTest, PaginationSearchTest, ParentChildPaginationTest, etc.) act as an executable contract for the security-relevant and shape-relevant behaviour, which is the actual regression guard for the in-repo client.
3. The `{success,message,data,errors}` envelope is uniformly applied: every controller under app/Http/Controllers/Api/ contains 'success' except ReportPdfController (binary PDF output, where an envelope is not applicable). api.js centralises 401 handling. So consumers face one consistent envelope, not 19 divergent shapes.
4. CLAUDE.md (223 lines) already contains a human-readable route table with gates and handlers, plus the envelope, 422/Arabic-message contract, date/timezone, phone normalisation, and Western-digit rules that the auditor asks to be documented.

What keeps the finding alive (not refuted): the documentation that does exist is demonstrably drifting — CLAUDE.md:152 says weekly-tests is index/store/destroy/show and CLAUDE.md:220 says "no update endpoint", both contradicted by routes/api.php:164 (index/store/show/update). The `?all=1` dual shape is a real, acknowledged inconsistency. Those are genuine documentation-debt issues for onboarding and for any future second client, but for a small-team, single-consumer, Arabic-only product with a co-located client and a feature-test suite guarding the contract, this is medium (housekeeping/roadmap-enabler), not a critical blocker.

```text
Confirmed: backend/composer.json:7-13 (no doc generator); backend/routes/api.php (99 Route:: lines + 5 apiResource at :96,:100,:141,:160,:164); backend/app/Http/Controllers/Api/StudentController.php:132-137 (bare get() vs paginate(20)); frontend-html/admin/students.html:40 and ~30 similar `res.data.data || res.data` sites; DEPLOYMENT.md:41 open item. Mitigations: backend/routes/api.php:162-164 explicit approved-decision comment on weekly-tests; backend/tests/Feature/WeeklyTestUpdateTest.php:83-89 asserts DELETE→405, :40 asserts update semantics; 38 files in backend/tests/Feature act as executable contract (RoleMatrixTest, OwnershipTest, PaginationSearchTest, ParentChildPaginationTest); envelope present in every Api controller except ReportPdfController (binary PDF); CLAUDE.md:120-170 route table + envelope/422/timezone/phone/digit rules. Counter-evidence of drift supporting a medium rating: CLAUDE.md:152 and :220 describe weekly-tests as destroy/no-update, contradicting routes/api.php:164. No mobile/Flutter roadmap exists anywhere in the repo (grep across *.md/*.php/*.html/*.json excluding vendor).
```

</details>

### CLAUDE.md (the only architecture reference) is 60 commits stale: 20 endpoints, 8 pages, 2 controllers, 1 table missing and ≥12 statements now false

<a id="claude-md-drift-60-commits"></a>

`claude-md-drift-60-commits` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `CLAUDE.md:25`, `CLAUDE.md:32`, `CLAUDE.md:34`, `CLAUDE.md:68`, `CLAUDE.md:70`, `CLAUDE.md:85`, `CLAUDE.md:128`, `CLAUDE.md:152`, `CLAUDE.md:207-210`, `CLAUDE.md:218`, `CLAUDE.md:220`, `backend/routes/api.php:47-49`, `backend/routes/api.php:59-61`, `backend/routes/api.php:66`, `backend/routes/api.php:69`, `backend/routes/api.php:76-78`, `backend/routes/api.php:103-105`, `backend/routes/api.php:112`, `backend/routes/api.php:143-149`, `backend/routes/api.php:164`, `backend/app/Support/LoginEmail.php:7-9`, `backend/app/Http/Controllers/Api/AuthController.php:36-37`, `backend/database/seeders/LibyanDataSeeder.php:40-43`

**Evidence**

```text
`git log --oneline a07327d..HEAD | wc -l` = 60 (43 touching backend/app, routes or frontend-html). False: CLAUDE.md:25 '20 feature-test files' vs `find backend/tests/Feature -name '*.php' | wc -l` = 38 (+2 Unit, 178 test methods). CLAUDE.md:32 'password `[redacted-demo-password]`' vs LibyanDataSeeder.php:40 `ADMIN_PASSWORD = '[redacted-demo-password]'` (DatabaseSeeder calls LibyanDataSeeder, and says ExtraDataSeeder 'لا يُستدعى … غير منصوح به'). CLAUDE.md:85 'email is the fixed scheme `{latin}.centeradmin@mutqin.ly`' and :68 '`{latin}.{id}@domain`' vs LoginEmail.php:8 '{الاسم اللاتيني}_{الكود بحروف صغيرة}@mutqin.ly — muad_t1 · muad_ca1 · ahmed_p1'. CLAUDE.md:70 '(admin + parent have none)' vs DisplayCode.php:23 `'parent' => 'P'`. Login by code undocumented: AuthController.php:36 `User::whereRaw('UPPER(display_code) = ?'…)`. CLAUDE.md:152 'weekly-tests (index/store/destroy/show)' and :220 'no update endpoint (create/delete only)' vs api.php:164 `only(['index','store','show','update'])`. CLAUDE.md:34 '`frontend/` contains only … routes/web.php' vs `ls frontend` → 'No such file or directory'. CLAUDE.md:218 'StudentController@show reads revisions' vs StudentController.php:352 'أُزيل الجلبُ الميت'. CLAUDE.md:128 handler `searchParents` vs api.php:61 `managerSearchParents`. Missing entirely: MessageController, AdminUserController, Message model, `messages` table, LoginEmail/Percentage support classes, `message_received` notification type, routes /parent/messages*, /teacher/messages*, /admin/users, /manager/parents, /manager/students/{id}/teacher, /manager/teachers/{id}/performance, /manager/teachers/{id}/status, /manager/reports/{center,teacher/{id},student/{id}}, /centers/{id}/{stats,teachers,students}, /students/{id}/{details,day}; pages admin/{center,profile,users}, manager/{parents,teacher}, teacher/{student,messages}, parent/messages; PWA manifest; n8n; .cpanel.yml; DEPLOY_LOG.md; LibyanDataSeeder/ProductionSeeder.
```

**Why it matters**

Anyone (human or agent) onboarding from the documented source of truth will build against wrong login-email formats, miss the login-by-code feature that the mobile login screen must support, believe weekly tests are deletable, and be unaware of messaging — a full feature the mobile app must ship. Drift of this magnitude in 3 days of commits shows there is no docs gate in the workflow.

**Recommendation**

Do a one-shot resync of CLAUDE.md against routes/api.php, the controller list, the pages list and the seeders (this audit's list is the diff). Then add a lightweight gate: a feature test that asserts every route name/path in `Route::getRoutes()` appears in docs/api/openapi.yaml, and a PR checklist item 'docs updated'. Split CLAUDE.md into stable architecture (docs/architecture.md) and generated reference (routes table produced by `php artisan route:list --json` in CI) so the reference can never drift by hand.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 92% · corrected severity: medium

Every concrete claim in the finding verified against the working tree: 60 commits since a07327d; 38 Feature test files (CLAUDE.md says 20); LibyanDataSeeder.php:40-43 sets all demo passwords to '[redacted-demo-password]' and DatabaseSeeder.php:19 calls it (CLAUDE.md says '[redacted-demo-password]'); LoginEmail.php:8 documents the new scheme '{latin}_{code}@mutqin.ly' (CLAUDE.md still says '{latin}.centeradmin@mutqin.ly' / '{latin}.{id}@domain'); DisplayCode.php:23 gives parents a 'P' code (CLAUDE.md says parents have none); AuthController.php:36-37 resolves login by display_code first (undocumented); api.php:164 weekly-tests is only(index,store,show,update) — no destroy, has update (CLAUDE.md says the opposite twice); frontend/ directory does not exist; StudentController.php:352-353 says the revisions fetch was removed; api.php:61 uses managerSearchParents; MessageController, AdminUserController, /parent|teacher/messages*, /admin/users routes and 8 new pages (admin/center,profile,users; manager/parents,teacher; teacher/student,messages; parent/messages) all exist and are absent from CLAUDE.md. No docs/ directory or openapi spec exists as an alternative reference. The finding is factually correct and not mitigated. However, 'high' overstates the risk for this product: it is a documentation-drift issue in a small-team, single-repo project with no runtime or security impact; the 38 feature tests remain the actual guard on behaviour, and much of the drift is 3 days old (CLAUDE.md last touched 2026-09-08, HEAD 2026-09-11). Real onboarding-misdirection cost (wrong password, wrong email scheme, weekly-test destroy vs update, missing messaging) justifies medium, not high. The recommendation of a CI route-vs-openapi gate is disproportionate for a project that has no openapi.yaml at all.

```text
backend/database/seeders/LibyanDataSeeder.php:40-43 (ADMIN/MANAGER/TEACHER/PARENT_PASSWORD = '[redacted-demo-password]'); backend/database/seeders/DatabaseSeeder.php:19 ($this->call(LibyanDataSeeder::class)); backend/app/Support/LoginEmail.php:8 ('{latin}_{code}@mutqin.ly — muad_t1 · muad_ca1 · ahmed_p1'); backend/app/Support/DisplayCode.php:23 ('parent' => 'P'); backend/app/Http/Controllers/Api/AuthController.php:36-37 (login by UPPER(display_code) then email); backend/routes/api.php:47-49,147-149 (MessageController routes), :61 (managerSearchParents), :112 (/admin/users AdminUserController), :164 (weekly-tests only index,store,show,update); backend/app/Http/Controllers/Api/StudentController.php:352-353 (revisions fetch removed); `find backend/tests/Feature -name '*.php' | wc -l` = 38; `ls frontend` → No such file or directory; no docs/ directory or openapi.yaml exists in the repo; CLAUDE.md last modified 2026-09-08, HEAD 2026-09-11.
```

</details>

### No root README; backend/README.md is Laravel boilerplate; .env.example and the launcher .bat send a new developer down a broken path

<a id="no-root-readme-onboarding-broken"></a>

`no-root-readme-onboarding-broken` · 🟠 high (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/README.md:1-58`, `backend/.env.example:1-25`, `backend/phpunit.xml:20-21`, `CLAUDE.md:25`, `تشغيل-المشروع.bat:22-25`

**Evidence**

```text
`ls` at repo root: no README.md — only CLAUDE.md (agent-oriented). backend/README.md:1 '<p align="center"><a href="https://laravel.com"…Laravel Logo' … 'If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell'. backend/.env.example: `APP_NAME=Laravel`, `APP_LOCALE=en`, `DB_CONNECTION=sqlite`, no `CORS_ALLOWED_ORIGINS` — while CLAUDE.md:25 says 'sqlite won't work: raw MySQL ALTER in one migration' and config/cors.php:14-16 reads `CORS_ALLOWED_ORIGINS` with a closed default. تشغيل-المشروع.bat:25 `cd /d C:\xampp\htdocs\MUTQENQ\frontend && php artisan serve --port=9091` targets a directory that no longer exists (`ls frontend` → not found).
```

**Why it matters**

Time-to-first-request for a new backend or mobile engineer is measured in hours of trial and error (copy .env.example → sqlite → migration fails → CORS blocks the client). Scaling to dozens of centers means onboarding contractors; a boilerplate README and a broken quickstart are the first thing an investor's technical due diligence sees.

**Recommendation**

Write a root README.md (English, with an Arabic section) covering: what MUTQEN is, repo layout, 10-minute quickstart (MySQL DB names mutqin_db/mutqin_test, `composer.phar install`, `php artisan migrate:fresh --seed`, `php -S localhost:8080` for frontend), demo accounts pointing to LibyanDataSeeder constants, how to run tests, links to docs/. Replace backend/README.md with a pointer. Fix backend/.env.example to mysql/ar/Africa/Tripoli/CORS_ALLOWED_ORIGINS=http://localhost:8080 and mirror phpunit.xml. Delete or fix the .bat.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The quoted evidence is all accurate: repo root has no README.md (only CLAUDE.md, DEPLOYMENT.md, دليل-محتوى-الصفحات.md, the .bat); backend/README.md is untouched Laravel boilerplate (Taylor Otwell line at :54); backend/.env.example is the stock Laravel template (APP_NAME=Laravel, APP_LOCALE=en, DB_CONNECTION=sqlite, no CORS_ALLOWED_ORIGINS); phpunit.xml forces mysql/mutqin_test; the .bat step 3 targets a non-existent `frontend/` dir with `php artisan serve --port=9091` (and hard-codes C:\xampp\htdocs\MUTQENQ while the repo lives at C:\Users\HP\SRS\MUTQIN, so step 2 is broken too). However the finding is materially overstated and ignores existing mitigations: (1) config/cors.php's closed default falls back to `['http://localhost:8080']`, exactly the port CLAUDE.md/DEPLOYMENT.md suggest for the static client, so a missing CORS_ALLOWED_ORIGINS does NOT block the local client — the "CORS blocks the client" step in the impact chain is false. (2) CLAUDE.md, although agent-oriented, is a 223-line human-readable onboarding doc with the exact quickstart commands, PHP path, composer.phar usage, DB names, demo accounts, port, extensions, and it explicitly warns about sqlite (line 25) and about the stale .bat. (3) DEPLOYMENT.md (Arabic, 43 lines) covers local dev + production checklist, and backend/.env.production.example is a fully commented, correct template (mysql, ar, CORS_ALLOWED_ORIGINS) — only the dev .env.example is stale. (4) The sqlite mistake fails fast and loudly at `migrate` with a clear MySQL-syntax error, not after hours. Residual real issues: stale dev .env.example, boilerplate backend/README.md, a broken launcher, no English/root README, and DEPLOYMENT.md item 3 is itself stale (tells the reader to replace `allowed_origins => ['*']`, which no longer exists — the env-driven mechanism replaced it). For an Arabic-only single-country small-team product these are hygiene items, not a high-severity blocker.

```text
backend/config/cors.php:14-16 — `explode(',', env('CORS_ALLOWED_ORIGINS',''))... ?: ['http://localhost:8080']` → local client on the documented port is NOT blocked when the var is absent. CLAUDE.md:15-33 — full quickstart (php path, composer.phar, migrate:fresh --seed, test DB, php -S localhost:8080), :25 sqlite warning, :31 explicit note that the .bat web step is stale. DEPLOYMENT.md:1-43 — Arabic local-dev + production checklist (but item 3 at line 11 is stale: references `allowed_origins => ['*']` which cors.php no longer contains). backend/.env.production.example — correct, heavily commented template (DB_CONNECTION=mysql, APP_LOCALE=ar, CORS_ALLOWED_ORIGINS). Confirmed stale: backend/.env.example (DB_CONNECTION=sqlite, APP_NAME=Laravel, APP_LOCALE=en), backend/README.md:1-58 boilerplate, تشغيل-المشروع.bat:22-25 hard-coded C:\xampp\htdocs\MUTQENQ\{backend,frontend} paths (frontend/ absent; repo actually at C:\Users\HP\SRS\MUTQIN).
```

</details>

### DEPLOY_LOG.md promises an entry per commit but has none for the last 18 commits, 6/8 entries are 'pending', and its 'manual-only deploy' premise is contradicted by .cpanel.yml

<a id="deploy-runbook-abandoned-and-contradicted"></a>

`deploy-runbook-abandoned-and-contradicted` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/DEPLOY_LOG.md:6-9`, `backend/DEPLOY_LOG.md:20`, `backend/DEPLOY_LOG.md:39`, `.cpanel.yml:1-7`, `DEPLOYMENT.md:9-13`

**Evidence**

```text
DEPLOY_LOG.md:8 'النشر يدوي حصراً عبر cPanel File Manager … لا شيء يصل الخادم من تلقاء نفسه' and :20 'كل commit جديد = مدخل هنا'. Last entry is e7c50f9 (2026-09-11); `git log --oneline e7c50f9..HEAD | wc -l` = 18 with no entries (incl. 6b6dd22 'بريد الدخول المولَّد يتضمن كود العرض' — a login-identity change that needs server rollout). `grep -c 'حالة الرفع:\*\* pending'` → 6 of 8 entries pending. .cpanel.yml (added 9831f1c 2026-09-11 'نشر تلقائي من Git Version Control في cPanel') rsyncs frontend-html/ and backend/ automatically — the opposite deployment model, never mentioned in DEPLOY_LOG.md or DEPLOYMENT.md. DEPLOYMENT.md:9 still instructs 'استبدل `allowed_origins => ['*']`' (already env-driven), :11 'حدّث `frontend-html/js/config.js` (API_BASE_URL)' (now runtime-detected), :17 'كلمات مرور … `password`', and numbers two rows '7'.
```

**Why it matters**

There is no reliable answer to 'what is running in production and how do I roll back?'. With migrations applied as hand-pasted SQL and no artisan on the host, an out-of-date log is how a schema drift or a half-deployed identity change (login emails) becomes a production outage the day the mobile app is in the store.

**Recommendation**

Replace the per-commit log with a docs/ops/ folder: RUNBOOK.md (deploy via .cpanel.yml, what it excludes, how to apply SQL migrations on the host, rollback, backup/restore, log locations), RELEASES.md keyed by git tag (not per commit), and an incident/on-call one-pager. Decide and document one deployment model; delete the contradictory prose. Refresh DEPLOYMENT.md items 3, 5, 6, 8 and fix numbering.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual core holds. Verified: `git log --oneline e7c50f9..HEAD | wc -l` = 18 (one of them, 788ee03, is itself the log entry for e7c50f9, so 17 substantive commits without an entry, incl. 6b6dd22 login-email scheme change). DEPLOY_LOG.md:8-9 says deployment is manual-only and nothing reaches the server automatically; :21 says every commit gets an entry. `.cpanel.yml` (9831f1c, 2026-09-11, committed BEFORE the last log entry e7c50f9) rsyncs frontend-html/ and backend/ on cPanel git pull — a contradictory model that neither DEPLOY_LOG.md nor DEPLOYMENT.md mentions (grep for cpanel in *.md hits only the File-Manager prose). DEPLOYMENT.md:11 still says replace `allowed_origins => ['*']` although cors.php:14 is env-driven; :13 says edit API_BASE_URL although config.js:10 detects hostname at runtime; rows 15 and 16 both numbered 7. Minor corrections: pending count is 7 of 8 entries (not 6/8); note DEPLOY_LOG.md:23 explicitly makes 'pending' an owner-only flag, so pending ≠ proven undeployed. Mitigations that lower severity: no migrations, config, or .env.production.example changes exist in e7c50f9..HEAD (git diff --stat is empty), so the concrete 'schema drift' path the auditor describes has not materialised for these 18 commits; .cpanel.yml excludes .env/storage/vendor/bootstrap/cache consistent with the log's do-not-overwrite list, so the two models don't actively destroy each other; and the 'mobile app in the store' impact is speculative — nothing in the repo indicates a mobile app. This is a documentation/ops-process drift issue with no code defect, in a small-team single-host project. Real, but overrated as high.

```text
backend/DEPLOY_LOG.md:8-9 (manual-only claim), :21 (entry per commit), :23 (pending is owner-set flag); 7 of 8 entries pending (lines 46,55,64,72,83,92,100), only line 37 done. Last entry heading line 39 (e7c50f9); `git log e7c50f9..HEAD` = 18 commits, no entries. .cpanel.yml:5-6 rsync auto-deploy, commit 9831f1c dated 2026-09-11 10:53 — predates last log entry yet log unchanged. DEPLOYMENT.md:11 stale (cors.php:14 env-driven), :13 stale (config.js:10 runtime hostname detection), :15-16 duplicate row '7'. Mitigation: `git diff --stat e7c50f9..HEAD -- backend/database/migrations backend/config backend/.env.production.example` is empty — no schema/config changes among the unlogged commits.
```

</details>

### No CHANGELOG, one git tag in 224 commits, no API version or deprecation policy

<a id="no-changelog-versioning-policy"></a>

`no-changelog-versioning-policy` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/routes/api.php:26`, `backend/DEPLOY_LOG.md:20`, `frontend-html/js/config.js:10-12`

**Evidence**

```text
`git tag | wc -l` = 1 (`requests-restructure-2026-09-08`). `find . -iname 'CHANGELOG*'` → none. API is mounted unversioned (`Route::middleware('auth:sanctum')->group(...)` under /api with no /v1). 64/224 commits (29%) use a conventional prefix, so a changelog cannot be generated mechanically. Breaking changes already shipped silently: 6b6dd22 changed every generated login email; api.php:162 turned DELETE /weekly-tests into 405.
```

**Why it matters**

Once a Flutter app is in the stores, users run old clients for weeks; without versioned releases, a changelog and a stated deprecation window, every backend change is a potential fleet-wide break with no way to communicate 'update required'.

**Recommendation**

Adopt SemVer tags (v1.0.0 at mobile launch), a Keep-a-Changelog CHANGELOG.md with an 'API' section, enforce Conventional Commits (commitlint) so release notes are generated, and document an API compatibility policy (additive only within v1; /api/v2 for breaking; minimum-client-version header). Write this policy now so the mobile team can design update prompts.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 78% · corrected severity: low

Raw evidence verified: `git tag` = 1 (`requests-restructure-2026-09-08`), 224 commits, no CHANGELOG* outside vendor, 64/224 commits carry a conventional prefix, and api.php:26 mounts the protected group under /api with no version segment. So the absence of a CHANGELOG/SemVer/API-version policy is factually correct and not refuted. However the "breaking changes already shipped silently" evidence and the impact are overstated. (1) 6b6dd22's own commit message and `LoginEmail.php` docblock state "الحسابات القائمة لا تُرحَّل" — the new `{latin}_{code}@mutqin.ly` scheme applies only to accounts created afterwards; no existing user's login email changed, so no client broke. (2) The weekly-tests 405 is a deliberate, commented decision (api.php:162-164 "قرار معتمد — DELETE يعيد 405"), and it shipped together with the frontend that consumes it. (3) There is no Flutter/mobile client anywhere (0 commits mention flutter; frontend-html is the only consumer), and `config.js:10-12` resolves the API as a same-origin relative path `/backend/public/api` — frontend and backend are deployed as one bundle to the same cPanel host, so there is no "old client fleet" today; the impact scenario is entirely hypothetical about a future app. (4) `backend/DEPLOY_LOG.md` (8 entries, "كل commit جديد = مدخل هنا") already functions as a per-release change/deploy log with file lists and migration SQL, partially mitigating the missing CHANGELOG. Net: a real but forward-looking process gap for a single-team, single-client, same-origin deployment; not medium today.

```text
backend/app/Support/LoginEmail.php:13 and commit 6b6dd22 message: "الحسابات القائمة لا تُرحَّل" — email scheme change applies to newly created accounts only, not a break for existing users. backend/routes/api.php:162-164: DELETE /weekly-tests removal is an explicitly commented approved decision shipped with the matching frontend. frontend-html/js/config.js:10-12: production API is same-origin relative `/backend/public/api` — sole client is co-deployed with the backend, no independently-versioned clients exist (no Flutter/mobile code in repo). backend/DEPLOY_LOG.md:1-40: per-commit deployment log with changed files, upload status and raw SQL for migrations already serves as a partial changelog (8 entries).
```

</details>

### backend/.env.example contradicts the project (sqlite, English locale, no CORS origin) while .env.production.example is correct

<a id="env-example-defaults-wrong"></a>

`env-example-defaults-wrong` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/.env.example:1-18`, `backend/.env.production.example:20-27`, `backend/config/cors.php:14-16`, `backend/phpunit.xml:20-21`, `CLAUDE.md:25`

**Evidence**

```text
.env.example: `APP_NAME=Laravel`, `APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en`, `DB_CONNECTION=sqlite`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, and no `CORS_ALLOWED_ORIGINS`. cors.php:16 `explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))` → with the example file the browser client on :8080 is blocked. CLAUDE.md:25: 'sqlite won't work: raw MySQL ALTER in one migration'.
```

**Why it matters**

First-run failure for every new developer and for the mobile team standing up a local API; also risks a dev accidentally running with `APP_LOCALE=en` and seeing English validation messages that never appear in production.

**Recommendation**

Rewrite .env.example as the local twin of .env.production.example (mysql mutqin_db, `APP_LOCALE=ar`, `CORS_ALLOWED_ORIGINS=http://localhost:8080,http://127.0.0.1:8080`, `PHP_CLI_SERVER_WORKERS=4`, `APP_NAME=مُتقن`) with the same rationale comments, and reference it from the README quickstart.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 82% · corrected severity: low

Two of the three headline behavioural claims are factually wrong; only the sqlite default survives, and it is a minor housekeeping issue rather than a medium-severity first-run failure.

1. CORS claim is false. The auditor quoted cors.php:16 but stopped before line 17: `explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))` is wrapped in `array_values(array_filter(...)) ?: ['http://localhost:8080']`. With the variable absent or empty, the filtered array is empty and the `?:` fallback yields exactly `['http://localhost:8080']` — the documented dev origin. The comment block at cors.php:10-13 states this explicitly ("الافتراضي «مغلق» — غياب المتغيّر أو فراغه يعيد ['http://localhost:8080']"). So a developer using .env.example with the client on :8080 is NOT blocked. (127.0.0.1:8080 would be blocked, but that is not what the auditor claimed and matches the documented default.)

2. Locale claim is inert. The backend has no `lang/` or `resources/lang/` directory at all (find over the repo excluding vendor returns nothing, no `setLocale` calls in app/). Therefore `APP_LOCALE=en` vs `ar` changes nothing for validation output: every rule that lacks a custom message falls back to Laravel's embedded English strings under either setting, and the Arabic messages the project shows come from hardcoded per-call custom-message arrays in controllers (e.g. CenterController.php:38-40, AuthController.php:124). The two Carbon usages set `->locale('ar')` explicitly (NotificationController.php:30, ReportPdfController.php:31). "English validation messages that never appear in production" is not a real divergence — production (`APP_LOCALE=ar`, no lang/ar) produces the identical English fallback for rules without custom messages.

3. sqlite claim is real but small. backend/.env.example is the stock Laravel 11 skeleton (`APP_NAME=Laravel`, `DB_CONNECTION=sqlite`). Migration 2026_06_28_120000_add_nationality_to_students_and_users.php:23 runs `DB::statement('ALTER TABLE students MODIFY national_id VARCHAR(32) NULL')` with no driver guard, so `artisan migrate` on sqlite would fail partway. However CLAUDE.md (the onboarding doc for this repo) states MySQL `mutqin_db` on line 25 and gives the exact commands; phpunit.xml pins mysql/mutqin_test; no README quickstart tells anyone to copy .env.example (grep for env.example/cp .env across README/DEPLOYMENT/CLAUDE.md returns nothing). Small team, single documented setup — this is a stale template file, not a systemic first-run failure. SESSION_DRIVER/CACHE_STORE=database are harmless (Laravel 11 ships those migrations).

Side observation worth more than the original finding: DEPLOYMENT.md:11 is stale — it tells the deployer to replace `allowed_origins => ['*']` in cors.php, but cors.php no longer contains `'*'`; the correct action is setting `CORS_ALLOWED_ORIGINS` (as .env.production.example:74-77 already says).

Net: keep as a low-severity documentation nit (refresh .env.example to mysql/mutqin_db + APP_NAME, drop the false CORS/locale impact text, fix DEPLOYMENT.md:11).

```text
backend/config/cors.php:14-17 — `array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))) ?: ['http://localhost:8080']` → empty/missing env allows localhost:8080 (auditor omitted line 17). backend/ has no lang/ or resources/lang/ directory and no setLocale call in app/ → APP_LOCALE has no effect on validation text; Arabic messages are hardcoded per call (e.g. backend/app/Http/Controllers/Api/CenterController.php:38-40; AuthController.php:124). Real residue: backend/.env.example:1,23 (`APP_NAME=Laravel`, `DB_CONNECTION=sqlite`) vs backend/database/migrations/2026_06_28_120000_add_nationality_to_students_and_users.php:23 raw `ALTER TABLE students MODIFY ...` (MySQL-only). Stale doc: DEPLOYMENT.md:11 references `allowed_origins => ['*']` which no longer exists in cors.php.
```

</details>

### Blade-era and three-role documents are still shipped as if current (page guide, frontend README, 36 screenshots)

<a id="obsolete-docs-not-retired"></a>

`obsolete-docs-not-retired` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `دليل-محتوى-الصفحات.md:5`, `دليل-محتوى-الصفحات.md:11`, `دليل-محتوى-الصفحات.md:37-40`, `frontend-html/README.md:21-27`, `frontend-html/README.md:18`, `screenshots/index.html`, `_handoff2/untitled/README.md:1-5`

**Evidence**

```text
دليل-محتوى-الصفحات.md:5 'كل صفحات لوحة التحكم (٢٧ صفحة) تشترك في قالب واحد `layouts/app.blade.php`', :11 'معرّفة في `public/css/mutqin.css`', :37 role table has only المدير | المعلم | ولي الأمر (no center manager) — `git log -- 'دليل-محتوى-الصفحات.md'` shows it untouched since be47c33 2026-06-25 (223 commits ago). frontend-html/README.md:21 'حسابات تجريبية (كلمة المرور: `password`)' with `teacher1@mutqin.ly`, `parent1@mutqin.ly`; :18 'الـ API يسمح بكل المصادر (`allowed_origins: ['*']`)' vs config/cors.php env-driven closed default; its structure block lists no manager/, no forgot-password.html, no js/pages/. screenshots/ = 36 PNG, 4.9 MB, dated 2026-06-25, no manager screens, referenced nowhere (`grep -rl 'screenshots/'` → none). _handoff2/untitled/README.md:1 'CODING AGENTS: READ THIS FIRST … Read `نظام التصميم - مُتقن.dc.html` in full' — a design handoff whose status (implemented? partially?) is recorded nowhere.
```

**Why it matters**

Three of the eight markdown files actively mislead: a designer or mobile engineer picking up the 20 KB page guide would model a product without the center-manager role, messaging, requests, or PWA. Stale artifacts erode trust in the true docs and inflate the repo (9.3 MB of unreferenced images/design bundles).

**Recommendation**

Move دليل-محتوى-الصفحات.md, screenshots/ and _handoff2/ under docs/archive/ with a one-line ARCHIVED banner and date, or delete them (git keeps history). Rewrite frontend-html/README.md to the real 4-role structure, the runtime API_BASE_URL rule in js/config.js, and point to the seeder for accounts. Record the outcome of the _handoff2 design handoff (what was adopted into css/theme.css) in docs/design/README.md.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Every quoted piece of evidence checks out. دليل-محتوى-الصفحات.md:5 says all 27 dashboard pages share `layouts/app.blade.php`, :11 cites `public/css/mutqin.css`, :37-40 describe a three-role sidebar (مدير النظام / معلم / ولي أمر) — none of which exists in frontend-html/ (no Blade, css is css/theme.css, four roles incl. manager/). `git log` confirms the file has a single commit (be47c33, Initial commit) with 223 commits since. frontend-html/README.md:18 claims `allowed_origins: ['*']` while backend/config/cors.php:14-17 is env-driven with a deliberately closed default (`['http://localhost:8080']`, comment explicitly says it never returns '*'); README:21-27 lists password `password` and `teacher1@`/`parent1@mutqin.ly`, but the seeders use `[redacted-demo-password]` and the `{name}@mutqin.ly` / `.centeradmin@` scheme (ExtraDataSeeder.php:35, LibyanDataSeeder.php); README structure block omits manager/, forgot-password.html, manifest.webmanifest and js/pages/ which all exist on disk. screenshots/ has 36 PNGs (4.9 MB) with no manager screens and is referenced from no md/html/js file; _handoff2/ (3.3 MB, 50 git-tracked files with screenshots) has a Claude Design handoff README whose adoption status is recorded nowhere. No mitigation exists (no ARCHIVED banner, no docs/ index, .gitignore does not exclude them). So the finding is factually correct. However, the impact is somewhat overstated: the authoritative onboarding document, CLAUDE.md, is accurate and current (4 roles, static client, config.js, seeder accounts), DEPLOYMENT.md covers CORS correctly, and the stale README/guide have zero runtime effect — the wrong demo credentials fail visibly at login and the wrong CORS claim fails visibly as a CORS error rather than silently opening anything. For a small Arabic-only team this is documentation hygiene rather than a medium operational risk; I would rate it low. The one item worth fixing first is README.md:18, since it misdescribes a security-relevant config in the opposite direction of the actual hardened default.

```text
دليل-محتوى-الصفحات.md:5 (`layouts/app.blade.php`), :11 (`public/css/mutqin.css`), :36-40 three-role sidebar; last touched be47c33 (Initial commit), 223 commits behind HEAD. frontend-html/README.md:18 `allowed_origins: ['*']` contradicts backend/config/cors.php:10-17 (env CORS_ALLOWED_ORIGINS, closed default ['http://localhost:8080'], comment states '*' is never returned). README.md:21-27 password `password`, `teacher1@mutqin.ly`, `parent1@mutqin.ly` vs backend/database/seeders/ExtraDataSeeder.php:35 `Hash::make('[redacted-demo-password]')` and LibyanDataSeeder.php ADMIN_PASSWORD/MANAGER_PASSWORD constants. README.md:30-40 structure block lacks manager/, forgot-password.html, manifest.webmanifest, js/pages/ (all present: `ls frontend-html`). screenshots/: 36 files, 4.9 MB, `grep -rl 'screenshots/'` over md/html/js outside the folder → no hits. _handoff2/: 3.3 MB, 50 tracked files (`git ls-files screenshots _handoff2 | wc -l` = 50 total), README.md:1 'CODING AGENTS: READ THIS FIRST' with no adoption record. Mitigation: CLAUDE.md (root) is accurate and is the doc actually read by agents/devs; DEPLOYMENT.md documents CORS correctly.
```

</details>

### Architecture decisions live only as 18 scattered inline 'قرار معتمد' comments; some comments contradict each other

<a id="no-adr-log-inline-decisions-contradict"></a>

`no-adr-log-inline-decisions-contradict` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/routes/api.php:83-85`, `backend/routes/api.php:151`, `backend/routes/api.php:17`, `backend/routes/api.php:95`, `backend/routes/api.php:139`, `backend/app/Http/Controllers/Api/CenterController.php:244`, `backend/database/migrations/2026_07_18_120000_add_correction_audit_to_attendances.php:11`

**Evidence**

```text
`find . -type d -iname adr -o -iname docs -o -iname decisions` → none. `grep -rI 'قرار معتمد' backend | wc -l` = 18. Contradictions inside routes/api.php: line 83 '// طلبات النقل الداخلية لمركزه (from=target=مركزه) — العابرة تبقى لمدير النظام' vs lines 84-85 'مدير المركز هو مرجعها الوحيد (مدير النظام ليس طرفاً)'; line 151 '// طلبات الطلاب — إنشاء/متابعة من المحفّظ … ينتظر موافقة الأدمن' describes a removed flow (no teacher request routes follow). Line 17 'دخول موحّد للأدوار الثلاثة' (three roles) while four roles exist.
```

**Why it matters**

Key irreversible decisions (no hard delete anywhere, admin is not a party to requests, one primary teacher per center, tokens revoked on password change, no soft-deletes, Saturday week) have no context/alternatives/consequences recorded; new engineers or a mobile team will re-litigate or silently violate them, and stale comments actively mislead.

**Recommendation**

Create docs/adr/ (MADR template, Arabic body + English title) and backfill ~12 ADRs from the existing markers: 0001 dual role+ability auth, 0002 deactivate-instead-of-delete, 0003 manager-only request authority, 0004 display codes & generated login emails, 0005 SurahReference over stored juz, 0006 Sat–Fri week & Africa/Tripoli, 0007 no-SSH shared-host deployment, 0008 messaging scope, etc. Delete the stale comments at api.php:83 and :151 and fix :17.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The factual core holds: there is no docs/ or ADR directory; the cited stale/contradictory comments exist verbatim (api.php:83 says cross-center transfers "stay with the system admin" while :84-85 say the admin is not a party; :151 describes a removed teacher-request flow awaiting admin approval with no routes under it; :17 says "three roles" while four exist). Marker count is 16, not 18, and CenterController:244 / the attendance migration are consistent decision notes, not contradictions. The impact is overstated, however: the repo already has a substantial de-facto architecture record in CLAUDE.md (223 lines) that documents the dual role+ability auth, deactivate-instead-of-delete (explicitly labelled an approved decision), manager-only request authority, display codes, SurahReference over stored juz, Sat–Fri week, Africa/Tripoli timezone, token revocation, plus DEPLOYMENT.md for hosting. What is missing is the alternatives/consequences rationale and a place that supersedes inline comments; the concrete harm is limited to three misleading comments in routes/api.php. This is a low-severity documentation-hygiene issue for a small single-country team, not medium.

```text
backend/routes/api.php:17 ('الأدوار الثلاثة' — four roles exist: admin, center_manager, teacher, parent); backend/routes/api.php:83 vs :84-85 (stale "العابرة تبقى لمدير النظام" directly contradicted by the next line); backend/routes/api.php:151 (orphan comment about teacher requests awaiting admin approval, no routes follow). `grep -rIn 'قرار معتمد' backend | wc -l` = 16 (not 18). Mitigation: CLAUDE.md lines ~40-100 already record the auth model, deactivate-not-delete ("approved decision", line 71), manager-only request authority, display codes, SurahReference rule, Sat–Fri week and Africa/Tripoli timezone; DEPLOYMENT.md covers hosting. No docs/ or ADR directory exists.
```

</details>

### No ERD or data dictionary; schema semantics (vestigial/unreliable/overlapping columns) exist only as prose in CLAUDE.md

<a id="no-erd-data-dictionary"></a>

`no-erd-data-dictionary` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `CLAUDE.md:191-200`, `CLAUDE.md:74-76`, `backend/database/migrations/2026_08_22_100000_create_messages_table.php:14-26`, `backend/app/Models/Student.php`

**Evidence**

```text
`find . -iname '*.puml' -o -iname '*.mmd' -o -iname '*.drawio'` → 0. 35 migrations create 24 tables (`grep -rhoE "Schema::create\('(\w+)'" | sort -u | wc -l` = 24); CLAUDE.md:191 lists 23 (missing `messages`). Semantics that only CLAUDE.md prose carries: :76 '`memorizations.juz` is unreliable hand-entered data … Do not trust the stored `juz` column'; :196 '`birth_date` … largely vestigial — `age` is the field actually used'; :197 'weekly_tests … carries overlapping `result` + `passed` + `test_type` columns — historical cruft'; :68 '`guardian_name`/`guardian_phone` are display-only; the real guardian link is `parent_id`'.
```

**Why it matters**

A mobile team mapping JSON to models has no field-level reference and will bind to `juz`, `birth_date` or `guardian_phone` believing them authoritative. Multi-center reporting and any future data-warehouse or Awqaf export need a maintained dictionary of ~24 tables with nullability, enums (`status`, `quality`, `type`, `role`) and Arabic enum values.

**Recommendation**

Add docs/data/erd.mmd (Mermaid erDiagram is rendered natively by GitHub) generated from the schema, plus docs/data/dictionary.md with one table per entity: column, type, nullable, enum values (Arabic literals), 'authoritative?' flag, and source-of-truth note. Mark vestigial columns explicitly and open tickets to drop them.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Evidence verified as stated: no .puml/.mmd/.drawio (or any erd/dictionary/schema doc) exists outside vendor; 35 migrations create exactly 24 tables and CLAUDE.md:191 lists 23, omitting `messages` (backend/database/migrations/2026_08_22_100000_create_messages_table.php) — and the whole parent<->teacher messaging subsystem (Message model, MessageController, /parent/messages + /teacher/messages routes at routes/api.php:46-49,147-149) is absent from CLAUDE.md, which strengthens the "prose is the only doc and it is already drifting" point. The semantic caveats are real and only in prose/comments: `memorizations.juz` remains in Memorization::$fillable and is returned to clients while MemorizationController:29-46 deliberately ignores it (comments confirm "غير موثوق"); Student keeps `birth_date` fillable+cast (Student.php:12,31) alongside `age`; `guardian_phone` is fillable and displayed while `parent_id` is the real link. So an API consumer does receive columns the backend itself treats as non-authoritative, with no machine-readable reference. Mitigations that lower severity: CLAUDE.md is unusually thorough, migrations and controllers carry inline Arabic comments explaining the caveats, there is a single first-party client (frontend-html) that already follows the correct fields, no mobile team/export/warehouse exists, and this has zero runtime/security impact. The "impact" section is speculative. Real but over-rated: a documentation-hygiene gap for a small single-client team, not a medium risk.

```text
CLAUDE.md:191 lists 23 tables; `grep -rhoE "Schema::create\('(\w+)'" backend/database/migrations | sort -u | wc -l` = 24 (messages missing). Messaging subsystem entirely undocumented in CLAUDE.md: backend/app/Models/Message.php:11; backend/routes/api.php:46-49 and 147-149 (MessageController threads/thread/send). backend/app/Models/Memorization.php:14 keeps `juz` fillable while backend/app/Http/Controllers/Api/MemorizationController.php:29-31,36-46 derives juz from surah_name and comments that the stored column is untrusted. backend/app/Models/Student.php:12,31 keeps `birth_date` fillable+date cast; :19 `guardian_phone` fillable. No docs/ directory; only CLAUDE.md, DEPLOYMENT.md, backend/DEPLOY_LOG.md, دليل-محتوى-الصفحات.md exist.
```

</details>

### No end-user documentation for any of the four roles (manager onboarding, teacher workflows, parent app help, fingerprint-import guide)

<a id="no-user-manuals-per-role"></a>

`no-user-manuals-per-role` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/manager/attendance.html:84-87`, `frontend-html/login.html`, `DEPLOYMENT.md:8-9`

**Evidence**

```text
No user-guide, help, FAQ or training material exists anywhere in the tree (`git ls-files | grep -iE 'md|txt'` yields only the 8 dev/ops files listed). The only operator guidance is inline UI text such as attendance.html:87 '«رقم الطالب» = الرقم في جهاز البصمة…'. DEPLOYMENT.md item 4 notes password self-service depends on an SMS gateway that does not exist — a support process that is not written down.
```

**Why it matters**

Scaling to dozens of centers means dozens of center managers who must learn transfer requests, attendance correction audit, one-primary-teacher rule, and deactivation semantics without a manual; support load lands on the developer. A public parent app needs in-app help and a privacy/terms page (children's data) before store review.

**Recommendation**

Write docs/user/ in Arabic: manager-guide.md (day-1 checklist, add teacher/student, fingerprint xlsx format & S-code mapping, review/correct attendance, transfer requests), teacher-guide.md, parent-guide.md, admin-guide.md; plus a support runbook (password reset without SMS, deactivated account messages). Publish as a static help section inside frontend-html/ and reuse in the mobile app.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The factual core holds. `git ls-files` for md/txt/pdf/docx returns only CLAUDE.md, DEPLOYMENT.md, backend/README.md, backend/DEPLOY_LOG.md, frontend-html/README.md, n8n/README.md, _handoff2/untitled/README.md and robots.txt — all developer/ops material (frontend-html/README.md is setup/structure, and its demo-account table is even stale: password `password` vs the seeded `[redacted-demo-password]`). No help/guide/faq/privacy/terms file exists anywhere in frontend-html/ (grep for help/دليل/مساعدة/الأسئلة الشائعة/سياسة الخصوصية only hits inline UI hint strings in a few admin/manager pages). The cited attendance.html:84-87 text exists as quoted and is indeed the most substantial operator guidance in the tree (a columns table + 'كيف يعمل' step list), and DEPLOYMENT.md item 4 correctly states OTP self-service needs an SMS gateway that is not wired — with no written support fallback for admins. So the finding is not refutable. However it is over-rated: (1) the impact argument is speculative scaling ('dozens of centers') for a system currently in a single-country pilot with a small team; (2) there is no mobile app in the repo (only a `manifest.webmanifest` PWA stub), so the 'store review / privacy page before store review' impact is hypothetical; (3) the manager pages that carry the most non-obvious semantics (attendance import, requests, teachers, students) do contain inline Arabic guidance, so the gap is partial, not total; (4) this is a documentation gap with no correctness or security consequence and no code path behaves differently than documented. Corrected severity: low.

```text
git ls-files md/txt: CLAUDE.md, DEPLOYMENT.md, backend/README.md, backend/DEPLOY_LOG.md, frontend-html/README.md, n8n/README.md, _handoff2/untitled/README.md, backend/public/robots.txt — no user-facing guide. frontend-html/README.md:22-27 demo-account table lists password `password` (stale vs seeded `[redacted-demo-password]`) — dev doc, not a user manual. frontend-html/manager/attendance.html:81-90 is the fullest inline operator guidance (column table + 'كيف يعمل' steps). Inline hint strings also present in admin/managers.html, admin/students.html, admin/teachers.html, manager/requests.html, manager/students.html, manager/teachers.html. No privacy/terms page; no mobile app in tree (only frontend-html/manifest.webmanifest). DEPLOYMENT.md:11 (item 4) confirms OTP needs an unbuilt SMS gateway with no documented support fallback.
```

</details>

### No CONTRIBUTING.md, PR template, CODEOWNERS or CI — nothing defines how tests, style and docs are kept green

<a id="no-contributing-ci-docs-gate"></a>

`no-contributing-ci-docs-gate` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `backend/composer.json:17-25`, `backend/.editorconfig`, `CLAUDE.md:105`

**Evidence**

```text
`ls .github` → 'No such file or directory'; only CI-like file is .cpanel.yml (deploy). `find . -iname 'CONTRIBUTING*' -o -iname 'CODEOWNERS' -o -iname 'SECURITY.md'` → none. Branching: master + 2 stale `claude/*` remote branches, no protection documented. CLAUDE.md:105 says 'Keep them green — they are the only guard on the dual role+ability security model' but nothing runs them automatically.
```

**Why it matters**

With contractors joining for the mobile build, undocumented process means inconsistent PRs, un-run security tests, and docs that drift (as measured in this audit). Enterprise buyers ask for a SECURITY.md disclosure path, especially for a system holding minors' data.

**Recommendation**

Add CONTRIBUTING.md (branching, Conventional Commits, `php artisan test` must pass, docs-update checklist), .github/PULL_REQUEST_TEMPLATE.md, SECURITY.md (contact + disclosure), and a minimal GitHub Actions workflow running pint + phpunit against MySQL service + the OpenAPI route-coverage test.

### Comments explain intent well but carry almost no machine-readable contracts (@param/@return, strict_types, return types)

<a id="phpdoc-contract-tags-absent"></a>

`phpdoc-contract-tags-absent` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/MessageController.php:24-26`, `backend/app/Http/Controllers/Api/AdminUserController.php`, `backend/app/Http/Controllers/Api/AttendanceImportController.php`, `backend/app/Models/Revision.php`, `backend/app/Models/TajweedEvaluation.php`

**Evidence**

```text
Coverage sample over app/: 133/184 public methods (72%) have a docblock or comment directly above; but `grep -rc '@param'` = 4, `@return` = 2, `declare(strict_types` = 0 files, and only 71/219 functions declare a return type. Zero-coverage files: AdminUserController (0/1), AttendanceImportController (0/1), Memorization (0/3), Message (0/2), Revision (0/3), TajweedEvaluation (0/2). No PHPStan/Larastan in composer.json require-dev; laravel/pint is present but no pint.json or documented style.
```

**Why it matters**

IDEs and static analysers cannot derive response shapes or nullability from the code, which is exactly what an OpenAPI generator (finding 1) and the mobile team need; low for now because the prose comments are good.

**Recommendation**

Adopt Larastan level 5+ and typed return signatures on all controllers; add `@return JsonResponse` + array-shape docblocks (`@return array{success:bool,data:array<int,Student>}`) on the endpoints the mobile app consumes; commit pint.json and document the standard in CONTRIBUTING.md.

### No stated documentation language policy: English CLAUDE.md, Arabic ops docs, boilerplate English README, mixed commits

<a id="language-strategy-undefined"></a>

`language-strategy-undefined` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `CLAUDE.md:1`, `DEPLOYMENT.md:1`, `backend/DEPLOY_LOG.md:1`, `n8n/README.md:1-4`, `backend/README.md:1`

**Evidence**

```text
CLAUDE.md is fully English; DEPLOYMENT.md, DEPLOY_LOG.md, frontend-html/README.md, دليل-محتوى-الصفحات.md are Arabic; n8n/README.md has an Arabic title with an English body; backend/README.md is English Laravel boilerplate. Code comments: 709 of 982 comment lines (72%) contain Arabic. Commit subjects: mixed Arabic with English conventional prefixes (`feat(admin-ui): «ملفّي الشخصي» للأدمن`). No glossary maps domain terms (محفّظ/ثُمن/حزب/مدير مركز) to the code identifiers (teacher/eighth/hizb/center_manager).
```

**Why it matters**

A mixed Libyan/international team (mobile contractors, hosting vendor, auditors) cannot predict which language a document will be in; the absence of a glossary is why identifiers such as `eighth`, `type='محفظ أساسي'` and `result='ناجح'` are opaque to non-Arabic engineers.

**Recommendation**

Write docs/CONTRIBUTING.md stating: code identifiers English; API enum literals frozen as-is (Arabic) and enumerated in the OpenAPI spec; architecture/ADR/API docs English with Arabic summaries; user manuals Arabic; commit subjects Conventional Commits in English with Arabic body allowed. Add docs/glossary.md (Arabic term → English → code identifier).

### Unexplained artifacts committed at repo/backend root: personal photos, scratch PHP scripts, test xlsx, 3.3 MB design bundle

<a id="repo-hygiene-unexplained-artifacts"></a>

`repo-hygiene-unexplained-artifacts` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `Home photos/`, `backend/check_excel.php:1-9`, `backend/generate_test_excel.php:1-8`, `backend/attendance_test.xlsx`, `_handoff2/untitled/project/`, `.gitignore:1-10`

**Evidence**

```text
`ls 'Home photos'` → 11 JPGs (1.1 MB, e.g. '629319058_1229473859375865_…_n.jpg', 'DSC09960-scaled.jpg'), referenced nowhere. backend/check_excel.php:4 `IOFactory::load('attendance_test.xlsx')` and generate_test_excel.php are ad-hoc scripts sitting beside artisan. _handoff2/ = 3.3 MB of .dc.html prototypes + PNGs. Root .gitignore ignores only zips/node_modules/OS files.
```

**Why it matters**

Knowledge-management noise: nobody can tell which artifacts are documentation, fixtures, or accidents, and a public repo or vendor handoff would leak personal photos. Fixture scripts belong in tests/Fixtures with a note.

**Recommendation**

Remove 'Home photos/'; move check_excel.php/generate_test_excel.php/attendance_test.xlsx to backend/tests/Fixtures/ with a README line; archive _handoff2 to docs/design/archive or an external asset store; add a top-level docs/README.md index that says what every folder is for.

## Measured facts

| Metric | Value |
|---|---|
| Markdown/doc files in repo | 8 (.md) + .cpanel.yml + manifest.webmanifest; total ≈ 72 KB; no docs/ directory |
| CLAUDE.md size / age | 29,642 bytes, 223 lines; last edited a07327d 2026-09-08; 60 commits since (43 touching app/routes/frontend) |
| API endpoints (code) | ≈107 (89 explicit Route:: + 18 from 5 apiResource) |
| Endpoints missing from CLAUDE.md | 20 (≈19%); 1 documented endpoint does not exist (DELETE /weekly-tests) |
| Controllers / undocumented | 19 / 2 (MessageController, AdminUserController) |
| Models / Support classes undocumented | Message model; LoginEmail, Percentage support classes |
| Frontend role pages / missing from CLAUDE.md | 29 role pages (33 html total) / 8 missing (28%) |
| DB tables created by migrations / listed in CLAUDE.md | 24 / 23 (messages missing); 35 migrations |
| Feature test files: documented vs actual | '20' claimed vs 38 Feature + 2 Unit files, 178 test methods |
| False statements in CLAUDE.md (verified) | ≥12 (test count, demo password, manager email scheme, parent email scheme, parent has no code, weekly-tests destroy/update, frontend/ dir, revisions read in show, managerSearchParents handler, manager cannot toggle teachers, 3 roles in api.php:17 comment, 'male' national-id format) |
| Public-method comment coverage (app/) | 133/184 = 72%; @param 4, @return 2, strict_types 0 files, typed returns 71/219 (32%) |
| Comment language | 709 of 982 comment lines (72%) contain Arabic |
| Inline architecture-decision markers ('قرار معتمد') | 18; ADR files: 0 |
| OpenAPI / Postman / ERD / diagram / CHANGELOG / CONTRIBUTING / SECURITY files | 0 each |
| Git history | 224 commits, 1 tag, 64 conventional-prefixed (29%), 1,414 body lines |
| DEPLOY_LOG.md currency | 8 entries, 6 marked pending; 18 commits since last entry (e7c50f9 2026-09-11) |
| Stale docs age | دليل-محتوى-الصفحات.md, frontend-html/README.md, screenshots/ (36 PNG, 4.9 MB) unchanged since initial commit 2026-06-25 |
| Unreferenced binary bundles | screenshots/ 4.9 MB, _handoff2/ 3.3 MB, 'Home photos/' 1.1 MB (11 JPGs) |
| CI workflows | 0 (.github absent); deploy only via .cpanel.yml |

## Auditor notes

Additional minor items not promoted to findings: (a) DEPLOYMENT.md:39 'إضافة soft-deletes/سجل تدقيق' is listed as future work although CLAUDE.md:223 says the audit-field approach already closed that gap — the two docs disagree; (b) CLAUDE.md:68 'Libyan male format' vs StudentController.php:38 regex `^[12]\\d{11}$` accepting 1 (male) or 2 (female) — wording left over from a male-only seeding decision; (c) CLAUDE.md:24 still lists `db:seed --class=ExtraDataSeeder` as a routine command although DatabaseSeeder.php:12-13 says it is no longer called and 'غير منصوح به'; LibyanDataSeeder and ProductionSeeder (the actual seeders) are unmentioned; (d) CLAUDE.md:89 notification list omits `message_received`; (e) api.php:17 comment 'دخول موحّد للأدوار الثلاثة' says three roles; (f) the orchestrator brief said 39 feature-test files — the tree actually has 38 in tests/Feature (one is the skeleton ExampleTest) plus 2 in tests/Unit. Recommended docs/ structure: docs/README.md (index) · docs/architecture/{overview.md,c4-context.mmd,c4-container.mmd,auth-model.md} · docs/adr/NNNN-*.md · docs/api/{openapi.yaml,conventions.md,errors.md,versioning.md} · docs/data/{erd.mmd,dictionary.md,enums.md} · docs/ops/{runbook.md,releases.md,backup-restore.md,hosting-cpanel.md} · docs/user/{admin,manager,teacher,parent}-guide.md (Arabic) · docs/design/ (archived handoff + theme tokens) · docs/glossary.md; root: README.md, CONTRIBUTING.md, SECURITY.md, CHANGELOG.md. Flutter day-1 set = docs/api/* + docs/architecture/auth-model.md + docs/data/enums.md + docs/glossary.md.
