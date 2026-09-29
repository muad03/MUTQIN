# Messaging & Notifications

[← Enterprise Audit](../enterprise-audit.md)

**Score 57 / 100** — Significant risk · maturity **L2** · weight 2% · auditor scored 52, judge calibrated to 57

What exists is small, coherent and — at the authorization layer — genuinely well built: parent↔teacher messaging is server-side scoped per student with dual role+ability gating and 5 IDOR tests, notification ownership is enforced (404 on foreign ids) and tested, and request-event links never point at admin pages (asserted in tests). But measured against "public mobile app for four roles": there is zero push infrastructure (no FCM/APNs, no device-token table, no service worker), no working OTP delivery in production (SMS gateway is a TODO, dev_otp is local-only and Log::info is below the production LOG_LEVEL=error), a live correctness/privacy bug in thread rendering (`mine` is derived from sender_role, not sender_id, so a reassigned teacher inherits the predecessor's messages as their own and reads the parent's history to the former teacher), no pagination or unread-count endpoints, GET-with-side-effects read marking, no per-user rate limit or moderation, no channel at all for the center manager (the system's operational authority), a notification catalogue that omits attendance absence, and sendSafe failures that are silently dropped in production. The digest exists only as an external n8n email workflow that is not part of the deploy. That is "significant risk" territory (40–59), lifted toward the top of the band by the quality of the authorization work and the clean, extensible InAppNotification shape.

> **Calibration:** The critical (no push) was corrected to medium and three of four highs to medium/low, so the two drivers the rationale leaned on hardest (no push, no OTP delivery) were both judged overstated; the confirmed sender_role/'mine' privacy bug and no manager channel keep it inside the band but higher, consistent with i18n (56) at the same maturity.

## What is already strong

- Messaging authorization is enforced server-side per student and covered by tests: backend/app/Http/Controllers/Api/MessageController.php:26-49 `resolveStudent()` checks `(int) $student->parent_id !== (int) $user->id` (parent) and `!$user->isTeacher() || (int) $student->teacher_id !== (int) $user->id` (teacher) → 403; admin is explicitly not a party. backend/tests/Feature/MessagingTest.php has 5 tests incl. `test_parent_cannot_message_for_someone_elses_child`, `test_teacher_cannot_reply_for_student_not_his`, `test_role_gates_and_admin_are_not_conversation_parties`.
- Routes are gated by the dual role+token-ability aliases, not role alone: backend/routes/api.php:47-49 (`Route::middleware('parent')` → /parent/messages) and :147-149 (`Route::middleware('teacher')` → /teacher/messages); a parent token cannot reach /teacher/messages and vice-versa (MessagingTest.php:101-108).
- Notification ownership is scoped and tested: backend/app/Http/Controllers/Api/NotificationController.php:49 `$user->notifications()->whereKey($id)->first()` → 404 for foreign ids; backend/tests/Feature/OwnershipTest.php:65-75 `test_user_cannot_read_anothers_notification` asserts 404.
- Request-event links never target admin pages — verified in code and tests: backend/app/Http/Controllers/Api/StudentRequestController.php:418-421 `requesterLink()` returns 'manager/requests.html' or 'teacher/students.html'; :184 request_created → 'manager/requests.html'; tests assert `assertSame('teacher/students.html', $notif->data['link'])` (StudentRequestNotificationTest.php:49), `assertSame('manager/requests.html', ...)` (StudentTransferRequestTest.php:119) and `$admin->notifications()->count() === 0` in 5 places.
- Single generic notification class with a stable stored shape `{type,title,body,ref_id,link}` (backend/app/Notifications/InAppNotification.php:37-46) — new types need no new class; `sendSafe()` (:52-62) guards null/empty recipients and isolates failures from the primary write; notifications are emitted after `DB::transaction` commits (StudentRequestController.php:274-297).
- Thread list is N+1-free by design: MessageController.php:79-88 computes unread counts and last message with two grouped queries (`selectRaw('student_id, COUNT(*) AS n')->groupBy`, `MAX(id)` subquery) regardless of student count; the messages migration adds matching indexes `['student_id','id']` and `['student_id','sender_role','read_at']` (backend/database/migrations/2026_08_22_100000_create_messages_table.php:25-26).
- Input validation with Arabic messages and a length cap: MessageController.php:165-170 `'body' => 'required|string|max:2000'`; frontend mirrors with `maxlength="2000"` (frontend-html/teacher/messages.html:39) and escapes all rendered text via `UI.escapeHtml` (:56-58, :80).
- Bell UI has sensible polish: frontend-html/js/layout.js:196-198 badge caps at '99+'; :225 avoids re-rendering the list while the dropdown is open during the silent poll; :253 optimistic badge reset on read-all.
- n8n digest is timezone-correct and actionable-only: n8n/mutqin-daily-attendance-digest.json:287 `"timezone": "Africa/Tripoli"`, n8n/attendance-digest.code.js:53 `needsAttention: absent.length + missing.length > 0` gates the email; secrets shipped as placeholders (`"value": "PUT_PASSWORD_HERE"`, json:44).

## Level-5 target state

Level-5 messaging & notifications for MUTQEN is an event-driven, multi-channel system: every domain event (attendance recorded, memorization added, test added/updated, message sent, request created/approved/rejected, teacher changed, transfer, announcement) publishes a typed notification with a structured payload `{type, ref_type, ref_id, deep_link, params}` that is fanned out through in-app, FCM/APNs push, Web Push, SMS (OTP and critical only) and a scheduled per-center digest — respecting per-user, per-type preferences and quiet hours. Delivery is queued, retried and measured (sent/delivered/opened per channel, failure rate, p95 latency, unread ageing) with failures dead-lettered and alerted, never silently dropped. Messaging supports parent↔teacher, manager↔parent/teacher and center announcements with cursor pagination, explicit per-message read receipts keyed by sender_id, per-user rate limits, report/block and scoped manager oversight, and an explicit retention/archival policy — all covered by feature tests and documented in a single notification catalogue the mobile team can code against.

## What the Flutter team must know

The mobile team must plan for these facts: (1) There is NO push today — the app cannot receive anything in the background; the backend must ship `POST/DELETE /api/devices` + an FCM channel before launch, and the app must register its token after login and unregister on logout (server also revokes Sanctum tokens on password change/deactivation, so re-register after re-login). (2) Until push exists, the only signal is polling `GET /api/notifications` (returns 30 newest + `unread_count`, no paging) — poll only in foreground and only the cheap unread-count endpoint once added. (3) Notification `link` is a web path (`parent/child.html?id=5`); do not parse it — route on `type` + `ref_id`, knowing `ref_id` is the student id for `memorization_added`/`message_received` but the TEST id for `test_added` (ask for `ref_type`/`deep_link`). Current types: request_created, request_approved, request_rejected, manager_deactivated, memorization_added, test_added, message_received. (4) Messaging endpoints are role-prefixed: parent uses `/api/parent/messages[/{student}]`, teacher uses `/api/teacher/messages[/{student}]`; admin and manager get 403 (not parties). Threads are keyed by student id; `GET .../messages/{student}` returns max 100 messages ascending AND marks incoming messages read as a side effect — do not prefetch threads. (5) `mine` is currently computed from sender_role, not sender_id, and `sender_id` is absent from the payload — after a teacher reassignment a thread renders wrongly; request the fix before building chat UI. (6) Send validation: `body` required, max 2000 chars, 422 with Arabic `errors`; 422 with an Arabic message if the child has no teacher / student has no parent. (7) All notification text is pre-rendered Arabic (RTL) with server-side `created_ago`; render as-is, use `created_at` for local time formatting. (8) Forgot-password via OTP will not deliver anything in production until an SMS gateway is wired — do not ship the screen as functional until then. (9) Sanctum tokens expire after 7 days; a 401 means re-login (and re-register the device token).

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [No push channel (FCM/APNs) and no device-token storage — in-app DB channel only](#no-push-no-device-tokens) | 🔴 critical<br>_reviewers → medium_ | ✅ confirmed | NOW | L |
| [OTP password reset has no delivery path in production (SMS TODO; log line below LOG_LEVEL; dev_otp local-only)](#otp-no-production-delivery) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Message ownership derived from sender_role, not sender_id — reassigned teacher inherits predecessor's messages as 'mine' and reads the parent's history with the former teacher](#thread-mine-by-role-misattribution) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Notification payload carries HTML page paths as `link` and an inconsistent `ref_id` — no deep-link contract for mobile](#notification-payload-web-links-not-deep-links) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Notifications capped at 30 and threads at 100 with no cursor; no unread-count endpoints; thread list unsorted](#no-pagination-no-unread-aggregates) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Opening a thread (GET) bulk-marks all incoming messages as read — no explicit read endpoint, no per-message receipts](#read-marking-on-get-side-effect) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [60-second unconditional polling for the bell; open message threads never refresh; no visibility/ETag optimisation](#polling-only-no-realtime-no-backoff) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [No per-user rate limit on sending, no report/block, no manager oversight — spam amplifies into notifications](#no-anti-abuse-rate-limit-moderation) | 🟡 medium | ✅ confirmed | NEXT | M |
| [Communication matrix is parent↔teacher per student only — no manager↔parent/teacher channel and no center announcements](#no-manager-channel-no-broadcast) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | L |
| [Catalogue misses the highest-value parent events: absence/late, teacher change, transfer of own child, student deactivation, test update](#notification-catalogue-gaps) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [sendSafe swallows all Throwables and logs at warning — below production LOG_LEVEL=error, so notification loss is truly silent](#sendsafe-silent-loss-in-production) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [No tests for parent-facing notification types, message_received, GET /notifications or read-all; docs omit messaging entirely](#test-coverage-gaps-and-doc-drift) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Daily attendance digest lives only in an external n8n workflow (email to one address, admin password login, not deployed, app has no mailer/scheduler)](#digest-outside-app-n8n-email-only) | ⚪ low | ℹ️ informational | LATER | M |
| [No notification preferences (per-type opt-out, quiet hours) and notification text is pre-rendered Arabic stored in the DB](#no-preferences-no-structured-i18n) | ⚪ low | ℹ️ informational | LATER | M |
| [Notifications and messages grow unbounded — no pruning, archival or retention decision](#no-retention-policy) | ⚪ low | ℹ️ informational | LATER | S |

### No push channel (FCM/APNs) and no device-token storage — in-app DB channel only

<a id="no-push-no-device-tokens"></a>

`no-push-no-device-tokens` · 🔴 critical (reviewers → medium) · ✅ confirmed · **NOW** · effort L (1–2 weeks)

**Files:** `backend/app/Notifications/InAppNotification.php:31-34`, `backend/config/ (no broadcasting.php; grep for fcm|apns|firebase|device_token|push_token returns nothing)`, `backend/.env.production.example:72`, `frontend-html/manifest.webmanifest:1-22`, `frontend-html/js/config.js:37`

**Evidence**

```text
InAppNotification.php:31-34 `public function via(object $notifiable): array { return ['database']; }`. `grep -rn -iE "fcm|apns|firebase|device_token|push_token|onesignal|pusher|websocket|broadcast|serviceWorker|PushManager|webpush" backend/app backend/config backend/routes backend/database frontend-html` → no matches. No `devices`/`device_tokens` migration exists (git ls-files | grep -i device → none). .env.production.example:72 `BROADCAST_CONNECTION=log`. A PWA manifest is injected (config.js:37) but there is no service worker (`grep serviceWorker frontend-html` → 0), so even web push is impossible.
```

**Why it matters**

A mobile app cannot poll in the background (iOS forbids it, Android kills it); without push, parents will never learn about a new message, a memorization entry or a transfer approval until they open the app. Every notification type in the system is effectively 'pull only'. This is the single largest gap between the current state and a shippable Flutter client for all four roles.

**Recommendation**

Add `devices` table (user_id, platform, fcm_token unique, app_version, last_seen_at) + `POST /api/devices` (upsert on login/token refresh) and `DELETE /api/devices/{token}` (on logout; also purge in `recordPasswordChange()`/deactivation where tokens are revoked). Add an `fcm` channel to `InAppNotification::via()` (kreait/laravel-firebase or raw HTTP v1) with `toFcm()` sending `{type, ref_type, ref_id, deep_link, title, body}` as data + notification; keep `sendSafe` semantics but log at error level and record delivery failures. Dispatch via the database queue and run `php artisan queue:work --stop-when-empty` from cPanel cron every minute (no daemon on shared hosting), or fall back to sync with a 2s HTTP timeout.

<details><summary>Verification — 2 independent reviewers</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual evidence is confirmed: backend/app/Notifications/InAppNotification.php:31-34 returns only ['database']; backend/config/ has no broadcasting.php and grep for fcm/apns/firebase/device_token/push/webpush/serviceWorker across backend and frontend-html returns no source matches (only two binary JPEGs); no devices/device_tokens migration is tracked by git; .env.production.example:72 sets BROADCAST_CONNECTION=log; frontend-html/js/config.js:30 explicitly says "لا service worker" (no offline work by design) and layout.js:257 polls the bell every 60s with a comment "بلا real-time". So the system is in-app/pull-only — the finding is factually correct and there is no hidden mitigation. However, the severity is inflated. The product as documented (CLAUDE.md, README.md, DEPLOYMENT.md) is a static Bootstrap web client; no Flutter/mobile client or push requirement is stated anywhere in the repo (grep for flutter/mobile/push in the .md docs → nothing). The "impact" is entirely hypothetical about a future mobile app, not a defect in the shipped behaviour: in-app notifications are stored durably and shown on next login/poll, which is the deliberate design. A missing roadmap feature with no correctness, security, or data-loss consequence is not "critical"; it is at most a medium-priority gap to plan for if a mobile client is actually pursued (device table, FCM channel, queue worker). The recommendation itself is reasonable but should be scoped as a design decision, not an audit-blocking defect.

```text
backend/app/Notifications/InAppNotification.php:30-34 — via() returns ['database'] only (comment: "قناة قاعدة البيانات فقط"). backend/config/ contains no broadcasting.php (app, auth, cache, cors, database, filesystems, logging, mail, queue, sanctum, services, session only). backend/.env.production.example:72 BROADCAST_CONNECTION=log. frontend-html/js/config.js:30 comment "لا service worker (لا عمل دون اتصال بعدُ عمداً)" — deliberate. frontend-html/js/layout.js:257 setInterval(() => load(true), 60000) "بلا real-time". No repo doc (CLAUDE.md, README.md, DEPLOYMENT.md, دليل-محتوى-الصفحات.md) mentions a Flutter/mobile client or push as a requirement — the impact scenario is speculative.
```

---

**Upheld** · confidence 85% · corrected severity: medium

Factually the finding is accurate: InAppNotification::via() returns ['database'] only (backend/app/Notifications/InAppNotification.php:31-34); backend/config/ has no broadcasting.php; a repo-wide grep for fcm/apns/firebase/device_token/push/serviceWorker/PushManager/webpush hits nothing but two JPEGs; git ls-files has no devices/service-worker file; BROADCAST_CONNECTION=log in both env examples; config.js:27-30 injects a PWA manifest and its own comment says explicitly 'لا service worker (لا عمل دون اتصال بعدُ عمداً)' — the absence is deliberate. The only client-side delivery is layout.js:257 `setInterval(() => load(true), 60000)` polling while a page is open. No mitigation exists elsewhere (no n8n/Telegram/SMS/WhatsApp fan-out — the n8n workflow is only a daily attendance email digest to an admin, and DEPLOYMENT.md:12 records that even the OTP SMS gateway is not wired).

However the severity is materially overstated. 'Critical' is justified by the auditor only against a hypothetical 'shippable Flutter client for all four roles' that appears nowhere in the repo, README, DEPLOYMENT.md, DEPLOY_LOG.md, or 224 commits (no flutter/push/جوال mention). The shipped product is a web app / installable PWA used by staff (admin, managers, teachers) who are logged in while working, and parents who receive memorization_added/test_added — informational, non-time-critical notices that are fully visible on next login. Nothing in the domain depends on real-time delivery: transfer approvals, requests, and status changes are all visible via the bell (60s poll) and the relevant pages; no safety, financial, or SLA-bound flow is gated on a push. The system also states in .env.production.example:60-62 that it targets shared hosting with QUEUE_CONNECTION=sync and no worker, so the recommended FCM+queue architecture is a roadmap item, not a defect in the current product. The data-channel design (generic {type,title,body,ref_id,link} JSON, sendSafe wrapper) is also already extensible to add a push channel later without restructuring, which lowers remediation cost.

Correct classification: a real feature gap / roadmap item for engagement (especially for parents), not a critical enterprise defect. Medium at most; low if no mobile client is planned.

```text
backend/app/Notifications/InAppNotification.php:31-34 via() => ['database'] (confirmed). frontend-html/js/config.js:29 comment: "لا service worker (لا عمل دون اتصال بعدُ عمداً)" — no SW is an explicit design decision. frontend-html/js/layout.js:257 `setInterval(() => load(true), 60000); // تحديث دوري خفيف للشارة (بلا real-time)` — the only delivery mechanism is 60s polling while a page is open. backend/.env.production.example:60-62 QUEUE_CONNECTION=sync with comment that no queue worker exists on shared hosting (deployment target is shared hosting, not a mobile backend). No reference to Flutter/mobile app/push anywhere in README, DEPLOYMENT.md, backend/DEPLOY_LOG.md, n8n/, or git log (224 commits) — the 'shippable Flutter client' premise of the critical rating is not a stated product requirement. n8n/README.md: the only external channel is a daily attendance email digest to an admin, not a per-user notification fan-out.
```

</details>

### OTP password reset has no delivery path in production (SMS TODO; log line below LOG_LEVEL; dev_otp local-only)

<a id="otp-no-production-delivery"></a>

`otp-no-production-delivery` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:216-220`, `backend/app/Http/Controllers/Api/AuthController.php:153-156`, `backend/.env.production.example:12`, `backend/.env.production.example:38-39`, `backend/config/logging.php:68-72`, `DEPLOYMENT.md:12`

**Evidence**

```text
AuthController.php:216-219 `protected function sendOtp(User $user, string $otp): void { Log::info("OTP password-reset for user #{$user->id} (phone {$user->phone}): {$otp}"); // TODO(SMS): SmsGateway::send(...)`. :153 `if (app()->environment('local')) { $neutral['dev_otp'] = $otp; ...}`. .env.production.example:12 `APP_ENV=production`, :38-39 `LOG_CHANNEL=daily` / `LOG_LEVEL=error`; logging.php:71 `'level' => env('LOG_LEVEL', 'debug')` → the Log::info line is discarded in production. DEPLOYMENT.md:12 acknowledges: without an SMS provider parents/teachers cannot self-recover their password in production.
```

**Why it matters**

For a public mobile app, 'forgot password' is a mandatory flow; today it silently produces nothing in production. Parents (random password on create, ParentResolver) are the role most likely to need it. Also, in `local` the OTP is written in plaintext to laravel.log — acceptable for dev only, but the same code path would leak OTPs if LOG_LEVEL were ever raised to info in production.

**Recommendation**

Integrate an SMS gateway (Libyana/Almadar aggregator or Twilio/Vonage) behind an `SmsGateway` contract in `sendOtp()`, with delivery-status logging (no OTP value in logs — log a hash or last-2 digits), a per-phone daily cap, and an env flag `SMS_DRIVER=log|libyana|...`. Add a feature test that asserts the gateway is called with the phone and that the OTP never appears in the log in non-local envs. Ship this before the mobile launch; the Flutter forgot-password screen is dead without it.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 90% · corrected severity: medium

Traced the path and the core claim holds. AuthController::forgotPasswordRequest (backend/app/Http/Controllers/Api/AuthController.php:122-159) stores a hashed OTP and calls sendOtp() (:216-220), which only does Log::info(...) with the plaintext OTP and a TODO(SMS) comment; there is no SmsGateway class anywhere in app/ or config/. dev_otp is added to the response only under app()->environment('local') (:153-156). .env.production.example sets APP_ENV=production (:12) and LOG_CHANNEL=daily / LOG_LEVEL=error (:38-39); config/logging.php daily channel uses env('LOG_LEVEL','debug') (:68-72), so at 'error' the info-level line is dropped. Net effect: in production the OTP is delivered nowhere and the verify step can never succeed — the flow is dead as claimed. DEPLOYMENT.md:12 explicitly lists this as a mandatory pre-production item, so it is a known, documented gap rather than a hidden defect. No mitigation found: OtpResetTest.php only asserts dev_otp is absent outside local; no test covers delivery. Partial mitigations that lower severity: (1) teachers can have their password reset by the admin via TeacherController@update (:169-176), so teachers have an out-of-band recovery path; (2) parents do NOT — there is no admin/manager endpoint that sets a parent's password, so a parent who forgets is locked out permanently short of a DB edit. Minor factual errors in the finding: ParentResolver does not generate a random password — it requires guardian_password and returns 422 if missing (app/Support/ParentResolver.php:73-76), so parents start with a password chosen by the admin/manager (typically communicated to them); and the repo contains no Flutter/mobile app (only frontend-html), so the 'Flutter forgot-password screen is dead' framing is speculative. Given it is a documented known TODO on the pre-launch checklist, in a single-country small-team product where admin can reset teacher passwords, 'high' is somewhat overstated; 'medium' fits — real functional gap (parents have no recovery at all) but not a security vulnerability and already tracked in DEPLOYMENT.md. The plaintext-OTP-in-log concern is valid but only materializes if LOG_LEVEL is raised to info in production.

```text
backend/app/Http/Controllers/Api/AuthController.php:147 `$this->sendOtp($user, $otp);` → :216-220 `protected function sendOtp(User $user, string $otp): void { Log::info("OTP password-reset for user #{$user->id} (phone {$user->phone}): {$otp}"); // TODO(SMS): SmsGateway::send(...) }` — no SmsGateway class exists (grep app/ config/). :153-156 dev_otp only when `app()->environment('local')`. backend/.env.production.example:12 `APP_ENV=production`, :38-39 `LOG_CHANNEL=daily` / `LOG_LEVEL=error`; backend/config/logging.php:68-72 daily channel `'level' => env('LOG_LEVEL', 'debug')`. DEPLOYMENT.md:12 lists SMS gateway as mandatory pre-production item #4 (known gap). Mitigation for teachers only: backend/app/Http/Controllers/Api/TeacherController.php:169-176 admin can set a teacher password on update. No equivalent for parents (routes/api.php has no parent password endpoint). Correction to finding: app/Support/ParentResolver.php:73-76 requires `guardian_password` (422 if missing) — parents are not created with a random password. No Flutter/mobile app exists in the repo. backend/tests/Feature/OtpResetTest.php:22-33 only asserts dev_otp absent outside local; no delivery test.
```

</details>

### Message ownership derived from sender_role, not sender_id — reassigned teacher inherits predecessor's messages as 'mine' and reads the parent's history with the former teacher

<a id="thread-mine-by-role-misattribution"></a>

`thread-mine-by-role-misattribution` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/MessageController.php:120-130`, `backend/app/Http/Controllers/Api/MessageController.php:82-85`, `backend/app/Http/Controllers/Api/StudentController.php:611`, `backend/app/Http/Controllers/Api/StudentRequestController.php:274-296`, `frontend-html/teacher/messages.html:78-82`

**Evidence**

```text
MessageController.php:126 `'mine' => $m->sender_role === $myRole,` and the payload (:123-130) exposes only `id, sender_role, mine, body, read_at, created_at` — no `sender_id`/`sender_name`. The thread key is `student_id` (migration comment :8-10), but `students.teacher_id` changes via `PUT /manager/students/{id}/teacher` (StudentController.php:611 `changeTeacher`) and via transfer approval (StudentRequestController.php:281-289 sets `teacher_id => $targetTeacher->id`). Frontend renders bubbles purely by `m.mine` (teacher/messages.html:79 `class="msg-bubble ${m.mine ? 'mine' : 'theirs'}"`). Unread count (:82-84) also counts the parent's old messages to the former teacher as unread for the new teacher.
```

**Why it matters**

Privacy: a new teacher (possibly at a different center after a transfer) reads the full private conversation between the parent and the previous teacher. Correctness: the previous teacher's messages render on the new teacher's side of the chat as if they wrote them, and the parent's UI labels them with the current teacher's name (`'other' => $student->teacher->name`, :136-138). A Flutter client cannot fix this client-side because `sender_id` is not in the payload.

**Recommendation**

Return `sender_id` and `sender_name` per message and compute `mine` as `sender_id === auth()->id()`. Decide the transfer policy explicitly: either (a) archive the thread on teacher change (add `messages.teacher_id`/`parent_id` snapshot columns and scope `thread()`/`threads()` to the current pair, exposing the old thread read-only to the parent only), or (b) keep continuity but visibly attribute former-teacher messages («محفّظ سابق: فلان») and exclude them from the new teacher's unread count. Add a feature test: reassign teacher, assert the new teacher sees 0 'mine' messages and the parent's old messages are not visible / are flagged.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Traced the code and the finding is factually accurate. backend/app/Http/Controllers/Api/MessageController.php:126 computes `'mine' => $m->sender_role === $myRole` and the payload (:123-130) omits sender_id/sender_name even though the `messages` table stores `sender_id` (migration 2026_08_22_100000, Message model fillable). resolveStudent (:42) admits whoever is the *current* `students.teacher_id`, and thread() (:115-118) marks every parent-role message read for that caller. The thread key is student_id by explicit design (migration doc-block), and neither `StudentController::changeTeacher` (:604 ff.) nor transfer approval in StudentRequestController touches the messages table (grep finds no reference), so history follows the student to the new teacher, and the predecessor's messages render as `mine` in both frontends (teacher/messages.html:79, parent/messages.html:79). Parent side labels the whole thread with the current teacher's name (:136-138). MessagingTest.php covers only ownership/403 gates and mine flags for the original pair — no test for reassignment/transfer. No mitigation exists elsewhere. Two auditor claims are slightly overstated: (1) unread count (:82-85) only includes parent messages the former teacher never opened (read_at is per-message, not per-reader), so it is not "all" old messages; (2) thread continuity across teacher change is arguably intended (student-keyed thread), and the new teacher is the child's legitimate current teacher, with cross-center movement requiring manager approval. The clear bugs are misattribution (predecessor's messages shown as the new teacher's own, with read receipts) and the missing sender identity in the API. For a small single-country center system, this is a real correctness/attribution defect with a modest privacy dimension rather than a high-severity security issue — medium.

```text
backend/app/Http/Controllers/Api/MessageController.php:42 (teacher party resolved by current students.teacher_id), :82-85 (unread counts only messages with read_at NULL, i.e. those the former teacher never opened — narrower than claimed), :115-118 (opening thread marks all parent messages read for any current teacher), :123-130 (`mine` by sender_role; sender_id not exposed although stored — migration 2026_08_22_100000_create_messages_table.php line `sender_id`, Message::$fillable). StudentController.php:604-640 changeTeacher and StudentRequestController transfer approval never reference Message. tests/Feature/MessagingTest.php:29-123 — no reassignment/transfer scenario. frontend-html/teacher/messages.html:79,81 and parent/messages.html:79,81 render purely on m.mine.
```

</details>

### Notification payload carries HTML page paths as `link` and an inconsistent `ref_id` — no deep-link contract for mobile

<a id="notification-payload-web-links-not-deep-links"></a>

`notification-payload-web-links-not-deep-links` · 🟠 high (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Notifications/InAppNotification.php:37-46`, `backend/app/Http/Controllers/Api/MemorizationController.php:197-198`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:99-100`, `backend/app/Http/Controllers/Api/MessageController.php:187-188`, `backend/app/Http/Controllers/Api/StudentRequestController.php:183-184`, `backend/app/Http/Controllers/Api/NotificationController.php:20-32`, `frontend-html/js/layout.js:241`

**Evidence**

```text
`link` values are web files: `'parent/child.html?id=' . $student->id` (Memorization:198, WeeklyTest:100), `'teacher/messages.html?student=' . $student->id` (Message:188), `'manager/requests.html'` (StudentRequest:184), `'admin/managers.html'` (ManagerManagement:192). `ref_id` semantics differ per type: memorization_added → `$student->id` (Memorization:197) but test_added → `$test->id` (WeeklyTest:99) while its link uses the student id; request_* → `$req->id`; manager_deactivated → `null`. layout.js:241 navigates with `location.href = APP_ROOT + el.dataset.link`. NotificationController.php:20-32 returns the stored strings as-is plus a server-rendered `created_ago`.
```

**Why it matters**

A Flutter client must parse `parent/child.html?id=5` to route, and cannot rely on `ref_id` (test id vs student id). Any renaming of an HTML page silently breaks stored notifications forever (they are persisted rendered). Push payloads will inherit the same ambiguity.

**Recommendation**

Add `ref_type` (student|thread|request|manager) and `deep_link` (e.g. `mutqin://student/5`, `mutqin://thread/5`, `mutqin://requests`) to `toArray()`; make `ref_id` always the navigable entity id (for test_added use student id, or add `secondary_id`). Keep `link` for the web. Document the catalogue (7 types today) in one place — a `NotificationType` enum with `deepLink()` — and have the frontend/Flutter switch on `type`+`ref_type`. Backfill existing rows in a migration (map link → deep_link).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Factual claims verified: InAppNotification::toArray stores {type,title,body,ref_id,link} (InAppNotification.php:37-46); links are web paths ('parent/child.html?id=N' at MemorizationController:198 and WeeklyTestController:100, 'teacher|parent/messages.html?student=N' at MessageController:188, 'manager/requests.html' at StudentRequestController:184, 'admin/managers.html' at ManagerManagementController:192); ref_id is student id for memorization_added and message_received, test id for test_added, request id for request_*, null for manager_deactivated; layout.js:241 navigates via APP_ROOT + link. So the code behaves as described. However the impact is almost entirely hypothetical: (1) there is no Flutter/mobile client anywhere in the repo (grep for flutter/deep link/fcm/firebase returns nothing) — the product is explicitly a static HTML web client, and relative web links resolved against APP_ROOT are the correct contract for it; (2) the 'inconsistent ref_id' is never exposed to any client — NotificationController::index returns only id/type/title/body/link/is_read/created_at/created_ago and drops ref_id entirely, so no consumer can be misled by it today; (3) 'renaming an HTML page breaks stored notifications' is a real but minor maintenance risk affecting only the 30 most recent informational notifications per user (the list is capped at 30), with no data or security consequence; (4) a feature test already pins the link contract (StudentRequestNotificationTest.php:49). This is a forward-compatibility/design-debt note for a mobile client that does not exist, not a defect in current behavior. 'High' is unjustified; at most low.

```text
backend/app/Http/Controllers/Api/NotificationController.php:20-32 — index() maps only id/type/title/body/link/is_read/created_at/created_ago; ref_id is NOT returned to any client, so the ref_id inconsistency is invisible externally. backend/app/Http/Controllers/Api/ManagerManagementController.php:186-193 — manager_deactivated uses ref_id null, link 'admin/managers.html' (confirms auditor). backend/tests/Feature/StudentRequestNotificationTest.php:49 — asserts $notif->data['link'] === 'teacher/students.html' (link contract is test-pinned for the web client). No Flutter/mobile client or push integration exists in the repository (grep flutter|deep link|fcm|firebase → no hits outside vendor).
```

</details>

### Notifications capped at 30 and threads at 100 with no cursor; no unread-count endpoints; thread list unsorted

<a id="no-pagination-no-unread-aggregates"></a>

`no-pagination-no-unread-aggregates` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/NotificationController.php:20`, `backend/app/Http/Controllers/Api/MessageController.php:120-122`, `backend/app/Http/Controllers/Api/MessageController.php:92-99`, `backend/routes/api.php:33-35`, `backend/routes/api.php:47-49`

**Evidence**

```text
NotificationController.php:20 `$user->notifications()->latest()->limit(30)->get()` — no `page`/`cursor`/`unread` parameter. MessageController.php:121 `->latest('id')->limit(100)->get()->reverse()` — older messages are unreachable. `threads()` returns `$students->map(...)` in student-id order (:92-99) with no sort by `last_at`. Routes (api.php:33-35, 47-49, 147-149) expose no `/notifications/unread-count` or `/messages/unread-count`; the only way to get the message badge total is to fetch all threads.
```

**Why it matters**

A parent receiving `memorization_added` daily exceeds 30 notifications in a month — older ones vanish from the API. A busy thread loses history after 100 messages. Mobile tab badges need a cheap unread total; polling the full list every minute to get one integer is wasteful on data and battery.

**Recommendation**

Cursor-paginate `GET /notifications?before_id=&limit=&unread=1` and `GET /{role}/messages/{student}?before_id=&limit=50`; add `GET /notifications/unread-count` and `GET /{role}/messages/unread-count` (single COUNT each, indexes already exist); sort threads by `last_at DESC NULLS LAST`. Add a summary endpoint `GET /me/badges` → `{notifications_unread, messages_unread}` for the app shell.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted code exists and behaves as described: NotificationController.php:20 hard-caps at 30 with no page/cursor/unread filter; MessageController.php:120-122 returns only the latest 100 messages with no way to reach older ones; threads() (:92-99) maps in Student query order (effectively student id), not by last_at. However, two of the auditor's four claims are inaccurate or overstated. (1) "No unread-count endpoints": GET /notifications already returns `unread_count` via a single COUNT (NotificationController.php:37), and markRead returns it too (:59); the bell in layout.js:221-223 uses exactly that field. So the notifications badge does not lack an aggregate — it just rides along with a 30-row list. (2) "The only way to get the message badge total is to fetch all threads … polling every minute is wasteful on data and battery": there is no message badge in the app shell at all (layout.js has only the notification badge, no polling of /messages), and the threads endpoint already computes per-thread unread with one grouped COUNT backed by the index (student_id, sender_role, read_at) in 2026_08_22_100000_create_messages_table.php:26 — no N+1. There is no mobile app in this repo, so the "mobile tab badges" impact is hypothetical. What remains real: a busy 1:1 parent↔teacher thread about one child loses history beyond 100 messages (only from the API view, data is retained), notifications older than the 30 most recent are unreachable through the bell dropdown, and the thread list is not ordered by recency. For a small Libyan Quran-center deployment where a parent typically has 1-3 children and threads are one-child-scoped, these are UX/completeness gaps, not functional or security defects. Not covered by MessagingTest.php (checks authorization scoping only). Severity should be low, not medium.

```text
Confirmed: backend/app/Http/Controllers/Api/NotificationController.php:20 (limit(30), no cursor); backend/app/Http/Controllers/Api/MessageController.php:120-122 (latest 100, no cursor); MessageController.php:92-99 (thread order = student order, no last_at sort). Corrections: NotificationController.php:37 and :59 already return `unread_count` (single COUNT) — the notification aggregate exists; frontend-html/js/layout.js:221-223,257 consumes it and is the only poller (60s). No message badge exists in the shell (layout.js has no /messages polling), so no client fetches all threads for a badge. MessageController.php:82-88 computes per-thread unread + last message with two grouped queries, indexed by backend/database/migrations/2026_08_22_100000_create_messages_table.php:25-26. Only teacher/parent messages routes exist (routes/api.php:47-49,147-149) — the ":33-35" notifications routes are role-agnostic auth routes. backend/tests/Feature/MessagingTest.php covers authorization only, not pagination.
```

</details>

### Opening a thread (GET) bulk-marks all incoming messages as read — no explicit read endpoint, no per-message receipts

<a id="read-marking-on-get-side-effect"></a>

`read-marking-on-get-side-effect` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/MessageController.php:114-118`, `frontend-html/teacher/messages.html:74-86`

**Evidence**

```text
MessageController.php:114-118 inside `thread()` (GET): `Message::where('student_id', $student->id)->where('sender_role', '!=', $myRole)->whereNull('read_at')->update(['read_at' => now()]);` — executed before the fetch on every GET. The frontend calls `openThread` → `API.get(BASE + '/' + id)` (messages.html:74) and again after every send (:102) to refresh.
```

**Why it matters**

Any prefetch, background sync, pull-to-refresh, or notification-tap-then-back in a mobile client marks the whole thread read without the user having seen it; the sender's «قُرئت ✓» becomes unreliable. GET is non-idempotent, which also breaks HTTP caching semantics.

**Recommendation**

Remove the update from `thread()` (or gate it behind `?mark_read=1` for web compatibility) and add `POST /{role}/messages/{student}/read` with optional `up_to_id` for precise receipts. Return `read_at` per message as now. Flutter should call read only when the thread is actually visible.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The quoted evidence is accurate: MessageController::thread() (GET, lines 114-118) unconditionally bulk-updates read_at on the other party's messages before fetching, and routes/api.php:47-49 and 147-149 expose only threads/thread/send — there is no explicit read endpoint. The behaviour is deliberate and codified: the docblock at line 105 says opening the thread marks incoming as read, and tests/Feature/MessagingTest.php:43-49 asserts that a GET on the thread sets read_at. So the finding is factually correct and not mitigated by any middleware, model, or test. However, the impact is overstated for this product as it exists. The only callers of GET /{role}/messages/{student} in both frontends (teacher/messages.html and parent/messages.html) are openThread(), invoked on (a) a user click on a thread, (b) a notification deep link (?student=) that renders the thread immediately, and (c) the refresh right after the user sends a message — all cases where the thread is actually on screen. There is no polling, prefetch, background sync, or visibilitychange refetch of threads anywhere (layout.js bell polls /notifications, not messages). No Flutter/mobile client exists in the repo, so the "prefetch/pull-to-refresh marks read" scenario is hypothetical future work. HTTP caching of an authenticated JSON GET is also a non-concern here (no Cache-Control usage, per-user bearer token). What remains is a genuine API-design smell (non-idempotent GET, coarse thread-level receipt) that would matter if a second client is built; that is a low-severity design note, not a medium defect.

```text
backend/app/Http/Controllers/Api/MessageController.php:105 (docblock declares the mark-read-on-open behaviour as intended) and :114-118 (the update); backend/routes/api.php:47-49, 147-149 (only threads/thread/send routes exist — no read endpoint); backend/tests/Feature/MessagingTest.php:43-49 (test asserts GET marks read_at, so the behaviour is intentional and guarded); frontend-html/teacher/messages.html:70-91, 102, 113 and frontend-html/parent/messages.html:70-91, 102, 113 (openThread is the sole caller of the GET and is only triggered by click, notification deep-link, or post-send refresh — no polling/prefetch/visibility refetch, so receipts are accurate in the shipped web client).
```

</details>

### 60-second unconditional polling for the bell; open message threads never refresh; no visibility/ETag optimisation

<a id="polling-only-no-realtime-no-backoff"></a>

`polling-only-no-realtime-no-backoff` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/js/layout.js:257`, `frontend-html/js/layout.js:219-229`, `frontend-html/teacher/messages.html (no setInterval)`, `frontend-html/parent/messages.html (no setInterval)`, `backend/app/Http/Controllers/Api/NotificationController.php:20-37`

**Evidence**

```text
layout.js:257 `setInterval(() => load(true), 60000); // تحديث دوري خفيف للشارة (بلا real-time)`; `grep visibilit frontend-html/js/layout.js` → none (polls while tab hidden). `grep -n "setInterval\|visibilitychange" frontend-html/teacher/messages.html frontend-html/parent/messages.html` → none: an open conversation shows a new incoming message only if the user re-clicks the thread or the bell. Each poll runs two queries (NotificationController.php:20 list of 30 + :37 `unreadNotifications()->count()`) and returns the full payload with no ETag/304.
```

**Why it matters**

Chat feels broken (no live updates in the open thread). At dozens of centers with hundreds of concurrent web sessions this is ~N/60 req/s of pure baseline load on shared hosting, each returning 30 rendered notifications. Mobile cannot replicate this pattern in the background at all (see no-push).

**Recommendation**

Short term: poll the cheap `/notifications/unread-count` instead of the full list, pause on `document.hidden`, add a 10–15s refresh (or long-poll `?after_id=`) on the open thread. Medium term: push (FCM for mobile, Web Push via a service worker for the PWA) with polling as fallback only; consider Laravel Reverb/Pusher for web real-time once hosting allows a long-running process.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Evidence verified as quoted. frontend-html/js/layout.js:257 runs `setInterval(() => load(true), 60000)` and load() (219-229) always calls the full `GET /notifications`; there is no `visibilitychange`, `document.hidden`, focus gating, ETag/If-None-Match, or `after_id` anywhere in frontend-html (grep for setInterval|visibilitychange|unread-count|after_id|etag returns only layout.js:257). The recommended `/notifications/unread-count` endpoint does not exist in routes/api.php (only index, read-all, {id}/read). NotificationController::index (lines 20 and 37) issues two queries per poll and returns 30 mapped items with diffForHumans each time. teacher/messages.html and parent/messages.html have no timer or visibility hook; openThread() fetches once and only refreshes on re-click or after the user's own send (line ~103 `await openThread(currentId)`), so an incoming message never appears live in an open thread. Partial mitigation found: MessageController::send (line ~181) fires InAppNotification::sendSafe, so the recipient's bell badge updates within ≤60s and the notification links to the messages page — the user is signalled, just not inside the open thread. Backend has no throttle or cache on /notifications. The finding is factually correct, but the load impact is overstated for this product: even 300 concurrent sessions yield ~5 light indexed queries/s, negligible for MySQL; the real issue is UX (no live thread refresh, polling in hidden tabs) in a small single-country deployment. Severity is better placed at low.

```text
frontend-html/js/layout.js:219-229 (load() always hits full GET /notifications), :257 (60s setInterval, no visibility gating); frontend-html/teacher/messages.html:72-91 and frontend-html/parent/messages.html:72-91 (openThread single fetch, refresh only after own send at ~:103 or re-click); backend/routes/api.php:33-35 (no unread-count route exists); backend/app/Http/Controllers/Api/NotificationController.php:20,37 (two queries per poll, 30 items, no ETag); mitigation: backend/app/Http/Controllers/Api/MessageController.php:181 InAppNotification::sendSafe on every message → bell badge reflects new messages within 60s.
```

</details>

### No per-user rate limit on sending, no report/block, no manager oversight — spam amplifies into notifications

<a id="no-anti-abuse-rate-limit-moderation"></a>

`no-anti-abuse-rate-limit-moderation` · 🟡 medium · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/routes/api.php:49`, `backend/routes/api.php:149`, `backend/bootstrap/app.php:14-21`, `backend/app/Http/Controllers/Api/MessageController.php:172-189`, `backend/app/Models/Message.php:7-10`

**Evidence**

```text
api.php:49/149 `Route::post('/parent/messages/{student}', ...)` / `/teacher/messages/{student}` carry no `throttle:` middleware (only the three auth routes are throttled, api.php:17-21); `grep -rn -iE "throttle|RateLimiter" backend/bootstrap/app.php backend/app/Providers/` → nothing app-defined. MessageController.php:172-189 creates the message then always emits a `message_received` notification row to the receiver. Message.php:8-10 docblock: «لا حذف للرسائل ... ولا مرفقات» — no hide/flag mechanism either. No route lets a manager or admin read a thread (api.php:53-131 manager/admin groups contain no messages routes).
```

**Why it matters**

A parent (or compromised parent account) can send thousands of messages per hour; each becomes a notification row and, once push exists, a push. Abusive or inappropriate content between an adult teacher and a parent has no reporting path, no block, and no center-manager visibility — a real liability for a children's education product operating at scale.

**Recommendation**

Add `RateLimiter::for('messages', fn($r) => Limit::perMinute(10)->by($r->user()->id))` on both send routes and `throttle:notifications` on read endpoints; add `POST /{role}/messages/{student}/report` creating a `message_reports` row that notifies the center manager; give the manager a read-only thread view for reported threads (`GET /manager/messages/{student}`, scoped to own center); optionally a `hidden_at`/`hidden_by` soft-hide (keeps the no-delete decision). Log message sends with sender/receiver ids for audit.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual claims check out on inspection. backend/routes/api.php:49 and :149 register `POST /parent/messages/{student}` and `POST /teacher/messages/{student}` with no `throttle:` middleware; the only throttled routes are the three auth routes (api.php:17-21). bootstrap/app.php:14-21 defines only the four role aliases — no RateLimiter or global API throttle — and grep over app/, routes/, bootstrap/ finds no app-defined `RateLimiter::for`. MessageController::send (lines ~172-189) validates body (required, max 2000), creates the Message row, then unconditionally calls `InAppNotification::sendSafe($receiver, 'message_received', ...)` — one notification row per message. Message.php has only fillable/casts (no hidden_at/flag), the migration `2026_08_22_100000_create_messages_table.php` has no moderation columns, and no manager/admin group in api.php contains any messages route. MessagingTest.php (5 tests) covers ownership/403 only — nothing on rate limits or oversight. Frontend has no throttle guard either (and a client guard would be irrelevant to a scripted caller).

Mitigations that reduce (but do not remove) the exposure: (1) closed user population — parents/teachers are created only by admin or manager, no self-registration, so a spammer must be a provisioned user or a compromised account; (2) a parent can only reach the teacher(s) of their own children and a teacher only their students' parents (resolveStudent enforces this), so blast radius is one pair per thread, not the whole userbase; (3) every message already stores sender_id + student_id (receiver is derivable), so the "log sends for audit" recommendation is largely already satisfied; (4) NotificationController::index caps reads at `limit(30)`, so a flood degrades the recipient's bell but does not blow up the read endpoint; (5) admin/manager can deactivate a teacher (revokes tokens) as a blunt block. However there is no route to deactivate a parent account (no parent status toggle in api.php), so an abusive parent cannot be stopped from the app at all short of DB surgery — that part is real. No push channel exists today, so the "each becomes a push" impact is speculative.

Net: real but modest gap for a small single-country deployment with a closed user set — low-to-medium. Keeping it at medium is defensible given children-adjacent communications with zero oversight path; I would rate low-medium, so "medium" is a slight overstatement mainly in the impact narrative (push, thousands/hour at scale) but not wrong.

```text
backend/routes/api.php:47-49 (parent messages routes, no throttle); backend/routes/api.php:147-149 (teacher messages routes, no throttle); backend/routes/api.php:16-21 (only auth routes throttled); backend/bootstrap/app.php:14-21 (only role aliases, no RateLimiter/throttle); backend/app/Http/Controllers/Api/MessageController.php:162-189 (validate body max 2000 → Message::create with sender_id → unconditional InAppNotification::sendSafe to receiver); backend/app/Models/Message.php:12-22 (fillable student_id/sender_id/sender_role/body/read_at — no hidden/flag fields); backend/database/migrations/2026_08_22_100000_create_messages_table.php (no moderation columns); backend/app/Http/Controllers/Api/NotificationController.php:20 (`limit(30)` on reads — partial mitigation); backend/tests/Feature/MessagingTest.php (5 tests, ownership only, none for throttling). Mitigating: sender_id already persisted (audit exists); recipients bounded to own child's teacher / own students' parents; no self-registration; no parent deactivation route exists so an abusive parent cannot be blocked in-app.
```

</details>

### Communication matrix is parent↔teacher per student only — no manager↔parent/teacher channel and no center announcements

<a id="no-manager-channel-no-broadcast"></a>

`no-manager-channel-no-broadcast` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort L (1–2 weeks)

**Files:** `backend/routes/api.php:43-131`, `backend/app/Http/Controllers/Api/MessageController.php:12-19`, `backend/app/Http/Controllers/Api/MessageController.php:156-162`, `frontend-html/js/layout.js:34-47`

**Evidence**

```text
MessageController.php:12-19 docblock defines the only pair: «ولي الأمر ↔ المحفّظ ... لا مراسلة بين أولياء الأمور». Manager route group (api.php:53-92) contains no messages/announcements routes; layout.js:34-42 center_manager nav has no 'messages' entry, while teacher (:31) and parent (:46) do. MessageController.php:156-162 tells a parent whose child has no teacher: «لا محفّظ لهذا الطالب حالياً — تواصل مع إدارة المركز» — but there is no in-app way to contact the center.
```

**Why it matters**

The center manager is documented as the operational authority (approves transfers, manages teachers) yet cannot send 'the center is closed tomorrow' to all parents, cannot answer a parent whose child has no teacher, and cannot message their own teachers. At dozens of centers this pushes all operational communication out to WhatsApp/phone, outside the audited system.

**Recommendation**

Add a lightweight `announcements` table (center_id, author_id, audience: parents|teachers|all, title, body, published_at) with `POST /manager/announcements`, `GET /{role}/announcements` (scoped to the user's center via their children/teacher center), and fan-out through `InAppNotification::sendSafe` type `announcement` (→ push). Optionally extend messaging with a `manager` thread per student (student_id + party pair) reusing `resolveStudent`. Keep admin out, consistent with current design.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The evidence is accurate. backend/app/Http/Controllers/Api/MessageController.php:12-19 docblock does define parent↔teacher per student as the only channel; the only message routes in backend/routes/api.php are /parent/messages* (46-49) and /teacher/messages* (147-149); the manager group (lines ~53-92) has no messages or announcements routes; frontend-html/js/layout.js center_manager nav (34-43) lacks a 'messages' entry while teacher (31) and parent (46) have one; MessageController.php:156-162 returns «تواصل مع إدارة المركز» when a child has no teacher. A repo-wide grep for announcement/broadcast/إعلان/تعميم finds nothing in backend/app, routes, or frontend, and no migration exists besides 2026_08_22_100000_create_messages_table.php. So the gap is real and not mitigated in-app. However, this is a product-scope gap, not a code defect: nothing behaves incorrectly, no security or data-integrity exposure, and the design comment explicitly and deliberately restricts messaging. A partial mitigation exists: the manager has GET /manager/parents (api.php:60, CenterManagerController::parents 207+) and manager/parents.html, giving a center-scoped directory of guardians (with phone) and their children, plus manager/teachers.html for teacher contacts, so 'contact the center' is actionable out-of-band. The impact claim ("at dozens of centers") is speculative for this small single-country deployment. Rated as a low-severity roadmap/enhancement item rather than a medium finding.

```text
backend/routes/api.php:46-49 and :147-149 are the only message routes (parent/teacher); no manager message/announcement route in :53-92. backend/routes/api.php:60 GET /manager/parents + CenterManagerController.php:207-247 give the manager a center-scoped guardian directory (name/phone/children) — out-of-band contact is possible. frontend-html/js/layout.js:34-43 center_manager nav has 'parents' but no 'messages'. Only messaging migration: backend/database/migrations/2026_08_22_100000_create_messages_table.php; no announcements table/model/controller anywhere.
```

</details>

### Catalogue misses the highest-value parent events: absence/late, teacher change, transfer of own child, student deactivation, test update

<a id="notification-catalogue-gaps"></a>

`notification-catalogue-gaps` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/AttendanceController.php (no InAppNotification usage)`, `backend/app/Http/Controllers/Api/AttendanceImportController.php (no InAppNotification usage)`, `backend/app/Http/Controllers/Api/StudentController.php:609`, `backend/app/Http/Controllers/Api/StudentRequestController.php:332-339`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:94`

**Evidence**

```text
`grep -n "sendSafe\|InAppNotification" backend/app/Http/Controllers/Api/{AttendanceController,AttendanceImportController,CenterManagerController,StudentController}.php` → no matches; only 8 call sites exist (ManagerManagement 1, Memorization 1, Message 1, StudentRequest 4, WeeklyTest 1). StudentController.php:609 comment on `changeTeacher`: «لا إشعار لهذا الإجراء». Transfer approval notifies only `$req->requestedBy` (StudentRequestController.php:332-333) — the parent of the moved child is not told. WeeklyTestController.php:94 notifies on store only; `update` (api.php:159) emits nothing.
```

**Why it matters**

The one notification every parent of a memorization-center child expects — 'your child was absent/late today' — does not exist, while the daily digest that does compute absences (n8n) goes to a single admin mailbox. Parents also learn of a teacher change or a center transfer of their own child only by noticing it in the UI.

**Recommendation**

Emit `attendance_absent`/`attendance_late` to the parent from `AttendanceController@store` and after fingerprint import (batch: one notification per absent student, deduped per student/date); emit `teacher_changed` (parent), `student_transferred` (parent), `student_status_changed` (parent) and `test_updated`. Drive all of these through domain events (`StudentTeacherChanged`, `AttendanceRecorded`) with listeners, so controllers stop hand-building notifications and the catalogue is discoverable in one directory.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Every factual claim verified. grep over backend/app confirms exactly 8 InAppNotification::sendSafe call sites (ManagerManagementController:186, MemorizationController:191, MessageController:181, StudentRequestController:178/297/332/382, WeeklyTestController:94) and none in AttendanceController, AttendanceImportController, CenterManagerController or StudentController. No app/Events, app/Listeners or app/Observers directories exist. StudentController.php:609 docblock for changeTeacher states «لا إشعار لهذا الإجراء». StudentRequestController.php:332-339 (transfer approval) notifies only $req->requestedBy (the source-center manager), not the child's parent. WeeklyTestController::update (line 117 onward) contains no notification call although api.php:164 exposes update. n8n digest sends to one `sendTo` mailbox (n8n/README.md:20, workflow toEmail). Partial mitigations: parents can passively see attendance history and summary in parent/child.html and parent/dashboard.html, and a parent–teacher messaging channel exists (MessageController) — so information is reachable, just not pushed. This is a feature-gap / product-completeness finding rather than a correctness defect; nothing behaves incorrectly and no data or security issue arises. Given the product is a small single-country deployment where the in-app bell is the only channel (no SMS/push), the practical value of the missing notifications is real but the severity is better rated low: the recommended event/listener refactor is architectural preference, not a defect. Keep as unrefuted but downgrade to low.

```text
backend/app/Http/Controllers/Api/StudentController.php:609 (docblock «لا إشعار لهذا الإجراء» on changeTeacher); backend/app/Http/Controllers/Api/StudentRequestController.php:332-339 (transfer approval notifies $req->requestedBy only); backend/app/Http/Controllers/Api/WeeklyTestController.php:94 (store notifies parent) vs :117 update (no notification), route backend/routes/api.php:164 exposes update; no sendSafe in AttendanceController/AttendanceImportController/CenterManagerController; no app/Events, app/Listeners, app/Observers directories. Mitigation: frontend-html/parent/child.html:42,125,144 renders attendance history to parents on demand; MessageController:181 provides parent–teacher messaging with message_received notification; n8n/README.md:20 digest goes to a single sendTo address.
```

</details>

### sendSafe swallows all Throwables and logs at warning — below production LOG_LEVEL=error, so notification loss is truly silent

<a id="sendsafe-silent-loss-in-production"></a>

`sendsafe-silent-loss-in-production` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Notifications/InAppNotification.php:52-62`, `backend/.env.production.example:38-39`, `backend/config/logging.php:68-72`, `backend/.env.production.example:60-62`

**Evidence**

```text
InAppNotification.php:59-61 `} catch (\Throwable $e) { Log::warning('in-app notification failed: ' . $e->getMessage()); }`. .env.production.example:39 `LOG_LEVEL=error`; logging.php:71 `'level' => env('LOG_LEVEL', 'debug')` → warnings are discarded by the `daily` handler. No retry, no dead-letter, no counter; .env.production.example:62 `QUEUE_CONNECTION=sync` and `grep -rn ShouldQueue backend/app` → none, so the send runs inline and any DB hiccup drops the notification with no trace.
```

**Why it matters**

The 'never break the primary operation' choice is correct, but at scale you need to know your delivery rate. Today a broken notifications table, a failing FCM call (once added) or a serialization error would produce zero evidence in production. With push added, silent loss becomes user-visible churn ('the app never tells me anything').

**Recommendation**

Log at `error` with structured context (`type`, `recipient_ids`, exception class); increment a metric/counter (e.g. cache key or `notification_failures` table) and surface it on the admin dashboard; when the FCM channel lands, make the notification `ShouldQueue` with `tries=3`/backoff and use the database queue + cron worker so failures land in `failed_jobs` where they can be retried. Add a test that forces a failure and asserts the primary operation still returns 200/201 and the failure is recorded.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Evidence verified exactly as quoted: InAppNotification.php:59-61 catches \Throwable and calls Log::warning; config/logging.php:71 'daily' handler level = env('LOG_LEVEL','debug'); .env.production.example:38-39 sets LOG_CHANNEL=daily / LOG_LEVEL=error, so a warning is discarded by Monolog in a deployment that follows the template. .env.production.example:62 QUEUE_CONNECTION=sync and grep for ShouldQueue in backend/app returns nothing, so the send is inline with no retry/failed_jobs path. No feature test references sendSafe, Log::shouldReceive or a forced notification failure (grep over backend/tests is empty). No mitigation exists elsewhere (no counter, no dead-letter, no admin surface). So the finding is factually correct and not mitigated.

It is however over-rated. (1) The only channel is 'database', writing to the same MySQL connection the primary operation just used successfully a few lines earlier (e.g. StudentRequestController.php:178 runs right after the StudentRequest::create; the approve path's sendSafe at ~line 332 runs after DB::transaction commits). A DB outage that fails the notification insert but not the primary write is a very narrow window; the realistic failure modes (missing/altered notifications table, non-serializable payload, deleted recipient) are systematic and would surface immediately in dev where LOG_LEVEL=debug (.env.example:21) and where the payload is a fixed 5-key scalar array (toArray at :37-46). (2) The FCM/push and 'at scale delivery rate' impact is hypothetical — there is no push channel, no queue and the product is a small single-country shared-hosting deployment (per the .env.production.example comments) with no monitoring stack that a counter would feed into. (3) The fix that matters is a one-line change (Log::warning → Log::error with context); the queue/retry/metric recommendations are disproportionate to the current architecture. Net: real, low-severity observability gap rather than a medium reliability defect.

```text
backend/app/Notifications/InAppNotification.php:31-34 via() returns ['database'] only (same MySQL as the primary write); :59-61 catch \Throwable → Log::warning. backend/config/logging.php:68-72 'daily' level = env('LOG_LEVEL','debug'). backend/.env.production.example:38-39 LOG_CHANNEL=daily, LOG_LEVEL=error (warning dropped); :62 QUEUE_CONNECTION=sync. backend/.env.example:21 LOG_LEVEL=debug (dev sees the warning). grep -rn ShouldQueue backend/app → none; grep -rn "sendSafe|Log::shouldReceive" backend/tests → none. Call sites run after the primary write/commit: StudentRequestController.php:178 (after create), :332 (after DB::transaction at :323), MemorizationController.php:191, WeeklyTestController.php:94, ManagerManagementController.php:186, MessageController.php:181.
```

</details>

### No tests for parent-facing notification types, message_received, GET /notifications or read-all; docs omit messaging entirely

<a id="test-coverage-gaps-and-doc-drift"></a>

`test-coverage-gaps-and-doc-drift` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/tests/Feature/MessagingTest.php:29-129`, `backend/tests/Feature/StudentRequestNotificationTest.php:17-69`, `backend/tests/Feature/OwnershipTest.php:65-75`, `backend/app/Notifications/InAppNotification.php:13-17`, `CLAUDE.md:89`

**Evidence**

```text
`grep -rn -E "memorization_added|test_added|message_received|unread_count|read-all" backend/tests/` → no matches. MessagingTest covers 5 auth/flow cases but never asserts `threads()` unread counts, `last_body`, the 100-message cap, or that `send()` creates a `message_received` notification. Only OwnershipTest.php:65-75 touches NotificationController (markRead IDOR); `index` and `markAllRead` are untested. InAppNotification.php:13-17 lists 6 types — `message_received` (MessageController.php:183) is missing. CLAUDE.md:89 lists the same 6 types and does not mention MessageController, the `messages` table, or the teacher/parent messages pages at all; CLAUDE.md says '20 feature-test files' while `git ls-files backend/tests | grep -c Test.php` → 40 (38 in Feature).
```

**Why it matters**

The two most frequent notification producers (memorization, weekly tests → parents) have no regression guard; a refactor could stop notifying parents with all tests green. Doc drift means the next engineer (or the Flutter team reading CLAUDE.md as the API reference) will not know messaging exists.

**Recommendation**

Add tests: `test_memorization_store_notifies_parent_with_child_link`, `test_weekly_test_store_notifies_parent`, `test_send_message_notifies_receiver_with_thread_link`, `test_notifications_index_returns_unread_count_and_items`, `test_read_all_marks_only_own`, `test_threads_unread_and_last_message`, and the teacher-reassignment case from the misattribution finding. Update InAppNotification docblock and CLAUDE.md (messaging section, 7 types, 38 test files) in the same change.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 90% · corrected severity: low

Verified every quoted claim. grep over backend/tests/ for memorization_added|test_added|message_received|unread_count|read-all returns nothing; the only NotificationController coverage is OwnershipTest.php:71-74 (markRead IDOR) — no test hits GET /api/notifications or /notifications/read-all. MessagingTest.php has 5 tests (lines 29,67,84,96,120) that assert message rows, read_at and role gates, but never assert a notification is created by send(), nor threads() unread counts/last_body/cap. MemorizationController.php:191, WeeklyTestController.php:94 and MessageController.php:181 all call InAppNotification::sendSafe with no regression test on the parent/receiver side. InAppNotification.php docblock (lines 13-17) lists 6 types and omits message_received (MessageController.php:183). CLAUDE.md:25 says "20 feature-test files" while git ls-files shows 40 Test.php (38 in Feature); CLAUDE.md has zero mention of MessageController/messages table/messages pages. No mitigation exists elsewhere (sendSafe swallows failures by design, so a broken notification path would be silent). The finding is factually correct. However it is a coverage/documentation gap with no runtime defect and no security impact; the notification paths themselves work and sendSafe guarantees the primary operation is unaffected. Medium is slightly generous for a small-team project — low-to-medium is more appropriate; I keep it at low since nothing is currently broken.

```text
backend/tests/Feature/MessagingTest.php:29-129 (5 tests, no notification assertions); backend/tests/Feature/OwnershipTest.php:71-74 (only NotificationController test — markRead); backend/app/Http/Controllers/Api/MemorizationController.php:191, WeeklyTestController.php:94, MessageController.php:181-190 (sendSafe calls with no test guard); backend/app/Notifications/InAppNotification.php:13-17 (6 types listed, message_received missing); CLAUDE.md:25 ("20 feature-test files" vs 38 actual in tests/Feature); CLAUDE.md:89 (6 notification types, no messaging section anywhere in the 223-line file).
```

</details>

### Daily attendance digest lives only in an external n8n workflow (email to one address, admin password login, not deployed, app has no mailer/scheduler)

<a id="digest-outside-app-n8n-email-only"></a>

`digest-outside-app-n8n-email-only` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `n8n/mutqin-daily-attendance-digest.json:25-62`, `n8n/mutqin-daily-attendance-digest.json:87-112`, `n8n/mutqin-daily-attendance-digest.json:181-190`, `n8n/README.md:19-25`, `.cpanel.yml:1-8`, `backend/routes/console.php:1-9`, `backend/.env.production.example:66`

**Evidence**

```text
Workflow: 8 nodes, `n8n-nodes-base.emailSend` (json:190) with `"fromEmail": "no-reply@mutqin.ly"`, `"toEmail": "={{ $('الإعدادات').item.json.sendTo }}"` (:181-182) — a single recipient. It logs in via `POST /api/auth/login` (json:87-88) with `email: admin@mutqin.ly` + plaintext `password` in a Set node (json:37-46; README:21 «The password sits in plain text for demo convenience»), gets a fresh Sanctum token every night (json:112 `Bearer {{ $json.data.token }}`) and never calls `/auth/logout` (`grep -i logout n8n/*.json` → none). `.cpanel.yml` deploys only frontend-html/ and backend/ — n8n is not part of production. `backend/routes/console.php` contains only the `inspire` command; no `Schedule::` entries. `.env.production.example:66` `MAIL_MAILER=log` and `grep -rn "Mail::\|Mailable\|toMail" backend/app` → none — the app itself cannot email.
```

**Why it matters**

The digest is demo tooling, not a product feature: it cannot reach per-center managers or parents, depends on a service outside the deployment, and accumulates admin tokens (bounded by the 7-day expiry but never pruned — no `sanctum:prune-expired` schedule). Using the admin password as a service credential is poor hygiene for an automation.

**Recommendation**

Move the digest into the app: `php artisan mutqin:attendance-digest` scheduled at 20:00 Africa/Tripoli via `Schedule::` in routes/console.php (cPanel cron `schedule:run` every minute), computing per-center summaries and emitting `attendance_digest` in-app/push notifications to each center's active manager (and optionally per-parent absence pushes). If n8n stays, create a dedicated read-only service account with a long-lived named token instead of the admin password, and add a logout step.

### No notification preferences (per-type opt-out, quiet hours) and notification text is pre-rendered Arabic stored in the DB

<a id="no-preferences-no-structured-i18n"></a>

`no-preferences-no-structured-i18n` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `backend/app/Notifications/InAppNotification.php:37-46`, `backend/app/Http/Controllers/Api/NotificationController.php:30`, `backend/app/Http/Controllers/Api/MemorizationController.php:194-196`

**Evidence**

```text
`grep -rn -iE "notification_pref|notification_settings|mute|opt.?out|quiet" backend/app backend/database` → none. toArray() stores `title`/`body` as final strings built in controllers (e.g. MemorizationController.php:194-196 `'سجّل المحفّظ حفظاً جديداً لابنك «' . $student->name . '»: سورة ' . ...`); NotificationController.php:30 renders `created_ago` server-side with `->locale('ar')->diffForHumans()`.
```

**Why it matters**

A parent with three children in a center that records memorization daily gets ~90 identical-looking notifications a month with no way to reduce them; once push exists this becomes an uninstall driver. Pre-rendered strings cannot be re-localised, re-worded or fixed retroactively; Western/Arabic digit policy is embedded at emission time. The product is Arabic-only today, so severity is low.

**Recommendation**

Add `notification_preferences` (user_id, type, in_app bool, push bool, digest bool) with sane defaults and `GET/PUT /me/notification-preferences`; honour it inside `sendSafe`/channel selection. Store `params` (student_name, surah, quality…) alongside `type` and let clients render from a translation key; keep `title`/`body` for backward compatibility. Let the client compute relative time from `created_at`.

### Notifications and messages grow unbounded — no pruning, archival or retention decision

<a id="no-retention-policy"></a>

`no-retention-policy` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `backend/database/migrations/2026_07_01_221219_create_notifications_table.php:14-21`, `backend/database/migrations/2026_08_22_100000_create_messages_table.php:16-27`, `backend/routes/console.php:1-9`

**Evidence**

```text
`grep -rn -iE "prune|Prunable|sanctum:prune" backend/app backend/routes backend/config` → none; routes/console.php has no schedule. notifications.data is `text` (migration :18) with only the morphs index. messages uses `cascadeOnDelete()` on both `student_id` and `sender_id` (migration :18-19), so any future hard delete of a student or user would erase conversation history contrary to the project's 'history is preserved' stance.
```

**Why it matters**

At dozens of centers with daily memorization notifications, the notifications table reaches millions of rows within a couple of years; `unreadNotifications()->count()` per poll stays indexed but backups, migrations and the `markAllRead` in-memory collection (`unreadNotifications->markAsRead()`, NotificationController.php:66 loads all unread rows) degrade. Parent↔teacher messages may also carry personal data subject to a retention decision.

**Recommendation**

Decide and document retention (e.g. read notifications > 180 days pruned, messages retained for the student's lifetime then archived), implement via `Prunable`/`model:prune` on a schedule, switch `markAllRead` to a single UPDATE, and change messages FKs to `restrictOnDelete()` or `nullOnDelete()` for `sender_id` to match the no-hard-delete policy.

## Measured facts

| Metric | Value |
|---|---|
| Message routes | 6 (3 parent + 3 teacher: threads, thread, send) — backend/routes/api.php:47-49, 147-149 |
| Notification routes | 3 (index, read-all, {id}/read) — api.php:33-35; all auth-scoped, no pagination |
| Notification channels implemented | 1 (database). Push: 0, Email: 0 mailables, SMS: 0 (TODO at AuthController.php:219), Web Push/service worker: 0 |
| Device-token storage | none (no migration/model; grep fcm\|apns\|device_token → 0 hits) |
| Notification types emitted | 7 distinct across 8 sendSafe call sites (request_created, request_approved, request_rejected, manager_deactivated, memorization_added, test_added, message_received); docblock + CLAUDE.md list 6 |
| Domain events with no notification | attendance absent/late, teacher change, transfer (to parent), student status toggle, weekly-test update, announcements (n/a) |
| Bell polling | every 60,000 ms per open tab, 2 SQL queries/poll, no visibility pause, no ETag; open message threads: 0 refresh |
| Hard caps | notifications list 30, thread 100 messages, body 2000 chars; none paginated |
| Who-can-message matrix | parent↔teacher per student only; manager/admin: 0 channels; announcements: 0 |
| Rate limiting on messaging | 0 app-defined limiters (only auth routes throttled: login 10/min, OTP 5/min & 10/min) |
| Tests | MessagingTest 5 methods; StudentRequestNotificationTest 3; notification assertions in 4 more files; 0 tests for memorization_added/test_added/message_received/GET notifications/read-all; 38 Feature test files total (docs say 20) |
| Code size | MessageController 197 lines, NotificationController 74, InAppNotification 63, Message model 34, layout.js 261; messages pages 118 lines each (identical except 4 role strings) |
| DB indexes for messaging | 2 composite on messages (student_id,id) and (student_id,sender_role,read_at); notifications has only the morphs index |
| n8n digest | 8 nodes, channel = SMTP email to 1 address, trigger 20:00 Africa/Tripoli, auth = admin password login (new token nightly, no logout), not included in .cpanel.yml deploy; Laravel schedule entries: 0 |
| Production log visibility of notification failures | 0 — sendSafe logs at warning, LOG_LEVEL=error in .env.production.example |

## Auditor notes

Additional lower-severity observations not listed as separate findings: (a) MessageController::send (:153-155) does not verify the receiver is active or still holds the expected role — a message to a deactivated teacher succeeds and creates a notification nobody will read; (b) neither `resolveStudent` nor `threads()` filters `students.is_active`, so conversations continue about deactivated students (arguably desirable for history, but undocumented); (c) `threads()` for the teacher includes children with `teacher_id` set but the parent branch includes children with `teacher_id = null`, handled with a 422 on send and the hardcoded label «محفّظ غير معيّن» (:137); (d) `notifications.type` column stores the class name `App\Notifications\InAppNotification`, so the business type is only in `data->type` (JSON path) — no server-side filter by type is possible without a JSON index or a dedicated column; (e) `markAllRead` (NotificationController.php:66) loads all unread rows into a Collection before updating — use a bulk UPDATE; (f) the two messages pages are byte-identical except 4 strings — a shared `js/pages/messages.js` would remove the duplication; (g) the manager's `changeTeacher` explicitly documents «لا إشعار لهذا الإجراء» — a product decision worth revisiting; (h) the PWA manifest is injected via config.js:37 but no service worker exists, so 'installable' does not mean 'notifiable'. Doc drift confirmed: CLAUDE.md has no mention of MessageController, the `messages` table, `/parent|teacher/messages` routes, or the messages pages; its notification type list and the InAppNotification docblock both omit `message_received`; CLAUDE.md's '20 feature-test files' vs 38 actual. Vendor is not installed locally, so the Laravel 11 framework default `throttle:api` (60 req/min per user/IP) could not be verified from source; even if active it is a generic limit, not an anti-spam control for messaging. Recommended 2-week 'now' package for Flutter, in order: devices table + FCM channel; sender_id/mine fix; deep_link/ref_type in payload; cursor pagination + unread-count endpoints; SMS gateway for OTP. Everything else fits weeks 3–8.
