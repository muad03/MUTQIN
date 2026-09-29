# Reports & PDF Subsystem

[← Enterprise Audit](../enterprise-audit.md)

**Score 61 / 100** — Needs real work · maturity **L2** · weight 2%

The reporting slice that was built most recently (manager all-time reports, at-risk, centerManagement) is genuinely well engineered: one ReportService shared by JSON and PDF, account-derived center scoping with Arabic 403s, juz progress derived from SurahReference instead of the unreliable stored juz, grouped queries with a query-count regression test, and the at-risk definition exposed in both PDF subtitle and JSON. That earns it out of the 'significant risk' band. It stops short of 'solid' because: the admin role's four reports exist only as mPDF bytes (no JSON), the parent role has no progress/report at all, the student PDF omits memorization entirely, metric definitions are inconsistent across paths (pooled vs avg-of-avgs attendance, late counted differently in the n8n digest, teacher counts active vs all), four older service paths are still N+1, PDF generation is synchronous on a sync-queue shared host with no caching, only 1 of 9 PDF routes and none of the teacher ownership guards are covered by tests, and there is no export beyond PDF and no in-app scheduling. Maturity 2 (repeatable): good patterns exist and are reused in the newest code, but there is no written metric glossary, older paths were not brought up to the new standard, and nothing is measured (no timing, no size, no query budgets outside one test).

## What is already strong

- Single aggregation service shared by JSON and PDF controllers: backend/app/Services/ReportService.php:13-18 docblock ('كل منطق التجميع في مكان واحد') and both ReportController (line 31 `$reports->studentData(...)`) and ReportPdfController (line 88 `$this->reports->studentData(...)`) call the same methods.
- Juz progress is derived from surah names via SurahReference, never from the stored juz column: ReportService.php:289, :329, :429 all call `\App\Support\SurahReference::progress($names)`; progress() (SurahReference.php:157-206) counts a juz complete only when every surah is present and computes the contiguous chain from 30 downward.
- Manager scoping is taken from the account, never the request, and is tested: CenterManagerController.php:608 `$centerId = $request->user()->center_id`, ReportPdfController.php:150/159/167 use `$request->user()->center_id`; ManagerReportsTest::test_manager_cannot_request_another_center_via_param (lines 46-61) proves `?center_id=` is ignored; ManagerReportsScopeTest (lines 23-52) proves cross-center teacher/student -> 403 with Arabic message and no leakage of the other student's name.
- Newer aggregation paths avoid N+1 with grouped SQL: atRiskStudents (ReportService.php:217-228, two selectRaw/groupBy queries), centerManagement (318-323), aggregateAllTime (415-424); ManagerReportsTest::test_at_risk_query_count_stays_constant_with_scope (lines 86-111) asserts `<= 5` queries regardless of student count.
- At-risk definition is explicit and surfaced to users: constants ATTENDANCE_THRESHOLD=70 / FAIL_THRESHOLD=2 (ReportService.php:22-23), returned as `attThreshold`/`failThreshold` (262-263), printed in the PDF subtitle (at-risk.blade.php:4) and the manager UI (manager/reports.html:123).
- Active-only student filtering is applied consistently in every report method (`->where('is_active', true)` at ReportService.php:113, 136, 183, 213, 276, 313, 469, 482) and the system-level reports now count active centers only (168, 388) with ReportsActiveCentersTest pinning parity with the dashboard.
- Week convention Saturday->Friday is honoured in the weekly report: ReportController.php:94-95 `now()->startOfWeek(\Carbon\Carbon::SATURDAY)` / `endOfWeek(\Carbon\Carbon::FRIDAY)`, matching DashboardController.php:71.
- Western digits rule is respected: all numbers are PHP ints via Percentage::of (Support/Percentage.php:12-15) and dates use `->format('Y/m/d')` (student.blade.php:36, 56); period label uses `isoFormat('MMMM YYYY')` (ReportPdfController.php:31) which does not use alt-numbers.
- PDF layout is branded, RTL and escaped: `<body dir="rtl">` plus `SetDirectionality('rtl')` (layout.blade.php:37, ReportPdfController.php:60), brand palette (#04532F/#D4AF37/#FBF7DA), all interpolations via Blade `{{ }}` so student/teacher names cannot inject markup; filenames are derived only after `findOrFail` so header injection is impossible.
- Safe percentage helper prevents division by zero everywhere (Percentage::of returns 0 when total is 0) and the manager 'all records' JSON contract is explicit, flat and mobile-friendly (ReportService.php:436-463 attendance/tests/memorization blocks; 534-560 studentAllTime).

## Level-5 target state

Reporting is a documented read-model layer: a metric glossary (attendance %, late policy, active-only counts, at-risk rules, teacher index) is implemented once in query objects that feed dashboards, JSON reports, PDFs, exports and the digest, with parity tests guaranteeing every screen shows the same number for the same entity and period. Every report for every role — admin, manager, teacher and parent — is available as a stable versioned JSON contract first, with PDF/xlsx as renderings of that JSON generated from pre-aggregated monthly tables, cached for closed periods, produced asynchronously with a 'ready' notification and delivered through short-lived signed URLs that work in share sheets. Definitions and thresholds are configurable per center, surfaced in the API, and covered by route, ownership and content tests for 100% of report endpoints; generation time and query counts are measured and budgeted.

## What the Flutter team must know

What the mobile team gets today: (1) Manager — five clean JSON endpoints (`/manager/reports/center`, `/teacher/{id}`, `/student/{id}`, `/system`, `/management`) with explicit flat shapes and Arabic 403s; build native screens on these. (2) Teacher — `/reports/student/{id}` and `/reports/weekly` return raw Eloquent models (whole Student incl. guardian phone, unpaginated attendance rows) — usable but treat the shape as unstable and paginate client-side; the at-risk/period definitions (present-only attendance %, 70%/2-fails) must be mirrored exactly if any client-side computation is done. (3) Admin — there is NO JSON for the four admin reports; until the admin JSON endpoints land, the only option is fetching PDF bytes. (4) Parent — no progress or report endpoint; `/parent/students/{id}` gives attendance_summary/tests_summary and `last_surah` only, so 'completed juz' cannot be shown without the new `progress` block; do not compute juz from the stored `juz` column. PDFs: all 9 routes need `Authorization: Bearer` and return `Content-Disposition: inline` bytes with no Content-Length — download with Dio to a temp file, then open via a PDF viewer/share sheet; there are no signed URLs for `url_launcher` or WhatsApp sharing, and no caching headers, so every open regenerates the PDF server-side (expect 0.5–3 s, longer for admin all-centers). Numbers may differ between screens (avg-of-avgs vs pooled attendance, teacher counts) until the glossary fix lands — display the server value verbatim and never recompute. month/year are unvalidated: send only 1–12 and a sane year, or you get a 200 with misleading content.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [Admin reports exist only as PDF bytes; no JSON endpoints for center/teachers/at-risk/overview](#admin-reports-pdf-only) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Parent role has no memorization-progress report: no juz progress, no report page, no PDF](#parent-no-progress-report) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Attendance %, teacher counts and 'late' semantics are computed differently across report paths](#metric-definitions-inconsistent) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | M |
| [Only 1 of 9 PDF routes tested; teacher ownership guard on student report (JSON and PDF) has no test](#report-test-coverage-thin) | 🟡 medium | ✅ confirmed | NOW | S |
| [Student PDF fetches memorizations but never renders them; no juz progress in any PDF](#student-pdf-omits-memorization) | 🟡 medium | ✅ confirmed | NEXT | S |
| [Four older report paths still issue per-row queries (N+1) despite fixes elsewhere](#n-plus-one-legacy-paths) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [PDFs are Bearer-gated inline responses with no signed/shareable URL; web opener is popup-blocked on iOS](#pdf-mobile-consumption) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [At-risk definition ignores memorization stagnation and unrecorded students; thresholds are global constants](#at-risk-definition-narrow) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Teacher performance ranking uses a hidden composite formula and includes inactive teachers](#teacher-ranking-formula-undocumented) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Report aggregations are re-implemented in four controllers outside ReportService](#aggregation-duplicated-outside-service) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [PDF generation is synchronous per request; no caching, queue, or scheduler exists](#sync-pdf-no-cache-no-queue) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | LATER | L |
| [Five report JSON endpoints have no UI consumer and two return raw Eloquent models](#orphan-and-unstable-json-report-endpoints) | ⚪ low | ℹ️ informational | NEXT | S |
| [month/year query parameters are cast but never validated; label and data can disagree](#no-period-validation) | ⚪ low | ℹ️ informational | NEXT | S |
| [No xlsx/csv export and no in-app scheduled/e-mailed reports; only an external n8n digest with a divergent formula](#no-export-no-scheduling) | ⚪ low | ℹ️ informational | LATER | M |
| [PDFs use mPDF's bundled fallback font instead of brand fonts and have no page numbers or repeating header](#pdf-typography-pagination) | ⚪ low | ℹ️ informational | LATER | S |

### Admin reports exist only as PDF bytes; no JSON endpoints for center/teachers/at-risk/overview

<a id="admin-reports-pdf-only"></a>

`admin-reports-pdf-only` · 🟠 high (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/routes/api.php:124-128`, `backend/app/Http/Controllers/Api/ReportPdfController.php:106-142`, `frontend-html/admin/reports.html:78-82`

**Evidence**

```text
routes/api.php:125-128 registers only `/reports/admin/center/{id}/pdf`, `/reports/admin/teachers/pdf`, `/reports/admin/at-risk/pdf`, `/reports/admin/overview/pdf` under the admin gate; the sole admin JSON report route is `/reports/admin/missing-national-id` (line 122). admin/reports.html:78-82 wires every button to `UI.openPdf(...)`. ReportService already has centerData/allCentersData/teachersPerformance/atRiskStudents/overview (lines 133-400) but no controller exposes them as JSON for the admin.
```

**Why it matters**

A Flutter admin client cannot render native report screens (charts, drill-down, offline cache) — it can only download and display opaque PDFs. Also blocks any admin dashboard/BI integration and makes the admin the only role whose reports are not machine-readable.

**Recommendation**

Add `GET /reports/admin/{center/{id}|centers|teachers|at-risk|overview}` JSON endpoints that wrap the existing ReportService methods, returning a flat explicit shape (do not serialize raw Eloquent models — map to id/name/display_code like teacherAllTime does). Add RoleMatrix tests (admin 200, manager/teacher/parent 403). Keep the PDF routes as a rendering of the same JSON.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The factual evidence checks out: backend/routes/api.php:124-128 registers only the four `/reports/admin/.../pdf` routes under the `admin` gate, the sole admin JSON report route is `/reports/admin/missing-national-id` (line 122), ReportPdfController::center/teachers/atRisk/overview (lines 106-142) call ReportService::allCentersData/centerData/teachersPerformance/atRiskStudents/overview and return only rendered mPDF bytes, and admin/reports.html:79-82 wires every button to UI.openPdf. No JSON controller wraps those ReportService methods for the admin, and the admin token (abilities ['*'], role admin) cannot pass the `manager` gate to use the manager JSON report routes. However the severity is overstated. This is a feature/API-completeness gap, not a correctness, security, or data-integrity defect: the shipped client (frontend-html) works as designed, the PDFs render the same ReportService data, and the admin does have partial machine-readable coverage via `/dashboard` (system totals), `/centers/{id}/stats|teachers|students` (CenterController:93+, admin-gated aggregate JSON), and `/reports/admin/missing-national-id`. The "Flutter admin client" impact is hypothetical — no Flutter/mobile client is referenced anywhere in the repository docs — and no test or contract is violated. Correct rating is low (roadmap item: expose the existing ReportService methods as JSON), not high.

```text
backend/routes/api.php:122 (only admin JSON report route: missing-national-id); :125-128 (four admin routes all `/pdf`); backend/app/Http/Controllers/Api/ReportPdfController.php:106-142 (center/teachers/atRisk/overview return render() only); backend/app/Services/ReportService.php:133,165,176,211,380 (JSON-shaped array methods with no admin JSON caller); frontend-html/admin/reports.html:79-82 (all UI.openPdf). Partial mitigation: admin JSON aggregates exist at routes/api.php:103-105 (`/centers/{id}/stats|teachers|students`, CenterController.php:93+) and `/dashboard`. No Flutter/mobile client referenced in repo (grep -ril flutter *.md → none).
```

</details>

### Parent role has no memorization-progress report: no juz progress, no report page, no PDF

<a id="parent-no-progress-report"></a>

`parent-no-progress-report` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/StudentController.php:693-747`, `backend/app/Http/Controllers/Api/StudentController.php:751-857`, `frontend-html/js/layout.js:44-47`, `backend/routes/api.php:43-50`

**Evidence**

```text
parentChildren returns only `'last_surah' => $lastMemo ? $lastMemo->surah_name : '--'` and attendance_percent (StudentController.php:731-735). parentStudentDetails returns paginated raw memorization rows plus `attendance_summary` and `tests_summary` (lines 837-855) but never calls SurahReference::progress — there is no completed_juz/reached_juz/completion_percent for the parent, while the manager gets all of these (ReportService.php:550-559). layout.js:44-47 parent nav has only 'أبنائي' and 'الرسائل'; routes/api.php:43-50 has no parent report or PDF route.
```

**Why it matters**

The parent app's core value proposition (how far has my child memorised, is the current juz complete, attendance trend) is unavailable from the API; a mobile team would have to re-implement juz logic client-side against unreliable stored juz, contradicting the SurahReference source-of-truth rule.

**Recommendation**

Add a `progress` block (reuse ReportService::studentAllTime's progress sub-array or a new `studentProgress(Student)` helper) to `/parent/students/{id}` and a compact `completed_juz`/`reached_juz` to `/parent/children`. Then add `GET /parent/students/{id}/report/pdf` (ownership-checked like parentStudentDetails) using the student template once it includes memorization.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual claims check out. backend/app/Http/Controllers/Api/StudentController.php:723-735 (parentChildren) returns only last_surah/last_memo_date/attendance_percent; parentStudentDetails (lines 816-849) returns paginated raw memorization rows plus attendance_summary and tests_summary and never calls SurahReference::progress. routes/api.php:43-50 has only children/students/{id}/messages routes under the parent gate — no report or PDF route. layout.js:44-47 parent nav is only 'أبنائي' and 'الرسائل'. The frontend parent pages (parent/dashboard.html, child.html, messages.html) contain no juz/progress/PDF logic either, so there is no client-side mitigation. ReportService::studentAllTime (line 522, completed_juz at 551) exists and is reused by teacher/manager reports, so the helper the auditor recommends is already available. No feature test covers a parent progress payload (ParentChildPaginationTest / OwnershipTest only cover pagination and ownership). However, the severity is overstated: this is a missing feature / product gap, not a defect, security flaw, or data-integrity problem. The parent still receives the full memorization history (surah names, pages, quality, dates), attendance breakdown and weekly-test results; only the derived juz-completion summary and a PDF are absent. The "mobile team re-implements juz logic against unreliable stored juz" impact is hypothetical (no mobile client exists, and parentStudentDetails does not even expose the juz column). For an audit of a small single-country product, a functional gap of this kind rates medium, not high.

```text
backend/app/Http/Controllers/Api/StudentController.php:723-735 (parentChildren payload: last_surah, last_memo_date, attendance_percent only); :763-774 and :816-849 (parentStudentDetails: paginated raw memorizations, no SurahReference::progress call, no juz field exposed); backend/routes/api.php:43-50 (parent group: children, students/{id}, messages only); frontend-html/js/layout.js:44-47 (parent nav: 2 entries); frontend-html/parent/{dashboard,child,messages}.html contain no juz/progress/pdf handling; backend/app/Services/ReportService.php:522-551 (studentAllTime already computes completed_juz via SurahReference::progress — reusable helper exists, confirming the gap is an omission, not a design constraint).
```

</details>

### Attendance %, teacher counts and 'late' semantics are computed differently across report paths

<a id="metric-definitions-inconsistent"></a>

`metric-definitions-inconsistent` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Services/ReportService.php:116`, `backend/app/Services/ReportService.php:152`, `backend/app/Services/ReportService.php:135`, `backend/app/Services/ReportService.php:475`, `backend/app/Services/ReportService.php:178-180`, `backend/app/Services/ReportService.php:389`, `n8n/attendance-digest.code.js:17`

**Evidence**

```text
teacherGroupData averages per-student percentages: `$avgAtt = ... round($rows->avg('attendancePercent'))` (ReportService.php:116) while teachersPerformance/centerData use the pooled ratio `pct(present, att->count())` (152, 195) — the same teacher/month can show two different 'average attendance' numbers in the teacher's PDF vs the admin's teacher-performance PDF. Teacher counts: centerData `User::where('role','teacher')->where('center_id',...)->count()` with no is_active (135) and overview (389) vs centerAllTime `->where('is_active', true)` (475); teachersPerformance ranks inactive teachers too (178-180, no is_active filter). n8n digest counts late as attended: `rate = (present.length + late.length) / total` (attendance-digest.code.js:17) whereas every ReportService percent is present-only, so a student who is late every day is 0% and flagged 'حضور منخفض' at-risk (239). overview counts active centers only (388) but students/teachers/at-risk still include members of deactivated centers (CenterController::toggleStatus does not deactivate students).
```

**Why it matters**

At dozens of centers, numbers that disagree between screens/PDFs destroy trust in the reports and make manager-vs-admin disputes unresolvable; a mobile client showing two different 'attendance %' for the same entity is a visible defect.

**Recommendation**

Write a metric glossary (attendance % = present/(present+late+absent) with an explicit late policy; 'average' = pooled unless stated; counts = active only) and enforce it in one place: a `Metrics`/read-model class used by ReportService, dashboards and n8n. Replace avg-of-avgs in teacherGroupData with the pooled ratio (or label it 'متوسط نسب الطلاب'), add `is_active` to all teacher counts/rankings, and either scope system reports to active centers or label them 'all history'. Add unit tests asserting parity between paths for the same fixture.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Every cited line was verified and means what the auditor says. (1) ReportService::teacherGroupData line 116 computes avg-of-per-student-percentages (`$rows->avg('attendancePercent')`) while centerData:152, teachersPerformance:195, centerManagement:348/370 and aggregateAllTime:442 all use the pooled ratio present/total; both are rendered under the identical label «متوسط الحضور» (resources/views/pdf/teacher-group.blade.php:11 vs pdf/admin/center.blade.php:37), so the same teacher/month can legitimately show two different numbers. (2) Teacher counts: centerData:135, centerManagement:311-312/368, overview:389, teachersPerformance:178-180 and DashboardController:22 all omit `is_active`, while centerAllTime:475 filters `is_active = true` — the admin center PDF and the manager's all-time center report disagree on 'عدد المعلمين' for the same center once any teacher is deactivated; inactive teachers are also ranked in the teacher-performance report. (3) n8n/attendance-digest.code.js:17 counts late as attended (`(present.length + late.length) / total`) whereas every ReportService percent is present-only (atRiskStudents:220/235 flags a habitually-late student as 'حضور منخفض'). (4) overview:388 and allCentersData:168 restrict to active centers, but overview:389-391 and DashboardController count students/teachers/parents system-wide, so the overview total does not equal the sum of the per-center rows; CenterController::toggleStatus only revokes members' tokens and does not touch students. No mitigation found: tests/Feature/ReportsActiveCentersTest.php and ManagerReportsTest.php only cover active-center scoping, not parity of metric definitions, and there is no shared Metrics class (only Percentage::of for safe division). However, 'high' is overstated: this is a reporting-consistency/labeling defect with no security, data-integrity or money impact; avg-of-avgs differences in a single teacher's group are usually small; the n8n digest is an optional, differently-scoped daily summary; the deactivated-center leakage only manifests after a center is deactivated and admin dashboard/overview are at least consistent with each other. Corrected severity: medium.

```text
backend/app/Services/ReportService.php:116 avg-of-avgs vs :152, :195, :348, :370, :442 pooled ratios — same label «متوسط الحضور» in backend/resources/views/pdf/teacher-group.blade.php:11 and backend/resources/views/pdf/admin/center.blade.php:37. Teacher counts without is_active: ReportService.php:135, :178-180, :311-312/:368, :389; backend/app/Http/Controllers/Api/DashboardController.php:22, :41; with is_active only at ReportService.php:475. Late policy: n8n/attendance-digest.code.js:17 vs ReportService.php:220,235,239. Active-center scoping only at ReportService.php:168 and :388 (students/teachers at :389-391 unscoped); CenterController::toggleStatus (~:250-280) revokes tokens only, does not deactivate students. No parity tests: tests/Feature/ReportsActiveCentersTest.php and ManagerReportsTest.php cover center scoping only.
```

</details>

### Only 1 of 9 PDF routes tested; teacher ownership guard on student report (JSON and PDF) has no test

<a id="report-test-coverage-thin"></a>

`report-test-coverage-thin` · 🟡 medium · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/tests/Feature/ManagerReportsTest.php:82-83`, `backend/app/Http/Controllers/Api/ReportController.php:20`, `backend/app/Http/Controllers/Api/ReportPdfController.php:83`, `backend/tests/Feature/OwnershipTest.php:16-33`

**Evidence**

```text
`grep -rn "reports/student\|reports/weekly\|reports/teacher\|reports/admin\|attendance/report" tests/` returns only ManagerReportsTest.php:82 (`/api/reports/admin/at-risk/pdf` -> assertOk + application/pdf). The ownership guards `if (!$user->isAdmin() && $student->teacher_id !== $user->id)` (ReportController.php:20, ReportPdfController.php:83) are untested; OwnershipTest covers /students/{id} but not /reports/student/{id}. No test asserts PDF content, the 'all' center variant, overview/teachers/center PDFs, manager PDF routes, /reports/weekly or /attendance/report. Report tests: 3 files / 9 methods.
```

**Why it matters**

A regression in the teacher-report guard would leak another teacher's student data (attendance, guardian phone) to any teacher token; PDF templates can break silently (Blade errors only surface at render). The mobile teacher client will depend on exactly these routes.

**Recommendation**

Add: ownership 403 tests for both `/reports/student/{id}` and `/reports/student/{id}/pdf` (teacher B vs teacher A's student, admin allowed); smoke tests for all 9 PDF routes (200, application/pdf, %PDF- magic bytes) including `center/all`; a JSON-shape test for `/reports/weekly` with Saturday→Friday boundaries; RoleMatrix entries for the report routes.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Evidence verified. Grep of backend/tests for report/PDF routes: the only PDF assertion in the whole suite is ManagerReportsTest.php:82-83 (`/api/reports/admin/at-risk/pdf` -> assertOk + Content-Type header). routes/api.php defines exactly 9 PDF routes (admin: center/{id}, teachers, at-risk, overview @125-128; teacher: student/{id}/pdf, teacher/pdf @170-171; manager: center, at-risk, teachers @80-82) — 8 have no test. The teacher ownership guards exist verbatim at ReportController.php:20 and ReportPdfController.php:83 and are duplicated (not centralized), so a regression in one would not be caught by the other. OwnershipTest.php covers /students/{id}, /memorizations, /parent/students/{id}, notifications — nothing under /reports/. RoleMatrixTest.php has zero occurrences of 'report'. /reports/weekly and /attendance/report have no tests. The manager-side scoping IS tested (ManagerReportsScopeTest covers /manager/reports/center, teacher/{id}, student/{id} with 403s and ManagerReportsTest covers system/management JSON), so the auditor's '3 files / 9 methods' count is roughly right and the manager JSON side is a partial mitigation — but it does not cover the teacher-gated routes named in the finding. Mitigations checked: the guard itself is currently correct and fails closed (strict !== comparison; a type mismatch would produce a false 403, not a leak); the `teacher` middleware gate blocks parent/manager tokens. So this is purely a test-coverage gap, not a live defect — but the project's own CLAUDE.md states feature tests are 'the only guard on the dual role+ability security model', which makes an untested authorization guard on a data-exposing route a legitimate medium. Severity stands.

```text
backend/routes/api.php:80-82,125-128,170-171 (9 PDF routes); backend/tests/Feature/ManagerReportsTest.php:82-83 (only PDF test); backend/app/Http/Controllers/Api/ReportController.php:20 and ReportPdfController.php:83 (duplicated, untested teacher-ownership guard); backend/tests/Feature/OwnershipTest.php:16-33 (covers /students/{id} only); backend/tests/Feature/RoleMatrixTest.php (no 'report' occurrences). Partial mitigation not mentioned by auditor: backend/tests/Feature/ManagerReportsScopeTest.php:32-50,83,118,126 tests manager-scoped JSON report 403/200 paths.
```

</details>

### Student PDF fetches memorizations but never renders them; no juz progress in any PDF

<a id="student-pdf-omits-memorization"></a>

`student-pdf-omits-memorization` · 🟡 medium · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Services/ReportService.php:47-49`, `backend/resources/views/pdf/student.blade.php:1-77`, `backend/resources/views/pdf/teacher-group.blade.php:17-42`

**Evidence**

```text
studentData loads `$memorizations = Memorization::where('student_id', ...)->whereMonth(...)->get()` (ReportService.php:47-49) and returns it as `'memorizations'`, but student.blade.php renders only stats cards, student info, attendance table (lines 29-41) and weekly tests (43-75) — there is no `$d['memorizations']` reference anywhere in the template (`grep memorizations resources/views/pdf/` returns nothing). No PDF template shows completed_juz/reached_juz; teacher-group.blade.php columns are attendance and pass-rate only.
```

**Why it matters**

The flagship printable report of a Quran-memorization platform says nothing about memorization. Parents/awqaf-facing PDFs cannot be used as progress certificates, and the wasted query costs time on every render.

**Recommendation**

Add a 'سجل الحفظ' section (date, surah, pages, quality) and a progress card (completed juz / 30, reached juz, last surah via SurahReference) to student.blade.php, reusing ReportService::studentAllTime's progress block; add completed_juz to the teacher-group table. Add a test asserting the PDF response contains the surah name.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 92% · corrected severity: medium

Traced and confirmed. ReportService::studentData (backend/app/Services/ReportService.php:47-49,61) queries Memorization for the month and returns it under 'memorizations'; ReportPdfController::student (line 88-90) passes that array to view 'pdf.student'; student.blade.php (all 77 lines) references only attendancePercent/present/absent/late/passRate, student info, $d['attendances'] and $d['tests'] — no $d['memorizations'] anywhere. A grep across resources/views/pdf for memoriz|juz|surah|completed|ختم|أجزاء finds only admin/overview.blade.php:25,35, which prints a raw count of memorization sessions ("حصص الحفظ") — no juz/surah progress in any of the six templates (student, teacher-group, admin/center, admin/teachers, admin/at-risk, admin/overview). ReportService does compute juz progress (progressSummary, centerData rows with completed_juz/reached_juz at 505-508, studentAllTime at 522-555) but none of it is wired into a PDF template. No feature test asserts PDF body content (ManagerReportsTest exercises routes only). Aggravating detail the auditor missed: frontend-html/teacher/reports.html:40 advertises the student PDF as covering "حضور الطالب وحفظه واختباراته" (attendance, memorization, tests) — so the UI promises memorization content the PDF does not deliver. Not security-related and the JSON reports do expose progress, so medium (functional/product gap plus a dead query) is the right rating; not refuted.

```text
backend/app/Services/ReportService.php:47-49,61 (memorizations queried and returned); backend/app/Http/Controllers/Api/ReportPdfController.php:88-90 (studentData -> view pdf.student); backend/resources/views/pdf/student.blade.php:1-77 (no $d['memorizations'], no juz); only PDF mention of memorization is backend/resources/views/pdf/admin/overview.blade.php:25,35 (raw session count, not progress); ReportService.php:505-508 and 522-555 already compute completed_juz/reached_juz but are unused by any PDF; frontend-html/teacher/reports.html:40 advertises the student PDF as including "حفظه" (memorization).
```

</details>

### Four older report paths still issue per-row queries (N+1) despite fixes elsewhere

<a id="n-plus-one-legacy-paths"></a>

`n-plus-one-legacy-paths` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Services/ReportService.php:79-105`, `backend/app/Services/ReportService.php:112-115`, `backend/app/Services/ReportService.php:168`, `backend/app/Services/ReportService.php:182-199`, `backend/app/Http/Controllers/Api/ReportController.php:97-111`

**Evidence**

```text
teacherGroupData maps `$students->map(fn ($s) => $this->studentSummaryRow($s, ...))` (112-115) and studentSummaryRow runs 2 queries per student (81-89). allCentersData maps `->map(fn ($c) => $this->centerData($c, ...))` (168) and centerData runs 4 queries per center (135-146). teachersPerformance runs 3 queries per teacher inside `->map(function ($t) ...)` (182-199). ReportController::weekly runs Attendance + Memorization queries per student (98-110). Contrast: atRiskStudents/centerManagement/aggregateAllTime use grouped selectRaw (217-228, 318-323, 415-424). All period filters use non-sargable `whereMonth/whereYear` (17 occurrences).
```

**Why it matters**

Admin 'teachers performance' at 100 teachers = ~300 queries; 'all centers' at 40 centers = ~160 queries; admin weekly at 2,000 students = ~4,000 queries, all inside a synchronous PDF/JSON request on shared hosting. Latency grows linearly with scale and can hit PHP execution limits.

**Recommendation**

Rewrite the four paths on the grouped pattern already used in aggregateAllTime (one attendance groupBy, one tests groupBy keyed by student/teacher/center), replace whereMonth/whereYear with `whereBetween('date', [start, end])` so the (student_id,date) unique index is used, and extend the query-count regression test to cover them.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Evidence verified line-for-line. ReportService::studentSummaryRow (79-105) issues 2 queries per student and teacherGroupData (115) maps it per student; allCentersData (168) calls centerData per active center and centerData (135-146) issues 4 queries; teachersPerformance (182-199) issues 3 queries per teacher inside map(); ReportController::weekly (97-111) issues Attendance + Memorization queries per student. All four are reachable: teacherGroup -> GET /reports/teacher/pdf (teacher gate), allCentersData -> GET /reports/admin/center/all/pdf, teachersPerformance -> GET /reports/admin/teachers/pdf and /manager/reports/teachers/pdf, weekly -> GET /reports/weekly (admin path can span all students). No mitigation exists: the only query-count regression test (tests/Feature/ManagerReportsTest.php:97-110) covers atRiskStudents only; no caching; no pagination; migrations add only the unique (student_id,date) index on attendances. The 'non-sargable whereMonth/whereYear' point is technically true (20 occurrences) but mostly moot in practice since every such query is already narrowed by the indexed student_id FK, so date-range sargability changes little. Severity is overstated for this product: these are admin/manager-only synchronous report endpoints in a single-country Quran-center system where realistic scale is a handful of centers and tens of teachers; the real impact is added latency (tens to a few hundred extra small primary-key queries), not correctness, security, or availability. The teacher-group path is bounded by one teacher's own students. It is a genuine, cheap-to-fix inconsistency with the grouped pattern already used elsewhere, but a low-severity performance debt rather than medium.

```text
backend/app/Services/ReportService.php:81-89 (2 queries/student), :115 (per-student map), :135-146 (4 queries/center), :168 (per-center map), :183-189 (3 queries/teacher); backend/app/Http/Controllers/Api/ReportController.php:98-109 (2 queries/student). Callers: backend/app/Http/Controllers/Api/ReportPdfController.php:98,111,124,167; routes backend/routes/api.php:82,126,166,171. Only regression coverage: backend/tests/Feature/ManagerReportsTest.php:97-110 (atRiskStudents only). Sargability caveat: all period-filtered queries also filter by indexed student_id (unique index student_id,date at database/migrations/2024_01_01_000030_create_attendances_table.php:22), so whereMonth/whereYear cost is marginal.
```

</details>

### PDFs are Bearer-gated inline responses with no signed/shareable URL; web opener is popup-blocked on iOS

<a id="pdf-mobile-consumption"></a>

`pdf-mobile-consumption` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/ReportPdfController.php:65-72`, `frontend-html/js/ui.js:350-375`

**Evidence**

```text
render() returns `response($mpdf->Output(..., STRING_RETURN), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="..."'])` (ReportPdfController.php:65-72); the route is under `auth:sanctum`, so the URL cannot be opened by a browser/webview/`url_launcher`/share sheet without injecting the Authorization header. ui.js:355-372 does `await fetch(...)` then `window.open(url, '_blank')` — the open is no longer inside the user gesture, which Safari/iOS PWA blocks silently. No `URL::temporarySignedRoute`, no `Content-Length`, no ETag/caching headers.
```

**Why it matters**

The Flutter client must download bytes with Dio + auth header, write a temp file and hand it to a viewer/share sheet for every report; there is no way to share a report link with a parent or to WhatsApp (the dominant channel in Libya). The existing PWA path fails on iPhones.

**Recommendation**

Add a short-lived signed URL flow: `POST /reports/.../pdf/link` returns a `temporarySignedRoute` (5–15 min) that streams the PDF without a Bearer header; add `Content-Length` and `Cache-Control: private`. In the web client, open a blank tab synchronously in the click handler then set its location, or use an `<a download>` created before the await.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted code is accurate. ReportPdfController::render() (backend/app/Http/Controllers/Api/ReportPdfController.php:65-72) returns the mPDF string with only Content-Type and Content-Disposition inline headers; no Content-Length, no cache headers, no signed-URL variant anywhere in backend/app (grep for 'signed'/'Content-Length' finds nothing). All PDF routes sit inside the `Route::middleware('auth:sanctum')` group (routes/api.php:26, 80-82, 125-128, 170-171), so a bare URL cannot be opened without a Bearer header. UI.openPdf (frontend-html/js/ui.js:350-378) does `await fetch` then `window.open(url,'_blank')` (line 372); every caller (admin/reports.html:79-82, manager/reports.html:92-94, teacher/reports.html:68,70) uses the default path (no `opts.download`), so the popup is opened after the async boundary, which Safari/iOS blocks by default. A manifest.webmanifest exists, so the PWA context is real. No feature test covers the PDF routes beyond ManagerReportsTest/StudentTransferRequestTest, and none tests headers or the open path.

However, the finding is overrated. (1) No Flutter/mobile client exists in the repo (no pubspec.yaml or .dart files) — the "Flutter client must download bytes with Dio" impact is hypothetical. (2) Parents have no PDF route at all (only admin/manager/teacher gates), so "share a report link with a parent" is a missing feature, not a broken one; Bearer-gating the PDFs is the correct security posture given the role+ability model, and a signed public URL would be a new attack surface to design, not a bug fix. (3) The concrete defect that remains is a usability bug: on iOS Safari / installed PWA the 'open in new tab' silently fails after the await. Desktop Chrome/Edge/Firefox (the XAMPP-era primary target) generally allow the blob open. Missing Content-Length is cosmetic (progress bar) and Cache-Control is irrelevant for per-request generated private PDFs. Net: a real low-severity front-end UX bug on iOS plus a feature request; not medium.

```text
backend/routes/api.php:26 (`Route::middleware('auth:sanctum')->group`), :80-82, :125-128, :170-171 (all PDF routes inside it); backend/app/Http/Controllers/Api/ReportPdfController.php:65-72 (headers: only Content-Type + inline Content-Disposition); frontend-html/js/ui.js:355 (`await fetch`) → :372 (`window.open(url,'_blank')` after the async boundary); callers frontend-html/admin/reports.html:79-82, manager/reports.html:92-94, teacher/reports.html:68,70 all omit `opts.download`; frontend-html/manifest.webmanifest exists (PWA). No Flutter code in repo (find pubspec.yaml/*.dart → none); no parent PDF route exists.
```

</details>

### At-risk definition ignores memorization stagnation and unrecorded students; thresholds are global constants

<a id="at-risk-definition-narrow"></a>

`at-risk-definition-narrow` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Services/ReportService.php:22-23`, `backend/app/Services/ReportService.php:239-242`, `backend/resources/views/pdf/admin/at-risk.blade.php:28`

**Evidence**

```text
`const ATTENDANCE_THRESHOLD = 70; const FAIL_THRESHOLD = 2;` (22-23); `$lowAtt = $total > 0 && $pct < self::ATTENDANCE_THRESHOLD; $manyFails = $failed >= self::FAIL_THRESHOLD;` (239-240). A student with zero attendance rows in the month is never flagged (`$total > 0`), a student with no memorization for months is never flagged, and a student who is 'late' every day is flagged at 0%. at-risk.blade.php:28 hard-codes `>= 70` instead of `$d['attThreshold']`. Thresholds cannot vary per center or be changed without a deploy.
```

**Why it matters**

For a memorization center the most important risk signal (no progress / stalled juz) is absent, so the report under-detects the students it exists to find, while over-flagging habitually-late attendees. Managers cannot tune the rule for their center.

**Recommendation**

Extend the definition with (a) 'no memorization record in N weeks' and (b) 'no attendance recorded' as separate reasons, treat late per the agreed glossary, move thresholds to a `report_settings`/center-level config, and document the rule in DEPLOYMENT/CLAUDE docs. Keep returning the effective thresholds in JSON for the mobile client.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted evidence is accurate: ReportService.php:22-23 defines the two constants, :239-240 computes `$lowAtt = $total > 0 && $pct < 70` and `$manyFails = $failed >= 2`, memorization data is never consulted in atRiskStudents (:211-265), and at-risk.blade.php:28 hard-codes `>= 70` instead of `$d['attThreshold']`. So the factual claims hold: a student with zero attendance rows is skipped, a stalled memorizer is never flagged, and a student marked 'late' every day scores 0% and is flagged.

However, several points weaken the finding's framing and severity:
1. 'Late' handling is not a bug or an inconsistency: the whole system defines attendance % as present ÷ total (ReportService.php:405 comment, studentData, studentSummaryRow, centerData, aggregateAllTime, DashboardController) — the at-risk rule simply follows the single existing definition. Calling this 'over-flagging' presumes a glossary that does not exist in the repo.
2. The rule is explicitly disclosed to users everywhere it is shown: PDF subtitle (at-risk.blade.php:4 'الحضور أقل من X% أو الرسوب N مرات فأكثر'), stats tiles (:11-12), and the manager UI (frontend-html/manager/reports.html:123) all render the effective thresholds from the JSON payload (`attThreshold`/`failThreshold`, ReportService.php:262-263). The report does exactly what it says it does; the absence of a memorization-stagnation signal is a feature-scope gap / product decision, not a correctness defect.
3. The `$total > 0` guard is a deliberate choice to avoid flagging students with no data as 0% attendance; whether unrecorded students should be a separate reason is a requirements question.
4. The blade :28 hard-coded 70 is only a cell-colour class (`warn` vs `no`) applied to rows already selected by the service; it cannot change which students appear and only drifts cosmetically if the constant is edited. Cosmetic, trivially fixed.
5. Per-center configurable thresholds is an enhancement request for a small single-country deployment, not a defect. No test in backend/tests/Feature covers the definition semantics (ManagerReportsTest only checks scoping/query count), so nothing contradicts the finding, but nothing is 'broken' either.

Net: real but an enhancement/scope observation with one trivial cosmetic inconsistency; the code behaves as designed and as disclosed. Downgrade to low.

```text
backend/app/Services/ReportService.php:22-23 (constants), :239-240 (`$total > 0 && $pct < self::ATTENDANCE_THRESHOLD`, `$failed >= self::FAIL_THRESHOLD`), :211-265 (no Memorization query in atRiskStudents), :262-263 (thresholds returned in JSON), :405 (system-wide definition 'نسبة الحضور = حاضر ÷ الإجمالي' — late intentionally not counted as present, consistent across studentData/studentSummaryRow/centerData/aggregateAllTime). backend/resources/views/pdf/admin/at-risk.blade.php:4 and :11-12 render thresholds dynamically; :28 hard-codes `>= 70` for a CSS class only (cosmetic). frontend-html/manager/reports.html:123 displays `risk.attThreshold`/`risk.failThreshold` from the API. backend/tests/Feature/ManagerReportsTest.php:64-110 tests only scoping and query count, not the at-risk rule semantics.
```

</details>

### Teacher performance ranking uses a hidden composite formula and includes inactive teachers

<a id="teacher-ranking-formula-undocumented"></a>

`teacher-ranking-formula-undocumented` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Services/ReportService.php:176-204`, `backend/resources/views/pdf/admin/teachers.blade.php:4`

**Evidence**

```text
`->sortByDesc(fn ($r) => $r['passRate'] + $r['attendancePercent'] / 2) // الأداء = نجاح + نصف الحضور` (ReportService.php:200); the PDF subtitle only says 'مرتّب حسب الأداء (الأعلى أولاً)' (teachers.blade.php:4) and the score itself is not printed. The teacher query has no `is_active` filter (178-180). Teachers with 0 tests get passRate 0 and sink to the bottom regardless of attendance.
```

**Why it matters**

An HR-sensitive ranking that admins/managers will act on is not explainable to the ranked teachers, mixes deactivated staff into the list, and penalises teachers whose students simply were not tested that month.

**Recommendation**

Print the composite score and its formula in the PDF/JSON, exclude inactive teachers (or badge them), treat 'no tests' as N/A rather than 0, and document the index in the metric glossary; consider ranking by avg completed juz gain as the domain-relevant signal.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

All three factual claims verified by tracing the code. (1) ReportService::teachersPerformance (176-204) queries User::where('role','teacher') with no is_active filter; TeacherController::toggleStatus (~196-220) only flips is_active and does not detach students, so a deactivated teacher keeps active students and appears as a normal ranked row in both the admin PDF (ReportPdfController@teachers:124) and the manager PDF (@managerTeachers:167, same blade). Inconsistent with the rest of the service (allCentersData:168 filters inactive centers; teacherGroupData/others filter inactive students). (2) Sort key passRate + attendancePercent/2 (line 200) is not printed; teachers.blade.php only prints the seven columns and the subtitle 'مرتّب حسب الأداء'. (3) Percentage::of returns 0 when total=0, so a teacher with no tests in the month gets passRate 0 and can only reach a max score of 50 — a teacher with 100% attendance and no tests ranks below one with 55% pass rate and 0% attendance. Partial mitigations exist and lower the severity: the formula is documented in code (CenterManagerController:291, ReportService:200) and the manager's per-teacher page exposes performance_score with a visible tooltip stating the formula (frontend-html/manager/teacher.html:81, CenterManagerController:382), so the index is not entirely 'hidden' from the manager role. However the PDF — the artefact handed to people — omits the score and formula, the admin has no UI explaining it, and inactive-teacher inclusion is not mitigated anywhere. The ranking is an informational monthly report, not an automated HR action, and the team is small, so medium is slightly high; low-to-medium is fair. Keeping medium-low as 'low' given the mitigations and that the product's own tests (ManagerReportsTest:76-79) only check scoping, not ranking semantics.

```text
backend/app/Services/ReportService.php:178-180 (no is_active filter on teachers), :183 (only students filtered), :200 (sort key passRate + attendancePercent/2 not exposed in rows); backend/app/Support/Percentage.php:14 (0 tests -> 0%); backend/resources/views/pdf/admin/teachers.blade.php:4,12-14 (score column absent); backend/app/Http/Controllers/Api/ReportPdfController.php:124,167 (same blade for admin and manager); backend/app/Http/Controllers/Api/TeacherController.php:~200-204 (deactivation keeps students assigned). Partial mitigation: backend/app/Http/Controllers/Api/CenterManagerController.php:291,382 and frontend-html/manager/teacher.html:81 expose performance_score with the formula in a tooltip for the manager role only.
```

</details>

### Report aggregations are re-implemented in four controllers outside ReportService

<a id="aggregation-duplicated-outside-service"></a>

`aggregation-duplicated-outside-service` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/CenterManagerController.php:306-360`, `backend/app/Http/Controllers/Api/CenterController.php:99-126`, `backend/app/Http/Controllers/Api/StudentController.php:800-855`, `backend/app/Http/Controllers/Api/DashboardController.php:25-71`

**Evidence**

```text
CenterManagerController::teacherPerformance rebuilds attendance/tests/progress aggregation with its own selectRaw and Percentage calls (306-360) instead of calling ReportService::teacherAllTime; CenterController::stats has its own month aggregation (99-126); StudentController::parentStudentDetails computes attendance_summary/tests_summary inline (800-855); DashboardController computes today/week stats separately. ReportService's docblock claims 'بلا تكرار للمنطق' (13-18).
```

**Why it matters**

Each copy is a place where the metric glossary can drift (it already has — see metric-definitions-inconsistent). Fixing a definition requires touching five files, and mobile endpoints inherit whichever variant they hit.

**Recommendation**

Introduce query objects/read models (e.g. `AttendanceStats::forStudents($ids, Period)`, `TestStats`, `ProgressStats`) in app/Services or app/Queries and have ReportService, dashboards, CenterController::stats, parent details and teacherPerformance compose them. Add parity tests.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The duplication is factually present: CenterManagerController::teacherPerformance (306-360), CenterController::stats (98-121), StudentController parent details (791-847) and DashboardController (21-73) each issue their own attendance/tests/progress aggregations rather than composing ReportService, and ReportService's docblock (13-18) does claim "no duplication" for its two consumers. However the finding is overstated in two ways. (1) The specific evidence is partly wrong: teacherPerformance is a *current-month* aggregation (whereMonth/whereYear on lines 313, 318), whereas ReportService::teacherAllTime (480-520) is deliberately all-time — it could not simply be called as the auditor suggests; the comparable service method is teachersPerformance($month,$year,$centerId) (176-204), and even that returns a different shape (no per-student rows, no progress). (2) The metric *definitions* are in fact already centralized where drift would matter: every copy delegates percentage math to App\Support\Percentage::of (single source, used by all 4 files + ReportService::pct) and juz/khatma progress to SurahReference::progress; the SQL predicates (status='present', result='ناجح') are identical across all copies and match aggregateAllTime (414-420). The copies also carry explicit comments pinning them to ReportService's definitions (CenterManagerController 290-292, 382; CenterController 91). Each duplicated endpoint has feature-test coverage asserting concrete metric values (ManagerTeacherPerformanceTest lines 83-96 incl. performance_score = pass + att/2; AdminCenterDetailsTest 60-67), so a definition change would be caught, though there are no cross-endpoint parity tests. DashboardController's "aggregation" is trivial today-only counts for which ReportService has no method at all, so listing it as re-implementation is a stretch. Net: a real maintainability/DRY concern in a small codebase, not a correctness defect and not the driver of metric drift (the shared primitives prevent formula drift; the "inconsistency" finding elsewhere concerns scope filters like counting inactive teachers, which is a separate issue). Severity should be low.

```text
backend/app/Http/Controllers/Api/CenterManagerController.php:312-320 (month-scoped whereMonth/whereYear — not equivalent to ReportService::teacherAllTime at app/Services/ReportService.php:480-520, which is all-time; the nearest service analogue is teachersPerformance at 176-204); CenterManagerController.php:290-292,382 and CenterController.php:91 (comments explicitly pin definitions to ReportService); app/Support/Percentage.php:13-16 (single percentage source used by all 4 controllers and ReportService::pct at 26-29); ReportService.php:412-420 vs CenterController.php:108-121 and CenterManagerController.php:312-320 (identical SQL predicates status='present' / result='ناجح'); tests/Feature/ManagerTeacherPerformanceTest.php:83-96 and tests/Feature/AdminCenterDetailsTest.php:60-67 (metric values asserted per endpoint; no parity tests across endpoints); DashboardController.php:21-31,57-73 (today-only counts; ReportService has no today/week method to reuse).
```

</details>

### PDF generation is synchronous per request; no caching, queue, or scheduler exists

<a id="sync-pdf-no-cache-no-queue"></a>

`sync-pdf-no-cache-no-queue` · 🟡 medium (reviewers → low) · ✅ confirmed · **LATER** · effort L (1–2 weeks)

**Files:** `backend/app/Http/Controllers/Api/ReportPdfController.php:37-73`, `backend/.env.production.example:60-62`, `backend/routes/console.php:1-9`

**Evidence**

```text
render() builds a fresh `new \Mpdf\Mpdf([...])`, sets autoScriptToLang/autoLangToFont and WriteHTML on every request (ReportPdfController.php:50-63). .env.production.example:60-62: 'لا queue worker على الاستضافة المشتركة' / `QUEUE_CONNECTION=sync`. `grep -rn "Cache::\|->remember(\|dispatch(" app/` finds no report caching or jobs; `app/Jobs` does not exist; routes/console.php contains only the default `inspire` command. .cpanel.yml indicates shared cPanel hosting.
```

**Why it matters**

Every report is recomputed from raw rows on each click; the admin 'all centers'/'teachers' PDFs scale with total data and run inside the web request under shared-host time/memory limits (typically 30s/128MB). Dozens of centers hitting month-end reports simultaneously will serialize on a single-threaded host.

**Recommendation**

Introduce a read model: nightly (or on-write) materialised `student_month_stats` / `center_month_stats` tables via a scheduled command, so reports read pre-aggregated rows; cache rendered PDFs for closed months by (report, scope, period) key; move heavy PDFs to a queued job with a 'ready' notification once a worker is available (database queue + cron `schedule:run` works on cPanel).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

All quoted evidence is accurate: ReportPdfController::render() (lines 37-73) constructs a fresh Mpdf instance and calls WriteHTML per request and returns the PDF as a string in the same HTTP response; .env.production.example:60-62 sets QUEUE_CONNECTION=sync and states there is no queue worker; routes/console.php has only `inspire`; no app/Jobs directory; no Cache::/remember()/dispatch() anywhere in app/; .cpanel.yml exists at repo root. Nothing mitigates it elsewhere: no throttle on the PDF routes (only login/OTP routes are throttled in routes/api.php), no set_time_limit/memory_limit tuning, no frontend debounce/disable on the report buttons (UI.openPdf fires on every click), and ManagerReportsTest only covers scoping/authorization, not performance. ReportService also does per-student N+1 queries (studentSummaryRow per student in teacherGroupData; centerData per center in allCentersData; per-teacher student/attendance/test fetches in teachersPerformance), so the 'all centers' and 'teachers' admin PDFs do scale with total data. However, the severity is over-rated: this is a small-team, Libyan Quran-center system with a handful of centers, month-scoped queries (whereMonth/whereYear), and mPDF rendering of tabular Arabic text is typically well under a second for hundreds of rows. Data volumes will not approach 30s/128MB limits; 'dozens of centers hitting month-end reports simultaneously' is not a realistic load profile for this product. It is a valid architectural observation (synchronous, uncached, N+1 aggregation) but a low-severity one with no current correctness defect. The auditor's own recommendation (materialised tables, queued jobs, cron) is disproportionate; fixing the N+1 aggregation in ReportService alone would remove most of the risk.

```text
backend/app/Http/Controllers/Api/ReportPdfController.php:50-66 (new Mpdf + WriteHTML + STRING_RETURN inside the request); backend/app/Services/ReportService.php:110-117 (teacherGroupData maps studentSummaryRow per student, each doing 2 queries at :81-89), :165-168 (allCentersData calls centerData per center, 4 queries each at :135-144), :176-189 (teachersPerformance: 3 queries per teacher) — N+1 aggregation is the real scaling cost, not mPDF; backend/routes/api.php:80-82,125-128,170-171 (PDF routes have no throttle middleware — only :17,20,21 are throttled); frontend-html/admin/reports.html:79-82 (UI.openPdf fires on every click, no disable); .cpanel.yml exists at repo root (not under backend/ as one might read the finding); backend/.env.production.example:60-62 confirmed.
```

</details>

### Five report JSON endpoints have no UI consumer and two return raw Eloquent models

<a id="orphan-and-unstable-json-report-endpoints"></a>

`orphan-and-unstable-json-report-endpoints` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `backend/routes/api.php:73-74`, `backend/routes/api.php:122`, `backend/routes/api.php:155`, `backend/routes/api.php:166-167`, `backend/app/Http/Controllers/Api/ReportController.php:33-49`

**Evidence**

```text
`grep -rn "reports/weekly\|reports/student\|attendance/report\|missing-national-id\|reports/management" frontend-html/` finds no consumer for `/reports/weekly`, `/reports/student/{id}` (JSON), `/attendance/report`, `/reports/admin/missing-national-id` or `/manager/reports/management` (manager/reports.html uses only /system for atRisk). ReportController::student returns `'student' => $d['student']` (full Student model incl. guardian_phone/national_id), `'attendances' => $d['attendances']` (all rows, unpaginated) and `'tests'` with nested questions (33-49); weekly returns `'student' => $student` models (102).
```

**Why it matters**

Unused endpoints rot without tests (see coverage finding) yet remain attack surface; raw-model responses leak whatever columns are added later and give the mobile team an unstable contract to code against.

**Recommendation**

Decide per endpoint: wire it into a page (weekly/attendance report are natural teacher mobile screens; missing-national-id is a real admin need) or remove it. Convert student/weekly responses to explicit resource arrays (API Resources) with only the fields the client needs.

### month/year query parameters are cast but never validated; label and data can disagree

<a id="no-period-validation"></a>

`no-period-validation` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/ReportPdfController.php:22-32`, `backend/app/Http/Controllers/Api/ReportController.php:27-28`, `backend/app/Http/Controllers/Api/CenterManagerController.php:596-599`

**Evidence**

```text
`$month = (int) $request->get('month', now()->month); $year = (int) $request->get('year', now()->year);` (ReportPdfController.php:24-25) then `Carbon::create($year, $month, 1)->locale('ar')->isoFormat('MMMM YYYY')` (31). month=13 → Carbon overflows to January next year in the label while `whereMonth('date', 13)` returns no rows; month=abc → 0 → label 'ديسمبر 2025' with empty data; ReportController::student passes the raw string to whereMonth (27-28, 38). No FormRequest, no 422 envelope.
```

**Why it matters**

Garbage-in produces a 200 PDF with a misleading period header; mobile clients get no validation error to display; inconsistent with the platform's 422-Arabic-errors contract.

**Recommendation**

Validate `month` (integer|between:1,12) and `year` (integer|between:2020,2100) in a shared `ReportPeriodRequest`/trait used by all three controllers, returning the standard 422 envelope; treat future periods explicitly.

### No xlsx/csv export and no in-app scheduled/e-mailed reports; only an external n8n digest with a divergent formula

<a id="no-export-no-scheduling"></a>

`no-export-no-scheduling` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `backend/composer.json:12`, `backend/.env.production.example:64-66`, `n8n/README.md:7-15`, `n8n/attendance-digest.code.js:17`, `n8n/mutqin-daily-attendance-digest.json:70-123`

**Evidence**

```text
composer.json lists `mpdf/mpdf` but no spreadsheet writer; the only xlsx code is the attendance import. `MAIL_MAILER=log` with comment 'لا بريد صادر في المشروع أصلاً' (.env.production.example:64-66); routes/console.php has no scheduled commands. The n8n workflow logs in with an admin password every night (`POST /api/auth/login`, workflow json:70-87), never logs out (no logout node), and computes `rate = (present + late) / total` (code.js:17), unlike the API.
```

**Why it matters**

Awqaf/ministry reporting and center bookkeeping need spreadsheets; managers cannot subscribe to a monthly report; the digest creates a new 7-day `*`-ability token per run and reports a different attendance rate than the app.

**Recommendation**

Add CSV/xlsx export for the tabular reports (teacher group, at-risk, all centers) via a small writer (PhpSpreadsheet is already a transitive dependency of the import); add a scheduled command + mail/notification channel for monthly manager summaries; make the digest reuse a server-side endpoint (or a long-lived scoped token) and the shared metric glossary.

### PDFs use mPDF's bundled fallback font instead of brand fonts and have no page numbers or repeating header

<a id="pdf-typography-pagination"></a>

`pdf-typography-pagination` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `backend/resources/views/pdf/layout.blade.php:6`, `backend/resources/views/pdf/layout.blade.php:24`, `backend/resources/views/pdf/layout.blade.php:55-57`, `backend/app/Http/Controllers/Api/ReportPdfController.php:50-63`, `frontend-html/css/theme.css:6-17`

**Evidence**

```text
`body { font-family: dejavusans; ... }` (layout.blade.php:6) and a dead class `.amiri { font-family: dejavusans; }` (24) — the brand fonts Amiri/Cairo declared in theme.css:6-17 are not embedded; no `fontDir`/`fontdata` config in the Mpdf constructor (ReportPdfController.php:50-59). The footer is an inline `<div class="foot">` at the end of content (55-57); there is no `SetHTMLHeader`/`SetHTMLFooter` or `{PAGENO}`, so a long student or all-centers report has unnumbered continuation pages without the brand header.
```

**Why it matters**

Official-looking documents handed to parents/awqaf look off-brand and are hard to reference page-by-page; the digits/fallback behaviour the CLAUDE.md warns about depends on the bundled font.

**Recommendation**

Register Amiri/Cairo TTFs with mPDF (`fontDir` + `fontdata`) and use them for body/headings; add `SetHTMLFooter` with `{PAGENO}/{nbpg}` and generation timestamp, `SetHTMLHeader` with the brand strip; add `SetTitle`/`SetAuthor` metadata.

## Measured facts

| Metric | Value |
|---|---|
| ReportService size | 562 lines, 13 public + 2 protected methods (backend/app/Services/ReportService.php) |
| PDF endpoints | 9 (admin 4 incl. center/{id\|all}, manager 3, teacher 2); mPDF v8.3.1; 7 Blade templates / 346 lines |
| JSON report endpoints by role | manager 5 (+1 teachers/{id}/performance), teacher 2 (+ /attendance/report), admin 1 (missing-national-id), parent 0 |
| Report JSON endpoints with no frontend consumer | 5 (/reports/weekly, /reports/student/{id}, /attendance/report, /reports/admin/missing-national-id, /manager/reports/management) |
| Report tests | 3 files / 9 test methods; PDF routes exercised by tests: 1 of 9 (11%); teacher ownership guard on report routes: 0 tests |
| Feature test files | 38 in tests/Feature (CLAUDE.md says 20) |
| N+1 report paths | 4 of 13 service/controller aggregation paths (teacherGroupData, allCentersData, teachersPerformance, ReportController::weekly) |
| Non-sargable period filters | 17 whereMonth/whereYear occurrences across report-related files |
| Aggregation sites outside ReportService | 4 controllers (CenterManagerController::teacherPerformance, CenterController::stats, StudentController::parentStudentDetails, DashboardController) |
| Async/caching infrastructure | QUEUE_CONNECTION=sync (prod), 0 jobs, 0 scheduled commands, 0 Cache usages in reports, MAIL_MAILER=log |
| Export formats | PDF only (0 xlsx/csv report exports; xlsx exists for import only) |
| Frontend report pages | 3 (admin 86 lines, manager 269, teacher 74); parent 0; parent nav items 2 (no reports) |
| At-risk thresholds | attendance < 70% (present-only) OR failed tests >= 2 per month; global constants, not configurable |

## Auditor notes

Additional lower-priority observations not listed as findings: (a) ReportPdfController::student uses `abort(403, ...)` (line 84) instead of the `{success,message}` envelope used everywhere else, so with `Accept: application/pdf` the client gets a non-JSON 403 and ui.js falls back to a generic message. (b) manager/reports.html locks all three PDF buttons to the current month (`curPeriod`, lines 28, 92-94) — managers cannot print a past month, while the JSON sections are all-time; the two time bases on one page are not explained to the user. (c) The student PDF prints display-only `guardian_name/guardian_phone` (student.blade.php:25) rather than the linked parent (parent_id), unlike studentAllTime which prefers the parent record. (d) manager/reports.html:227 displays the raw stored `x.juz` for recent memorizations, which CLAUDE.md itself calls unreliable. (e) Admin passing the teacher gate can call `/reports/teacher/pdf` and gets an empty 'my students' PDF (teacherGroupData uses the caller's id). (f) PDF colour thresholds (75/50, 70) are hard-coded per template rather than taken from constants. (g) n8n digest creates a fresh `*`-ability Sanctum token nightly with no logout. (h) `storage/app/mpdf` is created with `@mkdir` at request time (ReportPdfController.php:39-42) — on read-only or mis-permissioned hosting this surfaces as a 500 with no actionable message. Doc drift observed: CLAUDE.md states weekly_tests went `date→exam_date→date` but the live column is `exam_date` (WeeklyTest.php:12; migration 2026_05_11 renames date→exam_date in up()); CLAUDE.md omits `/manager/reports/center|teacher/{id}|student/{id}`, `/manager/teachers/{id}/performance`, `/centers/{id}/stats|teachers|students`, the messaging routes and AdminUserController; CLAUDE.md says 20 feature-test files vs 38 present; دليل-محتوى-الصفحات.md describes teacher report pages as `.blade.php` (e.g. `teacher/reports/student.blade.php`, `teacher/reports/weekly.blade.php`) that do not exist in the static frontend. Environment note: PHP and vendor/ are not present in this checkout (C:\xampp\php\php.exe missing, backend/vendor ignored and absent), so mPDF font behaviour and Carbon locale digit output could not be executed — statements about fonts are based on the template CSS and mPDF constructor options only.
