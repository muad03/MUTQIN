# Web App UI/UX (role dashboards & pages)

[← Enterprise Audit](../enterprise-audit.md)

**Score 63 / 100** — Needs real work · maturity **L3** · weight 6%

The logged-in UI is visually coherent and brand-consistent (verified in 11+ screenshots and theme.css tokens/button system), and the list pages follow a genuinely solid pattern: server-side pagination with load-more, 300 ms debounced unified search, stale-response guards (fetchSeq), inline empty/error rows, Arabic consequence-explaining confirmations for every status toggle, and 422 error mapping to fields in UI.formModal. The responsive shell (off-canvas drawer, bottom nav, bottom-sheet modals, 16px inputs, safe-area) and local-date/Western-digit handling are above average for a vanilla-JS app. Against an enterprise level-5 bar it falls short on: accessibility (0 role= attributes, no dialog semantics, no focus management, no ESC, 4/68 labels associated, toasts without aria-live, mouse-only autocomplete), colour contrast (secondary text 2.23:1 and 3.45:1), verified horizontal overflow at 375px on 4 pages, a parent experience that omits the product's headline KPI (juz progress), two teacher list pages silently truncated to the first 15 records, no skeletons/retry on 8 detail/dashboard pages, no sortable tables, no self-service profile for managers/parents, and heavy duplication (661 inline style attributes, 9 hand-rolled modals). Functional and polished-looking, but needs real work before scaling to dozens of centers and serving as the reference for a mobile app.

## What is already strong

- Coherent design system: brand tokens and a documented button system with focus rings, disabled/loading states, >=40px touch sizes and prefers-reduced-motion support (frontend-html/css/theme.css:8-22, :31-138 'منظومة الأزرار ... 5 أنواع × 6 حالات × 3 مقاسات', :63 ':focus-visible{...box-shadow:var(--mq-focus-ring)}', :134-138 '@media (prefers-reduced-motion:reduce)').
- List pages use server-side pagination + load-more, 300 ms debounced unified search and stale-response guards — e.g. frontend-html/admin/teachers.html:187 'let page = 1, lastPage = 1, total = 0, loadedCount = 0, fetchSeq = 0;', :234 'if (seq !== fetchSeq) return; // ردود متأخرة لا تكتب فوق الأحدث', :264 counter 'عرض ${loadedCount} من ${total}'. Same pattern in admin/students, admin/users, manager/students, manager/teachers, manager/parents, manager/attendance-review, admin/center, parent/child (9 pages).
- Destructive/status actions use an Arabic, consequence-explaining confirm dialog rather than a bare prompt — frontend-html/admin/teachers.html:201-210 ('لن يستطيع تسجيل الدخول، وتُبطَل جلساته فوراً' ... 'تبقى كل بياناته وسجلّاته وطلابه كما هي'); admin/managers.html:135-143 warns the center stays without an active manager; admin/centers.html:92-101.
- Validation feedback: 422 errors are mapped to per-field boxes with Arabic text — frontend-html/js/ui.js:181-188 setErrors(...) + :200-203 'if (err.errors && Object.keys(err.errors).length) setErrors(err.errors); toast(err.message ...)'; required markers rendered at ui.js:155; bespoke forms replicate it (admin/students.html:334, manager/teachers.html:232). Submit buttons are disabled with a spinner during save (ui.js:195-206 'btn.classList.add("is-loading")').
- Responsive shell is real, not nominal: off-canvas drawer + overlay, mobile bottom nav with the role's first 4 destinations, modals become bottom sheets, 16px inputs to stop iOS zoom, safe-area insets — frontend-html/js/layout.js:139-186, css/theme.css:293-372 ('div[style*="position:fixed"][style*="inset:0"] > .mq-card { ... border-radius:22px 22px 0 0 ...}', '.mq-input ... font-size:16px').
- Dates/numbers: Western digits and local dates — UI.fmtDate uses 'ar-LY' (js/ui.js:126) and rendered '14‏/09‏/2026' in the browser check; UI.todayStr() avoids the UTC-midnight bug (ui.js:128-134); the topbar date renders 'الاثنين، 14 سبتمبر 2026'.
- Role leakage is absent: navigation is generated from a per-role NAV map (js/layout.js:13-48), every page calls Auth.requireAuth([role]) and redirects other roles to their own dashboard (js/auth.js:185-197), and the API enforces the dual role+ability gate; no admin/teacher/parent link appears in another role's menu.
- Teacher daily workflow has thoughtful touches: attendance pills with 'الكل حاضر/الكل غائب' shortcuts and a 409 conflict flow that lists per-student changes before re-submitting with confirm=true (frontend-html/teacher/attendance.html:63-85); weekly-test entry with athman autocomplete (ui.js:303-347, screenshot 27) showing surah/hizb/thumn/page on select.
- Manager panel is operationally rich: in-page center/teacher/student reports with drill-down and at-risk table (frontend-html/manager/reports.html), fingerprint import with drag-drop and a summary modal that surfaces name mismatches and rejected rows (manager/attendance.html:83-124, ui.js:236-298), attendance correction with audit badge (manager/attendance-review.html:86-92, :121-165), and contextual empty states (manager/parents.html:88-96).
- In-app notifications with unread badge, mark-read, deep links to the right page for the role, and 60 s polling that does not redraw an open dropdown (js/layout.js:188-258); backend links resolve to existing pages (parent/child.html?id=, manager/requests.html, teacher|parent/messages.html?student=).
- PWA basics present: manifest with lang/dir/theme colours injected on every page from config.js (frontend-html/js/config.js:28-44, manifest.webmanifest).

## Level-5 target state

Every role has a task-first information architecture with actionable KPIs (each tile links to its filtered list), a single shared component layer (form modal, sheet, table with server sort/filter/pagination, skeleton, error+retry, confirm) used by all 30 pages with zero page-level modal reimplementation, and design tokens that pass WCAG AA and are shared verbatim with the Flutter theme. All dialogs, tables and autocompletes are keyboard- and screen-reader-operable, verified by an axe-core + Playwright viewport suite (375/768/1280, RTL) in CI. Parents see memorization progress and can message the teacher; teachers record attendance/memorization/tests in under 30 seconds per student on a phone; managers and parents manage their own account; the PWA shell works offline and loads without third-party blocking resources.

## What the Flutter team must know

Replicate from the web (it is the de-facto spec): the per-role NAV map in js/layout.js:13-48 as the source of truth for tabs/drawer (but fix teacher priorities first); the API envelope contract and 422 field-error mapping (js/api.js:109-116, ui.js:181-188) — errors arrive keyed by field with Arabic messages, so Flutter forms must map errors[field][0] under each input; status vocabularies are Arabic string literals in payloads (result 'ناجح'/'راسب', teacher type 'محفظ أساسي'/'محفظ معاون') while attendance/quality are English keys (present/absent/late, excellent/good/average/weak) — build one badge/colour mapper mirroring ui.js:89-113 and theme.css badge colours; Laravel pagination shape {data,current_page,last_page,total} everywhere with ?all=1 for pick-lists and, on /parent/students/{id}, three independent page params (memo_page/att_page/tests_page); Western digits with ar-LY date formatting and local-date 'today' (never UTC); the 409 attendance conflict flow (resend with confirm:true after listing conflicts); the display-code preview → actual-code toast pattern on student/teacher creation (next-code endpoints are previews only); guardian modes (new/existing/none) which differ between admin (/parents/search by name or phone, guardian_email) and manager (/manager/parents/search by national id, parent_id_number) — align these before building one mobile form; athman autocomplete via /athman/search?q= with 300 ms debounce, min 2 chars, free text allowed; notification 'link' values are web paths ('parent/child.html?id=', 'manager/requests.html', 'teacher/messages.html?student=') that Flutter must translate to routes; the consequence-explaining confirmation copy for deactivations. Improve rather than copy: build Semantics/focus/labels from day one; implement a tri-state attendance register with a live tally; paginate memorization and weekly-tests lists; show a juz progress bar on parent screens (requires the new API field); add per-role account screens (requires role-agnostic /me endpoints); use the corrected contrast tokens; use searchable sheets instead of long selects (surah, transfer student); store the Sanctum token in secure storage and treat 401 as a global logout like api.js:95-102. Screenshots in /screenshots are from the June initial commit and predate the current UI (delete buttons, admin upload button and the juz-progress table no longer exist) — do not use them as the mobile reference; use the live pages.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [Parent pages never show memorization progress (juz X/30) — the product's headline KPI](#parent-no-progress-kpi) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Center managers and parents have no profile page and no API to change password/phone](#self-service-profile-missing-manager-parent) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Accessibility is essentially unimplemented: no dialog semantics, focus management, ESC, label association, live regions or icon labels](#a11y-dialogs-focus-labels) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | M |
| [Teacher memorization and weekly-tests lists silently show only the first 15 records (paginated API, no load-more)](#teacher-lists-truncated-first-page) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | S |
| [Secondary text, hints, table headers and bottom-nav labels fail WCAG AA contrast](#contrast-below-aa) | 🟡 medium | ✅ confirmed | NOW | S |
| [Four pages hard-code a 2-column grid outside .mq-card, causing horizontal scroll at phone widths](#mobile-2col-grid-overflow) | 🟡 medium | ✅ confirmed | NEXT | S |
| [Dashboards and detail pages render nothing until the API answers and show only a 3-second toast on failure; no skeletons anywhere](#dashboards-blank-no-loading-no-retry) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Attendance marking pre-selects 'حاضر' for every unrecorded student with no 'not marked' state](#attendance-default-present-tristate) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Memorization entry asks for hand-typed juz/pages, uses a 114-option plain select, and the class-wide juz progress table was removed](#teacher-memorization-workflow-gaps) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Nine hand-rolled overlay modals and 661 inline style attributes duplicate the shared UI layer](#duplicated-modals-inline-styles) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | LATER | L |
| [Admin has no student detail view and no in-page analytics — only PDFs; manager PDFs are locked to the current month](#admin-cannot-drill-into-student-reports-pdf-only) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | LATER | M |
| [Teacher mobile bottom nav wastes a prime slot on 'ملفّي الشخصي' while memorization and tests hide behind 'المزيد'](#bottom-nav-priority-teacher) | ⚪ low | ℹ️ informational | NEXT | S |
| [Assorted consistency gaps: stat-card styles, date formats, missing confirmation on approve, double-submit on 4 forms, dual student-detail paths](#inconsistent-patterns-confirmations-dates) | ⚪ low | ℹ️ informational | NEXT | S |
| [No sortable columns; five pages search client-side over unpaginated or partial lists; admin students lacks center/teacher filters](#no-sorting-client-search-filters) | ⚪ low | ℹ️ informational | LATER | M |
| [Bootstrap RTL CSS is loaded on all 30 pages but unused, fonts are @import-blocked, and the PWA has no offline shell](#perf-unused-bootstrap-fonts-no-offline) | ⚪ low | ℹ️ informational | LATER | M |

### Parent pages never show memorization progress (juz X/30) — the product's headline KPI

<a id="parent-no-progress-kpi"></a>

`parent-no-progress-kpi` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `frontend-html/parent/dashboard.html:44-53`, `frontend-html/parent/child.html:66-81`, `backend/app/Http/Controllers/Api/StudentController.php:693-735`, `backend/app/Http/Controllers/Api/StudentController.php:820-850`

**Evidence**

```text
parent/dashboard.html:46-48 renders only '📖 آخر سورة' = c.last_surah and '📋 نسبة الحضور'; parent/child.html:66-72 renders an attendance summary only ('ملخص الحضور'). Backend parentChildren returns 'last_surah' => $lastMemo ? $lastMemo->surah_name : '--' (StudentController.php:731) and parentShow returns student/memorizations/attendances/weekly_tests/tests_summary/attendance_summary (lines 820-850) with no 'progress' key, whereas teacherDetails computes \App\Support\SurahReference::progress(...) (line 403) and manager/reports/student shows 'مقدار القرآن المُتمّ ${pr.completed_juz} / 30' (manager/reports.html:222).
```

**Why it matters**

Parents are the largest user base of a public mobile app; the read-only parent experience currently answers 'did he attend' but not 'how much Quran has he memorized', which is the reason the center exists. The Flutter parent app cannot show progress without an API change.

**Recommendation**

Add a 'progress' object (completed_juz, completion_percent, reached_juz, last_surah, khatmat) to /parent/children and /parent/students/{id} by reusing SurahReference::progress (same shape as /students/{id}/details), then render a juz progress bar/card on parent/dashboard.html and parent/child.html. Define this payload before the Flutter parent screens are built.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual claims check out. StudentController::parentChildren (backend/app/Http/Controllers/Api/StudentController.php:693-740) returns only id/name/age/is_active/center/center_city/teacher_name/last_surah/last_memo_date/attendance_percent/total_attendance_days — no progress object. parentShow (lines ~816-858) returns student/memorizations/attendances/weekly_tests/tests_summary/attendance_summary — again no progress key. SurahReference::progress is called only in teacherDetails (line 403, exposing 'progress' at line 426), which is behind the teacher gate, so a parent token cannot reach it. On the frontend, grep for progress/completed_juz/juz in frontend-html/parent/*.html returns nothing; parent/dashboard.html:46-53 renders only "آخر سورة" and "نسبة الحضور", and parent/child.html:66-72 renders only an attendance summary (a paginated raw memorization log "سجل الحفظ" follows, so parents can see individual surah entries but no aggregate juz/30 figure). No feature test covers parent progress. No mitigation exists elsewhere. However, the severity is overstated: this is a product/UX feature gap (missing aggregate KPI), not a defect — nothing is wrong, insecure, or misleading; the parent already sees the last surah and the full memorization log. The Flutter-app impact is speculative and outside this repo. A medium rating (worth doing before any mobile-client payload is frozen) is more appropriate than high.

```text
backend/app/Http/Controllers/Api/StudentController.php:724-736 (parentChildren payload: last_surah/last_memo_date/attendance_percent only); StudentController.php:816-858 (parentShow payload keys: student, memorizations, attendances, weekly_tests, tests_summary, attendance_summary — no 'progress'); StudentController.php:403,426 (SurahReference::progress used only in teacher-gated teacherDetails); frontend-html/parent/dashboard.html:46-53 and frontend-html/parent/child.html:66-72 (no juz progress rendering; child.html does show a paginated raw memorization log table at lines ~76-81).
```

</details>

### Center managers and parents have no profile page and no API to change password/phone

<a id="self-service-profile-missing-manager-parent"></a>

`self-service-profile-missing-manager-parent` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `frontend-html/js/layout.js:34-47`, `backend/routes/api.php:133-137`, `frontend-html/admin/profile.html:1`, `frontend-html/teacher/profile.html:1`

**Evidence**

```text
NAV.center_manager and NAV.parent (layout.js:34-47) contain no 'profile' entry; only admin/profile.html and teacher/profile.html exist. routes/api.php:133-137 puts GET /profile, PUT /profile/phone, POST /profile/password inside Route::middleware('teacher') — a manager token (ability 'manager') or parent token (ability 'parent') fails that gate. grep 'manager/profile|parent/profile' in routes → none. The only alternative is the OTP flow, which has no SMS gateway (CLAUDE.md: 'No SMS gateway ... dev_otp').
```

**Why it matters**

Two of the four roles cannot change an admin-assigned initial password or fix their phone number; at dozens of centers this becomes an admin support burden and a security smell (initial passwords never rotated). A Flutter app for all four roles needs a 'my account' screen per role backed by an API that does not exist yet.

**Recommendation**

Expose role-agnostic self-profile endpoints under the generic auth:sanctum group (e.g. /me, /me/password, /me/phone reusing TeacherProfileController logic and User::recordPasswordChange('self')), add manager/profile.html and parent/profile.html (or one shared page), and add 'ملفّي الشخصي' to both NAV lists.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 92% · corrected severity: medium

Evidence verified as quoted. frontend-html/js/layout.js NAV.center_manager (lines 34-43) and NAV.parent (44-47) have no 'profile' entry; only admin/profile.html and teacher/profile.html exist (no manager/profile.html or parent/profile.html). backend/routes/api.php:133-137 places GET /profile, PUT /profile/phone, POST /profile/password under Route::middleware('teacher'), whose gate requires role in [teacher, admin] AND tokenCan('*'); manager tokens carry ability ['manager'] and parent tokens ['parent'], so they are rejected (tests/Feature/TeacherProfileTest.php:55-56 explicitly asserts a parent gets 403 on /api/profile). No other authenticated password/phone endpoint exists in api.php for these roles. The finding is actually slightly understated: AuthController::forgotPasswordRequest (line 132) restricts the OTP flow to whereIn('role', ['parent','teacher']), so center managers have NO self-service password path at all — not even the OTP fallback; parents have only the OTP path, whose sendOtp() (line 216-219) merely logs the code (no gateway, dev_otp only in local). Mitigation present: admins can set a manager's password via PUT /admin/managers/{id} (ManagerManagementController:126-128) and set a guardian password at student creation, so accounts are not stranded — it is an admin-burden/UX gap and a weak-credential-hygiene smell, not an exploitable vulnerability. Severity 'high' overstates a missing feature with an admin-side workaround; medium is appropriate.

```text
frontend-html/js/layout.js:34-47 (no 'profile' in center_manager/parent NAV); backend/routes/api.php:133-137 (profile routes inside middleware('teacher')); backend/tests/Feature/TeacherProfileTest.php:55-56 (parent → 403 on /api/profile, confirming the gate); backend/app/Http/Controllers/Api/AuthController.php:132 (OTP reset limited to whereIn('role',['parent','teacher']) — center managers excluded from OTP too); AuthController.php:216-219 (sendOtp only logs; no SMS). Mitigation: ManagerManagementController.php:126-128 (admin can set manager password via PUT /admin/managers/{id}).
```

</details>

### Accessibility is essentially unimplemented: no dialog semantics, focus management, ESC, label association, live regions or icon labels

<a id="a11y-dialogs-focus-labels"></a>

`a11y-dialogs-focus-labels` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/js/ui.js:139-212`, `frontend-html/js/ui.js:9-29`, `frontend-html/js/ui.js:443-447`, `frontend-html/js/ui.js:303-347`, `frontend-html/js/layout.js:136`, `frontend-html/parent/dashboard.html:36`, `frontend-html/parent/dashboard.html:55`

**Evidence**

```text
Live check of UI.formModal in the browser: {activeTag:'BODY', activeInsideModal:false, roleDialog:null, ariaModal:null, labelsWithFor:0, inputsWithId:0, requiredAttrs:0, closeBtnLabel:null, closedOnEscape:false, toastHostAriaLive:null, actionBtnHasLabel:false}. Repo-wide: grep 'role="' in frontend-html → 0; 'aria-' → 10 occurrences, all in index.html/login/landing.js, none in the 30 role pages; '<label for=' 4 of 68 labels. Athman autocomplete items only bind 'mousedown' (ui.js:331) — no arrow/Enter selection. Native confirm() still used for logout (layout.js:136) and memorization delete (ui.js:382). parent/dashboard.html:36 uses a <div onclick="location.href=..."> card with a <div class="mq-btn"> as the CTA (line 55) — not focusable, not a link.
```

**Why it matters**

Fails WCAG 2.1 AA and any public-sector procurement checklist (Awqaf reporting is a stated use); keyboard users cannot dismiss or navigate modals; screen-reader users get unlabeled icon buttons and silent toasts. The same omissions will be copied into the Flutter app unless semantics are specified now.

**Recommendation**

In ui.js: add role="dialog" aria-modal="true" aria-labelledby, move focus to the first field on open and back to the trigger on close, trap Tab, close on Escape; generate ids and <label for>; set required on inputs; give #mq-toasts role="status" aria-live="polite"; add aria-label to actionBtn and the × close buttons; make the athman/parent dropdowns keyboard-navigable (role=listbox, arrow keys). Replace remaining window.confirm with UI.confirmAction. Turn parent cards into <a> links. Add an axe-core run to CI.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual evidence checks out against the code. frontend-html/js/ui.js formModal (139-212) builds the overlay with no role="dialog"/aria-modal/aria-labelledby, never calls .focus(), has no keydown handler (repo-wide grep for 'keydown' or 'Escape' returns zero hits in any .js), and the <label class="mq-label"> at line 155 has no `for` and inputs have no `id`; `required` is only rendered as a red asterisk, never as an attribute (line 152). toast() (9-29) creates #mq-toasts with no role/aria-live. actionBtn (443-447) emits an icon-only <button> with no aria-label. attachAthmanSearch items bind only mousedown (331) — no keyboard selection. layout.js:136 uses native confirm(); ui.js:33 and :382 also still use window.confirm even though confirmAction exists and is used in 9 files. parent/dashboard.html:36 is a <div onclick=...> card whose CTA at :55 is a <div class="mq-btn"> — not focusable and not a link. Repo-wide: zero role=" attributes; aria-* appears only in index.html, login.html, landing.js and one aria-hidden SVG in layout.js; only 4 of 67 labels carry `for`; the only autofocus/tabindex/.focus() in the whole client is login.html:44. No backend or test mitigations are relevant to client-side a11y. However, the severity is overstated: (a) the native confirm() calls the auditor flags are the one part that IS keyboard/screen-reader accessible — replacing them with the custom confirmAction (which itself has no dialog semantics, focus or ESC) would currently make things worse, so that sub-point is backwards; (b) Tab still reaches the modal's fields in DOM order and Bootstrap/native inputs remain operable, so keyboard users are hindered (no ESC, no focus move, no trap) rather than fully blocked; (c) the WCAG/procurement impact is speculative for a small Arabic-only center-management tool with a handful of trained staff users and no stated compliance requirement in the repo. It is a real, pervasive quality gap, not a functional or security defect — medium.

```text
frontend-html/js/ui.js:139-212 formModal: no role/aria-modal, no .focus(), no keydown/Escape handler (grep 'keydown|Escape' in frontend-html/js → 0 hits); ui.js:152 input has no id/required attr; ui.js:155 <label> without for; ui.js:9-16 #mq-toasts has no role/aria-live; ui.js:443-447 actionBtn icon-only button without aria-label; ui.js:331 athman items bind mousedown only; ui.js:33, ui.js:382, layout.js:136 use native confirm() (which is itself keyboard-accessible — that sub-claim is not an a11y defect); parent/dashboard.html:36 <div onclick> card, :55 <div class="mq-btn"> CTA. Repo-wide: 'role="' → 0; '<label ... for=' → 4 of 67; only focus management anywhere is login.html:44 autofocus. confirmAction (ui.js:388-408) is already used in 9 files but also lacks role/focus/ESC.
```

</details>

### Teacher memorization and weekly-tests lists silently show only the first 15 records (paginated API, no load-more)

<a id="teacher-lists-truncated-first-page"></a>

`teacher-lists-truncated-first-page` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/teacher/memorization.html:73`, `frontend-html/teacher/weekly-tests.html:139`, `backend/app/Http/Controllers/Api/MemorizationController.php:56`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:25`

**Evidence**

```text
memorization.html:73 'const memos = res.data.data || res.data || [];' and weekly-tests.html:139 'const tests = tRes.data.data || tRes.data || [];' consume only the first page; no page/load-more state exists in either file (grep 'load-more' → absent), while the endpoints paginate: MemorizationController.php:56 '$query->paginate(15)->withQueryString()' and WeeklyTestController.php:25 '$tests = $query->paginate(15)'. Screenshot 23 already shows 15+ rows for one teacher after a month of seed data.
```

**Why it matters**

After a few weeks a teacher can neither see nor edit/delete older records from the UI (the delete/edit buttons only exist on the visible rows); the client-side search on weekly-tests (bindTableSearch) searches only those 15 rows. Data appears 'lost' to the user.

**Recommendation**

Reuse the load-more/counter pattern from admin/teachers.html (page/lastPage/fetchSeq) on both pages, and move weekly-tests search to a server ?q= parameter like memorizations already do.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

The quoted evidence is accurate and the execution path behaves as claimed. frontend-html/teacher/memorization.html:73 does `res.data.data || res.data || []` on a response produced by MemorizationController.php:56 `$query->paginate(15)->withQueryString()`, and frontend-html/teacher/weekly-tests.html:139 does the same against WeeklyTestController.php:25 `$query->paginate(15)`. Neither controller honors any `all=1` / `per_page` override (grep for those in both controllers finds nothing — only the students endpoint supports `?all=1`), and neither page has any page/load-more/lastPage state (the pattern exists only in admin/teachers.html:178-265). weekly-tests.html uses `UI.bindTableSearch` (pure client-side) over the 15 rendered rows and its edit handler uses `tests.find(...)` over the same 15-row array, so older tests cannot be reached or edited from that page at all. No feature test asserts anything about listing completeness for these two endpoints (the tests found cover juz filter/search, validation, ownership, update). So the finding is factually correct and not mitigated.

Severity is overstated, however. (1) Data is not lost: records remain in the DB and are still surfaced via the teacher's student report (`/reports/student/{id}`, PDF) and progress endpoints (`/memorizations/students-progress`), and the memorization list does have a server-side `?q=` search (memorization.html:71, controller lines 36-53) letting the teacher narrow to a student or juz to reach older rows — so memorization delete is reachable for most practical cases, just 15 at a time per query. (2) It is a UX/functional-completeness defect with no security or data-integrity consequence. (3) Weekly tests is the worse case (no server search, no pagination UI, edit only on visible rows), which is a genuine gap in a core teacher workflow. Overall this is a real medium-severity defect, not high.

```text
frontend-html/teacher/memorization.html:71-73 — fetches '/memorizations' (+ optional ?q=) and renders only `res.data.data` (first page); no page state. frontend-html/teacher/weekly-tests.html:138-139 — fetches '/weekly-tests' once, `tests = tRes.data.data`; :164 `UI.bindTableSearch('search','tbl')` client-side only; :167-170 edit handler does `tests.find(...)` over the same 15 rows. backend/app/Http/Controllers/Api/MemorizationController.php:56 `paginate(15)->withQueryString()`; backend/app/Http/Controllers/Api/WeeklyTestController.php:25 `paginate(15)` — no `all`/`per_page` handling in either controller (grep negative). Load-more pattern exists only in frontend-html/admin/teachers.html:178,183,187,237,265. Mitigations: memorization server-side `?q=` search (MemorizationController.php:36-53) and student report/progress endpoints (routes/api.php:159, /reports/student/{id}) still expose older records; no equivalent for weekly tests.
```

</details>

### Secondary text, hints, table headers and bottom-nav labels fail WCAG AA contrast

<a id="contrast-below-aa"></a>

`contrast-below-aa` · 🟡 medium · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `frontend-html/css/theme.css:12`, `frontend-html/css/theme.css:183`, `frontend-html/css/theme.css:174`, `frontend-html/css/theme.css:310`, `frontend-html/js/layout.js:213`

**Evidence**

```text
Computed ratios from the theme tokens: --faint #9DB3A6 on #FFFFFF = 2.23:1 (used for e-mail/guardian sub-lines at 12px, empty-state text, placeholders → 2.11:1 on ivory); --muted #7A8F82 on white = 3.45:1 (stat labels 13px, help text 12px, counters, bottom-nav labels at 10.5px theme.css:310); --gold-deep #9A7A1E on white = 4.06:1 (hint/warning copy); table header gold #D4AF37 on emerald #04532F = 4.37:1 at 13.5px bold (theme.css:183); disabled button 2.05:1.
```

**Why it matters**

Most of the 'meta' information in tables (emails, guardian names, codes context) and every help text is below the 4.5:1 normal-text threshold; on sunlit phones in a mosque courtyard this is a real legibility problem, and it is an automatic audit failure.

**Recommendation**

Darken --muted to ≈#5F7466 (≥4.6:1) and --faint to ≈#7E948A for text (keep the light value for borders only); raise th text to ivory or a lighter gold (#F0D27A ≈ 7:1 on emerald); bump bottom-nav label size to 11.5–12px. Publish the corrected tokens to the Flutter team as the canonical palette.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 90% · corrected severity: medium

The evidence checks out. Tokens at frontend-html/css/theme.css:9-12 are exactly as quoted (--faint #9DB3A6, --muted #7A8F82, --gold-deep #9A7A1E, --gold #D4AF37, --emerald #04532F). I recomputed WCAG 2.x luminance ratios with a script: faint/white 2.23, faint/ivory-soft 2.11, muted/white 3.45, gold-deep/white 4.06, gold-on-emerald 4.37, disabled button 2.05 — all match the auditor's numbers. The usages are real and widespread, not incidental: `.mq-table th` (line 183, 13.5px bold gold on emerald), `.mq-stat-lbl` (174, 13px muted), `.mq-empty` (197), `.mq-stat-sub`/`.mq-manager-mail`/`.sd-muted`/`.pf-item-lbl`/`.pf-hint` (437-534), bottom-nav labels hardcoded `color:#7A8F82; font-size:10.5px` (310-311), and ~50 hardcoded `#9DB3A6` text occurrences across admin/*.html, manager/*.html, js/layout.js:203/213 and js/ui.js:324 for e-mails, guardian names, empty states and autocomplete no-match text. None of these hit the 4.5:1 normal-text threshold, and none qualify for the 3:1 large-text exemption (all ≤14px). There is no mitigation: no dark-mode/high-contrast alternative, no prefers-contrast media query, and the body text token --text (#3D6B52, 6.13:1) is only used for td primary cells. Two nitpicks on the auditor's framing: (1) the disabled-button 2.05:1 figure is not a WCAG failure — inactive UI components are explicitly exempt from 1.4.3/1.4.11; (2) placeholders are debatable but generally treated as failing. Neither changes the conclusion. Severity: medium is appropriate for an accessibility/audit finding — it affects real data (e-mails, guardian names, mobile nav labels), but primary content, headings, buttons, and table body text all pass, so it is not high. Purely a CSS-token fix, no backend involvement.

```text
frontend-html/css/theme.css:12 (--muted #7A8F82 = 3.45:1 on white; --faint #9DB3A6 = 2.23:1 on white, 2.11:1 on --ivory-soft); theme.css:183 (.mq-table th 13.5px #D4AF37 on #04532F = 4.37:1 < 4.5 normal-text threshold); theme.css:174/197/437/445/483/521/534 (muted/faint/gold-deep text 11.5–13px); theme.css:310-311 (bottom nav hardcoded #7A8F82 at 10.5px, 3.45:1); js/layout.js:203,213 and ~48 further hardcoded #9DB3A6 text usages in admin/*.html, manager/*.html, js/ui.js:324 (e-mail/guardian sub-lines at 11.5–12.5px, 2.23:1). Correction: the disabled-button 2.05:1 datapoint is not a WCAG violation (inactive controls are exempt) and should be dropped from the finding.
```

</details>

### Four pages hard-code a 2-column grid outside .mq-card, causing horizontal scroll at phone widths

<a id="mobile-2col-grid-overflow"></a>

`mobile-2col-grid-overflow` · 🟡 medium · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/admin/reports.html:36`, `frontend-html/teacher/reports.html:36`, `frontend-html/admin/dashboard.html:57`, `frontend-html/teacher/dashboard.html:50`, `frontend-html/css/theme.css:358`

**Evidence**

```text
Inline '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">' at the four locations; the only mobile collapse rule is theme.css:358 '.mq-card [style*="grid-template-columns"] { grid-template-columns:1fr !important; }' which targets descendants of a card, not these siblings. Verified in the browser at a 375px viewport: admin/reports.html → {docW:375, scrollWidth:571, gridCols:'278.625px 258.175px'}; teacher/reports.html → {docW:375, scrollWidth:573, gridCols:'257.962px 281.138px'}; the primary button (scrollWidth 243px) cannot fit a 165px column at true phone width.
```

**Why it matters**

The 'add to home screen' PWA path that the code deliberately supports (config.js:28-44) breaks on the two report pages and both role dashboards — the body scrolls sideways and the bottom nav is offset.

**Recommendation**

Replace the inline grids with a shared class (e.g. .rp-grid / .mq-two-col already collapses at ≤1024px in theme.css:384/416) or extend the theme rule to '#page-content [style*="grid-template-columns"]'. Add a viewport regression check (Playwright at 375/768/1280) to CI.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

The cited evidence is accurate. All four inline grids exist exactly as quoted (admin/reports.html:36, teacher/reports.html:36 with gap:20px; admin/dashboard.html:57, teacher/dashboard.html:50 with gap:24px) and each is injected directly into the page container (pc.innerHTML / .mq-content) as a sibling of .mq-card elements, not a descendant. The only rule in theme.css that collapses inline grids on phones is line 358 '.mq-card [style*="grid-template-columns"] { grid-template-columns:1fr !important; }' inside the max-width:767.98px block, and its descendant selector cannot match these wrappers. I enumerated every @media block in theme.css (lines 134, 275, 285, 300, 318, 416, 423, 449, 492, 542, 547) and the per-page <style> blocks; none target #page-content, .mq-content, or these grids. The .rp-grid class (theme.css:384, collapses at 417) exists but is not used by these four pages. The grid children also lack min-width:0 while the dashboards contain .mq-table elements, so grid items cannot shrink below their content width, which is consistent with the reported 571-573px scrollWidth at 375px. I did not re-run the browser measurement, but the CSS trace fully supports the mechanism. The same mobile stylesheet deliberately builds a bottom nav, off-canvas sidebar, bottom-sheet modals and PWA meta tags (config.js:28-44), so phone use is an intended product path, not out of scope. Severity medium is appropriate: it is a real layout regression on four primary pages at phone width, but content remains reachable by horizontal scrolling and nothing functional or security-related is affected. Note the parent/dashboard.html:47 inline 1fr 1fr grid is also outside a card (not mentioned by the auditor) and may share the issue.

```text
frontend-html/admin/reports.html:36 and frontend-html/teacher/reports.html:36 — pc.innerHTML = `<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">` injected directly into the page container. frontend-html/admin/dashboard.html:57 and frontend-html/teacher/dashboard.html:50 — `<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">` wrapping .mq-card siblings. frontend-html/css/theme.css:358 — the only phone collapse rule, scoped to `.mq-card [style*="grid-template-columns"]`; no @media block (lines 300-365, 423-449) targets .mq-content or #page-content grids. theme.css:384/417 — .rp-grid collapses at ≤1024px but is unused by these four pages. Additional unreported instance: frontend-html/parent/dashboard.html:47 inline 1fr 1fr grid.
```

</details>

### Dashboards and detail pages render nothing until the API answers and show only a 3-second toast on failure; no skeletons anywhere

<a id="dashboards-blank-no-loading-no-retry"></a>

`dashboards-blank-no-loading-no-retry` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/admin/dashboard.html:24-74`, `frontend-html/teacher/dashboard.html:24-58`, `frontend-html/parent/dashboard.html:26-64`, `frontend-html/teacher/students.html:117-138`, `frontend-html/teacher/reports.html:58`, `frontend-html/admin/center.html:163-169`, `frontend-html/manager/teacher.html:36-41`, `frontend-html/teacher/student.html:43-46`, `frontend-html/js/ui.js:28`

**Evidence**

```text
Eight pages call 'await API.get(...)' directly after Layout.mount with #page-content still empty (awk scan listed admin/dashboard:25, teacher/dashboard:25, parent/dashboard:27, teacher/students:34, teacher/reports:58, admin/center:42, manager/teacher:37, teacher/student:44). Browser check of admin/dashboard.html with the API down: {pcHtmlLen:0, hasSkeleton:false}; the catch is 'catch (e) { UI.toast(e.message, 'danger'); }' (admin/dashboard.html:74) and toasts self-remove after 3200 ms (ui.js:28). grep 'skeleton|spinner' → 0; 41 occurrences of the plain text 'جارٍ التحميل...' are the only loading affordance. Admin KPIs are six raw totals (admin/dashboard.html:49-56) with no per-center comparison, trend, at-risk or pending counters; quick link 'تسجيل طالب جديد' opens the list page, not the add form (line 67).
```

**Why it matters**

On the single-threaded dev server or a slow Libyan connection the first paint is an empty beige page; if the request fails the user is left with a blank screen and no way to retry. The admin dashboard is informational rather than actionable, unlike the manager dashboard which already surfaces 'طالب بلا محفّظ' and pending requests.

**Recommendation**

Add a shared skeleton (stat cards + table rows) and a standard error block with a retry button in ui.js used by every page; make admin KPIs actionable (link each tile to its filtered list, add at-risk count, centers without manager/primary teacher, requests pending system-wide, students missing national id); support ?action=add deep links for quick actions.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Partially confirmed, materially overstated. CONFIRMED: admin/dashboard.html:24-74, teacher/dashboard.html:24-58, parent/dashboard.html:26-64 and the teacher/students.html main list load (line 114-138) call API.get right after Layout.mount with #page-content empty and, on failure, only fire UI.toast(e.message,'danger') which self-removes after 3200 ms (ui.js:28); no retry control. However the page is not an 'empty beige page' - Layout.mount renders sidebar, topbar and page title synchronously (layout.js:131 places #page-content inside the full shell), and api.js:47 throws an explicit Arabic message ('تعذّر الاتصال بالخادم...') so the toast is informative, not silent. WRONG for 4 of the 8 cited pages: admin/center.html:42-46, manager/teacher.html:37-41 and teacher/student.html:44-45 render a persistent `.mq-empty` error block ('تعذّر عرض ...' + message) into #page-content on failure - not a transient toast. teacher/reports.html renders the full report form synchronously before the API call at line 58; only the student <select> is populated later, so it is never blank. teacher/students.html:34 is the details modal, which already shows a loading placeholder ('جارٍ تحميل بيانات الطالب...', line 28) before fetching. Also, 12 other pages (admin/centers, managers, students, teachers, manager/dashboard, requests, teachers, teacher/attendance, memorization, weekly-tests, profiles) set a 'جارٍ التحميل...' placeholder into pc before the fetch - so the 'no loading affordance anywhere' framing is untrue; the gap is limited to the three role dashboards and the teacher student list. The admin-KPI 'not actionable' and deep-link points are product/roadmap opinions, not defects. Net: a real but minor UX polish gap on 4 pages, with browser reload as the trivial retry; severity should be low, not medium.

```text
Confirmed blank-until-API + toast-only failure: frontend-html/admin/dashboard.html:24-25,74; teacher/dashboard.html:24-25,58; parent/dashboard.html:26-27,64; teacher/students.html:114,138 (main list). Not as claimed: admin/center.html:42-46, manager/teacher.html:37-41, teacher/student.html:44-45 render a persistent mq-empty error block with the message (no toast); teacher/reports.html renders the form before line 58 (only dropdown fills later); teacher/students.html:28-34 modal shows a loading placeholder. Layout shell (sidebar/topbar/title) renders synchronously: js/layout.js:131,163. api.js:47 gives an explicit connection-failure message. 12 other pages pre-render 'جارٍ التحميل...' into pc before fetching (e.g. admin/centers.html, manager/dashboard.html, teacher/attendance.html).
```

</details>

### Attendance marking pre-selects 'حاضر' for every unrecorded student with no 'not marked' state

<a id="attendance-default-present-tristate"></a>

`attendance-default-present-tristate` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/teacher/attendance.html:41-45`, `frontend-html/teacher/attendance.html:66-72`

**Evidence**

```text
attendance.html:42 'const cur = att[id] || 'present';' — students without a saved record render identically to students saved as present (screenshot 22 shows all five pills green on an unsaved day); save serialises every checked radio (lines 66-72) so one click on 'حفظ الحضور' records the entire class as present. No count summary (present/absent/late) is shown before saving, and the save button is not locked during the request.
```

**Why it matters**

Optimises the 'mark exceptions' workflow but makes it impossible to distinguish a genuinely taken register from an accidental blanket save; attendance feeds at-risk reports, PDFs for Awqaf and the parent's attendance percentage, so silent all-present days corrupt downstream KPIs.

**Recommendation**

Render unrecorded students in a neutral 'لم يُسجَّل' state (visually distinct), keep the 'الكل حاضر' shortcut, show a live tally (حاضر n · غائب n · متأخر n · غير مسجّل n) and require the tally to be all-marked or confirm the remainder before POST; lock the button while saving. Replicate the tri-state in Flutter.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Evidence verified. frontend-html/teacher/attendance.html:42 is exactly `const cur = att[id] || 'present';` — students with no saved record for the day are rendered with the green 'حاضر' pill, indistinguishable from students actually saved as present. saveAttendance (lines 74-79) walks every student and serialises whatever radio is checked, so a single click on 'حفظ الحضور' on an untouched day POSTs present for the whole class. There is no tally, no 'not marked' state, and the save button is never disabled during the request (line 100 wires a plain onclick). Backend AttendanceController@store confirms the behaviour end-to-end: the 409/confirm guard only fires when an EXISTING record would change status; the comment explicitly says a student with no record 'يُنشأ بحرية بلا تأكيد' (created freely without confirmation), so a blanket save on an unrecorded day writes N present rows silently. The 'الكل حاضر' button (line 69) is effectively redundant because present is already the default.

Mitigations that reduce (not remove) the impact: (1) existing records cannot be flipped without an explicit Arabic confirm dialog listing each change (409 path, lines 84-97 + backend step 2); (2) updateOrCreate under the unique (student_id,date) constraint makes an accidental double-click idempotent, so the missing button lock cannot create duplicates; (3) the center manager has an attendance-review/correction endpoint (`PUT /manager/attendance/{id}/status` with corrected_by/at audit) to fix wrong days after the fact; (4) 'default present, mark exceptions' is a deliberate, common register pattern for a small centre, and the teacher still has to click save. The data-integrity concern is real (a saved all-present day is indistinguishable from a deliberately taken register), but it is a UX/design trade-off rather than a defect, it requires a user action, and it is correctable. Severity is overstated at medium; low is appropriate.

```text
frontend-html/teacher/attendance.html:42 `const cur = att[id] || 'present';` (unrecorded → rendered as present); :74-81 saveAttendance serialises every checked radio and POSTs all students; :100 save onclick has no disabled/lock; :69 'الكل حاضر' is a no-op relative to the default. backend/app/Http/Controllers/Api/AttendanceController.php store(): comment 'طالب بلا سجل = يُنشأ بحرية بلا تأكيد' — 409 confirm guard only covers changing an existing record, so blanket present on an unrecorded day writes without any confirmation. Mitigations: 409/confirm on overwrites (frontend :84-97, backend step 2); updateOrCreate + unique(student_id,date) makes double-submit idempotent; manager correction route `PUT /manager/attendance/{id}/status` (corrected_by/at) allows post-hoc fixes.
```

</details>

### Memorization entry asks for hand-typed juz/pages, uses a 114-option plain select, and the class-wide juz progress table was removed

<a id="teacher-memorization-workflow-gaps"></a>

`teacher-memorization-workflow-gaps` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/teacher/memorization.html:28-39`, `frontend-html/teacher/memorization.html:54-61`, `backend/routes/api.php:159`, `screenshots/48-juz-progress-all.png`

**Evidence**

```text
addFields() (memorization.html:28-39) = student <select>, date, surah <select> of all surahs, quality, 'juz (1-30)' number, page_from, page_to, notes — juz is typed manually although CLAUDE.md states the stored juz column is unreliable and must be derived from surah via SurahReference; no athman autocomplete is offered here (only in weekly tests). routes/api.php:159 still exposes GET /memorizations/students-progress but grep 'students-progress' in frontend-html → 0 hits; screenshot 48 ('تقدّم الطلاب في حفظ الأجزاء' with 'وصل إلى الجزء 29') documents the table that no page renders today, so a teacher's only progress view is one student at a time (teacher/student.html).
```

**Why it matters**

Daily entry is slower and error-prone (wrong juz/page ranges — screenshots 21b/23 show reversed ranges like '597–574'), and the teacher loses the at-a-glance class progress that motivates the halaqa; the mobile team may reproduce the same form.

**Recommendation**

Derive juz (and default page range) client-side from the surah pick and show it read-only; add a searchable surah picker (type-ahead, ordered from juz 30 downward per the center's convention); restore a compact class progress strip on the memorization page or dashboard using /memorizations/students-progress; in Flutter make surah selection a searchable sheet with juz auto-filled.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Verified the cited code. frontend-html/teacher/memorization.html:30-41 does build the add form with a plain surah <select> over all 114 names (line 34), a free-typed 'الجزء (1-30)' number (36) and page_from/page_to numbers (37-38); the athman autocomplete in ui.js:300+ is only wired for weekly tests. GET /memorizations/students-progress exists (routes/api.php:159, MemorizationController@studentsProgress) and is covered by MemorizationJuzGapFilterTest, but no file under frontend-html calls it; screenshots/48-juz-progress-all.png confirms an earlier UI rendered 'تقدّم الطلاب في حفظ الأجزاء' on this very page. The only teacher progress views today are per-student (teacher/student.html:87-88); the class-wide reached_juz list survives only on the manager's reports page (manager/reports.html:154). So the factual core stands. However, the finding is overstated: (1) git history shows frontend-html never consumed students-progress in any commit — the table belonged to the pre-repo UI captured in the snapshot, so this is a gap, not a regression within this repo; (2) the 'error-prone juz/page' impact is already substantially mitigated server-side: MemorizationController@store validates surah_name against SurahReference, page 1-604 with page_to gte page_from (Arabic 422), and rejects a juz outside the surah's range via SurahReference::juzRangeOf (commit d509387). The reversed ranges in screenshots 21b/23 ('597–574') are legacy/seeded rows that could no longer be created; (3) the stored juz column is never used for progress (derived from surah_name in index/studentsProgress/StudentController@show), so a wrong entry cannot corrupt progress. Remaining real issues are UX-only: an unnecessary optional juz field and a long non-searchable select, and the missing at-a-glance class strip. Downgrade to low.

```text
frontend-html/teacher/memorization.html:30-41 (form fields incl. juz number, plain surah select); backend/routes/api.php:159 + MemorizationController.php:77-129 (studentsProgress endpoint, no frontend caller — grep 'students-progress' in frontend-html = 0 hits, never present in any git revision of frontend-html); MemorizationController.php:132-164 store(): surah_name Rule::in(SurahReference), page 1-604 with page_to gte:page_from, juz checked against SurahReference::juzRangeOf — reversed ranges/wrong juz now 422; manager/reports.html:154 and teacher/student.html:87-88 show progress derived from surah names (stored juz unused). screenshots/48-juz-progress-all.png shows the pre-repo table on the memorization page.
```

</details>

### Nine hand-rolled overlay modals and 661 inline style attributes duplicate the shared UI layer

<a id="duplicated-modals-inline-styles"></a>

`duplicated-modals-inline-styles` · 🟡 medium (reviewers → low) · ✅ confirmed · **LATER** · effort L (1–2 weeks)

**Files:** `frontend-html/admin/students.html:107-217`, `frontend-html/manager/students.html:196-320`, `frontend-html/manager/teachers.html:145-203`, `frontend-html/teacher/weekly-tests.html:30-45`, `frontend-html/teacher/students.html:26-30`, `frontend-html/manager/attendance-review.html:121-140`, `frontend-html/admin/students.html:59-72`, `frontend-html/parent/messages.html:1`

**Evidence**

```text
grep -c 'position:fixed;inset:0' outside ui.js → admin/students 1, admin/teachers 1, manager/attendance-review 1, manager/students 2, manager/teachers 2, teacher/students 1, teacher/weekly-tests 1 (9 modals, each re-implementing overlay/close/submit/error-mapping). Inline 'style="' count across the 30 role pages = 661 (manager/students 78, admin/students 69, manager/teachers 52). The activate/deactivate SVG pair is pasted into 6 files (grep 'M8 12h8'). teacher/messages.html and parent/messages.html are 118-line copies differing in 5 lines (diff). admin/students.html:59-72 defines addFields() that is never called (openAddStudent replaced it). The admin and manager add-student forms diverge (admin guardian search by name/phone via /parents/search, manager by national id only via /manager/parents/search; admin asks guardian e-mail, manager does not).
```

**Why it matters**

Every UX fix (a11y, loading lock, bottom-sheet behaviour) must be applied in up to 10 places and is already inconsistent (e.g. weekly-tests modal lacks the submit lock and field-error mapping the shared formModal has). This is the main reason the maturity is 'defined' rather than 'measured'.

**Recommendation**

Extend UI.formModal with slots (custom HTML sections, onMount hooks already exist) and a shared studentForm(mode) so admin and manager reuse one guardian flow; extract a statusToggleButton(entity) helper; move repeated inline styles into theme.css utility classes; parameterise the messages page by role. Track inline-style count as a lint metric.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Every quoted piece of evidence reproduces exactly: grep -c 'position:fixed;inset:0' outside ui.js gives admin/students 1, admin/teachers 1, manager/attendance-review 1, manager/students 2, manager/teachers 2, teacher/students 1, teacher/weekly-tests 1 (9); total inline style="" across the role pages is 661 (manager/students 78, admin/students 69, manager/teachers 52); 'M8 12h8' appears in 6 files; teacher/messages.html and parent/messages.html are both 118 lines differing in 5 lines (role, BASE path, label, two empty-state strings); admin/students.html:59 defines addFields() with no caller (only openAddStudent is wired at line 504); admin uses /parents/search and collects guardian_email while manager uses /manager/parents/search with no e-mail field. The correctness claim about inconsistency also holds: teacher/weekly-tests.html:113-131 has no submit-button disable and catches errors only with UI.toast(err.message), whereas UI.formModal (js/ui.js:186, 196) maps 422 field errors and locks the button. However, this is a maintainability/duplication finding with no functional defect or security impact — the hand-rolled modals work, the backend still validates, and the codebase is a small no-build vanilla-JS client. It is real but modest; 'medium' overstates it for this product; 'low' is the better rating.

```text
frontend-html/teacher/weekly-tests.html:113-131 — onsubmit has no btn.disabled lock and only UI.toast(err.message) on failure (double-submit possible on slow API; 422 field errors not shown per field). Contrast frontend-html/js/ui.js:186 (per-field error mapping) and :196 (btn.disabled = true). frontend-html/admin/students.html:59 addFields() is dead (only openAddStudent at :102/:504 is wired). Duplication counts (9 overlays, 661 inline styles, 6-file SVG paste, 5-line diff between teacher/parent messages.html) all verified.
```

</details>

### Admin has no student detail view and no in-page analytics — only PDFs; manager PDFs are locked to the current month

<a id="admin-cannot-drill-into-student-reports-pdf-only"></a>

`admin-cannot-drill-into-student-reports-pdf-only` · 🟡 medium (reviewers → low) · ✅ confirmed · **LATER** · effort M (1–3 days)

**Files:** `frontend-html/admin/students.html:397-405`, `frontend-html/admin/center.html:243-257`, `frontend-html/admin/reports.html:36-68`, `frontend-html/manager/reports.html:28`, `frontend-html/manager/reports.html:92-94`

**Evidence**

```text
Admin student rows offer only edit + toggle (admin/students.html:397-405); grep 'student.html' in frontend-html/admin → 0; admin/center.html student rows are plain <tr> without links (lines 243-257). admin/reports.html is four 'فتح ... (PDF)' cards (lines 36-68) with no on-screen figures, whereas manager/reports.html renders stats, at-risk tables and per-teacher/per-student drill-downs. Conversely manager PDFs use 'const curPeriod = `month=${now.getMonth()+1}&year=...`' (manager/reports.html:28) with no month/year picker, unlike admin/teacher report pages.
```

**Why it matters**

The system owner cannot answer 'how is this student / which center is slipping' without opening a PDF; a manager cannot print last month's report after the 1st. Inconsistent report capabilities across roles will be mirrored in the mobile app.

**Recommendation**

Give the admin a read-only student page (reuse teacher/student.html markup against an admin-scoped /students/{id}/details), link names in admin/students and admin/center; add an overview section (per-center attendance %, pass %, at-risk counts, centers without manager) to admin/reports using existing ReportService data; add the month/year picker to manager report PDFs.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Core claims verified in the files. (1) Admin student rows in frontend-html/admin/students.html:397-405 render only an edit button and a status toggle; no href/data-view link to any detail page exists, and the only student detail page (teacher/student.html) calls Auth.requireAuth(['teacher']) at line 21, so the admin is redirected away (matches commit 37313bf which deliberately blocks admin from teacher/ pages). admin/center.html student rows (studentRow, ~line 243+) are plain <tr> with no links. (2) admin/reports.html:36-68 is exactly four cards each ending in a 'فتح ... (PDF)' button with no on-screen data. (3) manager/reports.html:28 hardcodes curPeriod to the current month/year and lines 92-94 pass it to the three PDF endpoints; no month/year picker exists on that page, whereas admin/reports.html:26-33 builds month/year selects. ReportPdfController::period() (lines 24-26) does accept month/year for the manager* variants, so the limitation is purely frontend. However the finding overstates 'no in-page analytics' for the admin: admin/center.html (lines 40-90) shows per-center on-screen stats from /centers/{id}/stats — active students/teachers, monthly attendance %, monthly test pass %, students without teacher, inactive students, today's attendance, and whether the center has a manager — and admin/dashboard.html renders system totals. What is genuinely missing is an admin per-student view, at-risk/drill-down tables on screen, and the manager PDF period picker. Manager on-screen JSON reports (reportsSystem/reportsManagement) are not month-locked; only the PDFs are. This is a feature/UX gap with no security or data-correctness impact, so medium is inflated; low is appropriate.

```text
frontend-html/admin/students.html:397-405 (edit + toggle only, no detail link); frontend-html/teacher/student.html:21 requireAuth(['teacher']) blocks admin from the only student detail page; frontend-html/admin/reports.html:36-68 four PDF-only cards; frontend-html/manager/reports.html:28 and :92-94 hardcoded current month for PDFs; backend/app/Http/Controllers/Api/ReportPdfController.php:24-26,149-152 manager PDFs already accept month/year params (frontend-only gap). Mitigation the auditor omitted: frontend-html/admin/center.html:40-90 renders on-screen per-center analytics (attendance %, pass %, students without teacher, manager presence) from GET /centers/{id}/stats, and admin/dashboard.html:25-49 shows system totals.
```

</details>

### Teacher mobile bottom nav wastes a prime slot on 'ملفّي الشخصي' while memorization and tests hide behind 'المزيد'

<a id="bottom-nav-priority-teacher"></a>

`bottom-nav-priority-teacher` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/js/layout.js:22`, `frontend-html/js/layout.js:24-33`, `frontend-html/js/layout.js:169-171`

**Evidence**

```text
layout.js:170 'const items = (NAV[user.role] || []).slice(0, 4);' and NAV.teacher lists ['dashboard','profile','students','attendance', 'memorization','tests','messages','reports'] (lines 25-32). Browser check at 375px: bottomNavItems = ['الرئيسية','ملفّي الشخصي','طلابي','الحضور والغياب','المزيد']. The admin list carries the comment on line 22 'آخر القائمة — لا يمسّ شريط الهاتف السفلي (أول 4)' showing the rule was known but not applied to the teacher.
```

**Why it matters**

The two most frequent daily teacher tasks after attendance (recording memorization, recording tests) need two taps and a drawer on the phone; messages (the parent-facing channel) are also hidden.

**Recommendation**

Move 'profile' to the end of NAV.teacher (and consider students→memorization→tests→attendance as the four tabs); define the per-role primary tab set explicitly (separate from the sidebar order) so the Flutter bottom bar and the web bottom nav share one spec.

### Assorted consistency gaps: stat-card styles, date formats, missing confirmation on approve, double-submit on 4 forms, dual student-detail paths

<a id="inconsistent-patterns-confirmations-dates"></a>

`inconsistent-patterns-confirmations-dates` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/manager/dashboard.html:24-29`, `frontend-html/manager/attendance-review.html:86`, `frontend-html/teacher/messages.html:79`, `frontend-html/manager/requests.html:55-60`, `frontend-html/teacher/weekly-tests.html:113-131`, `frontend-html/teacher/profile.html:94-109`, `frontend-html/admin/profile.html:79`, `frontend-html/teacher/students.html:26-30`, `frontend-html/teacher/students.html:123`

**Evidence**

```text
manager/dashboard.html:24-29 builds its own stat card (inline Amiri 34px) instead of .mq-stat used by admin/teacher/parent. attendance-review.html:86 prints raw ISO '${a.date}' while every other page uses UI.fmtDate; messages show fmtDate(created_at) with no time (teacher/messages.html:79). requests.html:55-60 approves an 'add' request with a direct POST and no confirmation, whereas every status toggle confirms. Submit handlers without a button lock: weekly-tests.html:113, teacher/profile.html:94 and :109, admin/profile.html:79 (scan for 'disabled = true|is-loading' within 25 lines → none) — a double-click on 'تسجيل الاختبار' creates two tests. teacher/students.html has both an openDetails modal against /students/{id} (line 26) and a link to student.html against /students/{id}/details (line 123).
```

**Why it matters**

Small individually, but together they signal the absence of a component contract; the mobile team will have to ask which of two behaviours is canonical for each screen.

**Recommendation**

Adopt one stat-card, one date formatter (with time for messages), confirm every irreversible approve/reject, lock every submit button via a shared submitGuard(form), and keep a single student-detail path (the page). Encode these as UI conventions in the frontend README.

### No sortable columns; five pages search client-side over unpaginated or partial lists; admin students lacks center/teacher filters

<a id="no-sorting-client-search-filters"></a>

`no-sorting-client-search-filters` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `frontend-html/js/ui.js:65-75`, `frontend-html/admin/centers.html:111`, `frontend-html/admin/managers.html:93`, `frontend-html/manager/requests.html:154`, `frontend-html/teacher/students.html:137`, `frontend-html/teacher/weekly-tests.html:150`, `frontend-html/admin/students.html:454`

**Evidence**

```text
grep 'sort_by|data-sort|sortable' in frontend-html → 0. UI.bindTableSearch (ui.js:65-75) filters rendered <tr> text only and is used on admin/centers, admin/managers, manager/requests, teacher/students, teacher/weekly-tests. admin/students.html:454 comment: '(أُزيلت بطاقة الفلاتر بطلب المستخدم — البحث الموحّد يغطي كل الأعمدة من الخادم)' — no center/teacher/nationality filters remain, only status + free text. manager/requests.html:100-103 builds the transfer picker as a plain <select> over '/manager/students?status=active&all=1'.
```

**Why it matters**

At dozens of centers with hundreds of students per center, admins cannot order by age/enrollment/attendance or slice by center+teacher, and a 300-option <select> for transfers is unusable on a phone.

**Recommendation**

Add server-side sort (sort=col&dir=asc) with clickable <th aria-sort> on paginated lists; restore center/teacher filter chips on admin students; replace large <select>s with the existing searchable dropdown pattern (manager/reports.html student search).

### Bootstrap RTL CSS is loaded on all 30 pages but unused, fonts are @import-blocked, and the PWA has no offline shell

<a id="perf-unused-bootstrap-fonts-no-offline"></a>

`perf-unused-bootstrap-fonts-no-offline` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `frontend-html/admin/dashboard.html:7`, `frontend-html/css/theme.css:6`, `frontend-html/js/config.js:28-31`, `frontend-html/js/layout.js:257`

**Evidence**

```text
30 role pages include '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">'; grep for real Bootstrap classes (btn/col-/form-control/table-/modal) in role pages → only one 'class="alert' (teacher/dashboard.html:44) which is fully inline-styled. theme.css:6 '@import url(https://fonts.googleapis.com/...)' (render-blocking, third-party). config.js:30 comment 'لا service worker (لا عمل دون اتصال بعدُ عمداً)'; grep 'serviceWorker' → 0. layout.js:257 polls /notifications every 60 s per open tab.
```

**Why it matters**

~230 KB of dead CSS plus a blocking font request on every navigation of a multi-page app, on Libyan mobile networks; the 'installable app' promise degrades to a white screen offline. Not blocking, but visible as 'slow' to users.

**Recommendation**

Drop Bootstrap (keep a 2 KB reset), self-host Amiri/Cairo with font-display:swap and <link rel=preload>, add a minimal service worker for the app shell + static assets, and de-duplicate notification polling (BroadcastChannel/leader tab or backoff when hidden).

## Measured facts

| Metric | Value |
|---|---|
| Logged-in role pages | 30 (admin 9, manager 9, teacher 9, parent 3); shared JS: ui.js 455 lines, layout.js 261, api.js 85, auth.js 82; theme.css 560 lines |
| Inline style attributes in role pages | 661 (manager/students 78, admin/students 69, manager/teachers 52) |
| Hand-rolled overlay modals outside UI.formModal | 9 across 7 files; activate/deactivate SVG duplicated in 6 files; messages page duplicated (118 lines, 5-line diff) |
| Accessibility attributes in role pages | role= 0; aria-* 0 (10 total, all on landing/login); <label for> 4 of 68; UI.formModal: no focus move, no ESC, no aria-modal (live check) |
| Contrast ratios (computed) | --faint 2.23:1, --muted 3.45:1, --gold-deep 4.06:1, table header gold/emerald 4.37:1, disabled button 2.05:1 |
| Mobile overflow at 375px viewport (live check) | admin/reports scrollWidth 571 vs docW 375; teacher/reports 573 vs 375; two dashboards use the same inline 2-col grid |
| List pages with server pagination + load-more | 9; pages with client-side-only bindTableSearch: 5; sortable columns: 0; lists truncated to first API page: 2 (memorization, weekly-tests) |
| Pages with no loading state before first API await | 8; skeleton/spinner components: 0; plain-text 'جارٍ التحميل' occurrences: 41 |
| Forms without submit lock (double-submit risk) | 4 (weekly-tests, teacher profile x2, admin profile); native window.confirm usages: 3 |
| External payload per page | Bootstrap RTL CSS on 30 pages with 1 Bootstrap class used; Google Fonts via @import; service worker: none; notification poll: every 60 s per tab |
| Roles without a self-service profile page/API | 2 of 4 (center_manager, parent) |
| Automated UI tests | 0 (backend feature tests: 38 files — CLAUDE.md says 20) |

## Auditor notes

Doc drift observed (trust code): (1) CLAUDE.md frontend structure omits admin/users.html, admin/profile.html, admin/center.html, manager/parents.html, manager/teacher.html, teacher/student.html, teacher/messages.html, parent/messages.html; NAV in layout.js has 8 admin, 8 teacher, 8 manager, 2 parent entries. (2) CLAUDE.md says weekly-tests have no update endpoint; routes/api.php:164 exposes update (no destroy) and the UI comment says delete was abolished. (3) CLAUDE.md says 20 feature-test files; tests/Feature holds 38 *Test.php. (4) CLAUDE.md says PHP exists only at C:\\xampp\\php\\php.exe — that path does not exist on this machine (Test-Path false); PHP resolves via Herd (C:\\Users\\HP\\.config\\herd\\bin\\php.bat). (5) frontend-html/README.md still lists demo password 'password', no manager/ pages, and no messages/profile pages. (6) screenshots/ are from the 2026-06-25 initial commit and no longer match the UI: they show delete buttons on memorization/tests, an 'رفع ملف الحضور' button on the admin dashboard, and a juz-progress table (48) that no page renders now. Additional minor observations not listed as findings: manager/students.html offers no edit of student fields (only change-teacher and status) so a typo in a name/national id must go to the admin; manager/dashboard quick-links and admin quick-links use different card styles; notification dropdown is 340px fixed width; the sidebar 'collapsed' state on desktop hides navigation entirely (no icon rail); native <input type=date> renders in the browser locale (screenshot 22 shows 06/21/2026) rather than Arabic/ISO; UI.bindTableSearch normalisation is good but only applies to already-rendered rows; teacher list of students (teacher/students.html) shows 'هاتف ولي الأمر' but no memorization progress column. Browser checks were performed against a read-only python static server on 127.0.0.1:8098 with a fake localStorage session (API intentionally not started; no state was modified).
