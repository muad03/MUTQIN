# Performance & Scalability

[← Enterprise Audit](../enterprise-audit.md)

**Score 56 / 100** — Significant risk · maturity **L2** · weight 5% · auditor scored 50, judge calibrated to 56

The daily per-role hot paths (teacher/manager/parent lists, messaging, notifications, manager dashboards, teacher-performance and all-time center reports) are consciously written with batched GROUP BY/SUM aggregations, pagination and column-limited selects, and two feature tests actually assert query counts. But the system-wide (admin) paths still contain a textbook N+1 (ReportController::weekly = 2 queries per active student; ReportService::teachersPerformance/teacherGroupData/allCentersData = per-teacher/per-student/per-center query fans loading whole month collections into PHP), memorization progress is recomputed from every raw row on every request, 20 whereMonth/whereYear filters make the largest tables unindexable, attendances.date / memorizations.date / weekly_tests.exam_date carry no index, there is zero caching, zero queueing, no per_page contract, an uncapped ?all=1 escape hatch, and the authenticated API has no rate limiter at all (bootstrap/app.php never calls throttleApi()). Production is shared cPanel without SSH and the deploy script deletes bootstrap/cache without regenerating config/route caches. At 50 centers / 5,000 students / 500 concurrent mobile users the admin weekly report, admin PDFs, admin students-progress and a monthly fingerprint import would each exceed a 30 s shared-hosting execution limit, and unthrottled report endpoints are an availability risk. That is "significant risk" (40–59) rather than "functional but needs work", because the failure modes are hard timeouts and memory exhaustion, not slowness.

> **Calibration:** The lead critical (weekly-report N+1) was corrected to low/medium and three of five highs to low, so the verifier rejected the 'hard timeouts at 5,000 students' framing that drove the score; the confirmed N+1 (verified at ReportController.php:98-109), missing date indexes and no API throttle keep it in the band but near its top.

## What is already strong

- Batched SQL aggregation is the norm in newer code: CenterManagerController::teacherPerformance (lines 312-325) and ReportService::centerManagement/aggregateAllTime/teacherAllTime (lines 318-323, 415-424, 489-494) use selectRaw COUNT/SUM ... GROUP BY student_id keyed collections instead of per-student queries; ReportService::atRiskStudents (218-228) and StudentController::parentChildren (703-707) were explicitly de-N+1'd (comment: «استعلامان مجمّعان للجميع (بدل استعلامين لكل طالب — N+1)»).
- MessageController::threads (lines 82-88) resolves unread counts and last-message-per-thread with two grouped queries (MAX(id) subquery) and the messages table has purpose-built composite indexes: `$table->index(['student_id', 'id'])` and `$table->index(['student_id', 'sender_role', 'read_at'])` (2026_08_22_100000_create_messages_table.php:25-26).
- Pagination is applied on every primary list (14 paginate() calls: students 20, teachers 20, memorizations 15, weekly-tests 15, centers 10, attendance review 20, admin users 20, parents 20; parent child details use three independent 5-row paginators memo_page/att_page/tests_page).
- Column-limited fetches are common (66 ->select/->get([...])/->first([...])/pluck sites), e.g. `->get(['id', 'name', 'display_code', 'age'])` in TeacherController::show:114 and `with('center:id,name')` / `with(['student:id,name,display_code,national_id', 'teacher:id,name'])` in CenterManagerController::attendanceIndex:505.
- Two feature tests guard query counts: CenterStatusTest::test_center_details_include_teachers_without_n_plus_1 (DB::enableQueryLog at line 123) and ManagerReportsTest (lines 98-107, `assertLessThanOrEqual(5, $scopedCount)`), showing N+1 regression is at least partially measured.
- Display codes are reserved with a single atomic `UPDATE code_sequences SET value = LAST_INSERT_ID(value + 1)` (DisplayCode::next, lines 53-57) — no SELECT-then-INSERT race and no table lock.
- Attendance bulk store is set-based: one whereIn fetch of students, one whereIn fetch of existing rows, then a transaction (AttendanceController::store lines 59-116); attendances has unique(student_id,date) enforcing idempotent upserts.
- Frontend is light: 1.6 MB total, 53 KB of hand-written JS, gallery images use loading="lazy" (index.html:313), and the API wrapper is a 3.4 KB fetch shim with no framework payload.

## Level-5 target state

Every endpoint has a bounded cost independent of system size: lists are paginated with a clamped per_page, aggregates are single grouped SQL statements or reads from materialized/cached summaries (student_progress, cached dashboard counters), and no request touches more rows than one center's month. Date columns are indexed and queried sargably; heavy work (PDF rendering, xlsx import, digests) runs in queued jobs with status endpoints, driven by a cron-based scheduler that also prunes tokens and notifications. The API is rate-limited per user with published 429 semantics, responses are shaped by resources with ETags, and the deploy pipeline ships config/route/view caches, optimized autoloader and OPcache. Performance is measured, not assumed: query-count assertions on every list/report endpoint, a 5k-student load fixture with p95 budgets in CI, and slow-query/APM telemetry on production feeding a regression dashboard.

## What the Flutter team must know

The mobile team must build against the following facts: (1) there is currently no rate limit — expect one to be introduced (target ≈120 req/min per user, stricter on PDF/report routes) and implement 429 + Retry-After handling and request de-duplication now; (2) page size is fixed server-side (students/teachers/attendance/users/parents 20, memorizations/weekly-tests 15, centers 10, parent child sub-lists 5) with no per_page; `?all=1` returns a bare array while the default returns Laravel's paginator object — two shapes for the same endpoint; (3) dashboards and /public/stats are uncached aggregates — avoid refreshing on every foreground, throttle pull-to-refresh, and never poll them; (4) notifications are polled (web uses 60 s); on mobile use ≥3–5 min with background suspension until push (FCM) exists, and expect the notifications endpoint to gain an ETag/unread-count shortcut; (5) PDF endpoints return `application/pdf` inline after a synchronous multi-second render — use a 60 s timeout, a visible progress state, and plan for a future async job-id/download-URL contract; (6) do not wire admin screens to `GET /reports/weekly` (without center_id), `GET /memorizations/students-progress`, `GET /students?all=1` or `GET /attendance` (admin, no center_id) until they are bounded — they will time out or return multi-MB payloads at target scale; (7) list payloads embed full teacher/center models — budget 3–4 KB per student row and expect fields to be trimmed when API resources arrive; (8) each login mints a new 7-day token and old ones are not revoked — call /auth/logout on sign-out and expect 401 after password change/deactivation (all tokens revoked); (9) production is shared cPanel behind /backend/public/api with a small PHP worker pool — batch screen loads (Promise.all of ≤3 requests) and implement exponential backoff on 503/508.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [Admin weekly report issues 2 queries per active student (10,001 queries at 5,000 students)](#weekly-report-n-plus-1) | 🔴 critical<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [20 whereMonth/whereYear + 4 whereDate filters on tables with no date index → full scans on the largest tables](#non-sargable-date-filters-and-missing-date-indexes) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Authenticated API has no rate limiter — heavy report/PDF endpoints can be hammered without limit](#no-api-rate-limiting) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [PDF report data builders fan out per teacher / per student / per center and load whole month row-sets into PHP](#report-service-per-teacher-fanout) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | M |
| [Memorization progress is recomputed from every raw memorization row on every request (admin scope = whole system)](#progress-recomputed-from-raw-rows) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Fingerprint xlsx import loads the whole sheet and runs 3–5 queries per row plus 2 per student-per-date inside one transaction](#xlsx-import-per-row-queries-single-transaction) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | M |
| [`?all=1` returns entire tables with eager-loaded relations; no per_page parameter or cap; several list endpoints are unpaginated](#unbounded-all-flag-and-fixed-page-sizes) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | M |
| [Frequently filtered low-selectivity/lookup columns lack indexes (users.role/is_active, attendances.status/date, students composite, notifications.read_at)](#missing-indexes-role-status-progress) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Production runs without config/route cache or documented OPcache/autoloader optimization; the deploy script deletes bootstrap/cache and never regenerates it](#no-deploy-time-optimization) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [No caching anywhere — dashboard counters, public stats, surah list and pick-lists are recomputed on every request](#zero-caching-of-dashboard-and-reference-data) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [60-second notification polling in every tab + Sanctum last_used_at write per request + tokens never pruned or revoked on re-login](#polling-token-write-amplification) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [PDF generation is synchronous mPDF inside the request; no queue worker exists](#synchronous-mpdf-no-queue) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | LATER | M |
| [Unified Arabic search wraps every row in 9 nested REPLACE() calls across 4 columns + 2 correlated subqueries, then paginate() repeats it for COUNT(*)](#normalized-like-search-full-scan) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | LATER | M |
| [List and dashboard responses embed full related models (teacher User, Center) and there are no API Resources](#payload-shaping-full-models) | ⚪ low | ℹ️ informational | NEXT | M |
| [Frontend depends on jsDelivr + Google Fonts via CSS @import, ships un-versioned assets, and has no cache/compression headers or optimized gallery images](#frontend-delivery-third-party-and-cache-headers) | ⚪ low | ℹ️ informational | LATER | S |

### Admin weekly report issues 2 queries per active student (10,001 queries at 5,000 students)

<a id="weekly-report-n-plus-1"></a>

`weekly-report-n-plus-1` · 🔴 critical (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/ReportController.php:85-111`

**Evidence**

```text
Line 91 `$students = $studentsQuery->get();` (admin without center_id = every active student), then line 97 `$data = $students->map(function ($student) ... { $attendances = Attendance::where('student_id', $student->id)->whereBetween('date', [...])->get(); ... 'memorizations' => Memorization::where('student_id', $student->id)->whereBetween(...)->count(); })`. Each iteration runs one SELECT * on attendances and one COUNT on memorizations and returns the full Student model per row.
```

**Why it matters**

At 5,000 students the request executes ~10,000 round-trips on shared MySQL (≈1–2 ms each) — 10–20 s of pure query latency plus a multi-MB payload, comfortably past a cPanel max_execution_time of 30 s. First endpoint to hard-fail at target scale; no frontend page currently consumes it (grep of frontend-html finds no 'reports/weekly'), so it is a latent trap for a mobile admin screen or an n8n job.

**Recommendation**

Replace the loop with two grouped queries over the student id set (attendances: student_id, SUM(status='present'/'absent'/'late'); memorizations: student_id, COUNT(*)) keyed by student_id — the exact pattern already used in ReportService::atRiskStudents — and paginate the students or require center_id for admins. Add a query-count assertion test like ManagerReportsTest.

<details><summary>Verification — 2 independent reviewers</summary>

**Upheld** · confidence 85% · corrected severity: low

The code is exactly as quoted. backend/app/Http/Controllers/Api/ReportController.php:85-91 builds `Student::where('is_active', true)` and only narrows it when the caller is a non-admin (teacher_id) or when an admin passes `center_id`; an admin with no center_id gets every active student. Lines 97-111 then run, per student, one `Attendance::where('student_id', ...)->whereBetween(...)->get()` and one `Memorization::where(...)->count()`, and return the full `$student` model in each row. That is a genuine 1+2N pattern with no eager loading, no grouping, no pagination. The route is gated `teacher` (routes/api.php:120,166), which admins pass, so the unscoped path is reachable. No test in backend/tests/Feature exercises /reports/weekly and no query-count guard exists. ReportService (lines 220-227, 320-323, 490-494) already uses the grouped SUM/COUNT + groupBy(student_id) pattern the auditor recommends, so the fix is cheap and consistent with the codebase.

However the severity is overstated. (1) No client calls the endpoint at all: grep of frontend-html for 'reports/weekly' returns nothing (teacher and manager report pages use /manager/reports/* and the PDF routes), and the latest commit even removed admin access to teacher/ pages, so nothing sends an admin token there. It is dead API surface today, not a live hot path. (2) The teacher path (the only realistic caller) is bounded to that teacher's own active students — tens of rows, tens of queries, well under any timeout. (3) The per-student queries hit indexed columns (attendances has a unique (student_id,date) index, migration 2024_01_01_000030:22), so each round-trip is sub-millisecond on the local XAMPP/cPanel MySQL; 5,000 students is also far beyond this single-country Quran-center product's plausible scale (dashboard counts are in the hundreds). The "first endpoint to hard-fail at target scale" claim is a hypothetical about an unused endpoint. Real defect, correct diagnosis and recommendation, but a latent code-quality/perf debt item rather than a critical production risk.

```text
backend/app/Http/Controllers/Api/ReportController.php:85-91 (admin without center_id → all active students), :97-111 (per-student Attendance::get + Memorization::count inside map; full Student model per row). backend/routes/api.php:120 + :166 — route lives in the `teacher` middleware group, which admins pass. frontend-html: no reference to 'reports/weekly' anywhere (manager/reports.html only calls /manager/reports/*). backend/tests/Feature: no test covers ReportController@weekly. Existing grouped-aggregate pattern to reuse: backend/app/Services/ReportService.php:220-227, 320-323, 490-494. Index mitigating per-query cost: database/migrations/2024_01_01_000030_create_attendances_table.php:22 unique(['student_id','date']).
```

---

**Upheld** · confidence 80% · corrected severity: medium

Evidence confirmed verbatim: backend/app/Http/Controllers/Api/ReportController.php:85-111 loads every active student (admin without center_id) and, per student, runs Attendance::where(...)->get() and Memorization::where(...)->count() — a textbook 2N+1 pattern, and it serializes the full Student model per row. No mitigation exists in the code: the route (routes/api.php:166) sits in the plain `teacher` gate with no throttle, pagination, required center_id, or time limit, and no Feature test covers `/reports/weekly` at all (grep of backend/tests finds none; the query-count guards in ManagerReportsTest/CenterStatusTest cover other endpoints). ReportService::atRiskStudents (lines 218-227) already uses the grouped whereIn/selectRaw/groupBy pattern, so the fix is cheap and idiomatic for this repo. However, the CRITICAL rating is not materially justified: (1) grep of frontend-html confirms zero consumers of `reports/weekly` — the auditor concedes it is latent, so there is no current user-facing or business impact; (2) the teacher path is bounded to the teacher's own students (typically tens), so only an admin can trigger the unbounded case, and admins are trusted users, not an abuse vector; (3) the product is a small-team Libyan Quran-center system with a handful of centers — 5,000 active students is a hypothetical target, not an observed scale; (4) even at that scale the failure mode is one slow/timeout report request, not data loss, security exposure, or system-wide outage (php artisan serve/Apache would serialize but not corrupt). Could not run the app to measure actual latency (no vendor/ and no PHP at C:\xampp in this environment), so the 10-20 s figure is unverified extrapolation. Real, worth fixing before any client wires the endpoint, but a latent unused-endpoint N+1 is medium, not critical.

```text
backend/app/Http/Controllers/Api/ReportController.php:85-111 (N+1 confirmed; admin path unbounded, teacher path scoped by teacher_id at line 87). backend/routes/api.php:166 — route in `teacher` gate, no throttle/pagination. No frontend caller: `grep -rn "reports/weekly" frontend-html` returns nothing. No test: `grep -rn "reports/weekly" backend/tests` returns nothing. Fix pattern already present in backend/app/Services/ReportService.php:218-227 (whereIn + selectRaw + groupBy).
```

</details>

### 20 whereMonth/whereYear + 4 whereDate filters on tables with no date index → full scans on the largest tables

<a id="non-sargable-date-filters-and-missing-date-indexes"></a>

`non-sargable-date-filters-and-missing-date-indexes` · 🟠 high (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Services/ReportService.php:39-53`, `backend/app/Http/Controllers/Api/CenterManagerController.php:313-318`, `backend/app/Http/Controllers/Api/CenterController.php:109-119`, `backend/app/Http/Controllers/Api/DashboardController.php:25-30`, `backend/app/Http/Controllers/Api/AttendanceController.php:139-142`, `backend/database/migrations/2024_01_01_000030_create_attendances_table.php:15-22`, `backend/database/migrations/2024_01_01_000040_create_memorizations_table.php:15`, `backend/database/migrations/2024_01_01_000070_create_weekly_tests_table.php:15`

**Evidence**

```text
`grep -rn 'whereMonth\|whereYear' app | wc -l` → 20; `grep -rn 'whereDate(' app | wc -l` → 4 (e.g. attendanceIndex:513 `$query->whereDate('date', $request->date)`, teacherDay:466-470). MySQL cannot use an index for `MONTH(date) = ? AND YEAR(date) = ?` or `DATE(date) = ?`. Schema: attendances has only `$table->unique(['student_id', 'date'])` (date is the trailing column, unusable for date-only predicates) and FK indexes; memorizations and weekly_tests have only FK indexes on student_id/teacher_id — no index on `date`/`exam_date`. DashboardController admin branch runs `Attendance::where('date', today())->where('status', 'present')->count()` three times (lines 25-30) with no usable index.
```

**Why it matters**

attendances grows ~5,000 rows/day at target scale (≈1.2M rows/year). Every admin dashboard load becomes 3 full table scans; every month-scoped report/stat (CenterController::stats, CenterManagerController::teacherPerformance, all ReportService month methods) scans the whole table and filters in the engine. On a shared MariaDB 10.4 instance (per DEPLOYMENT.md) this is the steady-state latency killer and will also inflate the 60-second notification-poll load through lock/IO contention.

**Recommendation**

(1) Migration adding indexes: attendances(date, status), attendances(center_id, date), memorizations(student_id, date), memorizations(date), weekly_tests(student_id, exam_date), weekly_tests(exam_date), users(role, center_id, is_active), students(center_id, is_active), students(teacher_id, is_active). (2) Replace whereMonth/whereYear with `whereBetween('date', [$start, $end])` computed from Carbon::create($year,$month,1)->startOfMonth()/endOfMonth(), and whereDate('date', X) with where('date', X) (columns are already DATE). Ship the SQL as raw ALTER statements per the DEPLOY_LOG convention.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The raw evidence is accurate: `grep whereMonth|whereYear` = 20 and `grep whereDate(` = 4 (verified), and the three migrations create no standalone index on attendances.date / memorizations.date / weekly_tests.date(exam_date) — attendances has only `unique(['student_id','date'])` plus FK indexes. However the central claim ("full scans on the largest tables ... every month-scoped report/stat scans the whole table") is wrong for almost every cited query. Tracing each: ReportService lines 39, 48, 52, 82, 89 are `where('student_id', $id)->whereMonth(...)`; lines 140, 144, 187, 189, 219, 224, 319 and CenterController 109/119, CenterManagerController 313/318, AttendanceController 139-142 are all `whereIn('student_id', $ids)->whereMonth(...)`; all four whereDate calls (CenterManagerController 513 sits under `whereHas('student', center_id=...)`, StudentController 466-470 are `where('student_id', ...)`). In every one of these the optimizer uses the existing student_id FK index (memorizations, weekly_tests) or the leading column of the unique (student_id, date) index (attendances — where the DATE value is even available in the index, so MONTH()/YEAR() is evaluated without a row lookup). Cost is proportional to the rows of the selected student(s), not the table; the non-sargable MONTH/YEAR is a post-filter on an already-narrowed range, not a full scan. Only 5 queries are genuinely unscoped: ReportService::overview lines 382 (system-wide weekly_tests for the month, `->get()`) and 385 (memorizations count), and the three admin-dashboard `Attendance::where('date', today())->where('status', ...)->count()` calls (DashboardController 25-30) — those are sargable but no index leads with `date`, so on MariaDB 10.4 they are full scans. That is a real but narrow issue. The scale premise is also unsupported: no repo document mentions "5,000 rows/day"; DEPLOY_LOG.md shows production launched with an empty DB and the demo data is one center with 50 students, so attendances grows by tens of rows per day, and three COUNT(*) scans over a table of that size are sub-10ms. The recommendation is sound hygiene (whereBetween + an index on attendances(date,status) and the two date columns), but several proposed indexes duplicate ones that already exist (students.center_id/teacher_id FKs; the (student_id,date) unique already serves the per-student month filters). Real, but overstated from high to low.

```text
Scoped (index-range, not full-scan) — backend/app/Services/ReportService.php:38-39, 47-48, 51-52, 81-82, 88-89, 139-140, 143-144, 186-189, 218-224, 318-319 (all preceded by `where('student_id', ...)` or `whereIn('student_id', $ids)`); backend/app/Http/Controllers/Api/CenterController.php:108-119 (`whereIn('student_id', $activeIds)`); backend/app/Http/Controllers/Api/CenterManagerController.php:312-318 (`whereIn('student_id', $ids)`), 507-513 (`whereHas('student', center_id)` before whereDate); backend/app/Http/Controllers/Api/AttendanceController.php:139-142 (`whereIn('student_id', $studentIds)`); backend/app/Http/Controllers/Api/StudentController.php:466-470 (`where('student_id', $student->id)` before each whereDate). Index available: backend/database/migrations/2024_01_01_000030_create_attendances_table.php:22 `unique(['student_id','date'])`; FK indexes via `foreignId(...)->constrained()` at ..._000040_create_memorizations_table.php:13 and ..._000070_create_weekly_tests_table.php:13. Genuinely unscoped: backend/app/Services/ReportService.php:382, 385 (system-wide month filter on weekly_tests/memorizations); backend/app/Http/Controllers/Api/DashboardController.php:25-30 (3x `Attendance::where('date', today())` with no leading-date index). Scale premise unsupported: backend/DEPLOY_LOG.md:40 (production DB launched empty), :49 (demo = 1 center, 50 students).
```

</details>

### Authenticated API has no rate limiter — heavy report/PDF endpoints can be hammered without limit

<a id="no-api-rate-limiting"></a>

`no-api-rate-limiting` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/bootstrap/app.php:13-20`, `backend/routes/api.php:16-21`

**Evidence**

```text
bootstrap/app.php withMiddleware only registers aliases (`$middleware->alias([...])`) — `grep -c throttleApi bootstrap/app.php` → 0 and `grep -rn RateLimiter app/Providers` → nothing. In Laravel 11 the `api` group only gets `throttle:api` when `Middleware::throttleApi()` is called (framework: `'api' => array_values(array_filter([... $this->apiLimiter ? 'throttle:'.$this->apiLimiter : null, ...]))`). The only throttled routes are `/auth/login` (throttle:10,1), `/auth/forgot-password/request` (5,1) and `/verify` (10,1).
```

**Why it matters**

Any authenticated user (a parent account) can loop `GET /notifications`, and any admin/manager token can loop `/reports/admin/teachers/pdf` or `/manager/reports/center/pdf` (each several seconds of CPU and hundreds of queries). On shared cPanel the account's PHP process cap (typically 10–20 entry processes) is exhausted and every user sees 508/503. A buggy mobile retry loop produces the same outcome accidentally.

**Recommendation**

Call `$middleware->throttleApi()` and define `RateLimiter::for('api', fn ($r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()))` plus a stricter named limiter (e.g. 6/min) applied to all `*/pdf` and report routes. Document the 429 contract (Retry-After) for the Flutter client.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual claims check out. backend/bootstrap/app.php (lines 14-20) only calls `$middleware->alias([...])` — there is no `throttleApi()` call, and `app/Providers/AppServiceProvider.php` has an empty `boot()` with no `RateLimiter::for(...)` definitions (`grep -rn "RateLimiter" app bootstrap` → nothing). In Laravel 11 the `api` middleware group only carries `throttle:api` when `Middleware::throttleApi()` is invoked, so every `auth:sanctum` route is unthrottled. The only throttled routes are exactly the three the auditor lists (routes/api.php:16-21: login 10/1, forgot-password/request 5/1, verify 10/1), and the sole rate-limit test is `AuthLoginTest::test_login_is_throttled_after_10_attempts` (line 34-45) — nothing covers authenticated routes. There are 9 `pdf` routes in routes/api.php, none with any throttle middleware, and no other mitigation (no queue, no caching of report output, no per-user concurrency guard) exists. The frontend's 60s notification poll is a benign client convention, not a server-side guard.

However, "high" overstates it for this product. Exploitation requires a valid Sanctum token; the heavy PDF/report endpoints are admin- and manager-gated (a handful of known, trusted staff), and the parent-reachable endpoints (`/notifications`, `/parent/children`, `/dashboard`) are lightweight indexed queries. A malicious parent looping cheap endpoints or a buggy retry loop is a plausible availability nuisance on a shared host, but it is an authenticated, attributable, small-user-base scenario — standard hardening rather than an exploitable-by-anyone outage vector. The recommendation (throttleApi + a stricter named limiter for `*/pdf` and report routes) is correct and cheap to add. Net: real, unmitigated gap; severity medium.

```text
backend/bootstrap/app.php:14-20 — withMiddleware only registers aliases; no throttleApi(). backend/app/Providers/AppServiceProvider.php:18-21 — empty boot(), no RateLimiter::for definitions. backend/routes/api.php:16-21 — the only three throttled routes (all public auth endpoints). backend/routes/api.php — 9 `/pdf` routes, none carry throttle middleware. backend/tests/Feature/AuthLoginTest.php:34-45 — only rate-limit test, covers login only. Note: vendor/ is not present in the checkout, so framework behaviour was confirmed from Laravel 11 Middleware::getMiddlewareGroups() semantics (throttle:api gated on $apiLimiter) rather than local source.
```

</details>

### PDF report data builders fan out per teacher / per student / per center and load whole month row-sets into PHP

<a id="report-service-per-teacher-fanout"></a>

`report-service-per-teacher-fanout` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Services/ReportService.php:79-128`, `backend/app/Services/ReportService.php:165-204`, `backend/app/Services/ReportService.php:380-400`, `backend/app/Http/Controllers/Api/ReportPdfController.php:94-126`

**Evidence**

```text
teachersPerformance (176-199): `$teachers = User::where('role','teacher')...->get(); $rows = $teachers->map(function ($t) { $students = Student::where('teacher_id', $t->id)->...->get(); $att = Attendance::whereIn('student_id', $ids)->whereMonth('date', $month)->whereYear('date', $year)->get(); $tests = WeeklyTest::whereIn(...)->get(); ... $att->where('status','present')->count() })` — 3 queries and full row hydration per teacher. teacherGroupData (115) `$rows = $students->map(fn ($s) => $this->studentSummaryRow($s, $month, $year));` where studentSummaryRow (81-89) runs 2 `->get()` per student. allCentersData (168) `Center::where('is_active', true)->get()->map(fn ($c) => $this->centerData($c, $month, $year))` — 4 queries per center, centerData (136-144) loads every active student and every attendance/test row of the month. overview (382) `WeeklyTest::whereMonth(...)->get()` to count in PHP.
```

**Why it matters**

These feed synchronous mPDF endpoints (/reports/admin/teachers/pdf, /reports/admin/center/all/pdf, /reports/teacher/pdf). At 300 teachers the teachers PDF runs ~900 queries and hydrates the entire month of attendance (5,000 students × ~22 days ≈ 110k Eloquent models) before rendering — memory_limit (128–256 MB on shared hosting) and 30 s execution time are both at risk, and each click repeats the work.

**Recommendation**

Rewrite teachersPerformance/allCentersData/centerData/overview as grouped aggregates (GROUP BY teacher_id / center_id via JOIN students) returning counts only; reuse studentSummaryRow's math on a single grouped result set. Cache report payloads per (scope, month, year) for e.g. 10 minutes for closed months indefinitely. Add a query-count test at 10+ teachers.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The quoted code exists exactly as described and behaves as claimed. ReportService::teachersPerformance (lines 176-201) issues 3 queries per teacher (students ->get(), Attendance ->get(), WeeklyTest ->get()) and counts in PHP; teacherGroupData (115) calls studentSummaryRow per student, which runs 2 ->get() each (81-89); allCentersData (168) calls centerData per active center, and centerData (135-146) hydrates every active student plus all attendance and test rows of the month; overview (382) hydrates every WeeklyTest of the month just to count. There is no Cache::remember anywhere in app/Services or the report controllers, and no query-count test covers these paths — tests/Feature/ManagerReportsTest.php only asserts a constant query count for atRiskStudents, which (like centerManagement and the *AllTime methods) was already rewritten with grouped SUM/COUNT aggregates, showing the team knows the pattern but did not apply it to these older builders. They feed synchronous mPDF endpoints (ReportPdfController lines 94-126, 147-169) wired from admin/reports.html, manager/reports.html and teacher/reports.html, and centerData is also called from CenterManagerController::reportsSystem (line 618).

However the severity is overstated for this product. The 300-teacher / 5,000-student / 110k-model scenario is hypothetical for a Libyan Quran-center management system; the heavy variants (/reports/admin/teachers/pdf, /reports/admin/center/all/pdf, overview) are admin-only, user-initiated, infrequent clicks, not a public or high-frequency path. attendances has a unique (student_id,date) index so the per-teacher whereIn queries are cheap; the manager variant is center-scoped (tens of students), and teacherGroupData is bounded by one teacher's roster (~20-40 students → ~60-80 small queries). The realistic failure mode is a slow (seconds) PDF at current scale, with memory/timeout only if the deployment grows an order of magnitude beyond a typical regional deployment. Real but medium, not high.

```text
backend/app/Services/ReportService.php:176-201 (teachersPerformance: 3 hydrating queries per teacher); :110-115 + :79-92 (teacherGroupData → studentSummaryRow, 2 ->get() per student); :165-168 + :133-146 (allCentersData → centerData, 4 queries and full month hydration per center); :380-383 (overview loads all WeeklyTest rows of the month to count). Contrast :211-228 (atRiskStudents), :309-323 (centerManagement) and :411-424 (aggregateAllTime) which already use selectRaw COUNT/SUM GROUP BY — the fix pattern exists in the same file. No caching: grep for Cache::/remember( in app/Services and app/Http/Controllers/Api/Report* returns nothing. Only query-count test is tests/Feature/ManagerReportsTest.php:85-108 and it covers atRiskStudents only. Callers: backend/app/Http/Controllers/Api/ReportPdfController.php:98,111,116,124,140,151,167; backend/app/Http/Controllers/Api/CenterManagerController.php:618. Index mitigation: database/migrations/2024_01_01_000030_create_attendances_table.php:22 unique(student_id,date).
```

</details>

### Memorization progress is recomputed from every raw memorization row on every request (admin scope = whole system)

<a id="progress-recomputed-from-raw-rows"></a>

`progress-recomputed-from-raw-rows` · 🟠 high (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/MemorizationController.php:77-121`, `backend/app/Services/ReportService.php:274-299`, `backend/app/Services/ReportService.php:411-433`, `backend/app/Http/Controllers/Api/CenterManagerController.php:322-331`, `backend/app/Support/SurahReference.php:157-197`

**Evidence**

```text
studentsProgress: `$students = $studentQuery->orderBy('name')->get();` (admin → all students, no is_active filter, no pagination) then `$bySurah = Memorization::whereIn('student_id', $students->pluck('id'))->whereNotNull('surah_name')->get(['student_id', 'surah_name'])->groupBy('student_id');` and per student `SurahReference::progress($names)`, which runs `Athman::normalize((string) $s)` (regex) for every row (SurahReference.php:168-173). Same shape in progressSummary (280-283), aggregateAllTime (423-424), centerManagement (322-323), teacherPerformance (322-325). `grep -rn 'SurahReference::progress' app | wc -l` → 7 call sites; `grep -rn Cache:: app` → 0.
```

**Why it matters**

At 5,000 students with ~50 surah records each, the admin variant hydrates ~250k rows and runs ~250k regex normalizations per request (hundreds of MB, seconds of CPU) — a memory_limit fatal on shared hosting. Manager-scoped variants are bounded by center size today but grow linearly with history and are hit on every reports page open and every PDF.

**Recommendation**

Materialize progress: add `student_progress` (student_id PK, completed_count, completed_down_to, reached_juz, last_surah, updated_at) recomputed for the one student inside MemorizationController::store/destroy (and on any surah edit); reports/progress endpoints then read a single indexed row set. Alternatively cache per-student progress with tags and invalidate on write. Paginate studentsProgress and default to active students.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted code exists and behaves as described: MemorizationController::studentsProgress (lines 81-100) loads all students for an admin with no is_active filter and no pagination, hydrates every non-null surah_name Memorization row as Eloquent models, and calls SurahReference::progress which runs ArabicText::normalize (3 preg_replace + str_replace) per row. The same shape is in ReportService::progressSummary/centerManagement/aggregateAllTime. No Cache:: usage anywhere in app/. So the mechanism is real. However the severity rests on the "admin scope = whole system" path, and that path is effectively dead: `grep -rn "students-progress"` over frontend-html returns nothing — no page calls /memorizations/students-progress, and the recent commit 37313bf explicitly blocks admins from teacher/ pages. progressSummary's system-wide (null centerId) branch is likewise never invoked — its only caller is CenterManagerController:620 with the manager's center_id. Every live progress path (centerAllTime, teacherAllTime, studentAllTime, centerManagement, progressSummary) is scoped to one center/teacher/student and filters is_active=true, so the working set is a single center's memorization history (a few hundred students x tens of rows), far below memory_limit. The regex normalization is cheap (~microseconds/row); the "hundreds of MB, seconds of CPU" figure only materializes for the unused admin studentsProgress variant at a scale (5,000 students) this Libyan single-org product is unlikely to reach. It remains a genuine latent scalability debt (an admin token can still hit the unbounded endpoint; manager reports grow linearly with history and are recomputed on every page/PDF open), but not a high-severity production issue.

```text
backend/app/Http/Controllers/Api/MemorizationController.php:81-94 — admin branch unbounded, no is_active filter, Eloquent hydration of all surah rows (confirmed). backend/routes/api.php:159 — route exists under the teacher gate (admin passes it) but `grep -rn "students-progress" frontend-html` → 0 hits: no UI consumer. backend/app/Services/ReportService.php:276-283 — progressSummary filters is_active=true; the only caller is CenterManagerController.php:620 passing $centerId (system-wide null branch never used). ReportService.php:313, 469-470, 481-485, 525 — centerManagement/centerAllTime/teacherAllTime/studentAllTime all scope to one center/teacher/student with is_active=true. backend/app/Support/ArabicText.php:44-57 — normalize is 3 preg_replace + 1 str_replace, not an expensive operation. `grep -rn "Cache::" backend/app` → 0 (confirmed no caching).
```

</details>

### Fingerprint xlsx import loads the whole sheet and runs 3–5 queries per row plus 2 per student-per-date inside one transaction

<a id="xlsx-import-per-row-queries-single-transaction"></a>

`xlsx-import-per-row-queries-single-transaction` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/AttendanceImportController.php:21-35`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:97-105`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:164`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:300-313`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:341-378`

**Evidence**

```text
Line 21 accepts files up to `max:5120` KB; line 33-35 `IOFactory::load(...); $rows = $worksheet->toArray();` (entire sheet in memory — PhpSpreadsheet costs ~1 KB per cell). Line 97 wraps everything in one `DB::transaction`. Per row: line 164 `Student::where('display_code', 'S' . $deviceNum)->first();` then line 300 `Attendance::updateOrCreate([...])` (SELECT + INSERT/UPDATE). Absence pass (341-378): `foreach (array_keys($datesInFile) as $date) { ... foreach ($scopeQuery->get() as $st) { $record = Attendance::firstOrCreate([...]) } }` — re-fetches the scope and runs 2 queries per (date × student). No chunking, no queue (`grep -rn ShouldQueue app` → 0).
```

**Why it matters**

A monthly device export for a 300-student center (≈7,800 rows) issues ≈23k row queries plus ≈15k absence queries in a single transaction holding row locks — 40–80 s on shared MySQL, past a 30 s limit, so the import fails and rolls back every time; a 5 MB sheet can itself exceed a 128 MB memory_limit during toArray(). Concurrent imports from several centers serialize on the same tables.

**Recommendation**

Pre-load students of the caller's scope once (`Student::where(center_id)...->get()->keyBy('display_code')`), batch rows into `Attendance::upsert([...], ['student_id','date'], [...])` chunks of 500, compute absences as one INSERT ... SELECT per date (students in scope NOT IN seen ids) with ON DUPLICATE KEY IGNORE, read the sheet with a chunked ReadFilter, and move the whole job to a queued Job with a status endpoint the UI/mobile polls. Cap rows (e.g. 10,000) explicitly.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

The quoted code is accurate. backend/app/Http/Controllers/Api/AttendanceImportController.php: line 21 `max:5120`; lines 33-35 `IOFactory::load()` + `toArray()` (whole sheet, no ReadFilter/chunking); line 97 a single `DB::transaction` wrapping both the row loop and the absence pass; line 164 `Student::where('display_code', ...)->first()` per row (no pre-loaded map); line 300 `Attendance::updateOrCreate` per row (SELECT + INSERT/UPDATE); lines 341-378 per-date `$scopeQuery->get()` and per-unseen-student `Attendance::firstOrCreate` (2 queries each). No `ShouldQueue` anywhere in app/, no row cap, no `chunk`/`ReadFilter`/`setReadDataOnly`. The three feature tests (FingerprintImportTest, AttendanceDuplicationTest, ManagerAttendanceReviewTest) only exercise 1-2 row sheets and do not cover volume. Mitigations that do exist: `display_code` is a unique index (2026_07_16 migration) so the per-row student lookup is an indexed point query, and `attendances` has `unique(student_id,date)` so the upsert is correct. However the impact is overstated: (1) the deployment runs `php artisan serve` / PHP CLI where `max_execution_time` is 0, so the asserted "30 s limit -> rolls back every time" does not apply in the documented setup (only under Apache/FPM defaults). (2) The absence-pass count of ~15k assumes every student is absent every day; `firstOrCreate` runs only for students NOT seen in the file (line 356 `continue`), so with normal attendance it is a few thousand queries. (3) Locks are InnoDB row locks on distinct (student_id,date) rows; imports from different centers touch disjoint rows, so "serialize on the same tables" is exaggerated (some unique-index gap-lock contention is possible, not full serialization). (4) A fingerprint device export is per-center and typically daily (code comments: one device per center), i.e. tens to a few hundred rows, which runs in well under a few seconds; the 7,800-row monthly case is plausible but not the normal path. (5) Memory: 7,800 x 5 cells is ~40k cells, far below 128 MB; a deliberately padded 5 MB sheet could exhaust memory, but that is an abuse edge case on an authenticated manager/teacher route. Net: a real N+1/no-chunking/no-cap performance weakness with a cheap fix (pre-load scope students keyed by display_code, chunked upsert, one INSERT...SELECT per date, explicit row cap), but for this product (single-country, small centers, local MySQL, CLI server) it is medium, not high.

```text
backend/app/Http/Controllers/Api/AttendanceImportController.php:33-35 (IOFactory::load + toArray, no ReadFilter); :97 (single DB::transaction around rows + absence pass); :164 (Student::where('display_code')->first() per row — unique-indexed per database/migrations/2026_07_16_100000_add_display_code_columns_and_sequences.php:34); :300-313 (Attendance::updateOrCreate per row); :343-355 (scope query re-executed per date); :356-357 (seen students skipped — absence firstOrCreate only for unseen students, so the ~15k estimate is a worst case); :360-372 (firstOrCreate per unseen student). No row cap, no chunking, no queue (grep ShouldQueue app -> 0). routes/api.php:70 and :156 expose the import to manager and teacher gates only. Tests tests/Feature/FingerprintImportTest.php cover 1-2 row sheets only. CLAUDE.md documents `php artisan serve` (CLI, max_execution_time=0) — the claimed 30 s timeout applies only under Apache/FPM defaults.
```

</details>

### `?all=1` returns entire tables with eager-loaded relations; no per_page parameter or cap; several list endpoints are unpaginated

<a id="unbounded-all-flag-and-fixed-page-sizes"></a>

`unbounded-all-flag-and-fixed-page-sizes` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/StudentController.php:132-137`, `backend/app/Http/Controllers/Api/TeacherController.php:41-45`, `backend/app/Http/Controllers/Api/CenterController.php:21-25`, `backend/app/Http/Controllers/Api/CenterManagerController.php:189-194`, `backend/app/Http/Controllers/Api/AttendanceController.php:17-29`, `backend/app/Http/Controllers/Api/ReportController.php:56-79`, `backend/app/Http/Controllers/Api/StudentRequestController.php:53-69`

**Evidence**

```text
StudentController::index: `if ($request->has('all') && $request->all == 1) { $students = $query->get(); } else { $students = $query->paginate(20)... }` on a query that is `Student::with(['center', 'teacher'])` — for an admin this is every student with a full Center and full User (teacher) model per row. Same switch in TeacherController (41), CenterController (21), CenterManagerController::teachers (191). `grep -rn per_page app | wc -l` → 0. AttendanceController::index admin path `$students = $studentsQuery->get();` (all active students, no pagination) — this is the endpoint the n8n digest calls daily (n8n/README.md). ReportController::missingNationalId and StudentRequestController::managerIndex use `->get()` with no limit. Frontend has 15 `all=1` call sites (grep count) so the flag cannot simply be removed.
```

**Why it matters**

`GET /students?all=1` for an admin at 5,000 students ≈ 5,000 × (student + teacher + center) ≈ 3–4 MB JSON and 15k model hydrations per request; on mobile radio that is seconds of transfer and a memory spike in the client. The two response shapes (array vs paginator object) also force every client to branch (DEPLOYMENT.md §4 already notes this).

**Recommendation**

Introduce a hard cap (e.g. `all=1` → limit 500 and return `meta.truncated`, or restrict `all=1` to scoped callers and slim `select`), accept `per_page` clamped to [5,100] on every paginated endpoint, paginate missingNationalId/managerIndex/attendance index, and unify the envelope so `data` is always `{items, meta}` (or keep Laravel's paginator shape everywhere). Publish this contract to the Flutter team before they build list screens.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The quoted code exists and behaves as described: StudentController::index (backend/app/Http/Controllers/Api/StudentController.php:132-137) does `$query->get()` on `Student::with(['center','teacher'])` when `all=1`, and the same switch is in TeacherController:41-45, CenterController:21-25, and CenterManagerController:190-193. `grep -rn per_page backend/app` returns 0. AttendanceController::index:17-23 does `$studentsQuery->get()` (admin path = all active students, no relations) and the n8n digest logs in as admin@mutqin.ly and hits this endpoint. ReportController::missingNationalId (57-61) and StudentRequestController::managerIndex (53-69) use unbounded `->get()`. No middleware in bootstrap/app.php, no model-level cap, and no test asserts any ceiling on `all=1` (PaginationSearchTest only asserts 20/page for the paginated branch; CenterManagerTest:77 and CenterStatusTest:101-107 exercise `all=1` without size checks). So the finding is factually correct and not mitigated in code.

However, it is over-rated for this product. Auditing the actual frontend call sites (16, in HTML files not .js — the auditor's count is right, the location is not): `/students?all=1` is only issued from teacher/ pages (scoped to the teacher's own students) and `/manager/students?all=1` from manager/requests.html (scoped to one center); the admin students page uses the paginated `/students?` + params (admin/students.html:477). `all=1` for admin is used only on /centers and /teachers, which are pick-list-sized tables (a handful of centers, dozens-to-hundreds of teachers). The 5,000-student admin scenario requires an admin token calling an endpoint the shipped client never calls that way. The attendance admin path returns bare Student rows (no eager loads) once a day to a server-side automation, not to a mobile client. For a single-city Quran-center system in Libya (a few centers, realistically hundreds to low thousands of students), none of these paths produce multi-MB responses today. The dual response shape is a real API-contract wart but already documented in DEPLOYMENT.md §4 and handled by the client. Net: real hygiene/scalability debt worth fixing before a Flutter client relies on these endpoints, but low rather than medium.

```text
Confirmed: backend/app/Http/Controllers/Api/StudentController.php:71 (`Student::with(['center','teacher'])`) and :132-137 (`all=1` → `->get()`); TeacherController.php:41-45; CenterController.php:21-25 (paginate(10) otherwise); CenterManagerController.php:189-194; AttendanceController.php:17-23 (`$studentsQuery->get()`, admin = all active students, no relations); ReportController.php:57-61; StudentRequestController.php:53-69. `grep -rn per_page backend/app` = 0. n8n/mutqin-daily-attendance-digest.json:38 logs in as admin@mutqin.ly and n8n/README.md:13 calls GET /api/attendance. Mitigating context: the 16 `all=1` call sites are in frontend-html/**/*.html (not js/): admin uses it only for /centers and /teachers (admin/centers.html:60, admin/managers.html:59, admin/reports.html:72, admin/students.html:38,344, admin/teachers.html:154); `/students?all=1` appears only in teacher/ pages (memorization.html:47, reports.html:58, students.html:114, weekly-tests.html:138 — own-students scope) and `/manager/students?...&all=1` in manager/requests.html:89 (own-center scope); the admin students grid uses paginated `/students?` (admin/students.html:477). No test caps `all=1` (tests/Feature/PaginationSearchTest.php:16 only checks the 20/page branch). DEPLOYMENT.md:42 already lists unifying the two response shapes as known work.
```

</details>

### Frequently filtered low-selectivity/lookup columns lack indexes (users.role/is_active, attendances.status/date, students composite, notifications.read_at)

<a id="missing-indexes-role-status-progress"></a>

`missing-indexes-role-status-progress` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/database/migrations/0001_01_01_000000_create_users_table.php:17`, `backend/database/migrations/2026_07_23_100000_add_is_active_to_users.php:17`, `backend/database/migrations/2024_01_01_000020_create_students_table.php:18-19`, `backend/database/migrations/2026_07_01_221219_create_notifications_table.php:15-21`, `backend/app/Http/Controllers/Api/DashboardController.php:22-42`, `backend/app/Http/Controllers/Api/CenterManagerController.php:70-71`

**Evidence**

```text
`grep -rn '->index(' database/migrations | wc -l` → 14, of which 3 are athman, 2 messages, 2 student_requests, 2 otp/password logs, rest framework tables; none on users.role, users.is_active, attendances.status, students.is_active, notifications.read_at. Hot predicates: `User::where('role', 'teacher')->withCount('students')->get()` (Dashboard:40), `User::where('role','teacher')->where('type','محفظ أساسي')->where('center_id',$centerId)` (CenterManager:70), `Student::where('center_id', $centerId)->where('is_active', true)->whereNull('teacher_id')->count()` (CenterManager:35), `$user->unreadNotifications()->count()` on every 60 s poll (morph index only covers notifiable_type/id, then filters read_at).
```

**Why it matters**

users will hold ~4,000+ parent rows at target scale, so every `where('role','teacher')` is a scan of the whole users table (dashboards, myCenter, PrimaryTeacherRule inside a lockForUpdate transaction). Individually cheap, collectively they are the per-request floor that the polling load multiplies.

**Recommendation**

Add composite indexes: users(role, center_id, is_active), users(role, type, center_id), students(center_id, is_active, teacher_id), students(teacher_id, is_active), students(parent_id), attendances(date, status), notifications(notifiable_type, notifiable_id, read_at). Verify with EXPLAIN on the dashboard queries against a 5k-student fixture.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Partly correct, materially overstated. The evidence count (`->index(` = 14) is literally true but misleading: it ignores that every `foreignId(...)->constrained()` on MySQL/InnoDB creates an implicit single-column index, and that `morphs()` and `unique()` create indexes too. Verified in migrations: students.center_id, students.teacher_id (2024_01_01_000020:18-19), students.parent_id (2026_06_20_100001:16-19), users.center_id (2026_05_11_100154:12), attendances.student_id/teacher_id/center_id plus UNIQUE(student_id,date) (2024_01_01_000030:13-22), notifications (notifiable_type, notifiable_id) via morphs (2026_07_01_221219:17) are all indexed. Consequences for the auditor's specific claims: (1) The recommendation to add students(parent_id) is redundant — it already exists. (2) The claim that `where('role','teacher')->where('center_id',$centerId)` (CenterManagerController:36, :70, :137 inside lockForUpdate, PrimaryTeacherRule) is "a scan of the whole users table" is wrong — MySQL uses the users.center_id FK index and filters a center's handful of members; parents have center_id NULL so they never enter that range. (3) `Student::where('center_id')->where('is_active')->whereNull('teacher_id')` (CenterManager:35) uses the center_id index, then filters a few hundred rows — fine. (4) The notification poll: morphs index narrows to one user's own rows, then read_at filter over dozens of rows — negligible, so the 60s-poll multiplier argument does not hold. (5) Manager attendance counts (CenterManager:37-38) use whereIn(student_id) + date → served by the UNIQUE(student_id,date) index. What remains genuinely un-indexed: users.role alone (DashboardController:22 and :40, TeacherController:14 — admin-only pages, full scan of users ≈ 4k rows ≈ single-digit ms), and attendances.date/status for the admin dashboard's three `where('date', today())->where('status', X)` counts (DashboardController:25-30) — attendances is the only table that grows unboundedly (5k students × ~250 days/yr), so this is the one predicate that could become noticeable in a few years. I could not run EXPLAIN (PHP/XAMPP not present on this machine at the documented path), so the plan analysis is from MySQL FK/index semantics rather than measured. Net: a real but minor hygiene item (attendances(date,status), optionally users(role)); the broad "missing indexes everywhere" framing and the users-scan/notifications/students(parent_id) sub-claims are incorrect.

```text
Already indexed via FK constraints (InnoDB auto-index) / morphs / unique: backend/database/migrations/2024_01_01_000020_create_students_table.php:18-19 (students.center_id, students.teacher_id); 2026_06_20_100001_add_parent_id_to_students.php:16-19 (students.parent_id — the recommended index already exists); 2026_05_11_100154_update_tables_for_mutqen_v2.php:12 (users.center_id — so CenterManagerController.php:36,70,137 and PrimaryTeacherRule are range-scans on a center's members, not full users scans); 2024_01_01_000030_create_attendances_table.php:13-22 (student_id, teacher_id FK indexes + UNIQUE(student_id,date), which serves CenterManagerController.php:37-38 whereIn(student_id)+date); 2026_07_01_221219_create_notifications_table.php:17 (morphs → (notifiable_type, notifiable_id) index; NotificationController.php:37,59 unreadNotifications count is per-user rows only). Genuinely un-indexed hot predicates, both admin-only: users.role alone — DashboardController.php:22,40; TeacherController.php:14; attendances.date/status — DashboardController.php:25-30 (three counts per admin dashboard load; attendances is the only unbounded-growth table). Frontend poll: frontend-html/js/layout.js:257 setInterval 60000 (confirmed, but hits an indexed per-user lookup).
```

</details>

### Production runs without config/route cache or documented OPcache/autoloader optimization; the deploy script deletes bootstrap/cache and never regenerates it

<a id="no-deploy-time-optimization"></a>

`no-deploy-time-optimization` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `.cpanel.yml:1-6`, `backend/DEPLOY_LOG.md:3-12`, `DEPLOYMENT.md:1-40`

**Evidence**

```text
.cpanel.yml: `- /bin/rm -f $DEPLOYPATH/backend/bootstrap/cache/*.php` with no subsequent `php artisan config:cache`/`route:cache`/`optimize`. DEPLOY_LOG.md: «SSH ... معطَّل من المستضيف ... عملياً بلا SSH / CLI / artisan / composer / git» and «النشر يدوي حصراً عبر cPanel File Manager». DEPLOYMENT.md's pre-production checklist has no item for config/route/view caching, OPcache, `composer install --no-dev --optimize-autoloader`, or a queue/cron worker.
```

**Why it matters**

Every request re-reads ~30 config files and re-compiles routes/api.php (173 lines, ~90 routes); with the Sanctum guard, middleware and Eloquent boot this adds ~30–60 ms of CPU per request before any SQL. At 500 concurrent mobile users with 60 s polling (~8 req/s baseline plus interactive traffic) that is a measurable fraction of a shared-hosting CPU quota and directly reduces how many requests the fixed PHP worker pool can serve.

**Recommendation**

Generate `bootstrap/cache/config.php`, `routes-v7.php`, `packages.php`/`services.php` and compiled views locally with production .env values and upload them with each release (or run `php artisan optimize` via a cPanel cron one-off since cron can execute PHP CLI even without SSH); ship vendor with `--optimize-autoloader --classmap-authoritative`; confirm OPcache is enabled in the cPanel PHP selector; add these as checklist items in DEPLOYMENT.md and fix .cpanel.yml to re-create the cache instead of only deleting it.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 75% · corrected severity: low

Quoted evidence is real: .cpanel.yml:6 deletes bootstrap/cache/*.php after an rsync that excludes bootstrap/cache (lines 4-5), with no artisan step; backend/DEPLOY_LOG.md:6-9 states SSH/CLI/artisan/composer are unavailable and deployment is manual via cPanel File Manager; DEPLOYMENT.md's 9-item checklist has no caching/OPcache/autoloader/cron item, and the repo has zero mentions of config:cache/route:cache/optimize/opcache. So the core claim (production runs with no config or route cache and this is undocumented) stands. However several parts are inaccurate or overstated: (1) packages.php and services.php are NOT 'never regenerated' — Laravel's PackageManifest and ProviderRepository rebuild them automatically on the next request when missing, so the rm only costs one request; only config.php and routes-v7.php genuinely never exist. (2) backend/config/ holds 12 files, not ~30. (3) The 30–60 ms/request CPU estimate is exaggerated for ~90 routes on PHP 8.3 (cPanel/CloudLinux PHP selectors normally ship OPcache on); realistic uncached-bootstrap overhead is single-digit to low-teens ms. (4) backend/composer.json:61 already sets "optimize-autoloader": true, so any composer install of the shipped vendor produces an optimized classmap (only --classmap-authoritative is missing). (5) The 500-concurrent-users scenario is not this product's scale (single-center deployment, demo DB of 50 students, small team); and given no CLI on the server, deleting stale caches is a defensible safety choice — a baked config.php with stale .env values and no way to run artisan is a worse failure mode, and generating it locally requires production secrets on the dev machine. Real but minor ops-hygiene gap; downgrade to low.

```text
.cpanel.yml:5-6 — rsync `--exclude='bootstrap/cache'` then `/bin/rm -f $DEPLOYPATH/backend/bootstrap/cache/*.php`, no regenerate step (confirmed). backend/DEPLOY_LOG.md:6-9 — no SSH/CLI/artisan on host, manual File Manager deploys (confirmed); DEPLOY_LOG.md:15 lists bootstrap/cache/ as a server-side folder not to be overwritten. DEPLOYMENT.md:7-17 — 9-item checklist, no cache/OPcache/autoloader item (confirmed). Corrections: backend/config/ contains 12 files (app, auth, cache, cors, database, filesystems, logging, mail, queue, sanctum, services, session), not ~30. backend/composer.json:61 `"optimize-autoloader": true` already present. packages.php/services.php are auto-rebuilt by Laravel on first request when absent; only config.php and routes-v7.php are truly never generated. Commit 9831f1c shows .cpanel.yml targets cPanel Git Version Control (UI-driven), which conflicts with DEPLOY_LOG's 'manual only' statement — it may be dormant.
```

</details>

### No caching anywhere — dashboard counters, public stats, surah list and pick-lists are recomputed on every request

<a id="zero-caching-of-dashboard-and-reference-data"></a>

`zero-caching-of-dashboard-and-reference-data` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/DashboardController.php:19-42`, `backend/app/Http/Controllers/Api/DashboardController.php:124-134`, `backend/app/Http/Controllers/Api/CenterManagerController.php:23-46`, `backend/app/Http/Controllers/Api/MemorizationController.php:229-236`, `backend/config/cache.php:18`

**Evidence**

```text
`grep -rn 'Cache::\|->remember(\|cache()' app/ routes/` → no matches. Admin dashboard executes 6 COUNTs + `Student::with(['teacher','center'])->latest()->take(5)->get()` + `User::where('role','teacher')->withCount('students')->get()` (all teachers, unbounded) per load; publicStats (unauthenticated landing page) runs 3 COUNTs per hit; manager dashboard runs 7 queries including `Student::where('center_id', $centerId)->pluck('id')` fed into three whereIn. `'default' => env('CACHE_STORE', 'database')` — even if caching were added, the default store would be MySQL.
```

**Why it matters**

Dashboards are the most frequently opened screens in a mobile app (every foreground). 500 concurrent users refreshing dashboards plus the public landing page means thousands of identical aggregate queries per minute against tables that lack the indexes above; a stampede on the unauthenticated /public/stats needs no login at all.

**Recommendation**

Cache::remember the admin/public counters for 60 s and the manager dashboard per center for 30 s; cache `/memorizations/surahs`, `/centers?all=1&active=1` and `/manager/centers` for minutes with invalidation on write; use the `file` store (fast on cPanel, no extra DB round-trips) or Redis if the host offers it; add ETag/Cache-Control on GET pick-lists so mobile clients can 304.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The factual claims hold. `grep -rn 'Cache::\|->remember(\|cache()\|Cache-Control\|ETag' app/ routes/ bootstrap/` returns nothing; config/cache.php:18 defaults to the `database` store and .env.example pins CACHE_STORE=database. DashboardController@index (lines 19-42) really runs 6 COUNTs + a 5-row recent-students query + `User::where('role','teacher')->withCount('students')->get()` with no limit; publicStats (124-134) runs 3 COUNTs and the route (routes/api.php:22) has no throttle middleware (only login/OTP routes are throttled); CenterManagerController@dashboard (23-46) does the pluck-then-whereIn pattern. The only relevant index on attendances is the unique (student_id, date) — date is the second column, so the `date = today()` filters in both dashboards cannot use it. No mitigations exist (no HTTP cache headers, no reverse-proxy config, frontend does not cache; layout.js polls /notifications every 60 s, not the dashboard). However the severity is overstated for this product: it is a single-country Quran-center management tool with dozens of centers and at most a few hundred users; the queries are trivial COUNTs on small tables that MySQL serves in well under a millisecond each, and the "500 concurrent users refreshing dashboards" scenario is hypothetical. The one genuinely exposed point is the unthrottled, unauthenticated /public/stats and /public/demo-accounts, which is a cheap DoS amplifier but still only 3 small COUNTs. Real but low-impact at the actual scale; a `throttle` on the public routes and a 60 s Cache::remember would be trivial hygiene rather than a medium-severity defect.

```text
backend/routes/api.php:22-23 — `/public/stats` and `/public/demo-accounts` are registered with no throttle middleware (only lines 17, 20, 21 carry `throttle:`); backend/app/Http/Controllers/Api/DashboardController.php:22-42 — 6 COUNTs + unbounded `User::where('role','teacher')->withCount('students')->get()`; backend/app/Http/Controllers/Api/DashboardController.php:124-134 — publicStats 3 COUNTs uncached; backend/app/Http/Controllers/Api/CenterManagerController.php:26-44 — pluck + 3× whereIn; backend/database/migrations/2024_01_01_000030_create_attendances_table.php:22 — only `unique(['student_id','date'])`, so `where('date', today())` alone is unindexed; backend/config/cache.php:18 + .env.example:40 — CACHE_STORE=database; no `Cache::`/`remember`/`ETag`/`Cache-Control` anywhere in app/, routes/, bootstrap/.
```

</details>

### 60-second notification polling in every tab + Sanctum last_used_at write per request + tokens never pruned or revoked on re-login

<a id="polling-token-write-amplification"></a>

`polling-token-write-amplification` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/js/layout.js:256-257`, `backend/app/Http/Controllers/Api/NotificationController.php:15-41`, `backend/app/Http/Controllers/Api/AuthController.php:64`, `backend/config/sanctum.php:55`, `backend/routes/console.php:1-9`

**Evidence**

```text
layout.js:257 `setInterval(() => load(true), 60000);` with no `document.hidden` check, ETag or backoff; each poll runs NotificationController::index = `$user->notifications()->latest()->limit(30)->get()` + `$user->unreadNotifications()->count()` and Carbon diffForHumans per item. Every authenticated request also performs Sanctum's `last_used_at` UPDATE on personal_access_tokens. Login (`$user->createToken('auth_token', $abilities)`, AuthController:64) never revokes earlier tokens for the same user; expiry is `'expiration' => 10080` (7 days) and there is no `sanctum:prune-expired` schedule (routes/console.php only defines `inspire`).
```

**Why it matters**

500 concurrent users ≈ 8 background requests/s ≈ 40 queries/s and 8 writes/s that do nothing 95% of the time; on mobile it also drains battery and data. personal_access_tokens grows by one row per login (500 users × daily login ≈ 180k rows/year) with only expiry-based dead rows and no pruning.

**Recommendation**

Return `unread_count` from a cheap endpoint with an ETag / `If-None-Match` → 304, pause polling when the tab/app is hidden, and back off to 3–5 min; for mobile, plan FCM push instead of polling. Revoke the previous token for the same device name on login, schedule `sanctum:prune-expired --hours=24` and `model:prune` via cPanel cron (`php artisan schedule:run` every minute), and consider disabling Sanctum's last_used_at update or batching it.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Every factual claim checks out. frontend-html/js/layout.js:257 registers `setInterval(() => load(true), 60000)` and there is no `visibilitychange`/`document.hidden` guard anywhere in layout.js; `load(true)` (line 219-225) still calls the full GET /notifications, which in NotificationController::index (lines 20-40) runs the 30-row `notifications()->latest()->limit(30)->get()` plus a separate `unreadNotifications()->count()`, so the "silent" poll is not a cheaper endpoint. AuthController::login (line 64) calls `$user->createToken('auth_token', $abilities)` with no prior `tokens()->delete()` or device-name revocation; the only token deletions are on logout (`currentAccessToken()->delete()`, line 91), deactivation paths, and `recordPasswordChange`. config/sanctum.php:55 sets `'expiration' => 10080`, routes/console.php defines only `inspire`, and no `prune`/`Prunable`/schedule exists in backend/app or routes. Sanctum 4 (composer.json) writes `last_used_at` on each authenticated request by default and nothing here overrides it (vendor/ is absent locally so that part is confirmed from Sanctum's known behaviour, not read from disk). No test in tests/Feature covers polling or token accumulation. So the finding is not refuted. However the severity is overstated for this product: it is a single-country Quran-center management system where realistic concurrency is tens of dashboard tabs, not 500; each poll is two indexed single-user queries; logout does revoke the current token so token accumulation comes only from abandoned/unlogged-out sessions (bounded by the 7-day expiry, and expired rows are inert, just unpruned); and personal_access_tokens has a unique index on `token` so even ~100k dead rows are harmless for MySQL. Real but housekeeping-grade: low, not medium.

```text
frontend-html/js/layout.js:219-225 (load(silent) always hits GET /notifications; silent mode only skips re-render), :257 setInterval 60000, no visibilitychange handler in file. backend/app/Http/Controllers/Api/NotificationController.php:20-36 (30-row fetch + Carbon diffForHumans per item, unread count at :36). backend/app/Http/Controllers/Api/AuthController.php:64 createToken with no revocation; :91 logout deletes only current token (mitigates growth for users who log out). backend/config/sanctum.php:55 expiration 10080. backend/routes/console.php: only `inspire`; no sanctum:prune-expired / model:prune anywhere in backend/app or routes. Token deletions exist only in User.php:81 (recordPasswordChange), TeacherController:211, CenterController:268, CenterManagerController:483, ManagerManagementController:174 (deactivation paths).
```

</details>

### PDF generation is synchronous mPDF inside the request; no queue worker exists

<a id="synchronous-mpdf-no-queue"></a>

`synchronous-mpdf-no-queue` · 🟡 medium (reviewers → low) · ✅ confirmed · **LATER** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/ReportPdfController.php:37-73`, `backend/config/queue.php:16`, `backend/routes/console.php:1-9`

**Evidence**

```text
render(): `$html = view($view, [...])->render(); $mpdf = new \Mpdf\Mpdf([... 'tempDir' => $tempDir ...]); $mpdf->WriteHTML($html); return response($mpdf->Output($filename, \Mpdf\Output\Destination::STRING_RETURN), 200, [...]);` — built and returned in the same HTTP request. `QUEUE_CONNECTION` defaults to `database` but `grep -rn 'ShouldQueue\|dispatch(' app` → 0 and routes/console.php contains only the `inspire` command (no schedule, no worker). Frontend UI.openPdf (ui.js:350-367) fetches the binary and waits.
```

**Why it matters**

mPDF with RTL shaping is CPU-heavy (a 20-page table ≈ 2–5 s, 100–200 MB peak). Ten managers exporting month-end reports simultaneously occupy ten PHP workers for several seconds each on a shared host — the same worker pool the mobile app's every request needs. Combined with the report N+1s above this is the most likely cause of intermittent 503/508 at month end.

**Recommendation**

Queue PDF builds (database queue is fine; run `queue:work --stop-when-empty` from cPanel cron every minute since there is no SSH), store the file in storage and return a job id + signed download URL; show progress in UI/mobile. Cache the underlying report arrays (finding report-service-per-teacher-fanout). Reuse a single Mpdf instance configuration and pre-generated font cache in tempDir.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The factual core is confirmed: ReportPdfController::render() (backend/app/Http/Controllers/Api/ReportPdfController.php:37-73) builds the Blade HTML, instantiates \Mpdf\Mpdf, calls WriteHTML and returns Output(STRING_RETURN) inside the same HTTP request. `grep -rn "ShouldQueue\|dispatch(\|Bus::" app routes` returns nothing; routes/console.php has only the `inspire` command; config/queue.php:16 defaults to `database` with nothing ever enqueued. The nine PDF routes in routes/api.php (lines 80-82, 125-128, 170-171) carry no `throttle` middleware (only the three auth endpoints are throttled), and UI.openPdf (frontend-html/js/ui.js:350-367) awaits the binary with only a toast. No test in tests/Feature covers PDF performance (ManagerReportsTest only checks scoping). So the finding is real and not mitigated.

However, the impact section is speculative and over-rated for this product: (1) The "intermittent 503/508 at month end" and "mobile app's every request" claims have no evidence in the repo — there is no mobile app, no incident log, and DEPLOYMENT.md (line 13) only says to run under Apache/nginx instead of `artisan serve`; the "cPanel, no SSH" premise in the recommendation is an assumption not grounded in any repo file. (2) The domain is a small Libyan Quran-center network with a handful of centers and one manager per center (ManagerManagementController::assertSingleSupervisor); "ten managers exporting simultaneously" is near the total population of managers, and each report covers dozens to low hundreds of students — a few pages, typically well under the 2-5 s / 100-200 MB figures cited. (3) Synchronous PDF rendering per request is the normal pattern for a Laravel app of this size; introducing a job queue, storage, signed URLs and a cron worker is a disproportionate architectural change for a small team. The cheap, proportionate fix is a `throttle` on the PDF route groups (and optionally caching the ReportService arrays), not a queue. Severity should be low.

```text
backend/app/Http/Controllers/Api/ReportPdfController.php:44-66 — synchronous view()->render(), new \Mpdf\Mpdf([...'tempDir' => storage_path('app/mpdf')...]), WriteHTML, Output(STRING_RETURN) in-request (confirmed). backend/routes/api.php:80-82,125-128,170-171 — nine PDF GET routes with no `throttle` middleware; the only throttles in the file are on /auth/* (lines 17,20,21). backend/routes/console.php:1-9 — only `inspire`; no schedule/worker. `grep -rn "ShouldQueue\|dispatch(\|Bus::" backend/app backend/routes` → 0 matches. DEPLOYMENT.md:13 — recommends Apache/nginx over `artisan serve`; contains no cPanel/SSH/cron statement, so the "no SSH, cPanel cron" premise and the "503/508 at month end" / "mobile app" impact claims are unsupported by the repo. frontend-html/js/ui.js:350-367 — openPdf awaits the blob with only a toast (confirmed).
```

</details>

### Unified Arabic search wraps every row in 9 nested REPLACE() calls across 4 columns + 2 correlated subqueries, then paginate() repeats it for COUNT(*)

<a id="normalized-like-search-full-scan"></a>

`normalized-like-search-full-scan` · 🟡 medium (reviewers → low) · ✅ confirmed · **LATER** · effort M (1–3 days)

**Files:** `backend/app/Support/ArabicText.php:27-38`, `backend/app/Http/Controllers/Api/StudentController.php:105-130`, `backend/app/Http/Controllers/Api/AdminUserController.php:54-66`, `backend/app/Http/Controllers/Api/CenterManagerController.php:528-535`

**Evidence**

```text
sqlNormalize builds `REPLACE(REPLACE(...REPLACE(name,'أ','ا')...,'ء','')` (9 levels, ArabicText.php:30-35). StudentController::index applies it to name, guardian_name, former_teacher_name, nationality_name plus `orWhereHas('center', ... sqlNormalize('name') LIKE ?)` and `orWhereHas('teacher', ...)` with a leading-wildcard `'%'.$norm.'%'` — index-impossible by construction; AdminUserController adds `display_code REGEXP ?` (61). `grep -rn 'sqlNormalize(' app | grep -v function | wc -l` → 13 call sites. `$query->paginate(20)` then issues the same predicate again for the total count.
```

**Why it matters**

At 5,000 students each keystroke-driven search (frontend fires on input) evaluates ~36 REPLACE chains per row plus two EXISTS subqueries per row, twice (data + count) → roughly 400k string ops per search; fine at 50 rows, ~100–300 ms on shared MySQL at 5k, and it scales linearly with parents in users (AdminUserController). Not a hard failure, but it is CPU the host bills against the shared pool.

**Recommendation**

Persist normalized columns (`name_norm`, `guardian_name_norm` on students; `name_norm` on users/centers) filled by model observers and indexed (or FULLTEXT with ngram parser), search those with the already-normalized query, debounce search in the client to ≥300 ms with a minimum of 2 characters, and use `simplePaginate` or a cached count for search results.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The core code facts are confirmed: ArabicText::sqlNormalize (backend/app/Support/ArabicText.php:27-38) wraps the column in 9 nested REPLACE() calls; StudentController::index (StudentController.php:105-130) applies it to name/guardian_name/former_teacher_name/nationality_name plus orWhereHas('center'/'teacher') with a leading-wildcard LIKE; AdminUserController.php:61 adds display_code REGEXP; CenterManagerController.php:528-535 applies it inside a whereHas('student'). There are 13 call sites and $query->paginate(20) does run the predicate twice (count + page). So the finding is not factually wrong. However, it is overstated and part of its recommendation is already implemented: (1) the frontend already debounces every live-search input at 300ms — admin/students.html:498-500, manager/students.html:414, admin/users.html:115, manager/attendance-review.html:174, admin/teachers.html:275-277 — so the "each keystroke fires a search" premise is wrong; (2) the orWhereHas EXISTS subqueries correlate on the primary key (centers.id = students.center_id, users.id = students.teacher_id), so each is a single PK lookup per candidate row, not a scan; (3) the pre-filters (is_active=true default, center_id/teacher_id scoping for manager/teacher roles) shrink the scanned set well below the full table for all but the admin; (4) for this product (Quran-memorization centers in one country, tens of centers, low thousands of students at most), a full scan of a few thousand short VARCHAR rows with string functions is on the order of tens of milliseconds on MySQL 8, and the 100–300ms / "400k string ops" estimate is speculative, unmeasured, and does not translate into a user-visible or cost problem. No caching or normalized columns exist, so the double evaluation via paginate() is real but cheap at this scale. I could not empirically time the query because php.exe is not present at C:\xampp\php\php.exe in this environment. Net: real but minor optimization opportunity, not a medium-severity scalability defect. Corrected severity: low.

```text
Confirmed: backend/app/Support/ArabicText.php:30-35 (9 nested REPLACE); backend/app/Http/Controllers/Api/StudentController.php:111-117 (4 normalized columns + orWhereHas center/teacher, leading-wildcard LIKE) and :136 paginate(20); AdminUserController.php:55-61; CenterManagerController.php:530-534. Mitigations the finding omits: frontend already debounces search at 300ms — frontend-html/admin/students.html:498-500, frontend-html/manager/students.html:414, frontend-html/admin/users.html:115, frontend-html/manager/attendance-review.html:174, frontend-html/admin/teachers.html:275-277. Pre-filters narrow the scan: StudentController.php:73-77 (manager center scope / teacher own-students scope) and :95-100 (is_active=true default). The whereHas subqueries correlate on PK columns (center_id -> centers.id, teacher_id -> users.id), so they are one PK lookup per row, not per-row scans. Empirical timing not possible here (php.exe absent at C:\xampp\php\php.exe).
```

</details>

### List and dashboard responses embed full related models (teacher User, Center) and there are no API Resources

<a id="payload-shaping-full-models"></a>

`payload-shaping-full-models` · ⚪ low · ℹ️ informational · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/StudentController.php:71`, `backend/app/Http/Controllers/Api/DashboardController.php:34-42`, `backend/app/Http/Controllers/Api/DashboardController.php:55-96`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:18`, `backend/app/Http/Controllers/Api/StudentController.php:708-712`

**Evidence**

```text
`Student::with(['center', 'teacher'])->latest()` (StudentController:71) serializes the whole User row (email, phone, type, center_id, timestamps, audit columns) for every student; teacher dashboard returns `'students' => $students` (all active student models, line 92) alongside stats; admin dashboard returns every teacher (`User::where('role','teacher')->withCount('students')->get()`, line 40-42). `ls app/Http/Resources` → no directory (0 resources). parentChildren fetches all memorization rows of the children to take the first per student: `Memorization::whereIn('student_id', $ids)->orderByDesc('date')->orderByDesc('id')->get()->groupBy('student_id')->map(fn ($g) => $g->first())` (708-712).
```

**Why it matters**

Roughly 2–3× more bytes than needed per list page on mobile networks, and any future column added to users/students silently leaks into every list response (payload growth and PII exposure ride together). The parent dashboard grows with a child's whole memorization history just to show the latest surah.

**Recommendation**

Introduce JsonResources (or explicit `select`/`with('teacher:id,name,display_code')`) for Student/Teacher/Center lists, slim the dashboards to ids+names, and replace the parentChildren latest-memo fetch with a `MAX(id) GROUP BY student_id` subquery. Define the mobile DTOs from these resources.

### Frontend depends on jsDelivr + Google Fonts via CSS @import, ships un-versioned assets, and has no cache/compression headers or optimized gallery images

<a id="frontend-delivery-third-party-and-cache-headers"></a>

`frontend-delivery-third-party-and-cache-headers` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `frontend-html/css/theme.css:6`, `frontend-html/index.html:8`, `frontend-html/admin/dashboard.html:7-17`, `frontend-html/img/gallery`, `frontend-html/js/config.js:1-40`

**Evidence**

```text
theme.css:6 `@import url('https://fonts.googleapis.com/css2?family=Amiri...&family=Cairo...&display=swap');` (render-blocking chain: theme.css → googleapis CSS → gstatic woff2); every page loads `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css` (≈230 KB). `grep -rn '\.js?v=\|\.css?v=' --include=*.html . | wc -l` → 0 (no cache-busting); `find . -name .htaccess` finds none under frontend-html (no Expires/Deflate rules; .cpanel.yml rsyncs the folder as-is). `du -sh img/gallery` → 1.1 MB across 11 JPEGs, largest 393,872 B (g10-judges-panel.jpg) and 233,924 B (g08), no WebP/srcset (lazy-loading is present). No service worker (config.js comment: «لا service worker»).
```

**Why it matters**

First paint depends on two third-party origins — from Libya, Google Fonts/jsDelivr reachability and latency are variable, and any outage blanks styling. Without versioned URLs, a deploy can leave users on stale JS against a changed API (or force no-cache headers that kill repeat-visit performance). Landing page is ~1.5 MB of images for a marketing section.

**Recommendation**

Self-host Bootstrap RTL and the Amiri/Cairo woff2 subsets with `<link rel=preload>` (drop @import), add a frontend .htaccess with mod_deflate/brotli and 1-year immutable caching for hashed assets, append a build hash (`?v=<git sha>`) to js/css references in the deploy step, convert gallery to WebP ≤120 KB with srcset, and consider a minimal service worker for app-shell caching once the PWA is promoted.

## Measured facts

| Metric | Value |
|---|---|
| Controllers / LOC audited (controllers+services+support+routes) | 19 controllers; 6,306 lines |
| Feature test files (CLAUDE.md claims 20) | 38 |
| Tests asserting query counts (N+1 guards) | 2 (CenterStatusTest, ManagerReportsTest) |
| paginate() call sites | 14 |
| ->get() call sites in controllers+services | 113 |
| Column-limited fetches (select/get([..])/first([..])/pluck) | 66 |
| whereMonth/whereYear filters (non-sargable) | 20 |
| whereDate() filters (non-sargable) | 4 |
| ArabicText::sqlNormalize call sites (9 nested REPLACE each) | 13 |
| Cache:: / remember() usages | 0 |
| Queued jobs / ShouldQueue / dispatch() | 0 |
| chunk()/lazy()/cursor() usages | 0 |
| API Resource classes | 0 |
| per_page parameter handling | 0 endpoints |
| Endpoints with unbounded ?all=1 switch | 4 (students, teachers, centers, manager/teachers); 15 frontend call sites |
| throttleApi() in bootstrap/app.php | 0 (only 3 public routes throttled: login 10/min, OTP request 5/min, OTP verify 10/min) |
| Explicit ->index() / ->unique() in 35 migrations | 14 / 10 (none on attendances.date, memorizations.date, weekly_tests.exam_date, users.role) |
| Notification poll interval (web) | 60,000 ms per open tab |
| Sanctum token expiry / prune schedule | 10,080 min (7 days) / none (routes/console.php has only 'inspire') |
| xlsx import cap | 5,120 KB, whole sheet via toArray(), 1 transaction |
| SurahReference::progress call sites (recompute from raw rows) | 7 |
| Frontend total size / JS / CSS | 1.6 MB / 53.5 KB unminified JS / 37.6 KB theme.css + Bootstrap RTL from jsDelivr |
| Gallery images | 11 JPEGs, 1.1 MB total, largest 393,872 B; lazy-loaded; no WebP/srcset |
| Asset cache-busting / frontend .htaccess | 0 versioned references / none |
| Dependency versions | Laravel 11.51.0, mPDF 8.3.1, PhpSpreadsheet 5.8.0, PHP 8.3 on host |
| Hosting | Shared cPanel (Libyan Spider), no SSH/CLI, MariaDB 10.4, manual File Manager deploys |

## Auditor notes

Additional lower-severity observations not listed above: (a) MemorizationController::index (lines 18-24) and CenterManagerController::dashboard (26) pluck all scoped student ids into a whereIn list — for an admin that is a 5,000-element IN clause per request; use a subquery/JOIN instead. (b) NotificationController::markAllRead (66) calls `$request->user()->unreadNotifications->markAsRead()` which loads and updates each notification individually; use a single UPDATE. (c) AttendanceController::report (139-143) returns the whole month of attendance rows grouped by student to the client instead of counts. (d) DashboardController::demoAccounts returns every user in local/debug mode (guarded for production). (e) DisplayCode::next serializes all creates of a type on one code_sequences row — correct and intended, but it means student creation throughput is bounded by that row lock (fine at this scale). (f) ReportService::studentData/studentSummaryRow count statuses in PHP after ->get(); trivial per student but repeated inside the teacherGroup loop. (g) StudentRequestController::managerIndex eager-loads 6 relations and returns all rows unpaginated (bounded by a center's request volume today). Doc drift relevant to this dimension: CLAUDE.md still says 20 feature-test files (38 exist), does not describe MessageController/messages indexes, AdminUserController, teacherPerformance or the all-time report trio, and DEPLOYMENT.md/.cpanel.yml disagree with DEPLOY_LOG.md on how deploys happen (rsync script vs manual File Manager) — the optimization steps are therefore absent from both. Estimated failure order at 50 centers / 5,000 students / 500 concurrent mobile users on shared cPanel: (1) admin GET /reports/weekly → 10k queries → 30 s timeout; (2) admin GET /memorizations/students-progress and progressSummary → ~250k rows in PHP → memory_limit fatal; (3) admin teachers/all-centers PDFs → ~900 queries + full-month hydration → timeout/memory; (4) monthly fingerprint import → ~40k queries in one transaction → timeout and rollback; (5) steady state: unthrottled polling + uncached dashboards + no config cache + missing date indexes saturate the account's PHP worker/CPU quota → 503/508 for everyone. None of these are visible with the current 50-student demo dataset, which is why load testing with a synthetic 5k-student fixture should precede the mobile launch.
