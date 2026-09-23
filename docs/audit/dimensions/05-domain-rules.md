# Domain Model & Business Rules

[← Enterprise Audit](../enterprise-audit.md)

**Score 64 / 100** — Needs real work · maturity **L3** · weight 8%

The core Quran-domain rules are genuinely well done: SurahReference is a complete, programmatically-verified 114-surah table with the juz-2/juz-5 gap handled, unit-tested, and used as the single source for validation, filters, seeders and the reverse-order (30→1) progress that every report consumes; DisplayCode reservation is atomic and tested; ParentResolver, PhoneNumber, LoginEmail and Percentage exist as single sources; memorization validation (surah-from-reference, pages 1–604, juz-within-surah-range) is enforced server-side with Arabic 422s; attendance has a DB unique key and a 409-confirm flow; the week and timezone rules are explicit. What keeps it out of the 75+ band: the invariants are enforced mostly at create-time in controllers and can be bypassed through update/reactivation paths (center is_active via generic PUT skips the token cascade; manager reactivation skips the one-active-manager rule; teacher center move orphans students); the two singleton-per-center rules have different semantics and the primary-teacher rule is implemented three ways with a lock in only one path and no DB constraint; the transfer state machine has no lock on approve, no cancel state, no decision audit and weak re-validation; messaging threads are keyed by student only and leak prior teacher↔parent conversations to a new teacher; phone normalization is applied inconsistently and never validated; the attendance-rate metric has two different formulas (API vs n8n); there are zero enums/value objects/FormRequests/Policies with Arabic literals baked into DB enums; and the docs (CLAUDE.md) have drifted from the code on several rules. Maturity 3 (defined): rules are documented in code and covered by 38 feature-test files, but they are not centralized as a domain layer, not measured, and several are inconsistently applied.

## What is already strong

- SurahReference table is complete and correct: 114 entries, juz assignments monotonic, exactly juz 2 and 5 have no starting surah (standard), juz 30 = 37 surahs (78–114), juz 29 = 11, juz 28 = 9 (verified by regex-parsing backend/app/Support/SurahReference.php:20-44). Spot-checked البقرة→1, آل عمران→3, النساء→4, المائدة→6, الأنعام→7, الأعراف→8, الأنفال→9, هود→11, يوسف→12, الكهف→15, الأحزاب→21, الصافات→23, الملك→29, النبأ→30, الناس→30 — all standard. Multi-juz surahs are assigned their *starting* juz; build() (lines 130-144) makes juz 2 and 5 depend on البقرة/النساء; juzRangeOf() (214-225) derives [start, next-surah-start] for validation (البقرة 1..3, النساء 4..6).
- Reverse-order memorization (30→1) is applied through one function everywhere: SurahReference::progress() (157-207) computes contiguous completed juz from 30 downward and is the only progress source in MemorizationController::studentsProgress (100), StudentController::teacherDetails (403), ReportService::progressSummary/centerManagement (289, 329, 429) and CenterManagerController. The unreliable memorizations.juz column is never used for logic — only displayed (frontend-html/teacher/student.html:51,140; manager/reports.html:227).
- SurahReference has real unit tests: tests/Unit/SurahReferenceJuzGapTest.php asserts all-114 → completed_count 30 / down_to 1, namesOfJuz(2)=['البقرة'], namesOfJuz(5)=['النساء'], and that removing البقرة stops the chain at 3 (28 complete) and removing النساء stops at 6 (25 complete).
- Memorization store validation is server-side and reference-driven: surah must be Rule::in(SurahReference::SURAHS) (MemorizationController.php:133), pages 1–604 with page_to gte page_from (136-137), juz must fall inside juzRangeOf(surah) with a specific Arabic message (153-163); covered by tests/Feature/MemorizationValidationTest.php (3 tests).
- DisplayCode reservation is atomic and per-type: `UPDATE code_sequences SET value = LAST_INSERT_ID(value + 1) WHERE name = ?` (DisplayCode.php:55-58), read back per-connection (64), reserved inside the create transaction via `creating` hooks (Student.php:42-46, User.php:139-143, Center.php:23+), preview() is read-only (32-45), parent sequence added idempotently (2026_09_11_100000_add_parent_code_sequence.php:56-58). Covered by DisplayCodeTest (6 tests) and StudentCodePreviewTest (5).
- ParentResolver is a genuine single source for guardian identity: id_number → normalized phone → email, parent-role scoped (ParentResolver.php:46-54), never overwrites an existing id_number (62-66), recovers from a unique-constraint race by re-matching (100-108); tested incl. the race (ManagerAddStudentGuardianTest::test_concurrent_parent_creation_rematches_instead_of_500).
- Attendance integrity: DB unique (student_id, date) (2024_01_01_000030_create_attendances_table.php:22); manual bulk store returns 409 with a conflict list unless confirm=true (AttendanceController.php:93-98) and writes inside one transaction (102-116); fingerprint import matches strictly by display-code number and skips inactive / out-of-scope students with explicit reasons (AttendanceImportController.php:164-202).
- Week and time rules are explicit rather than implicit: Saturday→Friday passed to Carbon explicitly (DashboardController.php:71, ReportController.php:94-95), app timezone Africa/Tripoli (config/app.php:70), n8n digest also pinned to Africa/Tripoli (n8n/mutqin-daily-attendance-digest.json:287).
- Manager scope is taken from the account, never the request, across the request flow: source center forced (StudentRequestController.php:82,172), student must belong to the manager's center (98-103) and be active (104-109), target must be another active center with an active manager (111-133), one pending transfer per student (152-161), approve/reject only by the target-center manager (199-215), target teacher re-validated to belong to the target center on approve (245-254). Covered by StudentTransferRequestTest (7 tests).
- Deactivation cascades exist and are tested: teacher/manager deactivation revokes tokens in a transaction (TeacherController.php:202-213, ManagerManagementController.php:163-174); center deactivation revokes all members' tokens (CenterController.php:258-271) and login re-checks both user and center state after the password check (AuthController.php:42-58); manager deactivation leaves requests pending and only informs admins (ManagerManagementController.php:176-194). CenterStatusTest, TeacherStatusTest, ManagerStatusTest, ManagerTeacherStatusTest cover these.
- The test suite is substantial for a project of this size: 38 feature-test files + 2 unit files, 173 test methods, including ownership, role matrix, scoping, status toggles, code reservation, phone normalization and transfer flow.

## Level-5 target state

All business rules live in an explicit domain layer — backed enums for every vocabulary (roles, teacher type, attendance status, quality, test result, request type/status with an allowed-transition map), value objects for Phone/NationalId/LoginEmail/DisplayCode, and rule classes (CenterSingletonRule, AttendanceWriter with source precedence, AttendanceRate, LibyanWeek) that are the *only* place a rule is expressed and are each unit-tested. Every invariant is enforced on every transition (create, update, reactivate, transfer) inside a transaction with the right lock and, where possible, mirrored by a DB constraint; every state change carries who/when audit and nothing domain-level is hard-deleted. Reference data (SurahReference, a complete 480-thumn athman index) is the single source for validation, filtering and progress, with progress semantics documented and versioned in the API. The storage encoding is stable ASCII codes with Arabic labels rendered at the edge, so the web and mobile clients share one contract, and the CLAUDE.md/API docs are regenerated from (or tested against) the code so they cannot drift.

## What the Flutter team must know

The mobile team can rely on the server to enforce the Quran rules (surah list from GET /memorizations/surahs, pages 1–604, juz-within-surah, reverse-order progress fields reached_juz/reached_juz_done/last_surah/completed_count/completed_down_to) and should never compute progress client-side or trust memorizations.juz. Things they must know now: (1) several domain values are Arabic strings used as storage codes — users.type 'محفظ أساسي'/'محفظ معاون', weekly test result 'ناجح'/'راسب' (questions too) — so model them as opaque constants and push for ASCII codes before v1 freezes; attendance status, quality, request status/type are ASCII. (2) Phone: send exactly what the user typed; the server normalizes on *create* but not on student *update* — until fixed, normalize to 09xxxxxxxx on the client for PUT /students/{id}, and expect no shape validation. (3) Student age is a static integer field, not derived from birth_date — design the student form for birth_date now to avoid a later migration of the mobile model. (4) Messaging threads are per student, not per (student, teacher); after a teacher change the thread history shifts — design the thread screen around a server-provided thread id, expected to change. (5) Weekly-test thumn is free text with an autocomplete endpoint (/athman/search); there is no athman_id yet — store the chosen athman locally if you want structured data. (6) Attendance percentage: the API defines it as present/(present+absent+late); do not replicate the n8n formula. (7) Week is Saturday→Friday and 'today' is Africa/Tripoli — use the server's `date`/`startOfWeek` fields rather than device-local dates. (8) Transfer requests have only pending/approved/rejected; there is no cancel endpoint — do not build a 'withdraw' button until the API adds it. (9) Login accepts display code (P1/T1/CA1) or email in the `email` field; parents get P-codes only if created after 2026-09-11.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [Messaging threads are keyed by student only, so a new teacher inherits (and 'owns') the previous teacher's conversation with the parent](#message-thread-keyed-by-student-leaks-across-teacher-change) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [One-primary-per-center is implemented three ways, locked in only one path, with no DB constraint](#primary-teacher-rule-triplicated-and-racy) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [One-manager-per-center and one-primary-per-center use different active semantics; reactivation bypasses both](#singleton-rules-inconsistent-and-reactivation-bypass) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | S |
| [Transfer approval is not serialized, re-validates too little, has no cancel state and no decision audit](#transfer-approve-unlocked-underrevalidated-no-cancel) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | M |
| [PUT /centers/{id} accepts is_active from the client and skips the token-revocation cascade; centers have no status audit](#center-update-bypasses-deactivation-cascade) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Phone numbers are normalized on some writes but not others, and PhoneNumber never validates the Libyan shape](#phone-normalization-inconsistent-and-unvalidated) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [The 'attendance percentage' has two different definitions (API vs n8n digest) and the role of 'late' is implicit](#attendance-rate-formula-divergence) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Fingerprint import silently overwrites manual and manager-corrected attendance; manual changes have no audit; no late/schedule rule](#attendance-source-precedence-undefined) | 🟡 medium | ✅ confirmed | NEXT | M |
| [Domain vocabulary is string literals (incl. Arabic literals in DB enums); zero PHP enums, FormRequests or Policies](#no-enums-value-objects-or-request-objects) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | L |
| [students.age is a static integer that never advances; birth_date exists but is unused](#student-age-static-birthdate-dead) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Teacher center change orphans students; update paths accept client email; admin teacher deactivation ignores primary and students](#teacher-move-and-email-edit-break-invariants) | 🟡 medium | ✅ confirmed | NEXT | M |
| [Memorization is the one record still hard-deleted, has no update, no audit, and unvalidated hizb/eighth/future date/inactive student](#memorization-hard-delete-no-update-weak-guards) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Weekly-test questions store the thumn as free text, not an athman reference; athman index has 477 of 480 rows](#weekly-test-thumn-free-text-athman-incomplete) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | LATER | M |
| [Legacy 'add' requests with a new guardian can no longer be approved (ParentResolver now requires a password)](#legacy-add-approval-dead-end) | ⚪ low | ℹ️ informational | LATER | S |
| [Juz completeness counts only surahs that *start* in the juz; portions extending in from the previous surah are ignored](#juz-completeness-starting-surah-semantics) | ⚪ low | ℹ️ informational | LATER | S |

### Messaging threads are keyed by student only, so a new teacher inherits (and 'owns') the previous teacher's conversation with the parent

<a id="message-thread-keyed-by-student-leaks-across-teacher-change"></a>

`message-thread-keyed-by-student-leaks-across-teacher-change` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/MessageController.php:26-49`, `backend/app/Http/Controllers/Api/MessageController.php:115-130`, `backend/app/Models/Message.php:13-19`, `backend/database/migrations/2026_08_22_100000_create_messages_table.php:16-27`, `backend/app/Http/Controllers/Api/StudentController.php:611-683`, `backend/app/Http/Controllers/Api/StudentRequestController.php:322-330`

**Evidence**

```text
Thread fetch is `Message::where('student_id', $student->id)` (MessageController:120) and ownership is `'mine' => $m->sender_role === $myRole` (126), not sender_id. The schema has no teacher_id/thread_id (migration:16-27). changeTeacher (StudentController:663-666) and transfer approve (StudentRequestController:323-328) reassign teacher_id without touching messages.
```

**Why it matters**

After a manager reassigns a student or approves a transfer, the new teacher can read every message the parent exchanged with the former teacher, and the former teacher's messages render as the new teacher's own. This is a privacy defect that the mobile app would surface to many more users.

**Recommendation**

Introduce a thread identity of (student_id, teacher_id) — e.g. a `message_threads` table or a `teacher_id` column on messages — and compute `mine` by sender_id. On teacher change, close the old thread (read-only for the parent, invisible to the new teacher) and open a new one. Define this in the API contract before the mobile messaging screen is built.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The evidence is accurate. MessageController::resolveStudent (lines 26-49) authorizes by the student's CURRENT teacher_id/parent_id only; thread() fetches Message::where('student_id', ...) (line 120) and computes 'mine' => $m->sender_role === $myRole (line 126), not by sender_id. The messages table (migration lines 16-27) has student_id, sender_id, sender_role, body, read_at — no teacher_id or thread id. StudentController::changeTeacher (lines 663-666) and StudentRequestController approve transaction (lines 323-328) update teacher_id/center_id and never touch messages. No mitigation exists: MessagingTest.php has no teacher-change scenario (tests only cover ownership 403s, role gates, and orphan 422), ManagerChangeTeacherTest.php does not reference messages, and frontend teacher/messages.html renders bubbles purely from m.mine (line 79) and even shows the read receipt "قُرئت ✓" on messages the new teacher never sent. So after reassignment/transfer the new teacher sees the whole prior conversation and the former teacher's messages are attributed to them; the former teacher correctly loses access (resolveStudent rejects them). Also the code comments in the controller/migration state the intent as "one conversation per PAIR" bound to a child, while the implementation keys by student only — a design/implementation mismatch, not a deliberate handover feature.

Severity is overstated, however. All parties who can ever see a thread are staff of the same organization who legitimately serve that specific child, plus the child's own parent; there is no exposure to an unrelated third party, no privilege escalation, and the former teacher cannot read anything after the change. Continuity of a child's conversation history to the new teacher is arguably acceptable or even desirable in a small memorization-center context; the concrete defect is the misattribution of authorship ('mine' by role) and the cross-center visibility on transfers. That is a medium correctness/privacy issue, not high.

```text
backend/app/Http/Controllers/Api/MessageController.php:42 (authorizes by current student.teacher_id only), :120 (thread = Message::where('student_id')), :126 ('mine' => sender_role === $myRole, sender_id ignored); backend/database/migrations/2026_08_22_100000_create_messages_table.php:16-27 (no teacher_id/thread column); backend/app/Http/Controllers/Api/StudentController.php:663-666 and backend/app/Http/Controllers/Api/StudentRequestController.php:323-328 (teacher_id/center_id reassigned, messages untouched); frontend-html/teacher/messages.html:79-81 (bubble side and "قُرئت ✓" receipt driven solely by m.mine); backend/tests/Feature/MessagingTest.php (no teacher-change or transfer scenario); backend/tests/Feature/ManagerChangeTeacherTest.php (no message assertions). Controller docblock (MessageController.php:13-14) and migration docblock (:8-10) state "محادثة واحدة لكل زوج" (one thread per pair) while the key is student_id only.
```

</details>

### One-primary-per-center is implemented three ways, locked in only one path, with no DB constraint

<a id="primary-teacher-rule-triplicated-and-racy"></a>

`primary-teacher-rule-triplicated-and-racy` · 🟠 high (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Support/PrimaryTeacherRule.php:84-101`, `backend/app/Http/Controllers/Api/TeacherController.php:79`, `backend/app/Http/Controllers/Api/TeacherController.php:159`, `backend/app/Http/Controllers/Api/CenterManagerController.php:135-144`, `backend/app/Http/Controllers/Api/CenterManagerController.php:412`, `backend/app/Http/Controllers/Api/TeacherController.php:237-242`, `backend/app/Http/Controllers/Api/CenterManagerController.php:70-71`

**Evidence**

```text
PrimaryTeacherRule::assert runs a plain `->exists()` with no lock (lines 90-94). Admin create calls it *before* the transaction: `$this->assertSinglePrimary($request->center_id, $request->type);` (TeacherController:79) then `DB::transaction(...)` (82). The manager path does NOT call the rule; it re-implements it inline with `->lockForUpdate()->first()` inside the transaction (CenterManagerController:136-143). Both update paths (TeacherController:159, CenterManagerController:412) call the unlocked rule outside any transaction. Two more read-only copies of the query exist in hasPrimary (TeacherController:239-242) and myCenter (CenterManagerController:70-71). `grep -rn "محفظ أساسي" app | wc -l` → 13 sites. No migration adds a unique/generated-column constraint.
```

**Why it matters**

Two concurrent admin creates/updates can produce two primaries for one center; the 'single source' comment in PrimaryTeacherRule is false in practice, so future edits will diverge. At dozens of centers with managers and admins editing concurrently this will eventually corrupt the invariant that reports and the manager dashboard rely on.

**Recommendation**

Move the lock into PrimaryTeacherRule (open a transaction, `lockForUpdate` the center's teachers, then assert) and call it from all four write paths; delete the inline copy. Add a DB guarantee: a generated column `primary_center_id = IF(type='محفظ أساسي', center_id, NULL)` with a UNIQUE index (MySQL 5.7+/8), so the rule holds even if code is bypassed. Expose has_primary from the same rule class.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The core factual claims hold on inspection. PrimaryTeacherRule::assert (backend/app/Support/PrimaryTeacherRule.php:24-28) is a plain `->exists()` with no lock and no transaction. Admin create (TeacherController.php:79) calls it BEFORE the DB::transaction at line 82, so check-then-insert is not atomic. Both update paths (TeacherController.php:159, CenterManagerController.php:412) call the unlocked rule outside any transaction. The manager create path (CenterManagerController.php:135-144) does not use the rule class; it re-implements the check inline with lockForUpdate inside the transaction. Two read-only copies of the same query exist (TeacherController hasPrimary 239-242, CenterManagerController myCenter 70-71). No migration adds any unique/generated-column constraint on (center_id, type) — grep of database/migrations finds none. Feature tests (ManagerAddTeacherTest etc.) cover the sequential rule, not concurrency.

However the severity is over-rated. (1) The auditor's line references for PrimaryTeacherRule.php (84-101, 90-94) are wrong — the file is 36 lines; the query is at 24-28. (2) The manager path IS locked: under InnoDB REPEATABLE READ, `SELECT ... FOR UPDATE` that finds no row still takes gap/next-key locks (and with no index on center_id/type it scans and locks broadly), so concurrent manager creates for the same center serialize in practice. The only truly racy write paths are the admin create and the two updates. (3) The race window is milliseconds and needs two privileged users (admin/manager) simultaneously flipping a primary for the SAME center — in this product there is one system admin and at most one manager per center, so the realistic attacker/collider set is ~2 people. (4) Consequence of a violation is cosmetic/business: hasPrimary/myCenter use `first()`, so the UI just shows one of two primaries; no security, auth, money, or data-loss impact, and it is trivially repairable by an admin edit. (5) `php artisan serve` (the documented dev server) is single-threaded, eliminating the race entirely in that setup; only Apache/multi-worker production is exposed.

The maintainability point (rule "single source" is not actually single — one inline write copy plus two read copies) is legitimate but is a code-quality item. Real but low-severity: a defensive DB constraint (generated column + unique) and moving the lock into the rule class are cheap hygiene, not an urgent enterprise risk.

```text
backend/app/Support/PrimaryTeacherRule.php:24-28 — unlocked `->exists()` (auditor cited 84-101/90-94; file is 36 lines). backend/app/Http/Controllers/Api/TeacherController.php:79 — `$this->assertSinglePrimary(...)` runs before `DB::transaction` at :82 (race window on admin create). TeacherController.php:159 and CenterManagerController.php:412 — updates call the unlocked rule with no transaction. CenterManagerController.php:135-144 — manager create uses inline `->lockForUpdate()->first()` inside `DB::transaction` (this path IS serialized by InnoDB gap locks; not racy as the finding implies). Read-only duplicates: TeacherController.php:239-242 (hasPrimary), CenterManagerController.php:70-71 (myCenter). database/migrations/ — no unique or generated-column constraint on users(center_id,type). tests/Feature/ManagerAddTeacherTest.php — covers sequential rule only, no concurrency test.
```

</details>

### One-manager-per-center and one-primary-per-center use different active semantics; reactivation bypasses both

<a id="singleton-rules-inconsistent-and-reactivation-bypass"></a>

`singleton-rules-inconsistent-and-reactivation-bypass` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/ManagerManagementController.php:24-37`, `backend/app/Http/Controllers/Api/ManagerManagementController.php:152-181`, `backend/app/Support/PrimaryTeacherRule.php:90-94`, `backend/app/Http/Controllers/Api/TeacherController.php:193-222`, `backend/app/Http/Controllers/Api/CenterManagerController.php:466-472`, `backend/app/Http/Controllers/Api/StudentRequestController.php:29-38`

**Evidence**

```text
Manager rule counts *active* managers only: `->where('is_active', true)` (ManagerManagementController:28). Primary rule counts *all* primaries including inactive (PrimaryTeacherRule:90-94 has no is_active filter). Manager toggleStatus re-activates with `$manager->update(['is_active' => $active, ...])` (164-168) and never calls assertSingleSupervisor → deactivate A, create B for the same center, reactivate A ⇒ two active managers; `activeManagerOf()` then picks an arbitrary `->first()` (StudentRequestController:34-37). Admin teacher toggleStatus (TeacherController:193-222) has no primary check at all, while the manager path refuses to deactivate the primary (CenterManagerController:468-472). ManagerStatusTest:59-62 tests reactivation only for 'can log in again'.
```

**Why it matters**

Transfer notifications and approvals can go to the wrong manager; a center whose primary was deactivated by the admin can never get a new active primary until someone edits the inactive teacher's type; the same business concept ('at most one X per center') behaves differently for the two roles, which the mobile app will have to special-case.

**Recommendation**

Define one CenterSingletonRule (parameterised by role/type) with a single documented semantic — recommended: active-only, and re-run the rule on *every* transition into active (create, update, reactivate). Add tests: reactivation-when-another-active-exists → 422 for both managers and primaries.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Every quoted evidence point checks out on the actual code. (1) ManagerManagementController::assertSingleSupervisor (lines 24-37) filters `->where('is_active', true)` and is called only from store (line 79) and update (line 117); toggleStatus (lines 152-168) updates `is_active` inside a transaction with no singleton check and returns early on `$active`. So deactivate A → create B for same center → reactivate A yields two active managers; both pass CenterManagerMiddleware (role + tokenCan('manager') + center_id), and StudentRequestController::activeManagerOf (lines 29-38) returns `->first()` arbitrarily. No DB unique constraint on (role, center_id) exists in migrations, the frontend admin/managers.html toggle (lines 131-154) only shows a confirm dialog with no active-manager check, and ManagerStatusTest lines 59-62 only asserts the re-activated manager can log in. (2) PrimaryTeacherRule::assert (lines 23-27) has no is_active filter, so an admin-deactivated primary (TeacherController::toggleStatus lines 193-222, no primary check) blocks any new primary until its type is edited; the manager path (CenterManagerController 468-472) refuses to deactivate the primary, so the two paths are indeed asymmetric. The finding is real and not mitigated anywhere. However, 'high' is over-rated: both defects require deliberate admin-only action sequences, there is no privilege escalation (a second active manager is still a legitimate manager of that same center, not a foreign one), no data loss, and each has a documented workaround (deactivate the duplicate; edit the inactive primary's type). It is a business-invariant consistency bug with operational/nuisance impact — medium.

```text
backend/app/Http/Controllers/Api/ManagerManagementController.php:24-37 (active-only check), :79 and :117 (only call sites: store/update), :152-168 (toggleStatus sets is_active with no assertSingleSupervisor; `if ($active) return;`). backend/app/Support/PrimaryTeacherRule.php:23-27 (no is_active filter). backend/app/Http/Controllers/Api/TeacherController.php:193-222 (admin toggle, no primary check). backend/app/Http/Controllers/Api/CenterManagerController.php:468-472 (manager refuses deactivating primary). backend/app/Http/Controllers/Api/StudentRequestController.php:29-38 (`->first()`). frontend-html/admin/managers.html:131-154 (no client-side guard). backend/tests/Feature/ManagerStatusTest.php:59-62 (reactivation tested only for login). No unique index on (role, center_id) in backend/database/migrations.
```

</details>

### Transfer approval is not serialized, re-validates too little, has no cancel state and no decision audit

<a id="transfer-approve-unlocked-underrevalidated-no-cancel"></a>

`transfer-approve-unlocked-underrevalidated-no-cancel` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/StudentRequestController.php:224-256`, `backend/app/Http/Controllers/Api/StudentRequestController.php:313-330`, `backend/app/Http/Controllers/Api/StudentRequestController.php:136-149`, `backend/database/migrations/2026_06_28_100000_create_student_requests_table.php:26`, `backend/database/migrations/2026_06_28_100000_create_student_requests_table.php:49`

**Evidence**

```text
`$req = StudentRequest::findOrFail($id); ... if ($req->status !== 'pending') {...422}` (226-237) runs outside the `DB::transaction` at 322 and without lockForUpdate → two concurrent approves both pass. On approve the target teacher is checked for role+center but not `is_active` (245-248), whereas managerStore requires `->where('is_active', true)` (138-140). Approve does not re-check that the student is still active, still in `from_center_id`, or that the target center is still active (313-330). Status enum is `['pending','approved','rejected']` (migration:26) — no cancelled/withdrawn state, so the source manager cannot retract a request. The only decision field is `admin_note` (migration:49); no decided_by/decided_at.
```

**Why it matters**

Double-approve of a legacy add row creates two students; a stale transfer can move a deactivated student or land one on an inactive teacher/center; managers cannot withdraw mistakes; there is no audit of who approved what when — unacceptable for an inter-center transfer of a minor's record at scale.

**Recommendation**

Wrap approve/reject in a transaction with `lockForUpdate()` on the request row and re-check status inside; re-validate student.is_active, student.center_id === from_center_id, target center active, target teacher active; add `cancelled` to the status enum with a `POST /manager/student-requests/{id}/cancel` for the requester; add decided_by/decided_at columns; rename admin_note → decision_note. Model the transitions in a small StudentRequestStatus enum with an allowed-transition map.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

Evidence verified line-by-line in backend/app/Http/Controllers/Api/StudentRequestController.php and the migration. Confirmed: (1) approve() and reject() read the row with plain findOrFail and check status !== 'pending' outside any transaction and without lockForUpdate (approve 226-237; the only DB::transaction starts at 279 for add and 322 for transfer, and neither re-reads status). Two overlapping approves of the same row both pass the guard. (2) On approve the target teacher is validated for role+center only (245-248) while managerStore requires is_active (138-140) — inconsistent. (3) Transfer approve (313-330) does not re-check student.is_active, student.center_id === from_center_id, or that the target center is still active; only student existence is re-checked. (4) status enum is ['pending','approved','rejected'] (migration:26); no cancel route exists anywhere in routes/api.php or the frontend — the source manager cannot retract. (5) Only admin_note (migration:49); no decided_by/decided_at columns in any migration; no later migration adds them. Tests (StudentTransferRequestTest) cover the happy paths and scope but not concurrency, stale-state, or audit. The frontend add-approve path (manager/requests.html:54-60) has no confirm and no button disabling, so a double-click genuinely fires two requests.

Mitigations that lower severity: only one active manager per center can approve a given row (assertManagerScope + one-manager-per-center), so the race is a self-inflicted double-submit by one person, not an attacker vector; students.national_id is UNIQUE (2026_06_21_130000 migration) so a double-approved legacy add row only duplicates when national_id is null (the second insert would otherwise throw); legacy add rows are a draining backlog; a stale transfer of a deactivated student moves a record that remains inactive (no attendance/report impact), and moving onto an inactive teacher is recoverable via the existing teacher reassignment. Under `php artisan serve` requests serialize, though Apache production does not. The audit gap (who approved, when) is real but partially inferable (only the target center's manager could have approved; updated_at marks the decision time), and the missing cancel is a feature gap rather than a defect. Overall the finding is factually correct but the "high" rating overstates realistic impact for a small, single-country deployment with one manager per center; medium is appropriate.

```text
backend/app/Http/Controllers/Api/StudentRequestController.php:226-237 (findOrFail + status check outside transaction, no lock); :245-248 (teacher check lacks is_active vs :138-140 in managerStore); :279 and :322 (DB::transaction blocks do not re-read status); :313-319 (transfer re-checks only student existence); :339-350 (reject also unlocked). backend/database/migrations/2026_06_28_100000_create_student_requests_table.php:26 (enum pending/approved/rejected), :49 (admin_note only). Mitigations: backend/database/migrations/2026_06_21_130000_add_national_id_to_students.php:17 (students.national_id UNIQUE blocks duplicate add-approval when national_id is set); frontend-html/manager/requests.html:54-60 (no double-submit guard on add approve — confirms the race is reachable by one user). No cancel route in backend/routes/api.php; no decided_by/decided_at in any migration.
```

</details>

### PUT /centers/{id} accepts is_active from the client and skips the token-revocation cascade; centers have no status audit

<a id="center-update-bypasses-deactivation-cascade"></a>

`center-update-bypasses-deactivation-cascade` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/CenterController.php:233`, `backend/app/Http/Controllers/Api/CenterController.php:42`, `backend/app/Http/Controllers/Api/CenterController.php:249-280`, `backend/app/Models/Center.php:9-16`

**Evidence**

```text
`$center->update($request->only(['name', 'city', 'address', 'phone', 'is_active']));` (CenterController:233) and the same on create (42). The cascade ('إبطال جلسات منتسبي المركز فوراً', members' `tokens()->delete()`) lives only in toggleStatus (258-271). `grep -rn status_changed database/migrations` shows the audit columns exist for users and students only — none for centers. Center `phone` is stored raw (no PhoneNumber::normalize) and `name` has no uniqueness rule.
```

**Why it matters**

An admin (or a compromised admin token) can deactivate a center through the generic update and its teachers/manager keep valid tokens until expiry (7 days) — the exact gap the S1 mechanism was built to close. No record of who deactivated a center or when.

**Recommendation**

Remove is_active from the fillable/only() list in store/update so state changes go only through toggleStatus; add status_changed_by/at to centers (same pattern as users/students); normalize center phone; consider unique(name, city).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Evidence verified. `CenterController::update` (line 233) and `store` (line 42) both mass-assign `$request->only([... 'is_active'])`, and `is_active` is in `Center::$fillable` (Center.php:9-16), so a PUT /centers/{id} body with `is_active:false` flips the center without the token-revocation loop that exists only in `toggleStatus` (lines 249-280). No middleware re-checks the center's `is_active` per request — the only center-active check is at login (AuthController.php:52), so existing tokens of the center's teachers/manager remain usable until Sanctum expiry (7 days). `grep is_active|status_changed database/migrations` confirms centers have only the boolean from the 2024 create migration and no `status_changed_by/at` audit columns. Tests (CenterStatusTest) cover only the `/status` route, not the generic update path. Mitigations that lower severity: (1) the route is admin-gated (role + `tokenCan('*')`), so the only actor able to trigger it is the fully trusted system admin — a compromised admin token can already do far worse directly; (2) the admin UI (frontend-html/admin/centers.html:24-29) sends only name/city/address/phone on edit and uses the `/status` endpoint for toggling, so the gap is not reachable through the shipped client; (3) reactivation via the update path is harmless. The phone-normalization and name-uniqueness remarks are accurate but cosmetic. Real, cheap to fix (drop `is_active` from `only()`), but the practical exposure — a trusted admin hand-crafting an API call and then the center's own staff working for up to 7 more days — is low, not medium.

```text
backend/app/Http/Controllers/Api/CenterController.php:42 and :233 — `$request->only(['name','city','address','phone','is_active'])`; :258-271 — cascade (`$m->tokens()->delete()`) only inside toggleStatus. backend/app/Models/Center.php:9-16 — `is_active` fillable. backend/app/Http/Controllers/Api/AuthController.php:52 — center-active check occurs only at login, no per-request middleware check (grep of app/Http/Middleware for is_active: no hits). database/migrations/2024_01_01_000010_create_centers_table.php:17 — only `is_active` boolean; no status audit columns for centers. Mitigation: frontend-html/admin/centers.html:24-29 edit form fields exclude is_active; :104 toggles via `/centers/{id}/status`. tests/Feature/CenterStatusTest.php covers only the /status path.
```

</details>

### Phone numbers are normalized on some writes but not others, and PhoneNumber never validates the Libyan shape

<a id="phone-normalization-inconsistent-and-unvalidated"></a>

`phone-normalization-inconsistent-and-unvalidated` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/StudentController.php:523`, `backend/app/Http/Controllers/Api/StudentController.php:550-553`, `backend/app/Http/Controllers/Api/StudentRequestController.php:282`, `backend/app/Support/PhoneNumber.php:123-148`, `backend/app/Http/Controllers/Api/TeacherProfileController.php:49-56`

**Evidence**

```text
Store normalizes: `'phone' => PhoneNumber::normalize($request->phone)` (StudentController:255,261). Update stores raw: `'phone' => $request->phone,` (523, admin) and `'phone' => $request->phone, ... 'guardian_phone' => $request->guardian_phone,` (550-553, teacher). Legacy add approval writes `'phone' => $req->phone` raw (StudentRequestController:282). PhoneNumber::normalize strips non-digits and maps 00218/218 prefixes but returns any digit string (e.g. '5') and does not add the leading 0 for a 9-digit '912345678' (lines 136-147). TeacherProfileController promises 'أدخل رقم هاتف ليبي صحيح' (54) yet only checks `!$normalized`.
```

**Why it matters**

Guardian de-duplication and the `q=` phone search rely on stored phones being in 09xxxxxxxx form; a single edit through the update path breaks matching (siblings get two parent accounts). Mobile clients will submit contact-picker formats (+218 9x…, spaces, 9-digit) far more often than the web form did.

**Recommendation**

Make PhoneNumber a value object with `normalize()` + `isValidLibyanMobile()` (`^09[1-5]\d{7}$`, and map 9-digit `9xxxxxxxx` → `09xxxxxxxx`); apply it via Eloquent mutators on User.phone, Student.phone, Student.guardian_phone, Center.phone so every write path is covered; return a 422 on invalid shape.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The code-level evidence is real but the impact is materially overstated and one file citation is wrong. Confirmed: StudentController@update writes `students.phone` raw (line 523 admin, 550 teacher) and `students.guardian_phone` raw (553); StudentRequestController legacy approval writes `students.phone`/`guardian_phone` raw (282, 288); CenterController create/update passes `centers.phone` via `$request->only` unnormalized (42, 233); PhoneNumber::normalize (file is 38 lines, not 123-148) never validates the Libyan shape and does not prepend 0 to a 9-digit number. However the headline impact — "siblings get two parent accounts" — is false: guardian de-duplication (ParentResolver:34,50,59,90) and the OTP lookup (AuthController:132,184) operate on `users.phone`, and EVERY write path to `users.phone` normalizes: ParentResolver, TeacherController:86/164, CenterManagerController:151/417, ManagerManagementController:86/122, TeacherProfileController:49-58. The student update paths never touch the parent User row, and `students.guardian_phone` is display-only by design (CLAUDE.md; StudentController:261 comment). StudentRequestController:404 also routes guardian_phone through ParentResolver (normalized). Tests already cover the de-dup and OTP formats (PhoneNormalizationTest: variants, same-guardian-different-formats, OTP international). What remains real: (a) a student's own `students.phone` edited via update in "+218 91 …" form will not match the `q=` digit search at StudentController:127 (LIKE on normalized digits vs. raw stored string) — a cosmetic search miss; (b) centers.phone unnormalized — display-only; (c) no shape validation, so garbage like "5" is stored and the TeacherProfileController message over-promises. These are consistency/data-quality issues for a single-country Arabic tool, not a business-rule break. Severity should be low.

```text
Raw writes confirmed: backend/app/Http/Controllers/Api/StudentController.php:523 ('phone' => $request->phone, admin update), :550-553 (phone + guardian_phone, teacher update); backend/app/Http/Controllers/Api/StudentRequestController.php:282,288; backend/app/Http/Controllers/Api/CenterController.php:42,233 ($request->only([... 'phone' ...])). PhoneNumber is backend/app/Support/PhoneNumber.php:12-38 (not :123-148) — no shape validation, no 9-digit handling. Mitigations the finding misses: all writes to users.phone (the de-dup/OTP key) are normalized — ParentResolver.php:34,59,90; TeacherController.php:86,164; CenterManagerController.php:151,417; ManagerManagementController.php:86,122; TeacherProfileController.php:49-58; lookups normalized at AuthController.php:132,184 and ParentResolver.php:50. students.guardian_phone is display-only (StudentController.php:261 comment). Covered by tests/Feature/PhoneNormalizationTest.php (test_same_guardian_in_different_formats_maps_to_one_parent_account, test_otp_request_matches_international_format). Residual real effect: students.phone edited in +218 form misses the q= search at StudentController.php:127.
```

</details>

### The 'attendance percentage' has two different definitions (API vs n8n digest) and the role of 'late' is implicit

<a id="attendance-rate-formula-divergence"></a>

`attendance-rate-formula-divergence` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Services/ReportService.php:42-67`, `backend/app/Services/ReportService.php:233-239`, `backend/app/Http/Controllers/Api/StudentController.php:420`, `backend/app/Http/Controllers/Api/StudentController.php:721`, `n8n/attendance-digest.code.js:16-17`

**Evidence**

```text
Backend everywhere: `attendancePercent => $this->pct($present, $total)` where `$total = $present + $absent + $late` (ReportService:45,67) — late counts against the student; 19 sites use a present-only numerator (`grep -rn "status = .present.\|'status', 'present'" app | wc -l` → 19), 29 Percentage/pct call sites. n8n: `const rate = total ? Math.round(((present.length + late.length) / total) * 100) : 0;` with `total = students.length` (attendance-digest.code.js:16-17) — late counts as attended and the denominator is all active students including unrecorded ones. At-risk threshold `ATTENDANCE_THRESHOLD = 70` (ReportService:22) is applied to the first definition only.
```

**Why it matters**

A center manager will see one attendance rate in the daily digest email and a different one in the app for the same day; the at-risk classification depends on which definition is 'true'. The mobile team has no authoritative definition to implement.

**Recommendation**

Define AttendanceRate once (recommend: numerator present+late or present only — decide with the centers; denominator = recorded rows, with 'unrecorded' reported separately), implement it in one PHP class, expose the computed value in the JSON payloads the digest consumes, and make n8n display rather than compute.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The quoted evidence is accurate. Backend: every attendance percentage goes through App\Support\Percentage::of(present, total) where total = recorded rows (present+absent+late) — ReportService::studentData (lines 42-45, 67), atRiskStudents (233-235, threshold 70 at line 22), StudentController lines 420 and 721, CenterManagerController lines 348/359. So the backend is internally consistent: late counts against the student, unrecorded students are excluded. n8n/attendance-digest.code.js:16-17 genuinely uses (present+late)/students.length — late counts as attended and unrecorded active students are in the denominator. Two definitions exist and no PHP or test enforces one; there is no feature test covering any attendance percentage. However the finding is over-rated: (1) the n8n workflow is an optional, separately-imported demo add-on (n8n/README.md: plaintext password 'for demo convenience', commit 'credentials as placeholders'), not a shipped API surface; (2) the app never displays a per-day attendance rate (DashboardController exposes only today_present/today_late counts), so the claimed 'same day, two numbers' comparison does not actually arise — the digest rate is a daily center snapshot, the app rates are per-student period aggregates; (3) the at-risk threshold is applied only to the backend formula, which is the single authoritative one for classification, so classification is not ambiguous in practice. Real inconsistency worth a one-line fix in the n8n Code node (or documenting the definition), but low severity.

```text
backend/app/Support/Percentage.php:12 — single backend formula round(part/total*100). Call sites all pass present-only numerator over recorded rows: backend/app/Services/ReportService.php:42-45,67 and :233-235; backend/app/Http/Controllers/Api/StudentController.php:420,721; backend/app/Http/Controllers/Api/CenterManagerController.php:314,348,359. Divergent: n8n/attendance-digest.code.js:16-17 (present+late over students.length). No daily rate in app: backend/app/Http/Controllers/Api/DashboardController.php:29-30,65-67 expose counts only. n8n/README.md marks the workflow as a demo/optional integration. No tests reference Percentage/attendancePercent (grep of backend/tests returns nothing).
```

</details>

### Fingerprint import silently overwrites manual and manager-corrected attendance; manual changes have no audit; no late/schedule rule

<a id="attendance-source-precedence-undefined"></a>

`attendance-source-precedence-undefined` · 🟡 medium · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/AttendanceImportController.php:300-313`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:341-378`, `backend/app/Http/Controllers/Api/AttendanceController.php:61-69`, `backend/app/Http/Controllers/Api/AttendanceController.php:102-116`, `backend/app/Http/Controllers/Api/CenterManagerController.php:580`

**Evidence**

```text
Import: `Attendance::updateOrCreate(['student_id'=>..., 'date'=>...], [..., 'status' => $status, 'notes' => 'حضور مستورد...', 'imported_at' => now()])` (300-313) — no check of an existing manual/corrected row, no confirm flag, corrected_by/at left stale. Manual store requires `confirm=true` on conflicts (AttendanceController:93-98) but the resulting `updateOrCreate` (104-114) does not set corrected_by/at; only the manager's correctAttendance does (CenterManagerController:580). Manual store silently `continue`s on invalid status or unauthorized student (62-69) instead of reporting. Auto-absent marks every scoped active student absent for each date in the file (341-378). `late` is never derived from `time`; a punch at any hour is `'present'` (281). Center has no schedule/late-threshold field (Center.php:9-16).
```

**Why it matters**

A manager's deliberate correction is lost on the next re-upload with no trace; a teacher can change a status with confirm=true and leave no audit; students not expected that day are marked absent; 'late' is meaningless for imported data. At dozens of centers with different session times, attendance analytics will be unreliable.

**Recommendation**

Define a precedence rule (corrected > manual > imported) and enforce it in one AttendanceWriter: imports never overwrite a row with corrected_at, and overwriting a manual row requires an explicit flag and is reported in the import summary; stamp corrected_by/at on every status change; return skipped rows in the manual store response; add per-center `session_start`/`late_after_minutes`/`working_days` and derive `late` from `time`.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Traced the actual paths. Import (AttendanceImportController.php:300-313) uses updateOrCreate on (student_id,date) and unconditionally writes status/notes/time/imported_at with no check of corrected_at and no reset of corrected_by/at, so a manager correction is silently overwritten and the row is left with a stale corrected_at that the manager review endpoint (CenterManagerController.php:548) still displays. Manual store (AttendanceController.php:104-114) writes only teacher_id/center_id/status: it stamps no corrected_by/at even when confirm=true, and does not clear imported_at, so a teacher override of an imported row still reports source=fingerprint. Silent `continue` on invalid status/unauthorized student (62-69) and the auto-absent loop (firstOrCreate, 341-378) behave as quoted; `late` is never derived from `time` (281); Center model/migrations have no schedule/late-threshold fields. No feature test covers import overwriting a corrected or manually-entered row (AttendanceDuplicationTest covers manual-vs-manual conflicts only; ManagerAttendanceReviewTest covers the correction write only). Partial mitigations: auto-absent never overwrites existing rows (firstOrCreate); the import summary does return an `updated` count that the UI shows (ui.js:286), so re-uploads are not fully invisible; the late/schedule item is a feature gap rather than a defect. The precedence/audit-loss part is a real data-integrity issue in the intended manager-correction workflow, so medium is appropriate — not high (no security impact, small-team product, attendance is re-uploadable/re-correctable) and not low (a documented audit feature is defeated silently).

```text
backend/app/Http/Controllers/Api/AttendanceImportController.php:300-313 — updateOrCreate writes status/notes/time/imported_at with no corrected_at guard and leaves corrected_by/at unchanged (stale). backend/app/Http/Controllers/Api/CenterManagerController.php:547-548 — review row derives source from imported_at and shows corrected_at, so the overwritten row misleadingly displays as corrected. backend/app/Http/Controllers/Api/AttendanceController.php:104-114 — manual updateOrCreate sets only teacher_id/center_id/status (no corrected_by/at, imported_at not cleared). backend/app/Http/Controllers/Api/AttendanceImportController.php:341-378 — auto-absent uses firstOrCreate (never overwrites, partial mitigation). frontend-html/js/ui.js:286 — import summary surfaces `updated` count (partial mitigation). backend/app/Models/Center.php:9-16 — no schedule fields. Tests: backend/tests/Feature/AttendanceDuplicationTest.php and ManagerAttendanceReviewTest.php do not cover import-over-corrected/manual rows.
```

</details>

### Domain vocabulary is string literals (incl. Arabic literals in DB enums); zero PHP enums, FormRequests or Policies

<a id="no-enums-value-objects-or-request-objects"></a>

`no-enums-value-objects-or-request-objects` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort L (1–2 weeks)

**Files:** `backend/app/Http/Controllers/Api/TeacherController.php:66`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:42`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:68`, `backend/app/Http/Controllers/Api/MemorizationController.php:138`, `backend/database/migrations/2026_05_11_100154_update_tables_for_mutqen_v2.php:23`, `backend/app/Models/User.php:188-209`

**Evidence**

```text
`grep -rln '^enum ' app | wc -l` → 0; `ls app/Http/Requests app/Policies` → neither exists. Literal counts in app/: role where-clauses 50, attendance statuses 71, 'محفظ أساسي' 13, 'ناجح'/'راسب' 19, quality 15, request statuses 10. Weekly-test result is an Arabic-valued DB enum: `$table->enum('result', ['ناجح', 'راسب'])` (v2 migration:23) and the pass rule is `collect($request->questions)->contains('result', 'راسب') ? 'راسب' : 'ناجح'` duplicated in store (68) and update (143). users.type stores 'محفظ أساسي'/'محفظ معاون'. Validation rules live inline in controllers (StudentController has ~90 lines of rules across store/update).
```

**Why it matters**

Every rule is a find-and-replace risk; a typo in one of 71 status literals is a silent bug; the mobile app must compare against Arabic display strings that are also the storage encoding, so any wording change breaks clients; there is no place to hang invariants (state transitions, allowed types) other than controllers.

**Recommendation**

Introduce backed enums (Role, TeacherType, AttendanceStatus, MemorizationQuality, TestResult, RequestType, RequestStatus) with `label()` for Arabic; store stable ASCII codes (`passed|failed`, `primary|assistant`) and migrate the two Arabic-valued columns; move validation into FormRequests (StoreStudentRequest, etc.) and ownership checks into Policies. Ship the ASCII codes in the API before the mobile client fixes its models.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Factual core is confirmed: `grep -rln '^enum ' app` → 0; `app/Http/Requests` and `app/Policies` do not exist; TeacherController.php:66 has `'type' => 'required|in:محفظ أساسي,محفظ معاون'`; WeeklyTestController.php:42 `in:ناجح,راسب` and the pass rule `contains('result','راسب') ? 'راسب' : 'ناجح'` is literally duplicated at lines 68 and 143 (update route exists at routes/api.php:164, contrary to CLAUDE.md); MemorizationController.php:138 `in:excellent,good,average,weak`; v2 migration:23 `enum('result', ['ناجح','راسب'])` and :13 `enum('type', ['محفظ أساسي','محفظ معاون'])`. Literal counts re-measured and match (13 / 19 / 52 / 71). One citation is wrong: User.php is 131 lines, so "User.php:188-209" does not exist — the role string comparisons are at lines 85-105.

However, the finding is an architecture/maintainability observation, not a demonstrated correctness defect, and it overstates impact: (1) Every vocabulary column is a MySQL ENUM (attendance status, memorization quality, users.type, weekly_tests.result, student_requests.type/status, nationality_type) and every controller input is guarded by an `in:` validation rule, so a typo'd literal on the write path fails loudly (422 or DB error under strict mode) rather than silently — only a typo in a read-side where-clause would be silent. (2) 38 feature tests (RoleMatrixTest, OwnershipTest, TeacherStatusTest, WeeklyTestUpdateTest, MemorizationValidationTest, etc.) exercise these literals, mitigating the find-and-replace risk. (3) The "mobile app must compare Arabic display strings" impact is speculative — no mobile client exists in the repo (only frontend-html). (4) Ownership checks are already centralized in middleware (bootstrap/app.php aliases) and support classes (PrimaryTeacherRule, ParentResolver), so "no place to hang invariants other than controllers" is exaggerated. Real but low-severity technical debt for a small Arabic-only team; not refuted, downgraded to low.

```text
Confirmed: backend/app/Http/Controllers/Api/TeacherController.php:66 (`in:محفظ أساسي,محفظ معاون`); WeeklyTestController.php:42, :68 and :143 (duplicated pass rule); MemorizationController.php:138; database/migrations/2026_05_11_100154_update_tables_for_mutqen_v2.php:13 and :23 (Arabic-valued ENUM columns). Corrected citation: app/Models/User.php:85-105 (role string comparisons; the file has only 131 lines, so 188-209 is invalid). Mitigations: DB ENUM constraints in migrations (attendances:16 status enum; memorizations:26 quality enum; student_requests:25-26), `in:` validation rules on every input path, middleware aliases in bootstrap/app.php, and 38 feature tests under backend/tests/Feature. No mobile client exists in the repo.
```

</details>

### students.age is a static integer that never advances; birth_date exists but is unused

<a id="student-age-static-birthdate-dead"></a>

`student-age-static-birthdate-dead` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Models/Student.php:12-17`, `backend/app/Models/Student.php:102`, `backend/app/Http/Controllers/Api/StudentController.php:205`, `backend/app/Http/Controllers/Api/MemorizationController.php:85-86`, `backend/app/Services/ReportService.php:483`

**Evidence**

```text
Student fillable includes both `'birth_date'` and `'age'` (Student.php:12,17); comment `// تم إزالة دالة حساب العمر لاستخدام حقل العمر الفعلي` (102). `grep -rn birth_date backend/app backend/routes frontend-html` returns only the model — no write path or reader. Age is validated as `'age' => 'nullable|integer|between:1,120'` (StudentController:205) and used as a filter `->where('age', (int) $request->age)` in progress reports (MemorizationController:85-86).
```

**Why it matters**

Every student's recorded age is wrong one year after enrollment; age-based progress filters and any future age-group reporting are silently incorrect; nothing recomputes it. For a system tracking children over multi-year memorization journeys this is a data-quality defect that compounds.

**Recommendation**

Collect birth_date (or birth year) and compute age as an accessor; for Libyan students the national id embeds the birth year (digits 2–5 of the 12-digit number) — offer it as a prefill, not a source of truth; backfill age→approximate birth year; remove `age` from write paths. Decide this before the mobile student form is designed.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Factually confirmed. `backend/app/Models/Student.php` lists both `birth_date` (fillable line 12, cast `'birth_date' => 'date'` line 31) and `age` (line 17), and line 102 carries the comment that the age-computation function was removed in favour of the stored age field. `grep -rn birth_date` over backend/app, backend/routes, frontend-html and backend/tests hits only the model and `LibyanDataSeeder.php:322` — no controller writes or reads it, and no frontend field exists. `age` is a client-supplied integer (`StudentController.php:205,509,541` validate `nullable|integer|between:1,120`; stored at 256/524/551) with no accessor, no scheduled job (`routes/console.php` / `app/Console` contain no schedule), and no recompute path. It is displayed as a fact in ~15 frontend pages (parent/dashboard.html:41, parent/child.html:81, teacher/students.html:84,123, manager PDFs via ReportService:499,537, etc.) and copied into transfer-request snapshots (StudentRequestController:283). So the data-quality claim stands: a stored age goes stale one year after entry unless an admin/manager edits it manually.

However the impact is overstated on one point: the age filter at `MemorizationController.php:85-86` lives on `GET /memorizations/students-progress`, an endpoint no frontend page calls (grep for `students-progress`/`studentsProgress` hits only `routes/api.php`), so "age-based progress filters are silently incorrect" affects no shipped UI. Age is otherwise purely informational display, there is no age-gated business logic (enrollment eligibility, grouping, pricing) anywhere, and update paths let the manager/admin correct it. This is a real but cosmetic/data-hygiene defect for a small single-country deployment — low, not medium. The recommendation (collect birth_date, derive age, optional national-id prefill) is sound; CLAUDE.md itself already acknowledges "birth_date ... is largely vestigial — age is the field actually used", so the project knows about the inconsistency.

```text
backend/app/Models/Student.php:12,17,31,102 — birth_date fillable+date cast but unused; age fillable; comment on removed age computation. backend/app/Http/Controllers/Api/StudentController.php:205,256,509,524,541,551 — age accepted from client as static integer on create/update; no accessor or recompute. backend/database/seeders/LibyanDataSeeder.php:322 — only writer of birth_date (seed data). backend/app/Http/Controllers/Api/MemorizationController.php:85-86 — age filter on /memorizations/students-progress, but no frontend-html page references students-progress (grep hits only backend/routes/api.php), so the filter is dormant. Display sites: frontend-html/parent/dashboard.html:41, parent/child.html:81, teacher/students.html:84,123, teacher/student.html:75, manager/students.html:137, admin/students.html:393, backend/app/Services/ReportService.php:483,499,537 (PDF).
```

</details>

### Teacher center change orphans students; update paths accept client email; admin teacher deactivation ignores primary and students

<a id="teacher-move-and-email-edit-break-invariants"></a>

`teacher-move-and-email-edit-break-invariants` · 🟡 medium · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/TeacherController.php:150-174`, `backend/app/Http/Controllers/Api/TeacherController.php:152`, `backend/app/Http/Controllers/Api/CenterManagerController.php:401`, `backend/app/Http/Controllers/Api/CenterManagerController.php:416`, `backend/app/Http/Controllers/Api/StudentController.php:203`, `backend/app/Support/LoginEmail.php:157-163`

**Evidence**

```text
Admin update writes `'center_id' => $request->center_id` (TeacherController:165) with no handling of `$teacher->students` — the invariant 'teacher belongs to the student's center' is enforced only at student create/update via `Rule::exists('users','id')->where('center_id', $request->input('center_id'))` (StudentController:203, 511). Both update paths validate and store a client-supplied email: `'email' => 'required|email|unique:users,email,'...` (TeacherController:152; CenterManagerController:401) → `'email' => $request->email` (416), while create generates `{latin}_{code}@mutqin.ly` and LoginEmail's docblock says 'لا يُقبل من العميل' (157-163). Admin toggleStatus (TeacherController:193-222) has neither the primary guard the manager path has (CenterManagerController:468-472) nor any treatment of the teacher's students.
```

**Why it matters**

Students end up assigned to a teacher in another center, breaking center-scoped reports, manager scope checks (which trust student.center_id) and attendance center_id; generated login identities can be rewritten arbitrarily; a deactivated teacher keeps active students who then vanish from the teacher-facing flows without appearing in `students_without_teacher`.

**Recommendation**

On teacher center change either refuse while students are assigned or unassign them (set teacher_id null, former_teacher_name) in the same transaction; drop `email` from update validation/fillable (or restrict to the prefix and regenerate via LoginEmail); on teacher deactivation, unassign students (or require reassignment) and apply the same primary guard as the manager path.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

All three factual claims verified by reading the code. (1) TeacherController::update (lines 146-188) validates and writes `center_id => $request->center_id` with no touch of `$teacher->students`; the admin edit form (frontend-html/admin/teachers.html:42-46) exposes a center_id select, so this path is reachable from the UI. No test in tests/Feature covers a teacher center move, and the only place the 'teacher belongs to student center' invariant is enforced is Student store/update validation (StudentController:203, 511) and the manager reassign endpoint (641-652). Students of a moved teacher keep student.center_id = old center and teacher.center_id = new center — a real, unguarded inconsistency that the center-scoped manager/report queries will surface oddly. (2) Both update paths accept a client `email` (TeacherController:152/163; CenterManagerController:401/416) and both edit forms send it (admin/teachers.html:42, manager/teachers.html:27), contradicting LoginEmail's docblock (line 12 'لا يُقبل من العميل') and the create paths which use email_prefix. GeneratedLoginEmailTest only covers create, not update. However, only trusted actors (admin, own-center manager) can do this and uniqueness is still validated, so this is a convention breach rather than a security hole. (3) Admin toggleStatus (TeacherController:193-222) has no primary-teacher guard and does not unassign students; the manager path (CenterManagerController:468-472) does guard. The manager error message ('...أو راجع مدير النظام') suggests the admin lacking the guard is partly intentional as an override, so that sub-point is weaker than the auditor implies. Students of a deactivated teacher keep teacher_id and are excluded from students_without_teacher (CenterManagerController:35) — confirmed. Net: finding is factually accurate; the center-move gap is genuine data-integrity debt, the email and admin-guard points are lower-impact. Medium is a fair aggregate rating given only trusted admin/manager actors can trigger any of it.

```text
backend/app/Http/Controllers/Api/TeacherController.php:150-167 (center_id and email taken from request, students untouched); frontend-html/admin/teachers.html:42-46 (edit form sends email + center_id); backend/app/Http/Controllers/Api/CenterManagerController.php:401,416 (manager update accepts email); frontend-html/manager/teachers.html:27; backend/app/Support/LoginEmail.php:12 (docblock: email not accepted from client); backend/tests/Feature/GeneratedLoginEmailTest.php:44-66 (covers create only, no update test); backend/app/Http/Controllers/Api/TeacherController.php:193-222 vs CenterManagerController.php:468-472 (admin toggle lacks primary guard — possibly intentional per manager error text 'راجع مدير النظام'); CenterManagerController.php:35 (students_without_teacher counts only teacher_id NULL).
```

</details>

### Memorization is the one record still hard-deleted, has no update, no audit, and unvalidated hizb/eighth/future date/inactive student

<a id="memorization-hard-delete-no-update-weak-guards"></a>

`memorization-hard-delete-no-update-weak-guards` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/MemorizationController.php:208-227`, `backend/app/Http/Controllers/Api/MemorizationController.php:128-138`, `backend/app/Http/Controllers/Api/MemorizationController.php:175-187`, `backend/routes/api.php:160-164`

**Evidence**

```text
`$memorization->delete();` (221) — `grep -rn -e '->delete()' app` shows this is the only domain hard-delete left after weekly-tests moved to update-only ('لا حذف للاختبار ... DELETE يعيد 405', routes:162-164) and memorizations are still `->only(['index','store','destroy'])` (160). Validation covers student_id/date/surah/juz/pages/quality (128-138) but `hizb`, `eighth`, `notes` are mass-assigned from the request unvalidated (181,184); `date` has no `before_or_equal:today`; `student_id` is only `exists:students,id` — an inactive student can receive new memorization.
```

**Why it matters**

Teachers correct mistakes by deleting and re-entering, which destroys history and the parent's `memorization_added` notification trail; there is no record of who deleted what; the deletion policy the product decided on ('activate/deactivate replaces deletion') is applied inconsistently across record types.

**Recommendation**

Replace destroy with update (and/or a soft `voided_at/voided_by`), mirroring WeeklyTestController::update; validate hizb 1–60, eighth against the athman index or an enum, date ≤ today (Tripoli), and require student.is_active for new records.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 75% · corrected severity: low

Every factual claim in the finding checks out. backend/app/Http/Controllers/Api/MemorizationController.php:221 is `$memorization->delete();` and it is indeed the only remaining domain hard-delete (the other `->delete()` hits are OtpReset rows in AuthController and the child-row replace in WeeklyTestController::update). routes/api.php:160 keeps memorizations at `only(['index','store','destroy'])` while line 162-164 documents the deliberate no-delete/update-only decision for weekly-tests, so the inconsistency with the product's stated 'deactivate replaces deletion' policy is real. Validation (lines 128-138) covers student_id/date/surah/juz/pages/quality only; `hizb`, `eighth`, `notes` are copied from the request into `Memorization::create` (lines 181, 184, 186) with no rules, all three are in `$fillable` (Memorization.php:9-21); `date` is `required|date` with no upper bound; `student_id` is only `exists:students,id` with no is_active check (AttendanceController by contrast filters `is_active` at lines 17/130). No feature test asserts any of these (MemorizationValidationTest covers surah/juz/pages only). Mitigations that reduce (not remove) the impact: destroy has a proper ownership guard (own students or admin, line 214), so this is a data-governance/audit gap, not an authorization hole; the frontend form (teacher/memorization.html) does not expose hizb/eighth at all and its student picker uses the default active-only student list, so the unvalidated fields and inactive-student path are reachable only via direct API calls by an authenticated teacher; `hizb` is an INTEGER column so garbage would surface as a DB error rather than silently persist (eighth is a free string). The future-date gap, however, is reachable from the UI (the date input has no max). Because the actors are trusted, scoped teachers acting on their own students and there is no security impact, medium overstates it; it is a legitimate low-severity consistency/audit and input-hygiene item.

```text
backend/app/Http/Controllers/Api/MemorizationController.php:128-138 (rules omit hizb/eighth/notes, no before_or_equal:today, no is_active); :181,184,186 (hizb/eighth/notes mass-assigned raw); :214-221 (owner/admin guard then `$memorization->delete()`); backend/app/Models/Memorization.php:9-21 (all three fillable); backend/routes/api.php:160 vs 162-164; backend/database/migrations/2024_01_01_000040_create_memorizations_table.php:20,23 (hizb integer nullable, eighth string nullable); frontend-html/teacher/memorization.html:14 (date input, no max), :28 (`/students?all=1` -> active-only default via StudentController.php:95-100), :87 (UI delete button calls DELETE /memorizations/{id}); no hizb/eighth fields in the UI form.
```

</details>

### Weekly-test questions store the thumn as free text, not an athman reference; athman index has 477 of 480 rows

<a id="weekly-test-thumn-free-text-athman-incomplete"></a>

`weekly-test-thumn-free-text-athman-incomplete` · 🟡 medium (reviewers → low) · ✅ confirmed · **LATER** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/WeeklyTestController.php:41`, `backend/database/migrations/2026_06_19_074100_create_weekly_test_questions_table.php:18`, `backend/database/seeders/AthmanSeeder.php:12`, `frontend-html/teacher/weekly-tests.html:75-91`

**Evidence**

```text
`'questions.*.eighth_start' => 'required|string'` (WeeklyTestController:41); schema `$table->string('eighth_start'); // بداية الثمن المختبر فيه` (migration:18) — no athman_id FK. The UI autocomplete (`UI.attachAthmanSearch(startInput, ...)`, weekly-tests.html:91) is a helper, not a constraint. AthmanSeeder docblock: 'بذر فهرس الأثمان (477 ثمناً)' (12) vs 60 hizb × 8 = 480; CLAUDE.md notes the index covers only 92 surahs.
```

**Why it matters**

Test results cannot be correlated with juz/hizb/page or with memorization progress (no 'tested on what he memorized' analytics); typos fragment reporting; the mobile app cannot render a structured thumn picker from the record. A 3-row gap in the reference index means some thumns can never be selected from the index.

**Recommendation**

Add `athman_id` (nullable FK) to weekly_test_questions, populate from the autocomplete selection, keep `eighth_start` as display text; complete the athman index to 480 and reconcile surah names with SurahReference; later derive 'tested juz' for reports.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 75% · corrected severity: low

Every factual claim checks out. WeeklyTestController::store (line 41) and ::update (line 137) validate `questions.*.eighth_start` as `required|string` only; the migration creates `eighth_start` as a plain string with no athman FK, and no later migration adds `athman_id` (grep over migrations/models/services finds none). The frontend autocomplete (`UI.attachAthmanSearch`, ui.js:303-347) merely fills the input with the picked `start_text`; when nothing matches it explicitly says "لا توجد أثمان مطابقة — يمكنك الكتابة يدوياً", so free text is an intended UX path, not a slip. Parsing the actual seed workbook (database/data/فهرس_الأثمان_الكامل.xlsx, sheet 'كل الأثمان') confirms exactly 477 data rows across 60 hizbs; hizb 35, 41 and 44 each lack thumn 8, so three thumns can never be picked from the index (the 92-surah count is expected — short surahs that do not begin a thumn have no row — and is not itself a defect). No feature test covers athman linkage. However, the finding is over-rated: `eighth_start` is consumed only as display text (StudentController:441/471/810, teacher/student.html:39, teacher/students.html:57); ReportService and ReportPdfController never read it, so nothing currently breaks or miscomputes. The impact is purely future analytics/data-hygiene debt plus a 3-row reference gap with a trivial workaround (type the text manually). No security, integrity, or functional loss today; the 'mobile app' impact is speculative. Real but low.

```text
backend/app/Http/Controllers/Api/WeeklyTestController.php:41 and :137 — `'questions.*.eighth_start' => 'required|string'` (store and update). backend/database/migrations/2026_06_19_074100_create_weekly_test_questions_table.php:18 — `$table->string('eighth_start')`, no athman FK; no later migration adds one. frontend-html/js/ui.js:324 — autocomplete fallback text "لا توجد أثمان مطابقة — يمكنك الكتابة يدوياً" (free text is deliberate); ui.js:334 only sets `input.value = it.start_text`, never an id. database/data/فهرس_الأثمان_الكامل.xlsx parsed: 477 rows, 60 hizbs, 92 surahs; hizb 35, 41, 44 each have thumn 1-7 only (thumn 8 missing) — matches AthmanSeeder.php:12 docblock. Consumers of eighth_start are display-only: StudentController.php:441,471,810; frontend-html/teacher/student.html:39; teacher/students.html:57. No reference in app/Services/ReportService.php or ReportPdfController.php.
```

</details>

### Legacy 'add' requests with a new guardian can no longer be approved (ParentResolver now requires a password)

<a id="legacy-add-approval-dead-end"></a>

`legacy-add-approval-dead-end` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/StudentRequestController.php:257-295`, `backend/app/Http/Controllers/Api/StudentRequestController.php:394-409`, `backend/app/Support/ParentResolver.php:72-77`, `backend/app/Http/Controllers/Api/StudentRequestController.php:239`

**Evidence**

```text
resolveParentId passes no `password` (401-408) and ParentResolver throws `ValidationException ['guardian_password' => ['كلمة مرور ولي الأمر مطلوبة لإنشاء حسابه']]` when creating (73-77); approve only validates `'target_teacher_id' => 'nullable|integer'` (239) so the manager has no way to supply one. The docblock at 396 still says 'إنشاء بكلمة عشوائية' (random password) — stale.
```

**Why it matters**

Any remaining pending add rows whose guardian is not already a parent account return 422 forever; managers can only reject them and re-enter the student manually. Low because add rows are legacy and draining.

**Recommendation**

Either accept `guardian_password` in the approve body for type=add, or auto-reject/flag legacy add rows with an explanatory note; fix the stale comment.

### Juz completeness counts only surahs that *start* in the juz; portions extending in from the previous surah are ignored

<a id="juz-completeness-starting-surah-semantics"></a>

`juz-completeness-starting-surah-semantics` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `backend/app/Support/SurahReference.php:114-145`, `backend/app/Support/SurahReference.php:243-256`, `backend/app/Http/Controllers/Api/MemorizationController.php:30-32`

**Evidence**

```text
build() populates `$juzToNorms[$juz][] = $norm` for each surah's *starting* juz only (121-128) and adds the extended surah only for juz with no starting surah (133-144). isJuzComplete(N) therefore requires e.g. juz 3 = {آل عمران} but not البقرة 253–286, juz 16 = {مريم, طه} but not الكهف 75–110. `?juz=3` filter → `whereIn('surah_name', namesOfJuz(3))` returns only آل عمران records (MemorizationController:31). Because completed_count is contiguous from 30 downward, the missing surah is caught one juz later, so the count over-reports by at most one partial surah; `reached_juz_done` can be true while part of that juz is unmemorized.
```

**Why it matters**

Small, systematic over-reporting of 'juz N done' and of `completed_count` at the frontier; explains why a student with all surahs except البقرة shows 28 complete (test asserts this) although juz 3 also contains البقرة verses. Acceptable while the unit of memorization is the surah, but must be documented for the mobile team and for any future page/thumn-level model.

**Recommendation**

Document the semantic ('juz N complete ⇔ all surahs *beginning* in N recorded'); optionally include the preceding surah in `juzToNorms[N]` when its range extends into N (derivable from juzRangeOf) for stricter completeness; long-term move progress to page/thumn granularity using the athman index.

## Measured facts

| Metric | Value |
|---|---|
| SurahReference entries | 114 (verified: monotonic juz; juz 2 and 5 have no starting surah; juz 30 = 37 surahs, 29 = 11, 28 = 9) |
| Support/domain helper classes | 8 files, 645 LOC (SurahReference 257, ParentResolver 111, ArabicText 70, DisplayCode 66, LoginEmail 50, PhoneNumber 39, PrimaryTeacherRule 36, Percentage 16) |
| PHP enums / FormRequests / Policies / Observers | 0 / 0 / 0 / 0 |
| String-literal rule sites in app/ | role where-clauses 50; attendance status 71; 'محفظ أساسي' 13; 'ناجح\|راسب' 19; quality 15; request status 10 |
| Percentage/pct call sites | 29 (19 with present-only numerator) |
| is_active filters in app/ | 40 |
| Week-rule sites (Carbon::SATURDAY) | 2 (DashboardController:71, ReportController:94) |
| National-id regex sites | 2 (StudentController:38, 50) |
| Remaining domain hard-delete endpoints | 1 (DELETE /memorizations/{id}) |
| Paths enforcing one-primary-per-center | 4 write paths, 3 implementations, 1 with lockForUpdate, 0 DB constraints |
| Feature/unit test files and methods | 38 feature files + 2 unit files; 173 feature test methods (CLAUDE.md says 20 files) |
| Athman index rows | 477 (seeder docblock) vs 480 expected (60 hizb × 8) |
| Controller size hot-spots | StudentController 851 LOC (25 commits), CenterManagerController 683, ReportService 562, StudentRequestController 454 (12 commits) |
| Migrations | 35 (latest 2026_09_11 parent code sequence) |
| Repository commits | 224 (last 2026-09-11) |
| Local toolchain state | C:\xampp\php\php.exe absent; Herd PHP 8.4 present; backend/vendor absent → tests not runnable in this audit |

## Auditor notes

Additional material findings not in the top-15 list: (a) DashboardController::index counts all teachers incl. inactive (`User::where('role','teacher')->count()`, line 22) while students/centers are active-only — inconsistent 'active' semantics on the admin dashboard. (b) Admin PUT /students/{id} can change center_id directly (StudentController:525), bypassing the transfer workflow and leaving a pending transfer that can still be approved from the old center. (c) Student toggleStatus (571-602) does not cancel pending transfer requests for that student. (d) ParentResolver::resolve on an existing parent overwrites `name`/`phone` with the latest sibling's guardian data (57-67) — last writer wins across siblings. (e) DisplayCode's row lock on code_sequences is held for the whole create transaction, serializing all creates of a type system-wide — fine now, a throughput ceiling later; MySQL-only (LAST_INSERT_ID). (f) Attendance/memorization `date` and weekly `exam_date` accept future dates. (g) Student/User $fillable include display_code, is_active, status_changed_* — safety relies on controllers passing explicit arrays. (h) `student_requests.admin_note` naming survives from the admin-era design. (i) StudentController::NATIONAL_ID_PRESENCE const is a code-edit toggle rather than config. (j) Revision and TajweedEvaluation: tables, models and Student relations exist (Student.php:85-93) with no controller/route/UI/report usage — modeled-but-dead, and CLAUDE.md correctly flags them. Documentation drift observed in CLAUDE.md vs code, relevant to this dimension: parents now get P{n} display codes (User.php:140, DisplayCode.php:23, migration 2026_09_11) — doc says 'admin + parent have none'; national_id regex accepts 1 (male) or 2 (female) (StudentController:29,38) — doc says 'male format'; ParentResolver requires an explicit guardian_password (73-77) — doc says 'random password on create', and StudentRequestController:396 comment repeats the stale claim; parent/manager login emails are `{latin}_{p-code|ca-code}@mutqin.ly` (LoginEmail.php:159, ManagerManagementController:52-53) — doc says `{latin}.{id}@domain` and `{latin}.centeradmin@mutqin.ly`; weekly-tests expose index/store/show/update with DELETE → 405 (routes:164) — doc says index/store/destroy/show and 'no update'; weekly_tests.test_type/passed were dropped in the v2 migration (2026_05_11:22) — doc says they still overlap with result; manager routes now include /manager/students/{id}/teacher, /manager/parents, /manager/teachers/{id}/status and /manager/reports/{center,teacher,student}; teacher routes include /students/{id}/details, /students/{id}/day and messaging; login accepts display codes (AuthController:36). Environment: PHP is not at C:\xampp (Herd PHP 8.4 exists) and backend/vendor is absent, so the test suite could not be executed during this read-only audit — coverage claims are based on reading the tests.
