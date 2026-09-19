# Database Schema & Data Integrity

[← Enterprise Audit](../enterprise-audit.md)

**Score 66 / 100** — Needs real work · maturity **L3** · weight 7% · auditor scored 64, judge calibrated to 66

The schema is coherent and disciplined for its size: 35 immutable migrations (0 edited after commit across 224 commits), every migration has a down(), 32 FK declarations, the right unique keys (attendances(student_id,date), students.national_id, users.id_number, display codes, email), an atomic code_sequences design, transactional two-step creates, utf8mb4_unicode_ci with no hard-coded collations, and a deliberate D1 fix (teacher_id -> nullOnDelete) to stop history loss. Seeders are unusually good (env-guarded, deterministic, batch-inserted, invariants asserted post-seed, synthetic PII, production seeder refuses silent passwords). What keeps it out of the 75+ band against an 'enterprise, dozens of centers, public mobile app' bar: (1) essentially no non-FK indexes on the hot columns (attendances.date, memorizations date/surah_name, weekly_tests.exam_date, users.role/phone, students (center_id,is_active)) while the admin dashboard full-scans attendances three times per load; (2) every business invariant (one primary teacher per center, one active manager per center, parent phone uniqueness, teacher/student same-center, FK-target role) lives only in PHP; (3) the tenant key center_id is absent from most record tables and used with two different semantics on attendances; (4) cascade deletes on history/audit tables contradict the stated never-delete policy; (5) no generic audit log (before-values are explicitly not kept), and the backup/restore story is one manual mysqldump line; (6) a concrete latent bug (student_requests.national_id VARCHAR(12) vs students.national_id VARCHAR(32)); (7) residual cruft (dead birth_date + static age, untrusted juz, Arabic display strings as enum codes). CMMI 3 (defined): conventions are consistent and documented in migration headers and guarded by 38 feature-test files on real MySQL, but nothing is measured (no slow-query/volume testing, no index review) and several invariants are not defended at the storage layer.

> **Calibration:** One of the three high findings (tenant-key inconsistency) was outright refuted and the other two corrected to medium, removing one of the seven stated reasons for staying under 75; a modest lift keeps parity with domain-rules (also level 3) while crediting the refutation.

## What is already strong

- Migration immutability and reversibility: `git log --diff-filter=M -- backend/database/migrations | wc -l` = 0 over 224 commits; all 35 files define down() (`grep -L "function down"` returns nothing). Migration headers carry Arabic ADR-style rationale (e.g. 2026_07_02_100000 explains the D1 history-loss bug it fixes; 2026_07_18_120000 records the approved 'light audit' decision).
- Referential integrity is the default: 32 `constrained()/foreign()` declarations; every relationship column is an FK (students.center_id/teacher_id/parent_id/status_changed_by, attendances.student_id/teacher_id/center_id/corrected_by, messages.student_id/sender_id, student_requests x6). D1 migration 2026_07_02_100000 converted attendances/memorizations/weekly_tests.teacher_id from cascadeOnDelete to nullOnDelete so deleting a teacher no longer wipes student history.
- Correct unique keys where the domain needs them: attendances `unique(['student_id','date'])` (2024_01_01_000030:22) with a dedicated AttendanceDuplicationTest; students.national_id unique nullable (2026_06_21_130000:17); users.id_number unique (2026_06_28_120000:29); display_code unique on students/users/centers; athman unique(hizb,thumn_in_hizb) (2026_06_21_120000:25).
- Atomic system-wide display codes: `code_sequences` table + `UPDATE code_sequences SET value = LAST_INSERT_ID(value + 1)` (app/Support/DisplayCode.php:56) reserved inside `creating` hooks; backfill migrations 2026_07_16_110000 and 2026_07_24_100000 are idempotent, ordered by id, and re-sync the counter with GREATEST(value, max). Covered by DisplayCodeTest and StudentCodePreviewTest.
- Transactional multi-step writes: teacher/manager/student creation wrap 'insert temp email -> reserve code -> assign final email' in DB::transaction (TeacherController.php:82, ManagerManagementController.php:82, StudentController.php:235, CenterManagerController.php:135 with lockForUpdate on the primary-teacher check).
- Charset/collation is right for Arabic and portable across MySQL 8 / MariaDB 10.4: config/database.php:56-57 `utf8mb4` / `utf8mb4_unicode_ci`; no `utf8mb4_0900_*` or hard-coded collation anywhere in app/ or database/ (grep returns nothing).
- messages table was designed from its query plan: composite indexes `(student_id,id)` for thread fetch and `(student_id,sender_role,read_at)` for unread counts (2026_08_22_100000:25-26) match MessageController.php:82-121 exactly.
- Seeder discipline is enterprise-grade: ProductionSeeder throws unless ADMIN_INITIAL_PASSWORD (>=8 chars) is set (ProductionSeeder.php:24-27); LibyanDataSeeder refuses to run outside `local` (LibyanDataSeeder.php:108-112), runs in one transaction, uses deterministic (non-random) identities, batch inserts of 500, fully synthetic names/phones/national ids (data/LibyanNames.php header: 'no real names or numbers'), and asserts the one-primary-per-center invariant after seeding (assertOnePrimaryPerCenter). AthmanSeeder is idempotent via updateOrCreate.
- Tests run against a real MySQL database (`mutqin_test`, phpunit.xml:26-27) with RefreshDatabase in all 38 feature files (178 test methods), so FK/unique/enum behaviour is exercised as in production rather than mocked on SQLite.
- The v2 migration actually removed the weekly_tests cruft: 2026_05_11_100154:21-23 drops `test_type` and `passed` and adds `result`; the final table is clean (student_id, teacher_id, result, exam_date, notes). CLAUDE.md's claim of overlapping result/passed/test_type columns is doc drift, not a schema problem.

## Level-5 target state

Every tenant-owned record table carries a NOT NULL, indexed center_id with one documented semantic, and every business invariant (one primary teacher, one active manager, unique parent phone, teacher-in-student's-center, FK-target role) is enforced by the database via generated-column unique indexes, CHECK constraints or composite FKs — the PHP checks exist only to produce friendly 422 messages. Hot paths (dashboard, attendance review, progress, monthly reports) read from indexed date/status columns or materialized rollups, with EXPLAIN-based regression tests and slow-query monitoring feeding an index review each release. History and audit tables are RESTRICT-protected against hard deletes, a generic before/after audit_logs table records every mutation of student/user/attendance/test data, and automated encrypted backups with PITR and a rehearsed restore back the whole thing. Enumerations are ASCII codes with server-provided labels, derived fields (age, juz) are generated or normalized away, and reference data (surahs, athman) is relational with FKs.

## What the Flutter team must know

The mobile team consumes this schema through JSON, so the following are load-bearing: (1) Enum values for `users.type` ('محفظ أساسي'/'محفظ معاون') and `weekly_tests.result`/`weekly_test_questions.result` ('ناجح'/'راسب') are Arabic literals that double as codes — treat them as opaque strings compared byte-for-byte, never as display text, and expect a future migration to ASCII codes (`primary|assistant`, `pass|fail`); other enums (attendances.status present/absent/late, memorizations.quality, nationality_type libyan/foreigner, student_requests.type/status) are already ASCII. (2) Primary keys are sequential bigints; display codes (S/T/CA/P/C + n) are the stable human identifiers and are login-capable for users (AuthController accepts display_code). (3) `students.age` is a static integer that goes stale — do not compute birthdays from it; `birth_date` is not populated by the UI. (4) `memorizations.juz` must never be trusted client-side; derive progress from the `/memorizations/students-progress` endpoint (SurahReference-based). (5) Dates are `Y-m-d` strings; model timestamps serialize as ISO-8601 with the Africa/Tripoli offset (+02:00); `attendances.time` is a free string like '07:35'. (6) Notification payloads store a `link` that is a web page path (e.g. `manager/requests.html`, `parent/child.html?id=N`) plus `type` and `ref_id` — route on `type`+`ref_id`, not `link`. (7) Attendance uniqueness is (student_id, date): offline-queued duplicate submissions will get a unique-violation/422, so implement idempotent upsert semantics client-side. (8) Every login creates a new token and tokens expire after 7 days with no server-side pruning; deactivation or password change revokes all tokens, so handle 401 as 'token gone' everywhere. (9) Lists are Laravel paginator envelopes (page size 5-20 depending on endpoint); some endpoints accept `?all=1`. (10) Expect the two 'now' findings (indexes, request national_id width) to land before mobile load; the tenant-scoping semantics on attendance after transfers may change in the 'next' window, so do not cache center membership of historical rows.

## Findings — 14 live, 1 refuted

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [No non-FK indexes on the columns every dashboard, review screen and report filters/sorts on](#missing-hot-path-indexes) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Every business invariant is enforced only in PHP; the database accepts violating rows](#invariants-app-only) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | M |
| [student_requests.national_id is VARCHAR(12) while students.national_id was widened to VARCHAR(32) — foreign-student transfers can 500](#request-national-id-width-mismatch) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Cascade deletes remain on history and audit tables even though the product policy is 'never hard-delete'](#cascade-deletes-contradict-no-delete-policy) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Audit is ad-hoc who/when columns without before-values; no generic audit trail for minors' data](#no-audit-log-table) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Backup/restore is a single manual mysqldump suggestion; no retention, restore drill, or PITR](#backup-restore-story) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Progress and reports are computed from raw rows in PHP; several endpoints load unbounded result sets](#derived-state-and-volume-assumptions) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Moving a teacher to another center leaves their students assigned to a teacher outside the students' center](#teacher-center-move-orphans-students) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [personal_access_tokens grows unbounded: every login inserts a token and nothing prunes expired ones](#token-table-unbounded-growth) | ⚪ low | ℹ️ informational | NEXT | S |
| [Arabic display strings are used as stored enum codes and compared in SQL/PHP](#arabic-strings-as-enum-codes) | ⚪ low | ℹ️ informational | LATER | M |
| [Historical cruft: dead birth_date with a static age, untrusted memorizations.juz/hizb/eighth, VARCHAR time and phones](#schema-cruft-birthdate-age-juz) | ⚪ low | ℹ️ informational | LATER | M |
| [MySQL-specific SQL in migrations and code prevents SQLite-backed fast tests and pins the engine](#mysql-only-constructs) | ⚪ low | ℹ️ informational | LATER | S |
| [ExtraDataSeeder has no environment guard or transaction and CLAUDE.md still advertises it with stale credentials](#seeder-guardrails-and-doc-drift) | ⚪ low | ℹ️ informational | LATER | S |
| [Surah reference lives in a PHP constant and athman in an xlsx; memorizations.surah_name is free text with no FK](#reference-data-not-normalized) | ⚪ low | ℹ️ informational | LATER | M |

### No non-FK indexes on the columns every dashboard, review screen and report filters/sorts on

<a id="missing-hot-path-indexes"></a>

`missing-hot-path-indexes` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/database/migrations/2024_01_01_000030_create_attendances_table.php:13-22`, `backend/database/migrations/2024_01_01_000040_create_memorizations_table.php:13-29`, `backend/database/migrations/2024_01_01_000070_create_weekly_tests_table.php:11-27`, `backend/database/migrations/0001_01_01_000000_create_users_table.php:14-24`, `backend/app/Http/Controllers/Api/DashboardController.php:25-30`, `backend/app/Http/Controllers/Api/CenterManagerController.php:507`, `backend/app/Http/Controllers/Api/AuthController.php:132`, `backend/app/Services/ReportService.php:39-53`

**Evidence**

```text
Across all 35 migrations the only explicit `->index(` calls (14) are on athman, otp_resets, password_change_logs, student_requests(status, national_id), messages and the framework tables; attendances has only the unique (student_id,date) plus FK indexes; memorizations/weekly_tests have FK indexes only; users has no index on role, phone or is_active. Meanwhile DashboardController.php:25-29 runs `Attendance::where('date', today())->where('status','present')->count()` three times (full scan on a table with no `date` index); CenterManagerController.php:507 sorts the manager review with `->latest('date')->latest('id')`; AuthController.php:132 logs in by `->where('phone', ...)` on users with no phone index; ReportService.php:39 `->whereMonth('date', $month)->whereYear('date', $year)` is non-sargable even if indexed. Column filter counts: `where('role'` 53 hits, `where('center_id'` 46, `where('is_active'` 46, `'date'` 21, `'surah_name'` 8.
```

**Why it matters**

At 30 centers x ~100 students x 6 days/week attendances grows ~900k rows/year; every admin dashboard open becomes 3 table scans and every manager review page a filesort. A mobile app polling dashboards multiplies this. Mobile login by phone scans users. This is the single cheapest, highest-leverage fix in the dimension.

**Recommendation**

One additive migration (safe now, online on InnoDB): attendances `index(['date','status'])`, `index(['center_id','date'])`, `index(['teacher_id','date'])`; memorizations `index(['student_id','date'])`, `index(['date'])`, `index(['surah_name'])`; weekly_tests `index(['student_id','exam_date'])`, `index(['exam_date'])`; users `index(['role','center_id','is_active'])`, `index(['phone'])`; students `index(['center_id','is_active'])`, `index(['teacher_id','is_active'])`, `index(['phone'])`; notifications `index(['notifiable_id','read_at'])`. Rewrite whereMonth/whereYear as `whereBetween('date', [$start, $end])` so the new indexes are used. Add a `EXPLAIN`-based regression check for the dashboard queries.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

The core factual claim holds: across the 35 migrations the only explicit ->index() calls are on athman, otp_resets, password_change_logs, student_requests and framework tables (verified via grep). attendances has only unique(student_id,date) + FK indexes and no index led by `date`; DashboardController.php:25-30 does run three `Attendance::where('date', today())->where('status', ...)->count()` queries on every admin dashboard load, each a full scan of attendances (date is not the leading column of any index). CenterManagerController.php:507 does `->latest('date')->latest('id')` over a whereHas(center) scope with no supporting index (filesort). users has no index on phone/role/is_active. No caching (Cache::remember) exists anywhere in app/ to mitigate.

However the finding is overstated in several places: (1) AuthController.php:132 is NOT login — it is forgot-password OTP request (throttle:5,1), and line 184 is OTP verify; actual login (line 36-37) looks up by `UPPER(display_code) = ?` (non-sargable, its own issue) then `email` which IS unique-indexed. "Mobile login by phone scans users" is factually wrong; and users is a tiny table (hundreds of rows) so the phone index is irrelevant. (2) ReportService.php:39-53 queries are per-student (`where('student_id', ...)`), so the existing unique(student_id,date) index on attendances already narrows to one student's rows before the whereMonth/whereYear filter; the "non-sargable even if indexed" concern is real for memorizations/weekly_tests (FK index on student_id still helps there) but is cheap in practice — a student has at most a few hundred rows. (3) The 900k rows/year impact scenario is hypothetical; current deployment is a single-country XAMPP install with seeder-scale data, and a full scan of ~1M narrow InnoDB rows is on the order of 100-300 ms, not an outage. There is no correctness, security or data-integrity consequence — purely a latency concern that grows over years.

Real, cheap, worth doing (attendances (date,status), (center_id,date), students (center_id,is_active)/(teacher_id,is_active)), but "high" is too strong for a performance nicety with no present symptom. Medium.

```text
backend/database/migrations/2024_01_01_000030_create_attendances_table.php:22 — only `$table->unique(['student_id', 'date'])`; no index led by `date`, `center_id`, or `teacher_id`. backend/app/Http/Controllers/Api/DashboardController.php:25-30 — three `Attendance::where('date', today())->where('status', ...)->count()` calls per admin dashboard load (full scans). backend/app/Http/Controllers/Api/CenterManagerController.php:505-507 — `whereHas('student', center_id) ->latest('date')->latest('id')` with no supporting index. CORRECTION: backend/app/Http/Controllers/Api/AuthController.php:132 and :184 are forgot-password OTP request/verify (public, throttle:5,1 / 10,1), not login; login at AuthController.php:36-37 uses `whereRaw('UPPER(display_code) = ?')` then `where('email')` (email unique-indexed at 0001_01_01_000000_create_users_table.php:17, display_code unique at 2026_07_16_100000...:39). CORRECTION: ReportService.php:39-53 filters by student_id first, which is covered by the existing unique(student_id,date) on attendances and FK indexes on memorizations/weekly_tests, so the whereMonth/whereYear non-sargability affects only a single student's rows. No Cache::remember anywhere in app/ (grep) — no mitigation, but also no current data volume to make this a live symptom.
```

</details>

### Every business invariant is enforced only in PHP; the database accepts violating rows

<a id="invariants-app-only"></a>

`invariants-app-only` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Support/PrimaryTeacherRule.php:18-35`, `backend/app/Http/Controllers/Api/ManagerManagementController.php:24-37`, `backend/app/Support/ParentResolver.php:50`, `backend/database/migrations/2024_01_01_000020_create_students_table.php:18-19`, `backend/database/migrations/2026_06_20_100000_change_users_role_to_string.php:15-17`, `DEPLOYMENT.md:43`

**Evidence**

```text
One primary teacher per center: `PrimaryTeacherRule::assert` is a plain `->exists()` check (no lock) on the admin path; only the manager path uses `lockForUpdate()` (CenterManagerController.php:137). One active manager per center: `assertSingleSupervisor` is also an unlocked exists() (ManagerManagementController.php:26-30). Parent phone uniqueness: ParentResolver.php:50 `User::where('role','parent')->where('phone', $phone)->first()` with no unique index; DEPLOYMENT.md:43 states a per-role unique index is 'not possible in MariaDB 10.4' (it is, via a STORED generated column). users.role is a free VARCHAR(255) with no CHECK (2026_06_20_100000:16); students.teacher_id/parent_id reference users with no guarantee the target row has role teacher/parent; messages.sender_role is an unchecked VARCHAR(16).
```

**Why it matters**

Two concurrent admin requests can create two primary teachers or two active managers for one center; a direct SQL fix-up, an import script, or a future endpoint can silently create a parent with a duplicate phone, a student whose 'teacher' is a parent account, or a role value the middleware does not know. At dozens of centers with a mobile client adding concurrent writers, app-only guards are a correctness liability.

**Recommendation**

Add DB-level guards with stored generated columns + unique indexes (works on MariaDB 10.2+ and MySQL 5.7+): `users.primary_of_center_id BIGINT AS (IF(role='teacher' AND type='محفظ أساسي', center_id, NULL)) STORED UNIQUE`; `users.active_manager_of_center_id AS (IF(role='center_manager' AND is_active=1, center_id, NULL)) STORED UNIQUE`; `users.parent_phone AS (IF(role='parent', phone, NULL)) STORED UNIQUE`. Add CHECK constraints (`role IN (...)`, `sender_role IN ('parent','teacher')`, `nationality_type`), enforced on MariaDB 10.2.1+/MySQL 8.0.16+. Keep the PHP checks for friendly 422 messages; catch the unique-violation to return the same message.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

The factual claims hold up on inspection. PrimaryTeacherRule::assert (app/Support/PrimaryTeacherRule.php:24-28) is an unlocked exists(); TeacherController@store calls it at line 79 BEFORE opening the DB::transaction at line 82, so on the admin path the check-then-insert is neither locked nor even inside the same transaction. ManagerManagementController::assertSingleSupervisor (lines 24-37) is likewise an unlocked exists() called at line 79 before the transaction at line 82. ParentResolver.php:50 looks up parents by phone with no unique index (users.phone is a plain nullable string, 0001_01_01_000000_create_users_table.php:18), and DEPLOYMENT.md:43 does claim a per-role unique index is impossible in MariaDB 10.4 (a STORED generated column + unique index would work). users.role is a plain string (2026_06_20_100000:16), messages.sender_role is an unchecked string(16) (2026_08_22_100000:20), and the only DB-level integrity is FKs, email/display_code/national_id uniqueness and the (student_id,date) attendance unique key — no CHECK constraints, no conditional unique indexes. Only the manager's add-teacher path uses lockForUpdate (CenterManagerController.php:137-138). So the finding is not wrong and not mitigated at the DB layer.

However, 'high' overstates the practical exposure. Mitigations that do exist: (1) every API write path forces role server-side and validates teacher_id/parent_id with Rule::exists(...)->where('role', ...) (StudentController.php:203,207,209,511), so a 'teacher who is a parent' cannot be produced through the API; (2) the invariants are covered by feature tests in 9 files (ManagerAddTeacherTest, CenterManagerTest, ManagerStatusTest, etc.); (3) the race requires two admins (or one admin double-submitting) hitting the same center within the same few milliseconds — the product has a single system admin, and php artisan serve is single-threaded in dev, so the realistic window is tiny; (4) the consequences of a duplicate primary/manager/parent-phone are recoverable data-quality problems (a deactivate/edit fixes them), not security or data-loss events. The 'dozens of centers + mobile client' scenario is speculative. This is a legitimate defense-in-depth gap (DB should be the last line of defense against imports/manual SQL/future bugs) but is best rated medium.

```text
backend/app/Http/Controllers/Api/TeacherController.php:79 `$this->assertSinglePrimary(...)` runs outside the `DB::transaction` opened at :82 — no lock, no shared transaction. backend/app/Http/Controllers/Api/ManagerManagementController.php:79 `assertSingleSupervisor` likewise precedes the transaction at :82; the check itself (:26-30) is an unlocked `exists()`. backend/app/Http/Controllers/Api/CenterManagerController.php:137-138 is the only path with `lockForUpdate()`. Migrations: users.phone plain nullable string (0001_01_01_000000_create_users_table.php:18), users.role plain string (2026_06_20_100000_change_users_role_to_string.php:16), messages.sender_role string(16) without CHECK (2026_08_22_100000_create_messages_table.php:20); no CHECK constraints anywhere in database/migrations. Mitigation: StudentController.php:203/207/209/511 validate teacher_id/parent_id with `Rule::exists('users','id')->where('role', ...)`, and the invariants are covered by tests (tests/Feature/ManagerAddTeacherTest.php, CenterManagerTest.php, ManagerStatusTest.php, ManagerTeacherStatusTest.php, AdminCenterDetailsTest.php).
```

</details>

### student_requests.national_id is VARCHAR(12) while students.national_id was widened to VARCHAR(32) — foreign-student transfers can 500

<a id="request-national-id-width-mismatch"></a>

`request-national-id-width-mismatch` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/database/migrations/2026_06_28_100000_create_student_requests_table.php:37`, `backend/database/migrations/2026_06_28_120000_add_nationality_to_students_and_users.php:23`, `backend/app/Http/Controllers/Api/StudentRequestController.php:168`, `backend/app/Http/Controllers/Api/StudentController.php:38-40`, `backend/config/database.php:60`

**Evidence**

```text
2026_06_28_100000:37 `$table->string('national_id', 12)->nullable();` — never widened by any later migration (grep confirms). 2026_06_28_120000:23 `ALTER TABLE students MODIFY national_id VARCHAR(32) NULL` and StudentController.php:38 allows foreigners `['string','max:32']`. StudentRequestController.php:168 copies it verbatim into the request snapshot: `'national_id' => $student->national_id`. `'strict' => true` (config/database.php:60) means a 13-32 char passport number raises 'Data too long for column' -> unhandled 500.
```

**Why it matters**

The transfer flow — one of the manager's core features and a likely mobile use case — fails for any foreign student with a passport/residence number longer than 12 characters. No test covers it (only PaginationSearchTest mentions 'foreigner').

**Recommendation**

Migration: `Schema::table('student_requests', fn ($t) => $t->string('national_id', 32)->nullable()->change());` plus a feature test transferring a foreigner with a 20-char id. Also align `students.phone` (VARCHAR 255) with `student_requests.phone` (20) or vice versa.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The evidence checks out. student_requests.national_id is created as VARCHAR(12) (2026_06_28_100000:37) and no later migration touches it (130000 only adds nationality columns; 2026_09_08 only changes target_teacher_id). students.national_id was widened to VARCHAR(32) (2026_06_28_120000:23) and StudentController::studentIdentityRules allows ['string','max:32'] for foreigners on both store (line 200) and update (line 507). The admin frontend actively supports this: admin/students.html relabels the field to 'رقم الجواز/الإقامة' for foreigners and sends national_id on add (line 302) and edit, with no maxlength. So a foreign student with a 13–32 char id is storable. StudentRequestController::managerStore (line 168) copies $student->national_id verbatim into StudentRequest::create with no try/catch; config/database.php has 'strict' => true, so MySQL raises 'Data too long for column' → QueryException → 500. Tests (StudentTransferRequestTest, StudentRequestNotificationTest) only use 12-digit Libyan ids, so nothing covers it. I could not run the DB (no XAMPP/vendor present in this environment) to confirm the live column width, but the migration chain is unambiguous. Partial mitigations reduce likelihood rather than refute: the manager frontend forces national_id=null for foreigners (manager/students.html:377), the seeders give foreigners null ids, and real passport/residence numbers are usually 6–10 chars, so exceeding 12 requires an admin-entered long id. Given that narrow precondition and a trivial workaround (admin shortens the id), I rate it low rather than medium; it remains a genuine, one-line-fix schema drift bug.

```text
backend/database/migrations/2026_06_28_100000_create_student_requests_table.php:37 `$table->string('national_id', 12)->nullable();` (only other student_requests migrations: 2026_06_28_130000 adds nationality cols, 2026_09_08_100000 alters target_teacher_id — neither widens national_id). backend/database/migrations/2026_06_28_120000_add_nationality_to_students_and_users.php:23 `ALTER TABLE students MODIFY national_id VARCHAR(32) NULL`. backend/app/Http/Controllers/Api/StudentController.php:36-38 foreigner rule `['string','max:32']`, applied at :200 (store) and :507 (update). frontend-html/admin/students.html:96,302 admin sends passport/residence number for foreigners with no length cap; frontend-html/manager/students.html:377 manager sends null for foreigners (mitigates the manager-created path only). backend/app/Http/Controllers/Api/StudentRequestController.php:163-175 StudentRequest::create with `'national_id' => $student->national_id`, no try/catch (grep for try/catch in the file: none). backend/config/database.php:60 `'strict' => true`. Tests: tests/Feature/StudentTransferRequestTest.php:30 uses only a 12-digit id.
```

</details>

### Cascade deletes remain on history and audit tables even though the product policy is 'never hard-delete'

<a id="cascade-deletes-contradict-no-delete-policy"></a>

`cascade-deletes-contradict-no-delete-policy` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/database/migrations/2024_01_01_000030_create_attendances_table.php:13`, `backend/database/migrations/2024_01_01_000040_create_memorizations_table.php:13`, `backend/database/migrations/2026_06_28_150000_add_password_tracking.php:24`, `backend/database/migrations/2026_08_22_100000_create_messages_table.php:18-19`, `backend/database/migrations/2026_06_28_100000_create_student_requests_table.php:29`, `backend/database/migrations/2024_01_01_000050_create_revisions_table.php:14`, `backend/database/migrations/2024_01_01_000060_create_tajweed_evaluations_table.php:14`

**Evidence**

```text
19 `cascadeOnDelete` vs 11 `nullOnDelete` across migrations. attendances/memorizations/weekly_tests/messages/weekly_test_questions all `student_id ... cascadeOnDelete()`; password_change_logs.user_id, otp_resets.user_id, messages.sender_id and student_requests.requested_by cascade on users; revisions/tajweed_evaluations.teacher_id still cascade (2026_07_02 only fixed three tables). CLAUDE.md and the toggleStatus endpoints say deletion was replaced by deactivation, but nothing at the storage layer prevents `DELETE FROM students WHERE ...` from silently erasing years of attendance and the password-change audit log.
```

**Why it matters**

A DBA clean-up, a tinker one-liner, or a future 'delete duplicate' feature would irreversibly destroy minors' attendance/memorization history and the audit trail with zero database resistance. Enterprise posture requires the DB to enforce the retention policy, not just the controllers.

**Recommendation**

Migration switching student_id/user_id FKs on attendances, memorizations, weekly_tests, weekly_test_questions, messages, password_change_logs, student_requests.requested_by to `restrictOnDelete()`; apply the D1 nullOnDelete to revisions/tajweed_evaluations.teacher_id for consistency. Keep cascade only on otp_resets (ephemeral). Add a test asserting `Student::destroy` on a student with attendance throws a QueryException.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 78% · corrected severity: low

The evidence is factually correct. grep over backend/database/migrations confirms the cited cascades: attendances/memorizations/revisions/tajweed_evaluations/weekly_tests/weekly_test_questions/messages `student_id ... cascadeOnDelete()`; password_change_logs.user_id, otp_resets.user_id, messages.sender_id and student_requests.requested_by cascade on users; and 2026_07_02_100000_teacher_id_null_on_delete_for_records.php only converts teacher_id on the three tables ['attendances','memorizations','weekly_tests'] to nullOnDelete, leaving revisions/tajweed_evaluations teacher_id on cascade. No migration uses restrictOnDelete, no model uses SoftDeletes, and no `deleting` observer exists, so the storage layer indeed offers zero resistance to a DELETE on students/users.

However, the finding is over-rated as medium. Tracing the actual execution paths: routes/api.php exposes no destroy for teachers (line 96 except destroy), centers (100), students (141 except store/destroy), weekly-tests (163, explicit 'no delete' decision), and admin/managers has no delete route. The only `->delete()` / `destroy` calls in app/ are on OtpReset rows, the current access token, a single Memorization row (MemorizationController:221, a teacher deleting their own record — a leaf table), and weekly_test_questions inside the test update (WeeklyTestController:152, followed by re-insert). No code anywhere calls `Student::destroy`, `User::delete`, or `Center::delete`. So the cascades are unreachable from the API; the only trigger is a privileged operator running tinker/raw SQL or a hypothetical future feature. That makes it a defense-in-depth / retention-policy gap, not a live data-loss bug. The revisions/tajweed_evaluations tables are additionally dormant (no controller writes to them), so their cascade has no practical impact today. For a small-team, single-deployment product where the same developers own the DB, this is best rated low with the recommended migration still worth doing (cheap, effort S) because it codifies the 'never hard-delete' policy at the DB level and would also make the retention policy testable.

```text
Cascades confirmed at: backend/database/migrations/2024_01_01_000030_create_attendances_table.php:13; 2024_01_01_000040_create_memorizations_table.php:13; 2024_01_01_000050_create_revisions_table.php:13-14; 2024_01_01_000060_create_tajweed_evaluations_table.php:13-14; 2024_01_01_000070_create_weekly_tests_table.php:13; 2026_06_19_074100_create_weekly_test_questions_table.php:16-17; 2026_06_28_100000_create_student_requests_table.php:29; 2026_06_28_140000_create_otp_resets_table.php:17; 2026_06_28_150000_add_password_tracking.php:24; 2026_08_22_100000_create_messages_table.php:18-19. D1 fix scope limited to three tables: 2026_07_02_100000_teacher_id_null_on_delete_for_records.php:15 (`private array $tables = ['attendances', 'memorizations', 'weekly_tests'];`). Mitigation (no app-reachable trigger): backend/routes/api.php:96 `->except(['destroy'])` (teachers), :100 (centers), :141 `->except(['store', 'destroy'])` (students), :163 weekly-tests `->only(['index','store','show','update'])`; the only `->delete()` calls in app/ are AuthController.php:91/139/191/204 (tokens, OtpReset), MemorizationController.php:221 (single leaf record), WeeklyTestController.php:152 (questions re-write). No `Student::destroy`/`User::delete`/`Center::delete` anywhere in app/; no SoftDeletes, no `deleting` hooks, no restrictOnDelete, and no feature test exercises a parent-row delete.
```

</details>

### Audit is ad-hoc who/when columns without before-values; no generic audit trail for minors' data

<a id="no-audit-log-table"></a>

`no-audit-log-table` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/database/migrations/2026_07_18_120000_add_correction_audit_to_attendances.php:8-12`, `backend/database/migrations/2026_07_23_100000_add_is_active_to_users.php:19-21`, `backend/database/migrations/2026_08_10_100000_add_status_audit_to_students.php:17-19`, `backend/database/migrations/2026_06_28_150000_add_password_tracking.php:22-29`, `DEPLOYMENT.md:41`

**Evidence**

```text
2026_07_18_120000 header: 'the previous state is not kept (approved decision: light audit suffices)'. Audit columns exist only for three actions (attendance correction, user status, student status) plus password_change_logs; there is no record of who edited a student's name/national_id/guardian, who changed a memorization/test, who moved a teacher between centers, or who approved a transfer (student_requests has no approved_by/at). DEPLOYMENT.md:41 lists 'soft-deletes/audit log for sensitive entities' as a future note.
```

**Why it matters**

The system stores national IDs, phones and attendance of children across many centers. Disputes ('who changed my son's result/teacher?'), regulatory requests, and incident forensics cannot be answered from the data. The per-table who/when pattern also does not scale: every new mutable field needs two more columns.

**Recommendation**

Add an `audit_logs` table (id, actor_id FK, actor_role, action, auditable_type, auditable_id, before JSON, after JSON, ip, user_agent, created_at; index (auditable_type, auditable_id), (actor_id, created_at)) fed by a model observer on Student, User, Attendance, Memorization, WeeklyTest, StudentRequest. Add `approved_by/approved_at/rejected_by/rejected_at` to student_requests. Keep the existing light columns for fast display.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 70% · corrected severity: low

Factual claims verified. The migration header at backend/database/migrations/2026_07_18_120000_add_correction_audit_to_attendances.php:8-11 does say the previous state is not kept as an approved 'light audit' decision. Audit who/when columns exist only on attendances (corrected_by/at), users (status_changed_by/at), students (status_changed_by/at) plus password_change_logs. No audit_logs table, no observer, no activitylog package (grep of app/, composer.json, migrations returns nothing), no Log:: calls in the Student/Teacher/Memorization/WeeklyTest controllers, no updated_by/edited_by columns anywhere. student_requests (2026_06_28_100000_create_student_requests_table.php:30-50) has only admin_note + timestamps; StudentRequestController.php:293/329/374 update status without recording the actor. DEPLOYMENT.md:41 lists an audit log as a non-blocking future item. So the finding is correct. However severity is overstated for this product: (1) it is a documented, deliberate design decision, not a defect; (2) the surface is smaller than implied — memorizations and weekly tests have no update endpoint at all (create/delete only) and every record carries teacher_id as creator; hard-deletion of students/teachers/centers/managers was already replaced by audited toggles; (3) request approval is effectively attributable because there is at most one manager per center (ManagerManagementController::assertSingleSupervisor) even without approved_by; (4) no regulatory regime requiring an audit trail is identified for this Libyan single-country small deployment. Real gap (edits to student identity/guardian fields, teacher center moves, hard-deletes of memorization/test rows leave no trace), but it is design debt / an enhancement, best rated low.

```text
backend/database/migrations/2026_07_18_120000_add_correction_audit_to_attendances.php:8-11 (light-audit decision, previous state not kept); backend/database/migrations/2026_06_28_100000_create_student_requests_table.php:49-50 (only admin_note + timestamps, no approved_by/rejected_by); backend/app/Http/Controllers/Api/StudentRequestController.php:293,329,374 (status updates record no actor); DEPLOYMENT.md:41 (audit log listed as future, non-blocking); no matches for audit_logs/activitylog/observer/updated_by in backend/app, backend/composer.json, backend/database/migrations. Mitigating: routes/api.php exposes no update for memorizations or weekly-tests (create/delete only) and both carry teacher_id; one-manager-per-center makes approver identity inferable.
```

</details>

### Backup/restore is a single manual mysqldump suggestion; no retention, restore drill, or PITR

<a id="backup-restore-story"></a>

`backup-restore-story` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `DEPLOYMENT.md:15`, `.cpanel.yml:1-7`, `backend/routes/console.php`

**Evidence**

```text
DEPLOYMENT.md:15 is the only backup reference in the repository: 'schedule mysqldump daily at least ... `mysqldump -u root mutqin_db > backup-$(date +%F).sql`'. `.cpanel.yml` rsyncs frontend and backend but contains no database step (no `migrate`, no backup-before-deploy). routes/console.php defines only the `inspire` command — no scheduled backup, no `backup:run`, no pruning. No mention of binary logs/PITR, off-site copy, encryption of dumps (they contain national IDs), or a tested restore.
```

**Why it matters**

For a system that will hold identity data of children across dozens of centers, an untested, unscheduled, unencrypted backup is a business-continuity and privacy risk. A bad migration deploy via cPanel has no automatic pre-deploy snapshot.

**Recommendation**

Adopt spatie/laravel-backup (or a cron mysqldump wrapper) with: daily full + binlog PITR, 30-day retention, AES-encrypted archives, off-site (S3-compatible) copy, `backup:monitor` health check, and a quarterly restore drill into `mutqin_test`. Add a pre-deploy dump step to `.cpanel.yml` before `php artisan migrate --force`. Document RPO/RTO targets in DEPLOYMENT.md.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Evidence verified as quoted. DEPLOYMENT.md:15 (row "7 | نسخ احتياطي لقاعدة البيانات") is the sole backup reference in the repo and only suggests a manual daily mysqldump example. `.cpanel.yml` lines 1-7 contain two rsync tasks and a cache-clear; no DB step of any kind. `backend/routes/console.php` defines only `inspire`; grep of app/, bootstrap/, routes/ finds no `Schedule::`, no Console/Commands directory, no spatie/laravel-backup in composer.json (only laravel-ignition). So the factual claim is correct and there is no mitigation anywhere in the code, tests, or docs.

Two corrections temper the finding: (1) the impact line "a bad migration deploy via cPanel has no automatic pre-deploy snapshot" overstates — `.cpanel.yml` never runs `php artisan migrate`, so migrations are already a manual, operator-driven step, not an automated deploy hazard; (2) the recommendation (binlog PITR, S3 off-site, AES archives, backup:monitor) is largely inapplicable to the actual target — a shared cPanel account (`/home/[redacted-cpanel-user]/public_html`), where binary-log access is normally unavailable and the host's own account-level backups (cPanel Backup/JetBackup) are the realistic mechanism. This is an operations/documentation gap, not a code-correctness defect, and for a small single-country deployment the practical fix is a documented cron + retention + restore-check, not the enterprise stack proposed. Real but over-rated relative to a code audit; downgrade to low.

```text
DEPLOYMENT.md:15 — only backup mention: `mysqldump -u root mutqin_db > backup-$(date +%F).sql` (manual example, no retention/encryption/restore). .cpanel.yml:4-7 — two rsync tasks + `rm -f bootstrap/cache/*.php`; no `artisan migrate`, so the "bad migration deploy" impact claim is inaccurate (migrations are not run by the deploy hook). backend/routes/console.php:6-8 — only `inspire`; no scheduler entries; no app/Console/Commands dir; backend/composer.json has no spatie/laravel-backup (line 23 is laravel-ignition). Target is shared cPanel hosting (`/home/[redacted-cpanel-user]`), where PITR/binlog is not normally available.
```

</details>

### Progress and reports are computed from raw rows in PHP; several endpoints load unbounded result sets

<a id="derived-state-and-volume-assumptions"></a>

`derived-state-and-volume-assumptions` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/MemorizationController.php:91-94`, `backend/app/Services/ReportService.php:38-56`, `backend/app/Services/ReportService.php:135-146`, `backend/app/Services/ReportService.php:213-219`, `backend/database/migrations/2024_01_01_000040_create_memorizations_table.php:18-19`

**Evidence**

```text
MemorizationController.php:91 `Memorization::whereIn('student_id', $students->pluck('id'))->whereNotNull('surah_name')->get(['student_id','surah_name'])` — for an admin `$students` is every student in the system, so every memorization row is pulled into PHP to derive juz completion (no DISTINCT, no aggregation). ReportService.php:38-56 loads all attendance/memorization/test rows per student per month and counts with Collection::where in PHP; centerData (:135-146) and progressSummary (:213-219) do the same per center. memorizations has no unique key, so repeated recordings of the same surah inflate these sets. Reports use `whereMonth/whereYear` which cannot use an index.
```

**Why it matters**

At 3,000 students x ~100-300 memorization rows the progress endpoint moves 300k-900k rows through PHP memory per request; monthly reports do N queries per teacher/center. Fine for one center with 50 students (the seeded demo), not for the target scale or for mobile clients that will hit progress screens frequently.

**Recommendation**

Materialize derived state: a `student_surah_progress` table (student_id, surah_number, first_recorded_at, last_quality) with unique(student_id, surah_number), maintained in MemorizationController@store/destroy, so progress = one aggregated query. Replace collection counting with SQL aggregates (`SUM(status='present')` is already used elsewhere) and `whereBetween` date ranges. Consider a nightly `center_daily_stats` rollup for the admin dashboard.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 75% · corrected severity: low

The quoted code exists and behaves as described: MemorizationController@studentsProgress (lines 79-94) loads every memorization row (student_id, surah_name) for the caller's student set into PHP and derives juz completion via SurahReference::progress; ReportService::studentData (38-56), centerData (135-146) and teachersPerformance (~165-190) load raw Attendance/WeeklyTest rows per month and count with Collection::where; memorizations has no unique key (migration 000040, only FK indexes); whereMonth/whereYear is not sargable. However the impact is materially overstated for this product: (1) `/memorizations/students-progress` has NO caller anywhere in frontend-html (grep finds none), so the "mobile clients hitting progress screens frequently" scenario does not exist; the only client is the static PWA-wrapped HTML. (2) The admin-wide path is effectively unreachable through the UI — the latest commit (37313bf) explicitly bars the admin from teacher pages, so in practice the endpoint would be teacher-scoped (a few dozen students × ≤114 surahs). (3) The report queries are already narrowed by an indexed `student_id` FK before the month filter, and atRiskStudents already uses SQL aggregates (selectRaw SUM/COUNT + groupBy), so the N-per-center/teacher loops iterate over a handful of centers/teachers in a Libyan memorization-center deployment. (4) No "target scale" of 3,000 students is documented anywhere in the repo; the 300k-900k-row figure is the auditor's assumption. The finding is a legitimate scalability/hygiene note (no dedup key on memorizations, collection counting instead of SQL aggregates, whereMonth), not a present defect, and the recommended materialized `student_surah_progress` table is disproportionate. Keep as a low-severity, "later" item.

```text
backend/app/Http/Controllers/Api/MemorizationController.php:79-94 — studentsProgress loads all memorization rows for the student set; admin branch (`!$user->isAdmin()`) is the only unbounded path. frontend-html/: no reference to `students-progress` (grep -rn returns nothing) — endpoint unused by the client. Commit 37313bf: admin no longer opens teacher/ pages, so admin-scale invocation is not reachable via UI. backend/app/Services/ReportService.php:213-236 — atRiskStudents already uses `selectRaw('student_id, COUNT(*) AS total, SUM(status = "present") AS present')->groupBy('student_id')`, i.e. SQL aggregation exists for the heaviest report. backend/database/migrations/2024_01_01_000040_create_memorizations_table.php:13-14 — foreignId()->constrained() creates indexes on student_id/teacher_id, so month filters run over an index-narrowed subset. No repo document states a 3,000-student target.
```

</details>

### Moving a teacher to another center leaves their students assigned to a teacher outside the students' center

<a id="teacher-center-move-orphans-students"></a>

`teacher-center-move-orphans-students` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/TeacherController.php:150-176`, `backend/app/Http/Controllers/Api/StudentController.php:203`, `backend/database/migrations/2024_01_01_000020_create_students_table.php:18-19`

**Evidence**

```text
TeacherController@update validates `'center_id' => 'required|exists:centers,id'` and writes `'center_id' => $request->center_id` (line 165) with no handling of `$teacher->students` (grep for `students()->update` in TeacherController returns nothing). Student creation enforces same-center via `Rule::exists('users','id')->where('role','teacher')->where('center_id', $request->input('center_id'))` (StudentController.php:203), but the DB has no such constraint (students.center_id and students.teacher_id are independent FKs).
```

**Why it matters**

Produces rows that violate the application's own rule: students in center A whose teacher_id points to a user in center B. Manager scoping (which mixes 'student center' and 'teacher center' — e.g. CenterManagerController.php:517-519 requires both) then hides or misattributes these students, and reports per center/teacher disagree.

**Recommendation**

In TeacherController@update, when center_id changes inside the transaction either (a) refuse with 422 if the teacher has active students, or (b) detach them (`teacher_id = null`, `former_teacher_name = teacher.name`) — matching the existing 'without teacher' path. Long term, enforce at the DB with a composite FK: add `students.teacher_center_id` generated/maintained column and `FOREIGN KEY (teacher_id, center_id) REFERENCES users(id, center_id)` (requires a unique(id, center_id) on users), or a trigger. Add a regression test.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Evidence verified. backend/app/Http/Controllers/Api/TeacherController.php:146-176 (`update`) validates `center_id => required|exists:centers,id` and writes `'center_id' => $request->center_id` (line 165) via `$teacher->update($data)`; the only `students()` reference in the controller is the read-only listing in `show` (line 112). No detach/refuse logic, no transaction. The admin edit form (frontend-html/admin/teachers.html:44) exposes the center select, so the path is reachable from the UI. Student create/update enforce teacher-in-same-center only at validation time (StudentController.php:203 and :511), and the migration has independent FKs with no composite constraint. No feature test covers changing a teacher's center (ManagerChangeTeacherTest covers moving a student between teachers, not a teacher between centers). Mitigations: the manager path (CenterManagerController teacher update) forces center_id server-side, so only the admin can trigger this; it is a rare administrative action; the student stays visible under its own center (manager lists filter on students.center_id) and the teacher still sees them via teacher_id, so nothing is hidden outright - but teacher-filtered manager views (e.g. attendanceIndex requiring both student center and teacher center, CenterManagerController.php:517-519) and per-center/per-teacher reports will disagree. Minor inaccuracy in the finding: there is no transaction in `update` to put the fix "inside". Real but admin-only and rare, so slightly overrated; low-to-medium.

```text
backend/app/Http/Controllers/Api/TeacherController.php:150-165 (center_id validated only as exists:centers,id and written directly; no students() handling; no DB transaction); TeacherController.php:112 is the only students() usage (read-only in show). frontend-html/admin/teachers.html:44 exposes center_id select on edit. StudentController.php:203 and :511 enforce same-center only at validation time. Manager teacher update in CenterManagerController forces center_id, so only admin can cause the inconsistency. No test in backend/tests/Feature exercises a teacher center change.
```

</details>

### personal_access_tokens grows unbounded: every login inserts a token and nothing prunes expired ones

<a id="token-table-unbounded-growth"></a>

`token-table-unbounded-growth` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:64`, `backend/config/sanctum.php:55`, `backend/routes/console.php`

**Evidence**

```text
AuthController.php:64 `$user->createToken('auth_token', $abilities)` on every successful login with no deletion of the user's previous tokens; sanctum.php:55 `'expiration' => 10080` (7 days) only makes rows unusable, not deleted; routes/console.php contains only the `inspire` command — `sanctum:prune-expired` is never scheduled.
```

**Why it matters**

A public mobile app for four roles will log in far more often than the web demo; the token table (indexed on a 64-char token and a morph pair) becomes the fastest-growing table in the schema and every auth request does a lookup against it.

**Recommendation**

In routes/console.php: `Schedule::command('sanctum:prune-expired --hours=24')->daily();` and, optionally, cap tokens per user at login (`$user->tokens()->where('name','auth_token')->orderByDesc('id')->skip(5)->delete()`). Requires the scheduler cron to be configured on the host (also needed for backups).

### Arabic display strings are used as stored enum codes and compared in SQL/PHP

<a id="arabic-strings-as-enum-codes"></a>

`arabic-strings-as-enum-codes` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `backend/database/migrations/2026_05_11_100154_update_tables_for_mutqen_v2.php:13`, `backend/database/migrations/2026_05_11_100154_update_tables_for_mutqen_v2.php:23`, `backend/database/migrations/2026_06_19_074100_create_weekly_test_questions_table.php:19`, `backend/app/Support/PrimaryTeacherRule.php:20`, `backend/app/Http/Controllers/Api/CenterController.php:120`

**Evidence**

```text
`$table->enum('type', ['محفظ أساسي', 'محفظ معاون'])` (users), `$table->enum('result', ['ناجح', 'راسب'])` (weekly_tests and weekly_test_questions). Logic compares the literals: PrimaryTeacherRule.php:20 `if ($type !== 'محفظ أساسي')`, CenterController.php:120 `SUM(result = 'ناجح')`, LibyanDataSeeder:239-240. Other enums (attendances.status, memorizations.quality, nationality_type, student_requests.type/status) correctly use ASCII codes.
```

**Why it matters**

Couples storage to one spelling of one language (a stray tatweel/hamza variant or a UI copy change breaks the primary-teacher rule silently); MySQL ENUM changes require ALTER TABLE; every client (including Flutter) must hard-code Arabic literals as codes and cannot localize.

**Recommendation**

Migrate to ASCII codes with label maps: users.type -> `primary|assistant`, result -> `pass|fail` (string(16) + CHECK, or a lookup table), with a data migration `UPDATE ... SET type = CASE ...`. Expose both `code` and `label` in API resources so the web client and Flutter render labels from the server.

### Historical cruft: dead birth_date with a static age, untrusted memorizations.juz/hizb/eighth, VARCHAR time and phones

<a id="schema-cruft-birthdate-age-juz"></a>

`schema-cruft-birthdate-age-juz` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `backend/database/migrations/2024_01_01_000020_create_students_table.php:14`, `backend/database/migrations/2026_05_11_100154_update_tables_for_mutqen_v2.php:17`, `backend/app/Models/Student.php:12,31`, `backend/database/migrations/2024_01_01_000040_create_memorizations_table.php:19-23`, `backend/database/migrations/2026_06_19_163400_add_excel_fields_to_attendances_table.php:12`

**Evidence**

```text
`birth_date` appears in the app only in Student.php:12 and :31 (fillable + cast) — zero controller/frontend references (`grep -rln birth_date frontend-html/` empty) — while `age` (24 references) is a static INTEGER that never advances. memorizations.juz is `integer nullable` hand-entered; CLAUDE.md itself says 'Do not trust the stored juz column' and SurahReference derives it from surah_name; `hizb`, `eighth` (free-text VARCHAR) are equally unvalidated. attendances.time is `string` not TIME; users.phone/students.phone are VARCHAR(255) though normalized to 10 digits.
```

**Why it matters**

Every student's age is wrong within a year of entry (age-based filters and reports drift); a nullable, untrusted juz column invites future code to trust it again; free-text eighth cannot be aggregated; oversized VARCHARs waste index bytes.

**Recommendation**

Make `age` a virtual generated column `TIMESTAMPDIFF(YEAR, birth_date, CURDATE())` (or compute in an accessor) after backfilling birth_date from age; then drop the physical age column. Drop memorizations.juz/hizb/eighth or replace with `surah_number` FK to a new `surahs` table and `thumn_id` FK to athman. Change attendances.time to TIME and phones to VARCHAR(20).

### MySQL-specific SQL in migrations and code prevents SQLite-backed fast tests and pins the engine

<a id="mysql-only-constructs"></a>

`mysql-only-constructs` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `backend/database/migrations/2026_06_28_120000_add_nationality_to_students_and_users.php:23,38`, `backend/app/Support/DisplayCode.php:56,64`, `backend/app/Http/Controllers/Api/CenterController.php:99-120`, `backend/database/seeders/LibyanDataSeeder.php:419`

**Evidence**

```text
2026_06_28_120000:23 `DB::statement('ALTER TABLE students MODIFY national_id VARCHAR(32) NULL')` — `MODIFY` is MySQL syntax; this is the migration CLAUDE.md refers to as 'raw MySQL ALTER ... sqlite won't work'. Laravel 11 supports `$table->string('national_id', 32)->nullable()->change()` natively. DisplayCode.php:56 relies on `LAST_INSERT_ID(expr)`; CenterController/others use `SUM(status = 'present')` boolean-sum idiom; seeder uses `DATE_ADD`.
```

**Why it matters**

Test suite must run on a real MySQL (slower CI, harder for contributors); DB portability (e.g. to PostgreSQL managed services) is off the table without touching migrations that have already run in production.

**Recommendation**

Accept MySQL/MariaDB as the committed engine and document it (it is a reasonable choice), but stop adding raw dialect SQL to migrations: use the schema builder's `change()`. If SQLite tests are wanted later, wrap the two raw statements in `if (DB::getDriverName() === 'mysql')`. Do not edit already-applied migrations in place.

### ExtraDataSeeder has no environment guard or transaction and CLAUDE.md still advertises it with stale credentials

<a id="seeder-guardrails-and-doc-drift"></a>

`seeder-guardrails-and-doc-drift` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `backend/database/seeders/ExtraDataSeeder.php:21-143`, `backend/database/seeders/DatabaseSeeder.php:13-19`, `backend/database/seeders/LibyanDataSeeder.php:40-43,108-112`, `CLAUDE.md:24,32,98`

**Evidence**

```text
ExtraDataSeeder::run has no `app()->environment('local')` check and no DB::transaction (LibyanDataSeeder has both at :108-116); it uses `rand()` so runs are non-reproducible. DatabaseSeeder.php:13-14 comment: 'ExtraDataSeeder remains a file and is not called ... not recommended any more'. CLAUDE.md:24 still lists `db:seed --class=ExtraDataSeeder` as a normal command, :98 describes it as center-aware appendable data, and :32 says demo password is `[redacted-demo-password]` while LibyanDataSeeder.php:40-43 uses `[redacted-demo-password]`.
```

**Why it matters**

A `php artisan db:seed --class=ExtraDataSeeder` on production would append 5 fake centers, 8 teachers and ~40 students with known passwords, partially if it fails midway. Stale docs increase the odds someone runs it.

**Recommendation**

Either delete ExtraDataSeeder or add the same local-env guard + transaction. Update CLAUDE.md (seeder list, password, test count 38 not 20, code_sequences now has `parent`, weekly_tests has no passed/test_type). Consider moving demo passwords out of source into `.env.example` keys.

### Surah reference lives in a PHP constant and athman in an xlsx; memorizations.surah_name is free text with no FK

<a id="reference-data-not-normalized"></a>

`reference-data-not-normalized` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `backend/app/Support/SurahReference.php:19-40`, `backend/database/seeders/AthmanSeeder.php:17-25`, `backend/database/data/فهرس_الأثمان_الكامل.xlsx`, `backend/app/Http/Controllers/Api/MemorizationController.php:129`

**Evidence**

```text
SurahReference::SURAHS is a 114-entry PHP array keyed by Arabic name; memorizations.surah_name is `string` validated only by `Rule::in(array_keys(SurahReference::SURAHS))` (MemorizationController.php:129) — rows written before that rule (or via seeders/imports) are unconstrained. AthmanSeeder parses a binary xlsx with a non-ASCII filename via PhpSpreadsheet; the athman table (477 rows) covers 92 surahs and has no surah_number/juz columns, so it cannot join to SurahReference.
```

**Why it matters**

Progress/juz logic depends on exact Arabic spelling matches between two sources of truth (PHP constant vs athman rows); renaming a surah spelling requires a code deploy and a data migration; reporting by juz cannot be done in SQL.

**Recommendation**

Create a `surahs` table (number PK 1..114, name_ar, juz_start, juz_end, pages) seeded from SurahReference, add `athman.surah_number` FK and `memorizations.surah_number` FK (backfill by name), keep `surah_name` as a denormalized label temporarily. Store the athman source as CSV/JSON with an ASCII filename for diffable version control.

## Refuted by verification (1) — kept for transparency

### center_id is missing from most tenant-owned record tables and has two different meanings on attendances

<a id="tenant-key-inconsistent"></a>

`tenant-key-inconsistent` · 🟠 high · ❌ refuted · **NEXT** · effort M (1–3 days)

**Files:** `backend/database/migrations/2024_01_01_000040_create_memorizations_table.php`, `backend/database/migrations/2024_01_01_000070_create_weekly_tests_table.php`, `backend/database/migrations/2026_08_22_100000_create_messages_table.php`, `backend/database/migrations/2026_06_19_163400_add_excel_fields_to_attendances_table.php:14`, `backend/app/Http/Controllers/Api/CenterManagerController.php:505-506`, `backend/app/Http/Controllers/Api/CenterManagerController.php:572-574`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:307-349`

**Evidence**

```text
Tables carrying center_id: users (nullable), students (nullable), attendances (nullable), student_requests (target/from). Tables without it: memorizations, weekly_tests, weekly_test_questions, messages, revisions, tajweed_evaluations, notifications. attendances.center_id is written as 'the student's center at record time' (AttendanceImportController.php:307 comment: 'the student's center always — most accurate on transfers') and used for scoping in the import path (:346-349 `whereIn('center_id', ...)`), but the manager review and correction endpoints ignore it and scope through the student's *current* center: CenterManagerController.php:506 `->whereHas('student', fn ($s) => $s->where('center_id', $centerId))` and :573 `$attendance->student->center_id !== $user->center_id`.
```

**Why it matters**

After an inter-center transfer, center B's manager can see and 'correct' attendance that physically happened in center A, while center A's manager loses access to records their own device produced. Every memorization/test/message query for a center must join through students, which blocks simple per-center indexes, per-center exports/backups, and any future row-level-security or sharding. Multi-tenancy by join is the classic scaling trap for a 'dozens of centers' system.

**Recommendation**

Decide and document one semantic ('center_id = center where the record was produced'). Add `center_id` (FK, NOT NULL, indexed with date) to memorizations, weekly_tests, weekly_test_questions and messages via backfill from students.center_id; make attendances.center_id NOT NULL after backfilling the NULLs from students. Scope every manager-gated query on the record's own center_id, and let a transferred student's history remain visible to both centers via an explicit 'history' endpoint if needed. Add a feature test: transfer student, assert old center still sees its rows and new center does not see pre-transfer rows.

<details><summary>Verification — 1 independent reviewer</summary>

**Refuted** · confidence 72% · corrected severity: low

The headline claim ("attendances.center_id has two different meanings" / "used for scoping in the import path but ignored by review") does not survive tracing the code. (1) The quoted import-path scoping at AttendanceImportController.php:346-349 is `Student::where('is_active', true)->where('center_id', ...)` — it filters STUDENTS by their current center to compute absentees; it never reads attendances.center_id. (2) attendances.center_id is only ever WRITTEN (import :307, manual store AttendanceController.php:111, both as `$student->center_id`) and is never read by any query: grep across app/ finds no `Attendance::...where('center_id'...)`; ReportService (lines 38, 81, 139, 186, 218, 318, 415, 489) scopes attendance exclusively via `whereIn('student_id', ...)` derived from students.center_id; CenterManagerController attendanceIndex (:506) and correctAttendance (:573) also scope via the student's current center. So the system has exactly ONE consistently-applied tenant semantic — "the student's current center owns the student's records" — and the column is an unused denormalization, not a conflicting second meaning. (3) The transfer scenario described in the impact (center B's manager sees/corrects pre-transfer attendance; A no longer sees it) is real behavior but is the intended consequence of the product's transfer model (StudentRequestController moves the student wholesale; history travels with the student, as with memorizations/tests which have no center_id at all). B's manager is the legitimate current owner of the student, so this is not a privilege escalation and not a data-integrity defect; A's manager losing view of a departed student is consistent with every other record type. No feature test asserts the opposite. (4) The remaining point — memorizations/weekly_tests/messages lack a center_id and must be scoped by joining through students — is an architectural preference. For a small single-country deployment ("dozens of centers", Laravel monolith, single MySQL) join-through-student scoping is a normal, correct design; "blocks sharding/RLS" is speculative roadmap concern, not a current integrity problem. There is no evidence of any query that produces wrong tenant results. Residual worth noting (low): attendances.center_id is dead-ish denormalized data that could drift from students.center_id after transfers; either drop it or document it as "center at record time" for future historical reporting — cosmetic, not high.

```text
backend/app/Http/Controllers/Api/AttendanceImportController.php:346-349 — `$scopeQuery = Student::where('is_active', true); ... ->where('center_id', $user->center_id)` filters students, not attendances.center_id (auditor misattributed this). backend/app/Http/Controllers/Api/AttendanceImportController.php:307 and backend/app/Http/Controllers/Api/AttendanceController.php:111 — attendances.center_id is written as `$student->center_id`; no read site exists anywhere in app/ (grep for Attendance queries on center_id returns none). backend/app/Services/ReportService.php:139,186,218,318,415,489 — all attendance aggregation scoped via `Attendance::whereIn('student_id', $ids)` where $ids come from Student::where('center_id', ...). backend/app/Http/Controllers/Api/CenterManagerController.php:506,573 — same student-current-center scoping. Migration 2026_06_19_163400_add_excel_fields_to_attendances_table.php:14 confirms the column is nullable with FK nullOnDelete (no integrity risk from the FK itself).
```

</details>

## Measured facts

| Metric | Value |
|---|---|
| migration files | 35 (all with down(); 0 modified after commit across 224 commits) |
| tables created | 24 Schema::create (16 application: users, centers, students, attendances, memorizations, revisions, tajweed_evaluations, weekly_tests, weekly_test_questions, athman, student_requests, otp_resets, password_change_logs, notifications, code_sequences, messages; 8 framework + migrations) |
| foreign key declarations | 32 (19 cascadeOnDelete, 11 nullOnDelete, remainder RESTRICT default) |
| unique constraints | 10 (users.email/display_code/id_number, students.display_code/national_id, centers.display_code, attendances(student_id,date), athman(hizb,thumn_in_hizb), personal_access_tokens.token, failed_jobs.uuid) |
| explicit non-FK/non-unique indexes | 14 total; on application tables only: athman(surah_name, global_order), otp_resets.user_id, password_change_logs.user_id, student_requests(status, national_id), messages x2 — none on attendances.date, memorizations, weekly_tests, users.role/phone, students.is_active |
| ENUM columns in final schema | 12 (attendances.status, memorizations.quality, revisions.quality, users.type[Arabic], users.nationality_type, students.nationality_type, weekly_tests.result[Arabic], weekly_test_questions.result[Arabic], student_requests.type/status/nationality_type/guardian_nationality_type, password_change_logs.method) |
| tables with center_id | 4 of 12 tenant-owned tables (users, students, attendances, student_requests) — all nullable |
| tables with timestamps() | 16 (all application tables except code_sequences) |
| audit columns | users.status_changed_by/at, students.status_changed_by/at, attendances.corrected_by/at, password_change_logs (3 rows types) — 0 before-value captures, 0 generic audit table |
| soft deletes | 0 deleted_at columns; is_active on users/students/centers |
| charset/collation | utf8mb4 / utf8mb4_unicode_ci (config default); 0 hard-coded collations in app or migrations |
| raw dialect SQL in migrations | 2 DB::statement (ALTER ... MODIFY, MySQL-only) + 4 DB::selectOne/DB::update in backfills |
| users/students column counts | 21 / 21 |
| feature-test files | 38 in tests/Feature + 2 in tests/Unit, 178 test methods, all RefreshDatabase on MySQL `mutqin_test` (CLAUDE.md says 20) |
| seeders | 5 (DatabaseSeeder -> AthmanSeeder + LibyanDataSeeder; ProductionSeeder; ExtraDataSeeder legacy/unguarded) + data/LibyanNames.php (30 male, 20 female, ~40 family names, fully synthetic) |
| LibyanDataSeeder volume | 1 center, 5 teachers, 2 managers, 26 parents, 50 students, 8 weeks x 6 days attendance (~2,400 rows), ~2,300 memorizations, 300 weekly tests, 3 requests, ~50 messages |
| query-filter hotspots (grep counts in app/) | where role=53, center_id=46, is_active=46, student_id=66, date=21, status=24, surah_name=8, phone=5 |
| scheduled DB maintenance | 0 (routes/console.php has only `inspire`; no sanctum:prune-expired, no backup) |
| backup references | 1 line (DEPLOYMENT.md:15 manual mysqldump); .cpanel.yml has no DB step |
| environment note | C:\xampp\php\php.exe and C:\xampp\mysql not present on this machine (Test-Path False) — PHP lint of migrations could not be executed; all analysis is static |

## Auditor notes

ERD-ready summary (final schema after all 35 migrations). users(id PK, name, display_code UQ NULL, email UQ, phone, role VARCHAR(255) default 'teacher', email_verified_at, password, password_changed_count, password_last_changed_at, remember_token, center_id FK->centers nullOnDelete, type ENUM(Arabic) NULL, is_active, status_changed_by FK->users nullOnDelete, status_changed_at, nationality_type ENUM, nationality_name, id_number UQ NULL, timestamps). centers(id, name, display_code UQ, city, address, phone, is_active, timestamps). students(id, name, display_code UQ, birth_date[dead], phone, national_id VARCHAR(32) UQ NULL, nationality_type, nationality_name, age INT, guardian_name, guardian_phone, center_id FK NULL nullOnDelete, teacher_id FK->users NULL nullOnDelete, former_teacher_name, parent_id FK->users NULL nullOnDelete, enrollment_date, is_active, status_changed_by FK, status_changed_at, timestamps). attendances(id, student_id FK cascade, teacher_id FK NULL nullOnDelete, center_id FK NULL nullOnDelete, date, time VARCHAR, status ENUM, notes, imported_at, corrected_by FK nullOnDelete, corrected_at, timestamps; UQ(student_id,date)). memorizations(id, student_id FK cascade, teacher_id FK NULL nullOnDelete, date, surah_name, juz NULL, hizb NULL, page_from, page_to, eighth VARCHAR, quality ENUM, notes, timestamps). weekly_tests(id, student_id FK cascade, teacher_id FK NULL nullOnDelete, result ENUM(Arabic), exam_date, notes, timestamps) — test_type/passed were DROPPED in v2 (CLAUDE.md drift). weekly_test_questions(id, weekly_test_id FK cascade, student_id FK cascade [redundant], eighth_start, result ENUM(Arabic), mistake, timestamps). revisions / tajweed_evaluations (dormant; teacher_id still cascadeOnDelete). student_requests(id, type ENUM, status ENUM idx, requested_by FK cascade, target_center_id FK, target_teacher_id FK NULL, student_id FK NULL nullOnDelete, national_id VARCHAR(12) idx [mismatch], nationality_type, nationality_name, student_name, age, phone(20), guardian_name, guardian_phone(20), guardian_email, guardian_nationality_type, guardian_nationality_name, guardian_id_number(32), from_center_id FK NULL, from_teacher_id FK NULL, admin_note, timestamps) — snapshot pattern, no approved_by/at. otp_resets(id, user_id FK cascade idx, otp_hash, expires_at, attempts, timestamps). password_change_logs(id, user_id FK cascade idx, changed_at, method ENUM, timestamps). notifications(uuid id, type, notifiable morphs idx, data TEXT, read_at, timestamps). messages(id, student_id FK cascade, sender_id FK cascade, sender_role VARCHAR(16), body, read_at, timestamps; idx(student_id,id), idx(student_id,sender_role,read_at)). code_sequences(name PK VARCHAR(40), value) rows: student, teacher, center_manager, center, parent. athman(id, hizb, thumn_in_hizb, global_order idx, surah_name idx, start_text, start_text_norm, page, timestamps; UQ(hizb,thumn_in_hizb)). Framework: password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs, personal_access_tokens, migrations.

Additional lower-priority observations not promoted to findings: (a) students.center_id is nullable at the DB but `required` in every create/update validator — tighten to NOT NULL after checking for NULLs. (b) students.guardian_name/guardian_phone are denormalized copies of the parent user with no sync; harmless today because parents cannot edit their phone, but the moment the mobile app adds parent profile editing they will drift — either drop them or maintain them in an observer. (c) weekly_test_questions.student_id duplicates weekly_tests.student_id with no consistency constraint. (d) `2026_07_02_100000` down() re-adds cascade but does not restore NOT NULL — not fully reversible (acceptable). (e) Two migrations (2026_07_23, 2026_07_24) were committed on 2026-08-10 with back-dated timestamps — harmless for fresh DBs but a process smell if any environment had already migrated past them. (f) utf8mb4_unicode_ci already folds Arabic hamza/alef variants and ignores tashkeel at the primary weight, so the REPLACE()-chain normalization in ArabicText::sqlNormalize is partly redundant for equality but still needed for LIKE on mixed inputs — worth a note when tuning search. (g) notifications.data.link stores web-client paths (coupling the data layer to HTML page names). (h) n8n digest and the PWA consume the HTTP API, not the DB — no schema coupling there (good). Doc drift confirmed in CLAUDE.md: 20 vs 38 feature-test files; weekly_tests cruft claim; code_sequences lacks `parent`; ExtraDataSeeder described as current though DatabaseSeeder marks it deprecated; demo password 2026 vs 2027; XAMPP paths do not exist on this machine; CLAUDE.md also still says weekly-tests have no update endpoint although WeeklyTestController@update and WeeklyTestUpdateTest exist. Safe-now migrations: additive indexes; widen student_requests.national_id; sanctum prune schedule. Next: generated-column uniques + CHECKs, RESTRICT FKs, center_id on record tables (backfill), audit_logs, backups. Later: enum code migration, surahs table + surah_number FK, age/birth_date normalization, TIME/VARCHAR(20) tightening.
