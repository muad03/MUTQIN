# Backend Architecture & Code Quality

[← Enterprise Audit](../enterprise-audit.md)

**Score 65 / 100** — Needs real work · maturity **L2** · weight 10% · auditor scored 61, judge calibrated to 65

Functional and unusually well-intentioned for its size: the role+token-ability middleware is small and correct, 38 feature-test files (178 tests) exercise real login/ownership flows, multi-write paths are wrapped in transactions, and several business rules were deliberately extracted to single-source Support classes. But measured against an enterprise/level-5 bar the architecture is a thin-framework monolith: 4,934 of 7,072 app LOC (69.8%) live in controllers, there are zero FormRequests, zero API Resources, zero Policies, zero Enums, zero Jobs/Events, an empty exception handler, no lang/ layer, no pint/phpstan config, no CI, an EOL Laravel 11 line, and the response contract is a mix of raw Eloquent dumps and hand-built arrays with three documented envelope violations. Cross-cutting concerns (ownership 403, center scoping, Arabic-digit mapping, status filters) are copy-pasted 6-13 times each. This is repeatable-by-habit (CMMI 2), not defined-and-enforced (3). Score lands in the upper half of 'functional but needs real work'.

> **Calibration:** Its own rationale places it in the 'upper half' of the 60-74 band (67+) yet the number sits at the bottom; with all five highs downgraded to medium/low and the strengths (178 real-login tests, transactions everywhere, atomic codes, single-source Support classes) intact, 65 reconciles the text with the score.

## What is already strong

- Dual role+ability authorization is the load-bearing design and is implemented cleanly: four ~25-line middleware each check role AND tokenCan (AdminMiddleware.php:17 `!$user->isAdmin() || !$user->tokenCan('*')`; CenterManagerMiddleware.php:45 adds `|| !$user->center_id`), abilities are issued once at AuthController.php:62-64, and the matrix is pinned by tests/Feature/RoleMatrixTest.php + OwnershipTest.php.
- Real regression suite: 38 feature-test files + 2 unit (178 test methods, 4,641 LOC) using RefreshDatabase against MySQL `mutqin_test`, obtaining tokens through the real POST /api/auth/login (tests/Concerns/CreatesCoreData.php:9-11) so Sanctum abilities are tested as in production. CLAUDE.md still says 20 files (doc drift).
- Atomic, never-reused display codes: DisplayCode.php:91-100 uses `UPDATE code_sequences SET value = LAST_INSERT_ID(value + 1)` + `SELECT LAST_INSERT_ID()` (connection-local, race-safe) invoked from `creating` hooks (User.php:36-40, Student.php:173-177, Center.php:259-263) so no create path can bypass it; `preview()` is read-only.
- Business rules extracted to single-source Support classes instead of scattered: PrimaryTeacherRule.php (one primary per center), ParentResolver.php (id_number -> normalized phone -> email, with QueryException race recovery at :252-261 and explicit 422 on missing password :[redacted]), PhoneNumber.php, ArabicText.php (with `sqlNormalize` for in-MySQL matching), Percentage.php, SurahReference.php (114-surah juz source of truth, unit-tested in tests/Unit/SurahReferenceJuzGapTest.php).
- Transactions on every multi-write path (15 `DB::transaction` uses): user create + code reservation + generated email (TeacherController.php:82-94, CenterManagerController.php:135-158, ManagerManagementController.php:82-93), test + questions (WeeklyTestController.php:71-91, :145-162), whole xlsx import (AttendanceImportController.php:97-380), status toggles with immediate token revocation (TeacherController.php:202-213, CenterController.php:258-271).
- Server-side scoping is never trusted from the client: manager center_id is overwritten (`$request->merge(['center_id' => $user->center_id])` StudentController.php:179-181), role/center forced on manager-created teachers (CenterManagerController.php:152-153), display_code and email never accepted from the body (LoginEmail.php:137-151).
- Side-effect isolation: InAppNotification::sendSafe (InAppNotification.php:181-191) wraps every notification in try/catch so a notification failure can never roll back or 500 the primary write.
- Newer code demonstrates the right query discipline: single-query SQL aggregates with conditional SUMs (CenterController.php:98-121 stats, ReportService.php:411-464 aggregateAllTime, MessageController.php:82-88 threads, StudentController.php:701-712 parentChildren) — the team knows how to avoid N+1; it just has not been applied retroactively.
- Consistent Arabic 422 messages and `{success,message,data,errors}` envelope on the happy/validation paths (153 `response()->json` sites), rich intent-recording docblocks in Arabic that explain approved product decisions (e.g. StudentController.php:564-570 on deactivate-instead-of-delete).
- Schema integrity: 35 migrations with 37 FK constraints and 24 index/unique declarations including the attendances (student_id,date) unique key that backs the upsert semantics; `php -l` passes on all 100% of app/routes/database/tests files (0 failures, PHP 8.2.29).

## Level-5 target state

A modular monolith on a supported Laravel line (12.x+/PHP 8.3) organised as `app/Domain/{Identity, Centers, Students, Memorization, Attendance, Assessment, Messaging, Reporting}` where each module owns its Models, Enums, Policies, FormRequests, Actions (single-purpose invokable classes wrapping transactions and locks), Events/Listeners (notifications, push, SMS as listeners on domain events), Jobs (imports, PDFs) and API Resources; controllers are 5-15-line adapters under `/api/v1` with named routes and scoped model binding. One exception renderer guarantees the `{success,message,data,errors}` envelope in Arabic for every status code, one `lang/ar` layer owns validation wording, and an OpenAPI spec generated from Resources is contract-tested in CI alongside Pint, PHPStan level 6+, `composer audit` and the feature suite, so the mobile team codes against a versioned, typed, published contract. Reporting is a read-model layer built on grouped SQL with query-count tests; heavy work is queued on a host that runs workers and the scheduler.

## What the Flutter team must know

What the mobile team must know: (1) Auth is one POST /api/auth/login whose `email` field also accepts display codes (T1/CA1/P1, case-insensitive, AuthController.php:35-37); the returned Sanctum token carries abilities ['parent'] | ['manager'] | ['*'] and tokens expire after 7 days (sanctum.php:55) and are revoked on password change/deactivation — the app must handle 401 by re-login at any time. (2) There is NO stable contract today: list endpoints return Laravel's paginator object under `data` (`data.data[]`, `current_page`, `last_page`, `total`, `links`) with full Eloquent rows, while detail endpoints return hand-picked arrays; the same entity (Student) has at least 4 shapes across /students, /students/{id}, /students/{id}/details, /parent/students/{id}. Model every payload defensively or, better, wait for the API Resources + OpenAPI deliverable (finding no-typed-api-contract-resources). (3) Dates arrive in two formats: `Y-m-d` strings from some endpoints and `2026-06-20T00:00:00.000000Z` (literal Z although the value is Africa/Tripoli local midnight) from `date`-cast attributes — parse with DateTime.parse then `.toLocal()` only for true timestamps, and treat date-only fields as calendar dates, never shift them. (4) Error handling: 422 = `{message, errors:{field:[...]}}` (Arabic, but some rules fall back to English/raw keys); 403 from middleware/controllers = `{success:false,message}`; 404/500 currently = Laravel default `{message}` in English with no `success` key; the PDF 403 uses `abort()`. Branch on HTTP status first. (5) Three responses violate the envelope: GET /dashboard has top-level `role`; POST /attendance/import returns counters at top level; PDF endpoints stream binary. (6) Enum values are Arabic literals you must send verbatim: teacher `type` 'محفظ أساسي'/'محفظ معاون', test `result` 'ناجح'/'راسب'; attendance status is English 'present|absent|late'; memorization quality 'excellent|good|average|weak'; nationality 'libyan|foreigner'. (7) Notification `link` is a web filename (e.g. 'parent/child.html?id=12') — until the payload is fixed, derive the target from `type` + `ref_id` (ref_id is a request id for request_*, a student id for memorization_added/test_added/message_received). (8) No idempotency: retrying POST /manager/student-requests/{id}/approve or POST /students on timeout can duplicate records — do not auto-retry non-idempotent POSTs. (9) No `/v1` prefix yet; expect a versioned base URL before store release. (10) Heavy endpoints (xlsx import, PDFs, admin reports) run synchronously and can take many seconds on the single-worker host — use long timeouts and progress UI, and expect these to become async jobs.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [No API Resources/FormRequests: responses are raw Eloquent dumps mixed with ad-hoc arrays, envelope violated in 3 places](#no-typed-api-contract-resources) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | L |
| [No global exception renderer: 404/500/401 fall through to Laravel defaults (English, leaks model class names, no `success` key)](#empty-exception-handler-leaks-and-breaks-envelope) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Business logic lives in 4,934-LOC controllers; only one service class exists](#fat-controllers-no-domain-layer) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | L |
| [Laravel 11.x (v11.51.0 locked) is past end of security support; PHP pinned to 8.2](#laravel-11-eol-dependency-line) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | M |
| [Ownership/scope authorization hand-rolled at 20 call sites with three different 403 shapes; no Policies](#authorization-copy-pasted-no-policies) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | M |
| [Check-then-write invariants are not locked or idempotent: double approve creates duplicate students, two admins can create two primaries](#unlocked-check-then-write-races) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [No lang/ directory: rules without custom messages return English locally and the literal key (e.g. 'validation.email') in production where APP_LOCALE=ar](#no-i18n-layer-validation-falls-back-to-english-or-raw-keys) | 🟡 medium | ✅ confirmed | NOW | S |
| [Notification payloads embed web-page paths ('manager/requests.html?...') instead of typed references](#notification-links-couple-api-to-web-pages) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Unversioned, unnamed routes with manual findOrFail and inconsistent parameter naming](#no-api-versioning-no-route-binding) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [No CI, no Pint config, no static analysis, stock README/.env.example; deployment is manual rsync via .cpanel.yml](#no-quality-gates-pint-phpstan-ci) | 🟡 medium | ✅ confirmed | NOW | S |
| [Roles, teacher type, attendance status, test result, request status are bare strings (Arabic literals included) with zero enums](#magic-strings-no-enums) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [No jobs/queues/events: xlsx import and mPDF rendering run synchronously in the request; production is QUEUE_CONNECTION=sync on shared cPanel](#no-async-boundary-heavy-work-in-request) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | L |
| [Half of ReportService (and ReportController::weekly) is N+1 and loads whole months of rows into PHP memory](#report-service-n-plus-one) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Alternate update routes bypass the audited status/identity paths (center is_active via PUT, teacher center_id & email via PUT)](#parallel-write-paths-bypass-audit) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Dead scaffolding and unused models: Vite/Tailwind assets, test-xlsx scripts in backend root, Revision/TajweedEvaluation, boilerplate tests, misplaced docblocks](#dead-code-and-stray-artifacts) | ⚪ low | ℹ️ informational | LATER | S |

### No API Resources/FormRequests: responses are raw Eloquent dumps mixed with ad-hoc arrays, envelope violated in 3 places

<a id="no-typed-api-contract-resources"></a>

`no-typed-api-contract-resources` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort L (1–2 weeks)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentController.php:139-142`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\TeacherController.php:96-100`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\DashboardController.php:44-52`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\AttendanceImportController.php:385-398`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\ReportPdfController.php:84`, `C:\Users\HP\SRS\MUTQIN\backend\app\Models\User.php:43-46`

**Evidence**

```text
`grep -rl 'JsonResource\|ResourceCollection\|FormRequest' app | wc -l` = 0; `grep -rn '\$request->validate' app | wc -l` = 36 inline calls. Raw model dumps: StudentController.php:141 `'data' => $students` (a LengthAwarePaginator of full Student models incl. national_id/guardian_phone), TeacherController.php:99 `'data' => $teacher` (full User row — User::$hidden hides only password/remember_token at :43-46, so id_number, password_changed_count, status_changed_by leak by default). Envelope violations: DashboardController.php:46 & :89 put `'role' => 'admin'|'teacher'` at top level; AttendanceImportController.php:387-397 returns `imported/updated/present/errors/name_warnings` at top level with no `data` key (consumed by frontend-html/js/ui.js:286 and FingerprintImportTest.php:56); ReportPdfController.php:84 `abort(403, ...)` bypasses the envelope. Date formats are mixed: `date`-cast attributes serialize as `Y-m-d\TH:i:s.u\Z` (20 date/datetime casts across models, no serializeDate override: `grep -rn serializeDate app` = 0) while CenterManagerController.php:545 emits `->toDateString()` and StudentController.php:464 `today()->toDateString()`.
```

**Why it matters**

The mobile team has no stable schema to code against: adding a column to `students` changes the public payload of 6+ endpoints; two date formats and non-enveloped responses force per-endpoint special-casing in Dart; sensitive columns leak whenever a model is returned wholesale. This is the single biggest de-risk for a 2-week Flutter build.

**Recommendation**

Create `app/Http/Resources/*` for User (role-aware), Student, Center, Attendance, Memorization, WeeklyTest(+Question), StudentRequest, Message, Notification and return them everywhere (use `additional(['success'=>true])` or a small `ApiResponse` helper). Add a `serializeDate()` override on a base model returning ISO-8601 with real offset (`toIso8601String()`) and emit plain `Y-m-d` only for date-only columns. Fix the three envelope violations (wrap import counters and dashboard role under `data`; replace `abort` with the JSON 403). Generate an OpenAPI 3 spec (scramble or l5-swagger) from the Resources and treat it as the mobile contract; phase 1 = auth/user, students, attendance, memorization, tests, notifications, messages.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual evidence checks out: no app/Http/Resources or app/Http/Requests directories exist (grep for JsonResource/ResourceCollection/FormRequest = 0), 36 inline $request->validate() calls, no serializeDate override, StudentController.php:139-142 returns the raw paginator, TeacherController.php:96-100 returns the raw User model, DashboardController.php:44-52 and :87-90 put 'role' at top level, AttendanceImportController.php:385-398 returns counters with no 'data' key, ReportPdfController.php:84 uses abort(403), and User::$hidden only covers password/remember_token. The date-cast serialization inconsistency (Carbon toJSON -> UTC 'Z' string vs ->toDateString() elsewhere) is also real and, with app timezone Africa/Tripoli, a date-only cast serializes as the previous day 22:00Z — a genuine hazard for any client not in the +2 zone. However, the severity is overstated on correctness grounds: (1) the "sensitive columns leak" is not a leak in this product — raw User/Student dumps are only returned on admin/manager/teacher-gated routes to roles entitled to that data, while the parent-facing endpoints (StudentController children/childDetails around :690-830) are hand-shaped arrays exposing only name/age/phone/guardian/center/teacher_name, so no User row reaches a parent; (2) the three envelope violations are handled by the existing client — api.js:65-68 treats any !res.ok as an error using data.message, which Laravel's default JSON 403 provides given the 'Accept: application/json' header at api.js:28; the import counters are consumed at top level in ui.js:285-286 and asserted in FingerprintImportTest.php; the extra top-level 'role' is additive and harmless; (3) ui.js fmtDate (:122-127) parses both ISO-Z and Y-m-d via new Date(). Nothing is functionally broken today; the finding is a maintainability/contract-stability concern whose 'high' rating rests on a hypothetical Flutter build described in the impact rather than on any defect in the shipped system. Real but should be medium.

```text
Confirmed as cited: backend/app/Http/Controllers/Api/StudentController.php:139-142 ('data' => $students raw paginator); TeacherController.php:96-100 ('data' => $teacher raw User); DashboardController.php:44-52 and :87-90 (top-level 'role'); AttendanceImportController.php:385-398 (no 'data' key); ReportPdfController.php:84 (abort(403)); User.php:43-46 ($hidden = password, remember_token). Mitigations: frontend-html/js/api.js:28 sends Accept: application/json and :65-68 treats any !res.ok as ApiError via data.message, so the abort(403) JSON is handled; ui.js:285-286 consumes import counters at top level (contract is de facto stable and tested in tests/Feature/FingerprintImportTest.php); ui.js:122-127 fmtDate parses both date formats. Parent-facing payloads are hand-built (StudentController.php ~:690-830: teacher_name, center name only), so no User row with id_number/password_changed_count reaches an unprivileged role — raw dumps go only to admin/manager/teacher gates. Date hazard is real: 'date' casts on Student.php:31-32, Attendance.php:23, Memorization.php:24, WeeklyTest.php:18 serialize via Carbon toJSON as UTC (e.g. 2026-09-13T22:00:00.000000Z for a Tripoli 2026-09-14), while CenterManagerController.php:545 / StudentController.php:464 emit Y-m-d.
```

</details>

### No global exception renderer: 404/500/401 fall through to Laravel defaults (English, leaks model class names, no `success` key)

<a id="empty-exception-handler-leaks-and-breaks-envelope"></a>

`empty-exception-handler-leaks-and-breaks-envelope` · 🟠 high (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\bootstrap\app.php:22-24`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentController.php:343`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\AttendanceImportController.php:36-42`

**Evidence**

```text
bootstrap/app.php:22-24 `->withExceptions(function (Exceptions $exceptions): void { // })` — empty. `ls app/Exceptions` -> 'No such file or directory'; `grep -rn 'Policy\|Gate::\|authorize(' app | wc -l` = 0. Every `findOrFail($id)` (e.g. StudentController.php:343, :455, :492; WeeklyTestController.php:120) therefore renders Laravel's default `{"message":"No query results for model [App\\Models\\Student] 5"}` — English, exposes internal class names, and lacks the `success:false` key the frontend api.js expects; unhandled 500s render `{"message":"Server Error"}`; ValidationException 422 happens to carry `message`+`errors` but also no `success`. Tests only assert status codes (OwnershipTest.php:75 `->assertStatus(404)`), never the 404 body, so the gap is untested.
```

**Why it matters**

A mobile client cannot rely on `success` being present on error paths and must branch on HTTP status plus two different JSON shapes; internal class names in 404s are an information-disclosure smell reviewers will flag; no place exists to map domain exceptions (e.g. a future `CenterInactiveException`) to Arabic 4xx responses.

**Recommendation**

Register renderables in `withExceptions`: ModelNotFoundException/NotFoundHttpException -> 404 `{success:false,message:'العنصر غير موجود',data:null,errors:null}`; AuthenticationException -> 401; AuthorizationException -> 403; ValidationException -> 422 with `success:false`; Throwable -> 500 with a generic Arabic message + `error_id` correlating to the log line. Add one feature test per mapping. Then introduce `app/Exceptions/Domain/*` (e.g. PrimaryTeacherExists, RequestAlreadyProcessed) and let actions throw them instead of returning ad-hoc 422 arrays (55 hand-built `'success' => false` sites today).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Core facts confirmed: backend/bootstrap/app.php:22-24 has an empty withExceptions closure, app/Exceptions does not exist, and there are ~37 findOrFail()/firstOrFail sites across controllers (StudentController.php:343/390/455/492/578/631/753, CenterController.php:58/95/178/205/226/255, TeacherController.php:110/148/199, WeeklyTestController.php:52/120/175, etc.) with no ModelNotFoundException handling anywhere in app/. No manual 404 catch exists except NotificationController.php:51. Under Laravel 11 defaults, ModelNotFoundException becomes NotFoundHttpException carrying the message "No query results for model [App\Models\Student] N", which the JSON renderer emits verbatim with no `success` key; AuthenticationException emits {"message":"Unauthenticated."}; unhandled errors emit {"message":"Server Error"} (or a full trace when APP_DEBUG=true, as in .env.example). Tests only assert status (OwnershipTest.php:75, AdminCenterDetailsTest.php:127 assertNotFound), never body shape. So the finding is real and not refuted.

However, severity 'high' is exaggerated for this product:
1. The only client, frontend-html/js/api.js:65, branches on `!res.ok || data.success === false` — it never depends on `success` being present on error paths, and 401 is handled by status alone at :51-58. So the "breaks envelope" claim has no practical effect on the shipped client; the hypothetical "mobile client" does not exist.
2. The information disclosed (Eloquent model class names like App\Models\Student) is trivially guessable from the route names and carries negligible security value; it is a cosmetic/hygiene issue.
3. The UX effect is limited: a 404 toast would show the English Laravel message, but ids come from server-rendered lists, so users rarely hit it; 500s show 'Server Error' (English) — a minor Arabic-consistency defect.
4. One cited file is mis-cited as evidence: AttendanceImportController.php:36-42 is a correctly handled try/catch returning an Arabic 422 envelope, i.e. it is the opposite of the problem.
5. The policy/Gate grep (=0) is irrelevant to this finding — authorization is done by the four middleware aliases which do return the Arabic envelope (e.g. AdminMiddleware returns success:false, 403).

Note: vendor/ and .env are absent from this checkout, so I could not execute a request to observe the live 404 body; the rendering behaviour is asserted from Laravel 11 framework semantics. Net: real but low-impact consistency/hygiene issue, best rated low-to-medium; I set medium only because APP_DEBUG=true in .env.example would leak stack traces on 500s if deployed as-is, though .env.production.example correctly sets APP_DEBUG=false.

```text
backend/bootstrap/app.php:22-24 empty withExceptions (confirmed). ~37 findOrFail sites, e.g. backend/app/Http/Controllers/Api/StudentController.php:343,390,455,492,578,631,753; CenterController.php:58,95,178,205,226,255; TeacherController.php:110,148,199; WeeklyTestController.php:52,120,175 — no ModelNotFoundException handling in app/ (only NotificationController.php:51 returns a manual Arabic 404). Mitigation: frontend-html/js/api.js:51-58 handles 401 by status; :65 `if (!res.ok || data.success === false)` throws on any non-2xx regardless of `success` key, falling back to data.message or 'فشل تنفيذ الطلب (status)'. Mis-cited: AttendanceImportController.php:36-42 is a proper try/catch returning an Arabic success:false 422 — not an example of the gap. .env.example:4 APP_DEBUG=true; .env.production.example:15 APP_DEBUG=false. Tests assert status only: OwnershipTest.php:75, AdminCenterDetailsTest.php:123-129.
```

</details>

### Business logic lives in 4,934-LOC controllers; only one service class exists

<a id="fat-controllers-no-domain-layer"></a>

`fat-controllers-no-domain-layer` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort L (1–2 weeks)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentController.php:162-272`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\AttendanceImportController.php:17-399`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentRequestController.php:224-348`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\CenterManagerController.php:109-165`, `C:\Users\HP\SRS\MUTQIN\backend\app\Services\ReportService.php:1`

**Evidence**

```text
`find app/Http/Controllers -name '*.php' | xargs cat | wc -l` = 4934 of 7072 app LOC (69.8%); `ls app/Services` = ReportService.php only; `ls app/Actions app/Jobs app/Events app/Listeners` -> 'No such file or directory'. Largest methods (approximate cyclomatic complexity / LOC): AttendanceImportController::import CC~62 / 391 LOC (a single method containing a DB::transaction closure with 15 by-reference captures, lines 97-102); ParentResolver::resolve CC~30; StudentRequestController::approve CC~19 / 129 LOC; StudentController::store CC~14 / 122 LOC and mutates the request in-controller (`$request->replace(array_diff_key(...))` :171, `$request->merge([...])` :180). 34 of 219 methods exceed 50 LOC, 5 exceed 100. Teacher creation is implemented twice (TeacherController::store :53-101 vs CenterManagerController::storeTeacher :109-165) with the primary-teacher rule duplicated inline at :136-144 instead of extending PrimaryTeacherRule; student creation for admin and manager share one method with role `if`s (:179, :212).
```

**Why it matters**

Every new capability (mobile endpoints, a second import format, a revision/tajweed module) must be threaded through already-large request handlers; the same rule (primary teacher, guardian resolution, transfer approval) can silently diverge between admin/manager/teacher paths; controllers cannot be unit-tested without HTTP + MySQL, so the 178 feature tests are the only guard and each takes a full DB refresh.

**Recommendation**

Introduce a domain layer: `app/Domain/<Module>/Actions/*` (e.g. CreateStudent, ImportFingerprintAttendance, ApproveStudentRequest, RegisterTeacher) invoked from thin controllers; move ImportFingerprintAttendance parsing into a dedicated `AttendanceSheetParser` + `AttendanceImporter` pair so parsing is unit-testable without a DB; make CenterManagerController::storeTeacher and TeacherController::store call one RegisterTeacher action that owns the locked primary check. Target: no controller method > 40 LOC.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

Every factual claim checks out: `find app/Http/Controllers -name '*.php' | xargs cat | wc -l` = 4934 and app total = 7072; app/Services contains only ReportService.php; app/Actions and app/Jobs do not exist. AttendanceImportController has exactly two methods (import at :17, namesMatch at :408) and the DB::transaction closure at :97-102 captures 15 by-reference variables as quoted. StudentController::store mutates the request via `$request->replace(...)` (:171) and `$request->merge(['center_id' => ...])` (:180) and branches on `$user->isCenterManager()`. Teacher creation genuinely exists twice: TeacherController::store (:53-101) and CenterManagerController::storeTeacher (:109-165), and the manager path re-implements the primary-teacher query inline with lockForUpdate (:136-144) and a different Arabic message rather than calling PrimaryTeacherRule::assert. The divergence the auditor warns about has in fact already happened: the admin path calls PrimaryTeacherRule::assert (:79) BEFORE and outside the transaction with no lock, while the manager path checks inside the transaction with lockForUpdate — so the admin route has a race window the manager route closed. So the finding is correct, not exaggerated in its facts.

Two things temper the severity, though. First, "no domain layer" overstates it: app/Support already holds 8 extracted rule/helper classes (ParentResolver, PrimaryTeacherRule, DisplayCode, LoginEmail, PhoneNumber, ArabicText, SurahReference, Percentage) plus ReportService — a partial domain layer exists and the pattern of extraction is established, so the recommended refactor is incremental rather than greenfield. Second, the risk is mitigated by 178 feature tests across 38 files that directly cover the fat paths named (FingerprintImportTest, ManagerAddTeacherTest, ManagerAddStudentGuardianTest, StudentTransferRequestTest, RoleMatrixTest), so behavioural regressions are caught even if the code is hard to unit-test. There is no user-facing correctness or security defect here beyond the small unlocked-primary race on the admin route; for a small-team, single-country Arabic product this is a maintainability debt, not a high-severity risk. Medium is the right rating.

```text
Confirmed: backend/app/Http/Controllers/Api/AttendanceImportController.php:17 (import) and :408 (namesMatch) are the only methods; :97-102 transaction closure with 15 by-ref captures. StudentController.php:171 `$request->replace(array_diff_key(...))`, :180 `$request->merge(['center_id' => $user->center_id])`. TeacherController.php:53-101 store vs CenterManagerController.php:109-165 storeTeacher — duplicate. Divergence already present: TeacherController.php:79 calls PrimaryTeacherRule::assert outside/before the transaction (no lock, race window), whereas CenterManagerController.php:136-144 does an inline lockForUpdate check inside DB::transaction with a different message than app/Support/PrimaryTeacherRule.php:31-33. Mitigations: app/Support/ already contains 8 extracted rule classes (partial domain layer); tests/Feature has 38 files / 178 tests including FingerprintImportTest.php, ManagerAddTeacherTest.php, ManagerAddStudentGuardianTest.php, StudentTransferRequestTest.php covering the cited fat methods.
```

</details>

### Laravel 11.x (v11.51.0 locked) is past end of security support; PHP pinned to 8.2

<a id="laravel-11-eol-dependency-line"></a>

`laravel-11-eol-dependency-line` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\composer.json:8-9`, `C:\Users\HP\SRS\MUTQIN\backend\composer.lock:9433-9435`, `C:\Users\HP\SRS\MUTQIN\backend\DEPLOY_LOG.md:3`

**Evidence**

```text
composer.json:8-9 `"php": "^8.2", "laravel/framework": "^11.0"`; composer.lock `"name": "laravel/framework", "version": "v11.51.0"`, `"platform-overrides": {"php": "8.2.12"}`. Laravel 11 bug-fix support ended 2025-09-03 and security fixes ended 2026-03-12 (today is 2026-09-14), so the framework line receives no patches while the 12.x line has been GA since 2025-02. The production host runs PHP 8.3 (DEPLOY_LOG.md:3 `PHP 8.3`) but composer resolves against a forced 8.2.12 platform, so lock resolution does not reflect the runtime. Other deps are current: sanctum 4.3.2, mpdf 8.3.1, phpspreadsheet 5.8.0, phpunit 11.5.55, pint 1.29.1.
```

**Why it matters**

Any framework CVE disclosed from now on ships no fix for 11.x; dozens of centers plus a public mobile app materially raise exposure. The 11->12 upgrade is mostly mechanical for this codebase (no Blade app, no queues, no broadcasting) but gets harder the longer controllers grow.

**Recommendation**

Upgrade to laravel/framework ^12 (and to the current line if 13 is GA by execution time), bump `php` to `^8.3` and the platform override to the host's 8.3 build, run the 38-file suite as the gate. Add `composer audit` to CI (see quality-gates finding) and Dependabot/Renovate on composer.lock.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

The factual core holds. backend/composer.json:8-9 pins `"php": "^8.2"` and `"laravel/framework": "^11.0"`; composer.lock:1133-1134 locks `laravel/framework v11.51.0` and composer.lock:9433-9435 carries `"platform-overrides": {"php": "8.2.12"}`; DEPLOY_LOG.md:3 states the host runs PHP 8.3. Laravel 11's published support window (bug fixes to 2025-09-03, security fixes to 2026-03-12) has indeed elapsed as of 2026-09-14, so the locked line receives no upstream patches. Nothing in the repo mitigates this (no CI, no `composer audit`, no Renovate). However the severity is overstated: (1) no concrete unpatched vulnerability affecting 11.51.0 is identified — this is an "outdated component" exposure finding, conventionally rated medium absent a live CVE; (2) the impact text cites "a public mobile app" that does not exist anywhere in the repo or docs (grep across CLAUDE.md, DEPLOYMENT.md, DEPLOY_LOG.md finds no mobile/android/flutter reference) — the client is a static HTML page; (3) the app is a small single-country, single-tenant deployment where the attack surface is a token-gated JSON API with a well-tested role/ability model (38 feature-test files). Also a minor citation error: the framework lock entry is at composer.lock:1133, not 9433 (9433 is the platform-overrides block). The DEPLOY_LOG additionally notes the host has no SSH/composer, so the upgrade must be done locally and vendor re-uploaded — relevant to effort but not to validity. Real finding, correct recommendation, severity should be medium.

```text
C:\Users\HP\SRS\MUTQIN\backend\composer.json:8-9 — `"php": "^8.2"`, `"laravel/framework": "^11.0"`. C:\Users\HP\SRS\MUTQIN\backend\composer.lock:1133-1134 — `"name": "laravel/framework"`, `"version": "v11.51.0"` (auditor cited 9433-9435, which is actually the `"platform-overrides": {"php": "8.2.12"}` block). C:\Users\HP\SRS\MUTQIN\backend\DEPLOY_LOG.md:3 — host PHP 8.3; lines 6-9 note SSH/composer/artisan are unavailable on the host, so the upgrade must be performed locally and the vendor tree re-uploaded via cPanel. No mobile app exists in the repository; the "public mobile app" impact claim is unsupported.
```

</details>

### Ownership/scope authorization hand-rolled at 20 call sites with three different 403 shapes; no Policies

<a id="authorization-copy-pasted-no-policies"></a>

`authorization-copy-pasted-no-policies` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentController.php:345`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentController.php:374-381`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\MemorizationController.php:168`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\WeeklyTestController.php:54`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\CenterManagerController.php:298`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentRequestController.php:199-215`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\MessageController.php:26-49`

**Evidence**

```text
`grep -rn "isAdmin() && .*teacher_id !== \$user->id"` = 11 sites (AttendanceController:67, MemorizationController:168,214, ReportController:20, ReportPdfController:83, StudentController:345,377,494, WeeklyTestController:54,122,177); manager center-scope comparisons hand-written at 9 sites (CenterManagerController:298,459,573,649,663; StudentController:581,633; StudentRequestController:208; AttendanceImportController:193). Inconsistent forms: StudentController.php:345 strict `$student->teacher_id !== $user->id` vs :377 `(int) $student->teacher_id !== (int) $user->id`; StudentController.php:374-381 defines a private `forbidUnlessOwnStudent` helper but `show()` at :345 and `update()` at :494 re-inline the same check instead of using it. 403 payloads differ: `'غير مصرح لك بالوصول لهذا الطالب'` (10 variants of 'غير مصرح'), `'خارج نطاق صلاحيتك'` (CenterManagerController:462,576), `'هذا الطالب ليس من طلاب مركزك'` (:584,:635,:664). `grep -rn 'Policy\|Gate::\|authorize(' app` = 0.
```

**Why it matters**

Each new endpoint (the mobile roadmap will add several) re-implements authorization by hand; one forgotten line is a cross-tenant data leak across centers. The dual role+ability model is excellent but is only enforced at the route-group level; object-level rules have no single owner and no single test seam.

**Recommendation**

Add `StudentPolicy` (view/update/recordFor/message), `TeacherPolicy`, `AttendancePolicy`, `StudentRequestPolicy` (approve/reject = active manager of target center) and a `BelongsToManagerCenter` scope on Student/User queries; call `$this->authorize()` or `Gate::authorize()` and let AuthorizationException render the single Arabic 403 via the exception handler. Delete the 20 inline checks. Keep RoleMatrixTest/OwnershipTest and add policy unit tests.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The evidence is substantially accurate. `grep -rn "isAdmin() && .*teacher_id !== \$user->id" app` returns 10 sites (auditor said 11; the 11th, StudentController:377 `forbidUnlessOwnStudent`, uses `(int)` casts and so does not match the pattern — minor miscount). `grep -rn 'Policy\|Gate::\|authorize(' app` returns 0 and there is no app/Policies directory. StudentController:345 (show) and :494 (update) do re-inline the check instead of calling the private helper at :374-381. Manager 403 messages differ ('خارج نطاق صلاحيتك' at CenterManagerController:462/576, 'هذا الطالب ليس من طلاب مركزك' at :664 and StudentController:584/636) and MessageController:26-49 has yet another two messages. So the code-quality observation stands.

However, on the CORRECTNESS lens the finding is over-rated: I traced every cited site and each one actually performs the correct ownership/scope check; none is missing or wrong. The mixed strict `!==` vs `(int)` casts is not a live bug: `teacher_id`/`parent_id` are `foreignId` (BIGINT) columns and PDO mysqlnd returns native ints, so `$student->teacher_id !== $user->id` compares int to int on the only supported DB (MySQL, per CLAUDE.md sqlite is unusable). The impact text itself concedes 'one forgotten line' — i.e. hypothetical future risk, not an existing leak. Mitigations exist: the route groups already enforce the dual role+ability gate (bootstrap/app.php aliases), and object-level checks are covered by feature tests (tests/Feature/OwnershipTest.php: teacher cannot access/record for another teacher's student, parent cannot access another's child; RoleMatrixTest, CenterManagerTest (8 tests), MessagingTest (5 tests), ManagerAttendanceReviewTest, ManagerReportsScopeTest, StudentTransferRequestTest). Inconsistent 403 message wording is cosmetic for an Arabic-only API whose frontend only checks the status code.

Net: a real maintainability/design-debt finding (no single seam for object-level authorization, duplicated checks, unused helper), but with no demonstrated defect, correct behavior at every call site, and existing test coverage, 'high' is not justified. Medium is appropriate as a refactor recommendation ahead of the mobile roadmap.

```text
backend/app/Http/Controllers/Api/StudentController.php:345 and :494 inline `!$user->isAdmin() && $student->teacher_id !== $user->id` while :374-381 defines the unused-by-them helper `forbidUnlessOwnStudent` with `(int)` casts. Exact grep count for the strict pattern is 10 sites (AttendanceController:67, MemorizationController:168,214, ReportController:20, ReportPdfController:83, StudentController:345,494, WeeklyTestController:54,122,177), not 11. `grep -rn 'Policy\|Gate::\|authorize(' app` = 0; no app/Policies dir. Strict comparison is safe in practice: students.teacher_id is `foreignId` (migrations/2024_01_01_000020_create_students_table.php:19) and MySQL/mysqlnd returns ints. All cited checks are present and correct; coverage exists in tests/Feature/OwnershipTest.php (4 tests), RoleMatrixTest.php, CenterManagerTest.php, MessagingTest.php, ManagerAttendanceReviewTest.php, ManagerReportsScopeTest.php.
```

</details>

### Check-then-write invariants are not locked or idempotent: double approve creates duplicate students, two admins can create two primaries

<a id="unlocked-check-then-write-races"></a>

`unlocked-check-then-write-races` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentRequestController.php:232-237`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentRequestController.php:274-295`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentRequestController.php:152-161`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\TeacherController.php:79-82`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\CenterManagerController.php:135-144`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\ManagerManagementController.php:79`

**Evidence**

```text
StudentRequestController.php:226 `$req = StudentRequest::findOrFail($id)` (no lock) -> :232 `if ($req->status !== 'pending') return 422` -> :274 `DB::transaction(function () ... Student::create([...]); $req->update(['status' => 'approved' ...])` — the status re-check is not repeated inside the transaction and the row is not `lockForUpdate()`, so two concurrent approves of an `add` row both pass :232 and both create a student. Same pattern for the transfer branch :314-330 and the duplicate-pending guard at :152-161 (`StudentRequest::where(...)->exists()` then `create` at :163). TeacherController.php:79 `$this->assertSinglePrimary(...)` runs BEFORE the `DB::transaction` at :82 with no lock, whereas CenterManagerController.php:136-144 correctly does `->lockForUpdate()->first()` inside the transaction — but by re-implementing the rule inline instead of giving PrimaryTeacherRule a locked mode. ManagerManagementController.php:79 `assertSingleSupervisor` likewise unlocked.
```

**Why it matters**

Mobile clients retry on flaky networks and users double-tap; a duplicated approve produces two students with two display codes (codes are never reused), a duplicated primary teacher breaks the has-primary UI and reports. Low probability per request, but the invariants are exactly the ones the product documents as hard rules.

**Recommendation**

Inside each transaction re-read with `lockForUpdate()` and re-assert the invariant (request status, primary exists, pending duplicate, single supervisor); give `PrimaryTeacherRule::assert` a `$lock = true` path and make both teacher-create controllers use it. Add a DB-level guard where possible (partial unique on student_requests(student_id) WHERE status='pending' via a generated column; unique (center_id, type='primary') via a nullable `primary_center_id` column). Accept an `Idempotency-Key` header on POST approve/store for the mobile client.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The code paths are exactly as quoted. StudentRequestController::approve loads the row with findOrFail (no lock) at line 226, checks status at 232, and the DB::transaction at 274 (add) and 314 (transfer) neither re-reads with lockForUpdate nor re-asserts status. The duplicate-pending guard at 152-161 is a bare exists() before create(). TeacherController::store calls assertSinglePrimary (PrimaryTeacherRule::assert, plain exists()) at line 79 outside the transaction at 82; ManagerManagementController::assertSingleSupervisor (lines 24-36, plain exists()) runs at 79 before its transaction. Only CenterManagerController::store (136-144) uses lockForUpdate. No DB constraints exist for these invariants (no unique index on student_requests, no unique on users(center_id,type) or users(center_id,role)), and no feature test covers concurrent approve/create (the only race test is ManagerAddStudentGuardianTest::test_concurrent_parent_creation_rematches_instead_of_500, which is a different path). So the finding is factually correct and not fully mitigated.

However it is overrated. Mitigations/scope limits the auditor understated: (1) the transfer approve is effectively idempotent — a second concurrent approve performs the same student->update to the same center/teacher and same req->update; the only side effect is a duplicate notification, not data corruption. (2) The transfer-approve UI goes through UI.formModal, which disables the submit button for the duration of the request (ui.js:196-204), so a user double-tap cannot fire two approves there. (3) The `add` branch — the only path that can create a duplicate student — exists solely for legacy rows created before the teacher add flow was removed (routes/api.php has no way to create new add rows), so the exposed population is finite and draining. (4) students.national_id is UNIQUE (migration 2026_06_21_130000), so for any add row carrying a national id the second concurrent Student::create fails at the DB with an integrity error rather than producing a duplicate; only null-national_id legacy rows can duplicate. (5) The double-primary / double-supervisor races need two admin requests for the same center inside the same few-millisecond window; the admin role is a single system account in this product, and the outcome is a self-inflicted data-quality issue the admin can fix via the edit/status endpoints, not a security or privilege issue. The auditor's own impact text concedes 'low probability per request'. The one genuinely unguarded double-click is the raw `add` approve button in manager/requests.html:57 (no disable), but that only matters for legacy null-national-id rows. Net: real hygiene issue (recommendation to lock/re-check inside the transaction is sound and cheap), but the realistic impact is low, not medium.

```text
Confirmed as cited: backend/app/Http/Controllers/Api/StudentRequestController.php:226 findOrFail (no lock), :232 status check outside transaction, :274 and :314 DB::transaction without lockForUpdate or status re-check; :152-161 exists() then :163 create(). TeacherController.php:79 assertSinglePrimary before DB::transaction at :82. ManagerManagementController.php:24-36 assertSingleSupervisor uses plain exists(); called at :79 and :117. CenterManagerController.php:136-144 is the only locked variant. Partial mitigations not credited: students.national_id UNIQUE (database/migrations/2026_06_21_130000_add_national_id_to_students.php:17) blocks duplicate student creation for add rows that carry a national id; transfer approve at :314-322 is idempotent (same update twice, only a duplicate notification); frontend-html/js/ui.js:196-204 disables the modal submit button during the request (covers transfer approve, teacher create, manager create); only the legacy `add` approve button (frontend-html/manager/requests.html:54-60) fires without a disable, and new add rows can no longer be created (routes/api.php:87-90 exposes only manager transfer store). No test in backend/tests/Feature covers these races.
```

</details>

### No lang/ directory: rules without custom messages return English locally and the literal key (e.g. 'validation.email') in production where APP_LOCALE=ar

<a id="no-i18n-layer-validation-falls-back-to-english-or-raw-keys"></a>

`no-i18n-layer-validation-falls-back-to-english-or-raw-keys` · 🟡 medium · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\.env.production.example:29-30`, `C:\Users\HP\SRS\MUTQIN\backend\config\app.php:83-85`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\TeacherController.php:150-156`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\CenterController.php:228-231`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentRequestController.php:239`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\ManagerManagementController.php:127`

**Evidence**

```text
`find . -type d -name lang -not -path './vendor/*'` -> nothing; no `validation.php` outside vendor. config/app.php:83-85 `'locale' => env('APP_LOCALE', 'en')`; .env.production.example:29-30 `APP_LOCALE=ar` / `APP_FALLBACK_LOCALE=ar`. Validation calls with no messages array: TeacherController.php:150-156 (`'name' => 'required|string|max:255', 'email' => 'required|email|unique:...'`), :170 `validate(['password' => 'min:6|confirmed'])`, CenterController.php:228-231, StudentRequestController.php:239, ManagerManagementController.php:127, CenterManagerController.php:423; and messaged calls cover only some rules (e.g. StudentController.php:196-228 has no `phone.max`, `name.max`, `guardian_email.max`, `age.integer`). Laravel's Validator returns the untranslated key when neither locale nor fallback has the file, so with `ar/ar` and no `lang/ar/validation.php` the API emits strings like `validation.max.string`.
```

**Why it matters**

Arabic-only end users (and the mobile app's error banners) will see English or raw translation keys for any rule the developer forgot to message; the product promise 'validation failures are Arabic' is enforced by per-call vigilance instead of by the framework.

**Recommendation**

Publish `lang/ar/validation.php` (+ `attributes` map: name -> 'الاسم', center_id -> 'المركز', ...) and set APP_LOCALE=ar in every environment; then delete most per-call message arrays, keeping only rule-specific business wording. Add a test asserting no 422 message in the suite contains 'validation.' or 'The '. This is the natural moment to move rules into FormRequests.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Evidence verified on disk. There is no `lang/` directory and no `validation.php` anywhere outside vendor (backend/resources contains only css/js/views). config/app.php:83-85 reads `APP_LOCALE`/`APP_FALLBACK_LOCALE` with `en` defaults; .env.example sets en/en while .env.production.example:29-30 sets ar/ar. All cited validate() calls exist and carry no messages array: TeacherController.php:150-156 and :170 (`min:6|confirmed`), CenterController.php:228-231, StudentRequestController.php:239, ManagerManagementController.php:127, CenterManagerController.php:423. Additional unmessaged rules found beyond those cited: AuthController.php:23-30 (`email.max`, `email.string` unmessaged on the login endpoint), TeacherProfileController.php:76-85 (`password.confirmed` unmessaged). No mitigation exists: bootstrap/app.php's withExceptions block does not override validation rendering or set a locale; no middleware sets locale; the frontend (ui.js:79-84, 181-187) prints `errors[field][0]` verbatim into the form, so whatever string the API emits is what the Arabic-only user sees. Tests only assert on messages the developers explicitly wrote in Arabic and never assert absence of English/raw keys. Laravel 11 (v11.51.0 per composer.lock) ships only `en` framework translations via FileLoader's built-in `__DIR__.'/lang'` path, so: with the default en/en, users get English sentences ("The password field confirmation does not match."); with the production example ar/ar, Translator::get returns the untranslated key (e.g. `validation.confirmed`, `validation.max.string`). I could not execute this live (backend/vendor is absent and C:\xampp\php\php.exe does not exist on this machine), but the framework behavior is deterministic and well-established. The finding is factually correct and not mitigated. Severity: medium is fair — no security impact, but it is a direct violation of the product's stated "422 errors are Arabic" contract, hit on very common rules (max, confirmed, string, integer), and the production env template makes the worst case (raw keys) the configured default. Effort S is accurate (publish lang/ar/validation.php).

```text
Confirmed: backend/config/app.php:83 `'locale' => env('APP_LOCALE', 'en')`, :85 `'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en')`; backend/.env.example:7-8 en/en; backend/.env.production.example:29-30 ar/ar; no lang/ dir or validation.php outside vendor. Unmessaged rules (additional to those cited): backend/app/Http/Controllers/Api/AuthController.php:23-30 login validate has messages only for email.required/password.required/password.min — `email.string`/`email.max` fall through to framework text; backend/app/Http/Controllers/Api/TeacherProfileController.php:76-85 `password.confirmed` has no message. Frontend renders server messages verbatim: frontend-html/js/ui.js:84 and :186 `box.textContent = errors[k][0]`. No locale-setting middleware or validation-exception override in backend/bootstrap/app.php:22.
```

</details>

### Notification payloads embed web-page paths ('manager/requests.html?...') instead of typed references

<a id="notification-links-couple-api-to-web-pages"></a>

`notification-links-couple-api-to-web-pages` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\app\Notifications\InAppNotification.php:166-175`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentRequestController.php:184`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentRequestController.php:418-421`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\MessageController.php:188`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\MemorizationController.php:198`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\WeeklyTestController.php:100`

**Evidence**

```text
InAppNotification::toArray stores `{type,title,body,ref_id,link}` (:168-174) where `link` is a static HTML path at 6 sites: StudentRequestController.php:184 `'manager/requests.html'`, :420 `? 'manager/requests.html' : 'teacher/students.html'`, MessageController.php:188 `'/messages.html?student=' . $student->id`, MemorizationController.php:198 & WeeklyTestController.php:100 `'parent/child.html?id=' . $student->id`, ManagerManagementController.php:192 `'admin/managers.html'`. `ref_id` is overloaded (request id, student id, or test id depending on `type`) with no `ref_type`, and the `message_received` type is not listed in the class docblock (:142-146) or CLAUDE.md.
```

**Why it matters**

A Flutter client tapping a notification has nothing to route on except parsing a web filename and query string; renaming a web page changes stored notification rows retroactively (no migration path). Notification `type` set is undocumented for the mobile team.

**Recommendation**

Change the payload to `{type, title, body, ref: {type: 'student_request'|'student'|'weekly_test'|'message_thread', id}, web_link}` (keep `link` for the web client during transition, derive it server-side from ref); document the closed set of `type` values as an enum exposed via the meta endpoint; add `NotificationResource`.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The core factual claim is confirmed: InAppNotification::toArray stores {type,title,body,ref_id,link} and `link` is a relative static-HTML path at all 6 cited call sites (grep confirms 'manager/requests.html', 'teacher/students.html', 'parent/child.html?id=', role.'/messages.html?student=', 'admin/managers.html'). NotificationController@index re-exposes only `link` (not even `ref_id`), and layout.js:241 navigates via `APP_ROOT + n.link`, so the web client is hard-wired to the file paths, and StudentRequestNotificationTest.php:49 asserts the literal 'teacher/students.html' — locking the coupling in. `message_received` is indeed absent from the class docblock. However, the finding is overrated: (1) several cited line numbers are wrong — InAppNotification.php is 63 lines, so ":166-175" and ":142-146" do not exist (actual: docblock :13-18, toArray :38-46); the MessageController quote drops the role prefix (actual value is 'teacher/messages.html?...' or 'parent/messages.html?...'). (2) The impact rests on a hypothetical Flutter client — no mobile/native client exists or is mentioned anywhere in the repo; mobile is delivered as a PWA of the same frontend-html (DEPLOY_LOG.md:75), for which these links are exactly right. (3) The "meta endpoint" the recommendation says to extend does not exist in routes/api.php. (4) Renaming a web page breaking old stored rows is real but trivially handled by a one-line data update on `notifications.data`, and the frontend uses APP_ROOT so deployments under different roots still work. This is a legitimate but minor design-smell / documentation gap for a small single-client product, not a medium-severity architectural defect.

```text
backend/app/Notifications/InAppNotification.php:13-18 (docblock listing types, missing message_received); :38-46 toArray returns {type,title,body,ref_id,link}. Call sites: StudentRequestController.php:184 'manager/requests.html'; :420 ternary 'manager/requests.html' : 'teacher/students.html'; MessageController.php:188 ($myRole==='parent' ? 'teacher':'parent').'/messages.html?student='.$id; MemorizationController.php:198 and WeeklyTestController.php:100 'parent/child.html?id='.$id; ManagerManagementController.php:192 'admin/managers.html'. Consumer: NotificationController.php:27 exposes only 'link' (no ref_id); frontend-html/js/layout.js:207,241 navigates to APP_ROOT + link. Test locks coupling: tests/Feature/StudentRequestNotificationTest.php:49 assertSame('teacher/students.html', $notif->data['link']). No mobile/Flutter client exists in the repo (only PWA — DEPLOY_LOG.md:75); no 'meta' endpoint exists in routes/api.php.
```

</details>

### Unversioned, unnamed routes with manual findOrFail and inconsistent parameter naming

<a id="no-api-versioning-no-route-binding"></a>

`no-api-versioning-no-route-binding` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\routes\api.php:26`, `C:\Users\HP\SRS\MUTQIN\backend\routes\api.php:47-49`, `C:\Users\HP\SRS\MUTQIN\backend\routes\api.php:65-66`, `C:\Users\HP\SRS\MUTQIN\backend\routes\api.php:141-144`

**Evidence**

```text
routes/api.php: 89 explicit `Route::get|post|put` lines + 5 `apiResource` (~107 endpoints) all under bare `/api/` — no `v1` prefix (`grep -n 'prefix(' routes/api.php` = 0), no `->name()` on any route, no `Route::controller()` groups (FQCNs repeated inline, e.g. `\App\Http\Controllers\Api\CenterManagerController::class` 22 times). Every action receives `$id` and does `Model::findOrFail($id)` (StudentController.php:343,455,492,578,631; WeeklyTestController.php:120,175 ...) instead of implicit/scoped model binding; parameter naming varies `{student}` (:48-49,:148-149) vs `{id}` everywhere else. CLAUDE.md's route table is stale: weekly-tests documented as index/store/destroy/show but code is `->only(['index','store','show','update'])` (:164); undocumented endpoints include /students/{id}/details, /students/{id}/day, /manager/students/{id}/teacher, /manager/parents, /manager/teachers/{id}/performance, /manager/reports/{center,teacher,student}, /centers/{id}/{stats,teachers,students}, /admin/users, all /messages routes.
```

**Why it matters**

Store-distributed mobile apps live for months on old versions; without `/v1` any contract fix (see resources finding) is a forced-upgrade event. Missing route names block URL generation in notifications/emails; manual findOrFail is what produces the un-enveloped 404s.

**Recommendation**

Wrap everything in `Route::prefix('v1')->name('v1.')` (keep an alias group at `/api/` for the web client during transition), adopt `Route::controller()` groups per module, name routes, and use implicit binding with `scopeBindings()`/custom resolution (`Student::query()->forUser($user)`) so 404-vs-403 semantics are centralized. Regenerate the route table in CLAUDE.md from `artisan route:list --json`.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The factual observations are accurate: backend/routes/api.php has 0 `prefix(` calls, 0 `->name(` calls, 0 `Route::controller()` groups, inline FQCN `\App\Http\Controllers\Api\CenterManagerController::class` repeated 18 times (auditor said 22 — minor overcount), `{student}` at lines 48-49/148-149 vs `{id}` elsewhere, `Student::findOrFail($id)` at StudentController.php:343,390,455,492,578,631,753 (37 findOrFail calls across Api controllers), and weekly-tests is `->only(['index','store','show','update'])` at line 164 while CLAUDE.md says index/store/destroy/show — documentation drift confirmed. However the impact section is overstated and partly wrong on causation: (1) there is no mobile app in this repo (only the static frontend-html client that is deployed alongside the API and reads API_BASE_URL from one config.js) — the "store-distributed mobile apps" premise is hypothetical, so versioning is a hygiene/roadmap item, not a present risk; (2) route names are not needed for URL generation — no `route()`/`url()` calls exist in app/, and InAppNotification stores frontend-relative links (`manager/requests.html`), not backend URLs; (3) "manual findOrFail is what produces the un-enveloped 404s" is a misattribution — implicit route-model binding throws the same ModelNotFoundException and would produce the identical default Laravel JSON 404; the real cause is the empty `->withExceptions()` block in bootstrap/app.php (no renderable for ModelNotFoundException/NotFoundHttpException into the `{success,message,data,errors}` envelope). The frontend api.js tolerates this (it reads `data.message` and falls back to a generic Arabic error), so nothing breaks today. Net: a real but low-severity code-quality/consistency finding (stale docs, inconsistent param naming, verbose route file, no envelope for 404s); no security or correctness defect, small team, single first-party client.

```text
backend/routes/api.php: 0 matches for `prefix(`, `->name(`, `Route::controller`; `\App\Http\Controllers\Api\CenterManagerController::class` appears 18 times (not 22); `{student}` only at :48-49 and :148-149; weekly-tests `->only(['index','store','show','update'])` at :164 (CLAUDE.md route table stale). backend/bootstrap/app.php:22-24 `->withExceptions(function (Exceptions $exceptions): void { // })` is empty — this, not findOrFail vs implicit binding, is why 404s are not enveloped. No `route(`/`url(` usage anywhere in backend/app; app/Notifications/InAppNotification.php stores frontend-relative `link` strings, so missing route names block nothing. No mobile client exists in the repo; the only consumer is frontend-html with a single API_BASE_URL in js/config.js and api.js:28 sends `Accept: application/json`, api.js:67 falls back gracefully on non-enveloped error bodies.
```

</details>

### No CI, no Pint config, no static analysis, stock README/.env.example; deployment is manual rsync via .cpanel.yml

<a id="no-quality-gates-pint-phpstan-ci"></a>

`no-quality-gates-pint-phpstan-ci` · 🟡 medium · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\composer.json:16-24`, `C:\Users\HP\SRS\MUTQIN\.cpanel.yml:1-8`, `C:\Users\HP\SRS\MUTQIN\backend\README.md:1`, `C:\Users\HP\SRS\MUTQIN\backend\.env.example:1-2`

**Evidence**

```text
`ls .github` -> 'No such file or directory' (repo root); `ls pint.json phpstan.neon phpstan.neon.dist rector.php` -> none; composer.json require-dev has laravel/pint ^1.13 but no phpstan/larastan and no `scripts.test|lint|analyse`. .cpanel.yml rsyncs `frontend-html/` and `backend/` to `public_html` with `--delete` on every push with no test step; DEPLOY_LOG.md:22-23 says migrations are applied as hand-pasted raw SQL ('الهجرات تُرفق بـSQL خام (لا artisan migrate على الخادم)'). backend/README.md is the untouched Laravel skeleton README ('About Laravel ...'); backend/.env.example still says `APP_NAME=Laravel`, `DB_CONNECTION=sqlite` although the app requires MySQL (CLAUDE.md:25 'sqlite won't work'). No Unit tests except the boilerplate ExampleTest and one SurahReference test (2 files). Git: 224 commits, 95 touching app/ since 2026-06-15, all landing on `master` with no branch protection or review gate visible.
```

**Why it matters**

The 178-test suite is the project's only safety net and nothing forces it to run before a deploy; style and type errors are caught only by reading; the mobile team cannot trust that `master` == what is on mutqin.ly; migration drift between hand-run SQL and the migrations table is inevitable.

**Recommendation**

Add GitHub Actions (or the host's CI) running `composer install`, `pint --test`, `phpstan analyse --level=5` (larastan, ratchet up over time), `php artisan test` against a MySQL service on every PR and on push to master; commit `pint.json` (laravel preset) and `phpstan.neon`; add `composer audit`. Replace README/.env.example with project-real content. Gate .cpanel.yml deploys on a green run (or move to a host where `artisan migrate --force` can run).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Every factual claim in the finding checks out against the repo. There is no `.github/` at the root or in `backend/`, no `pint.json`, `phpstan.neon(.dist)` or `rector.php`. `backend/composer.json` require-dev lists `laravel/pint ^1.13` but no larastan/phpstan and its `scripts` block contains only the stock Laravel post-install hooks (no test/lint/analyse). `.cpanel.yml` runs two `rsync -a --delete` steps straight from the pushed tree into `public_html` with no test or build step. `backend/DEPLOY_LOG.md:22-23` literally states migrations are shipped as raw SQL and `artisan migrate` is not run on the server, and that new .env keys are added by hand. `backend/README.md` is the untouched Laravel skeleton README; `backend/.env.example` has `APP_NAME=Laravel` and `DB_CONNECTION=sqlite` (line 23) while CLAUDE.md says sqlite cannot run the suite. `tests/Unit` holds exactly ExampleTest + SurahReferenceJuzGapTest; 178 test methods total across 43 files. Git: 224 commits, single `master` branch with two stray `claude/*` remote branches and no merge commits, no local git hooks.

Partial mitigations I found, none of which close the gap: (1) `backend/.env.production.example` exists with correct values (`APP_NAME=مُتقن`, `DB_CONNECTION=mysql`), so the misleading `.env.example` has a correct sibling — the auditor should have cited it; (2) `DEPLOYMENT.md:18` has a manual pre-deploy checklist item "tests green — run `php artisan test` before every deploy". That is a documented human process, not an enforced gate, so the core claim ("nothing forces the suite to run before a deploy") stands. Nothing in bootstrap/app.php, migrations or the frontend is relevant to a process/tooling finding.

Severity: for a small single-country team medium is defensible and I keep it. The risk is not a code defect but a delivery one: a push to master with a red suite or a broken security middleware ships to production automatically (`--delete` rsync), and hand-run SQL migrations mean the `migrations` table on the host drifts from the repo. Effort "S" and timing "now" are appropriate. Not refuted.

```text
Confirmed: C:\Users\HP\SRS\MUTQIN\.cpanel.yml:4-6 (two `rsync -a --delete` steps, no test step); backend\composer.json:16-24 (pint present, no phpstan; scripts block :36-51 has no test/lint entries); backend\DEPLOY_LOG.md:22-23 (raw SQL migrations, manual .env keys); backend\.env.example:1 `APP_NAME=Laravel`, :23 `DB_CONNECTION=sqlite`; backend\README.md:1 stock Laravel README; backend\tests\Unit contains only ExampleTest.php and SurahReferenceJuzGapTest.php; no .github/, pint.json, phpstan.neon*, rector.php anywhere; .git/hooks has only sample hooks. Partial mitigations the finding omits: backend\.env.production.example:8 `APP_NAME=مُتقن`, :42 `DB_CONNECTION=mysql`; DEPLOYMENT.md:18 manual checklist item "run `php artisan test` before every deploy" (documented, not enforced).
```

</details>

### Roles, teacher type, attendance status, test result, request status are bare strings (Arabic literals included) with zero enums

<a id="magic-strings-no-enums"></a>

`magic-strings-no-enums` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\app\Support\PrimaryTeacherRule.php:20-25`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\CenterManagerController.php:123`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\TeacherController.php:66`, `C:\Users\HP\SRS\MUTQIN\backend\app\Services\ReportService.php:55-56`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\AdminUserController.php:243`, `C:\Users\HP\SRS\MUTQIN\backend\app\Support\PhoneNumber.php:20-23`

**Evidence**

```text
`grep -rn '^enum ' app | wc -l` = 0; `ls app/Enums` -> no such directory. `grep -rn 'محفظ أساسي' app | wc -l` = 13 (incl. validation rules `'type' => 'required|in:محفظ أساسي,محفظ معاون'` at TeacherController:66,155 and CenterManagerController:123,403, and `orderByRaw("FIELD(type,'محفظ أساسي','محفظ معاون')")` CenterController:62,183). Role literals: 'teacher' 76 sites, 'parent' 41, 'center_manager' 14 (User.php:37 `in_array($user->role, ['teacher','center_manager','parent'])`). `'present'` 33 sites; `'ناجح'`/`'راسب'` 18 sites incl. SQL `SUM(result = 'ناجح')` (CenterController:120, ReportService:419). The Arabic-Indic digit `strtr` map is copy-pasted verbatim 7 times (AdminUserController:243, AttendanceImportController:153, CenterManagerController:228, MemorizationController:38, StudentController:292, TeacherController:28, PhoneNumber:20-23) instead of one `ArabicText::toWesternDigits()`.
```

**Why it matters**

A Flutter client must send the exact Arabic literal 'محفظ أساسي' (hamza-sensitive) as an API value; one normalization or typo yields a 422 with no machine-readable code. Renaming a status or adding 'excused' attendance requires touching 30+ sites and raw SQL strings; static analysis cannot help because everything is `string`.

**Recommendation**

Add backed enums `Role`, `TeacherType` (backing values stay the current Arabic strings for DB compatibility, expose `label()`), `AttendanceStatus`, `TestResult`, `RequestStatus`, `RequestType`, `MemorizationQuality`, `NationalityType`; cast them on the models; use `Rule::enum()` in validation; replace the 7 digit maps with `ArabicText::westernDigits()`. Expose enum value lists via a `GET /api/v1/meta/enums` endpoint so the mobile app does not hardcode them.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Evidence verified as factually correct: `grep -rn '^enum ' app` = 0 and no app/Enums dir; 'محفظ أساسي' appears 13 times in app/ (PrimaryTeacherRule.php:20,25; TeacherController.php:66,155; CenterManagerController.php:123,403 validation `in:محفظ أساسي,محفظ معاون`); ReportService.php:55-56 filters on 'ناجح'/'راسب'; WeeklyTestController.php:42,133 `in:ناجح,راسب`; CenterManagerController.php:563 `in:present,absent,late`. The Arabic-Indic digit strtr map is indeed duplicated verbatim in exactly 7 files (AdminUserController, AttendanceImportController, CenterManagerController, MemorizationController, StudentController, TeacherController, PhoneNumber) and ArabicText.php has no digit helper. So the finding is real, not exaggerated in its facts. However, from a correctness lens it does not describe a behavioral defect: every one of these value sets is constrained at the DB layer by MySQL enum columns (users.type, attendances.status, weekly_tests.result, weekly_test_questions.result, student_requests.type/status, memorizations.quality, nationality_type) and at the API boundary by `in:` validation rules returning 422 with field-keyed errors, so a typo or normalization mismatch cannot silently persist a bad value — it is rejected consistently. The frontend uses the same literals, so the contract is internally consistent, and 5 feature tests exercise the Arabic type literal. The "add 'excused'" scenario would already require a schema migration because of the DB enum columns regardless of PHP enums. This is a maintainability/DX issue with no runtime impact in the current Arabic-only single-client product; medium overstates it. Rated low (real duplication and hardcoding worth cleaning, especially the 7 digit maps, but nothing behaves incorrectly).

```text
Confirmed: backend/app/Support/PrimaryTeacherRule.php:20,25 ('محفظ أساسي' literal); backend/app/Http/Controllers/Api/TeacherController.php:66,155 and CenterManagerController.php:123,403 (`'type' => 'required|in:محفظ أساسي,محفظ معاون'`); CenterManagerController.php:563 (`in:present,absent,late`); WeeklyTestController.php:42,133 (`in:ناجح,راسب`); ReportService.php:55-56; digit strtr map duplicated in 7 files (AdminUserController.php:243, AttendanceImportController.php:153, CenterManagerController.php:228, MemorizationController.php:38, StudentController.php:292, TeacherController.php:28, PhoneNumber.php:20-23). Mitigations: DB enum columns in database/migrations/2026_05_11_100154_update_tables_for_mutqen_v2.php:13,23 (users.type, weekly_tests.result), 2024_01_01_000030_create_attendances_table.php:16 (status), 2026_06_28_100000_create_student_requests_table.php:25-26 (type/status), 2026_06_28_120000_add_nationality_to_students_and_users.php:19,26 — invalid values cannot be stored; validation `in:` rules reject them with 422 before reaching the DB.
```

</details>

### No jobs/queues/events: xlsx import and mPDF rendering run synchronously in the request; production is QUEUE_CONNECTION=sync on shared cPanel

<a id="no-async-boundary-heavy-work-in-request"></a>

`no-async-boundary-heavy-work-in-request` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort L (1–2 weeks)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\AttendanceImportController.php:164`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\AttendanceImportController.php:341-378`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\ReportPdfController.php:37-60`, `C:\Users\HP\SRS\MUTQIN\backend\.env.production.example:62`, `C:\Users\HP\SRS\MUTQIN\backend\DEPLOY_LOG.md:6-9`

**Evidence**

```text
`grep -rn 'ShouldQueue\|dispatch(' app | wc -l` = 0; no app/Jobs, app/Events, app/Listeners. AttendanceImportController.php:164 runs `Student::where('display_code', 'S'.$deviceNum)->first()` once per spreadsheet row inside the transaction, then :355-372 loops `foreach ($scopeQuery->get() as $st) { Attendance::firstOrCreate(...) }` per date x per in-scope student (a 300-student center x 22 working days = 6,600 round-trips in one request holding row locks). mPDF is instantiated per request (ReportPdfController.php:50-60) after `view(...)->render()`. .env.production.example:62 `QUEUE_CONNECTION=sync`; DEPLOY_LOG.md:6-9: SSH 'معطَّل من المستضيف', 'بلا SSH / CLI / artisan / composer / git' — so no worker or scheduler can run on the current host anyway.
```

**Why it matters**

At dozens of centers a monthly fingerprint upload or an all-centers PDF ties up the single PHP worker for tens of seconds, times out mobile clients, and holds attendance row locks; there is no place to hang the n8n attendance-digest automation, SMS OTP sending, or push notifications for the mobile app.

**Recommendation**

Refactor import into `ImportAttendanceSheet` job (parse to DTO rows first, then bulk `upsert()` in chunks; compute absences with one `INSERT ... SELECT` per date), return a `job_id` and expose `GET /imports/{id}` for progress; queue PDF generation and store to disk with a signed download URL. This requires leaving shared cPanel for a VPS/container with `queue:work` + `schedule:run` (or Laravel Cloud/Forge) — which is also the prerequisite for push notifications and SMS.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Evidence verified: AttendanceImportController.php:97 wraps all row processing plus absence computation in one DB::transaction; :164 does a per-row Student lookup; :300 Attendance::updateOrCreate per file row; :341-378 loops dates x scopeQuery->get() with Attendance::firstOrCreate per student (N+1). ReportPdfController.php:37-60 renders Blade then instantiates mPDF synchronously. grep for ShouldQueue/dispatch( in app/ = 0; no app/Jobs, Events, Listeners. .env.production.example:62 is QUEUE_CONNECTION=sync and DEPLOY_LOG.md:6-9 confirms no SSH/CLI on the host. So the finding is factually correct and cannot be refuted on correctness. However the impact is overstated: (1) 'ties up the single PHP worker' only describes `php artisan serve` in dev; production cPanel serves via Apache/LiteSpeed with multiple PHP workers, so one import does not block other users' requests. (2) Upload is capped at 5 MB (line 21 `mimes:xlsx|max:5120`), and a monthly 300-student file (~6.6k rows) yields on the order of 15-20k simple indexed queries — seconds, not obviously 'tens of seconds', though it could approach a shared host's 30s max_execution_time at the extreme. (3) Row locks on the center's attendance rows exist only for the transaction duration; the single transaction is a deliberate, documented atomicity choice (comment lines 95-96), not an oversight. (4) 'no place to hang n8n/SMS/push' is roadmap speculation, not a current defect; the CLAUDE.md already states OTP has a single integration point and no SMS gateway exists by design. (5) The recommendation (leave shared hosting, add queue workers) is disproportionate for a small-team, single-country product; the real fix is cheaper and requires no queues: batch student lookup by display_code once, chunked upsert(), and one INSERT...SELECT per date for absences. Net: real but low-severity performance/scalability observation, not a medium architectural defect.

```text
backend/app/Http/Controllers/Api/AttendanceImportController.php:21 — 'attendance_file' => 'required|file|mimes:xlsx|max:5120' (input bounded to 5 MB). :95-97 — comment states the single transaction is intentional ('فشل جزئي في المنتصف لا يترك حضور يومٍ نصف مكتوب'). :164 per-row Student::where('display_code',...)->first(); :300 Attendance::updateOrCreate per row; :355-372 foreach date -> $scopeQuery->get() -> Attendance::firstOrCreate per student (N+1 confirmed). ReportPdfController.php:50-60 synchronous new \Mpdf\Mpdf + WriteHTML (confirmed). No queue usage: `grep -rn 'ShouldQueue\|dispatch(' app` = 0 (confirmed). 'Single PHP worker' claim applies only to `php artisan serve` (CLAUDE.md Gotchas), not the cPanel production stack described in DEPLOY_LOG.md:3-9.
```

</details>

### Half of ReportService (and ReportController::weekly) is N+1 and loads whole months of rows into PHP memory

<a id="report-service-n-plus-one"></a>

`report-service-n-plus-one` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\app\Services\ReportService.php:79-115`, `C:\Users\HP\SRS\MUTQIN\backend\app\Services\ReportService.php:133-170`, `C:\Users\HP\SRS\MUTQIN\backend\app\Services\ReportService.php:176-204`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\ReportController.php:97-111`

**Evidence**

```text
ReportService.php:115 `$rows = $students->map(fn ($s) => $this->studentSummaryRow($s, $month, $year))` where studentSummaryRow (:79-105) issues 2 queries per student and counts in PHP (`$att->where('status','present')->count()`). teachersPerformance :182-199: 3 queries per teacher inside `->map`. centerData :136-146 does `Attendance::whereIn(...)->get()` for the whole month then counts in memory; allCentersData :168 calls it per center. ReportController.php:97-111 `weekly()` runs 2 queries per student. Contrast the same file's `aggregateAllTime` :415-424 and `centerManagement` :318-323 which use `selectRaw('COUNT(*) ..., SUM(status = "present")') ->groupBy` — the correct pattern exists but is applied inconsistently.
```

**Why it matters**

Admin overview/teachers PDFs scale as O(centers x students); at 30 centers x 200 students the monthly report is thousands of queries and tens of MB of hydrated models, on a single-worker host.

**Recommendation**

Rewrite studentSummaryRow/teacherGroupData/teachersPerformance/centerData/weekly on top of `aggregateAllTime`-style grouped SQL (one query per metric family keyed by student_id/teacher_id/center_id), return DTOs not Eloquent models, and add a query-count assertion test (`DB::enableQueryLog` or `expectsDatabaseQueryCount`) per report method. Move ReportService under a Reporting module with read-model classes.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted code is accurate and behaves as described. ReportService::studentSummaryRow (:79-92) issues 2 queries per student and counts in PHP; teacherGroupData (:115) maps it over the teacher's students; teachersPerformance (:182-189) issues 3 queries per teacher; centerData (:139-144) loads the whole month's attendance and test rows into memory; allCentersData (:168) calls centerData per active center; ReportController::weekly (:97-109) issues 2 queries per student. The grouped-SQL pattern (aggregateAllTime :415-424, centerManagement :318-323) really does coexist, and the only query-count guard in tests covers atRiskStudents (tests/Feature/ManagerReportsTest.php:96-105), not the cited methods. So the finding is not refutable on correctness.

However the impact statement is exaggerated in two ways: (1) the admin overview (allCentersData) is O(centers) queries (4 per center), not O(centers x students) — the students-scale cost there is hydrated rows in memory, not query count; (2) the only path that reaches "thousands of queries" is weekly() run by an admin over all students, and the frontend never calls /reports/weekly at all (no reference in frontend-html), so it is API-reachable but not a live UI path. The remaining call sites are bounded by realistic product scale (one teacher's ~20-40 students for the teacher PDF; a few dozen teachers for the performance PDF), and mPDF rendering dominates those requests anyway. For an Arabic single-country small-center product this is a real but low-impact code-quality inconsistency rather than a medium operational risk.

```text
backend/app/Services/ReportService.php:81-89 (2 queries per student, PHP-side counts), :115 (per-student map), :139-146 (month rows loaded then counted in memory), :168 (centerData called per center => 4 queries/center, O(centers) not O(centers x students)), :182-189 (3 queries per teacher); backend/app/Http/Controllers/Api/ReportController.php:97-109 (2 queries per student; route /reports/weekly at routes/api.php:166 has no caller in frontend-html — grep for "reports/weekly" returns nothing); partial mitigation: grouped SQL already used at ReportService.php:318-323 and :415-424, and a query-count regression test exists only for atRiskStudents at tests/Feature/ManagerReportsTest.php:96-105.
```

</details>

### Alternate update routes bypass the audited status/identity paths (center is_active via PUT, teacher center_id & email via PUT)

<a id="parallel-write-paths-bypass-audit"></a>

`parallel-write-paths-bypass-audit` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\CenterController.php:42`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\CenterController.php:233`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\CenterController.php:249-280`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\TeacherController.php:150-167`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\CenterManagerController.php:399-427`

**Evidence**

```text
CenterController.php:233 `$center->update($request->only(['name', 'city', 'address', 'phone', 'is_active']))` and :42 on store — `PUT /centers/{id}` with `is_active=false` deactivates a center WITHOUT the token revocation and member logout that `toggleStatus` performs at :258-271 (`$m->tokens()->delete()`); the frontend never sends it, so nothing tests it. TeacherController.php:154,165 lets admin move a teacher to another `center_id` via plain update with no primary-rule check on the *destination's* students, no `former_teacher_name` handling for the students left behind, and no notification; CenterManagerController.php:401,416 lets a manager overwrite `email` freely (`'email' => 'required|email|unique:...'`) although create paths insist the login email is system-generated `{latin}_{code}@mutqin.ly` (LoginEmail.php:8-16, TeacherController.php:55). `Hash::make($request->password)` is applied at 10 sites even though `User::casts()` declares `'password' => 'hashed'` (User.php:52) — harmless (hashed cast skips already-hashed values) but shows two competing conventions.
```

**Why it matters**

Business invariants documented as 'approved decisions' (deactivation revokes sessions; emails are system-generated) hold only on the path the current web UI happens to use; a mobile or third-party client hitting the generic PUT silently violates them.

**Recommendation**

Strip `is_active` from CenterController store/update `only([...])` and make status changes exclusively via `/status` (add a test that PUT with is_active is ignored); make `center_id` change on TeacherController::update go through a `TransferTeacher` action or reject it; drop `email` from manager updateTeacher (or validate it matches the LoginEmail scheme). Rely on the `hashed` cast and remove redundant `Hash::make` so there is one convention.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Core claim verified: CenterController.php:233 (update) and :42 (store) pass `is_active` through `$request->only([...])`, Center::$fillable includes `is_active` (app/Models/Center.php:9-16), and no middleware re-checks center/user activity per request (app/Http/Middleware/* contain zero `is_active` references). So an admin token calling `PUT /api/centers/{id}` with `is_active=false` flips the center without the `tokens()->delete()` loop in toggleStatus (:258-271). Partial mitigation: AuthController.php:52 blocks new logins for members of an inactive center, so the gap is limited to already-issued tokens surviving up to the Sanctum expiry (config/sanctum.php:55 = 10080 min / 7 days). No feature test covers PUT /centers with is_active (CenterStatusTest only exercises /status), and the admin centers.html form does not send is_active — so the finding's 'held only on the UI path' framing is correct for this part. However, several sub-claims are wrong or overstated: (1) TeacherController::update DOES apply the one-primary rule against the destination center (:159 `assertSinglePrimary($request->center_id, $request->type, $teacher->id)` → PrimaryTeacherRule::assert), so 'no primary-rule check on the destination' is false; the real residual issue is only that students keep `teacher_id` pointing at a teacher who now belongs to another center (student.center_id ≠ teacher.center_id), and this move is deliberately exposed in the admin UI (frontend-html/admin/teachers.html:44), not a hidden non-UI path. (2) Email editing is exposed in both the admin (admin/teachers.html:41) and manager (manager/teachers.html:27) edit forms, and LoginEmail.php's own doc says existing accounts are not migrated to the scheme — so free-form email on update is a knowing design choice, not a bypass reachable only by a third-party client. (3) The Hash::make redundancy is harmless, as the auditor concedes. Net: the finding is real (unaudited is_active write path, untested; teacher move leaves cross-center student links) but exploitable only by an already-admin token using a non-UI client, with the effect being a 7-day token tail on a center the admin chose to deactivate through an odd route. That is a hygiene/integrity gap, not a medium-severity defect; downgrade to low.

```text
backend/app/Http/Controllers/Api/CenterController.php:42 and :233 — `$request->only(['name','city','address','phone','is_active'])` (confirmed; is_active is in Center::$fillable, app/Models/Center.php:9-16). Revocation exists only in toggleStatus :258-271. Mitigation: backend/app/Http/Controllers/Api/AuthController.php:52 denies login when the user's center is inactive, so exposure is limited to existing tokens until expiry (config/sanctum.php:55, 10080 min). No middleware checks is_active per request (grep of app/Http/Middleware/* = 0 hits). No test hits PUT /api/centers/{id} with is_active (tests/Feature/CenterStatusTest.php only uses /status). CORRECTION: TeacherController.php:159 `$this->assertSinglePrimary($request->center_id, $request->type, $teacher->id)` DOES enforce the primary rule on the destination center — the auditor's 'no primary-rule check' claim is wrong; only the orphaned student→teacher cross-center link remains, and the center change is a UI-exposed feature (frontend-html/admin/teachers.html:44). CORRECTION: email is editable in the shipped UI (frontend-html/admin/teachers.html:41, frontend-html/manager/teachers.html:27) and LoginEmail.php header states legacy accounts are not migrated — so update-time free email is a design choice rather than a hidden bypass.
```

</details>

### Dead scaffolding and unused models: Vite/Tailwind assets, test-xlsx scripts in backend root, Revision/TajweedEvaluation, boilerplate tests, misplaced docblocks

<a id="dead-code-and-stray-artifacts"></a>

`dead-code-and-stray-artifacts` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `C:\Users\HP\SRS\MUTQIN\backend\check_excel.php:1`, `C:\Users\HP\SRS\MUTQIN\backend\generate_test_excel.php:1`, `C:\Users\HP\SRS\MUTQIN\backend\attendance_test.xlsx`, `C:\Users\HP\SRS\MUTQIN\backend\package.json:1`, `C:\Users\HP\SRS\MUTQIN\backend\vite.config.js:1`, `C:\Users\HP\SRS\MUTQIN\backend\resources\js\app.js:1`, `C:\Users\HP\SRS\MUTQIN\backend\app\Models\Revision.php:1`, `C:\Users\HP\SRS\MUTQIN\backend\app\Models\TajweedEvaluation.php:1`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\StudentController.php:274-283`, `C:\Users\HP\SRS\MUTQIN\backend\app\Http\Controllers\Api\CenterManagerController.php:197-206`

**Evidence**

```text
`git log -1 -- backend/check_excel.php generate_test_excel.php attendance_test.xlsx package.json vite.config.js resources/js/app.js resources/css/app.css` -> all last touched in `be47c33 2026-06-25 Initial commit` and tracked in git. package.json requires vite ^8 + tailwindcss ^4 + laravel-vite-plugin; vite.config.js inputs `resources/css/app.css` (a 390-byte Tailwind stub) and `resources/js/app.js` (content: `//`) — but the API has no Blade app (routes/web.php:5-10 returns JSON) and the real client is `frontend-html/` with no build step. `grep -rn 'Revision\b\|TajweedEvaluation' app routes tests database/seeders` -> only the model files and the two `hasMany` relations in Student.php:87,93: tables + models exist with no controller, route, seeder, or test (CLAUDE.md lists them as roadmap). tests/Feature/ExampleTest.php and tests/Unit/ExampleTest.php are untouched skeletons. Docblocks drifted from code: StudentController.php:274-283 has two stacked docblocks (the 'بحث أولياء الأمور (admin فقط)' one belongs to searchParents at :311 but sits above managerSearchParents); CenterManagerController.php:197 '/** تفاصيل محفّظ ... */' and :279-282 '/** تعديل بيانات محفّظ ... */' sit above parents() and teacherPerformance() respectively, not the methods they describe. Note: AdminUserController is NOT dead — wired at routes/api.php:112 and covered by tests/Feature/AdminUsersListTest.php; CLAUDE.md simply omits it.
```

**Why it matters**

Newcomers (and the mobile team reading the backend) waste time on a Vite toolchain and xlsx scripts that do nothing; `.cpanel.yml` rsyncs these files to production `public_html/backend/`; unused models with cascading FKs add migration surface with no consumer.

**Recommendation**

Delete check_excel.php, generate_test_excel.php, attendance_test.xlsx (move any fixture into tests/Fixtures), package.json, vite.config.js, .npmrc, resources/css|js, both ExampleTests; either ship Revision/Tajweed as a module or drop the models/relations and keep the migrations documented as reserved; fix the misplaced docblocks; regenerate CLAUDE.md sections from code (route list, notification types, test count, LoginEmail scheme `{latin}_{code}@mutqin.ly` vs documented `{latin}.centeradmin@`, parent `P{n}` codes, login-by-display-code).

## Measured facts

| Metric | Value |
|---|---|
| App PHP files / LOC | 49 files / 7,072 LOC (app/) |
| Controller share of app code | 19 API controllers, 4,934 LOC = 69.8% of app/ |
| Layer LOC | Services 562 (1 class) · Support 645 (8 static classes) · Models 739 (14) · Middleware 105 (4) · Notifications 63 (1) · Providers 24 (empty) |
| Laravel structural constructs present | FormRequests 0 · API Resources 0 · Policies/Gates 0 · Enums 0 · Jobs/Events/Listeners 0 · custom Exceptions 0 · withExceptions handlers 0 · Actions/DTOs 0 |
| Inline validation / JSON building | 36 `$request->validate` calls · 153 `response()->json` sites · 55 hand-built `'success' => false` error responses · 15 `DB::transaction` uses |
| Duplication hot-spots | ownership 403 check x11 · manager center-scope check x9 · Arabic-digit strtr map x7 · 'محفظ أساسي' literal x13 · 'present' literal x33 · 'ناجح/راسب' x18 · role literals 'teacher' x76 / 'parent' x41 / 'center_manager' x14 · status filter block x6 · `?all=1` unpaginated switch x4 · `Hash::make` x10 despite `hashed` cast |
| Method size / complexity (approx.) | 219 methods; 34 > 50 LOC; 5 > 100 LOC; max AttendanceImportController::import 391 LOC CC~62; ParentResolver::resolve CC~30; StudentRequestController::approve 129 LOC CC~19; StudentController::store 122 LOC CC~14 |
| Routes | 89 explicit + 5 apiResource (~107 endpoints), 0 named, 0 versioned, 4 middleware groups, 22 inline FQCN references |
| Tests | 38 Feature files + 2 Unit (+2 boilerplate ExampleTest), 178 test methods, 4,641 LOC, RefreshDatabase in 38 files, MySQL `mutqin_test`; CLAUDE.md says 20 |
| Schema | 35 migrations · 37 FK constraints · 24 index/unique declarations · 2 unused models (Revision, TajweedEvaluation) |
| Dependencies (composer.lock) | laravel/framework v11.51.0 (11.x security support ended 2026-03-12) · sanctum 4.3.2 · mpdf 8.3.1 · phpspreadsheet 5.8.0 · phpunit 11.5.55 · pint 1.29.1 (no pint.json) · no phpstan/larastan · php ^8.2 (platform override 8.2.12; prod host PHP 8.3) |
| Quality gates | CI: none (.github absent) · lint config: none · static analysis: none · deploy: .cpanel.yml rsync --delete with no test step; migrations hand-applied as SQL · `php -l` on 100% of app/routes/database/tests: 0 failures |
| Dead/stray files tracked in git | check_excel.php, generate_test_excel.php, attendance_test.xlsx, package.json, vite.config.js, .npmrc, resources/js/app.js ('//'), resources/css/app.css, stock README.md, stock .env.example (APP_NAME=Laravel, sqlite) |
| Git activity | 224 commits total, 95 touching backend/app since 2026-06-15, single `master` branch |

## Auditor notes

Environment: the task said PHP lives only at C:\xampp\php\php.exe, but that path does not exist on this machine (C:\xampp is absent) and backend/vendor is not installed; syntax checks were run read-only with Herd's PHP 8.2.29 at C:\Users\HP\.config\herd\bin\php82\php.exe (`php -l` only — no artisan, no DB). Repo root: C:\Users\HP\SRS\MUTQIN.

Additional observations not promoted to findings (to avoid silent drops): (a) DashboardController::index dispatches on `isAdmin()` else-branch, so a parent or manager calling GET /dashboard (auth-only) receives the teacher payload computed over `$user->students()` (DashboardController.php:53-55) — role dispatch by if/else instead of per-role handlers. (b) AuthController.php:218 logs the plaintext OTP (`Log::info("OTP ... {$otp}")`) and :216-220 has no `SmsGateway` contract — the SMS integration point is a TODO inside a controller; DashboardController::demoAccounts leaks all user names/emails whenever APP_DEBUG=true (:105) — both belong to the security dimension. (c) ArabicText::sqlNormalize (ArabicText.php:27-38) wraps columns in 9 nested REPLACE() calls, so every `q=` search is a non-sargable full scan — performance dimension. (d) Support classes are entirely static (DisplayCode, ParentResolver, PrimaryTeacherRule, LoginEmail, PhoneNumber) with DB access inside, so they cannot be mocked; SurahReference (Support) depends on the Athman model whose `normalize()` merely delegates back to ArabicText (Athman.php:32-35) — an inverted layering loop; ReportService is fetched via `app(\\App\\Services\\ReportService::class)` service-locator at 5 sites in CenterManagerController (:610,:640,:655,:669,:680) but constructor-injected in ReportPdfController.php:18. (e) WeeklyTest schema carries overlapping `result` + `passed` + `test_type` columns and `date`->`exam_date` renames; students.birth_date is vestigial (CLAUDE.md acknowledges) — data-model cruft. (f) Strict `!==` comparisons between DB ints and user ids are correct under mysqlnd native types but mixed with `(int)` casts elsewhere; if `PDO::ATTR_EMULATE_PREPARES`/stringify ever changes they become silent 403s. (g) Message.sender_role is a free string duplicating what sender_id already implies. (h) CLAUDE.md drift confirmed: test count (20 vs 38 files), weekly-tests verbs (destroy vs update), LoginEmail scheme (`{latin}.centeradmin@` vs `{latin}_{code}@mutqin.ly`, LoginEmail.php:8-11), parent display codes exist (`P{n}`, DisplayCode.php:59) though docs say parents have none, login-by-display-code undocumented, `message_received` notification type undocumented, ~15 endpoints undocumented, AdminUserController/MessageController/manager parents/teacher performance/center stats/students details+day absent from the controller list. Proposed module map for the refactor: Identity (User, Role/Token abilities, LoginEmail, DisplayCode, OTP, password logs, AdminUsers, Profile), Centers (Center, CenterManager management, PrimaryTeacherRule, status toggles), Students (Student, ParentResolver, guardians, StudentRequest transfers, status/teacher changes), Memorization (Memorization, SurahReference, Athman, progress read-model), Attendance (manual + fingerprint import job + manager correction), Assessment (WeeklyTest + questions; future Revision/Tajweed), Messaging (Message threads, InAppNotification, future push/SMS listeners), Reporting (ReportService read-models, PDF jobs, dashboards).
