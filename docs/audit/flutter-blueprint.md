# MUTQEN Flutter Blueprint

> A **proposal** for a single Flutter app serving all four roles (admin, center manager, teacher, parent) — nothing is built until an option is approved. Derived from the web client and the API at commit `37313bf` (2026-09-14). Interactive edition: https://claude.ai/code/artifact/b26659ee-9c41-4dba-a912-2a2a4ba30652 · companions: [Enterprise Audit](enterprise-audit.md) · [Architecture Atlas](architecture-atlas.md)

## Recommendation

Choose Option A (feature-first Riverpod 3 + go_router + dio + freezed), with four deliberate simplifications to protect the 2-week MVP: (1) no use-case classes and no separate domain-entity layer — freezed DTOs are the models; (2) Riverpod codegen only for providers that benefit (families, autoDispose); plain `NotifierProvider` is acceptable; (3) one `ApiClient.request<T>()` that parses the `{success,message,data,errors}` envelope and throws a sealed `Failure` — the only place status codes are interpreted (401 → clear session + router redirect, 403 → ForbiddenFailure with the server's Arabic message, 409 → ConflictFailure carrying `data.conflicts` for the attendance confirm dialog, 422 → ValidationFailure with `errors` keyed by field bound to inline TextFormField errors); (4) generated files are git-ignored and CI runs `build_runner` (adds ~1 min) so diffs stay readable.

Why not C: its 2-week edge is roughly one working day, but the cost shows immediately in this specific API: list-or-paginated responses (`?all=1` vs Laravel paginator), three independent paginators inside `/parent/students/{id}`, nullable nested `teacher`/`center`/`parent` objects, Arabic enum strings (`ناجح`/`راسب`), and a 409 handshake — all of which freezed unions and AsyncValue handle declaratively while ChangeNotifier code re-implements loading/error/race logic per screen (the web already needed `fetchSeq` guards). The 'years to enterprise' goal (offline attendance queue, push, multi-language, feature packages, a second team) makes C a guaranteed rewrite.

Why not B: Bloc's ceremony is real value for large teams but with 1–2 devs + Claude it consumes 2–3 sprint days for ~30 screens and adds a second DI codegen; Riverpod 3 gives equivalent testability (ProviderContainer overrides) and cross-feature invalidation (`ref.invalidate(dashboardProvider)` after saving attendance) with far less code. If the organisation later mandates Bloc, the feature-folder + repository layout of A migrates cleanly because notifiers are thin.

Single app with role-based routing, not four apps: one login endpoint, shared notifications/messaging, one store listing, one CI pipeline, and role shells that mirror `layout.js` NAV tables. Admin is a first-class role but mobile-lite in the MVP (dashboard, lists, status toggles, PDFs); heavy admin CRUD stays on the web, which is where admins already work.

Offline: MVP ships a read cache (last good JSON per request key, in-memory + disk, with an 'offline / stale data' banner via connectivity_plus) and no offline writes. The offline attendance queue is Phase 2 — it needs the 409/`confirm` semantics, idempotency and the fingerprint-import overlap designed with the backend; the MVP leaves a `SyncQueue` interface and a `sync/` folder so it slots in without refactoring. Push: wire firebase_messaging behind `PushService` with a no-op backend adapter until a `POST /devices` endpoint exists. Crash/analytics: Firebase Crashlytics + Analytics (one SDK you already need for FCM); Sentry is a fine swap if you want Dart-native breadcrumbs without Google services. Fonts: bundle Amiri (display) + Cairo (body) locally — both SIL OFL 1.1, so bundling is licensed; include OFL text via LicenseRegistry and never fetch from Google Fonts at runtime. Digits: render Western digits everywhere (match the PDF rule) by avoiding `ar` NumberFormat; wrap LTR islands (phones, national ids, display codes, emails) in `Directionality.ltr`.

## Scope rule for the 2-week MVP

Every role can finish its *daily* job on the phone: teachers mark attendance, record memorization and weekly tests; parents follow each child and message the teacher; managers approve requests and review attendance; admins monitor. Anything marked COULD / WON'T stays on the web for now.

ENDPOINT VERIFICATION (frontend calls vs backend/routes/api.php): every endpoint invoked by any page/shared JS resolves to a declared route — no missing endpoints found. Verified calls: auth (login/logout/forgot request+verify), public stats/demo-accounts, dashboard, notifications (index/read/read-all), athman/search, parent children/students/{id}/messages*, manager dashboard/center/students(+next-code,+/{id}/status,+/{id}/teacher)/parents(+/search)/teachers(+next-code,+/{id}/performance,+/{id},+/{id}/status)/attendance(import,index,/{id}/status)/reports(system,center,teacher/{id},student/{id},center|at-risk|teachers pdf)/centers/student-requests(index,store,approve,reject), admin teachers(index/show/store/update/status)/centers(index/store/update/status,/{id}/stats,/teachers,/students,/has-primary)/students(store,status,next-code)/parents/search/admin/users/admin/managers(index/store/update/status)/reports/admin/*/pdf, teacher profile(show/phone/password)/students(index/show/update route exists,/{id}/details,/{id}/day)/teacher/messages*/attendance(index/store/import)/memorizations(surahs,index,store,destroy)/weekly-tests(index,store,update)/reports/student/{id}/pdf,/reports/teacher/pdf. Note admin/profile.html calls /profile which sits under the teacher gate — valid because admin passes that gate. Routes that exist but NO web page uses (available for mobile): GET /auth/user (use for splash validation), GET /athman/hizb/{n}, GET /athman/{id}, GET /attendance/report (monthly, teacher-gated; used by n8n digest), GET /memorizations/students-progress and ?juz= filter (surah-completion progress board), GET /reports/weekly, GET /reports/student/{id} (JSON), GET /reports/admin/missing-national-id, GET /weekly-tests/{id}, GET /manager/reports/management, GET /centers/{id} (show), GET /manager/teachers/{id} (showTeacher), PUT /students/{id} for teachers (route exists, no teacher UI).

API GAPS THAT AFFECT MOBILE: (1) no /profile or change-password route for center_manager or parent — only OTP reset; hide Profile for them. (2) no push/FCM or websocket — notifications and messages are poll-only (web: 60s bell poll). (3) no token refresh — Sanctum tokens expire after 7 days (config/sanctum.php expiration=10080) and are revoked on password change/deactivation; handle 401 globally and re-login. (4) PDFs are Bearer-protected binary responses (no shareable URL). (5) /parent/students/{id} paginates three logs through one call (memo_page/att_page/tests_page) — three tabs, bump one param at a time. (6) teacher memorization and weekly-tests pages ignore pagination (server 15/page) — mobile must implement infinite scroll. (7) admin students wizard preloads GET /teachers?all=1 (all teachers system-wide) — drop it and use the center-scoped call. (8) manager transfer sheet loads /manager/students?status=active&all=1 — prefer searchable picker via ?q=. (9) /manager/student-requests and /admin/managers are unpaginated (acceptable sizes). (10) Login field accepts display code or email; strip all whitespace client-side (iOS keyboard adds spaces).

RECOMMEND DEFERRING / KEEPING WEB-ONLY FOR THE 2-WEEK MVP: index.html landing (store listing + About screen replace it); admin/users.html (read-only directory, low daily value); manager/parents.html (read-only, could be a tab later); manager/teacher.html performance page and manager Teacher Report (overlap — ship at most one, later); admin Teacher Detail password-change audit log; admin Add-Student wizard and Edit/Transfer (L, desktop-friendly with many fields — admins onboard in bulk at a desk; manager Add-Student is the same shape and can wait for week 3 unless managers demand it); teacher Reports PDF and admin Reports Hub (A4 print artifacts — keep the shared PDF viewer but treat as should/could; JSON report screens can replace them later); xlsx import on teacher (managers are the primary importers; fingerprint exports come from a PC — keep import on manager, expose teacher import only if file arrives on phone); Athman browse-by-hizb; students-progress board; center/teacher/manager CRUD forms are should — needed for 'management' but not daily. MUST for the 2-week MVP by role — teacher: Home, My Students, Student Detail (+day view), Take Attendance (with 409 flow), Memorization log+form, Weekly Tests list+form with Athman picker, Messages; parent: My Children, Child Detail, Messages; manager: Home, Attendance Review + correction, Requests (approve/reject; transfer create as should), My Students list; admin: Home, All Students list, plus shared Login/Splash/Shell/Notifications/Confirm.

MOBILE-SPECIFIC CONCERNS: RTL + Arabic fonts (Cairo UI, Amiri for Quran text/stat numerals; brand emerald #04532F, gold #D4AF37, ivory #FBF7DA, danger #B23A48, blue #2A6F8E — see _handoff2 design system and css/theme.css); Arabic-normalized client search (strip tashkeel, unify alef/ya/ta-marbuta/hamza, Arabic-Indic digits) must be replicated for the few client-filtered lists (centers, managers, teacher students/tests) — prefer server q where available; dates: compute 'today' in local Africa/Tripoli (never toIso UTC); week Saturday–Friday; Western digits in PDFs; file picking: file_picker restricted to xlsx, 5MB cap, multipart field name attendance_file, handle Android content URIs; PDF viewing: download with auth header to temp dir, render in-app, share via share_plus; offline: MVP online-only with cached last payload for Home/Children/Student lists (read-only), local draft for attendance selections, explicit 'no connection' state mirroring ApiError status 0 — do not queue writes (409 conflict handshake and code reservation need live server); large lists: use server pagination everywhere (page/last_page/total), infinite scroll with request-sequence guard, debounce 300ms; security: token in flutter_secure_storage, keep role+ability dual-check semantics (route guards by role only for UX; server is authoritative — 403s must be handled gracefully); deep links from notifications map web links to routes as listed; demo-accounts panel only in debug builds; PWA manifest already sets standalone/portrait — Flutter app should lock portrait for forms. n8n daily attendance digest (n8n/) is a server-side automation independent of the app.

## Auth flow on the current API

Recommended mobile sequence against the API as it exists today (evidence: backend/routes/api.php, app/Http/Controllers/Api/AuthController.php, app/Http/Middleware/*, config/sanctum.php, app/Models/User.php, frontend-html/js/api.js).

0. Transport rules for every call: base URL dev http://localhost:9090/api, prod https://mutqin.ly/backend/public/api (frontend-html/js/config.js uses the relative /backend/public/api on the same domain; shared cPanel host, HTTPS mandatory). Always send Accept: application/json — without it Laravel 11's default redirectGuestsTo(route('login')) turns an expired/invalid token into HTTP 500 'Route [login] not defined' instead of 401 (bootstrap/app.php has an empty withExceptions). Send Authorization: Bearer <token> when stored. Add X-Client: android|ios and X-App-Version for logs/gating (G-18).

1. Login: POST /auth/login {email, password}. The 'email' field accepts an email OR a display code (T3 / CA1 / P12, case-insensitive: UPPER(display_code) then exact email; phone is NOT accepted today — G-06). Success 200 {success:true,message,data:{token:'<id>|<plain>',user:{id,name,email,phone,role,center_id,center_name,type}}}. Failures: 422 {success:false,message:'بيانات الدخول غير صحيحة',errors:{email:[…]}} for wrong credentials (deliberately does not say which field); 422 {message,errors} WITHOUT success for missing/short fields (framework validation); 403 {success:false,message,errors:{email:[…]}} for is_active=false ('هذا الحساب غير نشط…') or the member's center inactive ('مركزك غير نشط…') — checked after the password so state is not leaked; 429 {message:'Too Many Attempts.'} + Retry-After after 10 attempts/min per IP. Persist token + user JSON in flutter_secure_storage (Keychain/Keystore), plus a client-side issued_at, because no expires_at is returned (G-02/G-07).

2. Abilities are decided server-side and are implicit in the response: parent → ['parent'], center_manager → ['manager'], admin and teacher → ['*']. Middleware double-checks role AND tokenCan: admin gate = isAdmin && '*'; teacher gate = role in [teacher,admin] && '*'; parent gate = isParent && 'parent'; manager gate = isCenterManager && 'manager' && center_id set. A wrong-role call returns 403 {success:false,message:'هذه الصفحة …'} — the app must gate navigation by role but still handle 403 gracefully.

3. Role → home routing (from user.role): admin → GET /dashboard (returns role:'admin', stats, recentStudents, teachers) then admin screens; teacher → GET /dashboard (role:'teacher', stats incl. this_week_memorizations, students, attendanceToday, recentMemorizations); center_manager → GET /manager/dashboard + GET /manager/center (do NOT call /dashboard: it falls into the teacher branch and returns role:'teacher' with zero stats — G-12); parent → GET /parent/children (and /parent/messages). Admin can also use the teacher-gated /profile* endpoints; parents and managers have no profile endpoints at all (G-04).

4. Cold start with a stored token: call GET /auth/user (returns the same user payload) to validate the token and refresh center_name/type; 401 → wipe storage → login screen; network error → show cached home in read-only mode with a retry banner. Then fetch GET /notifications (unread_count + last 30) and, if the role is parent/teacher, GET /{role}/messages for badges.

5. Expiry: tokens die exactly 7 days after creation (config/sanctum.php expiration=10080; Sanctum checks created_at, so activity does not extend it; last_used_at is only bookkeeping). There is no refresh endpoint. Treat ANY 401 as session end: clear secure storage, cancel in-flight requests, navigate to login with 'انتهت الجلسة'. Use the client-side issued_at to show a soft warning on day 6. Once G-02 lands: on app foreground, if expires_at - now < 48 h call POST /auth/refresh (rotates the token, deletes the old one) and overwrite storage; if refresh 401s, fall back to login.

6. Forced invalidation events: POST /profile/password (teacher/admin) and the OTP verify call User::recordPasswordChange → tokens()->delete() (all devices, including the caller); admin PUT /teachers/{id} or /admin/managers/{id} with a password, PUT …/status deactivation and center deactivation also delete tokens. So after a successful self password change the very next request is 401 — immediately re-login with the new password (once, in memory) or, after G-14, store the token returned by the change call. Login after deactivation returns 403 with the reason — show it.

7. Logout: POST /auth/logout deletes only the current token (other devices remain logged in). Always clear local storage even if the request fails (offline logout). When push exists (G-03) call DELETE /devices/{token} first.

8. Forgot password: POST /auth/forgot-password/request {phone} (5/min/IP, always a neutral 200; parents and teachers only, phone normalized via PhoneNumber::normalize) then POST /auth/forgot-password/verify {phone, otp(6 digits), password} (10/min/IP; 422 generic on wrong/expired/5 attempts). In production nothing is sent — sendOtp() only logs, dev_otp appears only when APP_ENV=local — so the mobile flow must be hidden behind the features.otp flag (G-08) and replaced by 'راجع مدير المركز' until an SMS gateway exists and a manager reset endpoint (G-05) is added.

Security posture to keep: never store the token in SharedPreferences; pin nothing (shared host cert rotates); do not cache PDF bytes with the token in the filename; on 403 do not retry.

## API contract the data layer must codify (as it behaves today)

What the API does TODAY (Laravel 11.51 + Sanctum 4.3 per composer.lock; vendor/ and .env are absent from this checkout, so framework-default behaviours are stated from Laravel 11 knowledge and corroborated by tests/Feature — 38 files):

ENVELOPE. Hand-written responses are {success:true|false, message?, data?, errors?} (response()->json in every controller; middleware 403s use the same shape). Creates return 201 (students, teachers, managers, memorizations, weekly-tests, messages, student-requests); everything else 200. Deviations: GET /dashboard adds a top-level role; POST /attendance/import and /manager/attendance/import return counters at top level with no data key (imported, imported_new, updated, present, late, absent, absent_computed, ignored_other_teachers, skipped, errors[{row,number,name,reason}], name_warnings[{row,number,file_name,system_name}]); PDFs return raw application/pdf bytes with Content-Disposition: inline. Framework-generated errors have NO success key and appear only when Accept: application/json is sent: 401 {"message":"Unauthenticated."}; 422 {"message":"<first message> (and N more errors)","errors":{field:[Arabic strings]}} from every $request->validate() (no FormRequests, no API Resources exist); 404 for unknown routes {"message":"The route api/x could not be found."} and for findOrFail {"message":"No query results for model [App\\Models\\Student] 5"}; 405 {"message":"The DELETE method is not supported for route api/weekly-tests/1…"}; 429 {"message":"Too Many Attempts."} with Retry-After and X-RateLimit-* headers; 500 {"message":"Server Error"} (APP_DEBUG=false in .env.production.example). Business errors written by hand: 403 ownership/scope (e.g. 'غير مصرح لك بالوصول لهذا الطالب', 'هذا الطالب ليس من طلاب مركزك'), 409 attendance conflict {success:false,message,data:{conflicts:[{student_id,name,current_status,new_status}]}} requiring confirm:true to overwrite, 422 rule violations sometimes with errors keyed by field (guardian_password, guardian_id_number, juz, phone, current_password), sometimes message-only (student-request flows, import file problems). Client rule: ok = 2xx && json['success'] != false; message = json['message']; fieldErrors = json['errors'] as Map<String,List<String>>; ignore the presence/absence of success.

AUTH. Bearer token '<id>|<plaintext>' from POST /auth/login; abilities parent ['parent'], center_manager ['manager'], admin/teacher ['*']; four gates (admin/teacher/parent/manager) verify role AND ability. Expiry 7 days from created_at (hard, no refresh). All tokens deleted on logout-current, password change (self/OTP/admin), user deactivation, center deactivation. Login accepts email or display code; identifier field is named 'email'.

PAGINATION. Raw Laravel LengthAwarePaginator inside data: {current_page, data:[…], first_page_url, from, last_page, last_page_url, links:[{url,label,active}] (labels contain HTML entities), next_page_url, path, per_page, prev_page_url, to, total}; query ?page=N; withQueryString() keeps filters in the absolute *_url fields (built from APP_URL — compute ?page= yourself). Fixed sizes, no per_page anywhere: 20 → /students, /manager/students, /teachers, /manager/teachers, /manager/parents, /manager/attendance, /admin/users; 15 → /memorizations, /weekly-tests; 10 → /centers; 5 → the three sub-lists of /parent/students/{id} with page names memo_page, att_page, tests_page; /centers/{id}/teachers|students 5. ?all=1 on /students, /teachers, /centers, /manager/teachers replaces the paginator with a plain array in data. Unpaginated arrays: /notifications (latest 30 + unread_count), /{role}/messages (threads), /{role}/messages/{student} (last 100, ascending), /manager/student-requests (with direction incoming|outgoing), /memorizations/students-progress, /athman/*, /parent/children, /attendance (students[] + attendances map).

FILTERS (StudentController::index): status=active (default)|inactive|all, q= normalized Arabic search (name, guardian, former teacher, nationality, center/teacher name, national_id, normalized phone), center_id, teacher_id, nationality=libyan|foreigner, missing_national_id=1; scoping is by role (manager → own center, teacher → own students, admin → all). Memorizations: juz=1..30 (derived from surah name via SurahReference, never the stored juz column), q= (juz number/name or student). Reports: month, year ints. Attendance: date=Y-m-d (default today in Africa/Tripoli), admin may pass center_id.

DATES/TIME. App timezone Africa/Tripoli (+02:00, config/app.php); 'today' and the Saturday→Friday week are computed server-side in that zone. Date-only columns (attendances.date, memorizations.date, weekly_tests.exam_date, students.enrollment_date/birth_date) are cast 'date' and serialize through Carbon::toJSON() as UTC ISO-8601 with microseconds — local midnight becomes the previous day 22:00Z, e.g. day 2026-09-14 → "2026-09-13T22:00:00.000000Z" (also in the hand-built arrays of /parent/students/{id}, /students/{id}/details, notifications created_at, messages created_at/read_at). Timestamps (created_at, updated_at, read_at, imported_at, corrected_at, status_changed_at, password_last_changed_at) use the same UTC 'Z' format. Inputs are plain strings interpreted in Tripoli time: date/exam_date/?date= as Y-m-d, time as HH:MM:SS, month/year ints. Client rule: parse ISO → convert to Africa/Tripoli (not device local) before taking the calendar date; send Y-m-d built in Tripoli time; display with Western digits (the PDFs already do).

SCALARS/ENUMS. ids and counts are JSON ints (withCount, count(), Percentage::of returns int, raw SUMs cast in code); booleans are real booleans (is_active, success, has_primary, is_read, mine, passed, attendanceToday); phones are strings normalized to 09xxxxxxxx; national_id/id_number strings (Libyan ^[12]\d{11}$ only when nationality_type=libyan; free text ≤32 for foreigner); age int. Vocabularies: role admin|center_manager|teacher|parent; attendance status present|absent|late; memorization quality excellent|good|average|weak; teacher type Arabic 'محفظ أساسي'|'محفظ معاون'; weekly-test result and questions.*.result Arabic 'ناجح'|'راسب'; nationality_type libyan|foreigner; request type add|transfer, status pending|approved|rejected, direction incoming|outgoing; notification type request_created|request_approved|request_rejected|manager_deactivated|memorization_added|test_added|message_received; sender_role parent|teacher. Notification ids are UUID strings; all other ids ints. Empty PHP arrays serialize as [] even where a map is expected (GET /attendance attendances is {"55":"present"} or []). Display codes S{n}/T{n}/CA{n}/P{n}/C{n} are server-assigned, never sent by the client; …/next-code endpoints are previews only. Generated login emails are {latin}_{code}@mutqin.ly (LoginEmail), e.g. ali_p12@mutqin.ly.

RATE LIMITS. /auth/login 10/min/IP (AuthLoginTest asserts 429 on the 11th); /auth/forgot-password/request 5/min/IP; /auth/forgot-password/verify 10/min/IP; all /api/* routes additionally run under Laravel 11's default throttle:api (60 req/min keyed by user id, else IP — confirm with a burst test since vendor is not checked out). Headers: X-RateLimit-Limit, X-RateLimit-Remaining, Retry-After on 429.

UPLOADS/DOWNLOADS. multipart field attendance_file (mimes:xlsx, max 5120 KB; header row must be exactly رقم الطالب|الاسم|التاريخ|الوقت[|الحالة]; student number = numeric part of S-code) on POST /attendance/import (teacher/admin) and POST /manager/attendance/import (manager, own center). PDFs: GET /reports/student/{id}/pdf, /reports/teacher/pdf, /reports/admin/{center/{id}|teachers|at-risk|overview}/pdf, /manager/reports/{center|at-risk|teachers}/pdf with ?month&year → 200 application/pdf bytes, Content-Disposition: inline; filename="…" (mPDF, synchronous, needs gd).

MISC. No API versioning (/api only), no ETag/Cache-Control/compression configured in the app (Apache defaults on the host unknown), CORS restricted to CORS_ALLOWED_ORIGINS (irrelevant to native clients — no Origin header). Health: Laravel default GET /up (HTML, outside /api). Public endpoints: /public/stats {centers,users,students}, /public/demo-accounts (empty in production unless APP_DEBUG). Notifications: GET /notifications → {unread_count, items[{id,type,title,body,link(web path),is_read,created_at,created_ago}]} (ref_id stored but not returned); POST /notifications/{id}/read → {unread_count}; POST /notifications/read-all. Messages: GET /{parent|teacher}/messages → [{student_id,student_name,other_name,unread,last_body,last_at}]; GET /{role}/messages/{student} marks the other side read and returns {student,other,messages[{id,sender_role,mine,body,read_at,created_at}]}; POST /{role}/messages/{student} {body ≤2000} → 201 raw Message model; 422 when the counterpart (teacher/parent) is missing; 403 when the student is not the caller's child/student (admin is refused).

<details><summary>Analyst notes</summary>

Scope and evidence: read-only review of backend/routes/api.php, bootstrap/app.php, config/sanctum.php, config/app.php, config/cors.php, .env.production.example, public/.htaccess, all four middleware, AuthController, DashboardController, NotificationController, MessageController, StudentController, TeacherProfileController, AdminUserController, CenterManagerController (dashboard/myCenter), AttendanceController, AttendanceImportController, MemorizationController, ReportPdfController::render, WeeklyTestController rules, StudentRequestController::present, Support/DisplayCode, LoginEmail, ParentResolver, Percentage, SurahReference, Notifications/InAppNotification, models' casts, frontend-html/js/api.js + config.js + ui.js (fmtDate/todayStr) + layout.js (60 s poll), n8n/README.md, DEPLOY_LOG.md, tests/Feature listing. vendor/ and .env are not in this checkout (fresh worktree), so Laravel-default error bodies, the redirectGuestsTo(route('login')) 500 trap, and the default throttle:api limiter are asserted from Laravel 11.51 behaviour — confirm with a quick curl against the running API (curl -i without Accept on an expired token; 61 rapid GETs) before relying on them.

CLAUDE.md drift confirmed while reading: parents DO have display codes (P{n}) and can log in with them; generated emails are {latin}_{code}@mutqin.ly (not {latin}.{id}@parent.mutqin.ly); random parent passwords no longer exist (guardian_password is required); MessageController/messages, AdminUserController (/admin/users), /manager/parents, /manager/students/{id}/teacher, /manager/teachers/{id}/performance, /manager/reports/{center,teacher/{id},student/{id}}, /students/{id}/details|day, /centers/{id}/stats|teachers|students, weekly-tests update (DELETE now 405) all exist and are undocumented there; tests are 38 Feature files, not 20.

Priority order for the 2-week window (all 'now' items are S except FCM which is M): (1) G-01 envelope + Accept handling and G-09 date format — they decide the Flutter data layer and are cheapest to fix before any client code is written; (2) G-02 expires_at/refresh, G-07 richer login payload, G-04 /me endpoints, G-05 parent password reset + must_change_password — without these two of four roles cannot operate the app for a full week; (3) G-08 /app/config with min_app_version and G-18 client headers — the only remote kill switch on a host with no CLI; (4) G-03 device registration + FCM (registration first so tokens accumulate even if sending slips to week 3); (5) G-13/G-15 notification/message cursors and ref_id; (6) G-11 login limiter key, G-16 import envelope, G-22 demo gate. Everything marked 'next'/'later' can ship after launch behind min_app_version.

Deployment constraint that shapes every proposal: production is cPanel shared hosting (Libyan Spider) with no SSH/artisan/composer/git — each backend change ships as a zip plus raw SQL for migrations (device_tokens, users.must_change_password), QUEUE_CONNECTION=sync (pushes send inline), CACHE_STORE=file (rate limiter uses the file cache — fine), and no scheduler unless a cPanel cron is added (so prune tokens at login instead). Keep all 38 feature tests green: every proposal above preserves existing status codes and keeps /profile* aliases; G-01 keeps assertJsonValidationErrors working because 'errors' stays keyed by field.

</details>

## API gaps for mobile — 25 (15 needed before ship)

| Gap | Severity | When | Effort |
|---|---|---|---|
| [Framework errors bypass the envelope; missing Accept header turns 401 into 500](#g-01) | 🟠 high | NOW | S |
| [Hard 7-day token expiry with no refresh/rotation and no expires_at in the login response](#g-02) | 🟠 high | NOW | S |
| [No device/push-token registration and no FCM delivery — notifications exist only as a 60-second web poll](#g-03) | 🟠 high | NOW | M |
| [Parents and center managers have no self-service profile/password/phone endpoints](#g-04) | 🟠 high | NOW | S |
| [A parent who forgets the password is permanently locked out — no reset path exists in production](#g-05) | 🟠 high | NOW | S |
| [No health/version/app-config endpoint — no force-update, no cached reference data](#g-08) | 🟠 high | NOW | S |
| [Date-only columns are serialized as UTC ISO timestamps shifted by -2 h](#g-09) | 🟠 high | NOW | S |
| [Login by phone is not supported; parents must type a code (P12) or a generated email](#g-06) | 🟡 medium | NOW | S |
| [Login payload lacks the fields a mobile client needs for routing and display](#g-07) | 🟡 medium | NOW | S |
| [Login throttle is per IP (10/min) — carrier NAT and school-wide onboarding will trip it](#g-11) | 🟡 medium | NOW | S |
| [Notifications: no pagination, ref_id not exposed, link is a web path — no way to deep-link on mobile](#g-13) | 🟡 medium | NOW | S |
| [Messaging thread has no cursor/delta fetch and no unread badge endpoint](#g-15) | 🟡 medium | NOW | S |
| [Attendance xlsx import returns a non-envelope body; multipart contract must be codified for the mobile file picker](#g-16) | 🟡 medium | NOW | S |
| [No API versioning or client identification headers](#g-18) | 🟡 medium | NOW | S |
| [Demo-accounts endpoint and dev_otp are environment-gated — verify the production .env, hide the panel in the app](#g-22) | ⚪ low | NOW | S |
| [Pagination is inconsistent: raw Laravel paginator, fixed page sizes, ?all=1 changes the shape, sub-paginators with custom page names](#g-10) | 🟡 medium | NEXT | S |
| [/dashboard is not role-aware for managers and parents](#g-12) | 🟡 medium | NEXT | S |
| [Password change revokes the caller's own token — app drops to login mid-flow](#g-14) | ⚪ low | NEXT | S |
| [Mixed enum vocabularies and empty-array-instead-of-object serialization](#g-20) | ⚪ low | NEXT | S |
| [No ETag/If-None-Match, no Cache-Control, compression not guaranteed](#g-21) | ⚪ low | NEXT | S |
| [Manager 'other centers', admin pick-lists and teacher pick-lists have no lightweight mobile variants](#g-25) | ⚪ low | NEXT | S |
| [PDF reports are inline bytes behind a bearer header — fine for dio, but slow and unshareable by URL](#g-17) | ⚪ low | LATER | S |
| [Validation and business errors are Arabic strings only, without stable codes](#g-19) | ⚪ low | LATER | S |
| [No image/avatar or attachment upload anywhere](#g-23) | ⚪ low | LATER | M |
| [No 'logout everywhere' / active sessions view; tokens accumulate per login](#g-24) | ⚪ low | LATER | S |

### Framework errors bypass the envelope; missing Accept header turns 401 into 500

<a id="g-01"></a>

`G-01` · 🟠 high · **NOW** · effort S (<1 day)

**Why:** bootstrap/app.php leaves withExceptions() empty, so every framework-raised error uses Laravel 11 defaults: 401 {"message":"Unauthenticated."}, 422 {"message":"<first error> (and N more errors)","errors":{...}} (every $request->validate() call — there are no FormRequests/Resources), 404 findOrFail {"message":"No query results for model [App\\Models\\Student] 5"}, 404 unknown route, 405 (DELETE /weekly-tests/{id}), 429 {"message":"Too Many Attempts."}, 500 {"message":"Server Error"} — none carry success:false. Worse: Laravel 11 registers redirectGuestsTo(route('login')) by default; if the client omits Accept: application/json, an expired/invalid token produces HTTP 500 'Route [login] not defined' instead of 401 (the web client survives only because api.js always sends Accept). Dio does not add Accept by default.

**Proposed change:** In bootstrap/app.php withExceptions(): render AuthenticationException, ValidationException, ModelNotFoundException/NotFoundHttpException, MethodNotAllowedHttpException, ThrottleRequestsException, AuthorizationException and the generic Throwable as the envelope {success:false, message, errors?, code?} for requests matching /api/* regardless of Accept (use $exceptions->shouldRenderJsonWhen(fn($req)=>$req->is('api/*'))). Keep HTTP statuses unchanged so the 38 feature tests stay green (assertStatus/assertJsonValidationErrors still pass). Hide model class names in 404 message ('السجل غير موجود'). Flutter side: always send Accept: application/json and treat ok = 2xx && json['success'] != false.

**Endpoint sketch**

```text
No new endpoint. Every /api/* error becomes:
HTTP 401 {"success":false,"message":"انتهت الجلسة، سجّل الدخول من جديد","code":"unauthenticated"}
HTTP 422 {"success":false,"message":"بيانات غير صالحة","errors":{"phone":["رقم الهاتف مطلوب"]},"code":"validation"}
HTTP 404 {"success":false,"message":"السجل غير موجود","code":"not_found"}
HTTP 429 {"success":false,"message":"محاولات كثيرة، أعد المحاولة بعد قليل","code":"throttled","retry_after":42}  (+ Retry-After header)
HTTP 500 {"success":false,"message":"خطأ في الخادم","code":"server_error"}
```

### Hard 7-day token expiry with no refresh/rotation and no expires_at in the login response

<a id="g-02"></a>

`G-02` · 🟠 high · **NOW** · effort S (<1 day)

**Why:** config/sanctum.php expiration=10080 is a hard cap from personal_access_tokens.created_at (Sanctum Guard checks created_at, not last_used_at) — every device is thrown to the login screen weekly with no warning; the login payload carries no expires_at so the app cannot even predict it. Sanctum 4 honours per-token expires_at but the global expiration overrides it, so mobile cannot get longer-lived tokens without touching config. No tokens are ever pruned (no scheduler, routes/console.php has only 'inspire', shared host has no artisan), and each login adds a row — the table only grows.

**Proposed change:** Raise config expiration to 43200 (30 d) as the global cap; at login pass an explicit expires_at per client: web 7 d (unchanged UX), mobile 30 d when the request carries X-Client: mobile (or device_name in body). Add POST /auth/refresh (auth:sanctum) that issues a new token with the SAME abilities and the same expires_at horizon, then deletes the current token (rotation). Return expires_at and abilities in login/refresh/user payloads. Add a cPanel cron (or a poor-man's prune inside login: delete this user's tokens with expires_at < now) to keep the table bounded. Client: refresh on app foreground when expires_at - now < 48 h.

**Endpoint sketch**

```text
POST /api/auth/login  body {email|code, password, device_name:"android|ios"} header X-Client: mobile
 → 200 {success:true,data:{token:"12|abc…",token_type:"Bearer",expires_at:"2026-10-14T20:00:00+02:00",abilities:["parent"],user:{…}}}
POST /api/auth/refresh  (Bearer) → 200 {success:true,data:{token:"13|def…",expires_at:"…",abilities:[…]}}  — old token deleted; 401 if already expired (client must re-login)
POST /api/auth/logout-all (Bearer) → 200 {success:true,message:"تم تسجيل الخروج من كل الأجهزة"}
```

### No device/push-token registration and no FCM delivery — notifications exist only as a 60-second web poll

<a id="g-03"></a>

`G-03` · 🟠 high · **NOW** · effort M (1–3 days)

**Why:** All notifications are Laravel database-channel rows (InAppNotification::via = ['database']) read by GET /notifications; layout.js polls every 60 s. A mobile app cannot poll in the background (iOS kills it), so parents will not learn about memorization_added/test_added/message_received, and managers will not see request_created, until they open the app. There is no device_tokens table and no route to register one. Messaging (MessageController) has the same problem — a chat with no push is unusable.

**Proposed change:** Add device_tokens table (user_id, platform android|ios, fcm_token unique, app_version, last_seen_at). Add POST /devices (register/upsert, auth:sanctum, any role) and DELETE /devices/{token} (call on logout). Add an FCM channel to InAppNotification::via() (or send inside sendSafe after the DB write) using FCM HTTP v1 with a service-account JSON (kreait/firebase-php or a 60-line JWT+curl client; vendor ships in the deploy zip so composer is fine; QUEUE_CONNECTION=sync means the push is sent inline — acceptable at this volume). Payload data: type, ref_id, student_id, link so the app can deep-link. Remove tokens on FCM 'UNREGISTERED' errors. Keep the DB notification as the source of truth for the in-app list.

**Endpoint sketch**

```text
POST /api/devices  {platform:"android",token:"fcm…",app_version:"1.0.0"} → 201 {success:true,message:"تم تسجيل الجهاز"}
DELETE /api/devices/{token} → 200 {success:true}
FCM data message: {type:"memorization_added",ref_id:"812",student_id:"55",title:"…",body:"…",notification_id:"uuid"}
GET /api/notifications?after=<uuid|created_at>&limit=30 (see G-13) for the in-app list.
```

### Parents and center managers have no self-service profile/password/phone endpoints

<a id="g-04"></a>

`G-04` · 🟠 high · **NOW** · effort S (<1 day)

**Why:** /profile, /profile/phone and /profile/password live in the teacher-gated group (role teacher|admin AND tokenCan('*')). A parent token (ability 'parent') and a manager token (ability 'manager') get 403 'هذه الصفحة للمعلمين فقط'. The frontend confirms: admin/profile.html reuses /profile (admin passes the teacher gate), manager/* and parent/* pages call no profile endpoint at all. So two of the four mobile roles cannot change their password or phone from the app, and cannot even see their own display_code (P12/CA3) because AuthController::userPayload omits it.

**Proposed change:** Move the three TeacherProfileController routes to a role-agnostic /me group under auth:sanctum only (no ability check needed — they act strictly on $request->user()). Keep /profile* as aliases so the web client and TeacherProfileTest keep working. Extend the payload with display_code, role, is_active, id_number, nationality_type/name, center_id, children_count (parent) / students_count (teacher). changePassword keeps recordPasswordChange('self') (revokes all tokens) but should return a fresh token for the current device so the app does not bounce to login (see G-14).

**Endpoint sketch**

```text
GET  /api/me → 200 {success:true,data:{id,name,display_code:"P12",email,phone,role:"parent",center_id,center_name,type,id_number,nationality_type,is_active,children_count}}
PUT  /api/me/phone {phone:"+218912345678"} → 200 {success:true,data:{phone:"0912345678"}}  (422 envelope if PhoneNumber::normalize fails)
POST /api/me/password {current_password,password,password_confirmation} → 200 {success:true,message:"…",data:{token:"14|…",expires_at:"…"}}
Aliases kept: GET /profile, PUT /profile/phone, POST /profile/password (teacher gate).
```

### A parent who forgets the password is permanently locked out — no reset path exists in production

<a id="g-05"></a>

`G-05` · 🟠 high · **NOW** · effort S (<1 day)

**Why:** Parent accounts are created by the admin/manager with an explicit guardian_password (ParentResolver requires it; no random generation) and a generated login email {latin}_p{n}@mutqin.ly (LoginEmail, e.g. ali_p12@mutqin.ly — CLAUDE.md's '{latin}.{id}@parent.mutqin.ly' is stale). The only reset is the phone-OTP flow, whose sendOtp() just writes to the log (no SMS gateway) and only exposes dev_otp when APP_ENV=local. No admin or manager endpoint can set a parent's password (PUT /teachers/{id} and PUT /admin/managers/{id} accept password; GET /manager/parents is read-only; students update never touches the parent). On mobile the largest role will hit this within days.

**Proposed change:** Add a manager-scoped (and admin) 'reset parent password' endpoint that only works for parents with at least one active child in the manager's center; sets the password, calls recordPasswordChange('admin') (revokes tokens) and returns nothing sensitive. Also add a first-login flag: users.must_change_password (set true by any admin/manager reset and at creation), returned in login payload so the app forces a change-password screen. Longer term wire an SMS provider (Libyana/Almadar) into AuthController::sendOtp() — until then hide the forgot-password flow in the app and show 'راجع إدارة مركزك'.

**Endpoint sketch**

```text
POST /api/manager/parents/{id}/reset-password {password,password_confirmation} → 200 {success:true,message:"تم تعيين كلمة مرور جديدة لولي الأمر — أبلغه بها"} ; 403 if the parent has no active child in the manager's center
POST /api/admin/users/{id}/reset-password (admin, any non-admin user) → same shape
Login payload gains user.must_change_password:true → app routes to /me/password before home; POST /me/password clears the flag.
```

### No health/version/app-config endpoint — no force-update, no cached reference data

<a id="g-08"></a>

`G-08` · 🟠 high · **NOW** · effort S (<1 day)

**Why:** Only Laravel's default GET /up exists (returns an HTML page at /backend/public/up, not JSON, outside /api). There is no API version, no min_app_version, and no single place for reference data: /memorizations/surahs returns a bare list of 114 names without their juz (SurahReference::SURAHS has it), centers require admin, and athman needs auth. Shipping in 2 weeks means bugs will be fixed post-launch — without a force-update switch, old clients cannot be retired. Deploys are manual zip uploads with no CLI (DEPLOY_LOG.md), so a config kill-switch is also the only remote lever.

**Proposed change:** Add public GET /api/app/config returning api_version, min_app_version {android,ios}, latest_app_version, store_urls, maintenance flag/message, feature flags (push_enabled, otp_enabled), server_time (Africa/Tripoli), and reference data: surahs [{name,juz_start,juz_end,order}], juz list, attendance/quality/result enums with Arabic labels. Send ETag (md5 of body) and honour If-None-Match → 304; Cache-Control: public, max-age=3600. Client fetches on cold start and caches. Also add public GET /api/health → {status:'ok',db:true,time}.

**Endpoint sketch**

```text
GET /api/app/config  (public, ETag)
→ 200 {success:true,data:{api_version:"1",min_app_version:{android:"1.0.0",ios:"1.0.0"},latest_app_version:"1.0.0",store_urls:{android:"…",ios:"…"},maintenance:{enabled:false,message:null},features:{push:true,otp:false,phone_login:true},server_time:"2026-09-14T20:00:00+02:00",timezone:"Africa/Tripoli",surahs:[{order:114,name:"الناس",juz_start:30,juz_end:30},…],enums:{attendance_status:{present:"حاضر",absent:"غائب",late:"متأخر"},quality:{excellent:"ممتاز",…},test_result:{"ناجح":"ناجح","راسب":"راسب"}}}}
GET /api/health → 200 {success:true,data:{status:"ok",db:true,time:"…"}}
Client sends X-App-Version: 1.0.3 and X-Client: mobile on every request (for logs and future gating).
```

### Date-only columns are serialized as UTC ISO timestamps shifted by -2 h

<a id="g-09"></a>

`G-09` · 🟠 high · **NOW** · effort S (<1 day)

**Why:** Attendance.date, Memorization.date, WeeklyTest.exam_date, Student.enrollment_date/birth_date are cast 'date'; Laravel's serializeDate() → Carbon::toJSON() converts to UTC, so with app timezone Africa/Tripoli the day 2026-09-14 is emitted as "2026-09-13T22:00:00.000000Z" (also for the hand-built arrays in parentStudentDetails, notifications and messages). The web client gets away with it because UI.fmtDate does new Date(d).toLocaleDateString() in a Libyan browser. A Flutter client that does DateTime.parse(...).toLocal() on a phone set to another timezone, or that takes substring(0,10), shows the previous day for every attendance/memorization row. Inputs, by contrast, are plain Y-m-d strings interpreted in Tripoli time.

**Proposed change:** Backend: cast date-only columns as 'date:Y-m-d' (Attendance, Memorization, WeeklyTest, Student) and, in the hand-built arrays (parentStudentDetails, teacherDetails/teacherDay, ReportService), emit ->toDateString(). Timestamps: override serializeDate() in a base model trait to ISO-8601 with the Tripoli offset ("2026-09-14T20:00:00+02:00") instead of Z so the day is unambiguous. fmtDate on the web tolerates both. Client (until then): parse ISO → convert to Africa/Tripoli via timezone package → take the date; never toLocal().

**Endpoint sketch**

```text
Before: {"date":"2026-09-13T22:00:00.000000Z","created_at":"2026-09-14T09:12:33.000000Z"}
After:  {"date":"2026-09-14","created_at":"2026-09-14T11:12:33+02:00"}
Inputs unchanged: date / exam_date / ?date= as "Y-m-d"; time as "HH:MM:SS"; month/year ints.
```

### Login by phone is not supported; parents must type a code (P12) or a generated email

<a id="g-06"></a>

`G-06` · 🟡 medium · **NOW** · effort S (<1 day)

**Why:** AuthController::login resolves the 'email' field by UPPER(display_code) then by exact email — phone is not tried. Parents receive a code like P12 and a synthetic email nobody remembers; the phone number is what every parent knows and is what ParentResolver de-duplicates on. Caveat: users.phone has no unique index (a teacher and a parent may legitimately share a phone; ParentResolver only dedupes inside role=parent), so a naive 'first by phone' is unsafe (the OTP flow already has this ambiguity with ->first()).

**Proposed change:** Extend login: if PhoneNumber::normalize(identifier) succeeds, load all active users with that phone; verify the password against each; log in iff exactly one matches (password disambiguates the role); otherwise the generic 422. Keep code/email paths. Document to parents 'رقم الهاتف أو كود الدخول'. Return display_code in the payload so the app can show 'كود دخولك P12' as a fallback.

**Endpoint sketch**

```text
POST /api/auth/login {email:"0912345678" | "+218912345678" | "P12" | "ali_p12@mutqin.ly", password} → 200 as today (+display_code, expires_at)
422 {success:false,message:"بيانات الدخول غير صحيحة",errors:{email:["…"]}} — unchanged wording, never reveals which candidate failed.
```

### Login payload lacks the fields a mobile client needs for routing and display

<a id="g-07"></a>

`G-07` · 🟡 medium · **NOW** · effort S (<1 day)

**Why:** userPayload returns id, name, email, phone, role, center_id, center_name, type only. Missing: display_code (parents/teachers/managers identify themselves by it), abilities (implicit today), expires_at, is_active, must_change_password, nationality/id_number, unread counts. The app must otherwise call /me + /notifications immediately after login.

**Proposed change:** Extend userPayload (used by login and /auth/user) with display_code, abilities, is_active, id_number, nationality_type, must_change_password, and a 'home' hint ('admin'|'manager'|'teacher'|'parent') so routing is explicit; add unread_notifications count.

**Endpoint sketch**

```text
POST /api/auth/login → data:{token,expires_at,abilities:["manager"],user:{id,name,display_code:"CA3",email,phone,role:"center_manager",home:"manager",center_id,center_name,type,is_active:true,must_change_password:false,unread_notifications:2}}
GET /api/auth/user → same user object.
```

### Login throttle is per IP (10/min) — carrier NAT and school-wide onboarding will trip it

<a id="g-11"></a>

`G-11` · 🟡 medium · **NOW** · effort S (<1 day)

**Why:** throttle:10,1 on /auth/login keys on IP for guests. Libyan mobile carriers put thousands of handsets behind a few NAT IPs; a center onboarding 30 parents in one sitting (or a wrong-password retry storm) returns 429 {"message":"Too Many Attempts."} to everyone on that IP. Forgot-password request is 5/min/IP. All authenticated routes also sit behind Laravel 11's default throttle:api (60/min per user id) — a dashboard that fans out 6–8 requests plus a 60 s notification poll is fine, but rapid pagination + PDF could approach it.

**Proposed change:** Key the login limiter by sha1(identifier)+IP (RateLimiter::for('login', fn($r)=>Limit::perMinute(10)->by(strtolower($r->input('email')).'|'.$r->ip())) and add a looser IP-only ceiling (e.g. 100/min) as abuse protection. Define RateLimiter::for('api') explicitly (e.g. 120/min per user, 30/min per IP for guests) so it is not implicit. Always return Retry-After. Client: honour Retry-After with a countdown on the login button; exponential backoff with jitter on 429/5xx; never retry non-idempotent POSTs automatically (attendance store, messages send).

**Endpoint sketch**

```text
POST /api/auth/login → 429 {success:false,message:"محاولات كثيرة — أعد المحاولة بعد 45 ثانية",code:"throttled",retry_after:45} headers Retry-After: 45, X-RateLimit-Limit: 10, X-RateLimit-Remaining: 0
```

### Notifications: no pagination, ref_id not exposed, link is a web path — no way to deep-link on mobile

<a id="g-13"></a>

`G-13` · 🟡 medium · **NOW** · effort S (<1 day)

**Why:** GET /notifications returns the latest 30 only (limit(30), no page) with {id(uuid),type,title,body,link,is_read,created_at,created_ago}. InAppNotification stores ref_id (request/memorization/test/student id) but NotificationController::index drops it, and link is 'manager/requests.html' / 'parent/child.html?id=55' / 'teacher/messages.html?student=55' — meaningful only to the web client. Mark-read is fine (POST /notifications/{id}/read → unread_count; POST /notifications/read-all).

**Proposed change:** Expose ref_id and a structured target {screen, params} derived from type (request_created→manager_requests, memorization_added/test_added→parent_child {student_id}, message_received→chat {student_id}, manager_deactivated→admin_managers). Add cursor pagination ?before=<created_at|uuid>&limit=30 and ?unread=1. Add a lightweight GET /notifications/unread-count for the badge (cheaper than the full list when the app is foregrounded).

**Endpoint sketch**

```text
GET /api/notifications?limit=30&before=2026-09-10T12:00:00+02:00
→ 200 {success:true,data:{unread_count:3,items:[{id:"uuid",type:"message_received",title,body,ref_id:55,target:{screen:"chat",params:{student_id:55}},link:"parent/messages.html?student=55",is_read:false,created_at:"…",created_ago:"قبل ساعتين"}],next_before:"…"|null}}
GET /api/notifications/unread-count → {success:true,data:{unread_count:3}}
```

### Messaging thread has no cursor/delta fetch and no unread badge endpoint

<a id="g-15"></a>

`G-15` · 🟡 medium · **NOW** · effort S (<1 day)

**Why:** GET /{role}/messages/{student} returns the last 100 messages (latest('id')->limit(100)->reverse) with no before/after parameters and marks the other side's messages read as a side effect; GET /{role}/messages returns threads with unread counts (fine). POST send returns the raw Message model (201) — shape differs from thread items (no 'mine' flag). Without ?after_id the mobile chat must re-download 100 messages every poll or FCM wake, and older history is unreachable.

**Proposed change:** Add ?after_id= (delta polling / push wake) and ?before_id=&limit= (history) to thread; add ?mark_read=0 to fetch without side effects; make send return the same item shape as thread (id, sender_role, mine:true, body, read_at, created_at). Add GET /messages/unread-count for the tab badge (both roles). Push type message_received with student_id is the wake signal (G-03).

**Endpoint sketch**

```text
GET /api/parent/messages/55?after_id=1200 → 200 {success:true,data:{student:{id,name},other:"أ. خالد",messages:[{id:1201,sender_role:"teacher",mine:false,body,read_at:null,created_at:"…"}],has_more_before:true}}
GET /api/teacher/messages/55?before_id=1100&limit=50 → older page
POST /api/parent/messages/55 {body} → 201 {success:true,data:{id,sender_role:"parent",mine:true,body,read_at:null,created_at}}
GET /api/messages/unread-count → {success:true,data:{unread:4}}
```

### Attendance xlsx import returns a non-envelope body; multipart contract must be codified for the mobile file picker

<a id="g-16"></a>

`G-16` · 🟡 medium · **NOW** · effort S (<1 day)

**Why:** POST /attendance/import and POST /manager/attendance/import expect multipart field attendance_file (mimes:xlsx, max 5120 KB; header row must be رقم الطالب|الاسم|التاريخ|الوقت[|الحالة]) and return counters at the top level (imported, imported_new, updated, present, late, absent, absent_computed, ignored_other_teachers, skipped, errors[{row,number,name,reason}], name_warnings[{row,number,file_name,system_name}]) with no data key — the only success response in the API that breaks the envelope. 422 for bad header/empty/corrupt file uses the envelope message. The zip PHP extension must be enabled on the host or every import fails with a 500.

**Proposed change:** Wrap the result in data (keep top-level keys too for one release so manager/attendance.html keeps working, then remove). Client: dio FormData with MultipartFile.fromFile(path, filename:'x.xlsx', contentType: MediaType('application','vnd.openxmlformats-officedocument.spreadsheetml.sheet')), sendTimeout 60 s, show errors[] and name_warnings[] tables exactly like the web.

**Endpoint sketch**

```text
POST /api/manager/attendance/import  multipart: attendance_file=<xlsx>
→ 200 {success:true,message:"تم الاستيراد",data:{imported:48,imported_new:40,updated:8,present:44,late:4,absent:6,absent_computed:6,ignored_other_teachers:0,skipped:2,errors:[{row:7,number:121,name:"…",reason:"…"}],name_warnings:[…]}}
422 {success:false,message:"عفواً، ترويسة ملف Excel غير مطابقة…"}
```

### No API versioning or client identification headers

<a id="g-18"></a>

`G-18` · 🟡 medium · **NOW** · effort S (<1 day)

**Why:** All routes are unversioned under /api; the web client and future mobile clients share them. Any response-shape change (G-01, G-09, G-10, G-16) risks breaking the deployed web app or an old mobile build. There is no way to tell in logs which client/version made a request.

**Proposed change:** Do not URL-version now (2 weeks). Require X-Client (web|android|ios) and X-App-Version headers; gate shape changes on them where needed (e.g. compact pagination, Y-m-d dates) and use min_app_version in /app/config (G-08) to retire old builds. Reserve /api/v2 for a future clean contract.

**Endpoint sketch**

```text
Request headers on every call: Accept: application/json, X-Client: android, X-App-Version: 1.0.0, Accept-Language: ar
Response header: X-Api-Version: 1
```

### Demo-accounts endpoint and dev_otp are environment-gated — verify the production .env, hide the panel in the app

<a id="g-22"></a>

`G-22` · ⚪ low · **NOW** · effort S (<1 day)

**Why:** GET /public/demo-accounts returns every user's name/email/role when APP_ENV=local OR APP_DEBUG=true; in production it returns data:[] as long as APP_DEBUG=false (.env.production.example sets it). forgotPasswordRequest adds dev_otp only when environment('local'). Both are correct but rely on server .env discipline on a host with no CLI to check.

**Proposed change:** Mobile: never call /public/demo-accounts in release builds (compile-time flag). Backend: additionally require a DEMO_ACCOUNTS_ENABLED=true env flag so APP_DEBUG mistakes do not leak the user directory; keep /public/stats public (it only exposes counts).

**Endpoint sketch**

```text
GET /api/public/demo-accounts → 200 {success:true,data:[]} in production (unchanged); only when DEMO_ACCOUNTS_ENABLED=true && APP_ENV=local → the list.
```

### Pagination is inconsistent: raw Laravel paginator, fixed page sizes, ?all=1 changes the shape, sub-paginators with custom page names

<a id="g-10"></a>

`G-10` · 🟡 medium · **NEXT** · effort S (<1 day)

**Why:** Lists return the untouched LengthAwarePaginator inside data (current_page, data[], first_page_url, from, last_page, last_page_url, links[{url,label:'&laquo; Previous',active}], next_page_url, path, per_page, prev_page_url, to, total). Page size is hard-coded per endpoint (20 students/teachers/users/parents/attendance-review, 15 memorizations/weekly-tests, 10 centers, 5 for each of the three sub-lists in /parent/students/{id} which use memo_page/att_page/tests_page) and no endpoint accepts per_page. ?all=1 on students, teachers, centers, /manager/teachers returns a bare array in data instead of the paginator (shape change). Other lists are unpaginated arrays (notifications 30, messages 100, manager student-requests, students-progress). next_page_url is absolute (APP_URL) — behind /backend/public on shared hosting it may not match the client base URL.

**Proposed change:** Keep the Laravel shape (the web client depends on it) but: accept ?per_page=1..100 everywhere paginate() is called (default = current size) via a small helper; drop links[] and the *_url fields for X-Client: mobile (or add ?compact=1) to save bandwidth; return the manager request list and notifications paginated behind ?page. Flutter data layer: one Paginated<T> model reading current_page, last_page, per_page, total, data; build next page as ?page=N (ignore next_page_url); handle List vs Map when ?all=1 is used.

**Endpoint sketch**

```text
GET /api/students?page=2&per_page=30&status=active&q=احمد  (Bearer)
→ 200 {success:true,data:{current_page:2,last_page:5,per_page:30,total:137,from:31,to:60,data:[{…student}]}}
GET /api/students?all=1 → data:[{…}]  (plain array — pick-lists only)
GET /api/parent/students/{id}?memo_page=2&att_page=1&tests_page=1 → data.memorizations / data.attendances / data.weekly_tests each a paginator of 5.
```

### /dashboard is not role-aware for managers and parents

<a id="g-12"></a>

`G-12` · 🟡 medium · **NEXT** · effort S (<1 day)

**Why:** DashboardController::index has only two branches: admin, else 'teacher'. A center_manager or parent token calling GET /dashboard receives role:'teacher' with $user->students() (hasMany by teacher_id) → zero stats and an empty list. The web app avoids it by calling /manager/dashboard and /parent/children directly. A mobile client that trusts the documented 'role-aware payload' would render an empty teacher home for two roles.

**Proposed change:** Make /dashboard dispatch on role: manager → reuse CenterManagerController::dashboard, parent → children summary (count, per-child today attendance, last memorization, unread messages), teacher/admin unchanged. Return role in every branch. Client meanwhile routes by user.role: admin→/dashboard, teacher→/dashboard, center_manager→/manager/dashboard, parent→/parent/children.

**Endpoint sketch**

```text
GET /api/dashboard (Bearer, any role)
→ parent: {success:true,role:"parent",data:{children:[{id,name,display_code,center,teacher_name,is_active,today_status:"present|absent|late|null",last_memorization:{surah_name,date,quality},unread_messages:2}]}}
→ center_manager: {success:true,role:"center_manager",data:{center:{id,name,city},stats:{total_students,students_without_teacher,total_teachers,today_present,today_absent,pending_requests}}}
```

### Password change revokes the caller's own token — app drops to login mid-flow

<a id="g-14"></a>

`G-14` · ⚪ low · **NEXT** · effort S (<1 day)

**Why:** User::recordPasswordChange() deletes all tokens (S1), including the one that just made the request; the web page copes by redirecting to login. On mobile the next background call (notifications poll) 401s and the interceptor wipes the session while the user is still on the success screen.

**Proposed change:** In changePassword (and the OTP verify) issue and return a new token for the current device after revoking the others (createToken with the same abilities), or accept a keep_current:true flag. Client fallback until then: on success, immediately re-login with the new password once, then drop it from memory.

**Endpoint sketch**

```text
POST /api/me/password {current_password,password,password_confirmation}
→ 200 {success:true,message:"تم تغيير كلمة المرور",data:{token:"15|…",expires_at:"…"}}
```

### Mixed enum vocabularies and empty-array-instead-of-object serialization

<a id="g-20"></a>

`G-20` · ⚪ low · **NEXT** · effort S (<1 day)

**Why:** Attendance status is English (present|absent|late), memorization quality English (excellent|good|average|weak), but teacher type is Arabic ('محفظ أساسي'|'محفظ معاون'), weekly-test result Arabic ('ناجح'|'راسب') and questions.*.result must be sent in Arabic. GET /attendance returns attendances as {student_id: status} (object) but [] when empty (PHP empty array) — typed JSON decoding in Dart throws. Notification ids are UUID strings while all other ids are ints; ?all=1 flips a paginator into an array (G-10).

**Proposed change:** No breaking change now: publish the vocab in /app/config enums (G-08) and cast empty maps as objects ((object)[] / ->toArray() ?: new \stdClass) in AttendanceController::index. Client: enum mappers with unknown→fallback, tolerant Map/List decoding for attendances.

**Endpoint sketch**

```text
GET /api/attendance?date=2026-09-14 → data:{students:[…],attendances:{"55":"present","56":"late"} | {} ,date:"2026-09-14"}
```

### No ETag/If-None-Match, no Cache-Control, compression not guaranteed

<a id="g-21"></a>

`G-21` · ⚪ low · **NEXT** · effort S (<1 day)

**Why:** No middleware sets ETag or Cache-Control; nothing in app/ or public/.htaccess enables mod_deflate, so gzip depends on the Libyan Spider Apache defaults. Reference data (surahs, athman hizb lists, other centers, teacher pick-lists) and heavy lists are re-downloaded fully on every screen open over Libyan mobile data.

**Proposed change:** Add a small ETag middleware on GET /api/* (md5 of body → 304 on If-None-Match) plus Cache-Control: private, max-age for reference endpoints; add AddOutputFilterByType DEFLATE application/json to public/.htaccess (IfModule guarded). Client: dio_cache_interceptor with ETag support; persist /app/config and athman on device.

**Endpoint sketch**

```text
GET /api/athman/hizb/60  If-None-Match: "a1b2…" → 304 (empty body)  /  200 + ETag: "a1b2…", Cache-Control: private, max-age=86400
```

### Manager 'other centers', admin pick-lists and teacher pick-lists have no lightweight mobile variants

<a id="g-25"></a>

`G-25` · ⚪ low · **NEXT** · effort S (<1 day)

**Why:** Pick-lists used by forms (centers ?active=1&all=1 — admin only; /manager/centers; /manager/teachers?all=1; /teachers?all=1) return full models with counts and relations. Fine on desktop, heavy on mobile and repeated on every form open.

**Proposed change:** Add ?fields=id,name,display_code (select-list projection) or include the common lists in /app/config-like per-role bootstrap endpoint GET /me/bootstrap (own center, teachers of my center, children) fetched once per session and cached with ETag.

**Endpoint sketch**

```text
GET /api/me/bootstrap → manager: {success:true,data:{center:{id,name,city,has_primary},teachers:[{id,name,display_code,type,is_active}],other_centers:[{id,name,city}]}} ; parent: {children:[{id,name,display_code,teacher_id,teacher_name,center}]} ; teacher: {students:[{id,name,display_code,age}]}
```

### PDF reports are inline bytes behind a bearer header — fine for dio, but slow and unshareable by URL

<a id="g-17"></a>

`G-17` · ⚪ low · **LATER** · effort S (<1 day)

**Why:** ReportPdfController::render returns response(bytes,200,['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="…"']) — no envelope, requires Authorization, generated synchronously by mPDF (multi-second on shared hosting; needs the gd extension). Mobile can fetch with dio responseType: bytes and hand the file to share_plus/printing, but cannot open it in an external viewer or WhatsApp by link, and WebView/url_launcher cannot send the bearer header.

**Proposed change:** Keep bytes as the primary path. Optionally add POST /reports/pdf-link that returns a short-lived signed URL (URL::temporarySignedRoute, 10 min, bound to user id + report params) so the app can open the PDF in the system viewer / share a link; the signed route re-checks ownership. Set a 90 s client timeout for PDF calls and cache the last PDF per report on device.

**Endpoint sketch**

```text
GET /api/reports/student/55/pdf?month=9&year=2026 (Bearer) → 200 application/pdf bytes (as today)
POST /api/reports/signed-link {report:"student",id:55,month:9,year:2026} → 200 {success:true,data:{url:"https://mutqin.ly/backend/public/api/reports/signed/…?expires=…&signature=…",expires_at:"…"}}
```

### Validation and business errors are Arabic strings only, without stable codes

<a id="g-19"></a>

`G-19` · ⚪ low · **LATER** · effort S (<1 day)

**Why:** Every error is a hand-written Arabic message (validation messages arrays, PrimaryTeacherRule, ParentResolver, StudentRequestController 422s, 403 gates). For an Arabic-only app this is displayable as-is, but the client cannot branch on a condition (e.g. 'no primary teacher', 'duplicate national id', 'account inactive', 'center inactive') without string matching, and the 403 login reasons (inactive account vs inactive center) are only distinguishable by text.

**Proposed change:** Add an optional machine code alongside message where the client needs to branch: login 403 code:'account_inactive'|'center_inactive'; 409 attendance 'attendance_conflict'; 422 'duplicate_national_id', 'primary_exists', 'guardian_password_required', 'target_manager_missing'. Keep messages authoritative for display.

**Endpoint sketch**

```text
POST /api/auth/login → 403 {success:false,code:"center_inactive",message:"مركزك غير نشط حالياً، راجع إدارة النظام",errors:{email:["…"]}}
POST /api/attendance → 409 {success:false,code:"attendance_conflict",message:"…",data:{conflicts:[{student_id,name,current_status,new_status}]}}
```

### No image/avatar or attachment upload anywhere

<a id="g-23"></a>

`G-23` · ⚪ low · **LATER** · effort M (1–3 days)

**Why:** Neither users nor students have a photo column; messages are text-only by design (MessageController: 'لا مرفقات'); the only upload is the attendance xlsx. A mobile app will be expected to show student photos and let parents send a picture of the mushaf page — not possible without storage endpoints, and shared hosting has only the local disk (FILESYSTEM_DISK=local; storage/ is outside the web root by design).

**Proposed change:** Defer. If added: students.photo_path + POST /students/{id}/photo (multipart, ≤2 MB, jpg/png/webp, resized server-side with gd) served through GET /students/{id}/photo behind auth (or a signed URL) — never a public storage symlink on this host.

**Endpoint sketch**

```text
POST /api/students/55/photo  multipart photo=<jpg> → 200 {success:true,data:{photo_url:"/api/students/55/photo?v=1694700000"}}
```

### No 'logout everywhere' / active sessions view; tokens accumulate per login

<a id="g-24"></a>

`G-24` · ⚪ low · **LATER** · effort S (<1 day)

**Why:** logout deletes only currentAccessToken(); every login (web + each phone) creates a new personal_access_tokens row with name 'auth_token' and nothing prunes expired rows (no scheduler). Users cannot see or revoke other devices; support has no tool either besides deactivation.

**Proposed change:** Name tokens by device (device_name from login body), add GET /me/sessions and DELETE /me/sessions/{id} (own tokens only), POST /auth/logout-all; at login delete this user's expired tokens (cheap prune without cron).

**Endpoint sketch**

```text
GET /api/me/sessions → {success:true,data:[{id:12,name:"android · Samsung A12",last_used_at:"…",created_at:"…",current:true}]}
DELETE /api/me/sessions/9 → {success:true}
```

## Architecture options

### A — Feature-first Clean-ish: Riverpod 3 (codegen) + go_router + dio + freezed/json_serializable + ARB l10n + flutter_secure_storage

One Flutter app, folders by feature (auth, attendance, memorization, weekly_tests, messages, parent, manager, admin, reports), each feature split into data (freezed DTO + repository over a shared dio ApiClient), application (Riverpod AsyncNotifier/Notifier), and presentation (screens + widgets). No use-case classes and no separate domain-entity layer in the MVP (DTO == model) — that is the 'ish'. go_router with an auth/role redirect and one StatefulShellRoute per role (bottom nav = first 4 destinations + 'more' drawer, mirroring layout.js). Envelope parsing and a sealed Failure hierarchy (Network/Unauthorized/Forbidden/Validation(errors by field)/Conflict(data)/Server/Unknown) live in core/network and are the only place HTTP status codes are interpreted. AsyncValue drives every loading/error/data state; ref.invalidate drives refresh after mutations.

- **2-week fit:** 82/100
- **Stack:** `Flutter stable 3.3x / Dart 3.x`, `flutter_riverpod 3 + riverpod_annotation + riverpod_generator + riverpod_lint`, `go_router 16 (redirect + StatefulShellRoute.indexedStack per role)`, `dio 5 (interceptors: bearer, 401→logout, logging) + http_mock_adapter in tests`, `freezed 3 + json_serializable 6 + build_runner`, `flutter_localizations + intl + ARB (app_ar.arb as template locale)`, `flutter_secure_storage (token + user JSON)`, `infinite_scroll_pagination for paginated lists`, `printing + path_provider + share_plus for PDFs; file_picker for xlsx`, `firebase_core/messaging/crashlytics/analytics behind service interfaces`, `mocktail, alchemist (goldens), integration_test`
- **Pros:** Best long-horizon fit for 1–2 devs: Riverpod's AsyncNotifier + AsyncValue removes hand-written loading/error/state plumbing for ~30 screens; provider overrides make notifier and widget tests cheap without a DI container; freezed/json_serializable eliminate the most common MVP bug class (hand-written fromJson typos across ~40 DTOs) and give copyWith/== for free; the boilerplate is exactly what Claude generates reliably; Feature folders scale to years: a feature can later be lifted into a package (melos) without moving imports; sealed Failure + Envelope isolate the API contract in one module; go_router redirect + role shells give deep links from notification links and push payloads for free; single app keeps auth, notifications, messaging and store listing unified; riverpod_lint/custom_lint catch misuse (ref in wrong scope, missing autoDispose) at analysis time
- **Cons:** build_runner is a real tax: 30–90 s full builds, 'watch' must run in the background, generated files pollute diffs (or CI must run codegen); Riverpod 3 changed APIs vs 2.x (Notifier/AsyncNotifier only, offline persistence, retry) — older tutorials and some Claude outputs still emit 2.x idioms; requires a short team convention doc; Slightly more ceremony than Option C on Day 1–2 (ApiClient, Failure hierarchy, provider scaffolding) — roughly one day of the ten; Riverpod's 'everything is a provider' can blur boundaries; discipline needed to keep HTTP out of widgets and business rules out of notifiers

### B — flutter_bloc + get_it/injectable + go_router + dio + freezed

Classic enterprise Flutter: every screen backed by a Cubit/Bloc with explicit event/state classes (freezed unions), repositories and data sources registered in get_it via injectable codegen, BlocProvider trees under go_router. Strongest ceremony and the most explicit state machines (useful for the attendance save/409-confirm flow and the request approve/reject flow), with hydrated_bloc available later for offline state.

- **2-week fit:** 62/100
- **Stack:** `flutter_bloc 9 + bloc_test`, `get_it + injectable + injectable_generator`, `go_router 16`, `dio 5`, `freezed 3 + json_serializable 6 + build_runner`, `flutter_localizations + intl + ARB`, `flutter_secure_storage`, `hydrated_bloc (later, offline state)`, `mocktail, alchemist, integration_test`
- **Pros:** Most explicit and auditable state transitions; bloc_test makes state-machine tests declarative — good for the 409 attendance conflict and transfer-approval flows; Very common in large Arabic-market agencies and enterprises; easiest to hire for and to hand over to a bigger team later; Clear separation enforced by structure (no way to accidentally fetch from a widget); BlocObserver gives a single hook for analytics/crash breadcrumbs on every state change
- **Cons:** Highest boilerplate per screen (event + state + bloc + registration); with ~30 screens and 1–2 devs this costs 2–3 of the 10 days versus Riverpod; Two codegens (freezed + injectable) plus build_runner; injectable adds a DI layer that Riverpod already provides implicitly; Cross-bloc communication (e.g. saving attendance should refresh dashboard stats and the notification badge) needs listeners or a repository stream layer — more plumbing than ref.invalidate; Simple read-only lists (parent child sections, admin lists) become disproportionately heavy

### ⭐ C — Pragmatic minimal: Provider + ChangeNotifier + plain service classes, hand-written JSON, no codegen

Direct port of the web client's shape: a Config/Api/Auth trio as singletons, one ChangeNotifier per screen or feature, models with hand-written fromJson, Navigator 2 via go_router (or even Navigator 1 named routes). Zero build_runner. Fastest possible start; the app would look like frontend-html/ rewritten in Dart.

- **2-week fit:** 90/100
- **Stack:** `Flutter stable 3.3x`, `provider 6 + ChangeNotifier`, `go_router 16 (or Navigator named routes)`, `dio 5 or package:http`, `hand-written models (no freezed), manual == / copyWith`, `flutter_secure_storage`, `flutter_localizations (strings inline in Arabic, no ARB)`, `printing, file_picker, firebase_* as needed`
- **Pros:** Fastest first week: no codegen, no provider graph, no DI — every dev already knows ChangeNotifier; Mirrors the existing JS modules (api.js/auth.js/layout.js) almost 1:1, so the web team can read it immediately; Smallest dependency surface; trivial CI (analyze + test + build); Perfectly adequate for the parent role and read-only screens
- **Cons:** Hand-written fromJson for ~40 DTOs with nested paginators, nullable teacher/center objects and list-or-paginated responses is where MVP bugs will hide; no exhaustive sealed types for Failure; ChangeNotifier per screen degrades fast: shared state (auth, notifications badge, cached student lists) ends up in global singletons; testing needs manual mocking of singletons; No async-state primitive — every notifier re-implements loading/error/data and race handling (the web already had to add fetchSeq guards for stale responses); Realistic expectation is a partial rewrite to Riverpod/Bloc within 6–12 months when offline queue, multi-center, or a second dev team lands; that rewrite costs more than the ~1 day saved now

## Sprint plan (ten working days + buffer)

### Day 0 (pre-sprint, same week as kickoff) — Remove external lead-time blockers before code starts

- Start Apple Developer Program enrolment (organisation → D-U-N-S; 1–4 weeks) and Google Play Console (prefer an organisation account to avoid the 12-testers/14-days closed-testing rule for new personal accounts)
- Create Firebase project with dev/prod Android apps + iOS app; download google-services.json / GoogleService-Info.plist per flavor
- Decide bundle ids (ly.mutqin.app / ly.mutqin.app.dev), app name «مُتقِن», version scheme 1.0.0+build
- Dev API reachable from devices: run artisan serve with PHP_CLI_SERVER_WORKERS=4 (or XAMPP Apache vhost) on the LAN IP; confirm CORS irrelevant (native) and http allowed only for dev
- Write docs/API_CONTRACT.md by recording real JSON responses for the ~25 MVP endpoints into test/fixtures/*.json (login, /auth/user, /dashboard, /attendance, /memorizations, /weekly-tests, /athman/search, /parent/children, /parent/students/{id}, /manager/student-requests, /notifications, messages)

### Day 1 — Project skeleton, theme, network core, auth state, CI — all tested

- flutter create with org id; folder structure from this proposal; analysis_options with flutter_lints + riverpod_lint; l10n.yaml with app_ar.arb as template
- Flavors via --dart-define-from-file=env/{dev,staging,prod}.json exposing API_BASE_URL, FLAVOR; Android network_security_config allows cleartext only in dev; iOS ATS exception only in dev Info.plist
- Theme: tokens.dart from theme.css (emerald #04532F, emerald-dark #04361F, jade #006850, gold #D4AF37, gold-deep #9A7A1E, ivory #FBF7DA, paper #EAE6D4, danger #B23A48, info #2A6F8E), MutqinTokens ThemeExtension, Amiri headlines + Cairo body bundled, MaterialApp locale ar → RTL
- core/network: dio ApiClient, Envelope<T>, Paginated<T> + listOrPaginated parser, sealed Failure, AuthInterceptor (bearer), UnauthorizedInterceptor (401 → session clear)
- core/storage: SecureSession (token + user JSON) ; AuthNotifier (unknown → signedOut → signedIn(user)) with bootstrap that validates the stored token via GET /auth/user
- go_router skeleton: redirect on auth state + role prefix guards (/teacher, /parent, /manager, /admin); placeholder role shells
- GitHub Actions ci.yml: flutter analyze, build_runner, flutter test, debug APK artifact on PR
- Unit tests: envelope parsing, failure mapping for 401/403/409/422/5xx/network, paginated vs plain list, interceptors with http_mock_adapter

### Day 2 — Authentication and app shell usable by all four roles

- Login screen (email OR display code T1/CA1 + password; 422 → inline field errors from errors map; 403 inactive account/center message; throttle 429 message); demo-accounts panel from /public/demo-accounts shown only in dev flavor
- Forgot password: [redacted] OTP by phone (/auth/forgot-password/request → verify), dev_otp shown only in dev flavor
- Role shells: StatefulShellRoute.indexedStack per role with bottom nav = first 4 NAV entries + 'المزيد' drawer listing the rest + logout (mirrors layout.js)
- Notifications: bell with unread badge, list bottom sheet, mark one / mark all read, 60 s foreground polling paused in background (WidgetsBindingObserver); NotificationLinkMapper converts web links (manager/requests.html, teacher/students.html, admin/managers.html) to app routes
- Shared widgets: MqScaffold, MqCard, StatTile, EmptyState, ErrorView (Failure → Arabic text + retry), SearchField (300 ms debounce), badges (quality/attendance/result/status), LtrText, ConfirmDialog, FormSheet
- Widget tests: login validation + 422 rendering; notifier tests for AuthNotifier bootstrap paths (valid token, 401, network error)

### Day 3 — Teacher: dashboard, students list and student details (the teacher's home base)

- Teacher dashboard from GET /dashboard (total_students, today_present/absent/late, weekly stats) with pull-to-refresh
- Students list: GET /students paginated (20/page) with infinite scroll (infinite_scroll_pagination) + server q search + status filter chips; Student freezed model incl. nullable teacher/center/parent, former_teacher_name, display_code, is_active
- Student details screen: GET /students/{id}/details + day view GET /students/{id}/day?date=; edit sheet PUT /students/{id} (teacher-allowed fields only)
- PagedListView generic widget + PagedNotifier pattern documented in docs/ARCHITECTURE.md
- Repository + notifier tests against fixtures

### Day 4 — Teacher: attendance marking end-to-end, including the 409 confirm handshake and xlsx import

- Attendance screen: date picker (default device-local today, Y-m-d), per-student segmented control حاضر/غائب/متأخر preselected from data.attendances, bulk 'الكل حاضر/غائب', sticky save button
- Save flow: POST /attendance {date, attendance{id:status}, confirm:false}; on ConflictFailure show dialog listing name: current→new; on confirm re-POST with confirm:true; success snackbar + invalidate dashboard
- Attendance report screen (GET /attendance/report with date range)
- Xlsx import: file_picker (custom, xlsx) → multipart field attendance_file (≤5 MB) → result summary; component reused by manager on Day 8
- Golden test (alchemist, RTL) for the attendance screen; widget test for the conflict dialog

### Day 5 — Teacher: memorization entry and weekly thumn tests with athman autocomplete

- Memorization list: GET /memorizations?q= (server search by student/juz/name), delete with confirm; add sheet: student picker, date, surah picker from GET /memorizations/surahs (reverse order, juz shown from bundled assets/data/surahs.json), quality (excellent/good/average/weak), page_from/page_to 1–604 with client pre-validation + server 422 mapping, notes
- Students progress screen: GET /memorizations/students-progress (juz completion bars)
- Weekly tests: list GET /weekly-tests; editor for create (POST) and edit (PUT /weekly-tests/{id}) with dynamic thumn rows {eighth_start, result ناجح/راسب, mistake shown only when راسب}, at-least-one-row rule
- AthmanAutocomplete widget: debounced GET /athman/search?q= showing start_text + surah/hizb/thumn/page meta; reusable for future revisions feature
- Tests: memorization form validation, weekly test payload builder

### Day 6 — Parent role complete + messaging for both parent and teacher + teacher profile

- Parent children screen: GET /parent/children cards (is_active badge, teacher, center)
- Child details: GET /parent/students/{id}?memo_page&att_page&tests_page → header info, attendance summary tiles (total/present/absent/late/percent), three independently paged sections (memorization, attendance, tests with passed/failed counts) with 'عرض المزيد' per section
- Messaging: threads list + thread screen + send for parent (/parent/messages/{student}) and teacher (/teacher/messages/{student}) sharing one ThreadView widget; optimistic append with rollback on failure; 30 s polling while open
- Teacher profile: GET /profile, PUT /profile/phone, POST /profile/password (on success the server revokes tokens → app shows 'سجّل الدخول من جديد' and signs out cleanly)
- Golden test for child details; widget test for message send failure rollback

### Day 7 — Manager: dashboard, requests (approvals + transfers), teachers and students of the center

- Manager dashboard GET /manager/dashboard + GET /manager/center (has-primary flag drives the 'add primary teacher' hint)
- Requests screen: tabs pending/all from GET /manager/student-requests?status=; row shows kind (add / incoming / outgoing via direction), student + national id (LTR), requested_by, from→to route, date; incoming pending → approve (sheet with optional target_teacher_id from GET /manager/teachers?status=active&all=1) / reject (admin_note); outgoing shows 'بانتظار مدير …'; create transfer sheet: student (GET /manager/students?status=active&all=1) + target center (GET /manager/centers)
- Teachers: list/search, add (POST /manager/teachers with type primary/assistant; PrimaryTeacherRule 422 surfaced), edit, toggle status; teacher performance screen GET /manager/teachers/{id}/performance
- Students: list with q search, add (POST /manager/students with next-code preview, guardian resolution: existing parent search by national id via /manager/parents/search or new guardian fields, nationality libyan/foreigner with id format rule), toggle status, change teacher (PUT /manager/students/{id}/teacher)
- Notifier tests for request approval refresh + notification badge invalidation

### Day 8 — Manager attendance review + all PDF reports + admin mobile-lite

- Manager attendance import (reuse Day 4 component, /manager/attendance/import) and attendance review: GET /manager/attendance paginated with date/teacher/status filters, single-record correction PUT /manager/attendance/{id}/status with corrected_by/at shown
- PdfService: dio GET with bearer, responseType bytes → temp file (path_provider) → Printing.layoutPdf / sharePdf (share sheet, print, save); in-app preview via printing's PdfPreview; used for teacher (/reports/student/{id}/pdf, /reports/teacher/pdf), manager (/manager/reports/{center,at-risk,teachers}/pdf) and admin (/reports/admin/…/pdf)
- Manager reports screens: GET /manager/reports/system, /management, /center, /teacher/{id}, /student/{id}
- Admin mobile-lite: dashboard (system totals), centers/teachers/managers/users/students lists with search and status toggles, center details (/centers/{id}/stats, /teachers, /students), missing-national-id report, admin PDFs; create/edit forms deferred to buffer (web remains primary for admin CRUD)
- Tests: PdfService with mocked bytes; attendance review correction flow

### Day 9 — Hardening: offline read cache, accessibility, observability, push readiness, store assets

- ReadCache: last good JSON per request key persisted to disk; stale-data banner when connectivity_plus reports offline; mutations disabled offline with clear Arabic message; SyncQueue interface stub in sync/ for Phase 2
- Accessibility pass: Semantics labels on icon buttons and badges, ≥48 dp tap targets on attendance segments, text scaling tested at 1.3×, contrast fix (gold text uses gold-deep), TalkBack/VoiceOver smoke on login + attendance
- Firebase Crashlytics + Analytics wired behind CrashService/AnalyticsService (no-op in dev flavor); screen-view events on router changes; user id = users.id (no PII)
- PushService: firebase_messaging permission + FCM token retrieval + foreground display via flutter_local_notifications + tap → NotificationLinkMapper; backend registration adapter is a no-op until POST /devices exists (feature flag)
- App icon (flutter_launcher_icons from the star logo rendered to 1024 px PNG), splash (flutter_native_splash, emerald background), Arabic store listing copy, screenshots plan, privacy policy written and published as frontend-html/privacy.html (the web currently has none — required by both stores) + in-app link
- Golden tests for login, requests, memorization; integration_test login → mark attendance → save against the dev API (or fixtures via http_mock_adapter)

### Day 10 — Release candidate on both stores' internal tracks and handoff

- Android: upload keystore generated and stored in CI secrets; signed AAB (prod flavor, HTTPS API) built by GitHub Actions on tag; uploaded to Play internal testing
- iOS: Codemagic workflow (macOS) with App Store Connect API key → archive, sign, upload to TestFlight internal group; if Apple enrolment is still pending, distribute an ad-hoc/dev build to registered devices instead
- Real-device smoke on a low-end Android (Android 10, 2 GB) and an iPhone: login for all four demo roles, attendance save, memorization add, weekly test with autocomplete, parent child details, manager approve transfer, PDF share
- Performance pass: list scrolling with 200+ students (const widgets, keys, ListView.builder), image/asset sizes, startup time with deferred Firebase init
- docs/ARCHITECTURE.md, docs/RELEASE.md (flavors, signing, versioning), docs/API_CONTRACT.md refreshed; CHANGELOG; known-gaps list for Phase 2

### Buffer (Days 11–12) — Absorb slippage and external lag; ship what was deferred

- Fix tester feedback from internal tracks; App Store review / TestFlight external review lag (1–3 days)
- Admin create/edit forms (center, teacher, manager) if skipped on Day 8
- Backend gaps negotiated during the sprint: POST /devices for push tokens, POST /auth/refresh (token rotation before the 7-day expiry), unread message counts for the messages tab badge
- Phase 2 design notes: offline attendance queue (drift), revisions/tajweed screens once endpoints exist, tablet layouts, English locale

## Screens by role

### Admin (مدير النظام) — 2 must · 11 should

| Web page | Mobile screen | Purpose | Endpoints | Priority | Size |
|---|---|---|---|---|---|
| `admin/dashboard.html` | **Admin Home**<br>_Single call, role-aware payload keys: stats, recentStudents, teachers. Pull-to-refresh. Quick actions deep-link to list screens._ | System monitoring at a glance: 6 stat tiles (total_teachers, total_students [active], total_centers [active], today_present/absent/late), recent students (name, teacher, center), teacher roster with students_count, quick actions (add student/teacher/center). | `GET /dashboard` | MUST | S |
| `admin/centers.html` | **Centers List**<br>_Web fetches all centers unpaginated (small set). Toggle needs confirm dialog with Arabic warning: deactivation revokes tokens of all members and blocks their login; data retained. Use UI.confirmAction equivalent (custom dialog, not OS alert)._ | Browse all centers (display_code C{n}, name, city, address, phone, students_count, is_active); client-side normalized search over all columns; tap row -> Center Detail; row actions edit / toggle status. | `GET /centers?all=1`<br>`PUT /centers/{id}/status` | SHOULD | S |
| `admin/centers.html` | **Center Form (create/edit)**<br>_Web uses generic UI.formModal. display_code is server-reserved; never sent._ | Bottom-sheet/full-screen form: name (required), city, address, phone. 422 errors keyed by field in Arabic. | `POST /centers`<br>`PUT /centers/{id}` | SHOULD | S |
| `admin/center.html` | **Center Detail**<br>_Three parallel requests. On mobile use two tabs (Teachers / Students) with infinite scroll instead of two stacked tables. Lists default to active only._ | Header meta (name, code, active badge, city, address, phone, created_at); 7 stat tiles (students_active, teachers_active, attendance_percent for month with present/total, students_without_teacher, students_inactive, tests_month pass_percent passed/total, attendance_today present/late/absent); manager card (name, code, email, phone) or 'no manager'; paginated teachers (code, name, type, active_students_count, phone, status) and students (code, name, guardian_name, age, teacher or former_teacher_name or 'no teacher', national_id or 'missing', status) lists 5/page with 'show more'. | `GET /centers/{id}/stats`<br>`GET /centers/{id}/teachers?page=`<br>`GET /centers/{id}/students?page=` | SHOULD | M |
| `admin/teachers.html` | **Teachers List**<br>_Debounce 300ms + request sequence guard (stale responses ignored). Toggle confirm: deactivation revokes sessions immediately._ | Server-paginated teacher list with live search q (normalized name / display code T5 or Arabic-digit / center name), center filter (AND with q), 'load more', counter 'X of total'; rows: avatar, name, email, code, center badge, active badge; actions: details, edit, toggle status. | `GET /teachers?page=&q=&center_id=`<br>`GET /centers?all=1&active=1`<br>`PUT /teachers/{id}/status` | SHOULD | M |
| `admin/teachers.html` | **Teacher Detail (admin)**<br>_Password-change audit is admin-only info; low value on mobile — could hide logs behind an expander or defer._ | Read-only sheet: name, email, center_name, type, phone, students_count, password_changed_count, password_last_changed_at, collapsible password_logs (changed_at, method otp/self/admin), supervised students table (#, name, code, age). | `GET /teachers/{id}` | COULD | S |
| `admin/teachers.html` | **Teacher Form (create/edit)**<br>_Server enforces the rule anyway (422). Show resulting email after save (returned in response)._ | Create: name, email_prefix (latin, server builds {prefix}_t{n}@mutqin.ly), phone, center_id (active centers), type (primary/assistant), password + confirmation. Edit: name, email, phone, center_id, type, optional new password. Single-primary rule: on center change call has-primary and disable 'محفظ أساسي' option with hint naming existing primary (ignore self on edit). | `POST /teachers`<br>`PUT /teachers/{id}`<br>`GET /centers/{id}/has-primary`<br>`GET /centers?all=1&active=1` | SHOULD | M |
| `admin/students.html` | **All Students List**<br>_Banner reads only .total from the missing_national_id query. Toggle confirm text: inactive students leave attendance/fingerprint/reports but history stays. Large list: rely on server pagination; never fetch all._ | Server-paginated list with live search q (name, guardian, former teacher, nationality, national id, normalized phone, center, teacher), status filter active (default)/inactive/all, 'load more', banner with count of students missing national_id (Awqaf readiness); rows: name+guardian_name, code S{n}, national_id or warning badge, nationality badge (libyan / foreigner: name), center, teacher or 'former: X' or none, age, status; actions edit, toggle status. | `GET /students?page=&q=&status=`<br>`GET /students?missing_national_id=1`<br>`PUT /students/{id}/status` | MUST | M |
| `admin/students.html` | **Add Student Wizard (admin)**<br>_Web also preloads GET /teachers?all=1 (all teachers) — unnecessary on mobile; use the center-scoped call only. Body includes guardian_mode; server ignores guardian fields when none. Sequence guard on center change (fast double change). 422 field errors must map to the right step._ | Multi-step form: (1) predicted code preview (read-only, may change), name, nationality_type libyan/foreigner (+nationality_name when foreigner; id label switches to passport/residence), national_id optional (Libyan ^[12]\d{11}$), center_id (active) -> cascading teacher_id list filtered by center (with 'no teacher'), age, phone; (2) guardian mode radio: new (guardian_name, guardian_email optional match-only, guardian_nationality_type/_name, guardian_id_number, guardian_phone, guardian_password required >=6) / existing (debounced parent search by name or phone -> chip with children_count) / none. On save show actual display_code and warn if it differs from preview ('register S{n} number in fingerprint device'). | `GET /students/next-code`<br>`GET /centers?all=1&active=1`<br>`GET /teachers?all=1&center_id=`<br>`GET /parents/search?q=`<br>`POST /students` | SHOULD | L |
| `admin/students.html` | **Edit / Transfer Student (admin)**<br>_Same cascade widget as the wizard. Guardian is not editable here._ | Form: name, nationality_type (+nationality_name), national_id, center_id -> teacher_id cascade (transfer between teachers/centers), age, phone. | `PUT /students/{id}`<br>`GET /teachers?all=1&center_id=` | SHOULD | S |
| `admin/managers.html` | **Center Managers List**<br>_Unpaginated (one manager per center). Deactivation confirm must warn: center left without an active manager; its pending incoming requests stay pending and new requests are refused until a manager is active._ | List of center managers: avatar, name, email, display_code CA{n}, center badge, created_at, status; client-side search; actions edit, toggle status. | `GET /admin/managers`<br>`GET /centers?all=1&active=1`<br>`PUT /admin/managers/{id}/status` | SHOULD | S |
| `admin/managers.html` | **Manager Form (create/edit)**<br>_Compute 'centers without manager' client-side from managers list (excluding the edited one). If none available, show toast 'all centers have managers' instead of opening form._ | Create: name, email_prefix (auto-strip pasted '@...' and '.centeradmin'), phone, center_id restricted to centers WITHOUT a manager, password + confirmation (required, no auto-generation). Edit: name, email, phone, center_id (own center + manager-less centers), optional password; display_code shown read-only. | `POST /admin/managers`<br>`PUT /admin/managers/{id}` | SHOULD | S |
| `admin/users.html` | **All Users (read-only)**<br>_View-only by design (no edit/toggle from here). Low MVP value; defer or ship as simple list._ | Server-paginated directory of every account: code, name, role badge (admin/center_manager/teacher/parent), email, phone, center_name, status; filters role + status (active default/inactive/all) + live q. | `GET /admin/users?page=&status=&role=&q=` | COULD | S |
| `admin/reports.html` | **Admin Reports Hub**<br>_PDF endpoints require Bearer header (no public URL): download bytes -> temp file -> in-app PDF viewer + share sheet. No JSON variant for admin stats except per-center /centers/{id}/stats. mPDF uses Western digits._ | Four PDF report cards each with month/year pickers (current month default, last 3 years): comprehensive center report (center picker incl. 'all'), teachers performance, at-risk students (<70% attendance or repeated fails), system overview. | `GET /centers?all=1`<br>`GET /reports/admin/center/{id\|all}/pdf?month=&year=`<br>`GET /reports/admin/teachers/pdf?month=&year=`<br>`GET /reports/admin/at-risk/pdf?month=&year=`<br>`GET /reports/admin/overview/pdf?month=&year=` | SHOULD | M |
| `admin/profile.html` | **Profile (admin variant of shared Profile)**<br>_/profile is under the teacher gate; admin passes it (role admin + ability *). Phone edit is hidden for admin on web._ | Read-only name/email/role + change-password form (current_password, password, password_confirmation). Success revokes ALL tokens -> force logout to login screen. | `GET /profile`<br>`POST /profile/password` | SHOULD | S |

### Center manager (مدير المركز) — 3 must · 9 should

| Web page | Mobile screen | Purpose | Endpoints | Priority | Size |
|---|---|---|---|---|---|
| `manager/dashboard.html` | **Manager Home**<br>_pending_requests tile should deep-link to Requests screen (pending tab). center_name also present in login payload for the role label 'مدير مركز X'._ | Center header (name, city, 'scope limited to this center'), red alert when students_without_teacher > 0, 6 stat tiles (total_students, students_without_teacher, total_teachers, today_present, today_absent, pending_requests), quick links (teachers, import attendance, requests). | `GET /manager/dashboard` | MUST | S |
| `manager/teachers.html` | **My Teachers List**<br>_Toggle of the sole primary teacher returns 422 with errors.is_active[0] — surface that message. No delete anywhere._ | Server-paginated own-center teachers: live q, status filter (all default/active/inactive), 'load more'; rows: name (tap -> performance detail), email, code, type, students_count, status; actions edit, toggle status. | `GET /manager/teachers?page=&q=&status=`<br>`PUT /manager/teachers/{id}/status` | SHOULD | M |
| `manager/teachers.html` | **Add Teacher (manager)**<br>_center_id and role are forced server-side; never send them. Server re-checks primary rule under lockForUpdate._ | Form with read-only predicted code (next-code preview) and read-only center name; name, email_prefix (latin, auto-strip '@..'), type (primary option disabled when center already has_primary, hint names primary_teacher), phone, password + confirmation. Success dialog shows final display_code, numeric part, login email, and warns if code differs from preview. | `GET /manager/teachers/next-code`<br>`GET /manager/center`<br>`POST /manager/teachers` | SHOULD | M |
| `manager/teachers.html` | **Edit Teacher (manager)**<br>_Out-of-center id -> 403._ | Form: name, email, phone, type, optional new password + confirmation. | `PUT /manager/teachers/{id}` | SHOULD | S |
| `manager/teacher.html` | **Teacher Performance Detail**<br>_Single call. Nice-to-have for MVP; the Reports screen covers similar data._ | Header (name, code, type badge, active badge, email, phone, center_name, created_at); 6 stats (students_active + inactive sub, attendance_percent month present/total, performance_score = pass% + half attendance%, tests_month pass_percent passed/total, avg_completed_juz, khatmat); students table (code, name, guardian_name, age, attendance_percent colored >=70, tests_passed/tests_total, completed_juz or 'ختمة كاملة' + last_surah). | `GET /manager/teachers/{id}/performance` | COULD | M |
| `manager/students.html` | **My Students List**<br>_Preloads active teacher list (all=1 returns array, not paginated) for the change-teacher sheet._ | Server-paginated own-center students: live q (name/national id/phone), status filter active/inactive/all, 'load more'; rows: name+guardian_name, code, national_id or warning, teacher / former teacher / none (red), age, status; actions: change teacher, toggle status. | `GET /manager/students?page=&q=&status=`<br>`GET /manager/teachers?all=1`<br>`PUT /manager/students/{id}/status` | SHOULD | M |
| `manager/students.html` | **Change Student Teacher (sheet)**<br>_Helps clear the 'students without teacher' alert on the dashboard. Body: {teacher_id: int\|null}._ | Select new teacher from active own-center teachers or 'بدون محفّظ' (teacher_id null unassigns); shows current teacher / former_teacher_name. | `PUT /manager/students/{id}/teacher` | SHOULD | S |
| `manager/students.html` | **Add Student Wizard (manager)**<br>_Existing guardian linked by parent_id_number (not id). center_id forced server-side. Search only starts at >=3 digits (numeric keyboard)._ | Predicted code preview; name; nationality_type (+nationality_name when foreigner; national_id only for Libyan); teacher_id (active own-center teachers or none); age; phone. Guardian modes: existing (search by national id digits via manager parents search -> returns name, id_number, children_count only) / new (guardian_id_number, guardian_name, guardian_phone, guardian_password) / none. Success dialog: display_code, device number, parent login email, preview-mismatch warning. | `GET /manager/students/next-code`<br>`GET /manager/parents/search?q=`<br>`POST /manager/students` | SHOULD | L |
| `manager/parents.html` | **Parents Directory (read-only)**<br>_View only. Could be merged as a tab inside My Students._ | Server-paginated guardians of own center via active children: name, code, phone, id_number or 'missing', children chips (code + name), status; live q + status filter. | `GET /manager/parents?page=&q=&status=` | COULD | S |
| `manager/attendance.html` | **Import Fingerprint Attendance**<br>_Mobile: file_picker with xlsx MIME; files often arrive via WhatsApp/USB — support 'open with' intent if possible. Drag-drop irrelevant. Needs zip PHP extension server-side. Import Summary sheet is shared with teacher import._ | Pick .xlsx (<=5MB, columns: student number, name, date, time, status), upload multipart field attendance_file, then show Import Summary sheet: present/late/absent counts, imported (new/updated), ignored_other_teachers info, name_warnings table (row, S{number}, file_name vs system_name), rejected errors table (row, number, name, reason). Guide text explains device-number = digits of S-code, absence auto-counted, no duplicates. | `POST /manager/attendance/import` | SHOULD | M |
| `manager/attendance-review.html` | **Attendance Review**<br>_Core daily review job. Out-of-center record -> 403. Collapse filters into a filter sheet on mobile; default to today's date._ | Filter bar (from/to date -> from&to, single date -> date, teacher_id, status present/absent/late, live q by name/code/national id, clear) + server-paginated records: code, student_name, teacher_name, date, status badge, source badge (fingerprint/manual) with corrected marker; tap -> Correct Status sheet (select new status -> confirm dialog 'from X to Y' -> PUT). | `GET /manager/attendance?page=&from=&to=\|date=&teacher_id=&status=&q=`<br>`GET /manager/teachers?all=1`<br>`PUT /manager/attendance/{id}/status` | MUST | M |
| `manager/requests.html` | **Requests (Approvals)**<br>_Unpaginated list. Approve of type=add is direct; approve of transfer opens a sheet to optionally pick target_teacher_id from active own teachers (else student lands with teacher_id null). Reject sheet has optional admin_note textarea. Notification link manager/requests.html maps here._ | Tabs pending (default) / all; rows: kind badge (legacy add / incoming transfer / outgoing transfer via direction), student_name + national_id, requested_by, route from_center/from_teacher -> target_center/target_teacher, created_at, status badge or actions. Incoming pending: Approve / Reject. Outgoing pending shows 'awaiting manager of X'; rejected shows admin_note. | `GET /manager/student-requests?status=pending\|all`<br>`POST /manager/student-requests/{id}/approve`<br>`POST /manager/student-requests/{id}/reject` | MUST | M |
| `manager/requests.html` | **New Transfer Request (sheet)**<br>_students?all=1 can be large — prefer a searchable picker using /manager/students?q= instead of loading all. Server refuses if target has no active manager._ | Pick one active student of own center (label code — name (teacher)) and a target active center (name — city) from other centers; submit creates transfer received by target center's manager. | `GET /manager/students?status=active&all=1`<br>`GET /manager/centers`<br>`POST /manager/student-requests` | SHOULD | S |
| `manager/reports.html` | **Center Report**<br>_Web reads only .atRisk from /manager/reports/system. Stat-tile grid + list; PDFs via shared viewer._ | Center-scoped summary: students_count, teachers_count, attendance (percent, present/total, absent, late, absent_percent), tests (passed/total, failed, pass_percent), memorization (avg_completed_juz, records, khatmat, quality counts excellent/good/average/weak) + current-month at-risk table (code, name, teacher, attendancePercent, fails, reason; thresholds attThreshold/failThreshold) + PDF buttons (center, at-risk, teachers). | `GET /manager/reports/center`<br>`GET /manager/reports/system?month=&year=`<br>`GET /manager/reports/center/pdf?month=&year=`<br>`GET /manager/reports/at-risk/pdf?month=&year=`<br>`GET /manager/reports/teachers/pdf?month=&year=` | SHOULD | M |
| `manager/reports.html` | **Teacher Report (manager)**<br>_Overlaps with Teacher Performance Detail; pick one for MVP (this one has PDF-consistent numbers)._ | Pick an active teacher -> header (name, code, type, inactive badge), stats (students_count + shared attendance/tests/memorization block, quality chips), students table (code, name, attendance_percent colored, absent, tests passed/total (pass%), completed_juz / reached_juz / ختمة). | `GET /manager/teachers?all=1&status=active`<br>`GET /manager/reports/teacher/{id}` | COULD | M |
| `manager/reports.html` | **Student Report (manager)**<br>_Out-of-center student -> 403. Could also be reached from My Students row tap (student detail for manager)._ | Live student search (debounced q -> name, code, teacher, national_id) -> full profile: age, national_id, nationality, phone, enrollment_date, center_name, teacher (or former), parent name/phone; progress stats (completed_juz/30 with completion_percent and completed_down_to, reached_juz + done flag + last_surah, khatmat/completed_quran), attendance & tests stats, quality chips, recent_tests (exam_date, result, notes) and recent_memorizations (date, surah, juz, quality). | `GET /manager/students?q=`<br>`GET /manager/reports/student/{id}` | COULD | M |

### Teacher (المحفّظ) — 9 must · 1 should

| Web page | Mobile screen | Purpose | Endpoints | Priority | Size |
|---|---|---|---|---|---|
| `teacher/dashboard.html` | **Teacher Home**<br>_Week = Saturday..Friday (server). Make the alert the primary action of the day._ | Alert 'attendance not recorded today' (attendanceToday false && students>0) with CTA; 5 stats (total_students, today_present, today_absent, today_late, this_week_memorizations); first 8 students (name, age); recent memorizations (student, surah, quality badge) with '+ record' CTA. | `GET /dashboard` | MUST | S |
| `teacher/students.html` | **My Students List**<br>_Own-students scope is server-enforced. On mobile drop the modal and always navigate to Student Detail (which uses /students/{id}/details); keep /students/{id} unused. Tap phone -> dial guardian._ | All own students (name, guardian_name, code, guardian_phone, age, center, status) with client-side normalized search; tap -> Student Detail. Web additionally opens a modal via GET /students/{id} (student, memorizations, attendances, weeklyTests with questions). | `GET /students?all=1`<br>`GET /students/{id}` | MUST | S |
| `teacher/student.html` | **Student Detail (teacher)**<br>_Not-own student -> 403. Mobile: tabs Overview / Day / Memorization / Attendance / Tests; default day = today (local Africa/Tripoli, never UTC ISO). Quick actions here: record memorization, new test, message parent._ | Header (name, code, active/موقوف, age, phone, national_id, nationality, center_name, enrollment_date, former_teacher_name); 7 stats (attendance percent present/total, absent, late, tests passed/total failed pass_percent, completed_juz/30 completion_percent, reached_juz done/in-progress + last_surah, completed_quran); quality chips; 'what happened on day…' date picker -> attendance (status, time, corrected, notes), memorizations (surah, juz, pages, eighth, quality, notes), tests (result, thumn list with pass/fail dots + mistake); recent memorizations / attendances (with corrected badge) / tests tables. | `GET /students/{id}/details`<br>`GET /students/{id}/day?date=` | MUST | M |
| `teacher/attendance.html` | **Take Attendance**<br>_Highest-frequency teacher task — optimize for one-hand use, sticky save button. Offline: keep a local draft of selections; do not queue posts blindly because of the 409 conflict flow. Import shares the Import Summary sheet (teacher variant reports ignored_other_teachers)._ | Date picker (default today local); list of active own students each with segmented control present/absent/late (default present, prefilled from existing); 'all present' / 'all absent'; Save posts {date, attendance:{studentId:status}, confirm:false}; on 409 with data.conflicts show confirm listing name: current -> new, then resend with confirm:true. Import xlsx button. | `GET /attendance?date=`<br>`POST /attendance`<br>`POST /attendance/import` | MUST | M |
| `teacher/memorization.html` | **Memorization Log**<br>_Server paginates 15/page but web only renders page 1 — mobile must add infinite scroll. Also unused GET /memorizations/students-progress and ?juz=N filter could power a per-student juz progress board (could)._ | Paginated list of memorization records (student, date, surah, page_from–page_to, quality badge) with server search q (student name, juz number, juz name like عمّ/تبارك); swipe/long-press delete with confirm. | `GET /memorizations?q=&page=`<br>`DELETE /memorizations/{id}` | MUST | M |
| `teacher/memorization.html` | **Record Memorization (form)**<br>_Preselect student when opened from Student Detail. Notifies parent (memorization_added). Consider auto-filling juz from surah client-side using a bundled surah->juz table (mirror of SurahReference)._ | Form: student_id (own students), date (default today), surah_name (114 list from /memorizations/surahs; reverse-order convention starting juz 30), quality (excellent/good/average/weak), juz 1-30 optional, page_from/page_to optional, notes. Server 422: pages 1-604, page_to >= page_from, juz within surah range. | `GET /students?all=1`<br>`GET /memorizations/surahs`<br>`POST /memorizations` | MUST | S |
| `teacher/weekly-tests.html` | **Weekly Tests List**<br>_Server paginates 15/page; web shows page 1 only — add infinite scroll. No delete (405 by design)._ | Paginated tests (student, exam_date, thumn list with green/red dots per eighth_start, overall result ناجح/راسب) with client search by student; tap -> edit. | `GET /weekly-tests?page=`<br>`GET /students?all=1` | MUST | S |
| `teacher/weekly-tests.html` | **Test Form (create/edit) with Athman picker**<br>_Athman picker is best as a full-screen search sheet (Amiri font for Quran text). Notifies parent (test_added). Overall result computed server-side._ | Create: student_id, exam_date (default today); Edit: student fixed, exam_date editable, existing questions preloaded. Dynamic rows: eighth_start text with athman autocomplete (>=2 chars, debounced GET /athman/search -> start_text, surah_name, hizb, thumn_in_hizb, page; free text allowed), result ناجح/راسب, mistake textarea shown when failed; remove row; >=1 row required. Submit POST or PUT {exam_date, questions[]}. | `GET /athman/search?q=`<br>`POST /weekly-tests`<br>`PUT /weekly-tests/{id}` | MUST | L |
| `teacher/messages.html` | **Messages (teacher variant of shared Messages)**<br>_Admin is refused inside controller (teacher only). Deep-link from notification link teacher/messages.html?student={id}. No real-time: refresh on open/resume; poll while thread is open._ | Threads = own students with a linked parent (student_name, other_name = parent, last_body, unread); thread view bubbles (mine/theirs, created_at, read_at check) + compose (max 2000 chars). 422 when student has no parent. | `GET /teacher/messages`<br>`GET /teacher/messages/{student}`<br>`POST /teacher/messages/{student}` | MUST | M |
| `teacher/profile.html` | **Profile (teacher variant of shared Profile)**<br>_Token-owner only, no id parameter._ | Read-only: name, display_code, type, center_name, students_count, email. Editable: phone (PUT returns normalized 09xxxxxxxx) and password (current, new, confirmation) -> all tokens revoked -> logout. | `GET /profile`<br>`PUT /profile/phone`<br>`POST /profile/password` | SHOULD | S |
| `teacher/reports.html` | **Teacher Reports (PDF)**<br>_Student PDF enforces own-student. JSON variants GET /reports/student/{id} and GET /reports/weekly exist but are unused by web — a native (non-PDF) report screen could use them later._ | Two cards: single-student monthly PDF (student picker + month/year) and group PDF of all own students (month/year). | `GET /students?all=1`<br>`GET /reports/student/{id}/pdf?month=&year=`<br>`GET /reports/teacher/pdf?month=&year=` | COULD | M |

### Parent (ولي الأمر) — 3 must · 0 should

| Web page | Mobile screen | Purpose | Endpoints | Priority | Size |
|---|---|---|---|---|---|
| `parent/dashboard.html` | **My Children**<br>_Single call; parent may have several children (siblings share one account, de-duplicated by phone/id_number). Inactive children still visible._ | Card per child: name, age, center, teacher_name, last_surah, attendance_percent (color >=75 green, >=50 gold, else red), count of children; empty state 'no children linked, contact center'. Tap -> Child Detail. | `GET /parent/children` | MUST | S |
| `parent/child.html` | **Child Detail**<br>_One endpoint, three page params — every 'more' refetches the whole payload; on mobile use three tabs with infinite scroll, only bumping the relevant page param. Not-own child -> 403._ | Header (name, موقوف badge if inactive, age, center, center_city, teacher_name, guardian_phone, enrollment_date); attendance_summary 5 stats (total, present, absent, late, percent); tests_summary (total, last_result); three independently paginated logs (5/page): memorizations (date, surah, pages, quality), attendances (date, day name, status_raw), weekly_tests (date, result, questions_count with passed_count/failed_count, notes), each with 'show more'. | `GET /parent/students/{id}?memo_page=&att_page=&tests_page=` | MUST | M |
| `parent/messages.html` | **Messages (parent variant of shared Messages)**<br>_Deep-link from notification parent/messages.html?student={id}. Parent also receives memorization_added / test_added notifications -> link to child detail._ | Threads = children with a current teacher (other_name = teacher); same thread/compose UI; 422 when child currently has no teacher. | `GET /parent/messages`<br>`GET /parent/messages/{student}`<br>`POST /parent/messages/{student}` | MUST | M |

### Shared screens (every role)

| Web page | Mobile screen | Purpose | Endpoints | Priority | Size |
|---|---|---|---|---|---|
| `—` | **Splash / Session Bootstrap** | Read token + cached user from secure storage; if present validate via GET /auth/user (refreshes name/role/center_name/type); on 401 clear and go to Login; else route by role to the role shell. Replaces Auth.redirectIfLoggedIn/requireAuth. | `GET /auth/user` | MUST | S |
| `—` | **Login** | Single field 'email or display code' (T1 / CA1 / P1, whitespace stripped; server matches UPPER(display_code) then email) + password with show/hide; 422 field errors under inputs, 403 messages for deactivated user / deactivated center, throttle 10/min; on success store token (Sanctum abilities by role, 7-day expiry) and route by role. Web also shows a demo-accounts panel from /public/demo-accounts. | `POST /auth/login`<br>`GET /public/demo-accounts` | MUST | S |
| `—` | **Forgot Password (phone OTP)** | Step 1 phone -> request (throttle 5/min, always neutral message); step 2 OTP 6 digits (numeric keyboard, 10-min expiry, 5 attempts) + new password (>=6) -> verify; 'change number / resend' returns to step 1; in local env response carries dev_otp/dev_note to display. | `POST /auth/forgot-password/request`<br>`POST /auth/forgot-password/verify` | SHOULD | S |
| `—` | **Role Shell (bottom nav + drawer + top bar)** | Per-role scaffold mirroring layout.js NAV: bottom bar = first 4 destinations of the role + 'More' drawer with the full list and Logout (confirm). Top bar: page title, date (ar-LY), notifications bell with unread badge. Role label: 'مدير مركز {center_name}' for managers. Global 401 interceptor clears session and returns to Login. | `POST /auth/logout` | MUST | M |
| `—` | **Notifications** | List of latest 30 (title, body, created_ago, is_read highlight) + unread badge count; tap marks read (returns unread_count) and deep-links by the stored link: manager/requests.html -> Requests, teacher/students.html -> My Students, teacher\|parent/messages.html?student=ID -> thread, admin/managers.html -> Managers, plus memorization_added/test_added -> Child Detail; 'mark all read'. Web polls every 60s; mobile: refresh on app resume + pull-to-refresh, optional 60s timer while foregrounded. | `GET /notifications`<br>`POST /notifications/{id}/read`<br>`POST /notifications/read-all` | MUST | M |
| `—` | **Messages (threads + thread + compose)** | One widget parameterized by base path (/teacher/messages or /parent/messages) and counterpart label; threads list with unread badge and last_body; chat bubbles with read receipts; compose textarea max 2000; refresh threads after opening a thread; accepts ?student= deep link. | `GET {base}`<br>`GET {base}/{student}`<br>`POST {base}/{student}` | MUST | M |
| `—` | **Profile & Change Password** | Shared screen with role variants: teacher (view + phone edit + password), admin (view + password only). Password change revokes all tokens -> show toast then force logout. NOTE: no /profile route exists for center_manager or parent (teacher-gated), so hide the screen for them and offer only Forgot-Password OTP or 'contact admin'. | `GET /profile`<br>`PUT /profile/phone`<br>`POST /profile/password` | SHOULD | S |
| `—` | **PDF Report Viewer** | Generic screen used by all PDF buttons: GET with Authorization Bearer + Accept application/pdf, handle 401 (logout) and JSON error envelope on !ok, write bytes to temp file, render in-app (pdfx/flutter_pdfview) with share/print action. Replaces UI.openPdf (blob + window.open). | `GET /reports/**/pdf (role-specific)` | SHOULD | M |
| `—` | **Attendance Import (file picker + Import Summary sheet)** | Shared flow for teacher (/attendance/import) and manager (/manager/attendance/import): pick .xlsx (<=5MB), multipart field attendance_file, progress toast, then summary sheet: present/late/absent tiles, imported (imported_new new / updated), ignored_other_teachers banner, name_warnings table (row, S{number}, file_name, system_name), errors table (row, number, name, reason). Replaces UI.runAttendanceImport + importSummaryModal. | `POST /attendance/import`<br>`POST /manager/attendance/import` | SHOULD | M |
| `—` | **Athman Picker (search sheet)** | Shared component for weekly-test rows: debounced (300ms, >=2 chars) search against the thumn index returning start_text, surah_name, hizb, thumn_in_hizb, page; select fills the field and shows meta; free text remains allowed. Replaces UI.attachAthmanSearch. /athman/hizb/{n} and /athman/{id} exist for a browse-by-hizb mode (could). | `GET /athman/search?q=`<br>`GET /athman/hizb/{n}`<br>`GET /athman/{id}` | MUST | S |
| `—` | **Confirm Dialog (branded)** | Arabic-labelled confirm with rich body used for every status toggle (teacher/center/student/manager), attendance correction, 409 attendance conflicts, and memorization delete — replaces window.confirm/UI.confirmAction with consistent okText/cancelText. |  | MUST | S |
| `—` | **Settings / About** | Logout, app version, environment/API base URL switch (dev vs production /backend/public/api), links to landing content (about, features, contact from index.html), reduced-motion respect; optional public stats teaser. | `GET /public/stats`<br>`POST /auth/logout` | COULD | S |

## Packages

| Package | Purpose |
|---|---|
| `flutter_riverpod ^3 / riverpod_annotation ^3 / riverpod_generator ^3 / riverpod_lint ^3 + custom_lint` | State management and implicit DI: AsyncNotifier for lists/details, Notifier for auth and forms, families for per-student threads; lint rules catch misuse |
| `go_router ^16 (or latest stable)` | Declarative routing with auth/role redirect, StatefulShellRoute per role for bottom nav, deep links from notification/push payloads |
| `dio ^5` | HTTP client with interceptors (bearer, 401 handling, logging), multipart for xlsx upload, bytes responseType for PDFs, timeouts |
| `freezed ^3 + freezed_annotation ^3 + json_serializable ^6 + json_annotation ^4 + build_runner ^2` | Immutable DTOs with fromJson/toJson, copyWith, ==; sealed unions for Failure and AuthState |
| `flutter_localizations (SDK) + intl (version pinned by SDK, ~0.20) + flutter gen-l10n` | Arabic-first ARB strings, plurals, DateFormat for weekday names; RTL comes from locale ar |
| `flutter_secure_storage ^9 (or latest stable 10.x)` | Keychain/Keystore storage of the Sanctum token and cached user JSON |
| `shared_preferences ^2` | Non-sensitive prefs (last tab, collapsed sections) and read-cache index |
| `infinite_scroll_pagination ^5 (or latest stable)` | Infinite scroll over Laravel paginators for students, memorizations, attendance review, messages |
| `connectivity_plus ^6` | Offline detection for the stale-data banner and disabling mutations offline |
| `printing ^5 + path_provider ^2 + share_plus (latest stable, ^11)` | Save bearer-fetched PDF bytes to a temp file, preview in-app, print, share; share sheet for PDFs |
| `file_picker (latest stable, ^10)` | Pick .xlsx fingerprint-device exports for attendance import (teacher + manager) |
| `flutter_typeahead ^5 (or hand-rolled RawAutocomplete)` | Athman autocomplete for weekly-test thumn rows (debounced GET /athman/search) |
| `firebase_core / firebase_messaging / firebase_crashlytics / firebase_analytics (latest stable, pinned together via FlutterFire CLI)` | Push readiness (FCM token, foreground/background handling), crash reporting, screen analytics — all behind service interfaces, no-op in dev |
| `flutter_local_notifications (latest stable, ^19)` | Display foreground push and later local reminders; tap routing |
| `url_launcher ^6` | Open privacy policy, tel: for guardian phone, mailto |
| `package_info_plus ^8 + device_info_plus (latest stable)` | Version display, X-App-Version header, crash context |
| `talker_flutter + talker_dio_logger (or logger ^2)` | Structured logs and in-app log viewer in dev flavor |
| `flutter_native_splash ^2 + flutter_launcher_icons ^0.14` | Generated splash and adaptive icons from the star logo for Android/iOS |
| `mocktail ^1 + http_mock_adapter (latest stable)` | Mock repositories/notifiers and dio responses in unit/widget tests |
| `alchemist (latest stable)` | Golden tests for RTL screens across device sizes (golden_toolkit is discontinued) |
| `integration_test (SDK) + optional patrol` | End-to-end login → attendance flow on emulator/device in CI |
| `flutter_lints ^5 (very_good_analysis later)` | Baseline static analysis |
| `drift ^2 + sqlite3_flutter_libs (Phase 2 only)` | Offline attendance/memorization queue with replay and conflict handling once designed with the backend |
| `timezone (optional, latest stable)` | Compute 'today' in Africa/Tripoli if device-local dates ever diverge from server-side attendance defaults |

## Folder structure

```text
mutqen_app/
├── pubspec.yaml
├── analysis_options.yaml              # flutter_lints + riverpod_lint (custom_lint)
├── l10n.yaml                          # arb-dir lib/l10n, template app_ar.arb, nullable-getter false
├── env/
│   ├── dev.json                       # {"API_BASE_URL":"http://10.0.2.2:9090/api","FLAVOR":"dev"}  (LAN IP for real devices)
│   ├── staging.json
│   └── prod.json                      # https only — run/build with --dart-define-from-file=env/prod.json
├── assets/
│   ├── fonts/                         # Amiri-Regular/Bold.ttf, Cairo-Regular…ExtraBold.ttf, OFL.txt (SIL OFL 1.1)
│   ├── images/                        # logo.png (1024), splash.png
│   └── data/surahs.json               # 114 surahs → juz (mirror of app/Support/SurahReference.php) for offline pickers
├── lib/
│   ├── main.dart                      # bootstrap: env, Firebase (guarded by flavor), runApp(ProviderScope(App()))
│   ├── app.dart                       # MaterialApp.router, theme, locale ar, supportedLocales, builder (text scale clamp)
│   ├── l10n/
│   │   └── app_ar.arb                 # all user-facing strings (Arabic template; en added later)
│   ├── core/
│   │   ├── config/                    # app_env.dart (String.fromEnvironment), flavor.dart
│   │   ├── network/
│   │   │   ├── api_client.dart        # dio wrapper: request<T>(method, path, {body, query, parser, formData, bytes})
│   │   │   ├── envelope.dart          # Envelope<T>{success,message,data,errors}
│   │   │   ├── paginated.dart         # Paginated<T>{data,currentPage,lastPage,total} + parseListOrPaginated
│   │   │   ├── failures.dart          # sealed Failure: Network, Unauthorized, Forbidden, Validation(errors), Conflict(data), Server, Unknown
│   │   │   └── interceptors/          # auth_interceptor.dart (bearer), unauthorized_interceptor.dart (401→signOut), log_interceptor.dart (dev only)
│   │   ├── storage/                   # secure_session.dart (token/user), prefs.dart, read_cache.dart (last-good JSON per key)
│   │   ├── router/
│   │   │   ├── app_router.dart        # GoRouter + redirect(auth, role prefix), refreshListenable from AuthNotifier
│   │   │   ├── routes.dart            # typed route names/paths per role
│   │   │   ├── role_shell.dart        # StatefulShellRoute.indexedStack: bottom nav (first 4) + 'more' drawer + logout
│   │   │   └── notification_link_mapper.dart  # 'manager/requests.html' → '/manager/requests' etc.
│   │   ├── theme/
│   │   │   ├── tokens.dart            # brand colors from css/theme.css
│   │   │   ├── app_theme.dart         # ThemeData (ColorScheme from tokens, component themes)
│   │   │   ├── typography.dart        # Amiri display / Cairo body text theme
│   │   │   └── mutqin_theme_extension.dart  # gold, ivory, paper, badge colors
│   │   ├── utils/                     # dates.dart (Y-m-d, Sat→Fri week, todayStr), digits.dart (western), arabic_text.dart (normalize for local filtering), phone.dart, debouncer.dart
│   │   └── widgets/                   # MqScaffold, MqCard, StatTile, PagedListView, SearchField, EmptyState, ErrorView, badges/, LtrText, ConfirmDialog, FormSheet, OfflineBanner
│   ├── features/
│   │   ├── auth/
│   │   │   ├── data/                  # auth_api.dart, auth_repository.dart, models/user.dart (freezed), models/login_response.dart
│   │   │   ├── application/           # auth_notifier.dart (AsyncNotifier<AuthState>: unknown/signedOut/signedIn), session_bootstrap
│   │   │   └── presentation/          # login_screen.dart, forgot_password_screen.dart, demo_accounts_panel.dart (dev flavor only)
│   │   ├── dashboard/                 # data/dashboard_repository.dart, application/dashboard_provider.dart, presentation/{teacher,parent,manager,admin}_dashboard.dart
│   │   ├── notifications/             # models/app_notification.dart, notifications_repository.dart, notifications_notifier.dart (polling), bell_button.dart, notifications_sheet.dart
│   │   ├── students/                  # models/student.dart, students_repository.dart (teacher/manager/admin endpoints), list/detail screens, guardian_picker.dart, student_form_sheet.dart
│   │   ├── attendance/                # models/{attendance_day,conflict}.dart, attendance_repository.dart, teacher_attendance_screen.dart (409 flow), attendance_report_screen.dart, import_xlsx_button.dart, manager_review_screen.dart
│   │   ├── memorization/              # models/memorization.dart, memorization_repository.dart, surah_picker.dart, memorization_form_sheet.dart, memorization_list_screen.dart, students_progress_screen.dart
│   │   ├── weekly_tests/              # models/{weekly_test,test_question}.dart, weekly_tests_repository.dart, athman/{athman_repository.dart, athman_autocomplete.dart}, test_editor_sheet.dart, weekly_tests_screen.dart
│   │   ├── messages/                  # models/{thread,message}.dart, messages_repository.dart (parent/teacher prefixes), threads_screen.dart, thread_screen.dart
│   │   ├── parent/                    # children_screen.dart, child_details_screen.dart (3 independent pagers), child_details_provider.dart
│   │   ├── manager/                   # center/, teachers/ (list, form, performance), requests/ (list, approve_sheet, reject_sheet, transfer_sheet), parents/
│   │   ├── admin/                     # centers/, teachers/, managers/, users/, students/ (lists + status toggles; forms in Phase 1.5)
│   │   ├── reports/                   # pdf_service.dart (bytes → temp file → printing/share), reports_repository.dart, {teacher,manager,admin}_reports_screen.dart
│   │   └── profile/                   # profile_repository.dart, profile_screen.dart (phone, password → forced sign-out)
│   ├── services/                      # push_service.dart (FCM + local notifications, no-op backend adapter), analytics_service.dart, crash_service.dart, connectivity_service.dart, file_service.dart (file_picker, temp files)
│   └── sync/                          # Phase 2: sync_queue.dart interface (MVP no-op), later drift schema + replay
├── test/
│   ├── fixtures/                      # recorded JSON per endpoint (contract fixtures)
│   ├── core/                          # envelope_test, failures_test, paginated_test, interceptors_test (http_mock_adapter), dates_test, digits_test
│   ├── features/<feature>/            # *_repository_test (mocked ApiClient), *_notifier_test (ProviderContainer overrides), *_screen_test (widget)
│   └── goldens/                       # alchemist RTL goldens: login, attendance, child_details, requests, memorization (2 sizes)
├── integration_test/
│   └── login_attendance_flow_test.dart
├── android/                           # flavors dev/prod (applicationIdSuffix .dev), network_security_config (dev cleartext), google-services per flavor, signing from CI secrets
├── ios/                               # schemes dev/prod, ATS exception dev only, APNs entitlement, GoogleService-Info per scheme
├── .github/workflows/ci.yml           # analyze → build_runner → test → debug APK (PR); tag → signed AAB
├── codemagic.yaml                     # iOS archive + TestFlight, Android AAB on tag
└── docs/                              # ARCHITECTURE.md, API_CONTRACT.md, RELEASE.md, PRIVACY_POLICY.md, CONVENTIONS.md (Riverpod 3 idioms)
```

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Apple Developer Program lead time (organisation enrolment needs D-U-N-S; 1–4 weeks) blocks TestFlight and APNs push | Enrol on Day 0; until approved, distribute iOS builds ad-hoc to registered UDIDs via Codemagic and use Play internal testing for Android; TestFlight external testing also needs a 1–3 day Apple review — submit the internal build first |
| Google Play: new personal developer accounts must run a closed test with 12 testers for 14 days before production access | Register the Play Console as an organisation account, or start the closed test on Day 10 with the center staff as testers so the production gate opens on schedule |
| Arabic font licensing / rendering: Amiri and Cairo bundled in the binary; runtime Google Fonts fetch is offline-hostile and a privacy question | Both are SIL OFL 1.1 — bundling is licensed; ship OFL.txt, register it in LicenseRegistry, keep original font names; test diacritics and Arabic ligatures on both platforms and set fontFamilyFallback to system Arabic fonts |
| Backend gaps landing late: device registration for push (POST /devices), token refresh before the 7-day Sanctum expiry, message unread counts, revisions/tajweed endpoints | Every external capability sits behind an interface with a no-op adapter and a feature flag; the app must be shippable with polling notifications, weekly re-login (friendly 'انتهت الجلسة' + prefilled email), and no revisions UI; negotiate endpoints in week 1 so they can land in the buffer |
| API contract changes with no OpenAPI spec (CLAUDE.md already drifted from routes/api.php) | Recorded JSON fixtures per endpoint in test/fixtures drive Dart model tests — a backend change breaks CI immediately; additive-only policy for the envelope; send X-App-Version so the backend can gate; consider a lightweight Laravel feature test that snapshots the same fixtures on the backend side |
| Single-threaded artisan serve dev API serialises concurrent requests → dashboards and dependent pickers feel slow, and Future.wait fan-outs time out on emulator | Run with PHP_CLI_SERVER_WORKERS=4 or an Apache/XAMPP vhost; keep dashboard on the aggregated /dashboard endpoint; use 10.0.2.2 on Android emulator, LAN IP on devices; generous dev timeouts; cleartext http only in the dev flavor (prod must be HTTPS — iOS ATS blocks http) |
| Token/session semantics: 7-day expiry, deactivation and password change revoke all tokens mid-session (401), a parent/manager-scoped token reaching another role's route yields 403 | Global 401 interceptor clears the session and the router redirects; route guards use the role from the stored user, and 403 surfaces the server's Arabic message rather than looping; bootstrap always validates with GET /auth/user before showing a shell |
| Offline expectations: teachers in centers with weak connectivity will try to mark attendance offline; the web has no offline mode either | MVP is explicit: read cache + offline banner + mutations disabled with a clear message; Phase 2 offline queue is designed with the backend around the 409/confirm rules and fingerprint import overlap, with idempotent replay keyed by (student_id,date) |
| RTL/number pitfalls: Arabic-Indic digits from ar NumberFormat, mixed-direction strings (T5, phones, national ids, emails), EdgeInsets.only(left) bugs | Western digits everywhere (match the PDF rule), LtrText islands for codes/phones/ids, EdgeInsetsDirectional/AlignmentDirectional lint rule, golden tests in RTL for the key screens |
| Codegen friction (build_runner) slows a 1–2 dev team and pollutes diffs | Single scripts (tool/gen.sh / gen:watch), generated files git-ignored and built in CI, CONVENTIONS.md with Riverpod 3 idioms so Claude and devs emit the same patterns; if it still hurts, drop riverpod_generator (keep freezed) — the architecture does not depend on it |
| Scope creep from the ~30 web pages: full admin CRUD and manager reports on mobile in 10 days | Priority order fixed in the sprint (teacher → parent → manager approvals → admin lite); admin create/edit forms explicitly deferred to the buffer/Phase 1.5; web stays the admin's primary surface |
| Store compliance: no privacy policy exists (both stores require it), Apple may ask for an account-deletion path, Play Data Safety form must list crash/analytics collection | Write and publish privacy.html on Day 9; document that accounts are provisioned by the center (no self-signup) and provide an in-app 'request account removal' contact; declare Crashlytics/Analytics in Data Safety; keep analytics free of PII (user id only) |
| Timezone/date drift: server 'today' is Africa/Tripoli while the device may be misconfigured | Dates are calendar strings (Y-m-d) never converted through UTC; default date is device-local today with a visible date picker; optional timezone package if drift is observed |
| PDF viewing: mPDF endpoints require the bearer header, so opening in a browser or a WebView by URL fails | Always fetch bytes through dio, write to a temp file, then preview/share/print with printing; show file size and a retry on failure |

<details><summary>Architect notes</summary>

Grounding: read backend/routes/api.php, frontend-html/js/{api,auth,layout,config}.js, teacher/attendance.html, teacher/memorization.html, teacher/weekly-tests.html, parent/child.html, manager/requests.html, plus AuthController (login/userPayload), AttendanceController (409 confirm), AttendanceImportController (attendance_file xlsx ≤5 MB), MemorizationController validation (surah in 114 list, pages 1–604, quality enum), NotificationController ({unread_count, items[{id,title,body,link,is_read,created_ago}]}), MessageController (threads/thread/send under /parent/messages and /teacher/messages), ReportPdfController (application/pdf bytes), config/sanctum.php (expiration 10080 min), css/theme.css tokens, manifest.webmanifest. Confirmed drift from CLAUDE.md: MessageController + messages pages, AdminUserController + admin/users.html, manager/parents.html, admin/profile.html, admin/center.html, manager/teacher.html, teacher/student.html, manager change-teacher and teacher-performance routes, weekly-tests has update (PUT) and no destroy, centers/{id}/{stats,teachers,students} exist, 38 feature-test files present, PWA manifest, _handoff2 and n8n folders exist. Data-shape facts the Flutter models must honour: login accepts email OR display code in the 'email' field; user payload {id,name,email,phone,role,center_id,center_name,type}; token abilities parent/manager/* with a role+ability dual check on the server; lists are Laravel paginators {data,current_page,last_page,total,per_page} unless ?all=1 returns a plain array (write one parser for both); GET /attendance?date= → {students[], attendances{studentId:status}}; POST /attendance {date, attendance{}, confirm} → 409 {data:{conflicts[{name,current_status,new_status}]}}; weekly test payload {student_id, exam_date, questions[{eighth_start, result 'ناجح'|'راسب', mistake}]} and athman items {id,start_text,surah_name,hizb,thumn_in_hizb,page}; parent child details returns student, attendance_summary{total,present,absent,late,percent}, tests_summary{total,last_result} and three paginators memorizations/attendances/weekly_tests (5 per page, independent memo_page/att_page/tests_page params); manager requests rows carry type add|transfer, direction incoming|outgoing, status pending|approved|rejected, admin_note, and approval accepts optional target_teacher_id; notification link values are web paths (manager/requests.html, teacher/students.html, admin/managers.html) — the app maps them to routes. Decisions embedded in the plan: single app with role shells (bottom nav = first 4 NAV items + 'more', exactly as layout.js); admin is mobile-lite in the MVP; Western digits everywhere; fonts bundled (OFL); flavors via --dart-define-from-file (no flavorizr needed until side-by-side installs are required); generated code not committed; Crashlytics chosen because Firebase is needed for FCM anyway (Sentry is an equal alternative); offline = read cache now, write queue in Phase 2. Versions: majors stated are those I am confident about as of mid-2026 (riverpod 3, go_router 16, dio 5, freezed 3, json_serializable 6, flutter_bloc 9, printing 5, file_picker 10, connectivity_plus 6, flutter_local_notifications 19); Firebase packages should be pinned together with the FlutterFire CLI rather than hand-picked. Enterprise evolution path after MVP: melos multi-package (core, design_system, feature packages), offline queue (drift), English locale, tablet layouts, feature flags via remote config, OpenAPI generation on the Laravel side, contract tests in both repos, coverage gates in CI.

</details>
