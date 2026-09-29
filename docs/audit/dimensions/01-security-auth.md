# Security & Authentication

[← Enterprise Audit](../enterprise-audit.md)

**Score 64 / 100** — Needs real work · maturity **L3** · weight 14% · auditor scored 66, judge calibrated to 64

The authorization core is genuinely strong and unusually well-tested for a project of this size: all 93 role-gated endpoints sit behind one of four middleware aliases that check role AND Sanctum token ability, ownership checks (teacher->student, parent->child, manager->center) are consistent across every controller including the newer MessageController/AdminUserController, server-forced scope fields, no mass-assignment from request bodies, fully parameterised raw SQL, hashed/expiring OTP, token revocation on password change/deactivation, closed-by-default CORS, clean secrets hygiene, and a frontend that escapes every rendered string. That earns 'functional but needs real work' rather than 'significant risk'. What keeps it out of the 75+ band against an enterprise/level-5 bar: (1) per DEPLOY_LOG.md the live production DB is still the demo dump whose shared password is documented in the repo (cleanup marked pending); (2) parents and center managers have no password change or recovery path at all in production (OTP is teacher/parent only and has no SMS gateway; the manager is excluded; parents keep the staff-chosen password forever and cannot revoke a lost device); (3) plaintext OTP written to the application log; (4) 6-character password minimum with predictable login identifiers (T1/P1/CA1, {latin}_{code}@mutqin.ly) and IP-only throttling with no per-account lockout; (5) zero security headers; (6) no general audit trail or login audit; (7) no CI, dependency audit, or SAST; (8) a mobile-unfriendly session model (single fixed 7-day token, no per-device listing/revoke, no refresh); (9) a public user-directory endpoint gated on APP_DEBUG despite the codebase's own warning that APP_DEBUG is not a safe gate; (10) several smaller data-exposure and validation gaps. Maturity 3 (defined): the security model is documented, consistently applied and guarded by 173 feature tests, but nothing is measured or monitored (no audit log, no alerting, no dependency scanning), so it is not yet level 4.

> **Calibration:** The critical (production still running the demo dump with a repo-documented shared password, wipe pending per DEPLOY_LOG) survived verification, and this dimension omitted the Laravel v11.51.0 security-EOL finding that devops confirmed as high; a small downward correction keeps the highest-weight dimension honest without denying the genuinely strong authorization core.

## What is already strong

- Dual role + token-ability gate is enforced on every role-scoped route: routes/api.php groups all 93 role-gated endpoints under middleware('parent'|'manager'|'admin'|'teacher') (lines 43, 53, 94, 133); each middleware checks both, e.g. CenterManagerMiddleware.php:20 `if (!$user || !$user->isCenterManager() || !$user->tokenCan('manager') || !$user->center_id)` and AdminMiddleware.php:17 `!$user->isAdmin() || !$user->tokenCan('*')`. Abilities are issued per role at login (AuthController.php:62-64). The only auth:sanctum-only routes (dashboard, notifications, athman) either scope by $request->user() (NotificationController.php:20,49) or serve read-only reference data.
- Escalation is tested, not assumed: RoleMatrixTest.php:53-63 tampers the role to admin after issuing a parent token and asserts 403 on /api/teachers; CenterManagerTest.php:51-62 does the same for a manager token. 38 feature-test files / 173 test methods, 23 explicit 403 assertions, plus OwnershipTest, MessagingTest (parent cannot message for another's child, admin is not a party), ManagerAttendanceReviewTest (cross-center correction 403), OtpResetTest (neutral response, attempts cap, token revocation).
- Ownership/IDOR checks are consistent everywhere I read: MessageController.php:26-49 resolveStudent (parent_id / teacher_id strict compare, admin rejected), WeeklyTestController.php:54,122,177, MemorizationController.php:168,214, StudentController.php:344,494,580,624,760 (forbidUnlessOwnStudent), StudentRequestController.php:199-215 assertManagerScope, ReportPdfController.php:83, AttendanceImportController.php:193-205 (out-of-center row rejected, other teacher's row silently ignored). 43 in-controller role checks provide defence in depth behind the middleware.
- Scope fields are never trusted from the client: CenterManagerController.php:150-154 `'role' => 'teacher', 'center_id' => $centerId` forced; StudentController.php:178-180 `$request->merge(['center_id' => $user->center_id])` for managers; display codes come only from creating hooks (User.php:36-40, Center.php:25-29).
- No mass-assignment exposure: every one of the 14 models declares an explicit $fillable; grep for `->all()` into create/update returns zero hits — the only `$request->all()` (StudentController.php:171) is used to strip guardian keys, and all writes list columns explicitly (CenterController.php:42,233 use ->only([...])).
- Raw SQL is safe: all 47 whereRaw/selectRaw/orderByRaw sites use bound `?` parameters or constant literals; ArabicText::sqlNormalize (ArabicText.php:27-38) only wraps a code-constant column name in REPLACE(); AdminUserController.php:61 REGEXP binds an (int)-cast value.
- Token lifecycle: 7-day expiry (config/sanctum.php:55 `'expiration' => 10080`); User::recordPasswordChange() revokes all tokens (User.php:81); deactivating a teacher/manager/center revokes tokens (TeacherController.php:211, ManagerManagementController.php:174, CenterController.php:268); login of inactive users or members of inactive centers is blocked after the password check so account state is not leaked to guessers (AuthController.php:42-58).
- OTP flow is well designed: 6-digit random_int, stored as Hash::make (AuthController.php:141-146), 10-minute expiry, 5-attempt cap with deletion (:193-196), one active code per user, neutral response text on request, generic error on verify, `dev_otp` gated on `app()->environment('local')` not APP_DEBUG (:153) with the rationale written in code, routes throttled 5/min and 10/min (routes/api.php:20-21). OtpResetTest.php:18 asserts the code never leaks outside local.
- Login hardening: throttle:10,1 (routes/api.php:17), unified failure message for unknown identifier vs wrong password (AuthController.php:77-84, tested in AuthLoginTest.php:92), explicit Hash::check instead of guard-dependent Auth::attempt, bcrypt via 'hashed' cast (User.php:52) with BCRYPT_ROUNDS=12.
- CORS is closed by default: config/cors.php:14-17 reads CORS_ALLOWED_ORIGINS and falls back to ['http://localhost:8080'], never '*'; `'supports_credentials' => false` (:24).
- Upload safety: AttendanceImportController.php:21 `'attendance_file' => 'required|file|mimes:xlsx|max:5120'`, strict header check, all writes in one DB::transaction, active-student and centre-scope checks per row; phpoffice/phpspreadsheet 5.8.0 and mpdf 8.3.1 are current (composer.lock:3137, 2445).
- Frontend output encoding is disciplined: UI.escapeHtml (ui.js:116) wraps every API-sourced string rendered via innerHTML across admin/manager/teacher/parent pages and layout.js notifications (layout.js:207-213); the 54 interpolations not wrapped directly all go through escaping helpers (info/infoCell/badge/formModal title) or UI.initial (single char). Tokens travel only in the Authorization header (api.js:30; PDFs via fetch in ui.js:356), never in URLs.
- Secrets hygiene: `git ls-files` shows no .env, no logs; `git log --all -- backend/.env` is empty across 224 commits; backend/.env.production.example is placeholder-only; seed data is synthetic (LibyanDataSeeder.php:47-48 NATIONAL_ID_BASE/PHONE_PREFIXES, nextPhone/nextNationalId generators); ProductionSeeder.php:24-27 refuses an admin password under 8 chars instead of generating one silently.
- Deployment fence for shared hosting: backend/.htaccess:10-12 `Require all denied` over the whole Laravel tree with an explicit grant only in backend/public/.htaccess:3-5, and .cpanel.yml:6 excludes .env/storage/vendor from rsync.

## Level-5 target state

Every role can manage its own credentials and sessions from the mobile app: self-service password change and SMS-delivered OTP recovery for all four roles, forced rotation of staff-set passwords, per-device tokens with list/revoke and logout-everywhere, and a documented, tested error envelope for every failure shape. Authorization stays as it is today — dual role+ability middleware plus in-controller ownership checks — but is backed by a full audit trail (logins with IP/UA, every write to students/users/centers/records, no untracked hard deletes) that an admin can query and that alerts on anomalies. Password policy, per-account lockout, security headers, HSTS/TLS enforcement and PII-minimising lookups are in place, and a CI pipeline runs the feature suite, composer audit and static analysis on every change with the production artifact produced only from a green build; production never carries seeder credentials, and secrets/OTPs never touch logs.

## What the Flutter team must know

The API is already mobile-friendly at the transport level: stateless Bearer tokens (no cookies/CSRF), CORS is irrelevant for native clients (only Flutter Web needs CORS_ALLOWED_ORIGINS extended), and every protected call returns 401 on an invalid/expired token so the api.js pattern (clear token → login screen) ports directly. Things the mobile team must plan for: (1) tokens expire hard 7 days after login and there is no refresh endpoint — implement a re-login flow and store the token in Keychain/Keystore (flutter_secure_storage), never SharedPreferences; (2) login accepts email OR display code (T1/P1/CA1, case-insensitive) in the `email` field, and returns `data.token` + `data.user{role,center_id,type}` — the role drives the UI but the server re-checks it on every call, so a parent token receives 403 on all non-/parent/* routes; (3) error shapes are not uniform yet: envelope `{success:false,message,errors}` for controller-produced errors, bare `{message}` for 404/some 403s, and `{message,errors}` for 422 — parse defensively until the envelope finding is fixed; (4) do NOT ship 'change password' or 'forgot password' for parents or managers until the backend adds `/me/password` and an SMS gateway — today parents cannot change or recover passwords at all, and `dev_otp` will never appear against production; (5) there is no logout-everywhere or device list, so a lost-phone story cannot be offered yet — request `device_name` support on login; (6) file upload (xlsx import) is multipart `attendance_file`, ≤5 MB, xlsx only; PDFs require the Authorization header (no signed URLs), so download via authenticated HTTP client, not a browser intent; (7) notifications are polled (`GET /notifications`, 60 s on web) — no push infrastructure exists, so FCM would be a new backend feature; (8) parents' national IDs and children's data are returned in cleartext JSON — enforce TLS pinning or at least reject non-HTTPS base URLs in release builds.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [Production database still holds demo accounts with a repo-documented shared password (cleanup marked pending)](#prod-demo-credentials-live) | 🔴 critical | ✅ confirmed | NOW | S |
| [Parents and center managers cannot change or recover their password; parents keep the staff-chosen password forever](#parent-manager-no-credential-self-service) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Token model is not ready for multi-device mobile use: one fixed 7-day token, no per-device revocation, no logout-everywhere, no refresh](#mobile-session-model-gaps) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | M |
| [Public /api/public/demo-accounts returns every user's name, email and role whenever APP_DEBUG is true — a gate the codebase itself documents as unsafe](#debug-gated-user-directory) | 🟡 medium | ✅ confirmed | NOW | S |
| [OTP codes and phone numbers are written in plaintext to the application log](#otp-plaintext-in-logs) | 🟡 medium | ✅ confirmed | NEXT | S |
| [6-character minimum with no complexity/breach check, on predictable login identifiers, with IP-only throttle and no per-account lockout](#weak-password-policy-predictable-ids) | 🟡 medium | ✅ confirmed | NEXT | M |
| [No security headers anywhere (CSP, HSTS, X-Frame-Options, nosniff, Referrer-Policy) and bearer token lives in localStorage](#no-security-headers) | 🟡 medium | ✅ confirmed | NEXT | S |
| [No general audit log: who created/edited students, teachers, centers, memorizations, or who logged in from where, is not recorded](#no-audit-trail-or-login-audit) | 🟡 medium | ✅ confirmed | NEXT | L |
| [Any center manager can enumerate every parent's national ID number system-wide via a 3-digit prefix search](#manager-cross-center-parent-national-id-enumeration) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Parent↔teacher message threads are keyed by student only, so a newly assigned teacher inherits the full private history with the previous teacher](#message-history-visible-to-new-teacher) | 🟡 medium | ✅ confirmed | NEXT | M |
| [No CI pipeline, no composer audit, no static analysis — dependency and regression safety rely on a developer remembering to run tests locally](#no-ci-dependency-audit) | 🟡 medium | ✅ confirmed | NEXT | M |
| [Unhandled exceptions bypass the {success,message,data,errors} contract and leak internal model class names](#error-envelope-and-model-name-leak) | ⚪ low | ℹ️ informational | NOW | S |
| [Several endpoints pass unvalidated fields straight to the database or to Carbon, turning bad input into 500s instead of 422s](#unvalidated-inputs-cause-500s) | ⚪ low | ℹ️ informational | NEXT | S |
| [n8n attendance digest logs in daily as the human admin with a plaintext password and never revokes the '*' token it mints](#automation-runs-as-human-admin) | ⚪ low | ℹ️ informational | LATER | M |
| [Rate limiting keys on raw IP with no TrustProxies, and is_active is only enforced via token deletion rather than per request](#throttle-proxy-and-active-check-gaps) | ⚪ low | ℹ️ informational | LATER | S |

### Production database still holds demo accounts with a repo-documented shared password (cleanup marked pending)

<a id="prod-demo-credentials-live"></a>

`prod-demo-credentials-live` · 🔴 critical · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/DEPLOY_LOG.md:35-37`, `backend/DEPLOY_LOG.md:39-46`, `backend/database/seeders/LibyanDataSeeder.php:40-43`, `CLAUDE.md:32`, `DEPLOYMENT.md:17`

**Evidence**

```text
DEPLOY_LOG.md:35-37 (first deploy, the only entry marked done): «رُفعت ضمن mutqin-upload.zip مع قاعدة الديمو mutqin-demo-2026-09-07-clean.sql … **حالة الرفع:** done». The clean-DB replacement e7c50f9 (line 39-46) is «**حالة الرفع:** pending», as is c80e8f5 (line 55). LibyanDataSeeder.php:40-43: `public const ADMIN_PASSWORD = '[redacted-demo-password]'; MANAGER_PASSWORD = '[redacted-demo-password]'; TEACHER_PASSWORD = '[redacted-demo-password]'; PARENT_PASSWORD = '[redacted-demo-password]';` CLAUDE.md:32: «Demo accounts (password `[redacted-demo-password]`): `admin@mutqin.ly` (admin)…». DEPLOYMENT.md:17 itself lists «غيّر كلمات مرور حسابات الـ seeder أو احذفها» as a mandatory pre-production step. The production host (mutqin.ly), cPanel user and DB name are also written in DEPLOY_LOG.md:3-5.
```

**Why it matters**

Per the repo's own deploy log the live system at mutqin.ly runs with `admin@mutqin.ly` and dozens of teacher/manager/parent accounts whose password is a fixed, documented string. Anyone with repo access (or who guesses `[redacted-demo-password]/2027`) has full admin over the production instance and its children's PII. The `/api/public/demo-accounts` endpoint is disabled in production, but the seeder emails follow a predictable scheme ({latin}_{code}@mutqin.ly, admin@mutqin.ly) so the list is not needed. This is an operational rather than code defect, but it is the single highest-probability compromise path today.

**Recommendation**

Before any further exposure: import the clean dump (or run ProductionSeeder equivalent SQL) on [redacted-db-name], rotate the admin password to a unique value delivered out-of-band, invalidate all rows in personal_access_tokens, and mark the DEPLOY_LOG entry done. Add a startup guard (e.g. a scheduled/boot check or a test in the deploy checklist) that fails when APP_ENV=production and any user still authenticates with a seeder password. Remove the seeder passwords from CLAUDE.md and keep them only in the seeder class.

<details><summary>Verification — 2 independent reviewers</summary>

**Upheld** · confidence 75% · corrected severity: high

The quoted evidence exists and reads as the auditor says. backend/DEPLOY_LOG.md:35-37 records the only "done" upload as the full package plus the demo database dump mutqin-demo-2026-09-07-clean.sql; the clean-DB replacement e7c50f9 (lines 39-46) and the c80e8f5 re-seed (lines 48-55) are both "pending". DEPLOYMENT.md:17 (item 8) explicitly lists rotating/removing seeder accounts as a mandatory pre-production step, and CLAUDE.md:32 publishes the shared password. So, by the repo's own record, the live DB at mutqin.ly is the demo dataset with a documented shared password. I cannot verify the live server (read-only audit; no probing of production), and the log header notes the 09-07 upload originally went to InfinityFree before the 09-08 move to Libyan Spider, so the exact state of [redacted-db-name] is inferred, not proven — but nothing in the repo indicates the clean dump was ever imported.

Corrections to the finding: (1) The seeder constants cited (LibyanDataSeeder.php:40-43, '[redacted-demo-password]') post-date the deployed dump — c80e8f5 is dated 2026-09-10 and is itself "pending". The dump deployed on 2026-09-07 came from the earlier DatabaseSeeder/ExtraDataSeeder era whose password is '[redacted-demo-password]' (ExtraDataSeeder.php:35, commit 389f76b, CLAUDE.md:32). The practical claim (documented shared password) still holds, the string is just 2026 not 2027. (2) The impact statement "children's PII" is overstated: the deployed dataset is synthetic demo data (LibyanDataSeeder header explicitly marks national IDs/phones as synthetic and the log describes it as demo). No real minors' data is exposed until real data is entered, which per e7c50f9 is gated on the clean import. (3) Mitigations that exist: /api/public/demo-accounts returns [] outside local/debug (DashboardController.php:105-107); login.html/login.js no longer display or autofill the demo password; LibyanDataSeeder refuses to run outside APP_ENV=local (line 108); ProductionSeeder requires ADMIN_INITIAL_PASSWORD >= 8 chars with no silent default; the clean dump ships without personal_access_tokens rows. None of these changes the fact that the currently deployed DB (if the log is accurate) still accepts admin@mutqin.ly / [redacted-demo-password].

Net: real, operational, and the single most likely compromise path (admin takeover of a publicly reachable instance), but with synthetic data only and a prepared, documented remediation already waiting to be applied. That is High rather than Critical; it becomes Critical the moment real student/guardian data is entered before the clean import.

```text
backend/DEPLOY_LOG.md:35-37 — 2026-09-07 package uploaded «مع قاعدة الديمو mutqin-demo-2026-09-07-clean.sql», «حالة الرفع: done». backend/DEPLOY_LOG.md:39-46 — e7c50f9 clean DB (admin only, no tokens) «حالة الرفع: pending»; :48-55 c80e8f5 ([redacted-demo-password] demo) also pending, so the [redacted-demo-password] constants in LibyanDataSeeder.php:40-43 were never deployed. The deployed 09-07 dump predates that and uses the earlier demo password: [redacted]/database/seeders/ExtraDataSeeder.php:35 `$demoPassword = Hash::make('[redacted-demo-password]');`, CLAUDE.md:32 «Demo accounts (password `[redacted-demo-password]`): admin@mutqin.ly …», git 389f76b «new demo password [redacted-demo-password]». DEPLOYMENT.md:17 item 8 lists rotating/removing seeder accounts as mandatory pre-production. Partial mitigations: backend/app/Http/Controllers/Api/DashboardController.php:105-107 (demo-accounts list empty outside local/debug); frontend-html/login.html and js/pages/login.js contain no demo password text; LibyanDataSeeder.php:108-111 refuses non-local env; ProductionSeeder.php:25-28 requires ADMIN_INITIAL_PASSWORD (>=8 chars). Live state of [redacted-db-name] could not be verified (read-only review).
```

---

**Upheld** · confidence 85% · corrected severity: critical

The cited evidence is accurate. backend/DEPLOY_LOG.md:35-37 records the only entry marked done as the upload of mutqin-upload.zip together with the demo database mutqin-demo-2026-09-07-clean.sql to the live host mutqin.ly (cPanel user, DB name [redacted-db-name] at lines 3-5); the clean-DB replacement e7c50f9 (lines 39-46) and the c80e8f5 re-dump (line 55) are both still "pending". I verified via git that at the deploy commits (f868880..fbe25fc, 2026-09-07) DatabaseSeeder already called LibyanDataSeeder and its constants were ADMIN/MANAGER/TEACHER/PARENT_PASSWORD = '[redacted-demo-password]', so the dump uploaded on 09-07 was seeded with that fixed password and admin@mutqin.ly. No layer mitigates this: the login throttle (10/min) is irrelevant when the password is known; disabling /api/public/demo-accounts in production (DashboardController::demoAccounts) only hides the list, and the seeder email scheme is predictable and documented; token revocation, dual role+ability gates and feature tests all sit behind authentication and cannot help against a valid admin credential. One aggravating fact the auditor missed: `git remote`/`gh repo view` show the repository is PUBLIC (github.com/muad03/MUTQIN, isPrivate=false), so the password, host, admin email and DB name are readable by anyone on the internet, not just people with repo access. Minor corrections: CLAUDE.md:32 says `[redacted-demo-password]` which is stale — the actual seeded password is `[redacted-demo-password]` (also written in plain text at DEPLOY_LOG.md:49); and the "children's PII" impact is overstated for today because the currently deployed data is synthetic demo data — but admin@mutqin.ly is a real admin account on the production domain, and real data will be entered into that instance, so full-admin takeover of the live system remains. I could not verify the live server state (SSH disabled, no access), so the status might be stale, but the repo's own record is the only evidence and it says the cleanup is pending. Critical is justified: publicly documented, unrotated admin credential on the production host with an S-effort fix.

```text
backend/DEPLOY_LOG.md:35-37 — demo DB `mutqin-demo-2026-09-07-clean.sql` uploaded to mutqin.ly, «حالة الرفع: done»; backend/DEPLOY_LOG.md:39-46 and :48-55 — clean/re-seeded replacements «pending»; backend/DEPLOY_LOG.md:49 — password `[redacted-demo-password]` written in plain text; backend/DEPLOY_LOG.md:3-5 — host mutqin.ly, cPanel user `[redacted-cpanel-user]`, DB `[redacted-db-name]`. backend/database/seeders/LibyanDataSeeder.php:40-43 — all four role passwords = '[redacted-demo-password]' (identical at deploy commit fbe25fc, verified with `git show fbe25fc:backend/database/seeders/LibyanDataSeeder.php`); backend/database/seeders/DatabaseSeeder.php:19 — `$this->call(LibyanDataSeeder::class)` (so the 09-07 dump contained these accounts). Repository is public: `git remote -v` → https://github.com/muad03/MUTQIN.git, `gh repo view --json isPrivate` → false. CLAUDE.md:32 lists `[redacted-demo-password]` — stale; actual seeded password is `[redacted-demo-password]`. backend/app/Http/Controllers/Api/DashboardController.php:101-107 — demo-accounts endpoint returns [] outside local/debug (hides the list only, no auth mitigation). DEPLOYMENT.md:17 — step 8 "change or delete seeder account passwords" is listed as mandatory pre-production and is not recorded as done.
```

</details>

### Parents and center managers cannot change or recover their password; parents keep the staff-chosen password forever

<a id="parent-manager-no-credential-self-service"></a>

`parent-manager-no-credential-self-service` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/routes/api.php:43-50`, `backend/routes/api.php:53-91`, `backend/routes/api.php:135-137`, `backend/app/Http/Controllers/Api/AuthController.php:132`, `backend/app/Http/Controllers/Api/AuthController.php:184`, `backend/app/Http/Controllers/Api/AuthController.php:216-220`, `backend/app/Support/ParentResolver.php:73-92`

**Evidence**

```text
The parent group (routes/api.php:43-50) contains only children/students/messages; the manager group (:53-91) has no profile route; the only self-service password endpoint is teacher-gated: routes/api.php:137 `Route::post('/profile/password', [TeacherProfileController::class, 'changePassword'])` inside `Route::middleware('teacher')` (:133) — parents (ability 'parent') and managers (ability 'manager') get 403 there. OTP reset excludes managers: AuthController.php:132 and :184 `User::whereIn('role', ['parent', 'teacher'])`. And OTP is not delivered in production: AuthController.php:216-220 `protected function sendOtp(...) { Log::info(...); // TODO(SMS): SmsGateway::send(...)`. Parent passwords are chosen by staff at student creation: ParentResolver.php:73-76 `$password = $g['password'] ?? null; if (!$password) throw ValidationException::withMessages(['guardian_password' => ['كلمة مرور ولي الأمر مطلوبة لإنشاء حسابه']])`.
```

**Why it matters**

Every parent's credential is known to the admin/manager who created it and can never be rotated by the parent; a manager whose password leaks or who loses a phone has no way to change it or revoke sessions (only the admin can, via PUT /admin/managers/{id}); in production a parent who forgets their password has no recovery path at all because no SMS gateway exists. For a public Flutter app whose primary audience is parents this is a launch blocker: the app cannot offer 'change password' or 'forgot password' for its largest role, and lost-device scenarios cannot be contained by the user.

**Recommendation**

Add role-agnostic self-service endpoints under auth:sanctum (any role): `PUT /me/password` (current password required, reuse recordPasswordChange('self') which revokes all tokens) and `POST /auth/logout-all`. Extend forgotPasswordRequest/Verify to include center_manager (or document why not). Wire a real SMS provider into sendOtp() (Libyana/Almadar) before the mobile launch, or provide an admin-relayed reset with forced change-on-first-login. Add a `must_change_password` flag set whenever staff set/reset a password so the first mobile login forces rotation.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual claims hold when traced. routes/api.php: the parent group (lines 43-50) has only children/students/messages; the manager group (53-91) has no profile/password route; the only self-service password endpoint is `POST /profile/password` at line 137 inside `Route::middleware('teacher')` (line 133), and TeacherMiddleware rejects any user whose role is not teacher/admin or whose token lacks '*' — so parent ('parent' ability) and manager ('manager' ability) tokens get 403. AuthController::forgotPasswordRequest/Verify filter `User::whereIn('role', ['parent', 'teacher'])` (lines 132, 184), so center_manager is excluded from OTP; sendOtp() (216-220) only logs and has a TODO for the SMS gateway. ParentResolver 73-77 requires a staff-supplied guardian_password on parent creation. No `must_change_password`/`logout-all` exists anywhere in app/ or routes/. Frontend parent/ pages have no password UI either. So the finding is correct, not mitigated.

However it is over-rated. Nuances that soften impact: (1) parents DO have a recovery/rotation path in code — the OTP flow — and forgotPasswordVerify calls recordPasswordChange('otp') which revokes all tokens, so the lost-device scenario for parents is covered as soon as delivery is wired; the missing SMS gateway is an explicitly tracked launch item (DEPLOYMENT.md line 12), not an undiscovered gap. (2) The only role with truly zero self-service is center_manager, and the admin can reset their password via PUT /admin/managers/{id}, which also calls recordPasswordChange('admin') and revokes tokens; deactivation also revokes tokens; tokens expire in 7 days. (3) The staff who choose a parent's password (admin/manager) already have strictly broader access than the parent account grants (read-only child data), so 'password known to staff' adds little confidentiality exposure. (4) The 'public Flutter app / launch blocker' framing is speculative — nothing in the repo indicates a mobile client; the shipped client is the static HTML frontend. This is a security-hygiene/product gap (no credential rotation for two roles, no manager recovery), not an exploitable vulnerability, so medium fits better than high.

```text
backend/routes/api.php:133-137 — `/profile/password` only under `Route::middleware('teacher')`; backend/app/Http/Middleware/TeacherMiddleware.php:17 — `!in_array($user->role, ['teacher','admin']) || !$user->tokenCan('*')` → 403 for parent/manager tokens. backend/app/Http/Controllers/Api/AuthController.php:132 and :184 — `User::whereIn('role', ['parent','teacher'])` excludes center_manager from OTP (parents ARE included); :211 `$user->recordPasswordChange('otp')` revokes tokens on OTP reset; :216-220 sendOtp() logs only. backend/app/Http/Controllers/Api/ManagerManagementController.php:126-134 — admin can reset a manager's password and `recordPasswordChange('admin')` revokes tokens (compensating control). DEPLOYMENT.md:12 — SMS gateway already listed as a required pre-production item. No `must_change_password` or logout-all anywhere in backend/app or backend/routes.
```

</details>

### Token model is not ready for multi-device mobile use: one fixed 7-day token, no per-device revocation, no logout-everywhere, no refresh

<a id="mobile-session-model-gaps"></a>

`mobile-session-model-gaps` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:64`, `backend/app/Http/Controllers/Api/AuthController.php:91`, `backend/config/sanctum.php:53-55`, `backend/routes/console.php:1-9`

**Evidence**

```text
AuthController.php:64 `$token = $user->createToken('auth_token', $abilities)->plainTextToken;` — every login creates another token named 'auth_token' with no device name, no cap, and no listing endpoint. Logout deletes only the current token: AuthController.php:91 `$request->user()->currentAccessToken()->delete();`. Expiry is a hard 7 days from creation: config/sanctum.php:55 `'expiration' => 10080`. routes/console.php contains only the default `inspire` command — `sanctum:prune-expired` is never scheduled, so expired rows accumulate.
```

**Why it matters**

A mobile user cannot see or revoke a lost phone's session (the only revoke path is a password change, which parents/managers cannot perform — see previous finding). Weekly forced re-login with no refresh mechanism hurts retention and pushes users toward weak, memorable passwords. Unbounded token creation lets a compromised account keep many live tokens. These are the decisions the mobile team needs settled before building the auth layer, which is why this is 'now'.

**Recommendation**

Accept a `device_name` on login and use it as the token name; add `GET /auth/sessions` (list own tokens: name, last_used_at, created_at) and `DELETE /auth/sessions/{id}` plus `POST /auth/logout-all`; cap concurrent tokens per user (e.g. 5, delete oldest). Decide expiry policy explicitly for mobile: either keep 7d with a lightweight silent re-auth using device-bound credentials, or move to a longer TTL keyed on `last_used_at` (sliding) via a custom middleware. Schedule `sanctum:prune-expired --hours=24` (or a cron-hit endpoint given the host has no CLI).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted code is accurate: AuthController.php:64 creates a token named 'auth_token' with no device name and no cap; logout (line 91) deletes only the current token; config/sanctum.php:55 sets a hard 7-day expiry; routes/console.php has only 'inspire' and nothing schedules sanctum:prune-expired; routes/api.php has no /auth/sessions or logout-all route. So the finding is factually correct as a description of the token model. However, the impact is overstated and part of it is wrong. (1) "the only revoke path is a password change, which parents/managers cannot perform" is inaccurate for parents: the OTP forgot-password flow (AuthController.php:132/184 scopes to role in [parent, teacher]) ends in recordPasswordChange('otp') (line 203), and User::recordPasswordChange (User.php:81) deletes all tokens — so a parent can 'logout everywhere' by resetting their password via OTP. Managers indeed lack self-service, but the admin can reset a manager's password (ManagerManagementController.php:132-134 → recordPasswordChange('admin')) or deactivate them (line 174 → tokens()->delete()), both of which revoke every session; the same exists for teachers (TeacherController.php:211) and whole centers (CenterController.php:268). (2) The finding is premised on a 'mobile team' and multi-device use; the repo contains no mobile client (static frontend-html only), so this is roadmap/design guidance rather than an exploitable weakness. Tokens are SHA-256 hashed at rest, ability-scoped, and bounded to 7 days, which the project documents as a deliberate S1 decision. (3) Unbounded token creation per login is real but login is throttled (10/min) and each token is individually harmless past 7 days; accumulation of expired rows is a hygiene/DB-growth issue, not a security exposure. Net: real gap (no per-device session listing/revocation, no logout-all endpoint, no prune scheduling) but a low-severity hardening/roadmap item, not medium.

```text
Confirmed: backend/app/Http/Controllers/Api/AuthController.php:64 `$user->createToken('auth_token', $abilities)`; :91 `currentAccessToken()->delete()`; backend/config/sanctum.php:55 `'expiration' => 10080`; backend/routes/console.php has only the inspire command; backend/routes/api.php:27 is the sole logout route (no /auth/sessions, no logout-all). Mitigations the finding omits: AuthController.php:132,184 OTP reset available to role in ['parent','teacher'] → :203 `recordPasswordChange('otp')`; app/Models/User.php:81 `$this->tokens()->delete()` inside recordPasswordChange (revokes all sessions); ManagerManagementController.php:132-134 admin password reset → recordPasswordChange('admin'), :174 deactivation → `tokens()->delete()`; TeacherController.php:211 and CenterController.php:268 revoke tokens on deactivation. No mobile client exists in the repo (frontend-html is the only client).
```

</details>

### Public /api/public/demo-accounts returns every user's name, email and role whenever APP_DEBUG is true — a gate the codebase itself documents as unsafe

<a id="debug-gated-user-directory"></a>

`debug-gated-user-directory` · 🟡 medium · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/DashboardController.php:101-121`, `backend/app/Http/Controllers/Api/AuthController.php:149-153`, `backend/routes/api.php:23`, `backend/app/Http/Controllers/Api/DashboardController.php:124-134`

**Evidence**

```text
DashboardController.php:105 `if (! app()->environment('local') && ! config('app.debug')) { return ...[] }` then :109-111 `User::orderByRaw(...)->get(['name', 'email', 'role'])` for the whole users table, on an unauthenticated, unthrottled route (routes/api.php:23). Contrast AuthController.php:149-152 for dev_otp: «⚠️ خطير: لا تعتمد على APP_DEBUG — قد يبقى مفعّلاً بالخطأ في الإنتاج/staging … الشرط environment('local') يضمن…». publicStats (:124-134) additionally exposes `'users' => User::count()` publicly.
```

**Why it matters**

A single mis-set APP_DEBUG=true on staging or production (the exact failure mode the OTP code guards against) turns an unauthenticated endpoint into a complete directory of admins, managers, teachers and parents (names + login emails), which directly feeds the credential-stuffing risk created by the 6-char password policy and predictable identifiers. Any Flutter build pointing at a staging host would also leak this.

**Recommendation**

Gate on `app()->environment('local')` only (drop the `config('app.debug')` clause), and preferably return fixed demo identities from config rather than the live users table. Remove `users` from publicStats or replace with an aggregate that is not a headcount of real accounts. Add a feature test asserting the endpoint is empty when APP_ENV=testing with APP_DEBUG=true.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Evidence verified verbatim. DashboardController.php:105 gates demoAccounts on `! environment('local') && ! config('app.debug')`, so APP_ENV=production with APP_DEBUG=true still dumps `User::get(['name','email','role'])` for the entire users table (all four roles, including parents' login emails) from GET /api/public/demo-accounts, which routes/api.php:23 registers with no auth and no throttle. bootstrap/app.php adds no global API throttle (Laravel 11 does not apply throttle:api by default), and no feature test in backend/tests references demo-accounts or public/stats. AuthController.php:149-152 explicitly documents APP_DEBUG as an unsafe gate for dev_otp and uses environment('local') alone — an inconsistency inside the same codebase. DEPLOYMENT.md:14 even states that APP_ENV=production alone empties the endpoint, which is false given the OR-style clause, so an operator following the deployment checklist who leaves APP_DEBUG=true (or flips it on to diagnose a staging issue) would leak the directory while believing it is closed. publicStats (:124-134) also exposes User::count() publicly, unconditionally — minor. Mitigations that do exist: .env.production.example sets APP_DEBUG=false, and the data is only names/emails/roles (no hashes, phones, or codes). The finding is conditional on a misconfiguration and does not by itself grant access, so medium is the right rating — not higher (login is throttled 10/min per IP, so a leaked directory only aids slow credential stuffing) and not lower (full PII enumeration of parents/teachers via one unauthenticated GET, in a product whose own docs give misleading assurance). Not refuted.

```text
backend/app/Http/Controllers/Api/DashboardController.php:105 `if (! app()->environment('local') && ! config('app.debug'))` — returns full users table (:109-111 `->get(['name','email','role'])`) when APP_DEBUG=true in any environment. backend/routes/api.php:23 — route has no auth and no throttle middleware; backend/bootstrap/app.php registers no global API throttle. backend/app/Http/Controllers/Api/AuthController.php:149-153 — same codebase uses `environment('local')` alone for dev_otp with a warning against relying on APP_DEBUG. DEPLOYMENT.md:14 incorrectly claims `APP_ENV=production` alone empties `/api/public/demo-accounts`. No test in backend/tests/Feature covers demo-accounts or public/stats. Partial mitigation: backend/.env.production.example:15 `APP_DEBUG=false`.
```

</details>

### OTP codes and phone numbers are written in plaintext to the application log

<a id="otp-plaintext-in-logs"></a>

`otp-plaintext-in-logs` · 🟡 medium · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:216-220`, `backend/.env.production.example:38-39`

**Evidence**

```text
AuthController.php:218 `Log::info("OTP password-reset for user #{$user->id} (phone {$user->phone}): {$otp}");` runs in every environment; only the production template's `LOG_LEVEL=error` (.env.production.example:39) suppresses it, and the shared host stores logs at public_html/backend/storage/logs/ (DEPLOY_LOG.md:19) behind a single .htaccess deny rule.
```

**Why it matters**

Any environment where LOG_LEVEL is info/debug (staging, a misconfigured prod, the local dev box that DEPLOYMENT.md:37 says is where the admin reads the OTP) has a file containing live password-reset codes and the phone numbers they belong to. Combined with a log-shipping tool or a mis-set .htaccess this is a full account-takeover primitive for every teacher and parent. Secrets must never depend on log level for confidentiality.

**Recommendation**

Remove the OTP from the log line (log `user_id` and a masked phone only). If the admin-relay flow is still needed, expose the code through the already-gated `dev_otp` response in local only. When the SMS gateway is added, log the provider message-id, never the payload.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Evidence verified. backend/app/Http/Controllers/Api/AuthController.php sendOtp() unconditionally calls `Log::info("OTP password-reset for user #{$user->id} (phone {$user->phone}): {$otp}")` with no environment guard — unlike the `dev_otp` response field a few lines above, which is correctly gated by `app()->environment('local')`. So the only thing keeping live reset codes out of the log in production is LOG_LEVEL: the production template (.env.production.example:39) sets `error`, but .env.example:21 sets `debug`, and config/logging.php defaults to `debug` when LOG_LEVEL is unset. DEPLOYMENT.md:37 even claims the log exposure is "local only", which is factually wrong for the code as written — the response is local-only, the log line is not. Mitigations found: (1) backend/.htaccess denies all HTTP access to the Laravel tree (so the log is not web-readable unless the deny rule is lost); (2) the OTP is hashed in otp_resets, expires in 10 min, and is limited to 5 attempts; (3) production template uses LOG_LEVEL=error. None of these remove the secret from the log; they only reduce who can read it. No feature test covers this. Exploitation needs a second weakness (log read via shell/backup/log shipping/misconfig), and the code is an explicit SMS-gateway placeholder, so this is not high. Medium is appropriate: secret + PII (phone) in application logs, contradicting the project's own docs, with a trivial fix.

```text
backend/app/Http/Controllers/Api/AuthController.php:216-220 — `protected function sendOtp(User $user, string $otp): void { Log::info("OTP password-reset for user #{$user->id} (phone {$user->phone}): {$otp}"); ... }` with no environment() guard, whereas AuthController.php:153-156 gates `dev_otp` on `app()->environment('local')`. backend/config/logging.php:64,71 — `'level' => env('LOG_LEVEL', 'debug')` (default debug when unset). backend/.env.example:21 `LOG_LEVEL=debug`; backend/.env.production.example:38-39 `LOG_CHANNEL=daily` / `LOG_LEVEL=error`. DEPLOYMENT.md:37 incorrectly states the log exposure is "بيئة local فقط". Partial mitigation: backend/.htaccess `Require all denied` blocks HTTP reads of storage/logs; OTP stored hashed with 10-min expiry and 5-attempt cap (AuthController.php:190-198).
```

</details>

### 6-character minimum with no complexity/breach check, on predictable login identifiers, with IP-only throttle and no per-account lockout

<a id="weak-password-policy-predictable-ids"></a>

`weak-password-policy-predictable-ids` · 🟡 medium · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/TeacherController.php:64`, `backend/app/Http/Controllers/Api/ManagerManagementController.php:67`, `backend/app/Http/Controllers/Api/CenterManagerController.php:122`, `backend/app/Http/Controllers/Api/TeacherProfileController.php:78`, `backend/app/Http/Controllers/Api/AuthController.php:170`, `backend/app/Http/Controllers/Api/StudentController.php:214`, `backend/app/Support/LoginEmail.php:9`, `backend/app/Http/Controllers/Api/AuthController.php:36-39`, `backend/routes/api.php:17`

**Evidence**

```text
Five creation/change sites use `'password' => 'required|min:6|confirmed'` (TeacherController.php:64, ManagerManagementController.php:67, CenterManagerController.php:122, TeacherProfileController.php:78) or `'required|min:6'` (AuthController.php:170 OTP reset, StudentController.php:214 guardian_password) — no `Password::defaults()`, letters/numbers, or `uncompromised()`. Identifiers are enumerable: LoginEmail.php:9 «{الاسم اللاتيني}_{الكود بحروف صغيرة}@mutqin.ly — muad_t1 · muad_ca1 · ahmed_p1» and login accepts bare display codes (AuthController.php:36 `whereRaw('UPPER(display_code) = ?')` — T1, P1, CA1…). Throttle is per IP only (routes/api.php:17 `throttle:10,1`); no failed-attempt counter per account exists in users or anywhere else. AuthController.php:36-39 performs no Hash::check when the user is not found, so response time differs for valid vs invalid identifiers.
```

**Why it matters**

An attacker can iterate P1…P500 / T1…T60 as usernames (they are sequential and shown in the UI) and test common 6-char passwords at 10/min per IP — trivially parallelised across IPs — with no account lockout or alerting. Bcrypt-timing lets them first confirm which identifiers exist. Parents' passwords are chosen by staff under time pressure (see parent finding), making weak values likely.

**Recommendation**

Centralise the rule as `Password::min(8)->letters()->numbers()->uncompromised()` in AppServiceProvider (Password::defaults) and use it at all six sites; add a per-account failed-login counter with temporary lockout (e.g. 10 failures → 15 min) and an admin-visible flag; perform a dummy `Hash::check` against a static hash when the user is not found to equalise timing; consider `RateLimiter::for('login')` keyed by identifier+IP.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Every factual claim checks out. All ten password validation sites use bare `min:6` (grep: AuthController.php:25 login, :170 OTP reset; TeacherController.php:64/:170; ManagerManagementController.php:67/:127; CenterManagerController.php:122/:423; TeacherProfileController.php:78; StudentController.php:214) — the auditor actually under-counted (missed the three update paths). There is no `Password::defaults()`, `uncompromised()`, `RateLimiter::for`, or any failed-attempt/lockout column anywhere in app/, bootstrap/, config/, routes/, migrations/ or tests/. Login identifiers are enumerable: DisplayCode.php:23 assigns parents `P{n}` (contrary to CLAUDE.md, parents DO get codes), LoginEmail.php builds `{latin}_{code}@mutqin.ly`, and AuthController.php:36 accepts the bare code case-insensitively. Throttle is the stock `throttle:10,1` (routes/api.php:17), which for unauthenticated requests keys on IP only; AuthLoginTest.php:34 confirms only the per-IP behavior. Timing oracle is real: `Hash::check` (bcrypt) runs only when `$user` is found (AuthController.php:39), so unknown identifiers return measurably faster. Mitigations found: uniform Arabic error message (no explicit user-exists leak), IP throttle, deactivated-account check placed after password check, demo-accounts endpoint gated to local/debug (DashboardController.php:105), tokens expire after 7 days. These reduce but do not remove the issue. Given the small, single-country deployment and existing IP throttle, medium remains the right rating — it is a hardening gap, not an exploitable-now vulnerability, but the combination of sequential P/T/CA identifiers + 6-char staff-chosen parent passwords + no per-account lockout is a genuine credential-guessing surface.

```text
Additional `min:6` sites not cited by the auditor: backend/app/Http/Controllers/Api/TeacherController.php:170, ManagerManagementController.php:127, CenterManagerController.php:423 (`$request->validate(['password' => 'min:6|confirmed'])` on update), and AuthController.php:25 (login). Parent display codes confirmed at backend/app/Support/DisplayCode.php:23 (`'parent' => 'P'` — "كود دخول بديل عن البريد"). Frontend login label explicitly advertises code login: frontend-html/login.html:43 «البريد الإلكتروني أو كود الدخول». Only throttle coverage in tests: backend/tests/Feature/AuthLoginTest.php:34 (per-IP 429 after 10). Zero hits for RateLimiter|Password::|uncompromised|lockout|failed_login across app/ bootstrap/ config/ routes/ database/migrations/ tests/.
```

</details>

### No security headers anywhere (CSP, HSTS, X-Frame-Options, nosniff, Referrer-Policy) and bearer token lives in localStorage

<a id="no-security-headers"></a>

`no-security-headers` · 🟡 medium · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/bootstrap/app.php:14-21`, `backend/public/.htaccess:1-37`, `backend/.htaccess:1-19`, `frontend-html/js/api.js:22-24`

**Evidence**

```text
`grep -rn -iE 'X-Frame-Options|Strict-Transport|Content-Security-Policy|X-Content-Type|Referrer-Policy|Permissions-Policy|nosniff' backend/app backend/config backend/bootstrap backend/public backend/.htaccess frontend-html` returns nothing. bootstrap/app.php:14-21 registers only the four role aliases — no header middleware. No .htaccess exists for frontend-html/ (git ls-files shows only backend/.htaccess and backend/public/.htaccess), so nothing forces HTTPS or sets headers on the static site that holds the token: api.js:22-24 `return localStorage.getItem(window.MutqinConfig.STORAGE_TOKEN);`.
```

**Why it matters**

The web client (used by admins and managers with '*' / 'manager' tokens) has no defence-in-depth against XSS token theft, clickjacking, MIME sniffing or protocol downgrade; a single missed escapeHtml call anywhere in ~140 innerHTML sites becomes full session theft. Native Flutter is unaffected by CSP/XFO, but HSTS and TLS enforcement protect the API it talks to.

**Recommendation**

Add a SecurityHeaders middleware appended globally (`$middleware->append(...)`) setting `Strict-Transport-Security: max-age=31536000; includeSubDomains`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `Permissions-Policy`. Ship a frontend-html/.htaccess (deployed to public_html root by .cpanel.yml) with HTTPS redirect and a CSP (`default-src 'self'; script-src 'self' cdn…; connect-src 'self'`), tightening inline scripts over time. Consider moving the SPA token to a short-lived in-memory token + refresh later.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Verified all evidence. The same grep across backend/app, config, bootstrap, public, .htaccess and frontend-html (incl. <meta http-equiv>) returns zero matches for any security header. backend/bootstrap/app.php:15-21 registers only the four role aliases — no global append, no header middleware. `git ls-files | grep htaccess` shows only backend/.htaccess and backend/public/.htaccess; frontend-html/ has no .htaccess on disk either, and .cpanel.yml rsyncs frontend-html/ straight to public_html root, so the static client ships with no headers or HTTPS redirect. No `URL::forceScheme`/HSTS anywhere in backend/app; HTTPS is only a manual checklist row in DEPLOYMENT.md:16. api.js:22-24 does read the bearer token from localStorage. There are ~154 innerHTML sites in the frontend, so the XSS-defence-in-depth argument is not exaggerated. Mitigations found: CORS is restricted to an env-configured origin list (config/cors.php), and Sanctum tokens expire after 7 days / are revoked on deactivation — these limit blast radius but do not substitute for CSP/HSTS/XFO/nosniff. The finding is a hardening gap (defence-in-depth, no direct exploit by itself), so medium is the correct rating; the token-in-localStorage sub-point is a standard SPA tradeoff and should not raise it. Not refuted.

```text
backend/bootstrap/app.php:15-21 — withMiddleware only calls $middleware->alias([...]) with admin/teacher/parent/manager; no append/prepend of any header middleware. `grep -rn -iE 'X-Frame-Options|Strict-Transport|Content-Security-Policy|X-Content-Type|Referrer-Policy|Permissions-Policy|nosniff|http-equiv'` over backend/app, backend/config, backend/bootstrap, backend/public, backend/.htaccess, frontend-html → 0 matches. `git ls-files | grep -i htaccess` → only backend/.htaccess, backend/public/.htaccess; `ls -a frontend-html` has no .htaccess. .cpanel.yml:5 rsyncs frontend-html/ to /home/[redacted-cpanel-user]/public_html (site root) with no header/redirect file. No `forceScheme` in backend/app; HTTPS only appears as a manual step in DEPLOYMENT.md:16. frontend-html/js/api.js:22-24 `return localStorage.getItem(window.MutqinConfig.STORAGE_TOKEN)`. Partial mitigation: backend/config/cors.php restricts allowed_origins to CORS_ALLOWED_ORIGINS (default http://localhost:8080), supports_credentials=false.
```

</details>

### No general audit log: who created/edited students, teachers, centers, memorizations, or who logged in from where, is not recorded

<a id="no-audit-trail-or-login-audit"></a>

`no-audit-trail-or-login-audit` · 🟡 medium · ✅ confirmed · **NEXT** · effort L (1–2 weeks)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:19-85`, `backend/app/Http/Controllers/Api/MemorizationController.php:208-227`, `backend/app/Http/Controllers/Api/StudentController.php:489-560`, `backend/app/Models/User.php:70-82`, `backend/database/migrations`

**Evidence**

```text
Audit fields exist only for three actions: `status_changed_by/at` (students/users), `corrected_by/at` (attendances) and `password_change_logs` (User.php:70-82). Login (AuthController.php:19-85) records nothing — no last_login_at, IP, user agent, or failed attempt. StudentController::update (:489-560) overwrites name/national_id/center/teacher with no history. MemorizationController::destroy (:208-227) hard-deletes a record with `$memorization->delete()` and no trace, contradicting the project-wide 'deactivate instead of delete' rule. `ls database/migrations` shows no audit/activity table.
```

**Why it matters**

For a system holding minors' national IDs across dozens of centers, an enterprise buyer will require: who changed a child's guardian, who moved a student between centers, who deleted a memorization entry, and when/where a manager last logged in. Today an incident investigation cannot answer any of these, and there is no signal to detect credential abuse (e.g. a manager account logging in from abroad at 3am).

**Recommendation**

Add an `audit_logs` table (actor_id, actor_role, action, subject_type, subject_id, before/after JSON, ip, user_agent, created_at) written via a small `Audit::record()` helper from the write paths (or a model observer on Student/User/Center/Memorization/WeeklyTest/Attendance). Record login success/failure with ip+UA and expose `last_login_at` in /admin/users. Convert memorization destroy to a soft delete or an audited delete. Surface an admin-only `GET /admin/audit` with filters.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Traced every cited path and the finding is factually accurate. AuthController::login (backend/app/Http/Controllers/Api/AuthController.php:19-85) validates, looks up the user, checks Hash, is_active and center active, then calls createToken and returns — there is no last_login_at write, no $request->ip()/userAgent() capture, no Log:: call for success or failure (the only Log::info in the controller is the OTP relay at :218). MemorizationController::destroy (:208-227) does an unaudited hard `$memorization->delete()` (WeeklyTestController:152 also hard-deletes questions/tests). StudentController::update (:489+) does a plain overwrite with no before/after history. The only audit-ish structures in the codebase are status_changed_by/at (students/users), corrected_by/at (attendances) and password_change_logs (User.php:70-82); `ls database/migrations` confirms no audit_logs/activity table, and no activitylog/Auditable/SoftDeletes package or trait is used anywhere in app/ or composer.json. Partial mitigation the auditor did not mention: Sanctum's personal_access_tokens table (migration 2026_06_19_072223, `last_used_at` + created_at) gives an implicit successful-login timeline per user (one row per login, until revoked), but it carries no IP/UA, nothing on failures, and rows are deleted on logout/revocation, so it does not satisfy the stated needs. The "contradicts deactivate-instead-of-delete rule" framing is slightly overstated (that rule was applied to teachers/centers/students/managers, not to per-session memorization rows), but the substantive gap — no traceable actor for edits/deletes and no login audit — stands. Severity: medium is appropriate; this is an accountability/forensics gap rather than an exploitable vulnerability, and for a small single-country deployment it is not high, but the data (minors' national IDs, guardian links, cross-center transfers) justifies keeping it above low.

```text
backend/app/Http/Controllers/Api/AuthController.php:19-85 — login path ends at `$user->createToken('auth_token', $abilities)` with no last_login/ip/user-agent/failure logging; only Log call in file is OTP relay at :218. backend/app/Http/Controllers/Api/MemorizationController.php:221 — `$memorization->delete();` hard delete, no audit. backend/app/Http/Controllers/Api/WeeklyTestController.php:152 — `$test->questions()->delete()` hard delete as well. backend/app/Http/Controllers/Api/StudentController.php:489-500 — update with ownership check only, no change history. backend/app/Models/User.php:70-82 — password_change_logs is the sole event log. database/migrations: only 2026_07_18_120000_add_correction_audit_to_attendances.php and 2026_08_10_100000_add_status_audit_to_students.php match audit; no audit_logs table. Partial mitigation: database/migrations/2026_06_19_072223_create_personal_access_tokens_table.php:20 `last_used_at` + created_at per token = implicit successful-login timestamps (no IP/UA, no failures, rows deleted on logout/revoke).
```

</details>

### Any center manager can enumerate every parent's national ID number system-wide via a 3-digit prefix search

<a id="manager-cross-center-parent-national-id-enumeration"></a>

`manager-cross-center-parent-national-id-enumeration` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/StudentController.php:284-309`, `backend/routes/api.php:61`

**Evidence**

```text
routes/api.php:61 `Route::get('/manager/parents/search', [StudentController::class, 'managerSearchParents'])`; StudentController.php:295-297 accepts any query with ≥3 digits (`if (strlen($digits) < 3) return []`) and :300-309 runs `User::where('role','parent')->where('id_number','like', $digits.'%')->limit(10)->get()` returning `name`, full `id_number` and `children_count` with no center restriction and no per-route throttle (only the framework default 60/min).
```

**Why it matters**

Libyan national IDs are 12 digits starting with 1 or 2, so the prefix space is small; a manager of one center (or anyone holding a stolen manager token) can walk prefixes and harvest names + full national IDs of every guardian in every center — sensitive PII whose exposure is regulated and reputationally severe for a multi-center rollout. The legitimate use (link a sibling already registered elsewhere) does not require partial matching.

**Recommendation**

Require the full 12-digit id_number (exact match) and return at most one result with the name partially masked until linked; alternatively restrict prefix search to parents who already have a child in the manager's own center. Add `throttle:20,1` to the route and log each lookup to the audit trail.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Evidence verified exactly as quoted. backend/routes/api.php:61 registers GET /manager/parents/search under the `manager` middleware group (line 53), and StudentController::managerSearchParents (lines 284-309) strips non-digits (accepting Arabic digits), returns [] below 3 digits, then runs `User::where('role','parent')->where('id_number','like',$digits.'%')->withCount('children')->orderBy('id_number')->limit(10)` with no center_id filter and returns name + full id_number + children_count. No per-route throttle exists (only Laravel 11's default `throttle:api` 60/min; no RateLimiter::for override in app/ or bootstrap/). The only test (tests/Feature/ManagerAddStudentGuardianTest.php:199-218) asserts minimal fields, manager-only access, and that a 2-digit query returns nothing — it does not prevent prefix walking; the 3-digit minimum plus limit 10 plus deterministic ordering actually makes systematic enumeration straightforward (walk 100..299, deepen prefixes that return 10 rows). So the behavior claimed is real and not mitigated elsewhere. Mitigating context the auditor under-weighted: (1) cross-center matching is intentional product design — guardians are de-duplicated by phone/id_number system-wide and siblings may attend different centers, so the manager must be able to find a parent registered at another center; (2) the caller is a trusted staff role (one manager per center), the endpoint already strips phone/email/children list, and the manager already sees id_number of own-center parents via /manager/parents (CenterManagerController::parents searches id_number with LIKE %..%); (3) small single-country deployment with a handful of managers. The residual issue is that the legitimate use case (parent hands over their national ID) needs only exact full-ID match, so prefix matching is an unnecessary PII enumeration surface for a rogue manager or stolen manager token. That is a real but modest insider-exposure hardening item, not a medium-severity vulnerability: downgrade to low. Recommendation (exact 12-digit match, optional throttle) stands and is cheap.

```text
backend/routes/api.php:53 (`Route::middleware('manager')->group`) and :61 (`/manager/parents/search`); backend/app/Http/Controllers/Api/StudentController.php:284-309 — `if (strlen($digits) < 3) return []` at :295-297, `where('id_number','like',$digits.'%')->orderBy('id_number')->limit(10)` at :300-304, returns name/id_number/children_count at :306-310; no RateLimiter::for('api') override anywhere in backend/app or backend/bootstrap (framework default 60/min applies); tests/Feature/ManagerAddStudentGuardianTest.php:199-218 covers only field-minimality, role gate and <3-digit empty result — not prefix enumeration; frontend-html/manager/students.html:335-342 issues the query after 3 digits (client-side only). Cross-center scope is by design (shared guardian accounts across centers, CLAUDE.md 'Guardians are de-duplicated by phone (siblings share one parent account)').
```

</details>

### Parent↔teacher message threads are keyed by student only, so a newly assigned teacher inherits the full private history with the previous teacher

<a id="message-history-visible-to-new-teacher"></a>

`message-history-visible-to-new-teacher` · 🟡 medium · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/MessageController.php:107-142`, `backend/app/Http/Controllers/Api/StudentController.php:611-690`, `backend/app/Models/Message.php:13-19`

**Evidence**

```text
MessageController.php:120-121 `Message::where('student_id', $student->id)->latest('id')->limit(100)->get()` — no teacher_id filter; resolveStudent (:42) authorises whoever is the *current* `students.teacher_id`. Messages carry only `student_id, sender_id, sender_role, body` (Message.php:13-19). StudentController::changeTeacher (:611-690) and transfer approval reassign `teacher_id` without touching messages. There is also no rate limit on `send` (:145-196) beyond the default api limiter.
```

**Why it matters**

Conversations between a parent and teacher A (which may contain complaints about teacher A, family circumstances, health notes) become readable by teacher B — possibly at another center after a transfer — without the parent's knowledge. For a parent-facing mobile app this is a trust/privacy defect that will surface quickly at scale.

**Recommendation**

Add `teacher_id` to messages (backfill from the sender/recipient at write time) and filter the thread by `(student_id, current teacher_id)`; or archive the thread (`archived_at`) on reassignment/transfer and show the parent a fresh thread with the new teacher. Add `throttle:30,1` on send.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Traced the execution path; the finding is factually correct. `MessageController::resolveStudent` (backend/app/Http/Controllers/Api/MessageController.php:42) authorises the teacher party solely by `(int) $student->teacher_id === (int) $user->id`, i.e. whoever is the CURRENT teacher. `thread()` (:120-121) loads `Message::where('student_id', $student->id)->latest('id')->limit(100)` with no teacher/sender filter, and `threads()` (:72-88) lists every student the teacher currently owns with last message + unread counts. The `messages` table (migration 2026_08_22_100000_create_messages_table.php) has only `student_id, sender_id, sender_role, body, read_at` — no teacher_id/thread/archived_at column — so there is nothing to filter on. Reassignment paths do not touch messages: `StudentController::changeTeacher` (:611-690) only updates `teacher_id`/`former_teacher_name`; `StudentRequestController` transfer approval (:285, :325-329) sets a new `teacher_id` (possibly at another center) with no message handling. Nothing in bootstrap/app.php middleware, model casts, or DB constraints mitigates this, and `tests/Feature/MessagingTest.php` (5 tests) covers only current-ownership 403s and the no-teacher 422 — no test covers reassignment history. The frontend cannot mitigate since the API returns the full history. An aggravating detail the auditor did not mention: `thread()` (:136-138) labels the "other" party with the CURRENT teacher's name, so the parent's old messages to teacher A are displayed under teacher B's name, and `thread()` also marks the old parent messages as read by teacher B (:115-118). Whether this is a bug or a documented design choice ("thread keyed by student_id" is stated in the class docblock and migration comment) is debatable, but the docblock also claims "one conversation per pair", which the implementation does not deliver once the pair changes — so it is a genuine privacy defect, not a misreading. Medium severity is appropriate: exposure is limited to authenticated teachers who are legitimately assigned the child, content is bounded to that child's thread, and the product is a small single-country deployment; but cross-center transfer leaking a parent's complaints about the former teacher is a real trust issue. The secondary rate-limit remark is minor (Laravel's default `api` limiter of 60/min per user does apply) and does not change severity.

```text
backend/app/Http/Controllers/Api/MessageController.php:42 (auth by current students.teacher_id only); :72-88 (threads lists all currently-owned students with unread/last message); :115-118 (new teacher's first open marks old parent messages read_at); :120-121 (thread query has no teacher filter); :136-138 ('other' name = current teacher, so old messages are shown under the new teacher's name). backend/database/migrations/2026_08_22_100000_create_messages_table.php (columns: student_id, sender_id, sender_role, body, read_at — no teacher_id/archived_at). backend/app/Http/Controllers/Api/StudentController.php:665-670 (changeTeacher updates teacher_id/former_teacher_name only). backend/app/Http/Controllers/Api/StudentRequestController.php:285, :325-329 (transfer approval reassigns teacher_id, possibly cross-center, no message handling). backend/tests/Feature/MessagingTest.php:29-123 (no reassignment-history test).
```

</details>

### No CI pipeline, no composer audit, no static analysis — dependency and regression safety rely on a developer remembering to run tests locally

<a id="no-ci-dependency-audit"></a>

`no-ci-dependency-audit` · 🟡 medium · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/composer.json:1-40`, `backend/composer.lock:1133-1134`, `DEPLOYMENT.md:18`, `backend/DEPLOY_LOG.md:6-9`

**Evidence**

```text
`git ls-files | grep -iE '\.github|gitlab|ci\.|workflow|Makefile|dependabot|renovate'` returns nothing (exit 1). composer.json has no `audit`/`phpstan`/`pint` scripts wired to a gate. DEPLOYMENT.md:18 lists «الاختبارات خضراء — php artisan test قبل كل نشر» as a manual step. DEPLOY_LOG.md:6-9 documents a fully manual cPanel File-Manager deploy with no CLI on the server. Current versions are healthy (laravel/framework v11.51.0, laravel/sanctum v4.3.2, phpspreadsheet 5.8.0, mpdf 8.3.1) but nothing will notice when they are not.
```

**Why it matters**

The 173 tests are 'the only guard on the dual role+ability security model' (CLAUDE.md), yet nothing enforces that they pass before code reaches production; a future CVE in phpspreadsheet/mpdf/laravel will go unnoticed; and manual paste-deploys make it easy to ship a file that never ran through the suite. Enterprise buyers will ask for evidence of automated security gates.

**Recommendation**

Add a GitHub Actions (or equivalent) workflow: MySQL service, `composer install`, `php artisan test`, `composer audit --locked`, `vendor/bin/pint --test`, and `phpstan` (larastan level 5+). Enable Dependabot/Renovate for composer. Produce the deploy zip as a CI artifact so what is uploaded is exactly what was tested.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Evidence verified: `git ls-files | grep -iE '\.github|gitlab|ci\.|workflow|Makefile|dependabot|renovate|phpstan|pint\.json'` returns nothing (exit 1); backend/composer.json `scripts` contains only Laravel skeleton hooks (post-autoload-dump, post-update-cmd, etc.) — no test/audit/pint/phpstan gate; laravel/pint is in require-dev but never wired; composer.lock versions match the auditor's quote (framework v11.51.0 @1133-1134, sanctum v4.3.2 @1407-1408, mpdf v8.3.1 @2444-2445, phpspreadsheet 5.8.0 @3136-3137). DEPLOYMENT.md:18 does list `php artisan test` as a manual pre-deploy step, and backend/DEPLOY_LOG.md:6-9 documents a manual cPanel File Manager deploy with SSH disabled by the host. No mitigation exists: no git hooks (core.hooksPath unset, no committed hooks), no remote CI (origin is github.com/muad03/MUTQIN but no .github/ directory is tracked). The auditor also missed a root `.cpanel.yml` which defines a cPanel git-deploy that rsyncs frontend-html/ and backend/ straight into public_html with no test/audit step — an automated deploy path with zero gate, which if anything strengthens the finding. Severity: real process gap, but it is a process/hygiene issue with no exploitable code path, the current dependency versions are healthy, and the target is a small single-country team without CI infrastructure or server CLI access (composer cannot even run on the host). 'medium' is reasonable for an enterprise-audit lens; I would rate it low-to-medium and keep medium as stated since the tests are the only guard on the auth model. Not refuted.

```text
backend/composer.json:36-52 — `scripts` block contains only skeleton hooks (post-autoload-dump, post-update-cmd, post-root-package-install, post-create-project-cmd); no test/audit/pint/phpstan entry. backend/composer.json:17 — laravel/pint present in require-dev but never invoked anywhere. composer.lock: laravel/framework v11.51.0 (1133-1134), laravel/sanctum v4.3.2 (1407-1408), mpdf/mpdf v8.3.1 (2444-2445), phpoffice/phpspreadsheet 5.8.0 (3136-3137). DEPLOYMENT.md:18 — manual `php artisan test` step. backend/DEPLOY_LOG.md:6-9 — SSH disabled by host, manual cPanel File Manager deploy. Additional (missed by auditor): .cpanel.yml:1-7 — cPanel git-deploy rsyncs frontend-html/ and backend/ into /home/[redacted-cpanel-user]/public_html with no test or audit step; `git ls-files` shows no .github/, hooks, or dependabot config; `git config core.hooksPath` empty. tests/Feature contains 38 files (CLAUDE.md says 20; the '173 tests' count was not verified but is immaterial).
```

</details>

### Unhandled exceptions bypass the {success,message,data,errors} contract and leak internal model class names

<a id="error-envelope-and-model-name-leak"></a>

`error-envelope-and-model-name-leak` · ⚪ low · ℹ️ informational · **NOW** · effort S (<1 day)

**Files:** `backend/bootstrap/app.php:22-24`, `backend/app/Http/Controllers/Api/ReportPdfController.php:84`, `backend/app/Http/Controllers/Api/StudentController.php:342`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:120`

**Evidence**

```text
bootstrap/app.php:22-24 `->withExceptions(function (Exceptions $exceptions): void { // })` — no renderable/JSON normalisation. Controllers rely on `findOrFail` (e.g. StudentController.php:342 `Student::with([...])->findOrFail($id)`, WeeklyTestController.php:120), which Laravel renders for JSON clients as `{"message":"No query results for model [App\\Models\\Student] 123"}` — no `success` key and the FQCN exposed. ReportPdfController.php:84 `abort(403, 'غير مصرح لك بتقرير هذا الطالب');` likewise returns Laravel's bare `{"message":...}`. Default 422s from `$request->validate` also lack `success:false` (the frontend compensates via `!res.ok` in api.js:65).
```

**Why it matters**

Low security impact (class names only), but a real contract hazard for a typed Flutter client: three different error shapes (envelope with success:false, bare {message}, bare {message,errors}) must be handled, and a strongly-typed DTO parser will crash on the bare forms. Normalising this before the mobile client is written is far cheaper than after.

**Recommendation**

In withExceptions add `$exceptions->render()` for ModelNotFoundException/NotFoundHttpException → `{success:false,message:'العنصر غير موجود',data:null,errors:{}}` (404), for AuthorizationException/HttpException(403) → envelope 403, for ValidationException → envelope 422 with `errors`, and for Throwable in production → generic envelope 500 (never `getMessage()`). Replace `abort(403,...)` in ReportPdfController with the envelope response. Add one test per shape.

### Several endpoints pass unvalidated fields straight to the database or to Carbon, turning bad input into 500s instead of 422s

<a id="unvalidated-inputs-cause-500s"></a>

`unvalidated-inputs-cause-500s` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/MemorizationController.php:128-187`, `backend/database/migrations (memorizations: hizb integer, eighth string)`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:37-43`, `backend/app/Http/Controllers/Api/AttendanceController.php:124-134`, `backend/app/Http/Controllers/Api/AdminUserController.php:55-65`

**Evidence**

```text
MemorizationController.php:175-187 writes `'hizb' => $request->hizb, 'eighth' => $request->eighth, 'notes' => $request->notes` although the validate() block (:128-138) covers none of them; the migration declares `$table->integer('hizb')->nullable()` and `$table->string('eighth')->nullable()`, so `hizb=abc` or a 300-char eighth raises a QueryException. WeeklyTestController.php:41 `'questions.*.eighth_start' => 'required|string'` and :43 `mistake => nullable|string` have no max. AttendanceController.php:127-128 `$month = $request->get('month', now()->month); $year = $request->get('year', ...)` are unvalidated before use. LIKE wildcards in `q` are not escaped (AdminUserController.php:45,55 `'%' . $norm . '%'`), so `%` matches everything (not injection, but unbounded scans).
```

**Why it matters**

Not exploitable for data access, but each is an availability/quality gap: a malformed mobile request yields an opaque 500 (and, with APP_DEBUG on a staging box, a stack trace with paths and SQL), and the Flutter team cannot rely on 422+errors for these fields.

**Recommendation**

Add `'hizb' => 'nullable|integer|between:1,60'`, `'eighth' => 'nullable|string|max:50'`, `'notes' => 'nullable|string|max:2000'`, `max:255` on eighth_start/mistake, `'month' => 'nullable|integer|between:1,12'`, `'year' => 'nullable|integer|between:2000,2100'`; escape `%`/`_` in search terms (`addcslashes($q, '%_')`) and cap `q` length.

### n8n attendance digest logs in daily as the human admin with a plaintext password and never revokes the '*' token it mints

<a id="automation-runs-as-human-admin"></a>

`automation-runs-as-human-admin` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `n8n/mutqin-daily-attendance-digest.json:31-65`, `n8n/mutqin-daily-attendance-digest.json:70-123`, `n8n/README.md:19-22`

**Evidence**

```text
Workflow settings node: `"name": "email", "value": "admin@mutqin.ly"` (:38) and `"name": "password", "value": "PUT_PASSWORD_HERE"` (:43-44) with note «بدّل password إلى بيانات اعتماد n8n قبل الإنتاج» (:65); it POSTs `/api/auth/login` (:70) and uses `=Bearer {{ $json.data.token }}` (:112) to call `/api/attendance`; there is no logout node (grep for `logout` in the JSON returns nothing). README.md:21-22: «The password sits in plain text for demo convenience».
```

**Why it matters**

A scheduled integration holding the super-admin password and generating a fresh full-privilege token every day (each valid 7 days) is a classic lateral-movement target; compromise of the n8n host equals compromise of the whole system. There is no scoped 'reports:read' ability or service account concept in the auth model to give it instead.

**Recommendation**

Introduce a service-account pattern: a dedicated user role or a narrowly-scoped Sanctum ability (e.g. `['reports:read']`) checked by a small middleware, issued once by the admin and stored as an n8n credential; or generate the digest server-side (scheduled job + mail) and drop the login step entirely. At minimum add a logout node and store the password as an n8n credential.

### Rate limiting keys on raw IP with no TrustProxies, and is_active is only enforced via token deletion rather than per request

<a id="throttle-proxy-and-active-check-gaps"></a>

`throttle-proxy-and-active-check-gaps` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `backend/bootstrap/app.php:14-21`, `backend/routes/api.php:17-21`, `backend/app/Http/Middleware/TeacherMiddleware.php:17`, `backend/app/Http/Middleware/CenterManagerMiddleware.php:20`, `backend/DEPLOY_LOG.md:6-9`

**Evidence**

```text
bootstrap/app.php:14-21 configures only the role aliases — no `$middleware->trustProxies(...)`; `throttle:10,1` / `5,1` / `10,1` (routes/api.php:17-21) therefore key on `$request->ip()`. None of the four middleware check `is_active` (TeacherMiddleware.php:17, CenterManagerMiddleware.php:20, etc.); deactivation relies on `tokens()->delete()` in controllers. Production is administered through phpMyAdmin (DEPLOY_LOG.md:5-9), where flipping `is_active` in SQL would leave live tokens valid for up to 7 days.
```

**Why it matters**

If the site is later fronted by Cloudflare or a load balancer (likely when scaling to dozens of centers), every visitor shares one IP bucket — 10 logins/min for the whole country (self-DoS), or if X-Forwarded-For is trusted blindly the limit is bypassed. The is_active gap means an out-of-band deactivation (SQL, restore from backup) does not immediately cut access.

**Recommendation**

Configure `trustProxies(at: [...])` for the actual edge IPs when a proxy is introduced and key the login limiter by identifier+IP. Add `if (!$user->is_active) return 403` (and centre-active for centre members) inside a shared `EnsureAccountActive` middleware applied to the auth:sanctum group; add a test.

## Measured facts

| Metric | Value |
|---|---|
| API endpoints (explicit Route:: lines + apiResource expansion) | 89 explicit + 18 via 5 apiResource ≈ 107 |
| Public (unauthenticated) endpoints | 5 (login, forgot-password request/verify, public/stats, public/demo-accounts) |
| Endpoints gated only by auth:sanctum (no role alias) | 9 (logout, user, dashboard, 3 notifications, 3 athman) — all self-scoped or read-only reference |
| Endpoints behind a role+ability middleware alias | ≈93 (parent 5, manager 37, admin 22 explicit + 8 resource, teacher 15 explicit + 10 resource) |
| Middleware aliases performing dual role+tokenCan check | 4 of 4 |
| In-controller role checks (isAdmin/isCenterManager/isTeacher/isParent) | 43 call sites |
| Models with explicit $fillable / with $guarded=[] / request->all() into create-update | 14 / 0 / 0 |
| Raw SQL call sites (whereRaw/selectRaw/orderByRaw/DB::select*) — all bound or constant | 47 in app/ (0 with interpolated user input) |
| Feature test files / test methods (CLAUDE.md says 20 files) | 38 files (+1 real unit test) / 173 methods; assertStatus(403) ×23, assertStatus(401) ×4 |
| Role-matrix coverage | 8 routes × 3 roles + 4 manager routes × 4 roles + 4 manager-on-others = 40 route/role cells (~13% of ~300 possible) |
| Password minimum length / complexity rules / breach check | 6 chars (6 sites) / none / none |
| Roles with self-service password change / OTP recovery | change: teacher+admin only (2 of 4); OTP: teacher+parent only (2 of 4); SMS delivery: none |
| Sanctum token TTL / refresh / per-device revoke / logout-all | 10080 min (7 d) / no / no / no |
| Throttles | login 10/min/IP; OTP request 5/min/IP; OTP verify 10/min/IP; OTP 6 digits, 10-min expiry, 5 attempts; api group default 60/min |
| Security headers set (CSP/HSTS/XFO/nosniff/Referrer-Policy) | 0 of 5 |
| Audit coverage | 3 audited actions (status toggles, attendance correction, password change); login audit: none; general activity log: none |
| Frontend innerHTML sites / escaping helper | 10 in shared js + ~140 in 30 pages; UI.escapeHtml applied to all API-sourced strings inspected |
| Secrets in git | .env never committed in 224 commits; 0 API keys; production host/cPanel user/DB name disclosed in backend/DEPLOY_LOG.md:3-5 |
| Seed data | synthetic phones/national IDs; shared demo password '[redacted-demo-password]' (seeder) vs '[redacted-demo-password]' (CLAUDE.md); production demo DB import marked done, clean DB marked pending |
| Dependency versions (composer.lock) | laravel/framework 11.51.0, laravel/sanctum 4.3.2, phpoffice/phpspreadsheet 5.8.0, mpdf/mpdf 8.3.1, symfony/http-foundation 7.4.8 |
| CI / composer audit / SAST | none / none / none |
| Upload limits (xlsx import) | mimes:xlsx, max 5120 KB, single sheet, transactional |

## Auditor notes

Additional material observations not promoted to findings (to avoid silent drops): (a) Shared-hosting layout puts the entire Laravel tree under the document root (`.cpanel.yml:6` → public_html/backend/), protected only by `backend/.htaccess` `Require all denied`; if the host disables AllowOverride or moves to a non-Apache stack, .env and storage/logs become web-readable — prefer a docroot outside the tree or at least a monitored external check that `/backend/.env` returns 403. (b) Manager `updateTeacher` (CenterManagerController.php:399) and admin `update` paths accept any `email` (`required|email|unique`) whereas creation enforces the `{latin}_{code}@mutqin.ly` scheme — inconsistent identifier control, and staff-initiated password resets (`recordPasswordChange('admin')`) do not notify the affected user. (c) OTP request has no per-phone cooldown: each request deletes the previous code (AuthController.php:143), so an attacker at 5/min/IP can perpetually invalidate a victim's code (DoS) and, once SMS exists, run an SMS-pump cost attack — add a per-phone limiter. (d) OTP lookup uses `->first()` on phone across parent+teacher roles (AuthController.php:132); a person holding both roles with one phone resets an arbitrary one of the two accounts. (e) PII (national_id, id_number, phones, guardian data of minors) is stored and returned in cleartext; column-level encryption breaks the search/unique features, so at minimum document data-classification, backup encryption (DEPLOYMENT.md #7 mysqldump is plaintext) and retention. (f) `/api/public/stats` exposes total `users` count. (g) `mutqin_user` role in localStorage drives UI only; server re-checks — fine. (h) Sanctum `'guard' => ['web']` with `stateful` localhost domains is harmless because no session login exists, but `statefulApi()` must never be enabled without CSRF review. (i) Unlimited tokens per user and no `sanctum:prune-expired` schedule. (j) `hashed` cast plus explicit `Hash::make` is redundant but not double-hashing (Laravel 11 `isHashed` guard). Doc drift relevant to security: CLAUDE.md says 20 test files (38), says parents have no display code (User.php:37 gives `P{n}` and login accepts it), says weekly-tests have destroy and no update (routes/api.php:164 is index/store/show/update — no destroy), lists demo password `[redacted-demo-password]` (seeder now `[redacted-demo-password]`), omits MessageController/messages routes, AdminUserController `/admin/users`, `/manager/parents(/search)`, `/manager/students/{id}/teacher`, `/manager/teachers/{id}/status|performance`, `/manager/reports/center|teacher|student`, `/centers/{id}/stats|teachers|students`, `/students/{id}/details|day`; DEPLOYMENT.md:11 still instructs replacing `allowed_origins => ['*']` although config/cors.php is already env-driven and closed by default. Test-coverage gaps worth closing: no test that every non-public route carries one of the four aliases (a route-introspection test would make the security model self-enforcing), no test that non-xlsx/oversized uploads are rejected, no test for the `/api/public/demo-accounts` APP_DEBUG gate, no test that a manager cannot enumerate parents outside a full-ID match, no test for message-thread visibility after teacher reassignment.
