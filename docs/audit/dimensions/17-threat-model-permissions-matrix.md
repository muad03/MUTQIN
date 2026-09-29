# Threat Model, Permissions Matrix & Tenant-Isolation Assurance

[← Enterprise Audit](../enterprise-audit.md)

**Score 46 / 100** — Significant risk · maturity **L2** · weight 3%

The prevention layer is genuinely above average for a project of this size: four role+ability gates, center_id always taken from the account, 43 in-controller role checks, 13 teacher_id ownership checks, 11 explicit center-scope 403s, token revocation on deactivation/password change, 63 forbidden assertions across 173 tests. Judged on implementation alone it would land in the mid-60s. But this dimension is about ASSURANCE: there is no threat model, risk register, abuse-case list, incident-response plan or disclosure channel anywhere (0 hits for threat/incident/disclosure across all 7 doc files, 537 lines; backend/README.md is Laravel boilerplate whose security section points to taylor@laravel.com); the security model is unmonitorable (4 Log:: calls in the whole app, none for auth or privilege events, 0 Policies/Observers/Events, LOG_LEVEL=error in prod); 28/107 routes have no test at all and 24 more have no negative assertion; and a systematic pass finds a high-severity cross-tenant write path (any center manager can enumerate every parent's national ID system-wide via 3-digit prefix search, then overwrite that parent's name and recovery phone and attach a child to them, receiving the full parent record incl. email/phone/id_number in the 201 response), a cross-center student-roster oracle in the xlsx import, message-history inheritance across teacher/center reassignment, an orphaned public user-directory endpoint gated only by APP_DEBUG, a bot running as the human admin with a wildcard token, and parent accounts that nobody can disable. 'Significant risk' band (40-59) is the honest placement; maturity 2 (repeatable patterns in code and tests, but no defined product artefact and no measurement/detection).

## What is already strong

- Dual role+ability gate is real and tested: AdminMiddleware.php:17 `!$user->isAdmin() || !$user->tokenCan('*')`, CenterManagerMiddleware.php:20 adds `|| !$user->center_id`; RoleMatrixTest::test_parent_scoped_token_cannot_reach_admin_routes_even_if_role_tampered (line 53) and CenterManagerTest::test_manager_token_cannot_escalate_even_if_role_tampered (line 51) flip users.role after token issue and still get 403.
- Tenant scope is derived from the account, never the request, in every manager write path: StudentController.php:167 `$request->merge(['center_id' => $user->center_id])`, CenterManagerController.php:149-150 `'role' => 'teacher' ... 'center_id' => $centerId`, ReportPdfController.php:150-167 all manager PDFs use `$request->user()->center_id`; StudentCodePreviewTest:64-71 and ManagerAddTeacherTest:40-44 prove a forged center_id lands in the manager's own center.
- Token revocation is wired into every lifecycle event and tested: User::recordPasswordChange() (User.php:81 `$this->tokens()->delete()`), TeacherController@toggleStatus:210-212, ManagerManagementController@toggleStatus:174, CenterController@toggleStatus:255-259 revokes all members; TeacherStatusTest::test_deactivation_revokes_tokens_immediately, ManagerStatusTest::test_deactivation_revokes_tokens_and_records_audit, CenterStatusTest::test_deactivating_center_blocks_members_and_revokes_their_tokens, TeacherProfileTest::test_password_change_requires_current_and_revokes_tokens.
- Login hardening basics are in place and tested: unified error for unknown id/wrong password (AuthController.php:76-83, AuthLoginTest:92), inactive-account and inactive-center checks only after password verification (AuthController.php:42-58), throttle:10,1 on login and 5/10 on OTP (routes/api.php:16-21, AuthLoginTest::test_login_is_throttled_after_10_attempts), OTP hashed with bcrypt, 10-minute expiry, 5 attempts, neutral response, dev_otp gated on environment('local') not APP_DEBUG (AuthController.php:137-156, OtpResetTest 4 tests).
- Data-minimising payloads exist where the author thought about it: AdminUserController.php:26 selects explicit columns and the test asserts 'no sensitive fields'; managerSearchParents returns only name/id_number/children_count (StudentController.php:299-303, asserted in ManagerAddStudentGuardianTest:207); parentChildren/parentStudentDetails are curated arrays without national_id (StudentController.php:687-706, 782-796); teacherDetails deliberately omits guardian fields (StudentController.php:392-397).
- Hard deletion has been removed for every principal (routes/api.php:96,100,141,164 `except(['destroy'])`/`only([...])`, WeeklyTestUpdateTest::test_delete_route_is_gone_405) and replaced by toggles carrying `status_changed_by/at` audit columns (StudentController.php:592-596, CenterManagerController.php:446-450) plus attendance `corrected_by/at` (CenterManagerController.php:539-543).
- Production seeding does not bake a password: [redacted].php:24-25 reads ADMIN_INITIAL_PASSWORD from env and refuses < 8 chars; .env.production.example ships APP_DEBUG=false, LOG_LEVEL=error, CORS_ALLOWED_ORIGINS closed by default; backend/.htaccess denies the whole Laravel tree with public/.htaccess as the only grant.
- Every tenant-scope refusal is an explicit, consistent Arabic 403 in the controller rather than an implicit empty result (11 sites, e.g. CenterManagerController.php:429 'خارج نطاق صلاحيتك', StudentController.php:585 'هذا الطالب ليس من طلاب مركزك', StudentRequestController.php:214-224 assertManagerScope), which makes a permissions matrix reconstructable from code at all.

## Level-5 target state

A versioned threat model and permissions matrix are the source of truth: every route in routes/api.php maps to exactly one cell (resource x role x scope x exposed fields), a table-driven test suite generated from that matrix runs positive, foreign-tenant and role-tamper variants for all 107 routes (and fails when a route is missing from the matrix), and every cross-tenant read is explicit, minimised and consented. Tokens carry least-privilege abilities per role and per automation, parents/managers have full self-service credential lifecycle, and any principal can be disabled and its sessions revoked from the admin UI. Security events (auth, privilege, scope refusals, exports) are recorded with actor attribution and surfaced as metrics and alerts, so the model is measured, not just enforced; the incident-response runbook names who revokes what, how, and how guardians of affected minors are notified within a fixed SLA.

## What the Flutter team must know

Roles map 1:1 to screen sets (admin/center_manager/teacher/parent from POST /auth/login → data.user.role); the token carries abilities ['*'|'manager'|'parent'] and expires after 7 days (config/sanctum.php:55) with no refresh endpoint — the app must handle 401 by clearing secure storage and re-authenticating, and must send Accept: application/json (ReportPdfController.php:84 uses abort(403) which otherwise renders HTML). Login accepts email OR display code (T1/CA1/P1), so the login field is 'البريد أو كود الدخول'. Per-IP throttling (10/min) will misfire behind Libyan carrier NAT — expect spurious 429s and design retry/backoff and a per-account lockout story with the backend team. Parents and managers currently have NO change-password/phone endpoints (only teachers/admin via /profile*), and no user can list or revoke sessions — these screens cannot be built until the lifecycle finding is fixed. Messaging threads are keyed by student and inherit history on teacher change; the mobile UI must not imply per-teacher privacy until the thread model changes. Notifications are polled (no push, layout.js polls every 60s) and the PDF endpoints stream binary. Store the token in Keychain/Keystore, pin the mutqin.ly certificate, and never log request bodies (they contain national IDs and guardian phones). Field-level minimisation differs per endpoint (GET /students/{id} returns national_id/guardian_phone; /students/{id}/details omits guardian) — the mobile team should treat student payloads as sensitive and avoid caching them at rest.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [No threat model, risk register, abuse-case list, incident-response plan or vulnerability-disclosure channel exists](#no-security-governance-artefacts) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Any center manager can enumerate every parent's national ID system-wide, then overwrite that parent's name and recovery phone and attach a child to them, receiving the full parent record](#manager-parent-directory-enumeration-and-overwrite) | 🟠 high | ✅ confirmed | NOW | M |
| [Security model is prevention-only: no login audit, no failed-login counter per account, no alert on privilege changes, no actor on password-change log](#no-security-event-logging-or-detection) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Login identifiers are sequential public codes, passwords are min 6 with no policy or MFA, throttling is per-IP only, and teacher tokens carry the admin wildcard ability](#enumerable-login-ids-no-lockout-weak-passwords-wildcard-teacher-tokens) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Parent accounts cannot be disabled or reset by anyone; parents and managers have no in-app password/phone change; managers have no recovery path at all](#parent-manager-account-lifecycle-gaps) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Parent↔teacher message history is keyed by student only, so a reassigned or transferred student hands the whole private thread to the new teacher — including across centers](#message-thread-inherited-on-reassignment) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | M |
| [Unauthenticated /api/public/demo-accounts dumps every user's name/email/role whenever APP_DEBUG=true — and the frontend no longer uses it](#public-demo-accounts-orphan-endpoint) | 🟡 medium | ✅ confirmed | NOW | S |
| [28 of 107 routes have no test and 24 more have no negative assertion; the matrix has whole cells with no proof of denial](#tenant-isolation-negative-test-gaps) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | M |
| [Fingerprint xlsx import echoes the name and code of students from other centers, letting any teacher or manager dump the system-wide roster](#xlsx-import-cross-center-name-oracle) | 🟡 medium | ✅ confirmed | NEXT | S |
| [n8n digest logs in as the human admin with a plaintext password, holds a 7-day '*' token it never revokes, and exports minors' national IDs and guardian phones daily](#automation-uses-human-admin-wildcard-token) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [A center manager can silently set any of their teachers' passwords and free-form emails, then read parent↔teacher messages as that teacher; the audit log records it as 'admin'](#manager-teacher-password-reset-no-actor-audit) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Weekly-test edits are owned by the authoring teacher (not the current one) so a former teacher — even in another center after transfer — can rewrite results; memorization hard-deletes and grade rewrites leave no audit](#weekly-test-and-memorization-write-ownership-drift-no-grade-audit) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Generic admin PUTs bypass the deactivation and tenant invariants: PUT /centers/{id} flips is_active without revoking member tokens, and moving a teacher to another center leaves their students (and message threads) behind](#admin-update-paths-bypass-lifecycle-invariants) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Phone is the single recovery factor but is changeable without re-authentication or verification, and the plaintext OTP is written to the log unconditionally](#otp-recovery-path-weaknesses) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Exactly one super-admin account, created only by seeder, with no MFA, no OTP recovery, no second admin path and its initial password handed over in chat](#single-admin-no-mfa-no-break-glass) | 🟡 medium | ✅ confirmed | NEXT | M |

### No threat model, risk register, abuse-case list, incident-response plan or vulnerability-disclosure channel exists

<a id="no-security-governance-artefacts"></a>

`no-security-governance-artefacts` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `CLAUDE.md:1`, `DEPLOYMENT.md:1`, `backend/DEPLOY_LOG.md:1`, `backend/README.md:52`, `frontend-html/README.md:43`, `n8n/README.md:17`

**Evidence**

```text
`grep -rniE 'threat|risk register|abuse case|incident|disclosure|security.txt|breach|runbook' CLAUDE.md DEPLOYMENT.md backend/DEPLOY_LOG.md backend/README.md frontend-html/README.md n8n/README.md _handoff2/untitled/README.md` returns nothing (7 files, 537 lines). backend/README.md:52-54 is the untouched Laravel boilerplate: 'If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell'. DEPLOYMENT.md's only security content is a 9-row pre-production checklist; frontend-html/README.md:43-46 has a 3-bullet 'الأمان' section. No SECURITY.md, no .well-known/security.txt (`.cpanel.yml:5` even excludes `.well-known` from deploy), no privacy notice for guardians' and minors' data.
```

**Why it matters**

Every abuse case found so far (parent enumeration, demo-accounts dump, message inheritance, admin bot token) was discovered ad hoc. Without a stated model there is no way to know when the matrix is complete, no owner for a breach involving minors' national IDs, and no channel for a parent or researcher to report a leak. A public app-store listing will also require a security contact and privacy policy.

**Recommendation**

Write and version four short artefacts under docs/security/: (1) THREAT_MODEL.md — trust boundaries (public, parent, teacher, manager/tenant, admin, n8n, xlsx, OTP, cPanel host) with STRIDE table and residual-risk owner; (2) PERMISSIONS_MATRIX.md — the resource x role table from this audit, kept in sync by a test that asserts every route belongs to exactly one declared cell; (3) INCIDENT_RESPONSE.md — the runbook in notes (who revokes which tokens how, breach-notification template for guardians, 72h target); (4) SECURITY.md + public/.well-known/security.txt with a contact address. Add a 'risk register' section to DEPLOY_LOG.md entries that touch auth or scope.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

The factual core of the finding is confirmed: the cited grep over CLAUDE.md, DEPLOYMENT.md, backend/DEPLOY_LOG.md, backend/README.md, frontend-html/README.md, n8n/README.md and _handoff2/untitled/README.md (530 lines total) returns zero hits; backend/README.md:52-54 is the untouched Laravel boilerplate pointing security reports to taylor@laravel.com; there is no SECURITY.md, no .well-known/security.txt anywhere in the tree (neither backend/public nor frontend-html), no privacy notice string ('خصوصية'/'privacy') in any frontend page or JS; DEPLOYMENT.md is only a pre-production checklist ('قائمة ما قبل الإنتاج'); frontend-html/README.md:43-46 'الأمان' is three bullets about token storage/401/requireAuth. So it cannot be refuted as false.

However, it is over-rated and partly mischaracterized. (1) This is a documentation/governance gap, not a code defect: no execution path is affected, no data is exposed, and nothing in the codebase behaves differently for lack of these files. 'High' in a code-security audit implies an exploitable weakness; this is a process finding that should sit at medium at most. (2) The .cpanel.yml remark ('even excludes .well-known from deploy') is misread: `rsync -a --delete --exclude='.well-known'` protects the host-managed .well-known directory (e.g. ACME/Let's Encrypt challenges) from being wiped by --delete; it does not prevent the site from having a security.txt — it simply means one would be placed on the host rather than in the repo. (3) The permissions-matrix half of the recommendation is partially already handled: CLAUDE.md documents the dual role+token-ability model and a full route-by-gate table, and backend/tests/Feature contains ~30 test files (AuthLoginTest, OwnershipTest, CenterManagerTest, ManagerReportsScopeTest, ManagerStatusTest, CenterStatusTest, etc.) that assert the role/scope matrix, which is the executable form of a permissions matrix — what is missing is a test that every route belongs to exactly one declared cell, not the matrix itself. (4) Product context (small team, single-country, Arabic-only, admin-provisioned accounts, no public signup) reduces the practical urgency of a researcher-disclosure channel, though the minors'/guardians' national-ID data does make a privacy notice and an incident owner genuinely worth having. Net: real but exaggerated; corrected severity medium.

```text
Confirmed: grep -rniE 'threat|risk register|abuse case|incident|disclosure|security.txt|breach|runbook' over the 7 cited files → 0 hits (530 lines). backend/README.md:52-54 = Laravel boilerplate ('send an e-mail to Taylor Otwell'). No SECURITY.md / .well-known/security.txt in repo (find over tree, backend/public and frontend-html both lack .well-known). No 'خصوصية'/'privacy' in frontend-html/*.html or js/*.js. DEPLOYMENT.md:1,5 is only 'قائمة ما قبل الإنتاج'; frontend-html/README.md:43-46 'الأمان' = 3 bullets. Mischaracterized: .cpanel.yml:5 `--exclude='.well-known'` is an rsync --delete guard preserving host-managed .well-known (ACME), not a block on publishing security.txt. Partially mitigated: CLAUDE.md documents the role+tokenCan dual-gate model and a route/gate table; backend/tests/Feature (AuthLoginTest, OwnershipTest, CenterManagerTest, ManagerReportsScopeTest, ManagerStatusTest, CenterStatusTest, ManagerTeacherStatusTest, MessagingTest, ParentChildPaginationTest …) already assert the permissions matrix in executable form; routes/api.php has ~99 Route:: declarations that a completeness test could enumerate.
```

</details>

### Any center manager can enumerate every parent's national ID system-wide, then overwrite that parent's name and recovery phone and attach a child to them, receiving the full parent record

<a id="manager-parent-directory-enumeration-and-overwrite"></a>

`manager-parent-directory-enumeration-and-overwrite` · 🟠 high · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/StudentController.php:280`, `backend/app/Http/Controllers/Api/StudentController.php:196`, `backend/app/Http/Controllers/Api/StudentController.php:271`, `backend/app/Support/ParentResolver.php:50`, `backend/app/Models/User.php:43`, `backend/tests/Feature/ManagerAddStudentGuardianTest.php:30`

**Evidence**

```text
StudentController.php:288-303 `if (strlen($digits) < 3) return []; $parents = User::where('role','parent')->where('id_number','like',$digits.'%')->orderBy('id_number')->limit(10)` — no center scope; Libyan IDs start with 1 or 2, so walking prefixes 100..299 pages the whole directory (name + full id_number). ParentResolver.php:50-67: match by id_number first, then `$parent->fill(['name' => $name ?: $parent->name, 'phone' => $phone ?: $parent->phone])->save()` — a manager posting `guardian_id_number=<victim id>, guardian_phone=<attacker phone>, guardian_name=<anything>` renames the parent and replaces the phone that is the ONLY OTP recovery factor (AuthController.php:132). StudentController.php:196-197 `'parent_id' => [Rule::exists('users','id')->where('role','parent')]` accepts any parent id in the system, and :271 returns `$student->load(['center','teacher','parent'])` where User::$hidden (User.php:43-46) hides only password/remember_token — so email, phone, id_number, display_code of the victim come back in the 201. ManagerAddStudentGuardianTest:30-40 codifies linking a parent who has no child in the manager's center as correct; no test asserts name/phone are untouched.
```

**Why it matters**

Horizontal tenant isolation between centers is broken for the most sensitive principal (guardians of minors): a rogue or compromised manager in center A can dump national IDs and contact data of every guardian in centers B..N, silently attach students to them, and corrupt their recovery phone. Once the planned SMS gateway ships, the phone overwrite becomes a full parent account takeover (OTP to attacker phone → password reset → children data and parent↔teacher messages across centers). Students created for this cannot be deleted, only deactivated.

**Recommendation**

(1) Scope managerSearchParents to parents who already have a child in the manager's center (reuse the whereHas('children', center) from CenterManagerController@parents) and require the full 12-digit id_number for cross-center lookups (exact match, returns only 'exists: yes/no + first name'). (2) In ParentResolver, never mutate an existing parent when the caller is a center manager; only fill NULL fields, and never change phone without an OTP to the new number. (3) Restrict `parent_id` linking for managers to parents with a child in the center or to an exact id_number provided by the guardian; return a minimal parent projection (id, name, display_code) instead of load('parent'). (4) Add negative tests: manager A searches/links/overwrites parent P who only has children in center B.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: high

Every cited code path exists and behaves as described. (1) StudentController::managerSearchParents (lines 284-310) queries `User::where('role','parent')->where('id_number','like',$digits.'%')` with no center scope, only a 3-digit minimum and `limit(10)`, no throttle on the manager route group (routes/api.php:61; only auth/OTP routes are throttled). Prefix walking enumerates name + full id_number of every parent in the system (it does NOT return phone/email — the auditor states this correctly). (2) ParentResolver::resolve (lines 46-70) matches by id_number first, then unconditionally does `$parent->fill(['name'=>$name ?: $parent->name,'phone'=>$phone ?: $parent->phone])->save()` — so a manager posting guardian_id_number=<victim> + guardian_name/guardian_phone renames the parent and replaces the phone that AuthController::forgotPasswordRequest (line 132/184) uses as the sole OTP recovery lookup. The manager UI (frontend-html/manager/students.html:283) even exposes guardian_id_number + name + phone together in "new guardian" mode, so this also happens by accident on a typo. (3) store() line 207 accepts any `parent_id` (role=parent only) and line 209 any `parent_id_number` from a manager; line 270 returns `$student->load(['center','teacher','parent'])` and User::$hidden (User.php:43-46) hides only password/remember_token, so the victim's email, phone, id_number, display_code come back in the 201. (4) No negative test exists: ManagerAddStudentGuardianTest:30-52 asserts cross-center linking as correct and never checks name/phone are untouched; ManagerParentsTest only scopes the read-only /manager/parents list (CenterManagerController::parents uses whereHas children in center), which confirms the project already has the scoping pattern but did not apply it to search/link/resolve. Mitigating context (not enough to refute): the actor is a manager (trusted staff, admin-created), sibling-across-centers linking by national ID is an intended feature, and OTP today only logs the code (no SMS), so the phone overwrite is not yet a live takeover — but PII enumeration + contact-data disclosure + integrity mutation of another tenant's guardians is real. Severity high is defensible for a tenant-isolation audit.

```text
backend/app/Http/Controllers/Api/StudentController.php:284-310 (managerSearchParents: system-wide `id_number like prefix%`, 3-digit min, limit 10, no throttle; returns name/id_number/children_count only); StudentController.php:207-209 (`parent_id`/`parent_id_number` accept any role=parent user for managers); StudentController.php:240-248,270 (ParentResolver call + `load(['center','teacher','parent'])` in 201); backend/app/Support/ParentResolver.php:46-70 (match by id_number → unconditional fill of name/phone on existing parent); backend/app/Models/User.php:43-46 ($hidden = password, remember_token only); backend/app/Http/Controllers/Api/AuthController.php:132,184 (OTP lookup by phone); backend/routes/api.php:17-21,61 (throttle only on auth routes; manager search unthrottled); backend/app/Http/Controllers/Api/CenterManagerController.php:207-216 (existing center-scoped whereHas('children') pattern not reused); frontend-html/manager/students.html:283,342-387 (UI sends guardian_id_number with name/phone); backend/tests/Feature/ManagerAddStudentGuardianTest.php:30-52,199-218 (no cross-center negative test, no assertion that existing parent name/phone are preserved).
```

</details>

### Security model is prevention-only: no login audit, no failed-login counter per account, no alert on privilege changes, no actor on password-change log

<a id="no-security-event-logging-or-detection"></a>

`no-security-event-logging-or-detection` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:39`, `backend/app/Http/Controllers/Api/AuthController.php:76`, `backend/app/Models/User.php:70`, `backend/database/migrations/2026_06_28_150000_add_password_tracking.php:23`, `backend/app/Http/Controllers/Api/ManagerManagementController.php:102`, `backend/.env.production.example:39`

**Evidence**

```text
`grep -rn 'Log::' app/` → 4 calls total (2 xlsx errors, 1 notification warning, 1 OTP info) — no login success/failure, no token issue, no logout, no status toggle, no password set by admin/manager, no center reassignment, no transfer approval, no PDF export. `ls app/Listeners app/Events app/Observers app/Policies` → none exist. password_change_logs migration :23-27 stores only user_id/changed_at/method(enum otp|self|admin) — no changed_by, and CenterManagerController.php:342 logs a MANAGER reset as method 'admin'. ManagerManagementController@update:102-141 can move a manager to another center_id and set a password with no log or notification. .env.production.example:39 `LOG_LEVEL=error` means even the 4 existing lines are silent in production. No token pruning (`grep -rn 'prune|last_used_at'` → nothing), so personal_access_tokens grows forever with no session view.
```

**Why it matters**

Credential stuffing against enumerable login ids (T1.., CA1.., P1..) or a manager abusing the directory would be invisible; an admin cannot answer 'who changed this teacher's password/center' after the fact; a breach involving minors' data could not be scoped or dated. CMMI level 4/5 requires detection and measurement, not only prevention.

**Recommendation**

Add a `security_events` table (actor_id, actor_role, event, subject_type/id, ip, user_agent, meta JSON, created_at) written by one `SecurityAudit::log()` helper from: login ok/fail (with identifier hash), logout, OTP request/verify, token revocation, every toggleStatus, every password set (add changed_by to password_change_logs), role/center reassignment, transfer approve/reject, PDF export, xlsx import. Add a per-account failed-login counter with progressive lockout and an admin notification on N failures or on any privilege change. Expose GET /admin/security-events and GET /admin/users/{id}/sessions with revoke. Schedule sanctum:prune-expired. Keep LOG_LEVEL=error but route the audit table, not the log file.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The core factual claims verify. `grep -rn 'Log::' app/` returns exactly 4 calls (AttendanceImportController:37,326; AuthController:218 OTP info; InAppNotification:60). None of app/Listeners, app/Events, app/Observers, app/Policies exist. AuthController::login (lines 39-95) issues tokens on success and returns 422 on failure with no logging and no per-account counter; logout (:100) deletes the token silently. password_change_logs migration (:23-27) has only user_id/changed_at/method — no changed_by/actor. recordPasswordChange('admin') is called from TeacherController:177, ManagerManagementController:134 AND CenterManagerController:430 (the auditor cited :342, wrong line — but the manager-as-'admin' method conflation is real). ManagerManagementController@update (:102-141) changes center_id and password without any log/notification. .env.production.example sets LOG_LEVEL=error. No prune/scheduler: routes/console.php contains only the default inspire command; `grep prune|last_used_at` empty. However the finding overstates the gap and ignores existing partial controls: (1) login is rate-limited per IP (routes/api.php:17 throttle:10,1; :20-21 for OTP) and OTP has a per-row attempts counter (otp_resets.attempts); (2) every toggleStatus writes status_changed_by/at (TeacherController:205, ManagerManagementController:166, StudentController:591, CenterManagerController:477) — so 'who deactivated X' IS answerable; (3) attendance corrections carry corrected_by/at (CenterManagerController:583); (4) password changes are logged per user with method+timestamp, and every password set/deactivation revokes all tokens (User::recordPasswordChange); (5) Sanctum tokens expire after 7 days (config/sanctum.php:55 expiration=10080), which bounds the 'session' problem even without pruning. Not a code-correctness defect — the enforcement model is correct; this is a missing detection/forensics capability. For a small single-country center-management app the realistic risk (invisible credential stuffing against short codes, no actor on password/center reassignment of managers) is real but medium, not high; the 'CMMI level 4/5' framing is not a security severity argument.

```text
Confirmed: backend/app/Http/Controllers/Api/AuthController.php:39-95 (no login success/failure logging, no per-account counter; only per-IP throttle:10,1 at routes/api.php:17); AuthController.php:100-108 logout unlogged; database/migrations/2026_06_28_150000_add_password_tracking.php:23-27 (no changed_by column); app/Models/User.php:70-82 recordPasswordChange stores only method; CenterManagerController.php:430 (not :342) logs a center-manager reset as method 'admin'; ManagerManagementController.php:102-141 center_id + password change with no audit/notification; .env.production.example:39 LOG_LEVEL=error; routes/console.php has no schedule/prune. Mitigations the finding omits: status_changed_by/at written on every toggle (TeacherController.php:205, ManagerManagementController.php:166, StudentController.php:591, CenterManagerController.php:477); corrected_by/at at CenterManagerController.php:583; otp_resets.attempts counter; config/sanctum.php:55 expiration=10080 (7-day tokens); all tokens revoked on password change/deactivation.
```

</details>

### Login identifiers are sequential public codes, passwords are min 6 with no policy or MFA, throttling is per-IP only, and teacher tokens carry the admin wildcard ability

<a id="enumerable-login-ids-no-lockout-weak-passwords-wildcard-teacher-tokens"></a>

`enumerable-login-ids-no-lockout-weak-passwords-wildcard-teacher-tokens` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:36`, `backend/app/Http/Controllers/Api/AuthController.php:62`, `backend/app/Support/DisplayCode.php:19`, `backend/app/Support/LoginEmail.php:28`, `backend/routes/api.php:17`, `backend/app/Http/Middleware/AdminMiddleware.php:17`

**Evidence**

```text
AuthController.php:36 `User::whereRaw('UPPER(display_code) = ?', [mb_strtoupper($login)])` — login accepts T{n}/CA{n}/P{n}; DisplayCode.php:19-25 makes them sequential per role, and LoginEmail.php:28 makes generated emails `{latin}_{code}@mutqin.ly` equally predictable. Password rules are `min:6` everywhere (AuthController.php:25, TeacherController.php:63, ManagerManagementController.php:67, CenterManagerController.php:127, TeacherProfileController.php:78, ParentResolver via guardian_password :207). routes/api.php:17 `throttle:10,1` is keyed by IP for unauthenticated requests — no per-account counter exists. AuthController.php:39 `if ($user && Hash::check(...))` short-circuits, giving a timing oracle for valid identifiers. AuthController.php:62-63 grants `['*']` to BOTH admin and teacher; AdminMiddleware.php:17 only distinguishes them by `isAdmin()`, so RoleMatrixTest's role-tamper test (line 53) has no teacher counterpart and would fail if written (a teacher token whose users.role is flipped passes the admin gate).
```

**Why it matters**

A public mobile client makes the API reachable from every handset: with usernames P1..Pn known and 6-char passwords, a distributed password spray is cheap and undetectable (see logging finding). Per-IP throttling is also the wrong primitive for Libyan mobile carriers (carrier-grade NAT puts hundreds of legitimate parents behind one IP → collateral lockouts). The wildcard on teacher tokens means the ability layer offers zero least-privilege for the largest staff population and cannot be used to mint scoped automation tokens.

**Recommendation**

(1) Give teacher tokens ability `teacher` and admin `admin`; change TeacherMiddleware to `tokenCanAny(['teacher','admin'])` and AdminMiddleware to `tokenCan('admin')`; add the teacher role-tamper test. (2) Add per-account lockout (e.g., 5 failures → 15 min, exponential) keyed on the resolved user id, plus a device/IP-agnostic global rate limit; keep per-IP as secondary. (3) Password policy min 10 with breached-password check (Laravel Password::min(10)->uncompromised()) for staff; enforce on next login for existing accounts. (4) Constant-time login: run Hash::check against a dummy hash when the user is not found. (5) Plan TOTP MFA for admin and managers before scaling beyond a handful of centers.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

All quoted evidence is accurate: AuthController.php:36-37 resolves login by display_code (case-insensitive) then email; DisplayCode.php:19-25 makes codes sequential per role (P{n}, T{n}, CA{n}) and User.php:37-38 assigns them to teachers, managers and parents; LoginEmail.php:28 builds emails as {latin}_{code}@mutqin.ly; every password rule is `min:6` (10 occurrences across AuthController, TeacherController, ManagerManagementController, CenterManagerController, TeacherProfileController, StudentController guardian_password) and there is no `Password::` rule or `uncompromised()` anywhere in app/; routes/api.php:17 is the only login throttle (`throttle:10,1`, default IP/user key), no RateLimiter::for definitions exist in app/ or bootstrap/, and there is no failed-attempt/lockout column or logic in the migrations, User model or AuthController; AuthController.php:39 short-circuits Hash::check when the user is missing (timing side-channel, though of limited value since codes are already enumerable by design); AuthController.php:62-63 gives `['*']` to both admin and teacher and AdminMiddleware.php:17 / TeacherMiddleware.php:17 differ only by role. The refutation attempt fails on facts. However the severity is overstated. (1) The teacher-wildcard point is a defense-in-depth gap, not an exploitable path: escalating a teacher token requires writing users.role in the DB, which already implies a full compromise; a stolen teacher token still cannot pass the admin gate because isAdmin() is checked, so the stated design goal (stop a stolen lower-role token) holds. RoleMatrixTest.php:53-60 covers only the parent tamper case, as claimed. (2) Per-IP throttling at 10/min plus bcrypt does exist, so a spray is slowed, not free; the finding's "undetectable, cheap" framing assumes a distributed attacker and a public mobile client that this repo does not yet ship (static HTML client, XAMPP deployment, small single-country team). (3) The product deliberately publishes demo accounts with a known password (/public/demo-accounts), so the threat model already accepts weak credentials in the demo posture; still, for production the lack of per-account lockout combined with min:6 and predictable usernames is a real hardening gap. Net: real, but medium rather than high — the only concrete exploitable weakness is online credential guessing against predictable identifiers with a short password floor and no account-level lockout.

```text
backend/app/Http/Controllers/Api/AuthController.php:25,36-39,62-63 (min:6, code/email lookup, short-circuit Hash::check, ['*'] for admin and teacher); backend/app/Models/User.php:37-38 (display codes assigned to teacher/center_manager/parent, so P{n}/T{n}/CA{n} are all valid logins); backend/app/Support/DisplayCode.php:19-25; backend/app/Support/LoginEmail.php:28; backend/routes/api.php:17 (`throttle:10,1`, the only login limiter; no RateLimiter::for in app/ or bootstrap/); no lockout/failed-attempt logic exists in AuthController, User model, or database/migrations; backend/app/Http/Middleware/AdminMiddleware.php:17 and TeacherMiddleware.php:17 both require tokenCan('*') and differ only by role check — escalation requires DB write to users.role, not just a stolen token; backend/tests/Feature/RoleMatrixTest.php:53-60 covers only the parent role-tamper case; backend/tests/Feature/AuthLoginTest.php is the only test touching throttling.
```

</details>

### Parent accounts cannot be disabled or reset by anyone; parents and managers have no in-app password/phone change; managers have no recovery path at all

<a id="parent-manager-account-lifecycle-gaps"></a>

`parent-manager-account-lifecycle-gaps` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/routes/api.php:43`, `backend/routes/api.php:53`, `backend/routes/api.php:111`, `backend/app/Http/Controllers/Api/AuthController.php:132`, `backend/app/Http/Controllers/Api/AdminUserController.php:17`

**Evidence**

```text
routes/api.php:43-50 (parent group) exposes only children/students/messages — no /profile, no password, no phone. routes/api.php:53-91 (manager group) has no self-service route either. The only status toggles are /teachers/{id}/status, /admin/managers/{id}/status, /students/{id}/status, /centers/{id}/status — none for role=parent; AdminUserController.php:17 states 'لا تعديل ولا تعطيل ولا إضافة من هنا'. AuthController.php:132 `whereIn('role', ['parent','teacher'])` excludes managers (and admin) from OTP recovery, and the admin has no endpoint to set a parent's password (ParentResolver sets it only at creation, ParentResolver.php:78-83).
```

**Why it matters**

Incident response for the most numerous principal is impossible through the product: a compromised or custody-revoked guardian keeps reading a minor's attendance, memorization and messages until the 7-day token expires and beyond (they can still log in). A manager who forgets a password needs the system admin; a parent who changes phone number is locked out of recovery forever. A mobile app for all four roles cannot ship a 'change password' screen for two of them.

**Recommendation**

Add `PUT /admin/users/{id}/status` (any non-admin role; revokes tokens; audit) and `POST /admin/users/{id}/password` (audit + notify); add a generic `/me` group under auth:sanctum with `POST /me/password` (current password required, reuse recordPasswordChange) and `PUT /me/phone` (OTP to the NEW number before commit) for all roles; extend OTP recovery to center_manager; add `POST /me/logout-all`. Cover each with role-matrix tests.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Core evidence verified. routes/api.php:43-50 (parent group) has only children/students/messages; the only self-service routes are /profile* under the `teacher` gate (api.php:135-137, reachable by teacher and admin only). Status toggles exist only for teachers (97), centers (101), students (107), managers (119); AdminUserController is documented and implemented as read-only (line 17, only index()). AuthController.php:132 restricts OTP to role in ['parent','teacher']. No code path anywhere sets a parent's password after creation (ParentResolver.php:73-92 only on create; the existing-parent branch at 56-67 refills name/phone/id_number only). No middleware checks users.is_active on already-issued tokens (only login, AuthController:42), so even a manual DB flip would not cut an active session; only token revocation does. So for parents the claim holds: no disable, no admin reset, no self password/phone change. However the finding is partly wrong about managers: 'managers have no recovery path at all' is false — PUT /admin/managers/{id} (ManagerManagementController.php:126-134) accepts an optional password (min:6, confirmed), hashes it and calls recordPasswordChange('admin'), which revokes all tokens; the same endpoint updates the manager's phone. Managers are only excluded from self-service OTP, which the code comment (AuthController:113) presents as a deliberate scope. Also, a parent's phone can be updated indirectly by an admin/manager re-submitting the guardian block with the same id_number (ParentResolver:56-67), and parent OTP recovery does exist. Severity: this is a missing-feature / incident-response gap, not an exploitable vulnerability (no privilege escalation, no tenant leak); the affected principal is read-only, tokens expire in 7 days, and the environment is a small single-country deployment where an admin has DB access. Medium rather than high.

```text
Confirmed: backend/routes/api.php:43-50 (parent group, no profile/password/phone); api.php:135-137 (/profile* only under `teacher` gate); api.php:97,101,107,119 (status toggles for teacher/center/student/manager only, none for parent); backend/app/Http/Controllers/Api/AdminUserController.php:17,23 (read-only index only); backend/app/Http/Controllers/Api/AuthController.php:132 (OTP whereIn role parent,teacher); backend/app/Support/ParentResolver.php:56-67 (existing parent: only name/phone/id_number refilled, never password); no is_active check in app/Http/Middleware or bootstrap/app.php for existing tokens (login-only at AuthController.php:42). Refuting part: backend/app/Http/Controllers/Api/ManagerManagementController.php:119-134 — admin PUT /admin/managers/{id} sets manager phone and optional password with recordPasswordChange('admin') (token revocation), so managers DO have an admin-driven recovery path; they lack only self-service OTP/profile.
```

</details>

### Parent↔teacher message history is keyed by student only, so a reassigned or transferred student hands the whole private thread to the new teacher — including across centers

<a id="message-thread-inherited-on-reassignment"></a>

`message-thread-inherited-on-reassignment` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/MessageController.php:120`, `backend/app/Http/Controllers/Api/MessageController.php:42`, `backend/database/migrations/2026_08_22_100000_create_messages_table.php:18`, `backend/app/Http/Controllers/Api/StudentController.php:657`, `backend/app/Http/Controllers/Api/StudentRequestController.php:329`

**Evidence**

```text
messages table (migration :17-26) has student_id, sender_id, sender_role, body — no thread/teacher key. MessageController.php:120 `Message::where('student_id', $student->id)->latest('id')->limit(100)` with access decided at :42 by `$student->teacher_id !== $user->id` (CURRENT teacher). StudentController@changeTeacher:657 and StudentRequestController@approve:329-336 rewrite teacher_id/center_id without touching messages. No test in MessagingTest.php covers reassignment or transfer (5 tests: :29,:67,:84,:96,:120).
```

**Why it matters**

A parent's confidential conversation with teacher A (about a child's behaviour, family situation, health) becomes readable by teacher B in another center after a transfer the parent was never notified of; the 'admin is not a party' privacy stance (MessageController.php:17) is undermined by design. For a mobile app whose flagship parent feature is messaging, this is a reputational and child-safeguarding risk.

**Recommendation**

Add `teacher_id` (the teacher party at send time) to messages and a `threads` concept keyed by (student_id, teacher_id); on reassignment/transfer close the old thread (read-only for the old teacher, hidden from the new one) and open a new one; show the parent a system message 'تغيّر محفّظ ابنك'. Add tests: after changeTeacher and after transfer approval, new teacher sees zero old messages, old teacher gets 403, parent sees both threads.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: low

The cited code behaves exactly as the auditor describes. `messages` (migration :16-27) carries only student_id/sender_id/sender_role/body/read_at — no teacher/thread key. `MessageController::resolveStudent` (:42) grants the teacher side to whoever is the student's CURRENT `teacher_id`, and `thread()` (:115-121) marks read and returns ALL messages for the student_id regardless of which teacher was the counterparty when they were sent. `threads()` (:72) likewise lists every parent-linked student now assigned to the caller. `StudentController@changeTeacher` (:657-663) and `StudentRequestController@approve` transfer branch (:322-330) rewrite `teacher_id`/`center_id` and never touch `messages`; the transfer path notifies only the requesting manager, not the parent. `grep` confirms no other code references messages on reassignment, and MessagingTest.php has no reassignment/transfer test. So: not refuted. However, severity is overstated. The migration/controller comments (migration :8-10, controller :13-14) show this is a deliberate design — "one conversation per student, the student record defines both parties" — not an oversight, and the recipient is always the staff member now responsible for that child inside the same organisation (single admin, single-country deployment; centers are branches, not independent tenants). The old teacher correctly loses access (403 via :42), so the exposure is one-directional to the child's new custodian, which is the common expectation for continuity of care. There is no unbounded or cross-customer leak, no escalation, and the parent can see the same thread. I would rate it low: a privacy/UX design gap worth a product decision (archive on reassignment + notify parent) rather than a security defect.

```text
backend/app/Http/Controllers/Api/MessageController.php:42 (access = current teacher_id), :72-74 (threads lists all currently-assigned parent-linked students), :115-121 (thread() reads/marks all messages by student_id only); backend/database/migrations/2026_08_22_100000_create_messages_table.php:8-10,16-27 (design comment: thread keyed by student_id; no teacher_id column); backend/app/Http/Controllers/Api/StudentController.php:657-663 (changeTeacher updates teacher_id only, no message handling, no parent notification); backend/app/Http/Controllers/Api/StudentRequestController.php:322-338 (transfer approve updates center_id/teacher_id, notifies only requesting manager); backend/tests/Feature/MessagingTest.php (5 tests, none cover reassignment/transfer).
```

</details>

### Unauthenticated /api/public/demo-accounts dumps every user's name/email/role whenever APP_DEBUG=true — and the frontend no longer uses it

<a id="public-demo-accounts-orphan-endpoint"></a>

`public-demo-accounts-orphan-endpoint` · 🟡 medium · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/routes/api.php:23`, `backend/app/Http/Controllers/Api/DashboardController.php:104`, `DEPLOYMENT.md:14`, `CLAUDE.md:32`

**Evidence**

```text
DashboardController.php:104 `if (! app()->environment('local') && ! config('app.debug')) return []` then :108-110 `User::orderByRaw(...)->get(['name','email','role'])`. `grep -rn 'demo-accounts' frontend-html/` → no consumer (removed in commit b2501ae 'حذف لوحة البيانات التجريبية من صفحة تسجيل الدخول'). DEPLOYMENT.md:14 claims 'مع APP_ENV=production يعيد قائمة فارغة' — false when APP_DEBUG=true. CLAUDE.md:32 still says the login page pulls this list. No test covers the endpoint (coverage script: 0 files).
```

**Why it matters**

A single common misconfiguration on the cPanel host (APP_DEBUG left on to debug a 500) publishes the full staff and guardian directory (names + login emails, which combined with P{n} codes are also usernames) to the internet. The endpoint has no remaining business purpose.

**Recommendation**

Delete the route and method. If a dev-only account list is ever needed, gate it on `environment('local')` only (as dev_otp already is) and add a test that asserts an empty body under APP_ENV=production, APP_DEBUG=true. Fix DEPLOYMENT.md:14 and CLAUDE.md:32.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 90% · corrected severity: medium

Traced the execution path and all quoted evidence holds. backend/routes/api.php:23 registers GET /public/demo-accounts outside the auth:sanctum group and without any throttle middleware. DashboardController.php:104 returns an empty list only when BOTH app()->environment('local') is false AND config('app.debug') is false; config/app.php:42 reads 'debug' => env('APP_DEBUG', false), so APP_ENV=production with APP_DEBUG=true falls through to User::...->get(['name','email','role']) with no role filter — every admin, manager, teacher and parent (name + login email) is returned. No mitigation exists elsewhere: bootstrap/app.php adds no global rate limit or extra guard, there is no test under backend/tests referencing demo-accounts/demoAccounts, and grep over frontend-html/ finds no consumer (commit b2501ae 'حذف لوحة البيانات التجريبية من صفحة تسجيل الدخول' exists and removed it). DEPLOYMENT.md:14 claims APP_ENV=production alone empties the list, which is false, and CLAUDE.md:32/206 still describe the login page as consuming it. Not fully mitigated, so not refuted. Severity: the disclosure requires a misconfiguration (APP_DEBUG=true in production) that already leaks stack traces/env details on any 500, and the data is names/emails/roles (no passwords, phones, or student data). However the emails are the login usernames, the endpoint is unthrottled (enables enumeration for credential stuffing against the throttle:10,1 login), guardians of minors are included, and the endpoint has zero business purpose left. Medium is appropriate; it is a dead endpoint whose deletion is trivial and eliminates the risk entirely.

```text
backend/routes/api.php:23 `Route::get('/public/demo-accounts', [DashboardController::class, 'demoAccounts']);` — outside auth:sanctum group, no throttle. backend/app/Http/Controllers/Api/DashboardController.php:104 `if (! app()->environment('local') && ! config('app.debug'))` (only guard) → :108-110 `User::orderByRaw(...)->orderBy('id')->get(['name','email','role'])` (all roles, no is_active filter). backend/config/app.php:42 `'debug' => (bool) env('APP_DEBUG', false)`. backend/.env.example:2,4 ship APP_ENV=local / APP_DEBUG=true. DEPLOYMENT.md:14 says APP_ENV=production alone returns an empty list (incorrect); DEPLOYMENT.md:9 correctly notes dev_otp is gated on environment('local') only — inconsistent with this endpoint. frontend-html/: zero references to demo-accounts (commit b2501ae removed the panel). backend/tests/: zero references. CLAUDE.md:32,118,161,206 still document the endpoint as consumed by login.html.
```

</details>

### 28 of 107 routes have no test and 24 more have no negative assertion; the matrix has whole cells with no proof of denial

<a id="tenant-isolation-negative-test-gaps"></a>

`tenant-isolation-negative-test-gaps` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/tests/Feature/RoleMatrixTest.php:30`, `backend/tests/Feature/CenterManagerTest.php:35`, `backend/tests/Feature/FingerprintImportTest.php:147`, `backend/routes/api.php:58`, `backend/routes/api.php:160`, `backend/routes/api.php:164`

**Evidence**

```text
Static coverage pass (scratchpad cov2.php, method-aware): per gate total/tested/with-negative = public 5/3/1, auth 9/3/2, parent 5/5/5, manager 32/26/19, admin 28/21/16, teacher 28/21/12. RoleMatrixTest.php:30-39 covers 8 GET routes x 3 roles and omits the manager token entirely (CenterManagerTest:35-46 adds 4+4 routes). Untested cells with a guard in code: PUT /manager/students/{id}/status foreign center (StudentController.php:585), DELETE /memorizations/{id} other teacher (MemorizationController.php:214), GET /weekly-tests/{id} other teacher (WeeklyTestController.php:177), POST /weekly-tests other teacher's student (:54), GET /reports/student/{id} and /pdf other teacher (ReportController.php:20, ReportPdfController.php:83), POST /attendance with foreign student ids silently skipped (AttendanceController.php:67), GET /attendance/report and /reports/weekly scoping, all 7 PDF routes, GET /teachers/{id}, /parents/search, /reports/admin/missing-national-id, /notifications index, /auth/logout. Untested cells with NO guard (behaviour undefined): manager linking parent_id/id_number of another center's parent; managerSearchParents cross-center; PUT /weekly-tests/{id} by former teacher after transfer; PUT /centers/{id} with is_active. FingerprintImportTest:147 asserts the refusal text but not that the foreign student's name is absent (it is present).
```

**Why it matters**

The dual-check model is described as 'the only guard' (CLAUDE.md Gotchas) yet a third of the surface has no automated proof, so refactors can silently open cross-tenant reads. The testing dimension counted 5 untested guards; the matrix approach shows the real number is 15+ guarded cells and 5 unguarded ones.

**Recommendation**

Replace the hand-written matrices with a table-driven test generated from the permissions matrix (route, method, role → expected status, plus a 'foreign-tenant fixture' variant for every {id} route: other center, other teacher, other parent). Fail the suite if a route exists that is not in the table (compare Route::getRoutes() against the matrix). Add assertions that refusal bodies contain no name/code of the foreign record.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The cited evidence is accurate: RoleMatrixTest.php:30-39 covers 8 GET routes x 3 roles (no manager token), CenterManagerTest.php:35-48 adds 4 manager routes + 4 denials, and FingerprintImportTest.php:147 only asserts the refusal string (the reason text does not need to omit the name; the response echoes the row anyway). I spot-checked the claimed untested-but-guarded cells: PUT /manager/students/{id}/status foreign center (StudentController.php:581-586 guard present; grep of tests/Feature finds only /teacher, never /status via the manager route), DELETE /memorizations/{id} (MemorizationController.php:214 guard present; no deleteJson on memorizations in any test), GET /weekly-tests/{id} (WeeklyTestController.php:177 guard; untested), /reports/student, /reports/weekly, PDF routes — none have a negative test. So the coverage-gap claim stands.

However the finding overstates the risk and the "unguarded" list is partly wrong. (1) The suite is 38 feature files, not the 20 CLAUDE.md mentions, and OwnershipTest, WeeklyTestUpdateTest, ManagerReportsScopeTest, ManagerTeacherStatusTest, ManagerAddStudentGuardianTest, TeacherStatusTest, CenterStatusTest already provide cross-teacher, cross-parent, cross-center and role denials on most mutating routes. (2) Two of the four "NO guard" cells are not tenant boundaries: parents are deliberately global entities (deduplicated system-wide by phone/id_number via ParentResolver; a guardian may have children in several centers), so a manager linking or searching a parent used by another center is by design, not a leak — searchParents/managerSearchParents only return the parent role rows the manager needs to link. (3) PUT /centers/{id} accepting is_active (CenterController.php:233) is admin-only; it bypasses the toggleStatus token-revocation/audit path but involves no cross-tenant access. (4) PUT /weekly-tests/{id} checks the test's own teacher_id (WeeklyTestController.php:122) — a former teacher editing a test they recorded is record ownership, not a cross-tenant read; inconsistency with show() (checks student->teacher_id, :177) is a minor design wobble. No actual tenant-isolation defect was found in any traced path — every guard the auditor named exists and is correct in code. This is therefore a test-hygiene/regression-protection finding without a demonstrated vulnerability, which is low rather than medium.

```text
backend/tests/Feature/RoleMatrixTest.php:30-39 (8 GET routes x admin/teacher/parent; no manager token); backend/tests/Feature/CenterManagerTest.php:35-48 (4 manager GETs + 4 denials); backend/tests/Feature/OwnershipTest.php:25-28,40-46,55-57 (cross-teacher/cross-parent denials on students, memorizations store, parent child); backend/tests/Feature/WeeklyTestUpdateTest.php:67-79 (other teacher PUT 403); backend/tests/Feature/ManagerReportsScopeTest.php:35,50 (manager cross-center report 403); backend/tests/Feature/ManagerTeacherStatusTest.php:51 (manager other-center teacher status). Untested guards confirmed: backend/app/Http/Controllers/Api/StudentController.php:581-586 (manager toggleStatus foreign center — no test hits PUT /manager/students/{id}/status), MemorizationController.php:209-219 (destroy other teacher — no deleteJson in suite), WeeklyTestController.php:54,177 (store/show other teacher). Not a gap by design: parents are global (ParentResolver dedup by phone/id_number; StudentController.php:297,320 search is parent-role scoped, not center scoped). CenterController.php:224-233 update() accepts is_active outside toggleStatus (admin-only, bypasses revocation at :251-278, no cross-tenant effect). Suite is 38 feature files, not 20 as CLAUDE.md states.
```

</details>

### Fingerprint xlsx import echoes the name and code of students from other centers, letting any teacher or manager dump the system-wide roster

<a id="xlsx-import-cross-center-name-oracle"></a>

`xlsx-import-cross-center-name-oracle` · 🟡 medium · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/AttendanceImportController.php:164`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:184`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:198`, `backend/tests/Feature/FingerprintImportTest.php:147`

**Evidence**

```text
AttendanceImportController.php:164 `Student::where('display_code', 'S' . $deviceNum)->first()` (global lookup), then :184 `"الطالب {$student->display_code} ({$student->name}) موقوف"` and :198 `"الطالب {$student->display_code} ({$student->name}) من مركز آخر — خارج نطاق صلاحيتك"` are returned in `errors[]` to the uploader. Display codes are sequential (DisplayCode.php), so a 5 MB xlsx with rows S1..S50000 returns every student name in every center, active or not. Route is open to teachers (routes/api.php:156) and managers (:70). FingerprintImportTest:147 asserts only `assertStringContainsString('خارج نطاق صلاحيتك')`.
```

**Why it matters**

Names of minors across all tenants (plus their fingerprint-device numbers) are exposed to every staff account; combined with the parent search this reconstructs family relationships across centers. It also confirms which S-codes exist (active vs inactive).

**Recommendation**

For out-of-scope rows return a generic reason with the device number only ('الرقم {n} خارج نطاق مركزك') and never load the name; check scope BEFORE the is_active branch; add a test asserting the foreign student's name is absent from the response. Consider per-center device numbering (center prefix in display_code) so devices cannot collide across centers.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Traced the actual execution path in backend/app/Http/Controllers/Api/AttendanceImportController.php and the claim is factually correct. Line 164 does a global `Student::where('display_code', 'S'.$deviceNum)->first()` with no center/teacher scope. Before any scope check, line 179-184 (inactive branch) and then line 193-198 (out-of-center branch) both interpolate `$student->display_code` and `$student->name` into `errors[].reason`, and `errors` is returned verbatim in the JSON at line 396. The scope check at 193 comes AFTER the is_active check at 179, so inactive foreign students also leak their names. Only the in-scope `name_warnings` path (line 289-296) is properly scoped. Routes confirmed: routes/api.php:70 (manager gate) and :156 (teacher gate) both reach this controller; the middleware in CenterManagerMiddleware only enforces role/ability/center_id presence, not the row-level scope, so no mitigation exists there. The only existing test (FingerprintImportTest.php:147) checks the substring 'خارج نطاق صلاحيتك' and does not assert the name is absent — in fact the test file's name is passed in the xlsx itself, so it would not detect the leak anyway. No other test covers this. Display codes are sequential (S{n} via code_sequences), so enumeration via S1..S{n} is trivial and the 5 MB limit allows tens of thousands of rows in one request. Nothing in the frontend prevents this since the API is directly callable with any teacher/manager bearer token. Not exaggerated in mechanics; the impact is bounded — only student name + code (no phone, guardian, national id) leaks, to already-authenticated staff of the same organization, and the 'combined with parent search' chain is speculative. Medium is a fair rating for cross-tenant exposure of minors' names; I would not raise it.

```text
backend/app/Http/Controllers/Api/AttendanceImportController.php:164 unscoped `Student::where('display_code', 'S' . $deviceNum)->first()`; :179-184 inactive branch runs BEFORE the scope check and echoes `{$student->display_code} ({$student->name})` for any student system-wide; :193-198 out-of-center branch echoes the same; :396 `'errors' => $errors` returned to caller. routes/api.php:70 (manager) and :156 (teacher) both expose the handler. backend/app/Http/Middleware/CenterManagerMiddleware.php:20 checks only role/ability/center_id presence, not row scope. backend/tests/Feature/FingerprintImportTest.php:147 asserts only the substring 'خارج نطاق صلاحيتك' and never asserts the foreign name is absent (the same name is supplied in the uploaded row, so a containment check would be meaningless). The in-scope `name_warnings` path (:289-296) is correctly scoped and is not part of the leak.
```

</details>

### n8n digest logs in as the human admin with a plaintext password, holds a 7-day '*' token it never revokes, and exports minors' national IDs and guardian phones daily

<a id="automation-uses-human-admin-wildcard-token"></a>

`automation-uses-human-admin-wildcard-token` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `n8n/mutqin-daily-attendance-digest.json:1`, `n8n/README.md:19`, `backend/app/Http/Controllers/Api/AttendanceController.php:23`, `backend/app/Http/Controllers/Api/AuthController.php:62`

**Evidence**

```text
Workflow Set node: `email: admin@mutqin.ly`, `password: [redacted] (plain string); README:21 'The password sits in plain text for demo convenience'. Login node POSTs /api/auth/login → admin token with `['*']` (AuthController.php:62-63); no logout node exists, so a live admin token is minted every day at 20:00. GET /api/attendance (AttendanceController.php:23 `$students = $studentsQuery->get()`) returns full Student models — national_id, guardian_phone, phone, parent_id — for every active student in the system, into the n8n instance and its execution history; the email then lists absent minors by code and name to `sendTo`. There is no service-account concept: abilities are derived solely from role at login.
```

**Why it matters**

The most privileged credential in the system lives in a third-party automation store and is re-issued daily; a compromise of the n8n host is a full compromise of all centers. Daily bulk export of children's identity data to an external system is disproportionate to a headcount digest.

**Recommendation**

Add a read-only `reports:attendance-digest` ability and an admin endpoint to mint named, scoped, expiring tokens (Sanctum supports abilities; add `service` role or per-token abilities) with a token list/revoke UI; give the digest a minimal endpoint (`/reports/attendance-digest?date=` returning code/name/status only); add a logout step; store credentials in an n8n credential, not a Set node; log each automation call to the security-events table.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 72% · corrected severity: low

Traced and confirmed the factual chain. n8n/mutqin-daily-attendance-digest.json Set node holds `email: admin@mutqin.ly` and `password: [redacted] as plain string assignments; the login node POSTs to /api/auth/login; AuthController.php:62-64 grants `['*']` abilities to any non-parent/non-manager user (admin or teacher); config/sanctum.php:55 sets expiration 10080 min (7 days); the workflow has no logout node (connections: Schedule → Set → Login → GET /api/attendance → Code → IF → Email/NoOp) and there is no `sanctum:prune-expired` schedule in routes/console.php, so a fresh admin token row accumulates daily. AttendanceController.php:17-23 returns `$studentsQuery->get()` (all active students for an admin) and Student.php has no `$hidden` array, so national_id, phone, guardian_phone, parent_id are serialized into the n8n execution payload. The email itself only lists display_code + name (Code node `line()`), so the exfiltration to the recipient is minimal; the over-collection is into n8n's execution store. So the finding is factually correct. However it is over-rated: (1) the password is a placeholder, not a committed secret, and both README.md:20-21 and the node's own `notes` ("بدّل password إلى بيانات اعتماد n8n قبل الإنتاج") instruct replacing it with an n8n credential before real use; (2) this is an opt-in demo template that is not part of the deployed application — nothing in backend/ or frontend-html/ references it; (3) README.md:25-29 already documents running it under a teacher account (scoped to own students) and adding `center_id` to narrow scope, which are the available mitigations in the current model; (4) the "most privileged credential" point is real but the absence of service-account/scoped-token support is an architectural gap of the whole product, not a bug introduced by this file; (5) the recommendation references a `security-events table` that does not exist in the schema. The unrevoked 7-day token is a minor hygiene issue (each token is independent; leaking one requires compromising n8n, which already holds the password). Net: real but low, with the actionable items being "use an n8n credential + prefer a teacher account / add a logout node" rather than a new token subsystem.

```text
n8n/mutqin-daily-attendance-digest.json Set node assignments cfg-2/cfg-3 (`admin@mutqin.ly`, `PUT_PASSWORD_HERE` placeholder) and node `notes` telling the operator to switch to an n8n credential; n8n/README.md:20-21 (plain-text password is demo-only) and :25-29 (teacher account / center_id scoping documented); backend/app/Http/Controllers/Api/AuthController.php:62-64 (`['*']` for admin and teacher); backend/config/sanctum.php:55 (`'expiration' => 10080`); backend/app/Models/Student.php has no `$hidden` (national_id/phone/guardian_phone serialized); backend/app/Http/Controllers/Api/AttendanceController.php:17-23 (full Student models returned); backend/routes/console.php has no `sanctum:prune-expired`; no `security_events` table exists in backend/database (recommendation cites a nonexistent table). The Code node only emails display_code + name.
```

</details>

### A center manager can silently set any of their teachers' passwords and free-form emails, then read parent↔teacher messages as that teacher; the audit log records it as 'admin'

<a id="manager-teacher-password-reset-no-actor-audit"></a>

`manager-teacher-password-reset-no-actor-audit` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/CenterManagerController.php:333`, `backend/app/Http/Controllers/Api/CenterManagerController.php:342`, `backend/app/Http/Controllers/Api/CenterManagerController.php:314`, `backend/app/Http/Controllers/Api/MessageController.php:17`, `frontend-html/manager/teachers.html:30`

**Evidence**

```text
CenterManagerController@updateTeacher:333-335 `if ($request->filled('password')) { $data['password'] = Hash::make(...) }` and :342 `$teacher->recordPasswordChange('admin')` — the enum has no manager value and password_change_logs has no changed_by; :314 `'email' => 'required|email|unique:users,email,'` lets the manager replace the generated `{latin}_{code}@mutqin.ly` with any address (storeTeacher enforces the scheme, update does not). The teacher is logged out (tokens revoked) but receives no notification. MessageController.php:17 states the admin 'يُرفض هنا' as a non-party, yet the manager path above bypasses that intent. frontend manager/teachers.html:30 exposes 'كلمة مرور جديدة (اختياري)'.
```

**Why it matters**

The product promises parents a private channel with the teacher; in practice any manager can impersonate a teacher without trace. This is an insider/child-safeguarding risk and an accountability gap (a disputed grade or message cannot be attributed).

**Recommendation**

Record changed_by and changed_by_role in password_change_logs; notify the teacher in-app and by SMS when their password is set by someone else; restrict manager resets to a 'force reset on next login' one-time code rather than a chosen password, or remove the field in favour of OTP self-service; enforce the LoginEmail scheme on update as on create.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The claimed behaviour is real, though the cited line numbers are stale. CenterManagerController@updateTeacher (actual lines 392-437) validates `'email' => 'required|email|unique:users,email,'.$teacher->id` (line 401) with no LoginEmail-scheme constraint (storeTeacher at 150/157 enforces the scheme; update does not), and on `$request->filled('password')` hashes the chosen password (428-429) and calls `$teacher->recordPasswordChange('admin')` (430). User::recordPasswordChange (User.php:70-83) writes only `changed_at` + `method`; the password_change_logs migration (2026_06_28_150000) has an enum method [otp,self,admin] and no actor column, so a manager reset is indistinguishable from an admin reset and the actor id is not recorded anywhere. No notification to the teacher is sent in updateTeacher (only tokens are revoked). MessageController::resolveStudent (lines 41-48) admits only the real teacher of the student (`isTeacher() && student.teacher_id === user.id`), so someone logging in with the manager-chosen password can read/send parent messages as that teacher. Frontend manager/teachers.html:30-31 exposes the optional password + confirmation fields. No feature test covers the manager password-reset path (ManagerAddTeacherTest, CenterManagerTest, ManagerTeacherStatusTest do not exercise updateTeacher password). Mitigations that reduce severity: (1) the admin has the identical capability via TeacherController@update:170-177 with the same 'admin' method, so privileged password reset is an accepted design pattern here, and the manager is a trusted, single-per-center operational role, not an arbitrary user; (2) the reset is not fully silent — password_changed_count increments, password_last_changed_at is set, a log row is created, and all teacher tokens are revoked, so the teacher is forced to re-login and will discover their password no longer works; (3) the manager is scoped to their own center's teachers only (404 outside). The finding's 'no trace' is thus overstated — there is a trace, but it lacks actor attribution and role differentiation. The core accountability gap (no changed_by, no teacher notification, free-form email on update) is genuine. Given a small single-country deployment where managers are trusted staff, this is a low-to-medium accountability issue rather than a security vulnerability; I rate it low.

```text
backend/app/Http/Controllers/Api/CenterManagerController.php:401 (`'email' => 'required|email|unique:users,email,' . $teacher->id` — no LoginEmail scheme on update; create path at :150/:157 uses LoginEmail::temporary/assign); :427-431 (`if ($request->filled('password')) { ... Hash::make ... }` then `$teacher->recordPasswordChange('admin')`); backend/app/Models/User.php:70-83 (log row stores only changed_at + method, then `$this->tokens()->delete()`); backend/database/migrations/2026_06_28_150000_add_password_tracking.php:22-29 (enum method ['otp','self','admin'], no changed_by column); backend/app/Http/Controllers/Api/TeacherController.php:170-177 (admin path with identical 'admin' method — same pattern); backend/app/Http/Controllers/Api/MessageController.php:41-48 (teacher side requires isTeacher() && student.teacher_id == user.id — a login with the reset password satisfies this); frontend-html/manager/teachers.html:30-31 (password / password_confirmation fields in edit modal). No test in backend/tests/Feature exercises manager password reset on PUT /manager/teachers/{id}.
```

</details>

### Weekly-test edits are owned by the authoring teacher (not the current one) so a former teacher — even in another center after transfer — can rewrite results; memorization hard-deletes and grade rewrites leave no audit

<a id="weekly-test-and-memorization-write-ownership-drift-no-grade-audit"></a>

`weekly-test-and-memorization-write-ownership-drift-no-grade-audit` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/WeeklyTestController.php:122`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:177`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:151`, `backend/app/Http/Controllers/Api/MemorizationController.php:220`

**Evidence**

```text
WeeklyTestController@update:122 `if (!$user->isAdmin() && $test->teacher_id !== $user->id)` (author) vs @show:177 `$test->student->teacher_id !== $user->id` (current teacher) — inconsistent ownership semantics on the same resource. After StudentController@changeTeacher or a transfer (StudentRequestController.php:329) the old teacher keeps PUT access to tests of a student now in another center, while the new teacher can view but not correct them. :151-160 `$test->questions()->delete()` then re-insert with no history; MemorizationController@destroy:220 `$memorization->delete()` is the last hard delete in the system, unaudited. WeeklyTestUpdateTest covers 'other teacher' (line 66) but not 'former teacher'.
```

**Why it matters**

Tenant boundary leaks on write after a transfer, and the grading record of a minor can be altered without trace — unacceptable for a system that issues progress reports/PDFs to parents and management.

**Recommendation**

Make ownership = current teacher of the student for both show and update (or require center match), add `updated_by` and a `weekly_test_revisions` snapshot (or soft-delete questions), replace memorization hard delete with a soft delete + reason + actor, and add the 'former teacher after transfer → 403' test.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Traced the code and the finding is factually accurate. WeeklyTestController@update:122 gates on `$test->teacher_id` (author), while @show:177 and MemorizationController@destroy:214 gate on `$student->teacher_id` (current teacher) — the inconsistency is real, and the docblock at :115 shows it was a deliberate author-ownership choice ('المحفّظ يعدّل اختباراته هو فقط (teacher_id)'), not an accident, but nobody considered transfers. Neither the transfer approval (StudentRequestController.php:322-330 updates only students.center_id/teacher_id/former_teacher_name) nor changeTeacher rewrites weekly_tests.teacher_id, and index:19-20 lists tests by teacher_id, so a former teacher continues to see and can PUT /weekly-tests/{id} for a student now in another center. Route `weekly-tests` ->only(['index','store','show','update']) is behind the `teacher` gate (role+ability), so only real staff can do this — but no center/current-teacher check exists. Memorization hard delete at :221 confirmed; no SoftDeletes trait on any model, no updated_by/deleted_by columns in migrations, questions are delete()+re-inserted at :152 with no snapshot. WeeklyTestUpdateTest has 'other_teachers_test_is_forbidden' (line 66) and rollback tests but no former-teacher/transfer case. No mitigations found in bootstrap/app.php, models, migrations, or frontend beyond UI listing. However the severity is overstated: the actor must be an authenticated, active teacher of the same organization editing tests they themselves authored (not another teacher's), the product is a single-organization tool with a small trusted staff rather than a multi-tenant SaaS, teachers within a center already have full write over their students' grades with no audit anyway, and the 'tenant boundary' here is between centers of the same organization. Real correctness/authorization drift and audit gap, but low practical exploitability and no data exposure of other students — low, not medium.

```text
backend/app/Http/Controllers/Api/WeeklyTestController.php:19-20 (index filters by teacher_id, so former teacher still lists the tests); :115 docblock documents author-ownership as intended; :122 update gate = $test->teacher_id; :152 questions()->delete() then re-insert; :177 show gate = $test->student->teacher_id. backend/app/Http/Controllers/Api/StudentRequestController.php:322-330 transfer updates students only, never weekly_tests.teacher_id. backend/routes/api.php:164 weekly-tests only index/store/show/update under teacher gate; :160 memorizations destroy still exposed. backend/app/Http/Controllers/Api/MemorizationController.php:214 destroy gate = current teacher (consistent with show), :221 hard delete; no SoftDeletes in app/Models. tests/Feature/WeeklyTestUpdateTest.php:66 covers other-teacher only, no transfer/former-teacher case.
```

</details>

### Generic admin PUTs bypass the deactivation and tenant invariants: PUT /centers/{id} flips is_active without revoking member tokens, and moving a teacher to another center leaves their students (and message threads) behind

<a id="admin-update-paths-bypass-lifecycle-invariants"></a>

`admin-update-paths-bypass-lifecycle-invariants` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/CenterController.php:220`, `backend/app/Http/Controllers/Api/CenterController.php:42`, `backend/app/Http/Controllers/Api/TeacherController.php:161`, `backend/app/Http/Controllers/Api/ManagerManagementController.php:119`

**Evidence**

```text
CenterController@update:220 `$center->update($request->only(['name','city','address','phone','is_active']))` and @store:42 accept is_active directly, while @toggleStatus:245-259 is the only path that revokes members' tokens — centers also have no status_changed_by/at columns. TeacherController@update:161-174 writes a new center_id with no cascade: students.teacher_id still points at the teacher (student.center_id != teacher.center_id), so the teacher keeps recording attendance/memorization and messaging parents in the old center while StudentController@store:196 and changeTeacher:648 elsewhere enforce 'teacher belongs to the student's center'. ManagerManagementController@update:119-123 re-tenants a manager (center_id) without audit or token revocation. No tests cover any of these paths.
```

**Why it matters**

The invariant 'a teacher's students are in the teacher's center' is enforced on create but not on update, so cross-center ownership appears through a routine admin edit; a center 'closed' via the generic PUT leaves its staff working for up to 7 days.

**Recommendation**

Drop is_active from update/store fillables (toggle only) and add status_changed_by/at to centers; on teacher center change either refuse while students are assigned or unassign them (teacher_id NULL + former_teacher_name) inside the same transaction and revoke the teacher's tokens; log manager re-tenanting and revoke tokens; add tests for all three.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

All cited evidence checks out (line numbers are slightly off: CenterController@update is at 224-240 with the `$request->only([... 'is_active'])` at line 233, @store at 42, @toggleStatus at 249-280; TeacherController@update 146-185; ManagerManagementController@update 102-142). Center::$fillable includes is_active, so the generic PUT/POST genuinely writes it without the token-revocation loop that only lives in toggleStatus (266-269). No migration adds status_changed_by/at to centers (only users and students have them). TeacherController@update writes center_id with no student cascade; MessageController scopes parent<->teacher threads by students.teacher_id (line 42/72), so a re-centered teacher indeed keeps those threads and can still record attendance for old-center students (AttendanceController filters by teacher_id, not center). ManagerManagementController@update re-tenants center_id with no audit/revocation. No feature test exercises PUT /centers/{id}, PUT /teachers/{id} or PUT /admin/managers/{id} with a center or is_active change. Mitigations that do exist: (1) all three routes are behind the admin gate (role + tokenCan('*')), so only a trusted system admin can trigger them; (2) the admin frontend never sends is_active on the center edit form (centers.html fields() at lines 24-29 only include name/city/address/phone) and uses the dedicated /status endpoint with a confirmation dialog, so the is_active bypass requires a hand-crafted API call by an admin; (3) the teacher center change IS reachable through the normal admin UI (teachers.html line 44 offers center_id select in edit), so the cross-center ownership drift is a realistic routine outcome, not just a curl edge case. Given it is admin-only (no privilege escalation, no tenant boundary crossed by an untrusted actor) and the worst concrete effect is data-consistency drift plus a stale 7-day session window on an admin-only misuse path, medium is somewhat high; low-to-medium is fairer. Not refuted — the code does behave as described.

```text
backend/app/Http/Controllers/Api/CenterController.php:233 `$center->update($request->only(['name','city','address','phone','is_active']))` (update method 224-240); :42 store also accepts is_active; :249-280 toggleStatus is the only path deleting member tokens (266-269). backend/app/Models/Center.php:9-15 fillable includes is_active. No migration adds status_changed_by/at to centers (only 2026_07_23 users, 2026_08_10 students). backend/app/Http/Controllers/Api/TeacherController.php:146-185 update writes center_id (165) with no student/thread cascade or token revocation. backend/app/Http/Controllers/Api/ManagerManagementController.php:102-142 update writes center_id (123) with no audit/revocation. backend/app/Http/Controllers/Api/MessageController.php:42,72 threads keyed on students.teacher_id; AttendanceController.php:19,27 filters by teacher_id only. Mitigation: frontend-html/admin/centers.html:24-29 edit form omits is_active and uses /centers/{id}/status (line 104); frontend-html/admin/teachers.html:44 edit form does expose center_id. No test in backend/tests/Feature hits these generic PUTs.
```

</details>

### Phone is the single recovery factor but is changeable without re-authentication or verification, and the plaintext OTP is written to the log unconditionally

<a id="otp-recovery-path-weaknesses"></a>

`otp-recovery-path-weaknesses` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:218`, `backend/app/Http/Controllers/Api/TeacherProfileController.php:58`, `DEPLOYMENT.md:37`, `backend/.env.production.example:39`

**Evidence**

```text
AuthController::sendOtp:218 `Log::info("OTP password-reset for user #{$user->id} (phone {$user->phone}): {$otp}")` runs in every environment; only the template's `LOG_LEVEL=error` (.env.production.example:39) suppresses it, and DEPLOYMENT.md:37 wrongly says it is 'بيئة local فقط'. TeacherProfileController@updatePhone:58-60 sets `$user->phone = $normalized; $user->save()` with only the bearer token — no current password, no OTP to the new number — so a stolen teacher token can redirect future password resets permanently. Parents' phones are changeable by managers through ParentResolver (see enumeration finding). No SMS gateway exists (:219 TODO).
```

**Why it matters**

When the mobile app ships self-service recovery via SMS, the recovery channel can be hijacked by anyone who already has short-lived access (token theft) or by a manager, converting a 7-day exposure into permanent account takeover; on the shared host storage/logs/ is readable to anyone with cPanel access.

**Recommendation**

Remove the OTP value from the log line (log user id and outcome only); require current password AND an OTP to the new number for any phone change; notify the old number; rate-limit updatePhone; when integrating SMS, add a per-phone daily cap and log every OTP request to security events.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 78% · corrected severity: low

All quoted evidence verified verbatim. (1) backend/app/Http/Controllers/Api/AuthController.php:216-220 `sendOtp()` unconditionally calls `Log::info(... {$otp})` — there is no `environment('local')` guard on the log line (the guard at :153 only covers `dev_otp` in the response). config/logging.php reads `env('LOG_LEVEL','debug')`, so any environment without an explicit LOG_LEVEL>=notice (e.g. staging, or a prod .env copied from .env.example which has LOG_LEVEL=debug) writes the plaintext OTP + phone to storage/logs. DEPLOYMENT.md:37 does overstate this as "بيئة local فقط". The shipped production template (.env.production.example:39 LOG_LEVEL=error) does suppress it if followed. (2) TeacherProfileController::updatePhone:40-67 requires only the bearer token (route api.php:136 under the `teacher` gate, no extra throttle), no current password, no verification to the new number, no uniqueness check; frontend teacher/profile.html:99 just PUTs the phone. Contrast changePassword:87 which does require current_password. TeacherProfileTest covers normalization/mass-assignment only, not re-auth. (3) ParentResolver:59 overwrites parent phone from manager input. However, the finding is over-rated for the product as it exists today: there is no SMS gateway (:219 TODO), so in production (LOG_LEVEL=error, non-local) the OTP is delivered nowhere — the phone-based recovery path is inert, and the hijack impact the auditor describes is explicitly conditional on a future SMS integration. The OTP itself is stored hashed with 10-min expiry, 5 attempts, and throttle:5,1; anyone who can read storage/logs on the shared host can read .env DB credentials too, so the log line adds little marginal exposure. Real, latent, but low today; should be fixed before the SMS gateway ships (then it becomes medium).

```text
backend/app/Http/Controllers/Api/AuthController.php:153 (environment('local') guard applies only to dev_otp in the response) vs :218 (Log::info with plaintext OTP, unguarded); :219 TODO — no gateway exists, so recovery is non-functional in production. backend/config/logging.php:64,71,87 default `env('LOG_LEVEL','debug')`; backend/.env.example:21 LOG_LEVEL=debug; backend/.env.production.example:39 LOG_LEVEL=error (suppresses only if followed). DEPLOYMENT.md:37 wrong claim confirmed. backend/routes/api.php:136 PUT /profile/phone — teacher gate only, no throttle; TeacherProfileController.php:58-60 sets phone with no current_password (compare changePassword :87). frontend-html/teacher/profile.html:99 sends only {phone}. backend/tests/Feature/TeacherProfileTest.php:59-85 tests normalization/mass-assignment only, no re-auth test. backend/app/Support/ParentResolver.php:59 parent phone overwritten from manager-supplied guardian data.
```

</details>

### Exactly one super-admin account, created only by seeder, with no MFA, no OTP recovery, no second admin path and its initial password handed over in chat

<a id="single-admin-no-mfa-no-break-glass"></a>

`single-admin-no-mfa-no-break-glass` · 🟡 medium · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/routes/api.php:94`, `backend/app/Http/Controllers/Api/AuthController.php:132`, `backend/database/seeders/ProductionSeeder.php:24`, `backend/DEPLOY_LOG.md:45`

**Evidence**

```text
No route creates or promotes an admin (routes/api.php:94-130: teachers, centers, students, managers only; AdminUserController is read-only). AuthController.php:132 excludes admin from OTP recovery by design. DEPLOY_LOG.md:45 'كلمة مرور الأدمن الأولية سُلِّمت لصاحب المشروع في المحادثة'. DEPLOY_LOG.md:6-7: SSH disabled by the host — recovery means phpMyAdmin edits. No MFA anywhere (`grep -rni 'totp|2fa|mfa' app/` → none).
```

**Why it matters**

The account that can read every center, reset every staff password and export every PDF is protected by a 6+ char password and nothing else; losing it means DB surgery, compromising it is a total breach with no detection.

**Recommendation**

Add TOTP MFA for admin and managers (pragmarx/google2fa-laravel), a documented break-glass procedure (sealed secondary admin created via ProductionSeeder-style env-driven command, phpMyAdmin steps to revoke tokens), and require the initial password to be changed at first login (flag + middleware).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Evidence verified. routes/api.php:94-130 admin group creates only teachers/centers/students/managers; TeacherController.php:87 and ManagerManagementController.php:87 hard-code role to 'teacher'/'center_manager', and AdminUserController is read-only, so no route creates or promotes an admin. AuthController.php:132 restricts OTP recovery to whereIn('role',['parent','teacher']) — admin (and manager) excluded. grep for totp/2fa/mfa/two-factor/must_change/force_password across app/, config/, routes/, migrations returns nothing — no MFA and no forced first-login password change. ProductionSeeder.php:24-30 is the sole admin creation path (requires ADMIN_INITIAL_PASSWORD >= 8 chars). DEPLOY_LOG.md:6-7 confirms SSH disabled by host; line 45 confirms the initial password was delivered in chat. Partial mitigations not credited by the auditor: login throttle 10/min per IP (routes/api.php:17), Sanctum token expiry 7 days (config/sanctum.php:55), admin can self-change password via /profile/password (admin passes the teacher gate; TeacherProfileController.php:74-100 requires current password, revokes all tokens), and the seeder enforces 8+ chars for the initial admin password (not 6 as stated — 6 applies to later self-changes). The finding is factually correct; medium severity is appropriate for a small single-country deployment with a manual-only host and one administrator — the real exposure is the lack of a recovery path and detection, not an exploitable code flaw.

```text
backend/routes/api.php:94-130 (admin group: no admin create/promote route); backend/app/Http/Controllers/Api/TeacherController.php:87 and ManagerManagementController.php:87 (role hard-coded, cannot mint admin); backend/app/Http/Controllers/Api/AuthController.php:132 (whereIn role parent,teacher — admin excluded from OTP); backend/database/seeders/ProductionSeeder.php:24-30 (sole admin creation, ADMIN_INITIAL_PASSWORD >= 8 chars, not 6); backend/DEPLOY_LOG.md:6-7,45 (SSH disabled; password handed over in chat). Mitigations: routes/api.php:17 throttle:10,1 on login; config/sanctum.php:55 expiration 10080 min; TeacherProfileController.php:74-100 admin can self-change password (revokes tokens). No hits for totp|2fa|mfa|two.?factor|must_change in app/ config/ routes/ migrations.
```

</details>

## Measured facts

| Metric | Value |
|---|---|
| API routes (routes/api.php, apiResource expanded) | 107 = public 5 · auth-only 9 · parent 5 · manager 32 · admin 28 · teacher 28 |
| Middleware gates / distinct token ability sets | 4 aliases (admin, teacher, parent, manager) / 3 ability sets ('*' shared by admin+teacher, 'manager', 'parent') |
| In-controller role checks (isAdmin/isCenterManager/isTeacher/isParent) | 43 |
| teacher_id ownership comparisons in controllers | 13 |
| Explicit center-scope 403 refusals in controllers | 11 (of 12 manager routes with an {id}) |
| Feature test files / test methods (CLAUDE.md claims 20 files) | 38 / 173 |
| Negative assertions in tests (403 / 404 / 401) | 63 / 10 / 9 |
| Routes with no test at all | 28 / 107 (26%) |
| Routes tested with no negative (401/403/404) assertion | 24 / 107 |
| Per-gate routes with at least one negative test (total/tested/negative) | public 5/3/1 · auth 9/3/2 · parent 5/5/5 · manager 32/26/19 · admin 28/21/16 · teacher 28/21/12 |
| Throttled routes | 3 / 107 (login 10/min, OTP request 5/min, OTP verify 10/min — all per IP) |
| Log:: calls in app/ (security-event calls) | 4 (0) |
| Policies / Observers / Events / Listeners | 0 / 0 / 0 / 0 |
| Audit surfaces | password_change_logs (no actor column), users.status_changed_by/at, students.status_changed_by/at, attendances.corrected_by/at; centers: none; messages/memorizations/weekly_tests: none |
| Documentation files / lines / hits for threat\|incident\|disclosure\|breach\|runbook | 7 / 537 / 0 |
| Token lifetime / refresh / session management | 7 days (10080 min) / none / none (no list, no revoke-all, no prune) |
| Password policy / MFA | min 6 chars, no complexity or breach check / none |
| User model $hidden fields | 2 (password, remember_token) — email, phone, id_number, display_code serialize by default |
| Frontend pages / sidebar entries per role | 33 html (admin 9, manager 9, teacher 9, parent 3, public 3) / admin 8, teacher 8, center_manager 8, parent 2 |
| Migrations | 35 |
| Principals that cannot be deactivated through the API | 1 of 4 roles (parent) + admin |

## Auditor notes

== A. PERMISSIONS MATRIX (reconstructed from routes/api.php + controllers; C/R/U/T=toggle; scope in brackets; sensitive fields noted) ==
STUDENT — admin: C any center (POST /students; teacher must belong to center) · R all (GET /students, /centers/{id}/students: full model incl national_id, guardian_phone, parent_id) · U all incl center/teacher/national_id (PUT /students/{id}) · T all. center_manager: C [own center forced] · R [own center] full model + /manager/reports/student/{id} · U teacher assignment only (PUT /manager/students/{id}/teacher) · T [own center]. teacher: R [own students] full model incl guardian_phone/national_id (GET /students/{id}); /details omits guardian · U [own] name/phone/age/guardian display fields; teacher_id → 403. parent: R [own children] curated (no national_id).
GUARDIAN ACCOUNT (users.role=parent) — admin: C via student store (ParentResolver) · R system-wide search name/phone/email (/parents/search) + /admin/users · U name/phone via ParentResolver match · T NONE (no route). center_manager: C via /manager/students · R [own center via children] name/code/phone/id_number (/manager/parents) + SYSTEM-WIDE name/id_number/children_count by 3-digit prefix (/manager/parents/search) + full record in POST /manager/students response · U SYSTEM-WIDE name/phone overwrite via guardian_id_number match · T none. teacher: none (display fields only). parent: implicit self; no profile route.
TEACHER — admin: C/R/U any center incl center move, email, password (PUT /teachers/{id}); R /teachers/{id} incl password logs · T any. center_manager: C [own center; role/center forced; scheme email] · R [own center] email/phone; /performance incl students' national_id · U [own center] name/free-form email/phone/type/PASSWORD · T [own center; sole primary protected]. teacher: R/U self (/profile: phone, password). parent: name via child.
CENTER — admin: C/R/U (PUT also accepts is_active — bypass) · T (revokes members). center_manager: R own (/manager/center) + list of other active centers id/name/city (/manager/centers). teacher/parent: name via student.
MANAGER ACCOUNT — admin: C/R/U (incl center reassignment + password) · T. center_manager: no self-service. others: none.
ATTENDANCE — admin: R all (?center_id) · C/U upsert any (POST /attendance confirm=true) · import any center. center_manager: R [own center] · U single-record correction [own center] · import [own center; foreign rows rejected WITH name]. teacher: R/C/U [own students; foreign ids silently skipped] · import [own students; foreign center rejected WITH name]. parent: R own child.
MEMORIZATION — admin: R all · C any · D hard delete. center_manager: R via reports. teacher: R/C [own students] · D hard delete [own students], unaudited. parent: R own child.
WEEKLY TEST — admin: R/C/U any. center_manager: R via reports. teacher: R show [current teacher of student] · C [own students] · U [AUTHOR teacher_id — drifts after reassignment] · DELETE 405. parent: R own child incl per-thumn.
MESSAGE — admin: none (passes teacher gate, 403 in controller). center_manager: none (but can reset teacher password). teacher: R/C threads where student.teacher_id = self (history keyed by student → inherited). parent: R/C threads for own children.
NOTIFICATION — all roles: own only (404 on others').
STUDENT REQUEST — admin: none (informational manager_deactivated notification only). center_manager: C transfer [source = own center, target active center with active manager] · R incoming + outgoing · approve/reject [target = own center] (incoming rows expose student national_id by design). teacher/parent: none (legacy 'add' rows only approvable by target manager).
REPORT/PDF — admin: JSON+PDF all centers + missing-national-id. center_manager: JSON+PDF [own center from account; foreign teacher/student → 403]. teacher: weekly/student JSON+PDF [own students] + teacher group PDF. parent: child payload only.
USERS DIRECTORY — admin: R all (name/email/phone/code/role/center/status). center_manager: /manager/parents [own center]. others: none.
CREDENTIALS — admin: self via /profile/password (passes teacher gate); sets teachers'/managers' passwords. center_manager: NONE for self; sets teachers' passwords. teacher: self password (current required) + phone (no re-auth) + OTP by phone. parent: OTP by phone only.

== B. MATRIX vs CLAUDE.md / DEPLOYMENT.md (doc drift, security-relevant) ==
1) CLAUDE.md:40 'login by email' — code also accepts display code (AuthController.php:36); CLAUDE.md:70 'admin + parent have none' — parents get P{n} (DisplayCode.php:23). 2) CLAUDE.md:85,165 email scheme `{latin}.centeradmin@mutqin.ly` — code is `{latin}_{code}@mutqin.ly` (LoginEmail.php:28). 3) CLAUDE.md:152/220 weekly-tests 'index/store/destroy/show' & 'no update endpoint' — code: index/store/show/UPDATE, destroy gone (routes/api.php:164). 4) CLAUDE.md:32,118,206 demo-accounts panel — removed from frontend (b2501ae), endpoint orphaned. 5) CLAUDE.md:25 '20 feature-test files' — 38. 6) CLAUDE.md:14-15 PHP only at C:\xampp\php\php.exe — path absent on this machine (Herd PHP 8.4 at C:\Users\HP\.config\herd\bin). 7) Undocumented routes/controllers: MessageController (+6 routes), AdminUserController, /manager/parents, /manager/parents/search semantics, /manager/students/{id}/teacher, /manager/teachers/{id}/performance|status, /manager/reports/center|teacher/{id}|student/{id}, /centers/{id}/stats|teachers|students, /students/{id}/details|day; PWA manifest; n8n; .cpanel.yml. 8) DEPLOYMENT.md:14 'APP_ENV=production → empty demo list' false under APP_DEBUG=true; DEPLOYMENT.md:11 & frontend README:19 say CORS is ['*'] — code reads CORS_ALLOWED_ORIGINS (closed default). 9) Demo password stated three ways: CLAUDE.md:32 [redacted-demo-password], DEPLOY_LOG.md:49 [redacted-demo-password], frontend README:21 password. 10) DEPLOYMENT.md:37 OTP log 'local only' — Log::info is unconditional (AuthController.php:218). 11) CLAUDE.md says 'Teacher self-profile ... token-owner only' but omits that admin uses the same routes (admin/profile.html).

== C. TENANT-ISOLATION TEST GAP LIST (cells with a guard in code but no negative test) ==
manager: PUT /manager/students/{id}/status foreign student (StudentController.php:585); GET /manager/reports/{center,at-risk,teachers}/pdf (no tests at all); GET /manager/center, /manager/students/next-code, /manager/teachers/next-code (global counters, no policy); POST /manager/attendance/import — refusal tested but not absence of name. teacher: DELETE /memorizations/{id} other teacher (MemorizationController.php:214); GET /weekly-tests/{id} other teacher (:177); POST /weekly-tests other teacher's student (:54); GET /reports/student/{id} and /pdf other teacher (ReportController.php:20, ReportPdfController.php:83); POST /attendance foreign ids silently skipped (AttendanceController.php:67); GET /attendance/report, GET /reports/weekly, GET /reports/teacher/pdf scoping; GET /teacher/messages/{student} after reassignment. admin: GET /teachers/{id}, /parents/search, /reports/admin/*/pdf, /reports/admin/missing-national-id (gate only inferred from sibling routes). auth: POST /auth/logout, GET /notifications index, POST /notifications/read-all. public: /public/demo-accounts under APP_DEBUG. Cells with NO guard (behaviour undefined, needs a decision + test): manager parent_id / guardian_id_number linking of another center's parent; managerSearchParents cross-center; PUT /weekly-tests/{id} by former teacher after transfer; PUT /centers/{id} is_active; TeacherController@update center move with assigned students; teacher '*' token + role flipped to admin (would pass AdminMiddleware).

== D. STRIDE PER TRUST BOUNDARY (mitigations present → residual) ==
1 Public web/API: unified login error, neutral OTP response, throttle on 3 routes, 422 validation, .htaccess fence, CORS closed by default → identifier enumeration (P1..Pn, timing oracle), /public/demo-accounts under APP_DEBUG, /public/stats user count, no security headers/WAF, no request ids or logs.
2 Parent token ['parent']: role+ability gate, parent_id check before any query, curated payloads → no self-service credentials, no logout-all, 7-day token, inherited message history readable by new teacher.
3 Teacher token ['*']: role gate + 13 teacher_id checks → wildcard ability (= admin at ability layer), xlsx name oracle, weekly-test author ownership, attendance silent skip, guardian display phone editable, hard-delete memorization, phone change without re-auth.
4 Manager token ['manager'] (center = tenant): role+ability+center_id gate, center_id forced from account in every write, 11 explicit scope 403s, primary-teacher rule under lockForUpdate, transfer scope (target manager only) → parents are global (search/overwrite/link/PII in response), xlsx roster oracle, next-code global counters, teacher password reset → message access, 403-vs-404 existence oracle (reportTeacher/reportStudent/teacherPerformance/toggleStatus vs showTeacher 404), incoming transfer rows show national_id (by design).
5 Admin token ['*']: role gate; ProductionSeeder env password; profile password change revokes tokens → single account, no MFA, min-6 password, no OTP recovery, no action audit, PUT /centers bypass, teacher/manager re-tenanting without cascade/revocation, cannot disable parents.
6 n8n automation: throttled login, JSON envelope → plaintext admin password in workflow, daily '*' token never revoked, full Student PII export, minors' names emailed over SMTP, no service account.
7 Fingerprint xlsx upload: mimes:xlsx + 5 MB, transaction, per-row scope checks, inactive rows refused → cross-center name oracle, PhpSpreadsheet toArray() with formula calculation on untrusted file (memory/CPU DoS on shared host), sequential S-codes guarantee cross-center collisions.
8 SMS OTP path: bcrypt-hashed OTP, 10 min, 5 attempts, one active per user, dev_otp local-only, tokens revoked on success → plaintext OTP Log::info (LOG_LEVEL-dependent), phone single factor and changeable by teacher (no re-auth) and by manager (ParentResolver), managers excluded from recovery, no gateway yet.
9 cPanel shared host (Libyan Spider, no SSH): .env/storage excluded from deploy, .htaccess deny, LOG_LEVEL=error, daily log rotation → manual File-Manager deploys with no integrity check, no artisan (cannot prune tokens or run key rotation quickly), logs and DB dumps under the same account, APP_DEBUG-toggle risk, single shared PHP pool.

== E. MINIMAL INCIDENT-RESPONSE RUNBOOK (as the system stands today) ==
Detection: none automatic. Manual: phpMyAdmin → personal_access_tokens (count per tokenable_id, last_used_at), password_change_logs, users.status_changed_at; compare users table against last mysqldump for role/center/email changes (no audit exists).
Containment by principal: teacher → PUT /teachers/{id}/status {is_active:false} (admin) or PUT /manager/teachers/{id}/status (manager) — revokes tokens immediately; manager → PUT /admin/managers/{id}/status false; whole center → PUT /centers/{id}/status false (revokes all members, blocks login); parent → NO API PATH: phpMyAdmin `UPDATE users SET is_active=0 WHERE id=?` (login check is generic) + `DELETE FROM personal_access_tokens WHERE tokenable_id=?`; admin → POST /profile/password (revokes own tokens) or SQL; system-wide → `TRUNCATE personal_access_tokens` (everyone incl. n8n re-authenticates). Note: rotating APP_KEY does NOT revoke Sanctum tokens (they are sha256 hashes).
Eradication: rotate admin and n8n credentials (n8n Set node), review CORS_ALLOWED_ORIGINS/APP_DEBUG on the host, re-upload known-good code from the tagged commit (DEPLOY_LOG.md entry), restore from the daily mysqldump if integrity is in doubt.
Notification (minors' data: names, national IDs, guardian phones, attendance, memorization, messages): admin informs affected center managers within 24h with the list of student ids; managers inform guardians through the center (SMS not available in-product); keep a dated incident log entry in a new docs/security/INCIDENTS.md; target 72h for guardian notification; record scope, root cause, and the matrix cell that failed.
Owners to name: product owner (decision + guardian communication), technical owner (token revocation + code), hosting contact (Libyan Spider ticket for APP_DEBUG/logs), DBA (phpMyAdmin actions above).

== F. REMAINING LOWER-SEVERITY OBSERVATIONS (not in the 15 findings) ==
- Existence oracles: 403 (foreign) vs 404 (missing) in reportTeacher/reportStudent/teacherPerformance/student toggleStatus/changeTeacher vs 404 in showTeacher/updateTeacher; managerStore returns 422 exists vs 403 foreign; /manager/students/next-code and /manager/teachers/next-code reveal system-wide counters to a tenant; /public/stats exposes total users.
- ReportPdfController.php:84 `abort(403, ...)` returns HTML unless Accept: application/json.
- No rate limit on authenticated writes: message send (2000 chars, unlimited), /profile/password current-password guessing with a stolen token, /manager/parents/search (10 rows/call), xlsx import.
- No security headers (HSTS/CSP/X-Frame) added by the app; relies on host.
- No consent/notification to the guardian when a child is transferred between centers or when a new child is attached to their account.
- password min:6 also applies to guardian accounts created by staff; the staff member chooses the parent's password (ParentResolver requires it) — parents never set their own initial secret.
- Transfer approve does not lockForUpdate the student row nor re-check from_center_id.
- Messages: notifications carry 80-char excerpts (InAppNotification) to the recipient — fine, but they persist after thread reassignment.
- PhpSpreadsheet `toArray()` (AttendanceImportController.php:35) evaluates formulas and loads the whole sheet; a crafted 5 MB xlsx can exhaust shared-host memory (DoS, not data risk; transaction prevents partial writes).
- Static analysis only: vendor/ and .env are absent in this checkout so artisan route:list and the test suite could not be executed; route count (107) was derived by expanding apiResource declarations by hand and matches the brief.
