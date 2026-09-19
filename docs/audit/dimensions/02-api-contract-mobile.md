# API Contract & Mobile Readiness

[← Enterprise Audit](../enterprise-audit.md)

**Score 60 / 100** — Needs real work · maturity **L2** · weight 12% · auditor scored 55, judge calibrated to 60

The hand-written API is internally disciplined: 147 of 149 explicit response()->json() sites in backend/app/Http/Controllers/Api/*.php carry the {success,message,data,errors} envelope, Arabic field-keyed 422s are the norm, every protected route is behind the dual role+ability gates, and manager/teacher scoping is enforced server-side. But as a *contract a Flutter client can depend on* it is not yet defined or enforced anywhere: bootstrap/app.php:22-24 leaves withExceptions() empty, so every framework-rendered response (401, 404 via 38 findOrFail/abort sites, 405, 422 via 36 $request->validate() sites, 429, 500) escapes the envelope and an unauthenticated request without Accept: application/json is redirected instead of getting a 401; five date-cast columns serialize as UTC-shifted ISO datetimes (day-off bug already visible in teacher/weekly-tests.html:33); list endpoints flip between paginator-object and plain-array on ?all=1 and between {} and [] on empty pluck(); there is no OpenAPI/Postman artefact, no /v1 prefix, no API Resource layer, no lang/ar so uncustomised validation rules return English, no token expiry/refresh metadata, no push/device-token or app-version endpoints, and center managers have neither forgot-password nor change-password. The conventions are repeatable (level 2) because developers follow them by habit, but nothing—handler, resources, spec, or contract tests (6 'success' assertions, 1 assertJsonStructure in 173 tests)—makes them defined or measured. That places it in the 'significant risk' band, high end: functional web API, not yet a mobile-grade contract.

> **Calibration:** All four high findings were downgraded to medium and all mediums to low on verification, leaving no confirmed high; a dimension whose worst surviving defect is medium belongs at the boundary of 'functional but needs real work', not mid 'significant risk', and its strengths (147/149 envelope discipline, dual gating, idempotent attendance) match backend-architecture which scored 61 on the same evidence.

## What is already strong

- Explicit envelope discipline: 147/149 response()->json() calls in app/Http/Controllers/Api include 'success'; validation messages are hand-written Arabic per rule in nearly every controller (e.g. StudentController.php:218-228, MemorizationController.php:139-151).
- Dual role + Sanctum-ability gating on every protected route (routes/api.php:43-172; Middleware/AdminMiddleware.php:17, TeacherMiddleware.php:17, ParentMiddleware.php:16, CenterManagerMiddleware.php:19), with abilities issued per role at AuthController.php:62-64 and verified by RoleMatrixTest.php:31-40 and a 40-file / 173-method feature suite (tests/Feature).
- Server-side scoping never trusts client ids: manager center_id forced at StudentController.php:172-174 and CenterManagerController.php:106; teacher ownership guards return 403 with Arabic messages (StudentController.php:346-350, 401-405).
- Good conflict semantics on bulk attendance: 409 with data.conflicts and explicit confirm=true, backed by a unique (student_id,date) constraint and a transaction (AttendanceController.php:73-116) — a genuinely idempotent, retry-safe write.
- Aggregation endpoints built for scale without N+1 (single selectRaw/groupBy queries): CenterController.php:98-121 stats, CenterManagerController.php:305-325 teacherPerformance, StudentController.php:416-425 teacherDetails, MessageController.php:166-173 threads.
- Sanctum tokens expire after 7 days (config/sanctum.php:52 'expiration' => 10080) and are revoked on password change (User.php:81) and deactivation (TeacherController.php:210-212, CenterController.php:265-269); CORS is closed by default (config/cors.php:16-19) and Authorization header pass-through is handled in public/.htaccess.
- Newer endpoints already emit clean Y-m-d dates and integer aggregates (CenterManagerController.php:545 'date' => $a->date?->toDateString(); Percentage::of() returns int; ReportService casts month/year to int at lines 71-72,125-126,157-158), showing the team knows the right shape.
- Web client cross-check is clean: 76 literal + 8 dynamic API call sites across 33 pages (frontend-html) all resolve to existing routes; no frontend call targets a missing endpoint.

## Level-5 target state

Level 5 for this dimension means the API is a versioned, published contract that both the web client and the Flutter app are generated from and tested against: /api/v1 with an OpenAPI 3.1 spec committed and enforced in CI (route count, response schemas, error codes), JsonResource classes as the single definition of every entity shape, and a global exception renderer guaranteeing the {success,message,code,data,errors} envelope on every status including 401/404/422/429/500 regardless of Accept header. Lists share one paginated shape with per_page and cursors; dates are Y-m-d and offset-bearing ISO-8601 by rule; enums and error codes are stable machine strings with Arabic labels layered on top. Mobile lifecycle needs are first-class: device-named tokens with expiry metadata and refresh, logout-all, device registration + FCM push, /health and /app-config for monitoring and forced upgrades, idempotency keys on writes, and signed URLs for documents. Contract regressions are caught by schema snapshot tests before they reach any installed app, and payload-size and query budgets are measured per endpoint.

## What the Flutter team must know

1) Always send `Accept: application/json` (and `application/json` alongside `application/pdf` for reports) — without it an expired token yields a 302/200 or a 500, not a 401. 2) Do not trust the `success` key on error paths: parse `{message, errors}` when `success` is absent (Laravel-rendered 401/404/405/422/429/500); branch on HTTP status, not on body shape. Bad credentials come back as 422 with `errors.email`, inactive account/center as 403. 3) Parse dates defensively: date-only fields (`date`, `exam_date`, `enrollment_date`, `birth_date`) arrive as `...T22:00:00.000000Z` from raw-model endpoints (convert with `DateTime.parse(...).toLocal()` in Africa/Tripoli, never `.substring(0,10)`), as plain `Y-m-d` from /manager/attendance and /students/{id}/day, and `created_at`/`read_at` as UTC ISO; `corrected_at` on /manager/attendance is local `Y-m-d H:i:s` without offset. 4) `data` is polymorphic: paginated endpoints return a paginator object (`data.data`, `current_page`, `last_page`, `total`, `per_page`) but `?all=1` returns a bare list; `attendances` in GET /attendance and /attendance/report is a Map keyed by student id when non-empty and an empty List when empty; parent/students/{id} paginates three sub-lists with `memo_page`/`att_page`/`tests_page`. Page sizes are fixed (5/10/15/20) — no `per_page`. 5) Auth: POST /auth/login {email (or display code T1/CA1/P1), password} → `data.token` + `data.user{id,name,email,phone,role,center_id,center_name,type}`; no `expires_at` — tokens die 7 days after creation (not sliding), so implement 'on 401 → clear and re-login'; no refresh or logout-all; every login creates a new token (pass nothing for device). GET /auth/user validates a stored token at boot. Managers have no forgot-password and only teachers/admins can use /profile* — hide password-change for parent/manager until the backend adds it. 6) Role routing: parent → /parent/*, center_manager → /manager/*, teacher → /students, /attendance, /memorizations, /weekly-tests, /reports/*, /profile*, /teacher/messages; admin passes the teacher gate and its own /teachers, /centers, /admin/*. Generic GET /dashboard is only meaningful for admin/teacher (it labels manager/parent as role 'teacher' with empty stats) — use /manager/dashboard and /parent/children instead. 7) Enums are mixed-language literals to hard-code exactly: teacher type `محفظ أساسي`/`محفظ معاون`, test result `ناجح`/`راسب`, quality `excellent|good|average|weak`, attendance `present|absent|late`, request type `add|transfer`, status `pending|approved|rejected`, nationality `libyan|foreigner`; surahs are identified by exact Arabic name from GET /memorizations/surahs. 8) Writes: POST /attendance is safe to retry (409 + `data.conflicts` → resend with `confirm:true`); POST /memorizations, /messages, /manager/student-requests are not — debounce and disable buttons. 9) PDFs: fetch bytes with the bearer header (Dio responseType bytes), save, open with a viewer; no URL can be shared directly. 10) Notifications: poll GET /notifications (unread_count + last 30, no cursor); `link` is a web HTML path — map by `type` (request_created/approved/rejected, manager_deactivated, memorization_added, test_added, message_received) and parse the query string for ids since `ref_id` is not returned; no push yet. 11) Rate limits: login 10/min per IP (shared carrier NAT can trigger 429 for innocent users) and Laravel's default 60 req/min per user on the api group — batch dashboard calls and honour `Retry-After`. 12) Uploads: attendance import is multipart field `attendance_file` (.xlsx ≤5 MB) and its response is not enveloped (`imported`, `present`, `errors[]` rows at top level). 13) Sentinels: some read models use `'--'`/`'—'` strings instead of null for missing center/teacher names.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [401/404/405/422/429/500 rendered by Laravel bypass the envelope; unauthenticated requests without Accept: application/json are redirected, not 401](#framework-errors-escape-envelope) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Date-only columns serialize as UTC ISO datetimes shifted to the previous day; date formats are mixed across endpoints](#date-only-fields-utc-shifted) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [List responses switch between paginator object and plain array (?all=1), between {} and [] when empty, with hard-coded page sizes and no per_page](#pagination-shape-polymorphic) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [No OpenAPI/Postman/API-Resource contract artefact; CLAUDE.md is the only route documentation and has drifted from the code](#no-openapi-contract) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | L |
| [Center managers have no forgot-password and no change-password path; parents cannot change their password while logged in (/profile is teacher-gated)](#self-service-gaps-manager-parent) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [All routes live under /api with no version segment; a shipped mobile client would pin an un-versioned contract](#no-api-versioning) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Login returns no expiry or abilities, no device name, no refresh or logout-all; 7-day hard expiry forces weekly re-login; bad credentials are 422](#token-lifecycle-not-mobile-shaped) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | M |
| [Notifications are database-only with 60s web polling; no FCM/APNs device registration, no unread badge push, notification links are web HTML paths and ref_id is not exposed](#no-push-or-device-token-endpoints) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | L |
| [Errors carry only Arabic human strings — no stable machine-readable code; business-rule failures reuse 422/403/404 inconsistently](#errors-arabic-strings-only) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Several endpoints return the whole table (or all rows for admin) with no cap, and one report is N+1 per student](#unbounded-list-payloads) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Raw Eloquent models are returned as `data`, leaking schema columns and PII and making the same entity appear with different shapes per endpoint](#raw-eloquent-serialization) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | L |
| [No lang/ar directory: any rule without a hand-written message falls back to English; no Accept-Language handling](#validation-messages-mixed-language) | 🟡 medium | ✅ confirmed | NEXT | S |
| [No JSON health check with DB probe, no app-version/force-update or server-time endpoint](#no-health-version-endpoints) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Create endpoints other than attendance have no idempotency key or natural uniqueness, so mobile retries duplicate records](#write-idempotency-missing) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [PDF reports are bearer-only inline responses with no signed/temporary URL alternative and no JSON fallback on failure](#pdf-requires-bearer-no-signed-url) | ⚪ low | ℹ️ informational | LATER | M |

### 401/404/405/422/429/500 rendered by Laravel bypass the envelope; unauthenticated requests without Accept: application/json are redirected, not 401

<a id="framework-errors-escape-envelope"></a>

`framework-errors-escape-envelope` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/bootstrap/app.php:22-24`, `backend/routes/web.php:5-10`, `backend/app/Http/Controllers/Api/StudentController.php:343`, `backend/app/Http/Controllers/Api/ReportPdfController.php:84`, `frontend-html/js/ui.js:355-358`, `frontend-html/js/api.js:30`

**Evidence**

```text
bootstrap/app.php:22-24: `->withExceptions(function (Exceptions $exceptions): void {\n        //\n    })` — no shouldRenderJsonWhen(), no render() callbacks. routes/web.php defines only `Route::get('/', ...)` returning `{'message':'Welcome to MUTQEN API Backend','status':'online'}`; there is no route named 'login'. 36 `$request->validate(` sites + 5 `ValidationException::withMessages` and 38 `findOrFail`/`abort(` sites (grep counts) all render through the default Handler → `{"message":...,"errors":{...}}` (422) / `{"message":"No query results for model [App\\Models\\Student] 5"}` (404) / `{"message":"Unauthenticated."}` (401) — no `success` key. The web wrapper survives only because api.js:30 always sends `'Accept': 'application/json'` and checks `!res.ok`. ui.js:356 sends `'Accept': 'application/pdf'` for PDFs, so an expired token there does not hit the `res.status === 401` branch at :358 — Laravel's non-JSON path redirects to `/` (or throws Route [login] not defined) and the 200 welcome JSON is opened as a 'PDF'. All 173 tests use getJson/postJson (131+126) so the no-Accept path is never exercised.
```

**Why it matters**

A Flutter client (Dart http/Dio send no JSON Accept header by default) gets HTTP 200/302 for expired sessions and un-enveloped bodies for every framework error, so a single typed `ApiEnvelope.fromJson` parser breaks on exactly the paths that matter most (auth expiry, validation, not-found, rate-limit, server error). Every mobile screen must special-case two response shapes and status-code-only auth handling.

**Recommendation**

In withExceptions(): `$exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());` then `$exceptions->render()` for AuthenticationException (401), ValidationException (422), ModelNotFoundException/NotFoundHttpException (404), MethodNotAllowedHttpException (405), ThrottleRequestsException (429), AuthorizationException (403) and a catch-all Throwable (500) that all emit `{success:false, message, errors, data:null}` (Arabic messages, no class names). Add a feature test that hits an auth:sanctum route with no Accept header and asserts 401 + envelope. Optionally add a ForceJsonResponse middleware on the api group. Fix ui.js openPdf to send `Accept: application/pdf, application/json`.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

Core facts verified: backend/bootstrap/app.php:22-24 has an empty withExceptions() (no shouldRenderJsonWhen/render callbacks); routes/web.php defines only GET '/' and there is no route named 'login'; app/Http/Middleware contains only the four role gates (no ForceJson middleware); 36 `$request->validate(` and 38 `findOrFail`/`abort(` sites in controllers with zero `catch (ValidationException` blocks, so all of those render through Laravel 11's default Handler, which emits `{message, errors}` (422) / `{message: "No query results for model [App\Models\Student] N"}` (404) / `{message: "Unauthenticated."}` (401) without the `success` key when Accept: application/json is present, and redirect()/route('login') paths when it is absent. Tests use getJson/postJson (or `authed()->get` with a valid token), so the no-Accept unauthenticated path is indeed untested. The role middlewares themselves DO return the envelope (403 with success:false), so only framework-generated errors escape.

However, the finding is exaggerated in two ways. (1) The shipping client is fully mitigated: api.js:28 always sends Accept: application/json, and its error path (api.js:65-71) reads `data.message` / `data.errors` — exactly the keys Laravel's default handler emits — and gates on `!res.ok`, so validation errors, 404s and 401s are already handled correctly today; the missing `success:false` is cosmetic for this client. (2) The PDF scenario is mis-described: with `Accept: application/pdf` and an expired token, Laravel's Handler::unauthenticated() calls route('login'), which throws RouteNotFoundException → HTTP 500, not a 302 to '/' returning 200 welcome JSON. ui.js:359 (`if (!res.ok)`) catches that 500 and shows a toast ("تعذّر إنشاء التقرير (500)"); the user is not silently shown a fake PDF, they just miss the redirect-to-login (minor UX). The mobile-client impact is hypothetical (no mobile client exists in the repo or CLAUDE.md), and a Dio/http client fixes it with a one-line default Accept header. Residual real issues: contract inconsistency for framework errors, English messages ("Unauthenticated.", "Too Many Attempts.", "The given data was invalid") mixed into an Arabic-only API, model class-name leak in 404 bodies, and no JSON forcing for api/* — a valid hardening item but not high severity for this product.

```text
backend/bootstrap/app.php:22-24 — `->withExceptions(function (Exceptions $exceptions): void { // })` (empty, confirmed). backend/routes/web.php:5-10 — only `Route::get('/')`; no named 'login' route (confirmed; grep of routes/api.php shows only POST /auth/login, unnamed). app/Http/Middleware/AdminMiddleware.php:17-21 — role gates DO return `{success:false, message}` 403, so only framework exceptions escape the envelope. Controllers: 36 `$request->validate(` sites, 38 `findOrFail`/`abort(` sites, 0 `catch (ValidationException` (grep counts confirmed). frontend-html/js/api.js:28 — `const headers = { 'Accept': 'application/json' };` and :65-71 — error path uses `data.message` / `data.errors` with `!res.ok`, so the web client already copes with Laravel's default `{message, errors}` shape. frontend-html/js/ui.js:356-365 — openPdf sends `Accept: application/pdf`; on expired token Laravel's Handler::unauthenticated() → `route('login')` → RouteNotFoundException → 500 (not 302/200), caught by `if (!res.ok)` at :359 as a generic toast — the "welcome JSON opened as PDF" claim is incorrect. tests/Feature/ManagerReportsTest.php:82 uses `->get('/api/reports/admin/at-risk/pdf')` without Accept but with a valid token, so the unauthenticated no-Accept path remains untested (confirmed). Note: backend/vendor and backend/.env are absent in this checkout, so behavior was traced from Laravel 11 framework source knowledge, not executed.
```

</details>

### Date-only columns serialize as UTC ISO datetimes shifted to the previous day; date formats are mixed across endpoints

<a id="date-only-fields-utc-shifted"></a>

`date-only-fields-utc-shifted` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/config/app.php:70`, `backend/app/Models/Attendance.php:22-26`, `backend/app/Models/Memorization.php:23-25`, `backend/app/Models/WeeklyTest.php:17-19`, `backend/app/Models/Student.php:30-35`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:25-30`, `backend/app/Http/Controllers/Api/CenterManagerController.php:545-547`, `frontend-html/teacher/weekly-tests.html:33`

**Evidence**

```text
config/app.php:70 `'timezone' => 'Africa/Tripoli'`. Casts: Attendance `'date' => 'date'`, Memorization `'date' => 'date'`, WeeklyTest `'exam_date' => 'date'`, Student `'birth_date' => 'date', 'enrollment_date' => 'date'`. No model or base class overrides serializeDate (grep app/ for serializeDate → 0 hits) and there is no Resource layer, so Eloquent's default Carbon::toJSON() converts midnight Tripoli to UTC: a stored 2026-09-14 is emitted as `"2026-09-13T22:00:00.000000Z"` wherever raw models are returned (WeeklyTestController.php:25-30 `$query->paginate(15)` → `'data' => $tests`; MemorizationController.php:56-61; StudentController.php:354-364; AttendanceController.php:23-38). Meanwhile CenterManagerController.php:545 emits `'date' => $a->date?->toDateString(), // Y-m-d نظيف` and :547 `corrected_at?->toDateTimeString()` (local, no offset), and StudentController.php:464/482 returns `'date' => $date` as a plain Y-m-d string — three date dialects on one API. The web client already trips on it: teacher/weekly-tests.html:33 `String(existing.exam_date).slice(0, 10)` yields the previous day for every edit form.
```

**Why it matters**

Any device whose timezone is not UTC+2 (diaspora users, phones set to UTC, CI emulators) displays and re-submits the wrong day for attendance, memorization and exam dates; the web edit flow silently rewinds exam_date by one day on save. A Flutter team must know per-field which of three formats to parse.

**Recommendation**

Standardize: date-only columns cast `'date:Y-m-d'` (or override serializeDate on a base model to `format('Y-m-d')` for date casts and `toIso8601String()` with explicit +02:00 offset for datetimes). Document 'dates are Y-m-d in Africa/Tripoli; timestamps are ISO-8601 with offset'. Add a contract test asserting `data.data[0].exam_date` matches /^\d{4}-\d{2}-\d{2}$/. Fix weekly-tests.html:33 once the API is fixed.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 82% · corrected severity: medium

The finding is factually correct on every point I could verify. (1) backend/config/app.php:70 sets 'timezone' => 'Africa/Tripoli' (UTC+2, no DST). (2) The plain 'date' casts exist exactly as quoted: Attendance.php:23, Memorization.php:24, WeeklyTest.php:18, Student.php:31-32 (plus Revision/TajweedEvaluation). (3) grep of backend/app/ for serializeDate returns zero hits, there is no JsonResource layer, and composer.json pins laravel/framework ^11.0 — Laravel 7+ HasAttributes::serializeDate() calls Carbon::toJSON(), which is toISOString() and converts to UTC, so a date-cast 2026-09-14 (midnight Tripoli) serializes as 2026-09-13T22:00:00.000000Z. I could not execute this in tinker because backend/vendor/ is not installed in this checkout (php only via Herd, no autoload), but the framework behavior is well established and no override exists anywhere in app/. (4) WeeklyTestController::index returns $query->paginate(15) raw (lines 25-30), CenterManagerController.php:545/547 hand-format toDateString()/toDateTimeString(), and StudentController.php:464/770/783 mix plain strings and raw Carbon — three formats on one API as claimed. (5) The concrete client bug is real: teacher/weekly-tests.html:165 passes the raw list row to openTestModal, line 33 does String(existing.exam_date).slice(0,10) → previous day, line 124 PUTs f.exam_date.value, and WeeklyTestController::update (lines 130,147) validates 'required|date' and writes it. So every edit-save that does not touch the date field silently rewinds exam_date by one day, compounding on repeated edits — independent of browser timezone. No feature test asserts the serialized date shape (tests only use toDateString()/whereDate on the DB side). Mitigations found: UI.fmtDate (js/ui.js:122-126) uses new Date(d).toLocaleDateString('ar-LY'), which converts the UTC instant back to browser-local time, so display is correct for the actual user base (browsers set to Libya time); only the edit form's slice(0,10) bypasses that. Given the product is single-country web-only today, the UTC-shift display impact for non-+2 devices is mostly theoretical, but the weekly-test edit flow is a genuine silent data-corruption bug and the mixed date dialects are a real contract defect for any mobile client. Real, but "high" overstates it: no security or auth impact, one affected write path, trivial fix (cast 'date:Y-m-d' or serializeDate override). Medium.

```text
backend/config/app.php:70 'timezone' => 'Africa/Tripoli'; backend/app/Models/WeeklyTest.php:18 'exam_date' => 'date'; backend/app/Models/Attendance.php:23, Memorization.php:24, Student.php:31-32 same plain 'date' cast; no serializeDate override anywhere in backend/app/ and no Resource classes; backend/app/Http/Controllers/Api/WeeklyTestController.php:25-30 returns raw paginate(15); :130 update validates 'exam_date' => 'required|date' and :147 writes $request->exam_date; backend/app/Http/Controllers/Api/CenterManagerController.php:545 toDateString(), :547 toDateTimeString(); backend/app/Http/Controllers/Api/StudentController.php:464, :770, :783 plain/raw date values. frontend-html/teacher/weekly-tests.html:33 String(existing.exam_date).slice(0,10) → prior day; :124 PUT exam_date: f.exam_date.value; :164-165 openTestModal(t) with the raw list row. Mitigation: frontend-html/js/ui.js:122-126 UI.fmtDate converts via toLocaleDateString so read-only displays are correct for browsers in Libya time. Could not run tinker (backend/vendor missing; C:\xampp absent) — framework behavior asserted from Laravel 11 HasAttributes::serializeDate → Carbon::toJSON (UTC).
```

</details>

### List responses switch between paginator object and plain array (?all=1), between {} and [] when empty, with hard-coded page sizes and no per_page

<a id="pagination-shape-polymorphic"></a>

`pagination-shape-polymorphic` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/TeacherController.php:41-50`, `backend/app/Http/Controllers/Api/StudentController.php:132-142`, `backend/app/Http/Controllers/Api/CenterController.php:21-30`, `backend/app/Http/Controllers/Api/CenterManagerController.php:188-194`, `backend/app/Http/Controllers/Api/AttendanceController.php:29-38`, `backend/app/Http/Controllers/Api/AttendanceController.php:139-155`, `backend/app/Http/Controllers/Api/StudentController.php:764-801`

**Evidence**

```text
TeacherController.php:41-45 `if ($request->has('all') && $request->all == 1) { $teachers = $query->get(); } else { $teachers = $query->paginate(20)->withQueryString(); }` then `'data' => $teachers` — same pattern at StudentController.php:132-137, CenterController.php:21-25, CenterManagerController.php:191-193: `data` is a raw LengthAwarePaginator (`{current_page,data,links,path,per_page,total,...}`) or a bare array depending on a query flag. Page sizes are fixed per endpoint with no per_page input (grep per_page in controllers → 0 hits): 20 (users, students, teachers, parents, attendance), 15 (memorizations, weekly-tests), 10 (centers), 5 (centers/{id}/teachers|students, parent sub-lists). AttendanceController.php:29 `->pluck('status', 'student_id')` and :143 `->groupBy('student_id')` serialize as a JSON object keyed by student id when non-empty and `[]` when empty. parent/students/{id} uses three custom page params `memo_page`/`att_page`/`tests_page` (StudentController.php:766,781,801). Non-paginated growing lists: /admin/managers (get()), /manager/student-requests?status=all (get()), /notifications (limit 30, no cursor), messages thread (limit 100, no cursor), /parent/children, /students-progress.
```

**Why it matters**

Dart's strong typing turns each polymorphic field into a runtime cast failure (`List<dynamic>` vs `Map<String,dynamic>`); the client cannot tune page size for small screens or infinite scroll; notification and message histories are capped without a way to page further; the frontend reaches for ?all=1 (used 11 times) because 20/page is not adjustable, producing unbounded payloads (see unbounded-list-payloads).

**Recommendation**

Adopt one list shape everywhere: `data: [...]` plus `meta: {current_page, per_page, total, last_page}` (Laravel ResourceCollection does this); accept `per_page` (default 20, max 100); remove the ?all=1 branch in favour of a capped per_page; return `{}` explicitly for keyed maps (`(object) $pluck->all()`) or better, return arrays of `{student_id,status}`; add cursor pagination (`?before=<id>`) to notifications and messages. Cover with an assertJsonStructure contract test per list endpoint.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The evidence is factually accurate. Confirmed by reading the files: TeacherController.php:41-45, StudentController.php:132-137, CenterController.php:21-25 and CenterManagerController.php:191-193 all return `data` as either a raw LengthAwarePaginator object or a bare array depending on `?all=1`. `grep per_page backend/app` returns 0 hits; page sizes are hard-coded (20/15/10/5 per endpoint, 14 paginate() call sites). AttendanceController.php:29 `pluck('status','student_id')` and :143 `groupBy('student_id')` serialize as a keyed object when non-empty and `[]` when empty (PHP empty array). StudentController.php:766/781/801 use custom `memo_page`/`att_page`/`tests_page` params. NotificationController.php:20 `limit(30)` and MessageController.php:121 `limit(100)` have no cursor. The frontend calls `?all=1` 15 times. There is no app/Http/Resources directory, so no ResourceCollection normalizes shapes. However, the severity is overstated. Nothing is broken for the only existing consumer: the shape is deterministic given the query flag (the client opts into `?all=1` explicitly and each page knows which shape it requested), and the frontend guards the attendance map with `res.data.attendances || {}` (teacher/attendance.html:39), which tolerates both `{}` and `[]`. PaginationSearchTest.php and ParentChildPaginationTest.php already assert the paginator shape (`data.current_page`), so the current contract is covered. No mobile/Dart client exists today; the finding describes friction for a hypothetical typed client, not a defect, security issue, or data-loss risk. Hard-coded page sizes and an uncapped `?all=1` are design choices for a small single-tenant web app (the unbounded-payload aspect is already tracked as a separate finding). The one genuinely ambiguous contract element is the empty `[]` vs keyed-object polymorphism on attendance maps, which is a real but small inconsistency. Overall: real, but a consistency/roadmap concern for mobile readiness rather than a high-severity issue — medium is appropriate.

```text
Confirmed: backend/app/Http/Controllers/Api/TeacherController.php:41-45, StudentController.php:132-137, CenterController.php:21-25, CenterManagerController.php:191-193 (paginator-or-array on ?all=1); AttendanceController.php:29 pluck and :143 groupBy ([] when empty, object otherwise); StudentController.php:766,781,801 custom page params; NotificationController.php:20 limit(30) and MessageController.php:121 limit(100) with no cursor; 0 hits for per_page in backend/app; no app/Http/Resources directory. Mitigations: frontend-html/teacher/attendance.html:39 `res.data.attendances || {}` tolerates both empty shapes; backend/tests/Feature/PaginationSearchTest.php:141 and ParentChildPaginationTest.php assert the current paginator shape; all 15 frontend `?all=1` call sites (e.g. admin/students.html:344, teacher/students.html:114) explicitly opt in and consume `.data` as an array, so the shape is deterministic per request.
```

</details>

### No OpenAPI/Postman/API-Resource contract artefact; CLAUDE.md is the only route documentation and has drifted from the code

<a id="no-openapi-contract"></a>

`no-openapi-contract` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort L (1–2 weeks)

**Files:** `backend/composer.json:7-13`, `backend/app/Http/Controllers/Controller.php`, `CLAUDE.md`, `backend/routes/api.php`

**Evidence**

```text
`find . -iname '*openapi*' -o -iname '*swagger*' -o -iname '*.postman*'` (excluding vendor) → no files; composer.json requires only framework/sanctum/tinker/mpdf/phpspreadsheet — no scribe, l5-swagger or dedoc. `ls app/Http/Resources app/Http/Requests` → both absent; `grep -rn JsonResource app/` → 0. Response shapes are therefore defined by 23 places that return raw models (`'data' => $teacher`, `$student->load(...)`, `$test->fresh()->load('questions')`) and ~120 ad-hoc arrays. CLAUDE.md route table omits: /parent/messages*, /teacher/messages*, /admin/users, /manager/parents, /manager/students/{id}/teacher, /manager/teachers/{id}/performance, /manager/teachers/{id}/status, /manager/reports/{center,teacher/{id},student/{id}}, /centers/{id}/{stats,teachers,students}, /students/{id}/{details,day}, weekly-tests update (docs say destroy; routes/api.php:164 `->only(['index','store','show','update'])`); it states 20 feature-test files (actual 38 in tests/Feature), that login pulls /public/demo-accounts (no consumer in frontend-html — grep 'demo' in login.html/js/pages/login.js → 0), and the manager email scheme `{latin}.centeradmin@mutqin.ly` (code: LoginEmail.php:29 `{latin}_{code}@mutqin.ly`, and login accepts display codes at AuthController.php:36).
```

**Why it matters**

A Flutter team has no machine-readable source of truth for 107 routes, their field names, nullability, enums or error shapes; models must be reverse-engineered from PHP, and every backend change risks silently breaking a shipped mobile build. Contract drift is already demonstrable in the project's own docs.

**Recommendation**

Generate an OpenAPI 3.1 spec (knuckleswtf/scribe from the existing routes + docblocks is the fastest route; or hand-author openapi.yaml) and commit it under backend/docs/; introduce JsonResource classes for User/Student/Center/Attendance/Memorization/WeeklyTest/Message/Notification so shapes are defined once; add a spec-vs-routes CI check (route count = spec operation count). Use the spec to generate Dart models (openapi-generator) rather than hand-writing them. Update CLAUDE.md route table from the spec.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Every factual claim in the evidence checks out against the repo: no openapi/swagger/postman files outside vendor; backend/composer.json requires only framework, sanctum, tinker, mpdf, phpspreadsheet (no scribe/l5-swagger/dedoc); backend/app/Http/ contains only Controllers and Middleware (no Resources, no Requests; 0 JsonResource/FormRequest usages). The CLAUDE.md drift is real: routes/api.php:164 has weekly-tests ->only(['index','store','show','update']) (docs say destroy, no update); routes for /parent/messages*, /teacher/messages*, /admin/users, /manager/parents, /manager/teachers/{id}/performance, /centers/{id}/stats, /students/{id}/details|day exist but are undocumented; tests/Feature has 38 files not 20; frontend-html/login.html and js/pages/login.js contain no reference to demo accounts; LoginEmail::build() produces `{latin}_{code}@mutqin.ly` while CLAUDE.md states `{latin}.centeradmin@mutqin.ly`, and AuthController::login matches display_code before email. So the finding cannot be refuted on correctness.

However, the severity is inflated relative to this product. (1) There is no mobile client or Flutter project in the repo and the only consumer is the in-repo static frontend that ships in the same commit as the backend — the "shipped mobile build silently breaking" impact is hypothetical. (2) Partial mitigations exist: a uniform {success,message,data,errors} envelope, and 38 feature tests with 103 assertJson/assertJsonStructure/assertJsonPath assertions that pin a meaningful subset of response shapes, plus `php artisan route:list` as a machine-readable route inventory. (3) This is a maturity/process gap with zero runtime or security impact for a small single-team, single-repo Arabic-only deployment. It is a legitimate blocker only if a mobile app is actually planned, in which case it becomes a prerequisite rather than a present defect. Medium is the appropriate rating; the CLAUDE.md drift items are the concretely actionable part.

```text
Confirmed: backend/composer.json:7-13 (no doc-gen package); `ls backend/app/Http` → Controllers, Middleware only; `grep -rn JsonResource|FormRequest backend/app` → 0. Drift confirmed: backend/routes/api.php:164 `->only(['index','store','show','update'])` for weekly-tests (CLAUDE.md claims index/store/destroy/show); undocumented routes at api.php:47-49, 60-61, 66, 103, 112, 143-144, 147-149; backend/app/Support/LoginEmail.php:27-30 builds `{latin}_{code}@mutqin.ly` (CLAUDE.md says `{latin}.centeradmin@mutqin.ly`); AuthController.php:36-38 matches display_code first; `grep demo frontend-html/login.html js/pages/login.js` → 0; tests/Feature has 38 files. Mitigations: 103 assertJson*/assertJsonStructure assertions across tests/Feature pin many response shapes; no mobile/Flutter consumer exists anywhere in the repo (`grep -rli flutter|mobile *.md` → 0), so the mobile-break impact is prospective, not current.
```

</details>

### Center managers have no forgot-password and no change-password path; parents cannot change their password while logged in (/profile is teacher-gated)

<a id="self-service-gaps-manager-parent"></a>

`self-service-gaps-manager-parent` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:132`, `backend/app/Http/Controllers/Api/AuthController.php:184`, `backend/routes/api.php:133-137`, `backend/app/Http/Controllers/Api/TeacherProfileController.php:74-103`

**Evidence**

```text
AuthController.php:132 and :184 `User::whereIn('role', ['parent', 'teacher'])->where('phone', ...)` — the OTP reset silently excludes `center_manager` (and admin, which the comment at :131 intends; the manager exclusion is undocumented). routes/api.php:133 `Route::middleware('teacher')->group(function () {` contains :135-137 `/profile`, `/profile/phone`, `/profile/password` — the only self-service endpoints — so parent-ability and manager-ability tokens receive 403 from TeacherMiddleware. There is no /parent/profile, /manager/profile or generic /auth/password route. Managers' passwords can only be changed by the admin via PUT /admin/managers/{id} (ManagerManagementController.php:125-128); parents' only path is the phone OTP.
```

**Why it matters**

For a mobile app 'for all four roles', two roles ship without basic account hygiene: a manager who forgets a password is locked out until the system admin intervenes, and parents (the largest population) cannot rotate a password from Settings. Support load scales with center count.

**Recommendation**

Move profile/password self-service to an auth-only group: `GET /me`, `PUT /me/phone`, `POST /me/password` working on `$request->user()` for every role (reuse TeacherProfileController logic, keep recordPasswordChange('self') token revocation). Include `center_manager` in the OTP whereIn (managers carry phones) or document a deliberate exclusion. Add tests for manager/parent on these routes.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Evidence verified against the code. (1) backend/app/Http/Controllers/Api/AuthController.php:132 and :184 both filter `User::whereIn('role', ['parent', 'teacher'])`, so a center_manager phone gets the neutral "if registered" response and can never receive an OTP; the comment at :131 only documents the admin exclusion, so the manager exclusion is indeed undocumented. (2) backend/routes/api.php:133-137 puts /profile, /profile/phone and /profile/password inside `Route::middleware('teacher')`, and TeacherMiddleware.php:17 rejects anything that is not role teacher/admin with tokenCan('*') — parent tokens (ability 'parent') and manager tokens (ability 'manager') get 403. grep of routes/api.php shows no other profile/password route (only the two public forgot-password routes at :20-21). (3) ManagerManagementController.php:125-128 confirms the admin-only password path for managers. (4) No mitigation elsewhere: frontend-html/manager/ and frontend-html/parent/ contain no profile page and layout.js has a profile entry only for admin and teacher; tests/Feature/OtpResetTest.php has no manager case; no test covers parent/manager self-service. So the finding is factually correct and not mitigated. However "high" is overstated for this product: it is a feature gap, not a security or data-integrity defect. Managers are at most one per center and their admin already has a working reset path (PUT /admin/managers/{id}); parents can effectively rotate a password via the existing phone OTP flow (which also revokes tokens), so they are not without any hygiene path — they just lack an in-app "change password while logged in". For a small single-country deployment the operational impact is support friction, which fits medium, not high.

```text
backend/app/Http/Controllers/Api/AuthController.php:131-132 and :184 — `whereIn('role', ['parent','teacher'])` excludes center_manager from OTP request and verify (comment documents only admin). backend/routes/api.php:133-137 — /profile* under `Route::middleware('teacher')`; backend/app/Http/Middleware/TeacherMiddleware.php:17 — rejects role not in [teacher, admin] or token without '*' → parent/manager tokens 403. No other password/profile routes exist (routes/api.php grep: only :20-21 public forgot-password and :135-137). backend/app/Http/Controllers/Api/ManagerManagementController.php:125-128 — admin-only password set for managers. frontend-html/manager/ and frontend-html/parent/ have no profile page; frontend-html/js/layout.js:22,26 — profile menu entry exists only for admin and teacher. Mitigating context: parents retain the public OTP reset (routes/api.php:20-21) as a password-rotation path; managers can be reset by the admin.
```

</details>

### All routes live under /api with no version segment; a shipped mobile client would pin an un-versioned contract

<a id="no-api-versioning"></a>

`no-api-versioning` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/routes/api.php:16-173`, `backend/bootstrap/app.php:8-13`, `frontend-html/js/config.js:10-12`

**Evidence**

```text
bootstrap/app.php:8-13 `->withRouting(web:..., api: __DIR__.'/../routes/api.php', ..., health: '/up')` with default apiPrefix 'api'; `grep -rn 'v1\|version' routes/ config/app.php` → 0 hits. frontend-html/js/config.js:10-12 hard-codes `'http://localhost:9090/api'` / `'/backend/public/api'`. No Accept-Version header, no deprecation headers.
```

**Why it matters**

Web clients redeploy atomically with the backend; mobile clients do not — old app versions in stores keep calling the same URLs for months. Without /v1 there is no way to change a response shape (e.g. fixing the pagination or date findings above) without breaking installed apps, and no place to announce sunset.

**Recommendation**

Wrap routes/api.php in `Route::prefix('v1')->group(...)` (or set apiPrefix 'api/v1') before the mobile client is cut; keep `/api/*` as a temporary alias for the web client with a `Deprecation` header; put the fixed envelope/pagination/date shapes only on v1. Document the versioning policy (additive changes in-place, breaking changes bump the prefix).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 78% · corrected severity: low

The factual claims check out: backend/bootstrap/app.php:8-13 registers routes/api.php via withRouting with the default 'api' prefix and no apiPrefix override; routes/api.php has no Route::prefix('v1') group and grep for v1/version/prefix across routes/, config/app.php and bootstrap/app.php returns nothing; frontend-html/js/config.js:10-12 hard-codes '.../api' with no version segment; no Accept-Version or Deprecation header handling exists anywhere. So the finding is not factually wrong. However, its impact statement is entirely hypothetical for this product. There is no native/store mobile client in the repo, and none is documented as planned (grep of DEPLOYMENT.md, README.md, DEPLOY_LOG.md, CLAUDE.md for mobile/flutter/android/ios/apk finds only PWA/responsive-CSS work). The chosen mobile strategy (DEPLOY_LOG.md:75, config.js:27-45) is an installable PWA that deliberately ships no service worker, and in production it calls the API by a same-origin relative path ('/backend/public/api'). That means the only existing client always loads fresh HTML/JS from the same server as the API and cannot pin a stale contract — the exact failure mode the finding describes (old store binaries calling changed shapes for months) cannot occur today. The recommendation is sound hygiene to do before a native client is ever cut, and it is cheap, but with no un-atomically-deployed consumer in existence it is a forward-looking design note rather than a current defect; 'medium' overstates it. Corrected to low.

```text
backend/bootstrap/app.php:8-13 — withRouting(api: routes/api.php) with default 'api' prefix, no apiPrefix/v1. backend/routes/api.php:16-30 — flat routes, no version group. frontend-html/js/config.js:10-12 — API_BASE_URL is 'http://localhost:9090/api' in dev and same-origin relative '/backend/public/api' in production. frontend-html/js/config.js:27-45 — PWA manifest injection with explicit comment 'لا service worker' (no offline caching), so the web/PWA client always fetches current JS from the same host as the API. backend/DEPLOY_LOG.md:75 — mobile strategy is PWA + responsive layout; no native store app exists or is referenced anywhere in the repo (grep for flutter/android/ios/apk/react native → none outside CSS media queries).
```

</details>

### Login returns no expiry or abilities, no device name, no refresh or logout-all; 7-day hard expiry forces weekly re-login; bad credentials are 422

<a id="token-lifecycle-not-mobile-shaped"></a>

`token-lifecycle-not-mobile-shaped` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:62-83`, `backend/app/Http/Controllers/Api/AuthController.php:89-97`, `backend/app/Http/Controllers/Api/AuthController.php:225-237`, `backend/config/sanctum.php:52`, `backend/routes/api.php:16-28`

**Evidence**

```text
AuthController.php:64 `$token = $user->createToken('auth_token', $abilities)->plainTextToken;` — token name fixed, no device_name/platform input, no explicit expiresAt; response :66-73 returns `{token, user}` only (no `expires_at`, no `abilities`, no `token_type`). config/sanctum.php:52 `'expiration' => 10080` (7 days from creation, not sliding). logout :89-97 deletes only `currentAccessToken()`; there is no /auth/refresh, /auth/logout-all or token listing (routes/api.php:16-28). Invalid credentials return HTTP 422 with `errors.email` (:77-83) while an inactive account returns 403 (:42-48). userPayload (:225-237) omits `display_code`, `is_active`, and `id`-level abilities. n8n/README.md shows the automation logging in daily with a password because no long-lived service token exists. No scheduler prunes expired tokens (routes/console.php has only `inspire`; grep 'prune|Schedule::' app routes config → 0).
```

**Why it matters**

The mobile app cannot warn before expiry, cannot silently renew, and will hard-log-out every user weekly (parents especially will churn); it cannot let a user revoke a lost phone; 422-for-bad-password collides with validation handling; personal_access_tokens grows by one row per login per device forever.

**Recommendation**

Accept `device_name` (and optional `platform`, `app_version`) on login and name the token with it; return `expires_at`, `abilities` and `token_type: Bearer`; add `POST /auth/refresh` (issue new token, delete current) with a sliding window, `POST /auth/logout-all`, and `GET /auth/sessions`; return 401 for bad credentials; schedule `sanctum:prune-expired --hours=24` in routes/console.php. Consider 30-day expiry with refresh for parents.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Every factual claim checks out against the code. backend/app/Http/Controllers/Api/AuthController.php:64 creates a fixed-name 'auth_token' with role abilities and no expiresAt; the response (:66-73) carries only {token, user}; bad credentials return 422 with errors.email (:77-83) while inactive account/center return 403 (:42-57); logout (:89-97) deletes only currentAccessToken(); userPayload (:225-237) has no display_code/is_active/abilities. config/sanctum.php 'expiration' => 10080 is a hard (non-sliding) 7-day expiry, explicitly commented as the deliberate S1 security trade-off ("يُطلب تسجيل الدخول من جديد أسبوعياً"). routes/api.php:16-28 has no refresh/logout-all/sessions endpoints; routes/console.php contains only 'inspire' and grep for prune|Schedule:: across app/routes/config/bootstrap returns nothing. n8n/README.md:13-21 confirms the automation logs in nightly with a plaintext password. However the finding is over-rated for this product: (1) there is no mobile client in the repo at all — this is a roadmap gap for a hypothetical app, not a defect in the shipped web client, which handles 401 (api.js:51-57) and 422 field errors (login.js:39-41) correctly; (2) the 7-day expiry and 422-for-bad-credentials are documented, intentional design choices, and a unified 422 message that does not leak which field is wrong is a defensible convention, not a correctness bug; (3) a partial "logout everywhere" already exists: User::recordPasswordChange (User.php:81) and all status toggles (TeacherController:211, CenterController:268, ManagerManagementController:174, CenterManagerController:483) revoke all tokens, so a user who loses a phone can revoke sessions by changing their password; (4) token-table growth is bounded in practice — rows expire after 7 days and the user base is a handful of centers; missing sanctum:prune-expired is a minor housekeeping item. Net: real but low-severity, mostly "future mobile ergonomics" rather than a present contract defect.

```text
backend/app/Http/Controllers/Api/AuthController.php:64 createToken('auth_token', $abilities) — no device name / expiresAt; :66-73 response {token,user} only; :77-83 HTTP 422 on bad credentials (deliberate unified message per comment at :76); backend/config/sanctum.php:52 'expiration' => 10080 with an explicit S1 comment accepting weekly re-login; backend/routes/api.php:16-28 no refresh/logout-all/sessions routes; backend/routes/console.php only 'inspire'. Mitigations: backend/app/Models/User.php:81 recordPasswordChange() revokes all tokens (de facto logout-all via password change); frontend-html/js/api.js:51-57 handles 401 expiry cleanly; frontend-html/js/pages/login.js:39-41 renders the 422 errors under the field. No mobile client exists in the repository.
```

</details>

### Notifications are database-only with 60s web polling; no FCM/APNs device registration, no unread badge push, notification links are web HTML paths and ref_id is not exposed

<a id="no-push-or-device-token-endpoints"></a>

`no-push-or-device-token-endpoints` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort L (1–2 weeks)

**Files:** `backend/app/Notifications/InAppNotification.php:34-37`, `backend/app/Http/Controllers/Api/NotificationController.php:20-29`, `backend/app/Http/Controllers/Api/MessageController.php:273`, `backend/app/Http/Controllers/Api/MemorizationController.php:198`, `frontend-html/js/layout.js:257`

**Evidence**

```text
InAppNotification.php:36 `return ['database'];` — only channel. `grep -rni 'fcm|firebase|device_token|push' app config routes` → 0 hits; migrations contain no device/token table beyond personal_access_tokens. NotificationController.php:20-29 maps `id,type,title,body,link,is_read,created_at,created_ago` and drops `ref_id` that InAppNotification::toArray stores (:41). Links are web paths: MessageController.php:273 `'teacher/messages.html?student=' . $student->id`, MemorizationController.php:198 `'parent/child.html?id=' . $student->id`, ManagerManagementController.php:190 `'admin/managers.html'`. Web client polls: layout.js:257 `setInterval(() => load(true), 60000)`. Messages have no polling at all (grep setInterval in teacher/messages.html, parent/messages.html → 0).
```

**Why it matters**

The parent app's core value (know when my child memorized/was tested/got a message) cannot be delivered while the app is closed; foreground polling every 60s per user multiplies API load linearly with users across dozens of centers; the client must parse HTML query strings to deep-link because ref_id is withheld.

**Recommendation**

Add `POST /me/devices {token, platform, app_version}` / `DELETE /me/devices/{token}` and an FCM channel (laravel-notification-channels/fcm) alongside database; expose `ref_id` and a typed `target: {type:'student'|'request'|'message', id}` in the notification payload instead of HTML links; add `?since=<id>` / cursor to /notifications and /messages threads for cheap polling until push lands; consider a lightweight `GET /me/badges` returning unread counts for notifications and messages in one call.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The factual claims hold. backend/app/Notifications/InAppNotification.php:32-35 `via()` returns `['database']` only; `toArray()` (:40-46) stores `ref_id` but NotificationController::index (:20-31) maps only id/type/title/body/link/is_read/created_at/created_ago, so `ref_id` is indeed dropped from the API. `grep -rniE 'fcm|firebase|device_token|apns|push'` across app/, config/, routes/, migrations yields only an unrelated `$rows->push` in ReportService — no device-token table, no push channel, no `since`/cursor/badge routes. Links are web-relative HTML paths (MemorizationController.php:198 `parent/child.html?id=`, MessageController.php:188 `.../messages.html?student=`). frontend-html/js/layout.js:257 polls every 60s; no setInterval exists in the messages pages. No feature test or middleware mitigates any of this — it is an architectural absence, not a bug. Minor evidence error: the MessageController link is at line 188, not 273. However, the severity is overstated for this product: the repo contains no mobile client at all (web-only static client, single-country small deployment), so this is a roadmap gap for a hypothetical app rather than a defect affecting current users. The polling-load argument is weak — one 30-row request per minute per logged-in user is negligible at "dozens of centers" scale. The concrete, actionable parts (expose ref_id / typed target instead of HTML links, add a cheap unread-count or since-cursor endpoint) are real but small. Keeping the finding, downgrading to low.

```text
backend/app/Notifications/InAppNotification.php:32-35 (via → ['database'] only), :40-46 (toArray stores ref_id); backend/app/Http/Controllers/Api/NotificationController.php:20-31 (index omits ref_id); backend/app/Http/Controllers/Api/MessageController.php:188 (not :273) builds '<role>/messages.html?student=' link; backend/app/Http/Controllers/Api/MemorizationController.php:198 'parent/child.html?id='; frontend-html/js/layout.js:257 setInterval 60000. grep for fcm|firebase|device_token|apns|push over app/config/routes/migrations: 0 relevant hits. No mobile client exists anywhere in the repository.
```

</details>

### Errors carry only Arabic human strings — no stable machine-readable code; business-rule failures reuse 422/403/404 inconsistently

<a id="errors-arabic-strings-only"></a>

`errors-arabic-strings-only` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/StudentRequestController.php:229-234`, `backend/app/Http/Controllers/Api/CenterManagerController.php:296-303`, `backend/app/Http/Controllers/Api/CenterManagerController.php:255-260`, `backend/app/Http/Controllers/Api/CenterManagerController.php:648-655`, `backend/app/Http/Controllers/Api/AuthController.php:42-58`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:390-404`

**Evidence**

```text
No response contains an error code field (grep "'code' =>" in controllers matches only display-code previews). Already-processed request → 422 `'تمت معالجة هذا الطلب مسبقاً'` (StudentRequestController.php:229-234) rather than 409. Out-of-scope resource handling varies by method in one controller: showTeacher/updateTeacher use `->where('center_id', ...)->findOrFail($id)` → 404 (CenterManagerController.php:255-260, 370-373), teacherPerformance → 403 (:296-303), toggleTeacherStatus → 403 for both 'not found' and 'other center' (:648-655, comment: 'خارج النطاق (غير موجود أو ليس محفّظاً أو من مركز آخر) → 403 موحّد'). Login: bad credentials 422, inactive account 403, inactive center 403 — distinguishable only by Arabic text (AuthController.php:42-83). The xlsx import response (AttendanceImportController.php:390-404) puts `imported, present, errors, name_warnings` at the top level with no `data`/`message`, and its `errors` is a list of row objects, whereas everywhere else `errors` is `{field: [msg]}`. DashboardController.php:46,89 adds a top-level `role` key outside the envelope.
```

**Why it matters**

The mobile client cannot branch on error kind (retry vs re-login vs show-form-error vs navigate-away) without string-matching Arabic copy that will change; UX for 'request already handled', 'account deactivated', 'center closed' cannot be specialised; analytics cannot aggregate failures.

**Recommendation**

Add `code` (snake_case, stable, e.g. `auth.invalid_credentials`, `auth.account_inactive`, `auth.center_inactive`, `request.already_processed`, `scope.forbidden`, `validation.failed`) to every error envelope and to the exception renderers; decide and document one rule for out-of-scope (recommend 404 for 'not found in your scope' everywhere, 403 only for role/ability); use 409 for state conflicts; move import counters under `data` and rename row errors to `data.row_errors`. Publish the code list in the OpenAPI spec.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Traced every cited path; the evidence is factually accurate. (1) No error envelope anywhere carries a machine code: `grep -rn "'code' =>\|error_code" app/` returns nothing, and bootstrap/app.php's `withExceptions` block is empty (line 22-24), so Laravel's default renderers (422 `{message, errors}`, 403/404 `{message}`) are used unchanged. (2) StudentRequestController.php:232-235 returns 422 'تمت معالجة هذا الطلب مسبقاً' for an already-processed request. (3) Out-of-scope handling in CenterManagerController is genuinely inconsistent: showTeacher (:255-260) scopes via `->where('center_id', ...)->findOrFail()` → 404; teacherPerformance (:296-303) and reportTeacher (:645-651) → 403 with Arabic text; toggleTeacherStatus (:456-463) collapses not-found / not-a-teacher / other-center into one 403 (comment says '403 موحّد'). (4) AuthController: inactive account 403 (:42-49), inactive center 403 (:53-59), bad credentials 422 (:77-83) — distinguishable only by text; the two 403s even reuse the `errors.email` slot. (5) AttendanceImportController.php:385-398 returns counters and a row-array `errors` at the top level with no `data`/`message`. (6) DashboardController.php:46,89 emit a top-level `role`. Minor slip: the auditor's ':648-655' is reportTeacher, not toggleTeacherStatus (449-463) — both are 403, so the point stands.

However, the severity is over-rated for this product. The only existing client (frontend-html/js/api.js) branches solely on HTTP status (401 → re-login, :50-57) and otherwise displays `data.message` — server-authored Arabic copy is the intended contract for an Arabic-only, single-country app. The import shape deviation has exactly one consumer (ui.js:247-285) that already reads the top-level keys, so nothing is broken. The 403/404 asymmetry is deliberate per code comments and locked in by feature tests, and the security gates (role+ability middleware) are unaffected. This is a forward-looking API-hygiene/roadmap item for a hypothetical mobile client, not a present defect or contract break; nothing in the repo is a mobile client today. Keep the finding but rate it low.

```text
backend/bootstrap/app.php:22-24 — `withExceptions` is empty (default Laravel renderers, no code field). backend/app/Http/Controllers/Api/CenterManagerController.php:449-463 — toggleTeacherStatus unified 403 (auditor cited 648-655, which is reportTeacher, also 403). CenterManagerController.php:255-260 showTeacher → 404 via scoped findOrFail; :296-303 teacherPerformance → 403; :645-651 reportTeacher → 403. AuthController.php:42-49 (inactive account 403), :53-59 (inactive center 403), :77-83 (bad credentials 422). StudentRequestController.php:232-235 (already processed → 422). AttendanceImportController.php:385-398 (top-level counters, no data/message). DashboardController.php:46,89 (top-level role). Mitigation context: frontend-html/js/api.js:50-57 branches on 401 only and shows `message`; frontend-html/js/ui.js:247-285 is the sole consumer of the import shape and reads the top-level keys correctly.
```

</details>

### Several endpoints return the whole table (or all rows for admin) with no cap, and one report is N+1 per student

<a id="unbounded-list-payloads"></a>

`unbounded-list-payloads` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/MemorizationController.php:81-94`, `backend/app/Http/Controllers/Api/DashboardController.php:40-42`, `backend/app/Http/Controllers/Api/ReportController.php:91-111`, `backend/app/Http/Controllers/Api/StudentController.php:132-133`, `backend/app/Http/Controllers/Api/AttendanceController.php:17-23`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:342-375`

**Evidence**

```text
MemorizationController.php:81-94 studentsProgress: for admin `Student::query()->select('id','name','age')` (no scope) `->get()` then `Memorization::whereIn('student_id', ...)->get(['student_id','surah_name'])` — every memorization row in the system loaded into memory and returned as one JSON array. DashboardController.php:40-42 `User::where('role','teacher')->withCount('students')->get()` returns every teacher object on every admin dashboard load. ReportController.php:97-111 weekly: `$students->map(function ($student) { Attendance::where('student_id', $student->id)...->get(); ... Memorization::where(...)->count(); })` — two queries per student, and for admin without center_id all active students system-wide. StudentController.php:132-133 `?all=1` returns all students with eager-loaded center + full teacher User objects (web client uses `/students?all=1` 4×, `/teachers?all=1` 2×, `/manager/students?...&all=1`). AttendanceController.php:17-23 returns all active students (all centers for admin without center_id). AttendanceImportController.php:361-375 runs `Attendance::firstOrCreate` per student per date inside the transaction.
```

**Why it matters**

At 'dozens of centers' (thousands of students, tens of thousands of memorization rows) these responses reach megabytes over Libyan mobile links, spike PHP memory on shared hosting, and the weekly report becomes thousands of queries per request; dropdown-driven `all=1` calls make list screens unusable on 3G.

**Recommendation**

Cap every list: paginate studentsProgress (or scope admin to a required center_id), paginate/limit dashboard teacher list to top-N with a link to the paginated endpoint, rewrite weekly() with two grouped queries (pattern already used in teacherPerformance), replace `all=1` with typeahead search endpoints (`/students?q=` already exists) or `per_page<=100`, batch-insert computed absences with a single `insertOrIgnore`. Add slow-query logging and a payload-size budget (e.g. 200 KB) to the contract tests.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Every quoted code path exists exactly as cited and behaves as described: MemorizationController.php:81-94 loads all students (unscoped for admin) and every memorization row for them; DashboardController.php:40-42 `User::where('role','teacher')->withCount('students')->get()` with no limit; ReportController.php:91-111 runs two queries per student inside `map()` (N+1) with admin-without-center_id = all active students system-wide; StudentController.php:132-133 and TeacherController.php:41 honour `?all=1` with a plain `->get()`; AttendanceController.php:17-23 `->get()` all active students for admin without center_id; AttendanceImportController.php:355-375 does `firstOrCreate` per student per date. No global middleware, model-level cap, or test enforces a payload budget (bootstrap/app.php registers only the four role aliases). So the finding is factually correct and not mitigated in code.

However the impact is overstated for this product. Tracing who can actually reach the truly system-wide paths: (1) the teacher-gated endpoints (`/students?all=1`, `/attendance`, `/memorizations/students-progress`, `/reports/weekly`) are scoped to `teacher_id = caller` for teachers, i.e. one teacher's roster (tens of students), which is the only role the web client uses them with — all teacher/ pages call `Auth.requireAuth(['teacher'])` (commit 37313bf explicitly blocked admin from teacher pages), and no frontend page calls `/reports/weekly` or `/memorizations/students-progress` at all. The unbounded admin variants are reachable only by an authenticated admin hitting the API directly (a trusted single account, not a public/mobile path). (2) Manager `all=1` lists (`/manager/students`, `/manager/teachers`) are center-scoped (CenterManagerController.php:192), so bounded by one center's size (hundreds at most). (3) `/teachers?all=1` and `/centers?all=1` for admin return teachers/centers — at "dozens of centers" that is at most a few hundred small rows, not megabytes. (4) The import `firstOrCreate` loop is bounded by the uploader's scope (own center / own students) and runs inside one transaction; at center scale it is hundreds of cheap indexed queries, acceptable for an occasional upload. The N+1 in `weekly()` is real but in practice runs over a single teacher's students.

Net: real code-quality/scalability debt (no pagination discipline, N+1 in weekly report, admin-only unbounded lists) but the "megabytes over mobile / thousands of queries per request" scenario requires an admin caller on endpoints the shipped client never uses as admin, and the mobile-facing (teacher/manager/parent) paths are naturally scoped to small sets. Downgrade to low.

```text
Confirmed as cited: backend/app/Http/Controllers/Api/MemorizationController.php:81-94 (admin unscoped `Student::query()->get()` + `Memorization::whereIn(...)->get()`); DashboardController.php:40-42 (`->get()` all teachers); ReportController.php:91-111 (`$students->map` with `Attendance::where(...)->get()` and `Memorization::where(...)->count()` per student); StudentController.php:132-133 and TeacherController.php:41 (`all=1` → `->get()`); AttendanceController.php:17-23; AttendanceImportController.php:355-375 (`firstOrCreate` per student per date). Mitigating context: ReportController.php:86-87 and AttendanceController.php:18-19 and MemorizationController.php:82-83 scope non-admins to `teacher_id = $user->id`; CenterManagerController.php:192 manager `all=1` is center-scoped; frontend-html/teacher/{memorization,attendance,reports}.html:20/29/20 `Auth.requireAuth(['teacher'])` — admin never reaches the unbounded admin branches from the shipped client; no frontend page calls `/reports/weekly` or `/memorizations/students-progress` (grep of frontend-html returns none). No test or middleware caps payload size (bootstrap/app.php:14-19 only role aliases).
```

</details>

### Raw Eloquent models are returned as `data`, leaking schema columns and PII and making the same entity appear with different shapes per endpoint

<a id="raw-eloquent-serialization"></a>

`raw-eloquent-serialization` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort L (1–2 weeks)

**Files:** `backend/app/Http/Controllers/Api/TeacherController.php:96-100`, `backend/app/Http/Controllers/Api/TeacherController.php:127-143`, `backend/app/Http/Controllers/Api/StudentController.php:65-66`, `backend/app/Http/Controllers/Api/StudentController.php:270-274`, `backend/app/Models/User.php:43-46`, `backend/app/Models/Student.php:30-35`

**Evidence**

```text
23 sites return a model or paginator of models directly (grep `'data' => $var` / `->load(` / `->fresh()`). User.php:43-46 hides only `password` and `remember_token`, so `'data' => $teacher` (TeacherController.php:99, 183; CenterManagerController.php:158, 386) exposes `email_verified_at, password_changed_count, password_last_changed_at, status_changed_by, status_changed_at, id_number, nationality_*, created_at, updated_at` while `show()` (:127-143) returns a hand-picked 11-key array — same entity, two shapes. Student.php has no $hidden, so `Student::with(['center','teacher'])` (StudentController.php:66) returns for each row a nested full teacher User (with phone/email/id_number) and `guardian_phone`, `national_id`, `parent_id` even to teacher-role callers, while teacherDetails (:427-433) deliberately omits guardian data ('بلا بيانات ولي الأمر'). Store returns `$student->load(['center','teacher','parent'])` (:273) including the parent User's email/phone/id_number.
```

**Why it matters**

Dart models must be a union of every column that ever appeared; adding a DB column silently changes the mobile contract; PII (guardian phones, national ids, teachers' phones) travels to roles/screens that do not need it, increasing exposure on lost devices and in logs.

**Recommendation**

Introduce API Resources (UserResource with role-aware fields, StudentResource with `guardian` sub-object only for admin/manager, TeacherSummaryResource for nested teacher = {id,name,display_code}) and use them at every return site; set `$hidden` on Student/User for audit columns; snapshot-test each resource's key set so schema changes are explicit.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Core facts verified: there are no API Resources anywhere in backend/app (grep for JsonResource/Resource returns nothing); User::$hidden is only ['password','remember_token'] (User.php:43-46); Student has no $hidden at all (Student.php:9-35); StudentController::index returns `'data' => $students` where $students is `Student::with(['center','teacher'])` paginated/get (StudentController.php:71, 132-141) so every row carries the nested full teacher User (email, phone, id_number, nationality_*, password_changed_count, status_changed_* ...) plus national_id/guardian_phone/parent_id; store returns `$student->load(['center','teacher','parent'])` (:273) including the parent User's id_number; TeacherController store returns `'data' => $teacher` (:99) while show() (:127-143) hand-picks 11 keys — same entity, two shapes. AdminUsersListTest.php:44-50 even asserts a hand-picked key set for /admin/users, confirming shape inconsistency is real and partly recognised.

However the severity is overstated. (1) No secret ever leaks: password hash and remember_token are hidden; OTP data is in a separate table; the finding itself concedes this. (2) The 'PII to teacher-role callers who do not need it' claim is factually wrong for guardian data: the teacher UI deliberately displays guardian_name and guardian_phone (frontend-html/teacher/students.html:86-87, 120-122) because teachers contact guardians; teacherDetails omitting guardian data (:385) is a page-scope choice, not a policy. Index is already role-scoped (manager → own center, teacher → own students), so every recipient of the exposed columns is an admin/manager/teacher with a legitimate relationship to the record. (3) Audit/bookkeeping columns (email_verified_at, password_changed_count, status_changed_by/at, timestamps) are low-sensitivity. (4) 'Adding a DB column silently changes the mobile contract' is true but is a maintainability/hygiene concern in a small single-team project with a vanilla-JS client that ignores unknown keys; no mobile client exists yet.

Net: the finding is factually correct about mechanics and shape inconsistency, but the PII/exposure impact is exaggerated. It is a code-hygiene / API-contract consistency issue, not a medium-severity data-exposure risk. Corrected severity: low.

```text
backend/app/Models/User.php:43-46 ($hidden = password, remember_token only); backend/app/Models/Student.php:9-35 (no $hidden); backend/app/Http/Controllers/Api/StudentController.php:71,132-141 (raw `Student::with(['center','teacher'])` paginator returned as data — nested teacher User with email/phone/id_number), :270-274 (`$student->load(['center','teacher','parent'])` — parent User incl. id_number); backend/app/Http/Controllers/Api/TeacherController.php:96-100 vs :127-143 (raw model vs hand-picked 11-key array); backend/tests/Feature/AdminUsersListTest.php:44-50 (only endpoint with a key-set assertion). Counter-evidence to the teacher-PII claim: frontend-html/teacher/students.html:86-87,120-122 render guardian_name/guardian_phone for teachers by design; no API Resource classes exist (grep JsonResource in backend/app → none); password hash never serialized (User.php:43-46).
```

</details>

### No lang/ar directory: any rule without a hand-written message falls back to English; no Accept-Language handling

<a id="validation-messages-mixed-language"></a>

`validation-messages-mixed-language` · 🟡 medium · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/.env.production.example:29-30`, `backend/app/Http/Controllers/Api/AttendanceController.php:52-56`, `backend/app/Http/Controllers/Api/CenterController.php:228-231`, `backend/app/Http/Controllers/Api/TeacherController.php:150-156`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:37-50`

**Evidence**

```text
`ls backend/lang backend/resources/lang` → neither exists (resources has only css/js/views). .env.production.example:29-30 `APP_LOCALE=ar` / `APP_FALLBACK_LOCALE=ar` — with no ar files the translator falls through to the framework's built-in English lines. Rules with no custom message: AttendanceController.php:52-56 `'date' => 'required|date', 'attendance' => 'required|array', 'confirm' => 'nullable|boolean'` (no messages array at all) → 'The date field must be a valid date.'; CenterController.php:228-231 update (none); TeacherController.php:150-156 update (`email` unique/email, `center_id` exists, `type` in → English); WeeklyTestController.php:42 `'questions.*.result' => 'required|in:ناجح,راسب'` has a `required` message but no `in` message → 'The selected questions.0.result is invalid.'; StudentController.php:200-215 `nationality_type.in`, `teacher_id`/`parent_id` only partly covered; `is_active.boolean` uncovered in all toggleStatus methods. No SetLocale middleware; `grep -rn 'Accept-Language\|setLocale' app bootstrap` → 0.
```

**Why it matters**

Arabic-only end users see English validation text on the exact edge cases mobile clients hit (malformed dates from device pickers, wrong enum strings); the app cannot request a locale; error copy is unpredictable for QA.

**Recommendation**

`php artisan lang:publish` then add lang/ar/validation.php (community Arabic translations exist) with `attributes` mapped to Arabic field labels; keep per-rule overrides only for domain wording; add a SetLocale middleware honouring Accept-Language (ar default); add a test that posts an invalid `date` and asserts the message is Arabic.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The core claim holds. Verified: neither backend/lang nor backend/resources/lang exists; config/app.php:83-85 reads APP_LOCALE/APP_FALLBACK_LOCALE with default 'en'; .env.production.example:29-30 sets both to 'ar'; .env.example:7-8 sets both to 'en'. No SetLocale middleware or Accept-Language handling (bootstrap/app.php registers only the four role aliases; withExceptions is empty, so ValidationException is not re-rendered); no laravel-lang package in composer.json. All cited rule sets exist exactly as quoted: AttendanceController.php:52-56 (no messages array), CenterController.php:228-231 (none), TeacherController.php:150-156 (none), WeeklyTestController.php:37-50 (in: rule on questions.*.result has no message), toggleStatus 'is_active'=>'required|boolean' with no message (TeacherController.php:196, StudentController.php:574). Frontend ui.js:84/186 renders errors[field][0] verbatim, so whatever the backend emits is shown to users. Existing tests only assert the hand-written Arabic messages, none cover framework-generated ones.

One correction to the mechanism: with APP_LOCALE=ar and APP_FALLBACK_LOCALE=ar (the production example), Laravel's Translator only searches [locale, fallback]; the framework ships only Illuminate/Translation/lang/en, so in that configuration the Validator does not fall back to English — it returns the raw key (e.g. 'validation.date', 'validation.in'), which is arguably worse than English. English only appears when locale is 'en' (dev .env.example). I could not verify this empirically because vendor/ is not installed on this machine and PHP is not at the documented path, so it rests on reading of Laravel 11 Translator::get/Validator::getMessage.

Not refuted; not fully mitigated. Severity: it is an i18n/UX defect with no security or data-integrity impact, and the web client's HTML form controls (required, date inputs, selects) prevent most of these paths for the existing frontend, but any mobile/API client hits them directly and the project is Arabic-only. Medium is defensible given production would emit raw 'validation.*' keys; I keep medium.

```text
backend/config/app.php:83-85 ('locale' => env('APP_LOCALE','en'), 'fallback_locale' => env('APP_FALLBACK_LOCALE','en')); backend/.env.example:7-8 APP_LOCALE=en/APP_FALLBACK_LOCALE=en; backend/.env.production.example:29-30 APP_LOCALE=ar/APP_FALLBACK_LOCALE=ar with no lang/ar → Translator has no 'en' in its locale array, so uncovered rules yield raw keys such as 'validation.date' / 'validation.in' / 'validation.boolean' rather than English sentences. backend/bootstrap/app.php:14-23 (no locale middleware, empty withExceptions). backend/app/Http/Controllers/Api/TeacherController.php:196 and StudentController.php:574 'is_active' => 'required|boolean' with no messages array. frontend-html/js/ui.js:84,186 display errors[field][0] verbatim. No test in backend/tests/Feature asserts a framework-generated (non-custom) validation message.
```

</details>

### No JSON health check with DB probe, no app-version/force-update or server-time endpoint

<a id="no-health-version-endpoints"></a>

`no-health-version-endpoints` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/bootstrap/app.php:12`, `backend/routes/web.php:5-10`, `backend/routes/api.php:22-23`

**Evidence**

```text
bootstrap/app.php:12 `health: '/up'` — Laravel's built-in liveness page (HTML view, 200 regardless of DB state, outside /api and not JSON). routes/web.php:5-10 `/` returns `{'message':'Welcome to MUTQEN API Backend','status':'online'}` with no version, DB or migration status. routes/api.php public routes are only login, two OTP routes, /public/stats and /public/demo-accounts; `grep -rn 'version\|min_version\|force_update' routes app config/app.php` → 0. No endpoint returns server time/timezone although all 'today' logic is Africa/Tripoli.
```

**Why it matters**

Ops cannot alert on a DB outage behind a 200 `/up`; the mobile app cannot enforce a minimum supported version after a breaking backend change (critical once dates/pagination are fixed), cannot show a maintenance screen, and must trust device clocks for 'today' defaults.

**Recommendation**

Add `GET /api/v1/health` → `{success, data:{status, db:'ok', time:'2026-09-14T20:00:00+02:00', timezone:'Africa/Tripoli', version:'<git sha>'}}` (503 on DB failure) and `GET /api/v1/app-config` → `{min_app_version:{android,ios}, latest, force_update, maintenance:{active,message}, features:{...}}` read from config/.env. Wire /health into uptime monitoring.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted evidence is accurate: backend/bootstrap/app.php:12 registers Laravel's built-in `health: '/up'` (returns the HTML `health-up` view with 200; Laravel 11 only fires DiagnosingHealth and performs no DB probe); backend/routes/web.php:5-10 returns a static `{message, status:'online'}` JSON with no version/DB info; the only public API routes (api.php:16-23) are login, two OTP routes, /public/stats and /public/demo-accounts. A grep for version/min_version/force_update/health across routes, app and config/app.php finds only Laravel's stock maintenance-mode config. No endpoint returns server time/timezone. So the finding is factually correct and not refuted.

However it is overrated for this product. (1) DB-outage detection is partially mitigated already: `GET /api/public/stats` (DashboardController::publicStats, lines 124-134) runs three DB counts and will return 500 when MySQL is down, so an uptime monitor pointed at it is a de-facto DB probe — no code change needed. (2) There is no native mobile app: the client is a static HTML/JS PWA (manifest.webmanifest, no service worker found), so it always fetches fresh JS from the server and a min_app_version/force_update endpoint has nothing to enforce; the "critical once pagination is fixed" escalation does not apply. (3) Server-time: the product is single-country (Libya, one timezone), and the frontend deliberately uses local `UI.todayStr()` (ui.js:131) while the backend uses Africa/Tripoli `today()`; device-clock drift is a marginal concern for a small deployment. The remaining real gap — no purpose-built JSON health endpoint reporting DB status/version for ops — is an operational nicety, not a contract defect: severity low.

```text
backend/bootstrap/app.php:12 `health: '/up'` (Laravel default, HTML, no DB check) — confirmed. backend/routes/web.php:5-10 static welcome JSON — confirmed. backend/routes/api.php:16-23 public routes only login/OTP/public stats/demo-accounts — confirmed. Mitigation: backend/app/Http/Controllers/Api/DashboardController.php:124-134 `publicStats()` executes `Center::where(...)->count()`, `User::count()`, `Student::where(...)->count()` — a public endpoint that fails (500) when the DB is unreachable, usable as an uptime/DB probe today. Not applicable: frontend-html/ is a static PWA (manifest.webmanifest only, no service worker, no native app), so min_app_version/force_update has no consumer; frontend-html/js/ui.js:131 `todayStr()` uses the local clock by design in a single-timezone (Africa/Tripoli) deployment.
```

</details>

### Create endpoints other than attendance have no idempotency key or natural uniqueness, so mobile retries duplicate records

<a id="write-idempotency-missing"></a>

`write-idempotency-missing` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/MemorizationController.php:175-187`, `backend/app/Http/Controllers/Api/MessageController.php:257-262`, `backend/app/Http/Controllers/Api/StudentRequestController.php:88-96`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:61-65`, `backend/app/Http/Controllers/Api/AttendanceController.php:73-116`

**Evidence**

```text
MemorizationController.php:175-187 `Memorization::create([...])` with no unique constraint and no Idempotency-Key check — a retried POST on a flaky link inserts a second identical surah record, which then double-counts in SurahReference::progress-based reports. MessageController.php:257-262 `Message::create` likewise (duplicate chat bubbles + duplicate notifications via sendSafe at :266). StudentRequestController::managerStore creates a transfer request with no pending-duplicate check visible in :88-96 (validation only checks existence). WeeklyTestController.php:61-65 documents duplicates as intentional ('تكرار الاختبارات الأسبوعية «حر» عمداً'). Only AttendanceController.php:73-116 is retry-safe (unique (student_id,date) + updateOrCreate + 409 confirm flow). No `Idempotency-Key` header handling anywhere (grep → 0).
```

**Why it matters**

Mobile networks in Libya drop and retry frequently; Dio/http retry interceptors and users tapping twice will create duplicate memorization rows and messages, corrupting progress metrics and parent-facing history with no way to undo messages (no delete route).

**Recommendation**

Support an `Idempotency-Key` header on POST /memorizations, /messages, /manager/student-requests, /weekly-tests (cache key→response for 24h per user); add a unique index on memorizations (student_id, date, surah_name) returning 409 on repeat; reject a second pending transfer request for the same student with 409. Document in the spec which writes are idempotent.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Partially correct but materially overstated; several sub-claims are factually wrong. (1) StudentRequestController::managerStore DOES have a pending-duplicate guard: lines 152-161 query `StudentRequest::where('type','transfer')->where('status','pending')->where('student_id',...)->exists()` and return 422 — the auditor stopped reading at :96. (2) The claim that a duplicate memorization row "double-counts in SurahReference::progress-based reports" is wrong: `SurahReference::progress()` (app/Support/SurahReference.php:166-173) collapses surah names into a set (`$set[$n] = true`), so duplicates have zero effect on juz completion/progress. Only the raw monthly count in ReportService.php:385 would be inflated. (3) Memorizations HAVE a destroy route (apiResource index/store/destroy), so a duplicate is trivially undoable — only messages lack delete. (4) MessageController line references are wrong (Message::create is at :172, sendSafe at :181, not :257-266). (5) Weekly-test duplication is an explicitly documented product decision (WeeklyTestController.php:61-65), not a defect. (6) The existing client mitigates double-tap: UI.formModal disables the submit button and shows a loading state while the request is in flight (frontend-html/js/ui.js:195-205), and api.js does no automatic retry. What remains true: no Idempotency-Key handling anywhere (grep confirmed), no unique index on memorizations or messages, so a future mobile client with a retry interceptor could insert duplicate memorization rows or chat messages. That is a speculative hardening item for a not-yet-built mobile client with limited real impact (progress metrics unaffected, memorization duplicates deletable), so severity should be low.

```text
backend/app/Http/Controllers/Api/StudentRequestController.php:152-161 — pending-transfer duplicate check exists (returns 422 'يوجد طلب نقل معلّق لهذا الطالب قيد المراجعة'), contradicting the finding. backend/app/Support/SurahReference.php:166-173 — progress() dedups surah names into a set; duplicate memorization rows do not alter progress. backend/app/Http/Controllers/Api/MessageController.php:172 (Message::create) and :181 (sendSafe) — correct line numbers; no delete route for messages (routes/api.php:47-49,147-149). backend/app/Http/Controllers/Api/MemorizationController.php:175-187 — Memorization::create without unique constraint (true), but destroy route exists. backend/app/Services/ReportService.php:385 — only the raw monthly memorization count would be inflated by duplicates. frontend-html/js/ui.js:195-205 — submit button disabled + is-loading during in-flight request (double-tap guard in the existing client). No Idempotency-Key handling in app/, routes/, bootstrap/ (grep → 0, confirmed).
```

</details>

### PDF reports are bearer-only inline responses with no signed/temporary URL alternative and no JSON fallback on failure

<a id="pdf-requires-bearer-no-signed-url"></a>

`pdf-requires-bearer-no-signed-url` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/ReportPdfController.php:159-195`, `backend/app/Http/Controllers/Api/ReportPdfController.php:203-206`, `backend/routes/api.php:80-82`, `backend/routes/api.php:125-128`, `backend/routes/api.php:170-171`, `frontend-html/js/ui.js:350-375`

**Evidence**

```text
ReportPdfController.php:187-194 `return response($mpdf->Output(...STRING_RETURN), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="..."'])` on 9 routes all inside auth:sanctum groups; no `URL::temporarySignedRoute`/`signed` middleware anywhere (grep → 0). :206 `abort(403, 'غير مصرح لك بتقرير هذا الطالب')` renders through the default handler (no envelope; HTML if Accept is not JSON). PDFs are generated synchronously with mPDF per request (no caching, no queue). Web client works around it by fetching a blob with the bearer header (ui.js:350-375).
```

**Why it matters**

Feasible for Flutter (Dio + Authorization header → bytes → open_filex), but the app cannot hand the URL to the OS viewer, share sheet or WhatsApp (the dominant channel for Libyan parents/managers) without first downloading; a failed auth on this path yields the redirect described in the envelope finding; synchronous mPDF on shared hosting is a latency and memory risk under concurrent mobile use.

**Recommendation**

Add `POST /reports/.../pdf-link` returning a 10-minute `URL::temporarySignedRoute` that streams the PDF without a bearer (scope encoded in the signature), keep the bearer route; return `Content-Disposition: attachment` when `?download=1`; consider caching rendered PDFs per (report, period) for an hour; ensure abort(403) is enveloped by the exception renderer.

## Measured facts

| Metric | Value |
|---|---|
| API routes (routes/api.php, PUT+PATCH counted once) | 107 = 5 public + 9 auth-any-role + 5 parent + 32 manager + 28 admin + 28 teacher (+ web '/' and Laravel '/up') |
| Controllers | 19 files, 4,926 lines in backend/app/Http/Controllers/Api |
| Explicit JSON responses carrying 'success' | 147 of 149 response()->json() sites (98.7%) |
| Framework-rendered error paths outside the envelope | 36 $request->validate() + 5 ValidationException::withMessages (422); 38 findOrFail/firstOrFail/abort (404/403); plus all 401/405/429/500 — withExceptions() is empty |
| Paginated endpoints / per_page support / ?all=1 escape hatches | 11 endpoints (+3 sub-paginators) with fixed sizes 5/10/15/20; 0 endpoints accept per_page; 4 controllers (5 routes) switch to a bare array on all=1 |
| Date-cast columns serialized as UTC-shifted datetimes | 5 (students.birth_date, students.enrollment_date, attendances.date, memorizations.date, weekly_tests.exam_date); app timezone Africa/Tripoli; 0 serializeDate overrides |
| API Resources / FormRequests / OpenAPI files / lang files | 0 / 0 / 0 / 0 |
| Frontend API call sites | 76 literal + 8 dynamic across 33 HTML pages; 0 calls to non-existent routes; 11 backend routes with no web consumer (/public/demo-accounts, /athman/hizb/{n}, /athman/{id}, /manager/reports/management, /reports/admin/missing-national-id, /attendance/report, /memorizations/students-progress, GET /weekly-tests/{id}, /reports/weekly, GET /reports/student/{id}, /auth/user) |
| Tests | 40 test files (38 Feature incl. ExampleTest, 2 Unit), 173 test methods; 131 getJson/126 postJson/39 putJson/5 deleteJson vs 9 get/1 post/1 delete; 9 assertions of 401; 6 assertions on 'success'; 1 assertJsonStructure; 0 tests without JSON Accept header |
| Token lifetime / refresh / device / push endpoints | 10080 min (7 days, non-sliding) / 0 / 0 / 0; 0 scheduled prune |
| Raw Eloquent models returned as data | 23 sites; User hides 2 columns, Student hides 0 |
| Doc drift items found (CLAUDE.md vs code) | 6 (test count 20 vs 38; demo-accounts consumer; email scheme; login-by-code; ~15 undocumented routes; 'envelope on every response' claim) |
| Migrations | 35 |

## Auditor notes

Additional material observations not promoted to findings (all verified in code): (a) Login throttle `throttle:10,1` (routes/api.php:16-17) is keyed by IP; Libyan carriers use CGNAT so one shared public IP can lock out many mobile users — key by email+IP (S, next). Laravel's default api group also applies ~60 req/min per user unless overridden (nothing in bootstrap/app.php does), so parallel dashboard fetches can 429. (b) 403 vs 404 for out-of-scope entities is inconsistent inside CenterManagerController (showTeacher/updateTeacher 404; teacherPerformance/toggleTeacherStatus/correctAttendance 403) — pick one rule. (c) Domain enums stored/validated as Arabic literals (`in:محفظ أساسي,محفظ معاون` TeacherController.php:66; `in:ناجح,راسب` WeeklyTestController.php:42) while others are English (`excellent|good|average|weak`, `present|absent|late`); surah identity is the exact Arabic name (Rule::in on SurahReference::SURAHS keys) — mobile must ship the exact strings. (d) GET /dashboard for manager/parent falls into the teacher branch (DashboardController.php:53-96) and returns `role:'teacher'` with empty stats; `role` sits outside the envelope at :46/:89. (e) Presentation sentinels leak into JSON: `'--'`/`'—'` for missing center/teacher (StudentController.php:728,826; ReportController.php:66-67; StudentRequestController.php:427). (f) ModelNotFound messages leak class names (`No query results for model [App\\Models\\Student] 5`) even in production because ModelNotFound is converted to an HttpException. (g) personal_access_tokens grows unbounded (no sanctum:prune-expired schedule; n8n logs in daily with a password — no service/API-key auth for integrations). (h) No ETag/Cache-Control on static reference data (/memorizations/surahs, /athman/*) that mobile could cache offline. (i) AttendanceController@report echoes `month`/`year` untyped (string if sent by client, int if defaulted) unlike ReportService which casts. (j) The xlsx import response also overloads `errors` (list of row objects vs field map) — covered inside errors-arabic-strings-only. (k) /public/demo-accounts returns real user names/emails whenever APP_DEBUG is true (DashboardController.php:105) — flagged for the security dimension. (l) frontend weekly-tests.html:33 date slice bug is a direct consequence of finding 2 and should be fixed together. Doc drift confirmed: CLAUDE.md says 20 feature-test files (38), says login.html pulls /public/demo-accounts (no consumer), says manager emails are `{latin}.centeradmin@mutqin.ly` (LoginEmail.php builds `{latin}_{code}@mutqin.ly` and login accepts display codes T1/CA1/P1), says weekly-tests have destroy and no update (routes/api.php:164 is the reverse), omits MessageController/messages routes, AdminUserController, /manager/parents, /manager/students/{id}/teacher, /manager/teachers/{id}/performance|status, /manager/reports/{center,teacher,student}, /centers/{id}/{stats,teachers,students}, /students/{id}/{details,day}, and claims the envelope is used on every response (false for framework-rendered errors). Read-only audit: no files were modified and no artisan commands were run (vendor/ is not present in this checkout, so framework behaviour for the default exception handler is stated from Laravel 11.51 semantics, hedged where the redirect target depends on the patch level).
