# Data Privacy, Child Protection & Store Compliance

[← Enterprise Audit](../enterprise-audit.md)

**Score 33 / 100** — Unfit · maturity **L1** · weight 5%

The engineering baseline shows real, tested data-minimisation instincts (explicit-select admin user list with a contract test excluding id_number, parent endpoints that return only what the parent screen needs, hashed OTPs with a 'never leaks outside local' test, token revocation on deactivation, closed-by-default CORS, synthetic local-only seeders, a pseudonymous S{n} key on the fingerprint device). But as a privacy and child-protection PROGRAM the system is at level 1: there is no privacy notice, terms, consent record or lawful-basis statement anywhere (0 grep hits across frontend, backend and docs); the approved 'never hard-delete' policy has no retention schedule and no erasure/pseudonymisation path, so a withdrawn child or departed guardian cannot be forgotten and store Data-Safety/App-Privacy questions cannot be answered truthfully; a center manager can enumerate every parent's full national ID system-wide with 3-digit prefixes; guardianship is asserted by an unverified phone number typed by staff who also choose the parent's password; the public landing page publishes 11 byte-identical downloads of identifiable children's photos (Facebook CDN, Telegram export, Google Images filenames) with no consent, release or attribution; plaintext OTP+phone are written at info level; the public GitHub repo discloses the admin login, the universal demo password of a dataset the deploy log records as deployed, the cPanel user and DB name; and no processor (shared host, Google Fonts, jsDelivr, SMTP/n8n, future SMS gateway, fingerprint vendor) is inventoried or under agreement. No owner, DPO, DPIA, breach procedure or access-audit trail exists. Against a level-5 bar for a register of minors that is about to scale to dozens of centers and a public mobile app, this is unfit (33/100) despite good code-level hygiene.

## What is already strong

- Deliberate field-level minimisation on the newest admin endpoint: AdminUserController.php:26 selects explicit columns and :70-81 re-maps rows; AdminUsersListTest.php:46-49 asserts password/remember_token/id_number are absent and pins the exact key list (a real privacy contract test).
- Parent-facing payloads are hand-built, not raw models: StudentController.php:722-735 (parentChildren) and :819-833 (parentStudentDetails) expose no national_id, no other family's data, and ownership is enforced before any query (:755-760); OwnershipTest.php:49 and ParentChildPaginationTest.php:97 cover 'another parent's child -> 403'.
- Manager parent-search is intentionally reduced to name/id_number/children_count (StudentController.php:279-309) and tested for shape and role gate (ManagerAddStudentGuardianTest.php:199-217) — the design intent to limit exposure exists, even if the prefix match undermines it.
- OTP is stored only as a hash with 10-minute expiry and 5 attempts (migration 2026_06_28_140000:18-20, AuthController.php:140-145, :189-198); the dev-only dev_otp is gated on environment('local') not APP_DEBUG (AuthController.php:150-156) and OtpResetTest.php:18 'never_leaks_otp_outside_local' guards it.
- Deactivation revokes tokens immediately (User.php:81, TeacherController.php:211, CenterController.php:268, ManagerManagementController.php:174), giving an effective remote-wipe for a lost staff phone; Sanctum tokens expire after 7 days (config/sanctum.php:55).
- No silent credential generation anywhere: ParentResolver.php:72-78 refuses to create a guardian without an explicit password (422 Arabic), ProductionSeeder.php:24-27 refuses to run without ADMIN_INITIAL_PASSWORD >= 8 chars; LibyanDataSeeder.php:116-120 hard-stops outside 'local' so synthetic data cannot reach production.
- The fingerprint device is keyed by the pseudonymous display code, not the national ID (AttendanceImportController.php:164 `Student::where('display_code', 'S' . $deviceNum)`), and the uploaded xlsx is read from the PHP temp path and never persisted (:33 `IOFactory::load($file->getRealPath())`); the app stores no biometric templates and the data model has no photo/avatar field (grep of migrations/models/controllers: 0 hits).
- Hosting hardening is present for shared cPanel: backend/.htaccess denies the whole Laravel tree (`Require all denied`) with an explicit grant only for public/; .cpanel.yml:6 excludes .env and storage from rsync; CORS defaults closed (config/cors.php: fallback ['http://localhost:8080'], never '*'); .env.production.example:38-39 sets LOG_CHANNEL=daily, LOG_LEVEL=error.
- No third-party JavaScript, analytics or trackers are loaded by the client (only Bootstrap CSS from jsDelivr and Google Fonts CSS); no console logging of payloads; messaging is text-only, 2000 chars, no attachments, no parent-to-parent channel (MessageController.php:12-19).
- The team is aware it holds minors' data: DEPLOYMENT.md:15 'بيانات قُصّر — لا تفريط' and demo-accounts endpoint returns [] in production to avoid leaking names/emails (DashboardController.php:103-107).

## Level-5 target state

A named owner runs a documented privacy and child-protection program: an Arabic privacy notice and terms are published at a stable URL (linked from web, PWA and both store listings), guardians acknowledge them at first login, and enrolment records the consent method and date. Every API response is a role-shaped resource with an exact-keys contract test; direct identifiers (national ID, guardian ID, phone, message bodies) are encrypted at rest with blind indexes, masked in lists and never logged; every read of a child record, search and export is written to an access log that admins can query and that PDFs reference by requester stamp. A written retention schedule is enforced by a scheduler (expired tokens/OTPs daily, notifications 90 days, threads archived on teacher change, students pseudonymised on request within 30 days and automatically after the Awqaf-agreed period), a parent-facing deletion request exists in-app and on the web, and 'never hard-delete' is reconciled as 'never lose aggregates, always be able to forget the person'. Processors (host, SMS, SMTP/n8n, fingerprint vendor, app-store platforms, any push/crash SDK) are inventoried with agreements and breach-notice terms; marketing uses only released imagery; a DPIA and breach playbook are reviewed each release and privacy checks sit in the deployment checklist.

## What the Flutter team must know

The mobile team inherits store obligations the codebase cannot yet satisfy: both App Store Connect (Guideline 5.1.1(i)) and Google Play (User Data policy + Data Safety form) require a privacy-policy URL and in-app link before listing, and the Data Safety/App Privacy forms must truthfully declare collection of name, phone, email, government ID (child national_id and guardian id_number), attendance/assessment records, user-generated messages and 'can users request deletion' — today the honest answer to the last is 'no', so a deletion-request channel (web URL + in-app screen) and the backend pseudonymise endpoint must ship before or with the app. Accounts are provisioned by staff (no in-app sign-up), which keeps the strict in-app account-deletion trigger unmet, but reviewers routinely ask for a deletion path for child-data apps — plan for it. Do not reuse the landing-page gallery images in store screenshots. Technically: treat every current JSON payload as over-broad and map to whitelisted DTOs on the client (User objects carry id_number/password metadata; teacher payloads carry the child's national ID); never cache national IDs or message bodies in plaintext on device (use flutter_secure_storage for the Sanctum token and encrypted DB or no persistence for records); set FLAG_SECURE/screenshot protection on child-detail and message screens; add a PIN/biometric app lock because tokens live 7 days and teachers use personal phones with the PWA today; bundle Amiri/Cairo and Bootstrap-equivalent styling locally (no Google Fonts/jsDelivr calls); if push notifications are added, send content-free pushes (FCM/APNs become new processors and payloads must not carry child names); keep crash/analytics SDKs out or declare them as processors with PII scrubbing; expect the parent-search, guardian-linking and message-thread endpoints to change shape once the enumeration and thread-inheritance issues are fixed, so avoid hard-coding them; every parent onboarding flow should assume a forced first-login password change/activation OTP will be introduced.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [No privacy notice, terms, consent capture or lawful-basis artefact exists anywhere (store submission blocker)](#no-privacy-notice-consent-lawful-basis) | 🔴 critical<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| ['Never hard-delete' policy has no retention schedule, no pseudonymisation path and unbounded growth tables — contradicts store data-deletion expectations and storage-limitation duties](#no-erasure-or-retention-policy-never-delete) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Center managers can enumerate every parent's full national ID system-wide by 3-digit prefix](#manager-parent-search-national-id-enumeration) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Guardianship is asserted by an unverified phone number typed by staff, who also choose the parent's password; a mistyped number links a child to a stranger's account](#guardian-identity-unverified-phone-link-staff-set-password) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Landing page publishes 11 identifiable children's photos that are byte-identical raw downloads from Facebook CDN, Telegram and Google Images, with no consent, release or attribution](#child-photos-scraped-without-consent-on-public-landing) | 🟠 high | ✅ confirmed | NOW | S |
| [No named data owner/DPO, no DPIA/record of processing, no breach-notification or staff-confidentiality procedure for a register of minors](#no-governance-owner-dpia-breach-procedure) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | M |
| [Plaintext OTP and guardian phone are written to the application log at info level](#otp-and-phone-logged-plaintext) | 🟡 medium | ✅ confirmed | NOW | S |
| [Unauthenticated /public/demo-accounts dumps every user's name, email and role whenever APP_DEBUG is true — a weaker gate than the one the OTP code itself warns against](#demo-accounts-directory-gated-on-app-debug) | 🟡 medium | ✅ confirmed | NOW | S |
| [The public GitHub repository discloses the production admin login, the universal password of a dataset recorded as deployed, the cPanel user, DB name and host layout](#public-repo-discloses-admin-login-demo-password-hosting-metadata) | 🟡 medium | ✅ confirmed | NOW | S |
| [Six external processors/data flows carry children's or guardians' data with no inventory, contract or documented safeguards](#no-processor-inventory-or-agreements) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Parent-teacher message threads are keyed only by student, so a newly assigned teacher (or a re-linked guardian) reads the entire prior private conversation](#message-thread-inherited-by-successor-teacher-or-parent) | 🟡 medium | ✅ confirmed | NEXT | S |
| [No audit trail of who viewed, searched or exported a child's record; PDFs carry no confidentiality marking](#no-access-audit-trail-for-child-records-and-exports) | 🟡 medium | ✅ confirmed | NEXT | M |
| [Raw Eloquent models leak fields beyond each role's need: User::$hidden omits id_number/password metadata, teachers receive the child's national ID, managers see full guardian IDs, parents receive teacher notes not shown in their UI](#over-broad-model-serialization-and-role-minimisation-gaps) | 🟡 medium | ✅ confirmed | NEXT | M |
| [National IDs, phones and message bodies sit in plaintext on a shared cPanel MySQL with unencrypted mysqldump backups copied to a developer laptop](#plaintext-identifiers-shared-hosting-unencrypted-backups) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | L |
| [Google Fonts and jsDelivr receive IP/UA/referrer for every parent, teacher and manager page view; no SRI, CSP or referrer policy](#third-party-cdn-fonts-leak-parent-browsing) | ⚪ low | ℹ️ informational | NEXT | S |

### No privacy notice, terms, consent capture or lawful-basis artefact exists anywhere (store submission blocker)

<a id="no-privacy-notice-consent-lawful-basis"></a>

`no-privacy-notice-consent-lawful-basis` · 🔴 critical (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `frontend-html/index.html:338-374`, `frontend-html/login.html:48-61`, `frontend-html/manifest.webmanifest:1-21`, `backend/database/migrations/2024_01_01_000020_create_students_table.php:11-23`, `backend/app/Http/Controllers/Api/StudentController.php:162-273`

**Evidence**

```text
grep -rniE 'خصوصية|privacy|consent|terms' over frontend-html, backend/app, backend/routes, backend/resources, _handoff2, DEPLOYMENT.md, CLAUDE.md, دليل-محتوى-الصفحات.md returns 0 hits (the only 'موافقة' hits are request-approval code, e.g. teacher/attendance.html:72). The landing footer (index.html:338-374) links only #about/#features/#gallery/login and 'info@mutqin.ly · بنغازي، ليبيا' — no policy page, no controller identity. The students schema has no consent_at/consent_by/collected_from field (migration :11-23 and all 34 later migrations); StudentController::store (:162-273) creates the child record and the guardian account from data typed by staff with the guardian absent, and returns 201 with no notice generated.
```

**Why it matters**

Apple App Store Review Guideline 5.1.1(i) requires a privacy-policy link in App Store Connect and inside the app; Google Play's User Data policy requires an in-app and Play-Console privacy policy for every app that handles personal data and an accurate Data Safety form — without a policy the Flutter app cannot be listed at all. Under GDPR-style duties (Arts. 12-14 transparency, Art. 6 lawful basis, Art. 8 child data) and Libya's Law No. 4/1990 confidentiality duties for information systems, the organisation cannot demonstrate on what basis it holds national IDs, attendance and assessment notes about minors, nor that guardians were ever informed. Every parent enrolled so far has an unanswerable 'why do you have my child's national ID and who sees it' question.

**Recommendation**

Write and publish (Arabic, plain language) a privacy notice and terms at frontend-html/privacy.html and /terms.html, linked from the landing footer, login page, manifest and every role's 'more' drawer; name the controller (legal entity) and a contact; describe purposes (Awqaf reporting, attendance, memorization follow-up, parent messaging), retention and the deletion channel. Add schema fields students.consent_recorded_at / consent_recorded_by / consent_method (paper form reference) and make StudentController::store require them when a guardian is present; show a first-login acknowledgement screen for parents (users.privacy_accepted_at). Keep the same URL for the Flutter store listings.

<details><summary>Verification — 2 independent reviewers</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual evidence holds. A repo-wide case-insensitive grep for 'خصوصية|privacy|consent|terms|شروط|سياسة' (excluding vendor) returns zero hits; frontend-html contains only index.html, login.html, forgot-password.html at the root (no privacy/terms page); the landing footer (index.html:338-374) links only #about/#features/#gallery/login.html plus 'info@mutqin.ly · بنغازي، ليبيا'; login.html:48-61 has only remember-me and forgot-password; manifest.webmanifest has no policy URL; routes/api.php has no privacy/terms/policy route; create_students_table (:11-23) and no later migration add consent_*/privacy_accepted_at columns (the only 'موافقة' hits are request-approval comments); StudentController::store (:162+) creates the child + guardian account from staff-typed data with no consent field or notice. No mitigation exists in middleware, models, tests, or frontend. However, the severity is overstated as a 'critical store-submission blocker': there is no Flutter/mobile app anywhere in the repo (grep for flutter/app store/google play/متجر returns nothing) — the product is a PWA-manifest web client plus API, so App Store/Play policies are not currently applicable, and GDPR is not directly applicable to a Libya-only deployment. The remaining real gap is a transparency/governance omission (no notice, no lawful-basis or consent record for minors' data incl. national IDs) that is a documentation/product task, not a code defect that causes incorrect behaviour or data exposure. Corrected to medium.

```text
frontend-html/index.html:355-368 (footer links: #about, #features, #gallery, login.html; contact only); frontend-html/login.html:59-62 (remember-me + forgot-password only); frontend-html/manifest.webmanifest:1-21 (no policy URL); frontend-html root contains only index.html, login.html, forgot-password.html; backend/routes/api.php has no privacy/terms route; no migration under backend/database/migrations adds consent_*/privacy_accepted_at; backend/app/Http/Controllers/Api/StudentController.php:162-200 (store path, no consent input). Repo-wide grep for flutter/'app store'/'google play'/متجر: 0 hits — no store-distributed app exists, so the 'store blocker' framing is not applicable to the current product.
```

---

**Upheld** · confidence 78% · corrected severity: medium

Factual core confirmed: `grep -rniE 'خصوصية|privacy|consent|terms|سياسة|شروط'` over frontend-html, backend/app, backend/routes, backend/resources, DEPLOYMENT.md and CLAUDE.md returns 0 hits; frontend-html/ contains only index/login/forgot-password plus role folders (no privacy.html/terms.html); the landing footer (index.html:338-374) links only #about/#features/#gallery/login.html; manifest.webmanifest has no policy URL; no migration mentions consent. So the gap is real. However the severity and impact are materially overstated: (1) The headline "store submission blocker" rests on a Flutter app that does not exist anywhere in this repository (no pubspec.yaml, no 'flutter'/'متجر'/'app store' reference in DEPLOYMENT.md, CLAUDE.md, the page guide or _handoff2) — the shipped client is a static PWA served from the operator's own web server, so App Store 5.1.1 / Play Data Safety are not applicable to the audited artefact. (2) GDPR Arts. 6/8/12-14 do not govern a Libyan Quran center serving Libyan families; the applicable frame is Libyan law, and the recommended consent_* columns are a paper-process/governance control, not a code defect — nothing in the codebase is exploitable or incorrect. (3) The product is a closed, staff-provisioned system: there is no self-registration (CLAUDE.md "No self-registration"), guardians are enrolled in person by center staff, and the footer does name the operating entity ("مركز بلال بن رباح لتحفيظ القرآن الكريم", info@mutqin.ly, بنغازي) — so the claim of "no controller identity" is only partly true (no legal-entity notice, but the organisation and a contact are stated). (4) The technical controls that actually protect the child data — role+ability dual-gated routes, parent access limited to own children, token revocation on deactivation, feature tests on the role matrix — are present and unaffected by this finding. Net: a legitimate transparency/compliance gap worth fixing cheaply (an Arabic privacy notice page linked from footer/login/manifest, and an optional acknowledgement flag), but a documentation/governance item of medium severity, not a critical enterprise blocker. Could not confirm: whether any out-of-repo paper enrolment form already captures guardian consent, or whether a mobile store submission is actually planned.

```text
Confirmed 0 hits for privacy/terms/consent (Arabic and English) across frontend-html, backend/app, backend/routes, backend/resources, DEPLOYMENT.md, CLAUDE.md. frontend-html/index.html:355-362 footer links: #about, #features, #gallery, login.html only; :349-352 does name the operator "مركز بلال بن رباح لتحفيظ القرآن الكريم" and :366-367 gives info@mutqin.ly / بنغازي، ليبيا (partial controller identity). frontend-html/manifest.webmanifest:1-21 has no policy URL. frontend-html/login.html:48-61 has only remember-me and forgot-password links. No migration in backend/database/migrations references consent. No Flutter/mobile project exists in the repo (no pubspec.yaml; no 'flutter'/'app store'/'google play'/'متجر' references in DEPLOYMENT.md, CLAUDE.md, دليل-محتوى-الصفحات.md or _handoff2), so the store-blocker impact is not applicable to the audited artefact. CLAUDE.md "Missing / dormant features: No self-registration" — all users are provisioned by staff, no public signup flow to attach consent to.
```

</details>

### 'Never hard-delete' policy has no retention schedule, no pseudonymisation path and unbounded growth tables — contradicts store data-deletion expectations and storage-limitation duties

<a id="no-erasure-or-retention-policy-never-delete"></a>

`no-erasure-or-retention-policy-never-delete` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `CLAUDE.md:71`, `backend/routes/api.php:95-101`, `backend/routes/api.php:139-141`, `backend/database/migrations/2026_07_18_120000_add_correction_audit_to_attendances.php:7-12`, `backend/routes/console.php:1-8`, `backend/config/sanctum.php:55`, `backend/app/Http/Controllers/Api/MessageController.php:181-189`

**Evidence**

```text
CLAUDE.md:71: 'teachers, centers, students, and center managers are never hard-deleted — each has a PUT .../{id}/status toggle'. api.php:95 '// لا حذف للمحفّظ إطلاقاً (قرار معتمد)', :139 '// لا حذف للطالب إطلاقاً'. grep for SoftDeletes|anonymi|pseudonym in backend/app: 0 hits (only token/OTP/memorization deletes). routes/console.php contains only the 'inspire' command — no schedule; nothing calls sanctum:prune-expired although tokens expire after 10080 min (sanctum.php:55) and every n8n run creates a new token. messages (2026_08_22 migration: 'لا حذف رسائل'), notifications (MessageController.php:181-189 copies an 80-char excerpt of every message into the recipient's notifications row), password_change_logs and otp_resets have no purge. DEPLOY_LOG.md:3-9 records that the production host has no SSH/CLI/cron, so no scheduler runs there today.
```

**Why it matters**

A parent who withdraws a child, or a teacher who leaves, cannot have their name, phone, national ID or free-text notes removed — only a status flag flips, and the data stays readable to admin, in PDFs and in backups indefinitely. Google Play's Data Safety form and Apple's App Privacy declaration must state whether users can request deletion; the letter of both stores' account-deletion rules is triggered by in-app account creation (which MUTQEN does not offer), but reviewers routinely demand a deletion path for apps handling minors' data, and the GDPR-benchmark rights to erasure (Art. 17) and storage limitation (Art. 5(1)(e)) are unmet regardless. Unbounded tables also expand the blast radius of any breach.

**Recommendation**

Adopt a written retention schedule and a pseudonymise-on-request design that keeps aggregates but strips identity: Student::anonymize() sets name='طالب محذوف S{n}', national_id/phone/guardian_name/guardian_phone/nationality_name=NULL, parent_id=NULL, is_active=false, anonymized_at/by; nulls notes/mistake on that student's attendance, memorization and test rows; replaces messages.body with a tombstone and purges notification rows referencing the student. Mirror User::anonymize() for parents/teachers (name, email->deleted.{id}@invalid, phone, id_number, tokens). Expose POST /admin/students/{id}/anonymize and a parent-facing POST /parent/data-deletion-request that opens an admin task (30-day SLA), plus a public web URL for the store listings. Add a Laravel scheduler (cPanel cron -> php artisan schedule:run, or a signed HTTP trigger) that prunes expired tokens/OTPs daily, notifications after 90 days, and auto-pseudonymises students inactive > N years agreed with Awqaf.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Every factual claim in the finding checks out against the code. backend/routes/api.php:95-101 and :139-141 carry the '// لا حذف ... إطلاقاً (قرار معتمد)' comments and the apiResources exclude destroy for teachers, centers and students; ManagerManagementController has no destroy either. A grep over backend/app for SoftDeletes|anonymi|pseudonym|prune|Schedule returns zero hits; the only ->delete() calls are on tokens, OtpReset rows (per-user, on request/verify only — no age-based purge), a single memorization row and weekly-test questions. NotificationController exposes only index/markRead/markAllRead — no deletion. backend/routes/console.php contains only the 'inspire' command, there is no app/Console directory, and bootstrap/app.php registers no schedule, so sanctum:prune-expired never runs even though sanctum.php:55 sets expiration=10080; the n8n workflow (n8n/README.md:13, mutqin-daily-attendance-digest.json) does POST /api/auth/login daily with no logout call, so a new token row accumulates every night. MessageController.php:181-189 copies an 80-char excerpt of each message into the recipient's notifications row, and the messages migration (2026_08_22) states 'لا حذف رسائل'. backend/DEPLOY_LOG.md:3-9 confirms the production host has SSH disabled and no CLI/artisan, so no scheduler can run there today. No privacy policy, retention document or erasure/anonymisation path exists anywhere in the repo (grep for anonym|erasure|retention|احتفاظ across md/php/js: 0 hits outside a handoff scratch file). Mitigations that do exist are partial: inactive students are filtered out of attendance/reports (ReportService.php:113,136; ReportController.php:85) and deactivation revokes tokens, but the identity data (name, phone, national_id, guardian fields, notes) remains fully readable to admin/manager and in DB backups — the finding is factually correct. Where it is overstated: (1) there is no evidence of any mobile-store distribution — no manifest/webmanifest, no Capacitor/Flutter/APK references, no Play/App Store mention in DEPLOY_LOG.md — the product is a static web client + API on a Libyan cPanel host, so the 'Store Compliance' framing and Apple/Google account-deletion rules are hypothetical, not a current blocker; (2) GDPR is used as a benchmark only — the deployment is Libya-only with no EU data subjects, so the Art. 17/5(1)(e) 'duties' are not legally binding here; (3) unbounded growth is real but slow (one token/day from n8n, notifications proportional to messages) and the 'blast radius' argument is generic. Net: a real design gap (no erasure/pseudonymisation path, no retention or purge for tokens/OTPs/notifications/logs, no scheduler) but with no immediate legal or store trigger — medium rather than high.

```text
backend/routes/api.php:95-96,100-101 (teachers/centers apiResource ->except(['destroy']) with 'لا حذف ... إطلاقاً' comments); backend/routes/api.php:139-141 (students ->except(['store','destroy'])); backend/routes/api.php:33-35 (NotificationController only index/read-all/read — no delete); backend/routes/console.php:1-8 (only 'inspire'; no app/Console dir; no Schedule in bootstrap/app.php); backend/config/sanctum.php:55 ('expiration' => 10080) with no sanctum:prune-expired anywhere; n8n/README.md:13 (daily POST /api/auth/login, no logout in mutqin-daily-attendance-digest.json); backend/app/Http/Controllers/Api/AuthController.php:139,191,204 (OtpReset deletes are per-user on request/verify only — no age purge); backend/app/Http/Controllers/Api/MessageController.php:181-189 (80-char message excerpt copied into notifications); backend/database/migrations/2026_08_22_100000_create_messages_table.php:10 ('لا حذف رسائل'); backend/DEPLOY_LOG.md:3-9 (SSH disabled, no CLI/artisan/cron on production host). Partial mitigation: backend/app/Services/ReportService.php:113,136 and ReportController.php:85 filter is_active=true, so inactive students drop out of reports but their identity rows remain. No store-distribution artefacts found (no manifest.json/webmanifest, no Capacitor/Flutter/APK, no Play/App Store references) — store-compliance angle is speculative for this web-only deployment.
```

</details>

### Center managers can enumerate every parent's full national ID system-wide by 3-digit prefix

<a id="manager-parent-search-national-id-enumeration"></a>

`manager-parent-search-national-id-enumeration` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/StudentController.php:284-309`, `backend/routes/api.php:61`, `backend/tests/Feature/ManagerAddStudentGuardianTest.php:199-217`

**Evidence**

```text
StudentController.php:295 `if (strlen($digits) < 3) return []`; :297-301 `User::where('role','parent')->where('id_number', 'like', $digits . '%')->withCount('children')->orderBy('id_number')->limit(10)`; :304-306 returns name + full id_number + children_count. There is no center scope (the manager's center_id is never applied) and no route-specific throttle (api.php only throttles the three auth routes at :17-21). The Libyan ID format enforced elsewhere is `^[12]\d{11}$` (gender digit + birth-year digits), so prefixes like 11985, 11986 ... each yield up to 10 ordered full IDs; the test at :212 only asserts that 2 digits return nothing.
```

**Why it matters**

Any of the dozens of future center-manager accounts (or one compromised manager token) can harvest names and national identification numbers of parents in every other center — a special identifier used for government services and SIM registration in Libya — at up to 10 per request. This is cross-tenant leakage of the most sensitive guardian identifier and would be a reportable breach under GDPR-style regimes and an offence-relevant disclosure under Cybercrime Law 5/2022.

**Recommendation**

Require an exact 12-digit (or full passport) match: `where('id_number', $digits)` with `strlen($digits) >= 12`, return only {exists:true, masked_name, children_count} or a linkage token, never the raw id_number; add `throttle:20,1` on the route; log each lookup (who, when, hash of query). Update ManagerAddStudentGuardianTest to assert that a prefix returns nothing and that the response never echoes id_number. Apply the same masking to the center-scoped list at CenterManagerController.php:249 (show last 4 digits only) and manager/parents.html:81.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Evidence verified as quoted. backend/app/Http/Controllers/Api/StudentController.php:284-310 (`managerSearchParents`) strips non-digits, requires only 3 digits, then runs `User::where('role','parent')->where('id_number','like',$digits.'%')->orderBy('id_number')->limit(10)` and returns `name`, full `id_number`, `children_count`. There is no `center_id` scope anywhere in the method (the manager's center is never read), and the route at backend/routes/api.php:61 sits inside the `manager` group with no `throttle` middleware; grep of bootstrap/app.php, app/Providers and config for `throttle`/`RateLimiter` found nothing, so Laravel 11's default `throttle:api` is not applied either. The only rate limits in the app are the three auth routes (api.php:17-21). ManagerAddStudentGuardianTest.php:199-218 asserts the minimal field set, manager-only access and that 2 digits return nothing — it explicitly asserts the full `id_number` IS echoed (line 209), so tests lock in the behaviour rather than mitigate it. Because prefixes are ordered and 10 per page, iterating 4-5 digit prefixes (gender digit + birth year) dumps every parent's name + national ID system-wide. So the finding is factually correct, not mitigated by middleware, model casts (no `hidden` on id_number is relevant since it is explicitly selected), or frontend guards (manager/students.html:342-356 consumes and displays the full id_number). Mitigating context that argues against "high": (1) the cross-center reach is partly intentional — the feature exists to link a sibling to a guardian account that may already exist via another center, and the design comment (:279-282) shows the author consciously limited fields to name/id/children_count and excluded phone/email/children list; (2) the caller must hold an authenticated center_manager token — a role created only by the admin, at most one per center, i.e. a small set of trusted staff of the same organisation, not arbitrary tenants or the public; tokens are revoked on deactivation; (3) the exposed subjects are adult guardians, not children; (4) the auditor's parallel claim about CenterManagerController.php:249 is weaker — `parents()` at :207-213 IS scoped via `whereHas('children', center_id = own)`, so that list only shows guardians of the manager's own students (still full id_number, but not cross-tenant). Net: a real insider-enumeration / over-disclosure defect (prefix match + full ID echo + no throttle + no audit log) but bounded to a handful of admin-appointed accounts in a single-organisation deployment, so medium rather than high. The recommended fix (exact 12-digit match, return existence/masked data, add throttle) is correct and cheap.

```text
backend/app/Http/Controllers/Api/StudentController.php:293-307 — 3-digit minimum, `like $digits.'%'` prefix match on all parents (no center_id filter), returns full `id_number`; backend/routes/api.php:61 — route in `manager` group with no throttle; bootstrap/app.php and app/Providers contain no throttle/RateLimiter configuration (no global API rate limit); backend/tests/Feature/ManagerAddStudentGuardianTest.php:209 asserts the full id_number is echoed (locks in behaviour); frontend-html/manager/students.html:342-356 renders full id_number from the search. Correction to auditor: CenterManagerController.php:207-213 `parents()` is center-scoped via `whereHas('children', center_id = manager's)` — it exposes full id_number only for guardians of the manager's own students, not system-wide.
```

</details>

### Guardianship is asserted by an unverified phone number typed by staff, who also choose the parent's password; a mistyped number links a child to a stranger's account

<a id="guardian-identity-unverified-phone-link-staff-set-password"></a>

`guardian-identity-unverified-phone-link-staff-set-password` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Support/ParentResolver.php:44-70`, `backend/app/Support/ParentResolver.php:72-99`, `backend/app/Http/Controllers/Api/StudentController.php:209-217`, `backend/app/Http/Controllers/Api/StudentController.php:235-248`, `backend/app/Http/Controllers/Api/AuthController.php:122-159`

**Evidence**

```text
ParentResolver.php:49-50 `if (!$parent && $phone) $parent = User::where('role','parent')->where('phone', $phone)->first();` then :57-67 `$parent->fill(['name' => $name ?: $parent->name, 'phone' => ...])->save()` — an existing parent (from any center) is silently linked and their display name overwritten by whatever the manager typed. ParentResolver.php:73-78: the new parent's password comes from the request ('guardian_password', StudentController.php:213 `nullable|string|min:6`) i.e. chosen and known by the manager; grep must_change|first_login|force.*password across backend/app, database and frontend-html/js: 0 hits. Password reset then goes by OTP to the staff-entered phone (AuthController.php:132). ManagerAddStudentGuardianTest has no cross-center phone-collision test.
```

**Why it matters**

Two failure modes both disclose a child's records (attendance, assessments, teacher notes, private messages) to the wrong adult: (1) a typo or a recycled number links a new child to an unrelated parent account, who then sees that child on their dashboard and can message the teacher as 'the guardian'; (2) staff who set the password can log in as the parent at any time, and nothing forces the parent to change it. For a mobile parent app onboarding thousands of guardians this is the core integrity control and it does not exist.

**Recommendation**

Make phone a hint, not proof: when ParentResolver matches an existing parent by phone from a different center (or at all), require confirmation by an OTP sent to that phone or by the parent accepting the link from their own dashboard ('طلب ربط ابن جديد' — pending until accepted); never overwrite name/id_number on match. Replace staff-chosen passwords with an activation flow: create the account with `must_change_password=true` (new column) and a short-lived activation OTP/link delivered to the guardian's phone; block all parent routes until the password is set by the guardian. Add tests: phone collision across centers, forced first-login change, no name overwrite.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The quoted code exists and behaves as described. ParentResolver::resolve (backend/app/Support/ParentResolver.php:49-50) matches any existing role=parent user system-wide by normalized phone when no id_number matched, and :57-67 then unconditionally fills `name`/`phone` from the staff-typed values and saves — so a typo/recycled number in "new guardian" mode silently links the new child to an unrelated parent account and renames it. There is no center scoping on the match (parents have no center_id), no confirmation step, and no pending-link state. Both StudentController@store (:240) and StudentRequestController (:401) route through this. The new-parent password is the staff-entered `guardian_password` (:73-78, :92; validation :214 `nullable|string|min:6`; manager UI students.html:290 requires it); grep for must_change/first_login/force-password returns nothing, so nothing forces a parent to rotate a password known to staff. OTP reset (AuthController:132,184) keys on the same staff-entered phone, with no real SMS gateway. Parents can also message the child's teacher (routes/api.php:47-49), so a wrong link does expose progress data and a messaging channel. Tests (ManagerAddStudentGuardianTest, ManagerParentsTest) cover phone/password required, race re-match, guardian_mode=none, id_number search — none cover cross-account phone collision or name overwrite.

Mitigations that reduce (not eliminate) the risk: only trusted staff (admin / center manager) can reach this path; the manager UI offers an explicit "existing parent" mode that matches strictly by id_number (parent_id_number, no phone dedup and no field overwrite); id_number takes precedence over phone when supplied; staff-known initial passwords are a documented, deliberately approved product decision for in-person enrollment ("لا توليد صامت"), and the OTP flow lets a guardian reset the password themselves. The exposed data is a child's memorization/attendance/test records plus a teacher message thread, in a small single-country deployment. Given the insider-error precondition and the narrow blast radius, "high" overstates it; the silent cross-account phone match with name overwrite is a genuine correctness/integrity defect and warrants medium.

```text
backend/app/Support/ParentResolver.php:49-50 (system-wide phone match, no scoping/confirmation); :57-67 (`'name' => $name ?: $parent->name`, `'phone' => $phone ?: $parent->phone` then `->fill($fill)->save()` — overwrites existing parent's name on any match); :73-78,:92 (staff-supplied password hashed as the parent's password). backend/app/Http/Controllers/Api/StudentController.php:190-194,:212-214,:240-248 (guardian_password nullable min:6, passed through to resolver); :209,:236-239 (mitigation: explicit existing-parent link by parent_id_number, no overwrite). backend/app/Http/Controllers/Api/StudentRequestController.php:401 (second caller of resolver). backend/routes/api.php:47-49 (parent→teacher messaging reachable by a wrongly-linked parent). frontend-html/manager/students.html:289-290,:382-390 (UI requires phone+password for new guardian; id_number search for existing). No must_change_password/first-login-force anywhere in backend/app, backend/database, frontend-html/js. No feature test for phone collision across existing parents or for name overwrite (backend/tests/Feature/ManagerAddStudentGuardianTest.php, ManagerParentsTest.php).
```

</details>

### Landing page publishes 11 identifiable children's photos that are byte-identical raw downloads from Facebook CDN, Telegram and Google Images, with no consent, release or attribution

<a id="child-photos-scraped-without-consent-on-public-landing"></a>

`child-photos-scraped-without-consent-on-public-landing` · 🟠 high · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `frontend-html/index.html:304-325`, `frontend-html/img/gallery/g03-kids-traditional.jpg`, `frontend-html/img/gallery/g11-competition-boys.jpg`, `Home photos/629319058_1229473859375865_3097274146528381499_n.jpg`, `Home photos/photo_6_2024-11-24_10-21-19.jpg`, `Home photos/مصطفى-المهدوي-720x470.jpg`

**Evidence**

```text
index.html:304 '<!-- GALLERY: مشاهد حقيقية من مراكز التحفيظ الليبية -->', :314 caption 'براعم الحفظ بالزي الليبي', :322 'متسابقون صغار'. md5sum shows every gallery file equals a file in 'Home photos/': g04-listening.jpg == 629319058_1229473859375865_..._n.jpg (Facebook CDN naming), g11-competition-boys.jpg == photo_6_2024-11-24_10-21-19.jpg (Telegram export naming), g07-tasmee-bench.jpg == مصطفى-المهدوي-720x470.jpg (a news-site file named after a person), g10 == DSC09960-scaled.jpg (WordPress), g01/g02/g03/g05/g06/g09 == images (n).jpg (Google Images downloads). Viewing g03 and g11 confirms close-up, clearly identifiable faces of boys. Commit 7c86c22 ('11 صورة حقيقية') and the repo contain no credit, licence, model release or consent record (grep credit|attribution|المصدر|ترخيص|إذن in index.html: 0). The GitHub repo is public (api.github.com/repos/muad03/MUTQIN: "private": false).
```

**Why it matters**

Publishing identifiable minors' images on a commercial site without guardian consent is a child-protection failure in its own right, exposes the organisation under Libya's Cybercrime Law 5/2022 provisions on publishing private images/data and under copyright (the files belong to whoever shot them), and would embarrass the Awqaf-supervised centers the product serves. Any store screenshot or marketing reuse of these images would repeat the exposure. This is the only channel through which children's images leave the organisation (the data model has no photo field), so it is entirely avoidable.

**Recommendation**

Remove the 11 gallery images and the 'Home photos/' folder from the repo and rewrite history (git filter-repo) since the repo is public; replace with illustrations, photos of empty halls/boards, or images for which the center holds a signed guardian release (store the release reference alongside the asset, e.g. img/gallery/RELEASES.md). Add a written photo-consent rule to the child-protection policy: no identifiable minor in any marketing asset without a dated guardian release.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: high

Every factual claim checks out. (1) frontend-html/index.html:304-325 contains the gallery section exactly as quoted: line 304 comment 'مشاهد حقيقية من مراكز التحفيظ الليبية', line 314 '<img src="img/gallery/g03-kids-traditional.jpg" ...> براعم الحفظ بالزي الليبي', line 322 g11 'متسابقون صغار'. (2) md5sum over both folders shows all 11 gallery files are byte-identical to files in 'Home photos/' whose names are the untouched download names from third-party sources: 629319058_1229473859375865_..._n.jpg (Facebook CDN pattern) == g04, photo_6_2024-11-24_10-21-19.jpg (Telegram export pattern) == g11, مصطفى-المهدوي-720x470.jpg (WordPress news-site resized-image naming, named after a person) == g07, DSC09960-scaled.jpg (WordPress '-scaled') == g10, 698216-1151644804.jpeg == g08, and images.jpg / images (1..5).jpg (browser Google-Images download naming) == g09/g06/g05/g03/g02/g01. (3) Both folders are tracked in git (git ls-files), and commit 7c86c22 (2026-07-17) added the 11 gallery files + index.html with the message '11 صورة حقيقية' and no source/licence line. (4) Viewing g03 and g11 confirms close-up, sharp, fully identifiable faces of boys aged roughly 6-13; g11 is a 1080px professional-quality shot of two boys with lanyards. (5) The only 'credit'-family match in index.html is the footer '© ٢٠٢٦ مُتقِن — جميع الحقوق محفوظة' (line 373), which asserts the site's own copyright over the page rather than crediting anyone; no RELEASES/consent/LICENSE file exists anywhere in the repo. (6) api.github.com/repos/muad03/MUTQIN returns "private": false, "visibility": "public" — the images and the raw 'Home photos/' source folder are world-readable. (7) No mitigation exists or could exist in code: the landing page is a public static file served before any auth, the backend is not involved, and no test covers it. The one partial caveat is that the source-file naming strongly suggests these were already published elsewhere (news/social coverage of public Quran competitions and mosque circles), so the children's faces were not first exposed by this repo; that slightly softens the child-protection novelty but does nothing for the copyright/no-licence problem or for the guardian-consent obligation of re-publishing minors' faces as marketing for a product that also stores those children's category of data. The auditor's 'commercial site' framing is somewhat generous for what is currently a student/limited-deployment project, but the exposure grows with any real launch or store listing. High is the right rating.

```text
frontend-html/index.html:304 (gallery comment), :313-323 (11 <figure><img src="img/gallery/g0X..."> entries; :314 g03 'براعم الحفظ بالزي الليبي', :322 g11 'متسابقون صغار'); frontend-html/index.html:373 is the only 'حقوق' match and is the site's own '© ٢٠٢٦ مُتقِن — جميع الحقوق محفوظة', not an attribution. md5 pairs: g01==Home photos/images (5).jpg, g02==images (4).jpg, g03==images (3).jpg, g04==629319058_1229473859375865_3097274146528381499_n.jpg, g05==images (2).jpg, g06==images (1).jpg, g07==مصطفى-المهدوي-720x470.jpg, g08==698216-1151644804.jpeg, g09==images.jpg, g10==DSC09960-scaled.jpg, g11==photo_6_2024-11-24_10-21-19.jpg. Both 'Home photos/' (11 files) and frontend-html/img/gallery/ (11 files) are git-tracked; introduced by commit 7c86c22 (2026-07-17, 'Landing: photo gallery section ... 11 صورة حقيقية'). Remote origin https://github.com/muad03/MUTQIN.git, GitHub API: "private": false, "visibility": "public". Visual check of g03 (group of ~20 boys in traditional dress, several faces sharp and frontal) and g11 (two boys, close-up, 1080px) confirms identifiable minors. No LICENSE/RELEASES/consent file anywhere outside vendor/.
```

</details>

### No named data owner/DPO, no DPIA/record of processing, no breach-notification or staff-confidentiality procedure for a register of minors

<a id="no-governance-owner-dpia-breach-procedure"></a>

`no-governance-owner-dpia-breach-procedure` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `DEPLOYMENT.md:1-43`, `CLAUDE.md:1-40`, `backend/DEPLOY_LOG.md:1-25`, `frontend-html/index.html:338-374`

**Evidence**

```text
DEPLOYMENT.md's mandatory pre-production list (:5-18) covers debug flags, keys, CORS, SMS, HTTPS, backups and tests — nothing on privacy, consent, retention, breach handling or staff obligations; its 'future notes' (:39-43) mention soft-deletes/audit as non-blocking. DEPLOY_LOG.md:45 shows the initial admin password was 'سُلِّمت لصاحب المشروع في المحادثة' (handed over in a chat), i.e. no credential-handling procedure. The landing footer names only 'info@mutqin.ly · بنغازي، ليبيا' — no legal entity or responsible person. grep DPIA|breach|اختراق|إخطار|مسؤول حماية across docs: 0 hits.
```

**Why it matters**

Scaling from one demo center to dozens means dozens of managers and hundreds of teachers with routine access to minors' identifiers and free-text remarks, plus a public app surface. Without an accountable owner, a record of processing, a DPIA (a systematic-processing-of-children's-data trigger under GDPR Art. 35), staff confidentiality undertakings and a rehearsed breach playbook (who tells parents and Awqaf, within what time), the first incident will be handled ad hoc and the organisation cannot evidence compliance to Awqaf, a host, an SMS provider or an app-store reviewer.

**Recommendation**

Appoint a named privacy/child-protection owner; produce a one-page record of processing from the data map in this assessment; run a short DPIA (this document is 80% of it); adopt a breach playbook (detect -> contain: revoke tokens via existing status toggles -> assess -> notify guardians/Awqaf within 72h -> record); add a staff confidentiality clause to manager/teacher onboarding and an in-app acknowledgement at first login (users.confidentiality_accepted_at); include privacy review in the DEPLOYMENT.md checklist and in PR templates for any change touching students/users/messages.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 72% · corrected severity: medium

The factual core holds. DEPLOYMENT.md:5-18 lists only debug/key/CORS/SMS/Apache/demo-accounts/backup/HTTPS/tests; :39-43 treats audit trail as non-blocking. backend/DEPLOY_LOG.md:45 does say the initial admin password 'سُلِّمت لصاحب المشروع في المحادثة'. No privacy policy page exists (frontend-html has only index/login/forgot-password public pages), and grep for DPIA/breach/اختراق/إخطار/مسؤول حماية/خصوصية/سرية/privacy/confidential across docs, app/ and frontend-html/ returns zero relevant hits (the only hits for 'موافقة' are transfer-request approvals). No users.*_accepted_at column or onboarding acknowledgement exists in migrations. So the finding is not factually wrong and is not mitigated in code.

However it is over-rated and partly mis-framed: (1) The GDPR Art. 35 trigger is invoked for a Libya-only product (DEPLOY_LOG.md: Libyan Spider host, .ly domain, Libyan national-id format); Libya has no GDPR-equivalent statutory DPIA/72h duty, so the 'legal trigger' is an analogy, not an obligation. (2) The footer does name an operating entity — index.html:353 'مركز بلال بن رباح لتحفيظ القرآن الكريم' — so 'no legal entity or responsible person' is overstated (it names the center, not a DPO). (3) Real technical mitigations exist for the breach-containment half: deactivation toggles revoke all tokens (User::recordPasswordChange / status toggles per CLAUDE.md), 7-day token expiry, DB dumps exported 'بلا توكنات' (DEPLOY_LOG.md:43,52), mandatory backup line explicitly flagged as 'بيانات قُصّر' (DEPLOYMENT.md:15), and the admin password is directed to be changed at first login (DEPLOY_LOG.md:45). (4) This is a documentation/organisational gap, not a code defect — nothing in the execution path leaks data because a document is missing. For a currently single-center, single-owner deployment the practical exposure is lower than 'high'; it becomes high only if the multi-center scaling scenario the auditor hypothesises materialises. Corrected severity: medium.

```text
DEPLOYMENT.md:5-18 (checklist has no privacy/consent/retention/breach items; :15 does call the data 'بيانات قُصّر' and mandate backups); DEPLOYMENT.md:39-43 (audit trail listed as non-blocking); backend/DEPLOY_LOG.md:45 (initial admin password handed over 'في المحادثة', with instruction to change at first login); backend/DEPLOY_LOG.md:43,52 (SQL dumps exported without tokens — some credential hygiene); frontend-html/index.html:353 names 'مركز بلال بن رباح لتحفيظ القرآن الكريم' as operating entity (contradicts 'no legal entity' claim), :368-369 contact only info@mutqin.ly / بنغازي. No privacy policy page: frontend-html/ public pages are only index.html, login.html, forgot-password.html. Zero hits for privacy/confidentiality/breach terms in backend/app, frontend-html, and *.md. No confidentiality/terms acceptance column in backend/database/migrations. Jurisdiction is Libya (DEPLOY_LOG.md:1-5, .ly host), so GDPR Art. 35 is not a binding trigger.
```

</details>

### Plaintext OTP and guardian phone are written to the application log at info level

<a id="otp-and-phone-logged-plaintext"></a>

`otp-and-phone-logged-plaintext` · 🟡 medium · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:216-220`, `backend/.env.example:18-21`, `backend/.env.production.example:36-39`, `backend/config/logging.php:68-72`

**Evidence**

```text
AuthController.php:218 `Log::info("OTP password-reset for user #{$user->id} (phone {$user->phone}): {$otp}");` — the only integration point (:213 'نقطة الإرسال الوحيدة'). .env.example:18-21 LOG_CHANNEL=stack / LOG_LEVEL=debug (so every dev/staging copy records live OTPs + phones); the production template mitigates by LOG_LEVEL=error (.env.production.example:39) but that is convention, not code — any operator who raises the level to debug to diagnose a problem starts capturing password-reset codes; logs are retained 14 days by the daily channel (logging.php:72).
```

**Why it matters**

Anyone who can read storage/logs (cPanel File Manager users, backup handlers, support staff) can take over any parent or teacher account while a code is live, and obtains a phone directory keyed to user ids. When the SMS gateway lands, the TODO on :219 will be filled but the info line will remain unless removed. Credentials for accounts that unlock minors' records should never be written to a general log.

**Recommendation**

Delete the OTP and phone from the log line (log only user id and a hashed/last-4 phone at debug level: `Log::debug('otp.issued', ['user_id' => $user->id])`); keep dev_otp in the HTTP response for local only (already gated at :153). Add a test asserting laravel.log never contains the 6-digit code after a request (extend OtpResetTest). Add a logging guideline: no phone, national ID, OTP, token or message body in logs.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 90% · corrected severity: medium

Evidence confirmed verbatim. backend/app/Http/Controllers/Api/AuthController.php:216-220 `sendOtp()` unconditionally calls `Log::info("OTP password-reset for user #{$user->id} (phone {$user->phone}): {$otp}")` — there is no `app()->environment('local')` guard on the log line (that guard at :153 only covers the `dev_otp` response field). The OTP is stored hashed in `otp_resets` (:142), so the log is the only plaintext copy. config/logging.php:60-72 gives `single`/`daily` a default level of `debug`, and `.env.example:18-21` ships LOG_LEVEL=debug; the real `backend/.env` sets no LOG_* vars at all, so it inherits the debug default and the dev machine log does contain live codes. `.env.production.example:38-39` sets LOG_LEVEL=error, which would drop the info line — but only by convention. No test in tests/Feature/OtpResetTest.php (or anywhere) asserts on log contents. An extra point the auditor missed: the repo's own DEPLOYMENT.md:37 states the OTP appears in laravel.log "بيئة local فقط", which the code does not enforce — the docs are inaccurate, so an operator relying on them would not know raising LOG_LEVEL exposes codes. Mitigations that temper severity: OTP expires in 10 min with 5 attempts (:143,:190); the flow excludes admins (:132), and anyone with read access to storage/logs on a cPanel/XAMPP host typically also has .env (DB credentials) and could reset passwords directly, so the marginal escalation is limited; `*.log` is gitignored. Still a real, unmitigated defect — medium is appropriate.

```text
backend/app/Http/Controllers/Api/AuthController.php:218 unconditional `Log::info(... phone ... otp)`; :153 environment('local') guard applies only to the response `dev_otp`, not to the log call at :147/:218. backend/config/logging.php:64,71 default level 'debug'; backend/.env sets no LOG_CHANNEL/LOG_LEVEL so dev falls back to stack/debug. backend/.env.production.example:38-39 LOG_CHANNEL=daily, LOG_LEVEL=error (convention-only mitigation). DEPLOYMENT.md:37 incorrectly claims the log entry is local-only. No log assertions in backend/tests/Feature/OtpResetTest.php.
```

</details>

### Unauthenticated /public/demo-accounts dumps every user's name, email and role whenever APP_DEBUG is true — a weaker gate than the one the OTP code itself warns against

<a id="demo-accounts-directory-gated-on-app-debug"></a>

`demo-accounts-directory-gated-on-app-debug` · 🟡 medium · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/DashboardController.php:101-121`, `backend/routes/api.php:23`, `backend/app/Http/Controllers/Api/AuthController.php:149-156`

**Evidence**

```text
DashboardController.php:105 `if (! app()->environment('local') && ! config('app.debug')) return [];` then :109-119 `User::...->get(['name','email','role'])` mapped to name/email/role for ALL roles including parents; route api.php:23 is public with no throttle. Contrast AuthController.php:150-152: '⚠️ خطير: لا تعتمد على APP_DEBUG — قد يبقى مفعّلاً بالخطأ في الإنتاج/staging' — the same codebase already identified APP_DEBUG as an unsafe gate and used environment('local') for dev_otp, but not here.
```

**Why it matters**

A single misconfiguration (APP_DEBUG=true on staging or during a production incident) publishes the complete guardian and staff directory (names + login emails, which follow a predictable {latin}.{id}@parent.mutqin.ly scheme) to the internet with no authentication, giving attackers the username half of every credential and a list of families attached to specific centers.

**Recommendation**

Gate on `app()->environment('local')` only (mirror :153), or better, remove the endpoint and the login-page demo panel from production builds entirely (DEPLOYMENT.md:14 already asks operators to strip it by hand — make it code). Add a feature test that with APP_ENV=production and APP_DEBUG=true the endpoint returns an empty list.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 90% · corrected severity: medium

Traced the code and the evidence is accurate. DashboardController.php:105 returns the empty list only when BOTH `!environment('local')` AND `!config('app.debug')` hold, so on APP_ENV=production with APP_DEBUG=true the full `User::get(['name','email','role'])` dump (lines 109-119, all roles incl. parent, no is_active filter) is served. Route api.php:23 is public with no throttle middleware (no global API throttle found in bootstrap/app.php or providers). AuthController.php:149-156 indeed uses environment('local') only for dev_otp with an explicit warning against APP_DEBUG, confirming the inconsistency. No feature test in backend/tests covers demo-accounts (grep returned none). Mitigations are weaker than claimed elsewhere: DEPLOYMENT.md:14 tells operators that `APP_ENV=production` alone makes the endpoint return an empty list, which is false given the OR gate — the operator guidance itself would leave the leak open if APP_DEBUG is left true; and backend/.env.example:4 ships APP_DEBUG=true as the default. Frontend login.js hides the panel client-side only, which is not a mitigation for the API. Impact requires a misconfiguration (APP_DEBUG=true in a non-local env), and the leaked data is names/emails/roles (no phones, no passwords, no child data), so medium is the right severity — not exaggerated, not under-rated. Not applicable-to-product arguments (small team) do not remove the exposure of guardian names + login identifiers on an unauthenticated route.

```text
backend/app/Http/Controllers/Api/DashboardController.php:105 `if (! app()->environment('local') && ! config('app.debug')) return []` — leaks when EITHER local OR debug; :109-111 `User::...->get(['name','email','role'])` with no role or is_active filter. backend/routes/api.php:23 public, no throttle. backend/app/Http/Controllers/Api/AuthController.php:150-153 uses environment('local') only, with the APP_DEBUG warning. DEPLOYMENT.md:14 incorrectly states APP_ENV=production alone empties the endpoint (the OR gate means APP_DEBUG must also be false). backend/.env.example:4 `APP_DEBUG=true` default. No test in backend/tests references demo-accounts/demoAccounts.
```

</details>

### The public GitHub repository discloses the production admin login, the universal password of a dataset recorded as deployed, the cPanel user, DB name and host layout

<a id="public-repo-discloses-admin-login-demo-password-hosting-metadata"></a>

`public-repo-discloses-admin-login-demo-password-hosting-metadata` · 🟡 medium · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/DEPLOY_LOG.md:1-9`, `backend/DEPLOY_LOG.md:27-37`, `backend/DEPLOY_LOG.md:39-49`, `backend/database/seeders/LibyanDataSeeder.php:40-43`, `backend/database/seeders/ExtraDataSeeder.php:35`, `CLAUDE.md:32`, `backend/database/seeders/ProductionSeeder.php:35-36`

**Evidence**

```text
GitHub API for muad03/MUTQIN: `"private": false, "visibility": "public"`. DEPLOY_LOG.md:3-5 'المستضيف: Libyan Spider — لوحة cPanel · المستخدم [redacted-cpanel-user] ... جذر الويب /home/[redacted-cpanel-user]/public_html/ ... القاعدة [redacted-db-name]'; :35-37 records the demo database 'mutqin-demo-2026-09-07-clean.sql' as 'حالة الرفع: done' at mutqin.ly, and :49 states that dataset's universal password 'كلمة المرور الموحّدة [redacted-demo-password]' (LibyanDataSeeder.php:40-43 ADMIN/MANAGER/TEACHER/PARENT_PASSWORD = '[redacted-demo-password]'; CLAUDE.md:32 '[redacted-demo-password]'); the clean production dump with a fresh admin password is still ':46 pending'. ProductionSeeder.php:35 fixes the admin login as admin@mutqin.ly; login throttle is 10/min per IP (api.php:17).
```

**Why it matters**

An attacker reading the public repo gets the exact admin username, a password that the deploy log says was live on the production domain, the fact that the host has no SSH (so no fast remediation), and the internal path layout. If the pending clean dump has not yet replaced the demo dump, the production system holding real enrolments is one login away; even after rotation, the predictable email scheme plus the demo panel logic keep the username half public. For a system holding minors' national IDs this is an avoidable disclosure.

**Recommendation**

Immediately rotate every account on mutqin.ly created from the demo dump (or import the clean dump) and confirm APP_ENV=production; make the repository private or scrub DEPLOY_LOG.md of host/user/DB identifiers and passwords (move ops details to a private runbook); stop committing plaintext demo passwords (read them from env in seeders); consider a non-guessable admin login (display code) instead of admin@mutqin.ly.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Every quoted fact checks out. GitHub API confirms muad03/MUTQIN is public (`"private": false`), and backend/DEPLOY_LOG.md is tracked in git (not ignored). DEPLOY_LOG.md:3-5 names the cPanel user `[redacted-cpanel-user]`, web root `/home/[redacted-cpanel-user]/public_html/`, and DB `[redacted-db-name]`; :6-9 records SSH disabled and manual-only deploys; :35-37 records the demo dump `mutqin-demo-2026-09-07-clean.sql` as uploaded to mutqin.ly with status `done`; :46 shows the clean production dump still `pending`; :49 states the universal password `[redacted-demo-password]`. I traced the deployed dump's password: at deploy commit f868880 DatabaseSeeder already called LibyanDataSeeder, whose constants were `[redacted-demo-password]` for all four roles, so the password printed in the log is indeed the one live on the deployed demo dataset (not merely the pending 09-10 dataset). ProductionSeeder.php:36 hard-codes `admin@mutqin.ly` as the production admin login. Mitigations found: `/public/demo-accounts` returns an empty list unless APP_ENV=local or APP_DEBUG (DashboardController.php:105-107), and `.env.production.example` sets production/debug=false, so the demo panel does not leak usernames in production — the auditor's "demo panel logic keeps the username half public" is slightly overstated; the username is public via the repo/ProductionSeeder, not the panel. Login is throttled 10/min per IP (api.php:17), which is irrelevant when the exact password is published. The disk-side `.env` is not in the repo and the dumps are noted as token-free, so no secrets beyond the demo password and hosting identifiers are exposed. Nothing in code, tests, or config neutralizes a published, recorded-as-live credential; the 09-11 clean dump was still pending as of the log. Cannot verify the live server state (whether the clean dump has since been imported or passwords rotated), so severity depends on that; medium is fair given the repo cannot un-publish history even after rotation, and the hosting metadata remains useful reconnaissance for a system holding minors' national IDs.

```text
GitHub API: muad03/MUTQIN `"private": false, "visibility": "public"`; `git ls-files` includes backend/DEPLOY_LOG.md. backend/DEPLOY_LOG.md:3-9 (cPanel user [redacted-cpanel-user], /home/[redacted-cpanel-user]/public_html/, DB [redacted-db-name], SSH refused); :35-37 (demo dump 2026-09-07 uploaded, status `done`); :46 (clean dump `pending`); :49 (`كلمة المرور الموحّدة [redacted-demo-password]`). At deploy commit f868880, DatabaseSeeder.php:19 calls LibyanDataSeeder and LibyanDataSeeder.php:40-43 already = '[redacted-demo-password]' — so the deployed demo dataset used that password. backend/database/seeders/ProductionSeeder.php:36 hard-codes 'admin@mutqin.ly'. ExtraDataSeeder.php:35 '[redacted-demo-password]'. Partial mitigation: DashboardController.php:105-107 returns an empty demo-accounts list when not local/debug; .env.production.example:12,15 APP_ENV=production, APP_DEBUG=false. routes/api.php:17 throttle:10,1 on login (does not help against a known password).
```

</details>

### Six external processors/data flows carry children's or guardians' data with no inventory, contract or documented safeguards

<a id="no-processor-inventory-or-agreements"></a>

`no-processor-inventory-or-agreements` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `n8n/mutqin-daily-attendance-digest.json:35-52`, `n8n/mutqin-daily-attendance-digest.json:179-197`, `n8n/attendance-digest.code.js:18-41`, `frontend-html/css/theme.css:6`, `frontend-html/index.html:8`, `DEPLOYMENT.md:12`, `backend/app/Http/Controllers/Api/AuthController.php:216-220`, `backend/DEPLOY_LOG.md:3-9`

**Evidence**

```text
n8n JSON :38 email 'admin@mutqin.ly', :44 password 'PUT_PASSWORD_HERE' (README.md:21 'The password sits in plain text'), :181-185 emailSend from no-reply@mutqin.ly to $sendTo with text body; code.js:18 `'- ' + s.display_code + ' — ' + s.name` lists absent children by name (:31-41). theme.css:6 `@import url('https://fonts.googleapis.com/css2?family=Amiri...')` is pulled by 33/33 pages; index.html:8 (and 32 other pages) load bootstrap.rtl.min.css from cdn.jsdelivr.net; no SRI/CSP/referrerpolicy (grep: 0). DEPLOYMENT.md:12 plans an SMS gateway (ليبيانا/مدار) at AuthController::sendOtp (:219 TODO). DEPLOY_LOG.md:3-5: production on Libyan Spider shared cPanel, DB [redacted-db-name]; previously InfinityFree (:11). The fingerprint device vendor receives children's names + S-codes + timestamps (import format, AttendanceImportController.php:56-65). No file names a processor list or agreement.
```

**Why it matters**

Every page view by a parent sends IP, user-agent and the referring URL (which encodes the role path, e.g. /parent/child.html?id=) to Google and to jsDelivr's CDN; the daily digest puts absent minors' names (a 'which children were not at the center tonight' list) into an unencrypted-at-rest mailbox at an unnamed SMTP provider, authenticated with the human super-admin's full-privilege credentials; the SMS gateway will receive every guardian phone; the host holds the whole database with no contract terms on access, breach notice or deletion. Without an Art. 28-style processor register the organisation cannot answer the Data Safety 'data shared with third parties' question or notify anyone when a processor is breached.

**Recommendation**

Create docs/PROCESSORS.md listing each processor, data categories, purpose, location, contract/DPA status and exit plan; sign hosting and SMS terms that include confidentiality, breach notification (<72h) and deletion on termination. Self-host Bootstrap CSS and the Amiri/Cairo font files under frontend-html/css/ (removes both CDN flows; also needed for offline PWA/Flutter). Give n8n a dedicated service account with a scoped token ability (e.g. 'digest:read') instead of the admin login, drop student names from the email (send counts + a link into the app), and route it through an SMTP account owned by the organisation. Decide whether the fingerprint vendor sees names at all (export S-codes only).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Every quoted line exists and reads as cited: n8n/mutqin-daily-attendance-digest.json:38 email 'admin@mutqin.ly', :44 'PUT_PASSWORD_HERE', :181-185 emailSend text body; attendance-digest.code.js:18 builds '- S-code — name' lines and :31-41 lists absent/unrecorded children by name; theme.css:6 @import of fonts.googleapis.com; 33 HTML pages link bootstrap from cdn.jsdelivr.net with zero integrity/referrerpolicy/CSP hits; DEPLOYMENT.md:12 plans a Libyana/Madar SMS gateway at AuthController::sendOtp (:216-220, currently Log::info only); DEPLOY_LOG.md:3-11 names Libyan Spider cPanel and prior InfinityFree; no docs/ directory and no processor/DPA document anywhere. So the core governance gap (no processor inventory, third-party CDN/font flows, hosting without documented terms) is real and cannot be refuted.

However the impact statement is materially exaggerated on three points: (1) The claim that the referring URL 'encodes the role path, e.g. /parent/child.html?id=' is wrong for every current browser — the default referrer policy since Chrome 85 / Firefox 87 / Safari is strict-origin-when-cross-origin, so Google and jsDelivr receive only the origin (https://mutqin.ly/), plus IP and UA. No role, page or child id leaks via Referer. (2) The n8n digest is a demo template, not a deployed data flow: apiBase is http://localhost:9090, sendTo is manager@example.com, the password is a placeholder, README.md says 'for demo convenience', and DEPLOY_LOG.md/DEPLOYMENT.md never mention n8n being installed in production. The 'unencrypted-at-rest mailbox at an unnamed SMTP provider' is hypothetical. (3) The SMS gateway does not exist; sendOtp only writes to the Laravel log, so no guardian phone reaches any SMS processor today. The fingerprint-device point is also inverted: the import reads a file exported by an on-premise device, and the device holds only what the center itself types into it; the repo does not push data to any vendor.

What remains: two live CDN flows (IP+UA to Google Fonts and jsDelivr) on every page, a shared-hosting DB with no documented contract, and no written processor register. For a small single-country Quran center system this is a documentation/hardening item (self-host Bootstrap + fonts is a trivial change), not a medium-severity defect. Corrected severity: low.

```text
Confirmed as cited: n8n/mutqin-daily-attendance-digest.json:38,44,181-185; n8n/attendance-digest.code.js:18,31-41; n8n/README.md:19-21; frontend-html/css/theme.css:6; frontend-html/index.html:8 (33 pages load cdn.jsdelivr.net; 0 hits for integrity=/referrerpolicy/Content-Security-Policy); DEPLOYMENT.md:12; backend/app/Http/Controllers/Api/AuthController.php:216-220 (Log::info only, TODO(SMS) — no gateway exists); backend/DEPLOY_LOG.md:3-11. Corrections: n8n/mutqin-daily-attendance-digest.json:32 apiBase 'http://localhost:9090' and :50 sendTo 'manager@example.com' show a demo template, and no deployment doc references n8n — the digest is not an evidenced production flow. The Referer sent to fonts.googleapis.com / cdn.jsdelivr.net under browsers' default strict-origin-when-cross-origin policy is the origin only, so '/parent/child.html?id=' is not disclosed. AttendanceImportController.php:56-65 only parses an uploaded xlsx from a local device export; the app sends nothing to a fingerprint vendor.
```

</details>

### Parent-teacher message threads are keyed only by student, so a newly assigned teacher (or a re-linked guardian) reads the entire prior private conversation

<a id="message-thread-inherited-by-successor-teacher-or-parent"></a>

`message-thread-inherited-by-successor-teacher-or-parent` · 🟡 medium · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/MessageController.php:26-49`, `backend/app/Http/Controllers/Api/MessageController.php:107-142`, `backend/app/Http/Controllers/Api/StudentController.php:611-690`, `backend/database/migrations/2026_08_22_100000_create_messages_table.php:7-11`, `backend/tests/Feature/MessagingTest.php:29-120`

**Evidence**

```text
MessageController.php:42 authorises the CURRENT teacher (`(int) $student->teacher_id !== (int) $user->id`) and :120-121 loads `Message::where('student_id', $student->id)->latest('id')->limit(100)` regardless of sender_id or who the teacher was when each message was written; the migration comment :8-10 states 'المحادثة مفتاحها student_id'. StudentController::changeTeacher (:611-690) and StudentRequestController transfer approval (:285, :325) reassign teacher_id without touching messages. MessagingTest covers ownership and role gates only; grep 'messages' in ManagerChangeTeacherTest/StudentTransferRequestTest: 0 hits.
```

**Why it matters**

A parent's messages about a child (complaints, family circumstances, health remarks) written in confidence to teacher A are disclosed to teacher B — possibly at another center after a transfer — and a guardian re-linked to the child inherits the previous guardian's exchanges. This breaches purpose limitation and the reasonable expectations of the sender, and there is no oversight route for a manager to investigate a safeguarding complaint (admin is refused at :42-47, managers have no messages route in api.php:53-91).

**Recommendation**

Key threads by (student_id, teacher_id, parent_id) or store the participant ids on each message and filter `where('sender_id', $me)->orWhere('recipient_id', $me)`; on teacher change, close the old thread (read-only for its two participants, archived for the manager) and start a fresh one. Add a manager-only, audited read endpoint for safeguarding review with a stated policy. Add tests for 'successor teacher cannot read predecessor's thread' and 'transfer archives thread'.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

Core claim confirmed by tracing the code. MessageController::resolveStudent (:42) authorises whoever is the student's CURRENT teacher_id; thread() (:120-121) loads Message::where('student_id', ...) with no filter on sender_id or the teacher at time of writing, and threads() (:72-88) likewise. Messages store sender_id/sender_role only (migration :18-20), no recipient or teacher snapshot. Three reassignment paths change teacher_id without touching messages: admin PUT /students/{id} (StudentController :518-527, can also change center_id), manager changeTeacher (:611-690), and transfer approval (StudentRequestController :325 sets center_id+teacher_id to another center). So a successor teacher — including one at a different center after a transfer — reads the parent's full prior thread. No middleware, model scope, or frontend guard mitigates this (frontend cannot; backend is the authority). MessagingTest covers only ownership/role gates (5 tests, none about successor teachers); ManagerChangeTeacherTest and StudentTransferRequestTest have zero references to messages.

Partially refuted pieces: (1) the "re-linked guardian inherits the previous guardian's exchanges" scenario is NOT reachable via the API — parent_id is set only at student creation (store :236-259) and never modified by update() (:518-527 and :546-552 omit parent_id); transfer approval preserves the student row (only center/teacher change). Only a direct DB edit could re-link a guardian. (2) The "no manager oversight route" point is a missing feature/policy gap, not a code defect, and the CLAUDE.md design deliberately keeps admin and manager out of the conversation. Net: the finding is real but narrower than stated — teacher succession only. Medium remains defensible given cross-center disclosure of parent-authored remarks about a child; the guardian half should be dropped from the write-up.

```text
Confirmed: backend/app/Http/Controllers/Api/MessageController.php:42 (current teacher_id check), :72-88 and :115-121 (student_id-only thread queries, no sender/participant filter); backend/database/migrations/2026_08_22_100000_create_messages_table.php:18-20 (only student_id, sender_id, sender_role stored — no recipient/teacher snapshot). Reassignment paths that leave messages untouched: backend/app/Http/Controllers/Api/StudentController.php:518-527 (admin update changes center_id + teacher_id), :660-666 (manager changeTeacher), backend/app/Http/Controllers/Api/StudentRequestController.php:322-329 (transfer approval sets center_id/teacher_id). Tests: backend/tests/Feature/MessagingTest.php:29-120 has no successor-teacher case; grep for 'message' in ManagerChangeTeacherTest.php and StudentTransferRequestTest.php: 0 hits. NOT confirmed (drop from finding): guardian re-link — no API path modifies students.parent_id after creation (StudentController.php:518-527 and :546-552 exclude parent_id; StudentRequestController.php:325 transfer does not touch parent_id).
```

</details>

### No audit trail of who viewed, searched or exported a child's record; PDFs carry no confidentiality marking

<a id="no-access-audit-trail-for-child-records-and-exports"></a>

`no-access-audit-trail-for-child-records-and-exports` · 🟡 medium · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/bootstrap/app.php:14-21`, `backend/app/Http/Controllers/Api/ReportPdfController.php:37-73`, `backend/resources/views/pdf/layout.blade.php:55-57`, `backend/resources/views/pdf/student.blade.php:23-25`, `backend/database/migrations/2026_07_18_120000_add_correction_audit_to_attendances.php:7-12`

**Evidence**

```text
bootstrap/app.php:14-21 registers only the four role aliases — no request/access logging middleware; grep Log:: across backend/app finds 3 statements (two import errors, one OTP). ReportPdfController::render (:37-73) streams the PDF inline with no record of requester/student/time; student.blade.php:25 prints guardian name + phone; layout.blade.php:56 footer is 'تقرير صادر من منصة مُتقِن ... تاريخ الإنشاء' with no 'سري / للاستخدام الداخلي' marking or requester watermark. The only audit fields are write-side (corrected_by/at, status_changed_by/at) and the migration :10-11 explicitly limits them: 'الحالة السابقة لا تُحفَظ ... تدقيق خفيف يكفي'.
```

**Why it matters**

When a parent alleges that their child's data was shared (a leaked at-risk list, a report seen by an ex-spouse, a manager browsing another center's parents), the organisation cannot reconstruct who accessed what — a basic accountability control (GDPR Art. 5(2)/Art. 30-style) and the first thing a regulator or Awqaf inspector asks for. Exported PDFs of minors circulate on WhatsApp without any marking that would deter re-sharing.

**Recommendation**

Add an `access_logs` table (user_id, role, action, subject_type, subject_id, ip, ua, created_at) written by a lightweight middleware for student/parent detail reads, searches (query hash), message thread opens and every PDF/xlsx export; expose it to admin with filters; retain 12 months. Stamp PDFs with 'سري — أُصدر بواسطة {name} {display_code} في {datetime}' and a page footer 'يحوي بيانات قُصّر — لا يُعاد نشره'.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Every cited piece of evidence checks out. backend/bootstrap/app.php:14-21 registers only the four role aliases (admin/teacher/parent/manager) and app/Http/Middleware contains only those four classes — there is no request/access-logging middleware anywhere. A repo-wide grep for access_log/audit_log/activity_log/AccessLog/ActivityLog across app, database, routes, config and tests returns nothing; the only audit-ish tables are password_change_logs (write-side) plus the corrected_by/at and status_changed_by/at columns, and the attendance migration comment (lines 10-11) indeed documents the deliberate 'light audit, no history' decision. Log:: usage is 4 statements, not 3 (the auditor missed InAppNotification.php:60), but all are error/OTP logs — none records a read or export. ReportPdfController::render (37-73) builds the mPDF and returns it inline with no persistence of requester/subject/time, and no watermark or SetWatermark call exists anywhere in app/ or resources/. layout.blade.php:56 footer is exactly the generic 'تقرير صادر من منصة مُتقِن ... تاريخ الإنشاء' with no confidentiality marking, and student.blade.php:25 prints guardian name and phone. Sanctum's personal_access_tokens.last_used_at is not touched by app code and would only give per-token recency, not per-action accountability. The web server access log cannot identify the user (bearer tokens) and is not an application control. Mitigation exists in the form of strong authorization scoping (teacher own students, manager own center via CenterManagerMiddleware/assertManagerScope, parent own children), which prevents most cross-center browsing — but I found that GET /manager/parents/search (StudentController::searchParents, :311-338) is NOT center-scoped: it searches all parent-role users system-wide by phone/name and returns name, phone and email. So the auditor's 'manager browsing another center's parents' scenario is actually possible and unlogged, reinforcing rather than weakening the finding. The GDPR framing is a stretch for a single-country Libyan deployment, but the lack of any read/export accountability trail for minors' data is real. Medium severity is appropriate: it is not an exploitable vulnerability but a missing accountability control that the product's own design comments acknowledge as a conscious trade-off.

```text
backend/bootstrap/app.php:14-21 — only four role aliases, no logging middleware; app/Http/Middleware contains only AdminMiddleware, CenterManagerMiddleware, ParentMiddleware, TeacherMiddleware. Log:: occurrences are 4 (not 3): AttendanceImportController.php:37,326 (errors), AuthController.php:218 (OTP), app/Notifications/InAppNotification.php:60 (warning) — none records reads/exports. ReportPdfController.php:37-73 render() streams mPDF output with no persistence and no SetWatermark; all 7 PDF endpoints (:78-164) go through it. resources/views/pdf/layout.blade.php:55-57 generic footer, no confidentiality marking; student.blade.php:25 prints guardian_name + guardian_phone. database/migrations/2026_07_18_120000_add_correction_audit_to_attendances.php:10-11 documents the 'light audit, no history' decision. No access_logs/audit table or model anywhere in app/, database/, routes/, tests/. Additional: app/Http/Controllers/Api/StudentController.php:311-338 searchParents (route GET /manager/parents/search, manager gate) queries all parent users system-wide (not scoped to the manager's center_id) and returns name/phone/email — an unlogged cross-center parent lookup path that supports the auditor's impact scenario.
```

</details>

### Raw Eloquent models leak fields beyond each role's need: User::$hidden omits id_number/password metadata, teachers receive the child's national ID, managers see full guardian IDs, parents receive teacher notes not shown in their UI

<a id="over-broad-model-serialization-and-role-minimisation-gaps"></a>

`over-broad-model-serialization-and-role-minimisation-gaps` · 🟡 medium · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/app/Models/User.php:43-46`, `backend/app/Http/Controllers/Api/StudentController.php:268-272`, `backend/app/Http/Controllers/Api/StudentController.php:340-364`, `backend/app/Http/Controllers/Api/StudentController.php:412`, `backend/app/Http/Controllers/Api/StudentController.php:769-776`, `backend/app/Http/Controllers/Api/CenterManagerController.php:249`, `backend/app/Http/Controllers/Api/StudentRequestController.php:309`, `frontend-html/teacher/student.html:76`, `frontend-html/manager/parents.html:81`

**Evidence**

```text
User.php:43-46 `$hidden = ['password','remember_token']` only, so any serialized User exposes email, phone, id_number, nationality, password_changed_count and password_last_changed_at. StudentController.php:270 and StudentRequestController.php:309 return `$student->load(['center','teacher','parent'])` (full parent User incl. id_number); :341/:355-362 `show` returns the whole Student model plus three relation sets each `with('teacher')`; :412 teacherDetails hands the teacher `'national_id' => $student->national_id` and teacher/student.html:76 renders '🪪 الرقم الوطني'. CenterManagerController.php:249 paginates parents with full `id_number` and manager/parents.html:81 prints it unmasked. parentStudentDetails :769-776 sends memorization `notes` and attendance `notes` (teacher free text) to the parent although parent/child.html:38-41 never renders them; test-question `mistake` and test `notes` ARE rendered (:47) with no hint in teacher/weekly-tests.html:85 that the text is parent-visible.
```

**Why it matters**

Each unnecessary field widens who can leak it: a teacher's phone screenshot now carries a child's national ID; a manager's exported parent list carries full guardian IDs; internal teacher remarks travel to parent devices (and into any future mobile cache) without the teacher knowing. Data minimisation (GDPR Art. 5(1)(c), Apple 5.1.1(ii)) expects role-shaped DTOs, and the good pattern already exists in AdminUserController.

**Recommendation**

Introduce API Resources (StudentResource, ParentSummaryResource, TeacherPublicResource) and use them everywhere a model is returned; add id_number, nationality_*, password_changed_count, password_last_changed_at, email_verified_at to User::$hidden and expose them only through explicit resources; drop national_id from the teacher payload (teachers do not report to Awqaf); mask id_number to last 4 in manager lists; stop sending memo/attendance notes to parents unless the UI shows them, and label parent-visible fields in teacher forms ('يظهر لولي الأمر'). Extend the AdminUsersListTest pattern (exact key lists) to every role endpoint.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: medium

Every cited line was verified and behaves as described. User::$hidden (User.php:43-46) contains only password/remember_token, so any serialized User carries email, phone, id_number, nationality_*, password_changed_count, password_last_changed_at, email_verified_at. StudentController@store :270 and StudentRequestController :309 return `$student->load(['center','teacher','parent'])` with the full parent User (though these responses only reach admin/manager callers, who already handle id_number). StudentController@show :343-366 (teacher gate, GET /students/{id}) returns the raw Student model — including guardian_name, guardian_phone, national_id and parent_id — plus memorizations/attendances/weeklyTests each with a full teacher User; this contradicts the project's own intent expressed in teacherDetails ('بلا بيانات ولي الأمر') and TeacherStudentDetailsTest::test_details_return_own_student_without_guardian_fields, which only covers /details, not /students/{id}. teacherDetails :412 does hand the teacher national_id and teacher/student.html:76 renders it. CenterManagerController::parents :249 paginates with id_number and manager/parents.html:81 prints it unmasked (though this is by design: the manager links guardians by id_number via managerSearchParents and ManagerParentsTest asserts the value). parentStudentDetails :769/:788 sends memorization and attendance `notes` to the parent while parent/child.html:38-41 never renders them; test `notes` and question `mistake` are sent (:805,:812) and rendered (child.html:47) with no parent-visibility hint in teacher/weekly-tests.html:84-85. No middleware, model $hidden, or resource layer mitigates this (Student/Memorization/Attendance/WeeklyTest define no $hidden). Only AdminUsersListTest asserts an exact key list, and only for /admin/users. Some points are weaker than framed (store/approve leak goes to privileged roles only; manager id_number display is an intentional workflow key), so the finding is slightly overstated but the core data-minimisation gap — especially the teacher `show` endpoint exposing guardian phone/national id and the unrendered notes to parents — is real. Medium remains appropriate.

```text
backend/app/Http/Controllers/Api/StudentController.php:343-366 — teacher-gated `show` returns the raw Student model (guardian_name, guardian_phone, national_id, parent_id) plus three relation sets each with full teacher User models; contrast :384-416 teacherDetails which deliberately strips guardian fields, and backend/tests/Feature/TeacherStudentDetailsTest.php:42-69 which asserts 'guardian'/'parent' absent only for /students/{id}/details, not /students/{id}. StudentController.php:769 and :788 send memorization/attendance `notes` to parents; frontend-html/parent/child.html:38-41 never renders them; :805/:812 send test notes/mistake, rendered at child.html:47. Mitigating context: store (:270) and approve (StudentRequestController.php:309) responses reach admin/manager only; manager parents list (CenterManagerController.php:249) exposes id_number intentionally as the guardian linking key (see StudentController.php:279-309 managerSearchParents and tests/Feature/ManagerParentsTest.php:54). No $hidden on Student/Memorization/Attendance/WeeklyTest models; only AdminUsersListTest.php:44-50 enforces an exact key list.
```

</details>

### National IDs, phones and message bodies sit in plaintext on a shared cPanel MySQL with unencrypted mysqldump backups copied to a developer laptop

<a id="plaintext-identifiers-shared-hosting-unencrypted-backups"></a>

`plaintext-identifiers-shared-hosting-unencrypted-backups` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort L (1–2 weeks)

**Files:** `backend/app/Models/User.php:48-58`, `backend/app/Models/Student.php:30-35`, `DEPLOYMENT.md:15`, `backend/DEPLOY_LOG.md:43`, `backend/DEPLOY_LOG.md:52`, `backend/.env.production.example:2-6`

**Evidence**

```text
grep "'encrypted'|Crypt::|encrypt(" backend/app: 0 hits — no field-level encryption; casts in User.php:48-58 and Student.php:30-35 are plain. DEPLOYMENT.md:15 prescribes `mysqldump -u root mutqin_db > backup-$(date +%F).sql` with no encryption, location or retention; DEPLOY_LOG.md:43 and :52 store full dumps at `C:\mutqin-deploy\*.sql` on the developer's Windows machine; .env.production.example:2 describes the host as 'استضافة مشتركة: لا SSH، لا CLI' (shared tenancy, phpMyAdmin access via cPanel).
```

**Why it matters**

On shared hosting the practical attack surface is the control panel, phpMyAdmin and file-level backups, none of which are covered by the application's role checks; a stolen dump or a compromised cPanel session yields every guardian's national ID and every private message in the clear. Backups on a personal laptop are a second uncontrolled copy of minors' data with no retention. Encryption at rest of the direct identifiers and encrypted, expiring backups are the standard compensating controls when the infrastructure tier cannot be hardened.

**Recommendation**

Encrypt id_number, national_id, phone and messages.body at the application layer (Laravel `encrypted` cast) with a blind-index column (HMAC) for the existing uniqueness/exact-match lookups; keep display_code as the operational key. Define backups: automated by the host or a scheduled dump piped through gpg/age, stored off the laptop in a controlled location, 30-day retention, restore test quarterly; delete the existing plaintext dumps in C:\mutqin-deploy once superseded. Plan a migration to a VPS/managed DB with a contract before onboarding dozens of centers.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 72% · corrected severity: low

Cited evidence verified: User.php casts (lines 48-58) and Student.php $casts (30-35) have no `encrypted` cast; `grep -rE "'encrypted'|Crypt::|encrypt\("` over backend/app returns 0 hits, and `messages.body` is a plain `text` column (create_messages_table.php:21) actually served by MessageController routes in api.php:47-49. DEPLOYMENT.md:15 does prescribe an unencrypted `mysqldump` with no location/retention; DEPLOY_LOG.md confirms shared cPanel/phpMyAdmin hosting (lines 3-8, Libyan Spider, SSH disabled) and dumps under C:\mutqin-deploy. No mitigation exists elsewhere (no middleware, DB constraint or test addresses at-rest encryption or backups). So the finding is factually correct as an architectural gap. However two parts are overstated: (1) the specific laptop dumps cited (DEPLOY_LOG.md:43 and :52) are, per the log itself, a 'clean' production seed containing only the athman index + the admin account, and a demo-seeder dump (fictional LibyanDataSeeder data) — neither contains real minors' or guardians' PII, so 'a second uncontrolled copy of minors' data' is not true of those files today; (2) the log shows uploads still 'pending', i.e. no real population data confirmed in production yet. Also, application-layer encryption of phone/national_id would break the existing in-MySQL normalized search (ArabicText::sqlNormalize, PhoneNumber lookups in ParentResolver) and DB unique constraints unless a blind index is added — the recommendation acknowledges this but it is a substantial change. Given a small single-country deployment with no real data yet, a rated 'medium' is defensible but the concrete backup-on-laptop claim should be downgraded to a policy/process gap; overall severity low-to-medium; I set low with the note that it rises to medium once real center data is loaded.

```text
Confirmed: backend/app/Models/User.php:48-58 and backend/app/Models/Student.php:30-35 — no `encrypted` casts; grep for 'encrypted'|Crypt::|encrypt( in backend/app = 0 hits. backend/database/migrations/2026_08_22_100000_create_messages_table.php:21 `$table->text('body')` plain; wired at backend/routes/api.php:47-49. DEPLOYMENT.md:15 unencrypted mysqldump example, no retention. backend/DEPLOY_LOG.md:3-8 shared cPanel/phpMyAdmin, SSH disabled. Correction: DEPLOY_LOG.md:41-43 describes mutqin-clean-2026-09-11.sql as containing only athman index + admin account (no students/parents), and :50-52 describes mutqin-demo-2026-09-10-clean.sql as seeder demo data after migrate:fresh --seed — these laptop dumps hold no real minors' PII; upload status 'pending' (lines 46, 55).
```

</details>

### Google Fonts and jsDelivr receive IP/UA/referrer for every parent, teacher and manager page view; no SRI, CSP or referrer policy

<a id="third-party-cdn-fonts-leak-parent-browsing"></a>

`third-party-cdn-fonts-leak-parent-browsing` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/css/theme.css:6`, `frontend-html/index.html:8`, `frontend-html/parent/child.html:7`, `frontend-html/parent/messages.html:7`

**Evidence**

```text
theme.css:6 `@import url('https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Cairo:...')` and theme.css is included by 33/33 HTML pages; every page also loads `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css` (33 occurrences). grep referrerpolicy|Content-Security-Policy|integrity= in frontend-html: 0 hits, so the default referrer (full URL, e.g. /parent/child.html?id=17) is sent to both CDNs on each navigation.
```

**Why it matters**

Third parties outside Libya learn which IPs use a Quran-center parent app and when, and can correlate child ids in URLs — a disclosure with no processor agreement that must be declared in store privacy forms; EU courts have treated Google Fonts loading as an unlawful transfer of IP data. For the Flutter app the same fonts/CSS decisions recur (bundle assets, no remote fonts).

**Recommendation**

Self-host Amiri/Cairo (woff2) and bootstrap.rtl.min.css under frontend-html/css/ and fonts/; add `<meta name="referrer" content="same-origin">` and a CSP header from backend/public/.htaccess or the frontend .htaccess; move child ids out of query strings where feasible. Bundle fonts in the Flutter app.

## Measured facts

| Metric | Value |
|---|---|
| Privacy/consent/terms artefacts in repo (grep خصوصية\|privacy\|consent\|terms across frontend, backend, docs) | 0 hits; 0 consent columns in 35 migrations; 0 policy pages among 33 HTML pages |
| Direct PII columns about the child (students) | 9 (name, birth_date, phone, national_id, nationality_type, nationality_name, age, guardian_name, guardian_phone) + former_teacher_name |
| Direct PII columns about guardians/staff (users) | 6 (name, email, phone, nationality_type, nationality_name, id_number) + password hash, password_changed_count/last_changed_at |
| Free-text fields that can hold remarks about a minor | 8 (attendances.notes, memorizations.notes, weekly_tests.notes, weekly_test_questions.mistake, messages.body <=2000 chars, student_requests.admin_note, revisions.notes, tajweed_evaluations.notes) + notifications.data copies of message excerpts |
| Tables / migrations / API route lines / controllers | 24 tables / 35 migrations / 94 route lines / 19 controllers (4,926 LOC) |
| Erasure or anonymisation endpoints | 0 (only memorization destroy and token/OTP deletes); scheduled retention jobs: 0 (routes/console.php has only 'inspire') |
| Manager parent-search exposure | 3-digit prefix -> up to 10 full national IDs + names per call, system-wide, no route throttle (framework default only) |
| Log statements containing PII/secrets | 1 of 3 (AuthController.php:218 OTP + phone at info level) |
| Public landing-page photos of children | 11 files, 11/11 byte-identical (md5) to raw downloads in 'Home photos/', 0 consent/attribution records; 2 files verified as close-up identifiable child faces |
| Third-party calls per page view | 2 (fonts.googleapis.com via theme.css:6 on 33/33 pages; cdn.jsdelivr.net CSS on 33/33 pages); external JS: 0; SRI/CSP/referrer-policy: 0 |
| Repository visibility and disclosed operational metadata | public (GitHub API private=false); admin login, universal demo password ([redacted-demo-password]) for a dataset logged as deployed, cPanel user, DB name and host present in tracked files |
| Processors/data flows without a documented agreement | 6 (Libyan Spider cPanel host, Google Fonts, jsDelivr, n8n+SMTP digest, fingerprint device vendor, planned SMS gateway) + Awqaf reporting recipient |
| Feature tests | 38 files / 173 test methods (CLAUDE.md:25 still says 20); tests asserting field minimisation or non-leakage: 4 (AdminUsersListTest, ManagerAddStudentGuardianTest, ManagerTeacherPerformanceTest, OtpResetTest) |
| Session/credential lifetimes | Sanctum token 10,080 min (7 days) in localStorage, no idle logout; OTP 10 min / 5 attempts; log retention 14 days (daily channel) |
| Field-level encryption at rest | 0 encrypted casts; backups: unencrypted mysqldump, copies on developer laptop (DEPLOY_LOG.md:43,52) |

## Auditor notes

ENVIRONMENT NOTE: C:\\xampp\\php\\php.exe does not exist on this machine and backend/vendor is absent, so no PHP was executed; all findings are from static reading, git metadata, md5 hashes and one anonymous GitHub API GET. DOC DRIFT observed: CLAUDE.md:25 '20 feature-test files' vs 38 actual (+2 unit); CLAUDE.md does not describe MessageController/messages, AdminUserController, manager/parents, the PWA manifest, n8n or .cpanel.yml; migration 2026_06_21_130000:10-11 says national_id 'يُستخدم أيضاً كرقم تسجيل البصمة' but AttendanceImportController.php:164 keys on display_code (privacy-positive drift); دليل-محتوى-الصفحات.md still documents Blade pages and a teacher 'add student' flow that no longer exist.\n\nFIELD-LEVEL DATA MAP (field -> who receives it -> needed?):\n- students.name: parent(own), teacher(own), manager(center), admin, all PDFs, n8n absence email, parent notifications -> needed except n8n email (send counts/links).\n- students.display_code: staff, fingerprint device, n8n -> needed (pseudonymous key, good).\n- students.national_id: admin (index, students.html:381), manager (index, transfer snapshot present():435), teacher (show :341, teacherDetails :412, teacher/student.html:76) -> NOT needed by teacher; not sent to parent; not in PDFs (good).\n- students.phone (child's own phone): teacher/manager/admin, parent(own) -> questionable necessity for a minor; consider guardian phone only.\n- students.guardian_name/guardian_phone (display-only copies): teacher (students.html:86-87,122), manager, admin, parent (child.html:82), student PDF (student.blade.php:25) -> needed for teacher contact; duplicate of users.phone creates two copies to erase.\n- students.nationality_type/name, age, enrollment_date, is_active, former_teacher_name: staff + parent (age, dates) -> ok.\n- attendances.status/time/notes: teacher, manager review, admin, parent (status + notes via API :787-799, notes not rendered), n8n (absent names), PDFs -> notes to parent = over-fetch.\n- memorizations.quality/notes/surah/pages: teacher, admin, parent (quality+notes :769-776; notes not rendered), at-risk profiling -> notes over-fetch.\n- weekly_tests.result/notes + questions.mistake: teacher, parent (rendered child.html:47), PDF -> ok but teachers are not told it is parent-visible.\n- messages.body: parent + CURRENT teacher incl. successors (MessageController:120), 80-char excerpt into recipient's notifications (:181-189) -> successor access not needed.\n- 'at-risk' label + reason: admin/manager reports and PDFs (at-risk.blade.php) -> automated profiling of minors; document purpose.\n- users(parent).name: teacher (messages other_name), manager (parents list), admin, any manager via search -> search exposure excessive.\n- users(parent).phone: manager (parents list :249), admin (users list, parents/search :332), teacher (via students.guardian_phone), OTP log -> ok except log.\n- users(parent).email (generated login): admin, demo-accounts endpoint (debug) -> ok.\n- users(parent).id_number: manager list (full, :249; manager/parents.html:81), manager search (system-wide prefix :298), admin store response (`load('parent')` :270), transfer approval response (:309); excluded from /admin/users by design -> mask + exact-match only.\n- users(parent).password: [redacted] by staff at creation -> replace with activation flow.\n- users(teacher/manager).email/phone/type/password_changed_*: admin, manager (own center), via Student->teacher relation -> hide password metadata.\n- sessions.ip_address/user_agent: only if SESSION_DRIVER=database (prod uses file).\n\nRETENTION/ERASURE DECISION (reconciling never-delete with store mandates): keep row-level 'no physical delete' for operational integrity and Awqaf statistics, but add pseudonymise-on-request and pseudonymise-on-schedule as described in finding no-erasure-or-retention-policy-never-delete; proposed schedule — personal_access_tokens: prune expired daily; otp_resets: purge at expiry (hourly); notifications: 90 days; messages: archive on teacher change, tombstone on pseudonymisation, purge 24 months after last message; password_change_logs: 24 months; access logs: 12 months; logs: 14 days (already); backups: 30 days encrypted; students: pseudonymise on guardian request (30-day SLA) or 2 years after last activity/withdrawal; users(parent): pseudonymise when no linked active child for 12 months or on request; users(teacher/manager): pseudonymise 24 months after deactivation (keep display_code + former_teacher_name for history). Public deletion-request URL + in-app 'طلب حذف بياناتي' for the store forms.\n\nPROCESSORS REQUIRING AGREEMENTS: (1) Libyan Spider (cPanel shared hosting, DB [redacted-db-name], logs, .env) — hosting contract with confidentiality/breach/deletion terms; (2) planned SMS gateway (Libyana/Madar) — phone + OTP; (3) SMTP provider used by n8n (unnamed) + the n8n instance operator — absent children's names daily; (4) Google (fonts.googleapis/gstatic) — IP/UA/referrer of every page view — remove; (5) jsDelivr/Fastly/Cloudflare — same — remove; (6) fingerprint device/software vendor — names + S-codes + timestamps, biometric templates on device (outside app boundary but inside the program); (7) GitHub (public repo hosting code, photos, ops metadata); (8) Ministry of Awqaf as recipient of national-ID-based reports (Law 4/1990 public-body context) — data-sharing memorandum; (9) for Flutter: Apple/Google distribution, FCM/APNs if push, any crash/analytics SDK.\n\nLEGAL FRAME (benchmark, not legal advice): Libya has no comprehensive data-protection statute; Law No. 4/1990 on the National Information System imposes confidentiality/accuracy duties on information handled by public and quasi-public bodies (Awqaf-supervised centers); Cybercrime Law No. 5/2022 criminalises unauthorised access to information systems and the publication/disclosure of private data and images without consent (relevant to the gallery and to any leak); Child Protection Law No. 5/1997 sets general welfare duties; the 2011 Constitutional Declaration protects private life and correspondence. Because the mobile app will be reachable by diaspora users in EU/UK/Gulf stores, GDPR-style duties (Arts. 5, 6, 8, 12-17, 28, 30, 33-35) are the de-facto benchmark and Apple/Google policies are the enforced gate.\n\nADDITIONAL OBSERVATIONS not raised as separate findings: PWA on personal phones with 7-day localStorage tokens and no idle logout (auth.js:9-10, config.js:28-44) — mitigated by immediate token revocation on deactivation; transfer requests move a child between centers with no guardian notice or consent (StudentRequestController approve path notifies only the requester); students may be created with guardian_mode=none, leaving a minor's record with no responsible adult linked (StudentController.php:170-175); teachers' remarks (test notes/mistake) are parent-visible with no UI hint; n8n creates a fresh token every run with no logout; the at-risk report is automated profiling of minors that should be named in the notice; `students.birth_date` is vestigial while `age` is a static integer that drifts (accuracy duty); the login page's demo panel is stripped only by manual instruction (DEPLOYMENT.md:14); DEPLOY_LOG.md:45 records the admin password being delivered in a chat conversation (credential-handling procedure needed); backend/attendance_test.xlsx fixture contains only fictitious names (checked) and screenshots/ are of synthetic demo data.
