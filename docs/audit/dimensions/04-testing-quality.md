# Testing & Quality Gates

[← Enterprise Audit](../enterprise-audit.md)

**Score 63 / 100** — Needs real work · maturity **L2** · weight 9%

The backend has a genuinely strong, security-focused feature-test base (39 files, 178 test methods, ~958 assertions, 4,641 test LOC against 7,072 app LOC) that logs in through the real /api/auth/login so Sanctum abilities are exercised as in production, covers the 4-role matrix incl. token-tampering escalation, ownership/scope, Arabic validation messages, a simulated ParentResolver race, transaction rollback and N+1 query-count guards. That earns it 'functional'. It falls well short of the level-5 bar because nothing enforces it: there is no CI of any kind (no .github/.gitlab-ci/Jenkinsfile), the .cpanel.yml deploy is a plain rsync with no test gate, there is no static analysis or lint config, no coverage measurement, 34 of 107 routes (31.8%) have zero test hits (8 of 9 PDF endpoints, logout, notifications list, athman, 5 routes whose ownership/scope guards are untested), only one real unit-test file exists, the frontend (7,043 LOC, 33 pages) and n8n automation have zero tests, and the suite cannot even be run from this clean checkout (no vendor/, no .env, documented PHP path C:\xampp\php\php.exe does not exist). Repeatable practice (tests accompany every feature commit) but not a defined, gated, measured process → CMMI 2.

## What is already strong

- Real auth in tests: tests/Concerns/CreatesCoreData.php:88-100 `loginToken()` posts to /api/auth/login and uses the returned token, so every feature test exercises the actual Sanctum ability grant ('*' / 'parent' / 'manager'), not a mocked actingAs().
- Privilege-escalation regression tests: RoleMatrixTest.php:53-63 and CenterManagerTest.php:48-57 flip `role` to admin AFTER token issuance and assert 403 — directly guards the dual role+tokenCan design in AdminMiddleware.php:17 / CenterManagerMiddleware.php:20.
- 4-role matrix with 401 baseline: RoleMatrixTest.php:33-51 (admin/teacher/parent × 8 routes) plus CenterManagerTest.php:33-47 (manager vs the other three on 4 manager routes and manager on 4 foreign routes), each route also asserted 401 without token.
- Center-scope and ownership negatives across the manager panel: ManagerReportsScopeTest.php:536-565, ManagerTeacherPerformanceTest.php:819-845, ManagerAttendanceReviewTest.php:330-346, ManagerChangeTeacherTest.php:735-772, ManagerParentsTest.php:250-286 all assert 403 AND that no foreign data leaks (`assertStringNotContainsString('طالب سرّي', ...)`).
- Mass-assignment/injection negatives: ManagerAddTeacherTest.php:560-599 (injected center_id/role/display_code ignored), TeacherProfileTest.php:665-694 (name/type/center_id/role/display_code via phone update ignored), StudentCodePreviewTest.php:545-578, GeneratedLoginEmailTest.php:49-70 (client email ignored).
- Concurrency simulated for guardian de-dup: ManagerAddStudentGuardianTest.php:166-191 inserts a competing parent inside `User::creating` and asserts the request re-matches instead of 500.
- Transaction atomicity proven: WeeklyTestUpdateTest.php:817-839 forces a mid-transaction SQL failure and asserts full rollback of questions and result.
- Performance regression guards: CenterStatusTest.php:123-139 and ManagerReportsTest.php:486-511 count queries via DB::enableQueryLog and assert a small constant (<=5) to catch N+1.
- Timezone correctness pinned: TeacherStudentDetailsTest.php:551-564 uses Carbon::setTestNow('2026-09-12 00:30 Africa/Tripoli') to prove 'today' is local not UTC.
- Domain edge cases unit-tested: tests/Unit/SurahReferenceJuzGapTest.php (juz 2/5 with no starting surah, out-of-range juz 31, 114-surah completion → 30 juz) and MemorizationValidationTest.php:181-189 (juzRangeOf incl. multi-juz surahs).
- Fingerprint xlsx import tested end-to-end by generating real spreadsheets in-test: FingerprintImportTest.php:24-38 builds xlsx via PhpSpreadsheet, covering plain/prefixed codes, name-mismatch warnings, duplicate-day updates, 4-column device files, wrong headers (422) and out-of-center rejection.
- Tests co-evolve with features: 44 of 224 commits touch backend/tests, with a `test(...)` commit convention (e.g. edab077, d4ee995, 2bd8967, 83ae440, 53c14f6, 4ee4a11, 7b7b897); DEPLOYMENT.md:18 lists 'tests green' as a pre-deploy checklist item.
- Isolated test DB: phpunit.xml:26-27 forces mysql/`mutqin_test`, BCRYPT_ROUNDS=4, CACHE_STORE=array, QUEUE_CONNECTION=sync — every feature test uses RefreshDatabase (0 files missing it).

## Level-5 target state

Every push runs a CI pipeline (MySQL service) that executes the full PHPUnit suite in parallel with a coverage floor (≥80% lines, 100% of routes behaviour-tested via a route-manifest guard), larastan level ≥6, Pint, an OpenAPI contract validation step that fails on any response-shape drift, and a Playwright smoke run across the four roles; deploys to cPanel only happen from a green, tagged build. Pure domain logic (SurahReference, ArabicText, PhoneNumber, ParentResolver, DisplayCode, LoginEmail, week boundaries) lives in fast unit suites with frozen time, while feature tests cover every route's happy path, 401/403 for all four roles, Arabic 422s and tenant isolation. Coverage, flake rate and suite duration are tracked over time and reviewed each sprint — the 'optimizing' loop of level 5.

## What the Flutter team must know

The mobile team inherits an API whose security boundaries are well tested but whose response shapes are not contractually pinned: only 2 tests assert exact key sets, one test hedges between `data.data` and `data` (MemorizationJuzGapFilterTest.php:65-66), and 34 routes — including logout, notifications list/read-all, parent/children, parent/messages and teacher/messages thread lists, athman/search, memorizations/surahs, manager/center and all manager/teacher PDFs — have zero backend tests, so their shapes can drift without any red build. There is no CI, so a backend commit can ship to production between two mobile releases with nothing verifying compatibility. Before coding against an endpoint, the Flutter team should (a) obtain/write the contract test for it (see finding no-api-contract-tests), (b) treat `{success,message,data,errors}` plus paginator shape (`data.data/total/per_page/last_page`) as the only guaranteed envelope, (c) expect 422 with Arabic `errors` keyed by field, 401 on revoked tokens (deactivation revokes all tokens immediately — tested), and 403 with Arabic `message` on cross-tenant access, and (d) assume 'today' and week boundaries are computed in Africa/Tripoli with Saturday-start weeks (the latter untested). The mobile team should also expect PDF endpoints to be untested and environment-sensitive (gd/mPDF).

## Findings — 14 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [No CI pipeline anywhere; deploy is an ungated rsync](#no-ci-quality-gate) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [34 of 107 API routes (31.8%) have zero test hits; 3 more are status-only](#routes-untested-32pct) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Five existing ownership/scope guards have no test — the security model's 'only guard' is missing here](#ownership-guards-untested) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Response shapes are asserted ad hoc; no schema/contract snapshot for the envelope Flutter will depend on](#no-api-contract-tests) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Suite cannot be executed from this checkout; test-environment docs have drifted](#suite-not-runnable-from-clean-clone) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [8 of 9 PDF report endpoints untested (mPDF + gd extension dependency)](#pdf-endpoints-untested) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [No static analysis (phpstan/larastan) and Pint is installed but unconfigured and never run](#no-static-analysis-or-lint-gate) | 🟡 medium | ✅ confirmed | NEXT | M |
| [Only one real unit-test file; core Support classes rely solely on slow HTTP feature tests or have no tests](#unit-test-scarcity) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [No line/branch coverage is measured or thresholded; no mutation testing](#no-coverage-measurement) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Saturday→Friday week logic and /reports/weekly have no tests](#week-boundary-and-weekly-report-untested) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Zero automated tests for the 33-page web client; QA is manual screenshots](#no-frontend-or-e2e-tests) | 🟡 medium | ✅ confirmed | LATER | L |
| [Several tests depend on wall-clock date, MySQL strict mode, or the login throttle](#flaky-tolerances-and-env-coupling) | ⚪ low | ℹ️ informational | LATER | S |
| [Only a boilerplate UserFactory (unused); CreatesCoreData is the de-facto builder and generates non-Libyan phone numbers](#factories-absent-builder-quirks) | ⚪ low | ℹ️ informational | LATER | S |
| [DisplayCode atomicity and PrimaryTeacherRule lock are only tested sequentially](#concurrency-only-sequential) | ⚪ low | ℹ️ informational | LATER | M |

### No CI pipeline anywhere; deploy is an ungated rsync

<a id="no-ci-quality-gate"></a>

`no-ci-quality-gate` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `.cpanel.yml:3-7`, `DEPLOYMENT.md:18`, `backend/composer.json:15-24`

**Evidence**

```text
`ls .github .gitlab-ci.yml .circleci Jenkinsfile azure-pipelines.yml bitbucket-pipelines.yml` → all 'No such file or directory'. `.cpanel.yml` lines 5-6: `/usr/bin/rsync -a --delete ... frontend-html/ $DEPLOYPATH/` and `/usr/bin/rsync -a --delete ... backend/ $DEPLOYPATH/backend/` — no `artisan test`, no lint, no composer install step. DEPLOYMENT.md:18 says 'php artisan test قبل كل نشر' but nothing enforces it. No git hooks (`ls .git/hooks | grep -v sample` → empty).
```

**Why it matters**

The 178-test suite is only as good as a developer remembering to run it. A push to master deploys directly to production (public_html) with zero verification. At dozens of centers plus a mobile app depending on the API contract, one forgotten run ships a regression to every center simultaneously, and there is no audit trail proving what was tested before a release.

**Recommendation**

Add a GitHub Actions (or GitLab) workflow: services: mysql:8 with a `mutqin_test` DB; steps: composer install (using backend/composer.phar is fine), `php artisan test --parallel`, `vendor/bin/pint --test`, `php -l` on frontend? (n/a) — and make the cPanel/rsync deploy depend on a green run (deploy from a tag/branch that CI protects). Enable branch protection on master requiring the check.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Evidence verified: no CI config anywhere in the repo root (.github, .gitlab-ci.yml, .circleci, Jenkinsfile, azure-pipelines.yml, bitbucket-pipelines.yml all absent), no non-sample git hooks and no core.hooksPath, and .cpanel.yml lines 4-7 are pure rsync --delete of frontend-html/ and backend/ into public_html with a cache wipe — no composer install, no artisan test, no pint. DEPLOYMENT.md item 9 ('php artisan test قبل كل نشر') is documentation only; nothing enforces it. laravel/pint is in require-dev but is not installed in vendor/bin locally, so even the lint gate is unused. The finding is factually correct and not mitigated. However it is over-rated: (1) it is a process/governance gap, not a code defect — no incorrect behaviour exists today, and a substantive 173-test Feature suite across 38 files does exist and is documented as the pre-deploy step; (2) the impact text overstates the trigger — .cpanel.yml runs only when cPanel's Git Version Control pulls/deploys (manual 'Deploy HEAD Commit' or a push to the cPanel-hosted repo), not automatically on every push to the GitHub origin (muad03/MUTQIN), so a stray push does not by itself ship to production; (3) the 'mobile app depending on the API contract' and 'dozens of centers' claims are not evidenced in the repo; (4) for a small-team, single-country product the realistic fix is a single GitHub Actions workflow — effort S, as the auditor says. Real gap, medium severity.

```text
.cpanel.yml:4-7 — only `export DEPLOYPATH`, two `rsync -a --delete` tasks and `rm -f bootstrap/cache/*.php`; no test/lint/install step. DEPLOYMENT.md:18 — row 9 'الاختبارات خضراء | php artisan test قبل كل نشر' (advisory only). backend/composer.json:15-24 — laravel/pint, phpunit in require-dev; backend/vendor/bin contains no pint binary. Repo root listing: no .github/, no CI files; `ls .git/hooks | grep -v sample` empty; `git config core.hooksPath` unset. Test suite: 38 files in backend/tests/Feature, ~173 test methods. Deploy trigger is cPanel Git Version Control (pull/deploy action), not GitHub push — 'a push to master deploys directly' is overstated.
```

</details>

### 34 of 107 API routes (31.8%) have zero test hits; 3 more are status-only

<a id="routes-untested-32pct"></a>

`routes-untested-32pct` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/routes/api.php:22-23`, `backend/routes/api.php:27`, `backend/routes/api.php:33-34`, `backend/routes/api.php:38-40`, `backend/routes/api.php:47`, `backend/routes/api.php:57`, `backend/routes/api.php:62`, `backend/routes/api.php:96`, `backend/routes/api.php:98`, `backend/routes/api.php:100`, `backend/routes/api.php:109`, `backend/routes/api.php:117`, `backend/routes/api.php:122`, `backend/routes/api.php:147`, `backend/routes/api.php:155`, `backend/routes/api.php:158`, `backend/routes/api.php:160`, `backend/routes/api.php:164`, `backend/routes/api.php:166-167`

**Evidence**

```text
Coverage map built by enumerating routes/api.php (107 routes incl. apiResource expansion) against every `/api/...` literal in tests/Feature (grep of 39 files). Zero-hit routes: GET /public/stats, GET /public/demo-accounts, POST /auth/logout, GET /notifications, POST /notifications/read-all, GET /athman/search, GET /athman/hizb/{n}, GET /athman/{id}, GET /parent/messages, GET /manager/students/next-code, PUT /manager/students/{id}/status, GET /manager/center, GET /manager/reports/{center,at-risk,teachers}/pdf, GET /centers/{id}/has-primary, GET /parents/search, GET /reports/admin/missing-national-id, GET /reports/admin/{center/{id},teachers,overview}/pdf, GET /teacher/messages, GET /attendance/report, GET /memorizations/surahs, GET /reports/weekly, GET /reports/student/{id}, GET /reports/student/{id}/pdf, GET /reports/teacher/pdf, GET /teachers/{id}, PUT /teachers/{id}, PUT /centers/{id}, PUT /admin/managers/{id}, DELETE /memorizations/{id}, GET /weekly-tests/{id}. Status-code-only (RoleMatrixTest.php:33-38, no payload assertions): GET /attendance, GET /weekly-tests, GET /parent/children. `grep -rn "putJson.*admin/managers" tests/Feature | grep -v status` → empty; `grep -rn logout tests/` → empty.
```

**Why it matters**

A Flutter client for all four roles will call several of these on day one: logout, notifications list/read-all (bell), parent/children (parent home screen), parent/messages + teacher/messages thread lists, athman/search (autocomplete), memorizations/surahs (dropdown), manager/center. None has a test asserting its response shape, so any refactor can silently break the mobile app. Admin update paths (PUT /teachers/{id}, PUT /centers/{id}, PUT /admin/managers/{id}) carry business rules (PrimaryTeacherRule ignoreId, single-manager on update) that are unverified.

**Recommendation**

Add one feature test per untested route, prioritising the mobile-facing set (logout revokes only the current token; notifications index/read-all scoping; parent/children payload incl. is_active badge; messages thread lists; athman search; memorizations/surahs) and the three admin update endpoints (assert PrimaryTeacherRule with ignoreId and single-manager-per-center on update). Add a guard test that iterates Route::getRoutes() and fails if a route lacks an entry in a maintained coverage manifest so the gap cannot regrow.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The finding is factually accurate. I re-enumerated backend/routes/api.php (108 routes with apiResource expansion vs the auditor's 107 — one-off, immaterial) and grepped all 38 tests/Feature files for each route the auditor lists as zero-hit. Every one checks out: no test touches /public/stats, /public/demo-accounts, POST /auth/logout, GET /notifications, POST /notifications/read-all (only /notifications/{id}/read at OwnershipTest.php:74), any /athman/* route, GET /parent/messages or GET /teacher/messages thread lists (MessagingTest only hits the per-student {student} variants), /manager/students/next-code, PUT /manager/students/{id}/status, GET /manager/center, the three /manager/reports/*/pdf routes, /centers/{id}/has-primary, admin /parents/search (only /manager/parents/search is tested), /reports/admin/missing-national-id, /reports/admin/{center,teachers,overview}/pdf (only at-risk/pdf at ManagerReportsTest.php:82), /attendance/report, /memorizations/surahs, /reports/weekly, teacher /reports/student/{id} (only the manager variant is hit), /reports/student/{id}/pdf, /reports/teacher/pdf, GET/PUT /teachers/{id} (only /status), PUT /centers/{id} (only /status), PUT /admin/managers/{id} (only /status and a 405 DELETE), DELETE /memorizations/{id}, GET /weekly-tests/{id}. RoleMatrixTest.php:31-38 indeed asserts only status codes for /attendance, /weekly-tests, /parent/children. No Route::getRoutes() guard exists. The PrimaryTeacherRule ignoreId path on update (TeacherController.php:231, CenterManagerController.php:412) and assertSingleSupervisor on update (ManagerManagementController.php:117) have no tests — only the create-path variants are covered (ManagerAddTeacherTest.php:78-85).

However, "high" overstates it. The security-critical surface (role+ability dual check, ownership, center scoping, status toggles, token revocation) is well covered by 38 files / ~4,400 lines. The untested set is dominated by read-only GETs (reports, PDFs, autocomplete, next-code previews, public stats) where a regression would be visible, not exploitable. The impact narrative leans on a hypothetical Flutter client that is not part of the repo. The genuinely meaningful gaps are the three admin update endpoints with business rules and logout's token-revocation semantics. That is a real but moderate quality-gate deficiency for a small single-country team — medium, not high.

```text
Confirmed zero test hits (grep over backend/tests/Feature, 38 files): backend/routes/api.php:22-23 (/public/*), :27 (logout), :33-34 (notifications index/read-all; only :35 covered at tests/Feature/OwnershipTest.php:74), :38-40 (athman), :47 and :147 (thread lists; MessagingTest covers only {student} variants), :56, :58, :62, :80-82, :98, :109, :117, :122, :125-126,:128 (at-risk/pdf :127 covered at ManagerReportsTest.php:82), :155, :158, :166-167, :170-171; apiResource expansions GET/PUT /teachers/{id} (:96), PUT /centers/{id} (:100), DELETE /memorizations/{id} (:160), GET /weekly-tests/{id} (:164). Status-only assertions: tests/Feature/RoleMatrixTest.php:31-38. Untested update-path business rules: app/Http/Controllers/Api/TeacherController.php:231 (PrimaryTeacherRule::assert with ignoreId), app/Http/Controllers/Api/ManagerManagementController.php:117 (assertSingleSupervisor on update); only create-path variants tested (tests/Feature/ManagerAddTeacherTest.php:78-85). Mitigation: security model broadly covered (RoleMatrixTest, OwnershipTest, CenterManagerTest, *StatusTest), so the gap is regression-risk on mostly read-only endpoints rather than an exploitable hole.
```

</details>

### Five existing ownership/scope guards have no test — the security model's 'only guard' is missing here

<a id="ownership-guards-untested"></a>

`ownership-guards-untested` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/MemorizationController.php:214-219`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:177-182`, `backend/app/Http/Controllers/Api/ReportController.php:20-25`, `backend/app/Http/Controllers/Api/ReportPdfController.php:83-85`, `backend/app/Http/Controllers/Api/StudentController.php:581-585`, `backend/routes/api.php:57`

**Evidence**

```text
Guards exist: MemorizationController.php:214 `if (!$user->isAdmin() && $student->teacher_id !== $user->id) { ... 403`; WeeklyTestController.php:177 same for show; ReportController.php:20 same for student report; ReportPdfController.php:83 `abort(403, ...)`; StudentController.php:581 `if ($user->isCenterManager() && (int) $student->center_id !== (int) $user->center_id) ... 403`. No test hits DELETE /memorizations/{id}, GET /weekly-tests/{id}, GET /reports/student/{id}, GET /reports/student/{id}/pdf or PUT /manager/students/{id}/status (`grep -rn "manager/students/.*status" tests/Feature` → empty). CLAUDE.md Gotchas: 'Keep them green — they are the only guard on the dual role+ability security model.'
```

**Why it matters**

These are cross-tenant data-access boundaries (teacher A deleting teacher B's memorization record; manager A deactivating a student in center B; teacher reading another teacher's weekly-test details or student report/PDF). A refactor that drops or inverts one `!==` would pass the whole suite. At dozens of centers this is a multi-tenant leak with no automated detection.

**Recommendation**

Extend OwnershipTest with: teacherB DELETE /memorizations/{A's record} → 403 and row still present; teacherB GET /weekly-tests/{A's test} → 403; teacherB GET /reports/student/{A's student} and /pdf → 403; managerA PUT /manager/students/{B's student}/status → 403 and is_active unchanged; plus the positive owner path for each. ~1 test file, <1 day.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Evidence verified as accurate. All five guards exist exactly as quoted: MemorizationController.php:214 (destroy), WeeklyTestController.php:177 (show), ReportController.php:20 (student), ReportPdfController.php:83 (abort 403), StudentController.php:581 (toggleStatus manager scope). A grep over backend/tests/ for GET /weekly-tests/{id}, DELETE /memorizations/{id}, GET /reports/student/{id} (+/pdf) and PUT /manager/students/{id}/status returns nothing (exit 1). OwnershipTest.php covers only students show/update, memorization store, parent child, and notifications; the `putJson("/api/students/{id}/status")` at OwnershipTest.php:28 hits the admin-gated route and is rejected by middleware, not by the manager-scope guard, so it does not cover StudentController.php:581. ManagerReportsScopeTest covers the manager's `/manager/reports/student/{id}` (CenterManagerController), a different route from the teacher `/reports/student/{id}` cited. No middleware mitigation exists for these routes: the teacher gate only checks role+ability, so the controller `!==` compare is the sole cross-teacher boundary. The finding is therefore factually correct and not otherwise mitigated. However, severity is overstated: this is a test-coverage gap, not a live defect — the guards are present and correct today, they are three-token one-liners unlikely to be silently inverted, and the affected data (a teacher deleting another teacher's memorization row, reading a test detail/report) is within one small-team product rather than a broad multi-tenant leak. Medium is the appropriate rating for a missing regression test on an existing, working authorization check.

```text
backend/app/Http/Controllers/Api/MemorizationController.php:214, WeeklyTestController.php:177, ReportController.php:20, ReportPdfController.php:83, StudentController.php:581 — guards confirmed present. backend/tests/Feature/OwnershipTest.php:28 tests PUT /api/students/{id}/status as teacher B (403 from the `admin` middleware, not from the manager-scope guard) — it does not exercise the /manager/students/{id}/status branch. grep -rn -E 'getJson\("/api/weekly-tests/|deleteJson\("/api/memorizations|getJson\("/api/reports/student|/manager/students/.*status|/reports/student/.*pdf' backend/tests/ → no matches. ManagerReportsScopeTest.php:35,50 cover /manager/reports/student/{id} (CenterManagerController), not the teacher route at routes/api.php:167/170.
```

</details>

### Response shapes are asserted ad hoc; no schema/contract snapshot for the envelope Flutter will depend on

<a id="no-api-contract-tests"></a>

`no-api-contract-tests` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/tests/Feature/AuthLoginTest.php:18-22`, `backend/tests/Feature/AdminUsersListTest.php:181`, `backend/tests/Feature/MemorizationJuzGapFilterTest.php:65-66`, `frontend-html/js/api.js`

**Evidence**

```text
Only two tests pin a full key set: AuthLoginTest.php:22 `assertJsonStructure(['data' => ['token', 'user' => ['id','name','email','role']]])` and AdminUsersListTest.php:181 `assertSame([...10 keys...], array_keys($row))`. Elsewhere shape is inferred loosely — MemorizationJuzGapFilterTest.php:65-66 literally hedges: `$r->json('data.data') ?? $r->json('data')` and `firstWhere('id', ...) ?? firstWhere('student_id', ...)`, i.e. the test itself does not know whether the endpoint paginates or which id key it returns. No OpenAPI file, no JSON-schema, no snapshot tests exist (`find . -iname "*openapi*" -o -iname "*swagger*"` not present in repo listing). The `{success,message,data,errors}` envelope is documented only in prose.
```

**Why it matters**

A Flutter client is a second, compiled consumer that cannot be hot-fixed like the static HTML pages. Without contract tests, a backend change that renames `data.data`→`data.items`, drops a key, or changes pagination for one role breaks the app in the field, and the suite stays green. This is the single biggest testing risk for the 2-week mobile target.

**Recommendation**

For every endpoint the mobile app consumes, add `assertJsonStructure`/exact `array_keys` assertions (or spatie/pest snapshot style) pinned to a versioned contract; generate an OpenAPI 3 spec (e.g. dedoc/scramble or hand-written) and add a test that validates responses against it (league/openapi-psr7-validator). Make the envelope a shared trait/resource so its shape is tested once and enforced everywhere.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 72% · corrected severity: medium

Evidence checks out with one citation error. Confirmed: backend/tests/Feature has exactly 1 `assertJsonStructure` (AuthLoginTest.php:22) and only two full-key-set pins via `array_keys` (AdminUsersListTest.php:49 — NOT :181 as cited — and ManagerAddStudentGuardianTest.php:208). MemorizationJuzGapFilterTest.php:65-66 does hedge with `json('data.data') ?? json('data')` and `firstWhere('id') ?? firstWhere('student_id')`, exactly as quoted. No OpenAPI/swagger/scramble/snapshot tooling exists in composer.json or the repo. The `{success,message,data,errors}` envelope is not centralized: app/Http/Controllers/Controller.php is an empty abstract class, and the envelope is hand-built inline in ~148 `'success' => true/false` occurrences across 149 `response()->json(...)` calls with zero shared helper — so the "shape is tested once and enforced everywhere" recommendation addresses a real structural gap. However the impact is overstated: the auditor's example ("renames data.data→data.items ... and the suite stays green") is false — there are 102 `assertJsonPath` calls, ~85 of which address paginated `data.data.*`/`data.total`/`data.per_page` paths across 10 test files (PaginationSearchTest alone has 26), so a pagination-shape rename on the main list endpoints would fail many tests. Individual field keys are pinned per-test for the endpoints that have tests; what is missing is (a) full key-set pinning (extra/removed keys go unnoticed), (b) any envelope-level assertion (`success`/`message`/`errors` asserted in only ~6/22/8 places), and (c) any machine-readable contract. The 'high' rating rests entirely on a hypothetical Flutter client mentioned nowhere in the repo (no flutter/mobile reference in CLAUDE.md, DEPLOYMENT.md, or _handoff2); for the current single first-party static-HTML consumer maintained by the same small team, this is a maintainability/roadmap gap, not an active defect. Medium is the fair rating.

```text
backend/tests/Feature/AdminUsersListTest.php:49 (not :181) — `assertSame([... 10 keys ...], array_keys($row))`; backend/tests/Feature/ManagerAddStudentGuardianTest.php:208 — second array_keys pin (missed by auditor); backend/tests/Feature/AuthLoginTest.php:22 — sole assertJsonStructure; backend/tests/Feature/MemorizationJuzGapFilterTest.php:65-66 — hedged shape as quoted; backend/app/Http/Controllers/Controller.php:5-8 — empty base class, no envelope helper; ~148 inline `'success' =>` in backend/app/Http/Controllers/Api/*; mitigation: 102 `assertJsonPath` calls incl. ~85 on `data.data.*`/`data.total`/`data.per_page` (PaginationSearchTest.php 26, AdminUsersListTest.php 15, AdminCenterDetailsTest.php 12, ManagerAttendanceReviewTest.php 9, ManagerParentsTest.php 9) so a `data.data` rename would not leave the suite green.
```

</details>

### Suite cannot be executed from this checkout; test-environment docs have drifted

<a id="suite-not-runnable-from-clean-clone"></a>

`suite-not-runnable-from-clean-clone` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/phpunit.xml:26-27`, `backend/.env.example:1-10`, `CLAUDE.md:25`, `DEPLOYMENT.md:36`, `backend/.gitignore`

**Evidence**

```text
`ls -d backend/vendor` → 'No such file or directory'; `ls backend/.env backend/.env.testing` → both missing; only `.env.example` exists and it sets `DB_CONNECTION=sqlite` while phpunit.xml:26-27 forces `mysql` + `mutqin_test` and needs DB_USERNAME/DB_PASSWORD from a .env that does not exist. `ls C:/xampp/php/php.exe` → no such file (no C:\xampp at all) although CLAUDE.md:25 and the task brief name it as the only PHP; PHP actually present at C:/Users/HP/.config/herd/bin/php8{2,4,5}/php.exe. MySQL80 Windows service IS listening on 0.0.0.0:3306 (netstat) but `mysqladmin -u root ping` fails (credentials unknown, no .env). CLAUDE.md:25 claims '20 feature-test files'; actual is 39 (`ls tests/Feature | wc -l`). Result: `artisan test` was NOT run in this audit — vendor/autoload.php is absent and installing it would create files (read-only mandate).
```

**Why it matters**

A new engineer, a CI runner or the mobile team cannot reproduce a green suite without tribal knowledge (which PHP, which DB user, that .env must be hand-built and that sqlite is unsupported because of the raw `ALTER TABLE ... MODIFY` in 2026_06_28_120000_add_nationality_to_students_and_users.php:23). Doc drift about the test count and PHP path signals nobody is measuring the suite.

**Recommendation**

Commit a `backend/.env.testing` (or extend phpunit.xml with DB_USERNAME/DB_PASSWORD env for CI) and a `phpunit.xml`-documented one-liner setup in README/DEPLOYMENT; fix CLAUDE.md test count and PHP-path guidance to be environment-neutral (`php` via Herd/XAMPP/CI); consider replacing the raw MySQL ALTER with `->change()` so sqlite in-memory can serve as a fast local fallback.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 72% · corrected severity: low

Core claims verified: backend/vendor, backend/.env and backend/.env.testing are absent; only .env.example (DB_CONNECTION=sqlite) and .env.production.example are tracked; phpunit.xml:26-27 forces mysql + mutqin_test with no DB_USERNAME/DB_PASSWORD; the raw `DB::statement('ALTER TABLE students MODIFY ...')` at 2026_06_28_120000_add_nationality_to_students_and_users.php:23 does make sqlite unusable; C:\xampp\php\php.exe does not exist on this machine while CLAUDE.md:25 names it as the only PHP; CLAUDE.md:25 says '20 feature-test files' while tests/Feature actually holds 38 (auditor's own count of 39 is off by one). DEPLOYMENT.md:36 additionally claims mutqin_test is 'managed automatically' by `php artisan test`, which is false - Laravel never creates a MySQL database, so a fresh checkout must hand-create it (undocumented). backend/README.md is the stock Laravel readme with zero project setup info, and there is no CI config anywhere in the repo. So the finding is factually accurate and not mitigated. However it is overstated in places: a missing vendor/ is normal for any gitignored Laravel repo, not a defect; the DB-credential gap is largely covered by config/database.php:53-54 defaulting to root/'' (the XAMPP default the project targets), so on the intended XAMPP box the only real hidden step is creating the empty mutqin_test schema; and the 'cannot run' result is partly the auditor's own machine (Herd instead of XAMPP) rather than the repository. Impact is developer-experience/reproducibility for a small single-country team with no CI - no runtime, data or security consequence. Real but over-rated: low, not medium.

```text
backend/phpunit.xml:26-27 (DB_CONNECTION=mysql, DB_DATABASE=mutqin_test; no APP_KEY/DB_USERNAME/DB_PASSWORD); backend/.env.example:24 (DB_CONNECTION=sqlite, contradicts phpunit.xml); backend/config/database.php:53-54 (mysql username defaults to 'root', password to '' - mitigates the credential gap on XAMPP); backend/database/migrations/2026_06_28_120000_add_nationality_to_students_and_users.php:23 (raw MySQL ALTER, blocks sqlite); CLAUDE.md:25 ('20 feature-test files' - actual `ls backend/tests/Feature | wc -l` = 38, not 39 as the auditor states); DEPLOYMENT.md:36 (claims mutqin_test is 'managed automatically' by `php artisan test` - false, the DB must be created by hand); backend/README.md is the unmodified stock Laravel README; no CI workflow files exist in the repo; backend/.gitignore ignores .env and /vendor (vendor absence is expected, not a defect).
```

</details>

### 8 of 9 PDF report endpoints untested (mPDF + gd extension dependency)

<a id="pdf-endpoints-untested"></a>

`pdf-endpoints-untested` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/routes/api.php:80-82`, `backend/routes/api.php:125-128`, `backend/routes/api.php:170-171`, `backend/tests/Feature/ManagerReportsTest.php:481-483`, `backend/app/Http/Controllers/Api/ReportPdfController.php`

**Evidence**

```text
Only ManagerReportsTest.php:482 hits a PDF route: `->get('/api/reports/admin/at-risk/pdf')->assertOk()->assertHeader('Content-Type','application/pdf')`. Untested: GET /manager/reports/center/pdf, /manager/reports/at-risk/pdf, /manager/reports/teachers/pdf, /reports/admin/center/{id}/pdf, /reports/admin/teachers/pdf, /reports/admin/overview/pdf, /reports/student/{id}/pdf, /reports/teacher/pdf. CLAUDE.md Gotchas: 'gd (mPDF)... disabled by default in this install' and 'Render numbers with Western digits — Arabic-Indic digits show as empty boxes'.
```

**Why it matters**

PDF rendering is the most environment-sensitive code path (gd extension, fonts, Arabic shaping) and the one an admin will present to funders (Awqaf). No test proves the manager variants stay center-scoped (`managerCenter` uses center_id from the account) or that a template still compiles after a data-shape change. A mobile app will likely open these PDFs via a share sheet.

**Recommendation**

Add a `ReportPdfTest`: for each of the 8 routes assert 200 + `application/pdf` + non-trivial body length; for manager variants assert 403/isolation (another center's id in the URL is ignored) and for /reports/student/{id}/pdf assert the own-student 403. Mark the class with `@requires extension gd` so CI reports a clear skip instead of a fatal.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The core claim is factually correct: grep over backend/tests/Feature finds exactly one PDF hit, ManagerReportsTest.php:82-83 (`GET /api/reports/admin/at-risk/pdf` -> 200 + application/pdf). The other 8 routes at routes/api.php:80-82, 125-128, 170-171 are never requested in any test, and no test carries `@requires extension gd`. The auditor's line reference "481-483" is wrong (the file is far shorter; the test is at lines 81-83), but the evidence itself exists.

However the impact is overstated, which is why I lower the severity. (1) All nine routes funnel through one shared `render()` (ReportPdfController.php:37-70) that builds mPDF, so the single existing test already proves the environment-sensitive part (gd, mPDF, temp dir, RTL font shaping) works; a broken gd would fail the suite today. (2) The manager variants (lines 147-168) take `center_id` straight from `$request->user()->center_id` with no request input at all, and the scoped ReportService methods they call (`centerData`, `atRiskStudents`, `teachersPerformance` with center scope) are already exercised through the JSON routes in ManagerReportsTest (`test_manager_reports_are_scoped_to_his_center`, `test_manager_cannot_request_another_center_via_param`) and ManagerReportsScopeTest. The PDF layer adds no new scoping logic to test. (3) Of the 6 Blade templates (resources/views/pdf/{student,teacher-group,admin/center,admin/teachers,admin/overview,admin/at-risk}), only `admin/at-risk` is compiled under test, so a data-shape regression in the other five templates would go unnoticed — this is the real residual gap. (4) The only security-relevant untested branch is the own-student 403 at ReportPdfController.php:83-85 (`!$user->isAdmin() && $student->teacher_id !== $user->id`), a one-line guard mirroring the tested JSON student report ownership check. Net: a genuine but modest coverage gap in read-only reporting, no data-integrity or auth exposure beyond one trivially-reviewed guard; low, not medium.

```text
backend/tests/Feature/ManagerReportsTest.php:81-83 (not 481-483) is the only PDF assertion: `->get('/api/reports/admin/at-risk/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')`. backend/app/Http/Controllers/Api/ReportPdfController.php:37-70 — single shared `render()` used by all 9 endpoints (mPDF construction, gd dependency exercised once). ReportPdfController.php:147-168 — manager variants use `$request->user()->center_id` only; scoped ReportService methods are covered via JSON routes in ManagerReportsTest.php:20-62 and ManagerReportsScopeTest.php. ReportPdfController.php:83-85 — untested own-student 403 guard. Templates never compiled under test: resources/views/pdf/student.blade.php, teacher-group.blade.php, admin/center.blade.php, admin/teachers.blade.php, admin/overview.blade.php (only admin/at-risk.blade.php is covered). No `@requires extension gd` anywhere in backend/tests.
```

</details>

### No static analysis (phpstan/larastan) and Pint is installed but unconfigured and never run

<a id="no-static-analysis-or-lint-gate"></a>

`no-static-analysis-or-lint-gate` · 🟡 medium · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/composer.json:15-24`, `backend/composer.json:26-41`

**Evidence**

```text
composer.json:18 `"laravel/pint": "^1.13"` is in require-dev, but `ls pint.json phpstan.neon phpstan.neon.dist psalm.xml .php-cs-fixer.php` → none exist; no `larastan/larastan` or `phpstan/phpstan` in require-dev; composer `scripts` (lines 26-41) contain only Laravel boilerplate — no `test`, `lint`, or `analyse` script. No frontend linter either (`ls -a frontend-html | grep -iE eslint|prettier|package` → empty).
```

**Why it matters**

With 19 controllers and 7,072 LOC of PHP, type errors, wrong nullability (e.g. `teacher_id` null paths the docs call out), and unused variables are only caught if a feature test happens to execute that line. Level-5 shops treat a clean `phpstan --level=6` as a merge requirement; its absence also means the 4,192 lines of inline page scripts have no syntax gate at all.

**Recommendation**

Add larastan at level 5 (raise gradually), commit `phpstan.neon` and `pint.json`, add composer scripts `lint`, `analyse`, `test`, and run all three in CI. Add an ESLint (or at minimum `node --check`) pass over frontend-html/js.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The quoted evidence is accurate. backend/composer.json line 18 has `"laravel/pint": "^1.13"` in require-dev, and the `scripts` block (lines 26-41) contains only the Laravel skeleton hooks (post-autoload-dump, post-update-cmd, post-root-package-install, post-create-project-cmd) - no test/lint/analyse entries. No pint.json, phpstan.neon(.dist), psalm.xml, or .php-cs-fixer.php exists anywhere under backend/ or the repo root; larastan/phpstan are absent from require-dev. frontend-html/ has no package.json, eslint or prettier config; the only package.json is the untouched Laravel skeleton one in backend/ (vite/tailwind, no lint script). There is no .github/ directory, no CI workflow, and no git hooks (.git/hooks contains only samples). git history has no commit mentioning pint/phpstan/lint. The finding is factually correct and not mitigated: (a) Pint is a code-style formatter and would not catch type/nullability errors even if configured, so the phpstan gap is the substantive one; (b) the only quality gate is the 37 feature-test files (`php artisan test`), and DEPLOYMENT.md row 9 makes that a manual pre-deploy convention, not an enforced gate; (c) .cpanel.yml deploys straight from git push via rsync with no test/lint step, so an untested push reaches production unchecked - this somewhat strengthens the finding. Minor nits: the test count is 37 files (not 20), and `node --check` over frontend-html/js/*.js passes today, but the 31 inline <script> blocks in the HTML pages have no syntax check at all, as the auditor says. For a small single-country team with a no-build vanilla frontend the practical severity is modest, but a total absence of static analysis, formatter config, and CI on a codebase with an auto-deploy hook justifies keeping medium. Could not run PHP in this sandbox (C:\xampp\php\php.exe not reachable, backend/vendor absent) so no phpstan dry-run was possible to quantify latent issues.

```text
backend/composer.json:18 `"laravel/pint": "^1.13"` (require-dev only); backend/composer.json:26-41 scripts = Laravel skeleton hooks only. No pint.json / phpstan.neon / psalm.xml / .php-cs-fixer.php in backend/ or repo root; no .github/ workflows; .git/hooks has only *.sample files. .cpanel.yml:3-7 rsyncs frontend-html/ and backend/ to public_html on push with no test/lint step. DEPLOYMENT.md:18 lists `php artisan test` before each deploy as a manual convention. frontend-html/ has no package.json/eslint/prettier; 31 inline <script> blocks across frontend-html/**/*.html have no syntax gate. backend/tests/Feature holds 37 test files (auditor/CLAUDE.md say 20).
```

</details>

### Only one real unit-test file; core Support classes rely solely on slow HTTP feature tests or have no tests

<a id="unit-test-scarcity"></a>

`unit-test-scarcity` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/tests/Unit/SurahReferenceJuzGapTest.php`, `backend/tests/Unit/ExampleTest.php`, `backend/app/Support/ArabicText.php`, `backend/app/Support/ParentResolver.php`, `backend/app/Support/PrimaryTeacherRule.php`, `backend/app/Support/LoginEmail.php`, `backend/app/Support/Percentage.php`, `backend/app/Support/PhoneNumber.php`

**Evidence**

```text
`ls tests/Unit` → ExampleTest.php (boilerplate `assertTrue(true)`) and SurahReferenceJuzGapTest.php (4 tests). Per-class grep across tests/: ArabicText → 0 files, ParentResolver → 0, PrimaryTeacherRule → 0, LoginEmail → 0, Percentage → 0; PhoneNumber → only PhoneNormalizationTest (a RefreshDatabase feature test; the pure `normalize()` assertions at lines 19-27 need no DB); DisplayCode → only DisplayCodeTest (feature). Feature tests: 173 methods vs unit: 5.
```

**Why it matters**

Normalization (ArabicText::sqlNormalize drives every `q=` search), phone normalization, Libyan id/email scheme generation and percentage rounding are pure functions with many edge cases (hamza forms, tashkeel, Arabic digits, +218/00218, empty input). Testing them only through HTTP means each case costs a DB transaction and a login, so engineers write fewer cases; the suite is also slower than it needs to be, which discourages running it.

**Recommendation**

Move PhoneNumber::normalize assertions into tests/Unit; add unit suites for ArabicText (normalize/sqlNormalize table-driven), LoginEmail (transliteration + code suffix), Percentage, PrimaryTeacherRule (with ignoreId; can be a small DB-backed unit) and ParentResolver resolution order (id_number → phone → email) incl. 422 messages. Target ≥40 unit tests.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The structural claim is accurate: backend/tests/Unit holds only ExampleTest.php (assertTrue(true)) and SurahReferenceJuzGapTest.php (4 methods), versus 173 feature-test methods; no test file references ArabicText, ParentResolver, PrimaryTeacherRule or Percentage by class name. However the evidence contains a factual error and the framing overstates the gap: (1) "LoginEmail → 0 files" is wrong — tests/Feature/GeneratedLoginEmailTest.php has 4 tests covering the prefix/code-suffix scheme for teachers, managers and parents. (2) Every listed Support class has behavioural coverage through feature tests that exercise the same code path: ArabicText via PaginationSearchTest::test_normalized_search_matches_hamza_and_ta_marbuta_variants and test_teachers_search_is_normalized_and_paginated; PrimaryTeacherRule via ManagerAddTeacherTest::test_second_primary_is_rejected_with_current_primary_name (asserts the Arabic 422 message); ParentResolver via ManagerAddStudentGuardianTest (9 tests incl. id_number match, unknown id rejection, concurrent-creation rematch) and PhoneNormalizationTest (phone dedup); PhoneNumber::normalize has 7 direct pure assertions (just placed in a RefreshDatabase class). (3) Percentage.php is 16 lines — a trivial helper. So this is a test-organisation / suite-speed observation, not an uncovered-behaviour or correctness defect; nothing in the product misbehaves because of it. For a small single-country team where the feature suite is the stated security guard and is green, "medium" is inflated. Real but low severity; recommendation (move pure assertions to tests/Unit, add table-driven ArabicText tests) remains reasonable hygiene.

```text
backend/tests/Unit: ExampleTest.php (1 boilerplate test), SurahReferenceJuzGapTest.php (4 tests). Feature: 173 methods across 37 files. Auditor's "LoginEmail → 0 files" is incorrect: backend/tests/Feature/GeneratedLoginEmailTest.php:27,44,69,81 (4 tests). Indirect behavioural coverage exists for the "untested" classes: ArabicText — tests/Feature/PaginationSearchTest.php:39 (hamza/ta-marbuta normalization), :128 (teacher search normalized); PrimaryTeacherRule — tests/Feature/ManagerAddTeacherTest.php:74-85 (second primary rejected, Arabic message asserted); ParentResolver — tests/Feature/ManagerAddStudentGuardianTest.php:30,54,65,87,108,140 and tests/Feature/PhoneNormalizationTest.php:30 (phone-format dedup); PhoneNumber::normalize — tests/Feature/PhoneNormalizationTest.php:19-27 (7 pure assertions, only misplaced in a RefreshDatabase class). Percentage.php is 16 lines total. Only truly class-level-untested pure code: Percentage::of and direct ArabicText::normalize/sqlNormalize edge tables.
```

</details>

### No line/branch coverage is measured or thresholded; no mutation testing

<a id="no-coverage-measurement"></a>

`no-coverage-measurement` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/phpunit.xml:15-19`, `backend/composer.json:26-41`

**Evidence**

```text
phpunit.xml:15-19 declares `<source><include><directory>app</directory></include></source>` but there is no `<coverage>` element, no threshold, no `--coverage` invocation anywhere (composer scripts lines 26-41 have none), no xdebug/pcov mention in docs, no infection.json. The only coverage number available is the route-level map produced in this audit (65.4% of routes behaviour-tested).
```

**Why it matters**

Without a measured baseline the team cannot see that ReportPdfController, AthmanController, NotificationController and the admin update paths are dark, and cannot stop coverage from eroding as the mobile API surface grows. 'Measured' is the defining property of maturity level 4.

**Recommendation**

Run `php artisan test --coverage --min=70` in CI with pcov; publish the HTML report as a CI artefact; add a route-coverage guard test (iterate Route::getRoutes(), compare with a manifest). Later, add Infection on app/Support to measure assertion strength.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The factual claims check out. backend/phpunit.xml (lines 15-19) declares only `<source><include><directory>app</directory></include></source>`; there is no `<coverage>` element, no `--min`/threshold, and no report configuration. backend/composer.json's `scripts` block (lines 37-52 in the actual file, the auditor's 26-41 is slightly off but the point holds) contains only Laravel's default post-autoload/post-update/post-install hooks — no `test` or coverage script. There is no `.github/` directory, and the only automation file, `.cpanel.yml`, is a pure rsync deploy that never runs the test suite, so no coverage gate exists anywhere. No `infection.json` exists, and grep for coverage/xdebug/pcov across README.md, DEPLOYMENT.md, DEPLOY_LOG.md, CLAUDE.md returns nothing. I could not run C:\xampp\php\php.exe from this sandbox to confirm whether pcov/xdebug is even installed, which slightly undermines the "S effort" recommendation but does not affect the finding itself.

However, the finding is over-rated. It describes a missing metric/process, not a defect or a behavioural risk: the repo already has a substantive guard (37 feature-test files + 2 unit tests covering the role/ability matrix, ownership, scoping, status toggles, OTP, imports, etc.), and on a thin-controller Laravel API a line-coverage number adds little beyond the route-level map the auditor already produced. The "65.4% of routes behaviour-tested" figure is the auditor's own construction and cannot be independently confirmed. The more consequential gap — that no CI runs the tests at all — is a separate finding; a coverage threshold is meaningless until that exists. For a small, single-country team with no self-registration and a manual-run test suite, this belongs at low: a maturity/observability item, not a medium risk.

```text
backend/phpunit.xml:15-19 — `<source>` block present, no `<coverage>` element or threshold anywhere in the file. backend/composer.json:37-52 — `scripts` contains only post-autoload-dump / post-update-cmd / post-root-package-install / post-create-project-cmd; no test or coverage script. .cpanel.yml:1-7 — deploy-only rsync tasks, no test invocation; no .github/ directory exists. backend/tests/Feature contains 37 test files and backend/tests/Unit 2 (existing quality guard). No infection.json; no mention of coverage/xdebug/pcov in README.md, DEPLOYMENT.md, DEPLOY_LOG.md, CLAUDE.md.
```

</details>

### Saturday→Friday week logic and /reports/weekly have no tests

<a id="week-boundary-and-weekly-report-untested"></a>

`week-boundary-and-weekly-report-untested` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/DashboardController.php:71`, `backend/app/Http/Controllers/Api/ReportController.php:94-99`, `backend/routes/api.php:166`

**Evidence**

```text
DashboardController.php:71 `->whereBetween('date', [now()->startOfWeek(\Carbon\Carbon::SATURDAY), now()->endOfWeek(\Carbon\Carbon::FRIDAY)])` and ReportController.php:94-95 `$startOfWeek = now()->startOfWeek(\Carbon\Carbon::SATURDAY); $endOfWeek = now()->endOfWeek(\Carbon\Carbon::FRIDAY);`. `grep -rln 'startOfWeek\|SATURDAY' tests/` → empty; GET /reports/weekly has zero hits; `Carbon::setTestNow` is used in exactly one test file (TeacherStudentDetailsTest.php:554). CLAUDE.md Gotchas warns 'new week-scoped code must do the same' but nothing verifies it.
```

**Why it matters**

The center's weekly test day is Saturday; if a future refactor uses default Monday weeks, Saturday records fall into the wrong week and the teacher's weekly report and dashboard 'this week' stat silently mis-count — exactly the numbers a mobile teacher screen will show.

**Recommendation**

Add a WeeklyBoundaryTest that pins `Carbon::setTestNow` to a Saturday 00:10 and a Friday 23:50 (Africa/Tripoli), seeds attendance on Fri/Sat/Sun, and asserts /reports/weekly and /dashboard weekly stat include exactly Sat..Fri records; assert startOfWeek/endOfWeek strings in the payload.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The finding is factually accurate. Verified: backend/app/Http/Controllers/Api/DashboardController.php:71 and ReportController.php:94-95 both use now()->startOfWeek(Carbon::SATURDAY)/endOfWeek(Carbon::FRIDAY) explicitly (Carbon 3 supports the per-call argument, so the current behaviour is correct). Grep of backend/tests/Feature for startOfWeek|SATURDAY|reports/weekly|this_week returns nothing; the only Carbon::setTestNow usage is TeacherStudentDetailsTest.php:113, which tests the /students/{id}/day default-date timezone, not week boundaries. RoleMatrixTest covers /api/weekly-tests (a different resource) and ReportsActiveCentersTest hits /api/dashboard only for center counts, not this_week_memorizations. So GET /reports/weekly has zero test coverage and the Sat→Fri rule is protected only by the CLAUDE.md Gotcha comment. However, the severity is overstated for a correctness lens: the production code is currently correct, there are exactly two call sites (both already correct and commented in Arabic), and the impact is purely hypothetical regression risk from a future refactor. This is a test-coverage gap with no present defect, so it rates low rather than medium. The recommendation (a WeeklyBoundaryTest with setTestNow on Saturday 00:10 / Friday 23:50 Tripoli time) is sound and cheap.

```text
backend/app/Http/Controllers/Api/DashboardController.php:70-72 (this_week_memorizations whereBetween startOfWeek(SATURDAY)..endOfWeek(FRIDAY)); backend/app/Http/Controllers/Api/ReportController.php:93-95,99,108,117-118 (weekly report uses same bounds and returns startOfWeek/endOfWeek strings); backend/routes/api.php:166 (GET /reports/weekly, teacher gate). Tests: grep -n "startOfWeek\|SATURDAY\|reports/weekly\|setTestNow\|this_week" backend/tests/Feature/*.php → only TeacherStudentDetailsTest.php:25,113 (setTestNow for /students/{id}/day default date, unrelated to week bounds). No test file exercises /reports/weekly or the dashboard weekly stat.
```

</details>

### Zero automated tests for the 33-page web client; QA is manual screenshots

<a id="no-frontend-or-e2e-tests"></a>

`no-frontend-or-e2e-tests` · 🟡 medium · ✅ confirmed · **LATER** · effort L (1–2 weeks)

**Files:** `frontend-html/js/api.js`, `frontend-html/js/ui.js`, `frontend-html/js/auth.js`, `screenshots/`

**Evidence**

```text
`find frontend-html -iname '*test*' -o -iname '*spec*'` → only teacher/weekly-tests.html (a page, not a test). No package.json, no Playwright/Cypress/Vitest config. Frontend totals 7,043 LOC (html+js+css) across 33 HTML pages, with ~4,192 lines of inline `<script>` page logic. `screenshots/` holds 36 PNGs (01-landing … 44-login-demo-accounts), all dated 2026-09-14 — a manual visual-QA artefact, not an automated run.
```

**Why it matters**

api.js owns the auth-token attach and 401→login redirect, ui.js the formModal/xlsx import/athman autocomplete used by every role. A regression there is invisible to the PHP suite. Once dozens of centers' managers use the web admin daily, a broken modal or a lost redirect is an outage that no gate would have caught.

**Recommendation**

Add a thin Playwright smoke suite (login as each of the 4 demo roles, load each dashboard, one create flow each) run in CI against `php artisan serve` + `php -S`; unit-test the pure helpers in js/ (UI.todayStr, phone/Arabic-digit normalisation) with Vitest. Keep the screenshot folder but generate it from Playwright so it is reproducible.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Evidence verified on disk: `find frontend-html -iname '*test*' -o -iname '*spec*'` returns only frontend-html/teacher/weekly-tests.html (a page); no Playwright/Cypress/Vitest/Jest config, no *.spec.js/*.test.js anywhere outside vendor; 33 HTML pages and 7,043 LOC (html+js+css) match exactly; screenshots/ holds 36 PNGs all timestamped 2026-09-14 20:17 and committed only in the initial snapshot — a manual artefact. The only package.json is backend/package.json, which is the stock Laravel Vite/Tailwind scaffold with no test runner (so "no package.json" is slightly imprecise but the substance — no JS test tooling — holds). backend/tests/Feature (38 files) exercises the API only; nothing (no Dusk/Panther) drives the browser client. The load-bearing client paths the auditor names exist (api.js:51-57 401 handling, a duplicate 401 path in ui.js:358, ui.js:131 todayStr) and are untested. Corroborating the impact claim: the most recent commit 37313bf fixes a frontend regression ("undefined" rendered when admin opened teacher/ pages) that the PHP suite could not have caught. There is also no CI at all (no .github/), so the gap is real. Severity: medium is fair — not a security or data-integrity defect, but the client is the only way managers/teachers operate the system and its shared modules have zero automated coverage. Recommendation caveat: the suggested "run in CI" presupposes a CI pipeline that does not yet exist.

```text
frontend-html/js/api.js:50-57 (401 → clear token + redirect, untested); frontend-html/js/ui.js:358 (second, separate 401 handler in xlsx import path); frontend-html/js/ui.js:131 (todayStr); backend/package.json contains only vite/tailwind devDependencies and build/dev scripts — no test runner; no .github/ directory (no CI); screenshots/ 36 PNGs dated 2026-09-14 20:17, tracked since initial commit be47c33 only; git commit 37313bf is a recent frontend-only regression ("undefined" rendered on teacher/ pages) not catchable by backend/tests/Feature.
```

</details>

### Several tests depend on wall-clock date, MySQL strict mode, or the login throttle

<a id="flaky-tolerances-and-env-coupling"></a>

`flaky-tolerances-and-env-coupling` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `backend/tests/Feature/AdminCenterDetailsTest.php:359-383`, `backend/tests/Feature/WeeklyTestUpdateTest.php:821-831`, `backend/tests/Concerns/CreatesCoreData.php:88-100`, `backend/routes/api.php:16-17`, `backend/tests/Feature/ManagerTeacherPerformanceTest.php:857`

**Evidence**

```text
AdminCenterDetailsTest.php:383 `$this->assertContains($r->json('data.stats.attendance_percent'), [75, 100]);` with comment 'قد يقع الأمس في شهر سابق أول الشهر' — the assertion was loosened to tolerate month-boundary nondeterminism instead of freezing time. `today()`/`now()` used unfrozen in 7 feature files (AdminCenterDetailsTest, ManagerTeacherPerformanceTest:857 `tests_month`, ManagerReportsScopeTest, ReportsActiveCentersTest, …). WeeklyTestUpdateTest.php:828 relies on `str_repeat('ثمن', 200)` overflowing a VARCHAR(255) to raise an SQL error — only true under MySQL strict mode (non-strict truncates silently → test fails). Every `loginToken()` (CreatesCoreData.php:88-100) goes through `POST /auth/login` which is `throttle:10,1` (api.php:16-17); a test that logs in >10 times would get 429 (CenterManagerTest matrix already uses 4).
```

**Why it matters**

Low today, but each is a latent 'works on my machine' failure in CI (different date, different MySQL sql_mode, parallel runners sharing a limiter key) that erodes trust in the suite — the classic reason teams stop treating red as blocking.

**Recommendation**

Freeze time with `Carbon::setTestNow` in every test that seeds `today()` data and assert exact values; assert `sql_mode` contains STRICT_TRANS_TABLES in a setUp guard or trigger the rollback via a mocked exception instead of column overflow; in CreatesCoreData use `RateLimiter::clear()` or `withoutMiddleware(ThrottleRequests)` for `loginToken()` while keeping AuthLoginTest's explicit throttle test.

### Only a boilerplate UserFactory (unused); CreatesCoreData is the de-facto builder and generates non-Libyan phone numbers

<a id="factories-absent-builder-quirks"></a>

`factories-absent-builder-quirks` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `backend/database/factories/UserFactory.php:26-33`, `backend/tests/Concerns/CreatesCoreData.php:21-33`, `backend/tests/Concerns/CreatesCoreData.php:60-73`

**Evidence**

```text
`ls database/factories` → UserFactory.php only; `grep -rn 'factory(' tests/` → no usages. UserFactory.php:26-33 is Laravel's default (`email_verified_at`, `remember_token`, no `role`, `center_id`, `type`, `display_code`). CreatesCoreData.php:27 builds phones as `'09100000' . str_pad($seq, 3, '0', STR_PAD_LEFT)` → 11 digits (e.g. 09100000001), violating the 10-digit `09xxxxxxxx` format enforced by PhoneNumber::normalize; models are created via `::create` so validation is bypassed. Static `$seq`/`$sseq`/`$rseq` counters (lines 23, 62, 87) make emails/codes order-dependent.
```

**Why it matters**

No Student/Center/Attendance/Memorization/WeeklyTest factories means every new test hand-writes 6-10 field arrays (visible in ManagerReportsScopeTest.php:579-594), raising the cost of adding the ~40 missing tests; invalid phone fixtures mean the suite never exercises realistic normalised data for search/OTP paths by default.

**Recommendation**

Replace/extend UserFactory with role states (`->admin()`, `->teacher($center)`, `->manager($center)`, `->parent()`), add Center/Student/Attendance/Memorization/WeeklyTest factories, keep CreatesCoreData as a thin façade over them, and fix the phone generator to 10 digits.

### DisplayCode atomicity and PrimaryTeacherRule lock are only tested sequentially

<a id="concurrency-only-sequential"></a>

`concurrency-only-sequential` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `backend/app/Support/DisplayCode.php:55-70`, `backend/app/Support/PrimaryTeacherRule.php:17-33`, `backend/tests/Feature/DisplayCodeTest.php`, `backend/tests/Feature/ManagerAddTeacherTest.php:634-659`

**Evidence**

```text
DisplayCode.php:59-62 `UPDATE code_sequences SET value = LAST_INSERT_ID(value + 1) WHERE name = ?` then `SELECT LAST_INSERT_ID()` — correct per-connection pattern, but DisplayCodeTest.php only asserts S1/S2/S3 ordering in one connection; ManagerAddTeacherTest.php:634-659 checks preview-does-not-reserve and sequential codes. PrimaryTeacherRule.php:17-33 is a plain `exists()` check (the manager path adds lockForUpdate in CenterManagerController) with no test of two simultaneous primary inserts. The only race test in the repo is ManagerAddStudentGuardianTest.php:166-191 (ParentResolver), done via a `creating` hook.
```

**Why it matters**

Codes are printed on fingerprint-device enrolments and must never collide; with dozens of centers adding students concurrently via the mobile app, a regression to a non-atomic read-then-write would only be found in production.

**Recommendation**

Add a DB-level concurrency test that opens a second PDO connection (DB::connection('mysql_2') pointing at mutqin_test) and interleaves `DisplayCode::next()` / primary-teacher inserts to assert uniqueness and one 422; or at least a unit test asserting the exact SQL uses LAST_INSERT_ID (so a refactor to SELECT+UPDATE fails).

## Measured facts

| Metric | Value |
|---|---|
| Feature test files | 39 (38 substantive + ExampleTest boilerplate); CLAUDE.md:25 claims 20 |
| Unit test files | 2 (1 substantive: SurahReferenceJuzGapTest with 4 tests; ExampleTest boilerplate) |
| Test methods | 178 total (173 feature, 5 unit) |
| Assertions (static count of assert*( calls) | 958 |
| Test LOC vs app LOC | 4,641 vs 7,072 (ratio 0.66) |
| API routes enumerated | 107 (routes/api.php incl. apiResource expansion) |
| Routes with zero test hits | 34 (31.8%) |
| Routes with status-code-only coverage | 3 (GET /attendance, GET /weekly-tests, GET /parent/children) |
| Routes with behavioural assertions | 70 (65.4%) |
| PDF endpoints tested | 1 of 9 |
| Ownership/scope guards in code with no test | 5 |
| Controllers | 19; AthmanController 0/3 routes tested, NotificationController 1/3, ReportPdfController 1/9 |
| Support classes with unit tests | 1 of 8 (SurahReference); ArabicText/ParentResolver/PrimaryTeacherRule/LoginEmail/Percentage have 0 direct tests |
| Model factories | 1 (UserFactory, Laravel boilerplate, 0 usages in tests) |
| CI configuration files | 0 (.github, .gitlab-ci.yml, Jenkinsfile, .circleci, azure-pipelines, bitbucket-pipelines all absent) |
| Static-analysis / lint configs | 0 (pint in require-dev but no pint.json; no phpstan/larastan/psalm) |
| Coverage measurement | none configured (no <coverage> in phpunit.xml, no --coverage script, no threshold) |
| Frontend tests | 0 across 7,043 LOC / 33 HTML pages / ~4,192 inline script lines; no test tooling |
| Manual QA artefacts | 36 PNG screenshots in screenshots/ (all dated 2026-09-14) |
| Tests using Carbon::setTestNow | 1 file; 7 feature files use unfrozen today()/now() |
| Commits touching backend/tests | 44 of 224 (19.6%) |
| Migrations | 35 (2 contain raw DB::statement; one is a MySQL-only ALTER TABLE ... MODIFY) |
| Suite execution in this audit | NOT RUN — backend/vendor and .env absent in checkout; C:\xampp\php\php.exe does not exist (Herd PHP 8.2/8.4/8.5 present); MySQL80 service listening on 3306 but credentials unavailable |

## Auditor notes

Suite run: the brief asked to run `artisan test` once if MySQL was reachable. MySQL80 (Windows service) is listening on 0.0.0.0:3306, but this working copy is a fresh clone (all files stamped 2026-09-14 20:17, git clean) with no backend/vendor and no .env, and the documented PHP path C:\xampp\php\php.exe does not exist on this machine; installing dependencies or writing an .env would violate the read-only mandate, so pass/fail counts and duration could not be obtained. Historical evidence that the suite is maintained green: DEPLOYMENT.md:18 and the `test(...)` commit series on 2026-09-11.

Additional minor observations not promoted to findings: (1) tests/Feature/ExampleTest.php and tests/Unit/ExampleTest.php are Laravel boilerplate (the feature one hits GET / on routes/web.php which returns JSON 200 — harmless noise). (2) n8n/attendance-digest.code.js (attendance digest automation) has no tests and no fixture data. (3) Every feature test uses RefreshDatabase (migrate:fresh once per process + per-test transaction, 35 migrations) — acceptable speed strategy; `--parallel` is not configured (no ParaTest in require-dev) so wall-clock will grow linearly as the suite expands. (4) Role matrix nuance: RoleMatrixTest covers admin/teacher/parent on 8 routes and CenterManagerTest adds the manager row; combined they cover all 4×3 'foreign role' combinations for list endpoints, but role gates on athman/*, notifications/*, PDF endpoints and logout are never asserted. (5) Validation coverage is strong where it exists (Arabic 422 messages asserted verbatim for memorization pages/juz, guardian phone/password/id formats, national-id, teacher_id change, email_prefix, date format) but POST /centers, PUT /centers/{id}, PUT /teachers/{id}, PUT /admin/managers/{id} and login-field-missing 422s are unverified. (6) Doc drift observed for this dimension specifically: CLAUDE.md:25 '20 feature-test files' (actual 39), XAMPP-only PHP (Herd present, XAMPP absent), CLAUDE.md test list omits Messaging/AdminUsers/ManagerParents/AdminCenterDetails/TeacherStudentDetails/ManagerChangeTeacher/ManagerTeacherPerformance/WeeklyTestUpdate/MemorizationValidation/GeneratedLoginEmail/ReportsActiveCenters suites; `.env.example` says DB_CONNECTION=sqlite whereas the suite and docs require MySQL. (7) The coverage map was computed by regex over routes/api.php and test string literals; interpolated URIs were normalised, and the three status-only routes and the PUT /admin/managers/{id} false-positive were corrected by manual grep.
