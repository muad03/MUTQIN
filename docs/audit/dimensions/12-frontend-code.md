# Frontend Code Quality (JS/HTML)

[← Enterprise Audit](../enterprise-audit.md)

**Score 61 / 100** — Needs real work · maturity **L2** · weight 4% · auditor scored 58, judge calibrated to 61

The frontend is disciplined hand-written vanilla JS: every one of 38 scripts parses (node --check: 0 errors), all 33 pages are structurally valid HTML with dir=rtl/viewport/title, output escaping is near-universal (195 escapeHtml calls, 144 innerHTML sinks, a single residual unescaped innerHTML of trusted server text), race guards and debouncing are applied where needed, and a coherent mq-* design system replaces Bootstrap. That earns it "functional and safe". It fails the enterprise bar structurally rather than through defects: 80% of the JavaScript (4,254 of 5,310 lines) lives inline inside HTML pages, so it cannot be linted, unit-tested, cached, hashed, or covered by a CSP; there is zero automated verification of any kind (no ESLint, no unit test, no e2e, no build); the same pagination/search/modal/field-helper code is copy-pasted across 7-12 pages (the two messages pages are 94% identical); the only CDN dependency (Bootstrap RTL CSS) is loaded on all 33 pages without SRI and is used by zero classes; 161 script tags carry no version so cPanel rsync deploys can serve stale api.js against new HTML; and there is no client-side error capture. Conventions are consistent because one author follows them by hand (CMMI 2, repeatable), not because any process or tool enforces them. Score 58: upper end of "significant risk" - the risk is maintainability and lack of quality gates at scale, not active breakage.

> **Calibration:** The auditor's own words are 'functional and safe' with 0 parse errors, near-universal escaping and race guards, and all three highs were downgraded to medium; describing the 60-74 band while scoring in 40-59 is a rationale-score mismatch, and 61 also aligns with web-app-ui (63) which shares the duplication/a11y evidence.

## What is already strong

- Output-escaping discipline is genuinely strong: js/ui.js:116-118 defines escapeHtml and it is applied 195 times across pages; every helper that renders server text (admin/center.html:31-33 meta, admin/teachers.html:85-88 infoCell, teacher/student.html:36 notes, parent/child.html:28 info) escapes; UI.badge (ui.js:111-113) and formModal title/option labels (ui.js:148,164) escape internally. A scripted scan of all ${...} interpolations of string-typed server fields found only one unescaped innerHTML sink (forgot-password.html:71, trusted server message).
- Consistent page architecture: all 30 protected pages follow the identical IIFE contract - Auth.requireAuth([role]) (9 admin / 9 center_manager / 9 teacher / 3 parent, all single-role) -> Layout.mount -> API.get -> render, e.g. admin/students.html:19-22. Zero console.log/debugger statements and zero TODO/FIXME markers in the entire tree.
- Correctness details that many teams miss are handled and documented: stale-response race guards on every async dropdown/search/list (admin/students.html:30-48 teacherFillSeq, :455-478 fetchSeq; ui.js:311-322 lastReq); 300ms debounce on all live searches (9 sites); local-date helper UI.todayStr (ui.js:128-134) with a comment explaining the UTC/Libya midnight bug it fixes; Arabic-normalised client search matching the server's ArabicText (ui.js:52-63); required attributes toggled on hidden form sections so the browser does not silently block submit (admin/students.html:219-233).
- api.js is a small, correct fetch wrapper: unified ApiError carrying status/errors/data (js/api.js:12-20), network-failure translated to an Arabic message (:44-48), 401 clears both storage keys and redirects except on login page (:51-58), lenient JSON parse (:60-63), envelope-aware error (:65-72), FormData upload path that correctly omits Content-Type (:35-36).
- HTML hygiene: 33/33 pages have lang=ar dir=rtl, viewport meta, <title>; a parser pass over static markup found 0 unclosed/stray tags and 0 duplicate ids; 0 <img> without alt; 11/11 landing images use loading=lazy; prefers-reduced-motion respected (login.html:20, js/pages/landing.js:59).
- Mobile-first shell without a framework: js/layout.js:139-158 size-aware drawer via matchMedia, :166-186 app-style bottom nav (first 4 destinations + More), sidebar collapse persisted in localStorage; css/theme.css has 11 media queries including bottom-sheet modals on phones (theme.css:333).
- Design system centralised in css/theme.css (560 lines, 23 CSS custom properties, 877 mq-* class usages) with page-scoped blocks (rp-*, sd-*, msg-*, pf-*) documented by section; only 194 lines of inline <style> across 6 pages.
- Silent catch blocks are rare and each is justified in a comment (8 of 92 catch blocks, e.g. js/layout.js:239 'الإشعار ثانوي', admin/students.html:419 'اللافتة معلومة ثانوية').

## Level-5 target state

Level 5 for this dimension means the web client is a thin, verifiable back-office for admins and center managers while Flutter serves parents and teachers: all JavaScript lives in versioned, content-hashed modules under js/ with zero inline script, a strict CSP (script-src 'self'), self-hosted fonts and no unused third-party CSS, and one shared UI kit (paged list, modal, field, info cell) so each pattern exists exactly once. Quality is enforced by tooling, not memory: ESLint + no-unsanitized in CI, unit tests on api/auth/ui, a Playwright smoke suite per role with axe-core, and client error telemetry with a version header so release health is measured. Configuration is injected per environment, the API envelope is a single documented OpenAPI contract shared with the Flutter client, and documentation is generated from the page list so it cannot drift.

## What the Flutter team must know

The web client is currently the only executable documentation of the API contract, so the mobile team must read it (or the standardised OpenAPI that should replace it) and be aware of: (1) two list shapes - `?all=1` returns a bare array while paginated endpoints return `{data:[],total,last_page,...}` (13 fallbacks in the web code; freeze this before generating Dart models); (2) the envelope `{success,message,data,errors}` where 422 `errors` is a map of field -> array of Arabic strings (pages index `[0]`) and some payloads carry fields outside `data` (`dev_otp`, `dev_note` on OTP request; 409 attendance conflicts under `data.conflicts`); (3) 401 semantics - tokens are revoked server-side on password change, user deactivation and center deactivation, so the app must clear storage and route to login on any 401, and must treat 403 as 'account/center suspended', not a generic error; (4) tokens are 7-day Sanctum PATs with no refresh endpoint - Flutter should use flutter_secure_storage (never SharedPreferences) and expect weekly re-login unless the backend adds refresh/rotation; (5) login accepts a display code (T1/S5) or email in the `email` field and the web strips all whitespace before sending; (6) PDF endpoints return application/pdf with Bearer auth (Dio bytes + share sheet, not a URL); (7) notifications are polled every 60s (no push/FCM exists) and their `link` values are web paths like `manager/requests.html` - Flutter needs a link->route mapping table; (8) messages have no real-time channel; (9) parent child detail paginates three sub-lists via `memo_page/att_page/tests_page` query params; (10) all UI strings are hardcoded Arabic with Western digits deliberately used in PDFs - start Flutter with ARB/intl and ar-LY locale from day one; (11) dates: web uses local (Africa/Tripoli) YYYY-MM-DD, never UTC ISO - send dates the same way; (12) phone numbers are normalised server-side (+218/00218/Arabic digits -> 09xxxxxxxx) so client-side formatting can stay light. Because zero frontend tests exist, the Flutter team cannot borrow fixtures or contract tests from the web; they should drive the backend's 39 feature tests as the source of expected behaviour.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [80% of application JavaScript is inline in HTML pages (4,254 of 5,310 lines)](#inline-js-80-percent) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | M |
| [Core UI patterns (paged list, debounced search, modals, field helpers) are duplicated across 7-12 pages instead of living in ui.js](#copy-paste-page-patterns) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | L |
| [No automated verification of any kind for the frontend: no linter, no unit tests, no e2e, no build](#zero-tests-zero-lint-zero-build) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NEXT | M |
| [Client papers over two API list shapes (?all=1 array vs paginator) in 13 places - the contract the Flutter team will inherit](#api-dual-response-shape-in-client) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | M |
| [Bootstrap 5.3.3 RTL CSS loaded on all 33 pages with zero usage and no SRI; fonts pulled via render-blocking CSS @import from Google](#cdn-no-sri-dead-bootstrap-google-fonts) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [No cache-busting on 161 script tags / 33 stylesheet links - rsync deploys can pair new HTML with stale shared JS](#no-cache-busting-on-assets) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [api.js has no timeout, no retry, undifferentiated 403/419/429/5xx handling, 401 redirect races the throw, and openPdf bypasses the wrapper](#api-wrapper-missing-resilience) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [7-day Bearer token in localStorage, with inline scripts/842 inline styles making a mitigating CSP impossible and no refresh/rotation](#token-localstorage-no-csp-no-refresh) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [No global error handler or client telemetry - frontend failures in production are invisible](#no-client-error-capture) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [API base URL chosen by hostname sniff with a hardcoded production path; no staging/env injection; README documents a stale constant](#config-by-hostname-sniff) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [842 inline style attributes and 529 hardcoded hex colours bypass the 23 design tokens defined in theme.css](#inline-styles-bypass-tokens) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | LATER | M |
| [Accessibility is skin-deep: 4 of 68 labels bound with for=, no roles/aria-modal/focus trap on custom modals, 63 buttons without type](#a11y-gaps-in-dynamic-ui) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | LATER | M |
| [Residual output-handling inconsistencies: one unescaped innerHTML of server text, 5 double-escape sites, unencoded id in one API path, error keys used raw in selectors](#residual-escaping-inconsistencies) | ⚪ low | ℹ️ informational | NEXT | S |
| [Dead UI and stale documentation: unwired 'remember me', removed demo-accounts panel still documented, README/CLAUDE.md/launcher describe a client that no longer exists](#dead-ui-and-frontend-doc-drift) | ⚪ low | ℹ️ informational | NEXT | S |
| [PWA is a manifest only: no service worker, SVG-only icon (no PNG 192/512, invalid apple-touch-icon), start_url login.html](#pwa-manifest-without-installability-or-offline) | ⚪ low | ℹ️ informational | LATER | S |

### 80% of application JavaScript is inline in HTML pages (4,254 of 5,310 lines)

<a id="inline-js-80-percent"></a>

`inline-js-80-percent` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/admin/students.html:18-512`, `frontend-html/manager/students.html:18-422`, `frontend-html/admin/teachers.html:18-291`, `frontend-html/manager/teachers.html:18-290`, `frontend-html/manager/reports.html:18-267`, `frontend-html/js/ui.js:1-455`

**Evidence**

```text
Scripted extraction of <script> blocks without src: 'TOTAL inline JS lines: 4254' across 31 pages (admin/students.html 495, manager/students.html 405, admin/teachers.html 274, manager/teachers.html 273, manager/reports.html 250, ...). Shared js/ totals 1,056 lines (api.js 85, auth.js 82, config.js 44, layout.js 261, ui.js 455, landing.js 80, login.js 49). Only index.html and login.html have 0 inline lines. 161 <script src> tags, 0 with a version query.
```

**Why it matters**

Inline code cannot be linted, unit-tested, minified, content-hashed or browser-cached independently of the page, and forces script-src 'unsafe-inline' so a real CSP is impossible - which is exactly what would mitigate the localStorage-token exposure. Every bug fix in a table/modal pattern must be re-applied per page. At dozens of centers with a second (Flutter) client, the web code becomes the untestable legacy surface.

**Recommendation**

Mechanically move each page's IIFE to js/pages/<role>/<page>.js (same IIFE, same globals - no behaviour change), leaving each HTML as pure markup + 6 script tags. Then a CSP with script-src 'self' becomes possible and ESLint/tests can target real files. This is a prerequisite for every other frontend improvement.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual core is confirmed by independent measurement. Counting non-blank lines inside `<script>` blocks without `src` across all 33 HTML files in frontend-html/ gives 3,886 inline lines vs 960 lines in js/ (api.js 74, auth.js 71, config.js 39, layout.js 237, ui.js 425, pages/landing.js 72, pages/login.js 42) — 80.2% inline, matching the auditor's 80% (their 4,254 / 1,056 figures include blank lines; same ratio). The top offenders are as cited: admin/students.html (466), manager/students.html (380), manager/teachers.html (259), admin/teachers.html (253), manager/reports.html (230). Only index.html and login.html have 0 inline lines (their logic lives in js/pages/). 161 `<script src>` tags, 0 carry a version/cache-busting query. No Content-Security-Policy is defined anywhere in frontend-html/, backend/app, backend/config, or backend/public — so the claim that a strict `script-src 'self'` CSP is currently impossible is accurate (inline blocks would need 'unsafe-inline' or per-block hashes/nonces, which a static-host deployment cannot practically maintain). Each page follows the documented pattern (config/api/auth/ui/layout script tags then one page IIFE), so the recommended mechanical extraction is behaviour-preserving and low-risk.

Where the finding overstates: (1) "Every bug fix in a table/modal pattern must be re-applied per page" is only partly true — the shared modal/table/search/toast machinery already lives in ui.js (UI.formModal, table search, etc.) and layout.js; the inline IIFEs are mostly page-specific wiring, not duplicated framework code. (2) Extraction alone yields no lint/test benefit: the repo has no ESLint config, no JS test runner, and no build step at all, so "cannot be linted/unit-tested" is a property of the project's tooling, not of inline placement per se. (3) Independent browser caching of ~4k lines of JS is immaterial for a small-center intranet-style app. (4) No correctness defect follows from this: the pages execute exactly as intended; this is a maintainability/hardening finding, and the CSP it blocks is a defense-in-depth control against XSS, not a currently-exploited weakness (the localStorage token exposure is a separate finding).

Net: real and accurately measured, but rated too high. It is a structural/maintainability debt with a moderate security-enablement angle, not a high-severity defect. Medium is appropriate.

```text
Independent count (non-blank lines in <script> without src): admin/students.html 466, manager/students.html 380, manager/teachers.html 259, admin/teachers.html 253, manager/reports.html 230, ... TOTAL 3,886 inline across 33 pages vs 960 in js/ (api.js 74, auth.js 71, config.js 39, layout.js 237, ui.js 425, pages/landing.js 72, pages/login.js 42) = 80.2% inline. 161 <script src> tags, 0 versioned. grep for "Content-Security-Policy" in frontend-html/, backend/app, backend/config, backend/public: no matches. frontend-html/admin/students.html:13-18 shows the standard 5 shared script tags followed by the page IIFE at line 18+. No ESLint config or JS test runner exists in frontend-html/ (only js/, css/, html files), so lint/test benefits require tooling beyond extraction. Shared table/modal code already centralized in frontend-html/js/ui.js (UI.formModal etc.), so the "re-apply per page" claim is overstated.
```

</details>

### Core UI patterns (paged list, debounced search, modals, field helpers) are duplicated across 7-12 pages instead of living in ui.js

<a id="copy-paste-page-patterns"></a>

`copy-paste-page-patterns` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort L (1–2 weeks)

**Files:** `frontend-html/admin/students.html:109-117`, `frontend-html/manager/students.html:207-216`, `frontend-html/manager/teachers.html:75-81`, `frontend-html/admin/students.html:455-502`, `frontend-html/admin/users.html:64-86`, `frontend-html/manager/parents.html:47-54`, `frontend-html/teacher/messages.html:24-25`, `frontend-html/parent/messages.html:24-25`

**Evidence**

```text
grep counts: 'load-more' server-paged list + fetchSeq race guard + counter re-implemented in 7 pages (admin/students, admin/teachers, admin/users, manager/attendance-review, manager/parents, manager/students, manager/teachers); 12 hand-rolled overlay modals ('position:fixed;inset:0') across 8 files alongside UI.formModal/confirmAction/importSummaryModal in ui.js; the `const inp = (name, label, opts = {})` field-template helper is redefined verbatim in 3 pages; the `info/meta(lbl,val)` card helper in 7 pages; `const stat = (` card helper 8 times; 'const esc = UI.escapeHtml' alias in 6 pages; 41 copies of the 'جارٍ التحميل' loading markup. difflib on teacher/messages.html vs parent/messages.html: 85 of 90 non-blank JS lines identical (ratio 0.94) - only BASE and OTHER_LABEL differ. The admin and manager add-student modals (admin/students.html:102-339, manager/students.html:195-406) share the same guardian-mode/parent-search/chip/submit structure but have already diverged (admin sends parent_id after name/phone search; manager sends parent_id_number after national-id search).
```

**Why it matters**

A defect in pagination, the race guard, modal focus handling or field-error mapping must be found and fixed in up to 12 places; divergence has already begun (the two add-student flows implement the same business action differently), which is how role-specific behavioural bugs creep in. Onboarding a second developer means learning N variants of one pattern.

**Recommendation**

Extract into ui.js: UI.pagedList({endpoint, params(), rowsHtml, bind}) covering page/lastPage/total/fetchSeq/load-more/counter/debounced search; UI.modal({title, html, onMount}) as the single overlay primitive that formModal, confirmAction and the 9 page modals build on; UI.field(name,label,opts) replacing the 3 inp copies; UI.infoCell. Parameterise messages.html into one js/pages/messages.js driven by role. Add Escape-to-close and focus trap once in the primitive.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Every quoted data point checks out on the current tree. The `load-more` + `fetchSeq` + page/lastPage/total/loadedCount pattern is hand-copied into exactly the 7 pages named (admin/students.html:455-488, manager/teachers.html:194-275, etc.; fetchSeq also appears in teacher/memorization.html). `const inp = (name, label, opts = {})` is defined verbatim in admin/students.html:109, manager/students.html:207 and manager/teachers.html:75 (the manager copy has already drifted by adding `${opts.attrs || ''}`). Hand-rolled `position:fixed;inset:0` overlays: 9 in 7 page files plus 3 in ui.js, matching the auditor's 12. `const esc = UI.escapeHtml` alias in 6 pages, 41 copies of the loading string, 8 `const stat = (` helpers — all confirmed. teacher/messages.html vs parent/messages.html differ only in the role guard, BASE, OTHER_LABEL and three Arabic strings. `window.UI` (js/ui.js:449-454) exports no pagedList/modal/field primitive, and no page or ui.js modal handles Escape (grep count 0 everywhere), so nothing elsewhere mitigates it. The admin/manager add-student divergence (parent_id after /parents/search vs parent_id_number after /manager/parents/search) is real, though the backend accepts both (StudentController.php:190-239), so it is not a functional bug today.

Why not "high": under the correctness lens there is no observable wrong behaviour — every copy of the race guard and pager is the same working code, both add-student flows are valid against the API, and there are no security or data consequences. This is a maintainability/DRY finding in a small, no-build vanilla-JS client; the impact is future fix-propagation cost, not current misbehaviour. Medium is the appropriate rating; the recommendation (UI.pagedList / UI.modal / UI.field, shared messages.js) is sound.

```text
Confirmed: frontend-html/admin/students.html:455-488 and manager/teachers.html:194-275 (identical page/lastPage/total/loadedCount/fetchSeq/load-more blocks; same in admin/teachers, admin/users, manager/attendance-review, manager/parents, manager/students). `const inp = (name, label, opts = {})` at admin/students.html:109, manager/students.html:207 (adds `${opts.attrs || ''}` — already diverged), manager/teachers.html:75. Overlays `position:fixed;inset:0`: admin/students, admin/teachers, manager/attendance-review, manager/students(2), manager/teachers(2), teacher/students, teacher/weekly-tests + 3 in js/ui.js. js/ui.js:449-454 exports no paging/modal/field primitive; zero `Escape` handling in ui.js or any page modal. teacher/messages.html vs parent/messages.html: non-blank diff limited to lines 19, 22-23, 34, 57. Divergence: admin/students.html:269,320 (parent_id via /parents/search) vs manager/students.html:342,385 (parent_id_number via /manager/parents/search) — both accepted by backend/app/Http/Controllers/Api/StudentController.php:190-239, so no runtime defect.
```

</details>

### No automated verification of any kind for the frontend: no linter, no unit tests, no e2e, no build

<a id="zero-tests-zero-lint-zero-build"></a>

`zero-tests-zero-lint-zero-build` · 🟠 high (reviewers → medium) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/README.md:1-46`, `frontend-html/js/api.js:1-85`, `frontend-html/js/auth.js:1-82`

**Evidence**

```text
find for package.json/.eslintrc*/eslint.config.*/.prettierrc*/tsconfig.json/vite.config.*/*.test.js/*.spec.js/playwright.config.*/cypress.config.* under frontend-html returns nothing (only backend/ has Laravel's default package.json + vite.config.js, unused by frontend-html). Backend has 39 feature-test files; frontend has 0 tests for 5,310 lines of JS including the auth guard (auth.js:56-68) and the 401 handling (api.js:51-58).
```

**Why it matters**

The role-gate (requireAuth), token lifecycle, 401 redirect, envelope parsing, Arabic search normalisation and every modal flow are verified only by manual clicking. Regressions in shared js/ propagate to all 30 pages with no safety net; a refactor of the duplication above cannot be done safely without tests first. This is the largest gap versus a level-5 bar.

**Recommendation**

Add (1) ESLint flat config with eslint:recommended + no-unsanitized/DOM plugin, run in CI on js/**; (2) Vitest/jsdom unit tests for api.js (401/422/network), auth.js (requireAuth matrix for 4 roles), ui.js (escapeHtml, normalizeSearch, todayStr, formModal error mapping); (3) a Playwright smoke suite against the seeded backend: login per role, one list+search, one create modal, logout - ~8 specs. Wire into a GitHub Actions job alongside `php artisan test`.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual core is confirmed. A find under frontend-html for package.json, eslint/prettier configs, tsconfig, vite/vitest/jest/playwright/cypress configs and *.test.js/*.spec.js returns nothing. There is no CI at all in the repo (no .github/ directory; the only pipeline file is .cpanel.yml, which is a pure rsync deploy with no test/lint step — so even `php artisan test` is not run automatically). The cited code exists as described: auth.js:56-68 is requireAuth (isLoggedIn → role check → redirectByRole), api.js:51-58 is the 401 branch that clears storage and redirects. Line count checks out: shared js/ is 1,056 lines and inline <script> blocks in the 33 HTML pages add ~4,254 lines, i.e. ~5,310 lines of untested JS. Backend has 38 feature-test files (auditor said 39 — trivial). However, the impact is overstated. The frontend role guard is a UX convenience only; the actual authorization is enforced server-side by the role+tokenCan middleware and is already covered by backend tests (RoleMatrixTest, OwnershipTest, CenterManagerTest, TeacherProfileTest, etc.). A regression in requireAuth cannot grant data access — the worst case is a broken page or a wrong redirect. Envelope shape and 422 Arabic errors are also asserted by backend tests, so the contract the frontend depends on is guarded on one side. For a no-build, framework-free static client maintained by a small team, absence of a Vitest/Playwright stack is a maintainability gap rather than a correctness or security defect; 'high' inflates it. Medium is appropriate: real, unmitigated on the frontend side, but low blast radius on data/security and the recommended remediation (ESLint + a handful of jsdom unit tests + smoke e2e) is cheap.

```text
Confirmed: no package.json / eslint / vitest / playwright / *.test.js anywhere under frontend-html; no .github/workflows — the only pipeline file is .cpanel.yml (rsync deploy, no test/lint step for backend or frontend). frontend-html/js/*.js = 1,056 lines; inline <script> in 33 HTML pages ≈ 4,254 lines (≈5,310 total, matching the auditor). frontend-html/js/auth.js:56-68 requireAuth and frontend-html/js/api.js:51-58 401 handling exist as quoted. Mitigation not credited by the auditor: authorization is enforced server-side (bootstrap/app.php aliases + tokenCan) and covered by backend/tests/Feature/RoleMatrixTest.php, OwnershipTest.php, CenterManagerTest.php (38 feature-test files, not 39), so a frontend guard regression cannot leak data — it degrades UX only. Backend package.json/vite.config.js are Laravel scaffolding and indeed unused by frontend-html.
```

</details>

### Client papers over two API list shapes (?all=1 array vs paginator) in 13 places - the contract the Flutter team will inherit

<a id="api-dual-response-shape-in-client"></a>

`api-dual-response-shape-in-client` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `frontend-html/admin/students.html:40`, `frontend-html/admin/students.html:345-346`, `frontend-html/admin/centers.html:61`, `frontend-html/admin/managers.html:61`, `frontend-html/admin/reports.html:73`, `DEPLOYMENT.md:44`

**Evidence**

```text
grep '\.data\.data || .*\.data' -> 13 occurrences, e.g. admin/students.html:40 `const list = res.data.data || res.data || [];` and :345 `centers = cRes.data.data || cRes.data || [];`. DEPLOYMENT.md section 4 admits: 'توحيد شكل ردود ?all=1 مقابل المرقّمة (الواجهة تتعامل مع الشكلين حالياً)'. Also teacher/attendance.html:84 relies on `e.status === 409 && e.data.conflicts` and forgot-password.html:88 on top-level `res.dev_otp` outside the data envelope.
```

**Why it matters**

A typed Dart client cannot express 'data is either a List or a {data,total,last_page} object' without union hacks; every list endpoint would need a bespoke fromJson. Fixing the shape after the mobile app ships forces a coordinated release across web + two mobile stores. Two weeks before Flutter is exactly the moment to freeze the envelope.

**Recommendation**

Before the Flutter client is generated, standardise every list endpoint to one shape (recommend always `{success,message,data:[...],meta:{total,page,last_page,per_page}}`, with ?all=1 returning meta.total===data.length) and remove the 13 fallbacks in the web client in the same change so the web acts as the contract test. Publish an OpenAPI 3 document generated from the standardised responses.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 78% · corrected severity: low

Facts check out: all 13 `X.data.data || X.data || []` fallbacks exist exactly as cited, and the backend really emits two shapes on the same endpoint depending on `?all=1` (CenterController.php:21-25, StudentController.php:132-137, TeacherController.php:41-45, CenterManagerController.php:191-193 — `$query->get()` array vs `paginate()` object, both placed under `data`). DEPLOYMENT.md:44 explicitly lists unifying this as a TODO. `dev_otp`/`dev_note` are indeed written at the top level of the response, outside `data` (AuthController.php:154-155), and only in `local` env. However the finding is overstated: (1) the shape is not ambiguous at runtime — it is fully determined by whether the caller sent `?all=1`, so a typed client knows which decoder to use per call; this is standard Laravel paginator behaviour, not a bug, and the web client's fallback is correct in both branches (a paginator's `data` key is always an array, a plain array has no `.data`). (2) The shapes are deliberately locked by feature tests (CenterStatusTest.php:101,107 for `?all=1`; PaginationSearchTest.php:28,141-142 and AdminCenterDetailsTest for paginator keys), so this is an intentional, tested contract rather than accidental drift. (3) The 409 `conflicts` example is actually envelope-compliant — AttendanceController.php:93-97 returns `'data' => ['conflicts' => ...]` and api.js exposes `payload.data` as `e.data`, so `e.data.conflicts` is the standard envelope, not a deviation. (4) The 'two weeks before Flutter' premise appears nowhere in the repo (no Flutter/Dart/OpenAPI references found); the impact rests on an unverified external plan. Real but minor API-design/consistency debt with no functional defect and no security consequence: low, not medium.

```text
backend/app/Http/Controllers/Api/CenterController.php:21-29, StudentController.php:132-141, TeacherController.php:41-49, CenterManagerController.php:189-194 — `?all=1` returns a plain array under `data`, otherwise a paginator object (`data`,`current_page`,`last_page`,`total`) under `data`. AuthController.php:153-156 — `dev_otp`/`dev_note` set at top level of the JSON (outside `data`), gated to `app()->environment('local')`. AttendanceController.php:93-97 — 409 body is `'data' => ['conflicts' => ...]`, i.e. envelope-compliant; frontend-html/js/api.js:19 maps `payload.data` to `ApiError.data`, so `e.data.conflicts` in teacher/attendance.html:84 is the standard envelope, not a second shape. Shapes are pinned by tests: tests/Feature/CenterStatusTest.php:101,107 (`?all=1`), tests/Feature/PaginationSearchTest.php:28,141-142 (paginator keys). No Flutter/Dart/OpenAPI artefacts exist anywhere in the repo.
```

</details>

### Bootstrap 5.3.3 RTL CSS loaded on all 33 pages with zero usage and no SRI; fonts pulled via render-blocking CSS @import from Google

<a id="cdn-no-sri-dead-bootstrap-google-fonts"></a>

`cdn-no-sri-dead-bootstrap-google-fonts` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/login.html:8`, `frontend-html/admin/students.html:7`, `frontend-html/css/theme.css:6`, `frontend-html/css/theme.css:4`, `frontend-html/css/theme.css:20`

**Evidence**

```text
grep external resources: 33x `href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css"`; `integrity=` count 0; `crossorigin` count 0; no Bootstrap JS anywhere. Class-token scan across all HTML+JS templates: 877 mq-* tokens, 0 Bootstrap component/utility classes (matches for 'card-body','badge' were mq-card-body/mq-badge). theme.css:20 keeps `--bs-primary` 'مواءمة لون Bootstrap' for a framework that is not used. theme.css:6 `@import url('https://fonts.googleapis.com/css2?family=Amiri...&family=Cairo...')`.
```

**Why it matters**

~230KB of unused CSS on every page load (a material cost on Libyan mobile networks) plus a third-party script-less but style-injecting origin without integrity pinning (supply-chain: a compromised CDN could inject CSS exfiltration/UI redress). @import inside CSS serialises a second blocking request to Google before any text renders; Google Fonts availability/latency from Libya is not guaranteed and the site has no offline fallback. CLAUDE.md and README still describe the client as 'Bootstrap 5 RTL' - doc drift.

**Recommendation**

Remove the Bootstrap <link> from all 33 pages (keep a 30-line reset in theme.css if Reboot behaviour is relied on - verify visually), delete --bs-* vars. Self-host Amiri/Cairo woff2 in frontend-html/fonts/ with @font-face + font-display:swap and preload the two primary weights; drop the @import. If any CDN asset remains, add integrity+crossorigin. Update CLAUDE.md/README wording.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The factual core holds up on inspection. All 33 HTML pages load `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css` with no `integrity`/`crossorigin` attributes (grep counts: 33 link tags, 0 integrity, 0 crossorigin); no Bootstrap JS bundle is referenced anywhere; no `preconnect`/`dns-prefetch` hints exist. A strict class-token scan over every static `class="..."` attribute and every `classList`/`className` assignment in HTML+JS finds no Bootstrap component or utility classes except a single `<div class="alert">` in teacher/dashboard.html:38 whose padding/margin/radius/colors are all overridden inline (so only Bootstrap's transparent 1px border survives). theme.css defines its own fonts on `.mq-btn` (line 53-55) and `.mq-input` (line 153), sets `*{box-sizing}` and `body{margin:0}` itself, and `--bs-primary`/`--bs-primary-rgb` (theme.css:20) are never consumed by any `var(--bs-...)`. theme.css:6 indeed pulls Amiri/Cairo via a CSS `@import` from Google Fonts with no local fallback files (no `fonts/` directory). CLAUDE.md:10 still calls the client "HTML + CSS + JS + Bootstrap 5 RTL". So the finding is not refuted.

It is, however, overstated. (1) The "~230KB on every page load" figure is the uncompressed size; jsDelivr serves brotli/gzip (~30KB transfer) and versioned `@5.3.3` URLs carry year-long immutable cache headers, so the cost is paid once per browser, not per page. (2) The supply-chain angle is CSS-only from a well-known CDN; there is no script execution, and CSS attribute-selector exfiltration cannot read typed input values (the `value` attribute is not updated by typing), so the realistic damage is UI redress/defacement, not credential theft. (3) `display=swap` is already on the Google Fonts import, so text renders in the fallback font while fonts load; the truly blocking element is the extra CSS request chain (theme.css -> @import), which is a minor perf regression, not a functional outage - if Google is unreachable the page still renders in the fallback serif/sans stack. (4) Removing Bootstrap also removes Reboot normalizations (body line-height 1.5, heading margins, etc.) that the mq- styles implicitly sit on, so the "zero usage" claim is technically true for classes but the CSS is not entirely inert - the auditor's "verify visually" caveat is warranted. Net: a genuine hygiene/perf/doc-drift issue, but low severity for a small single-country internal system.

```text
frontend-html/login.html:8 (and 32 other pages) - `<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">` with no integrity/crossorigin; frontend-html/css/theme.css:6 - `@import url('https://fonts.googleapis.com/css2?family=Amiri...&family=Cairo...&display=swap')` (note `display=swap` is present, so text is not blocked from rendering); frontend-html/css/theme.css:20 - unused `--bs-primary`/`--bs-primary-rgb` (no `var(--bs-` consumer anywhere); frontend-html/teacher/dashboard.html:38 - the only Bootstrap class in the codebase (`class="alert"`), fully overridden by inline styles; frontend-html/css/theme.css:53-55 and :153 - `.mq-btn`/`.mq-input` set their own font-family, so button/input typography does not depend on Reboot; CLAUDE.md:10 - stale "Bootstrap 5 RTL" wording. Size correction: bootstrap.rtl.min.css is ~230KB uncompressed but ~30KB brotli over jsDelivr with immutable versioned caching - a one-time cost per browser, not per page view.
```

</details>

### No cache-busting on 161 script tags / 33 stylesheet links - rsync deploys can pair new HTML with stale shared JS

<a id="no-cache-busting-on-assets"></a>

`no-cache-busting-on-assets` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/admin/students.html:13-17`, `frontend-html/login.html:99-103`, `.cpanel.yml:5`

**Evidence**

```text
grep '<script src="..."' | grep -c '?' -> 0 of 161; theme.css referenced as bare '../css/theme.css' (30) / 'css/theme.css' (3). .cpanel.yml:5 deploys with `rsync -a --delete ... frontend-html/ $DEPLOYPATH/` - no hashing, no headers step, and frontend-html has no .htaccess setting Cache-Control.
```

**Why it matters**

A returning user's browser may reuse cached api.js/ui.js/layout.js while fetching a new page that calls a helper added in the same release -> 'UI.x is not a function' failures that are invisible to the developer and only clear after a hard refresh; conversely, long-cache headers cannot be set safely, so every visit re-fetches ~90KB of JS/CSS. During the Flutter rollout the API will change frequently, making this more likely.

**Recommendation**

Minimal: add ?v=<git short sha> to all script/link tags via a 20-line deploy step (sed in .cpanel.yml) and set Cache-Control: no-cache on *.html, max-age=31536000,immutable on ?v= assets via frontend-html/.htaccess. Better: a tiny esbuild step producing hashed js/css and rewriting the 33 pages.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Evidence verified as stated: 161 `<script src>` tags in frontend-html, 0 carry a query string; theme.css referenced bare 30x as `../css/theme.css` and 3x as `css/theme.css`; `.cpanel.yml` line 5 rsyncs `frontend-html/` straight to public_html with no hashing/rewrite step; there is no `frontend-html/.htaccess` (only `backend/.htaccess` and `backend/public/.htaccess`, neither sets Cache-Control/Expires); no `http-equiv` cache meta, no runtime versioned script loading in js/. No mitigation exists anywhere (backend middleware is irrelevant to a static host). The failure mode is real: with no Cache-Control the browser applies heuristic freshness (~10% of Last-Modified age) to ui.js/layout.js, and rsync -a preserves source mtimes, so a shared JS file that was stable for months is heuristically fresh for days after a release while the new HTML page is fetched -> `UI.x is not a function` until a hard refresh. However the severity is overstated: the window is bounded (self-heals within days), the audience is a handful of center staff in one country, a hard refresh fixes it, and the auditor's second impact claim ("every visit re-fetches ~90KB") contradicts the first (heuristic caching means it usually does NOT re-fetch — that is precisely the problem). The Flutter-rollout argument does not apply: a mobile app does not load these HTML/JS assets at all. Keep the finding, downgrade to low (an operational/deploy-hygiene issue, not a correctness or security defect).

```text
frontend-html/admin/students.html:13-17 (five bare `<script src="../js/*.js">`), frontend-html/login.html:99-103 (same, `js/` prefix); grep over frontend-html/*.html: 161 script tags, 0 with `?`; theme.css 30x `../css/theme.css` + 3x `css/theme.css`; .cpanel.yml:5 `rsync -a --delete ... frontend-html/ $DEPLOYPATH/`; no frontend-html/.htaccess exists (only backend/.htaccess, backend/public/.htaccess, neither sets Cache-Control/Expires); no `http-equiv` cache meta in any page; no versioned dynamic loading in frontend-html/js. Note: impact statement "every visit re-fetches ~90KB" is inconsistent with the stale-cache scenario (heuristic freshness avoids re-fetch) and the Flutter app does not consume these assets.
```

</details>

### api.js has no timeout, no retry, undifferentiated 403/419/429/5xx handling, 401 redirect races the throw, and openPdf bypasses the wrapper

<a id="api-wrapper-missing-resilience"></a>

`api-wrapper-missing-resilience` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/js/api.js:27-75`, `frontend-html/js/api.js:51-58`, `frontend-html/js/ui.js:350-378`, `frontend-html/js/ui.js:358`

**Evidence**

```text
api.js:27-45 builds fetch options with no AbortController/timeout and no retry; :51-58 on 401 sets `location.href = ...login.html` then `throw new ApiError(...)` - the calling page continues and paints an error toast/row before navigation. No handling exists for 403 (deactivated center/manager gets a raw toast on every page and is never routed), 419, 429 (login is throttled 10/min) or 5xx. grep 'status ===' shows only api.js:51 and ui.js:358. ui.js:350-378 openPdf re-implements fetch+Bearer; on 401 it removes only STORAGE_TOKEN (not STORAGE_USER, ui.js:358), and uses `window.open(blobUrl)` after an await (popup-blocked on iOS Safari).
```

**Why it matters**

On slow Libyan mobile links a hung request never resolves (spinner forever, button stays disabled); throttled logins (429) show a generic 'failed (429)'; a deactivated manager sees confusing per-widget errors instead of a single 'account suspended' screen; PDF open silently fails on iPhones. These are the exact behaviours a mobile-first user base will hit first.

**Recommendation**

Add AbortController with 15s timeout + one retry on network error for GET; map 403 -> a single 'حسابك موقوف' page, 429 -> Retry-After-aware Arabic message, >=500 -> generic with correlation id; make 401 handling `return new Promise(()=>{})` after redirect (or throw a distinguishable SessionExpired that pages ignore); route openPdf through request() with responseType blob and use an <a download> or same-tab navigation instead of window.open.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Mixed: the mechanical observations are accurate but the headline impact claims are largely wrong or exaggerated. CONFIRMED: api.js:27-48 has no AbortController/timeout/retry; api.js:51-57 assigns location.href then throws (a caller's catch can flash a toast before unload — cosmetic only, since navigation is already committed); ui.js:350-378 openPdf re-implements fetch and on 401 removes only STORAGE_TOKEN (ui.js:358), and uses window.open after an await (ui.js:372) which popup blockers on iOS Safari may block. REFUTED: (1) 'deactivated manager sees confusing per-widget 403s and is never routed' — deactivation revokes all tokens (User.php:81 recordPasswordChange, TeacherController.php:211, CenterController.php:268 for all center members, ManagerManagementController.php:174), so the next request is a 401, api.js redirects to login, and login returns 403 with an Arabic message (AuthController.php:42-57) that login.js:38-43 shows via showAlert. The 403 branch in CenterManagerMiddleware only fires for cross-role token misuse, not the deactivation flow. (2) '429 shows generic failed (429)' — api.js:67 prefers data.message; Laravel's throttle returns JSON {"message":"Too Many Attempts."} so the login alert shows that text (English, not the generic string) — an i18n nit, not a correctness failure; AuthLoginTest.php:43-45 covers the 429 itself. (3) 419 is not applicable — Sanctum bearer tokens, no CSRF/session, the API never emits 419. (4) 'hung request never resolves' is exaggerated — browser fetch has its own stall timeout; slow, not infinite. (5) openPdf leaving STORAGE_USER: harmless in practice because requireAuth (auth.js:57) checks isLoggedIn/token and redirects to login anyway, and login.js/Auth.login overwrites the user record. What remains real: no request timeout, no Arabic mapping for 429/5xx (English Laravel defaults leak through), openPdf duplicating auth logic and using window.open post-await. These are polish/resilience items, not a medium-severity defect.

```text
frontend-html/js/api.js:44-48 (fetch with no AbortController/timeout, no retry); api.js:51-57 (401: clear both storage keys, redirect, then throw — throw is harmless post-redirect); api.js:65-71 (non-OK: uses server data.message, so 429 shows Laravel's 'Too Many Attempts.' in English rather than a generic string); frontend-html/js/ui.js:355-358 (openPdf duplicate fetch/Bearer; 401 removes only STORAGE_TOKEN), ui.js:372 (window.open after await). Mitigations: backend/app/Models/User.php:81, TeacherController.php:211, CenterController.php:268, ManagerManagementController.php:174 revoke tokens on deactivation so the deactivated-account path is 401→login→403 Arabic message (AuthController.php:42-57, login.js:38-43), never per-widget 403s; frontend-html/js/auth.js:57-60 requireAuth redirects when token missing regardless of stale STORAGE_USER; 419 never emitted (bearer-token API, no CSRF).
```

</details>

### 7-day Bearer token in localStorage, with inline scripts/842 inline styles making a mitigating CSP impossible and no refresh/rotation

<a id="token-localstorage-no-csp-no-refresh"></a>

`token-localstorage-no-csp-no-refresh` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/js/auth.js:8-11`, `frontend-html/js/api.js:22-24`, `frontend-html/js/config.js:23-24`, `backend/config/sanctum.php:55`

**Evidence**

```text
auth.js:9 `localStorage.setItem(C.STORAGE_TOKEN, token)`; api.js:23 reads it per request; sanctum.php:55 `'expiration' => 10080` (7 days); grep for Content-Security-Policy across backend/.htaccess, public/.htaccess, app/, config/ -> none; frontend-html has no .htaccess. 842 `style="..."` attributes and 31 inline <script> blocks would each require 'unsafe-inline'. No /auth/refresh route exists (routes/api.php) and no code path rotates the token.
```

**Why it matters**

Any single XSS (the codebase is disciplined today, but has no linter or tests to keep it so) yields a 7-day replayable credential for an admin who manages minors' data across dozens of centers. localStorage is also readable by any script on the origin (e.g. a future analytics/chat snippet). Because a CSP cannot be applied, the defence is entirely 'never make a mistake'.

**Recommendation**

Short term (after inline-js extraction): ship `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self' https://api-host; img-src 'self' data:; frame-ancestors 'none'` and move toward nonce'd styles. Medium term for web: switch to Sanctum SPA cookie auth (httpOnly, SameSite) or shorten token life to hours with a refresh endpoint - the same refresh endpoint the Flutter app needs (it should store tokens in flutter_secure_storage). Add `Auth.requireAuth` revalidation via GET /auth/user on page load to detect revoked tokens early.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Core facts verified: auth.js:9 stores the plain Bearer token in localStorage; api.js:22-24 reads it per request; sanctum.php:55 sets expiration 10080 (7 days); no Content-Security-Policy header or <meta http-equiv> anywhere in backend/ or frontend-html/; routes/api.php has no /auth/refresh and AuthController::login (line 64) issues a token with no rotation path. Inline-style count is 785 (not 842) and there are 31 inline <script> blocks across 31 pages plus 3 inline on*= handlers. So the finding is not factually wrong. However it is over-rated and partly overstated: (1) 'CSP impossible' is false — style-src 'unsafe-inline' is a normal, low-risk allowance, and the 31 inline scripts can be hash-allowlisted (or extracted) without touching the 785 styles; only the 3 inline handlers need rewriting. (2) The 'no code path revokes/detects revoked tokens' angle is already mitigated server-side: deactivating a user/center/manager and any password change call tokens()->delete(), and this is covered by six feature tests (CenterStatusTest, ManagerStatusTest, ManagerTeacherStatusTest, TeacherStatusTest, TeacherProfileTest, OtpResetTest). (3) The recommended 'revalidate via GET /auth/user on page load' is effectively already achieved: every protected page calls API.get(...) immediately after Auth.requireAuth, and api.js:50-57 clears storage and redirects on 401, so a revoked/expired token is detected on the first page load. (4) XSS surface is disciplined: only 10 innerHTML sites (layout.js/ui.js), rendering goes through escapeHtml (ui.js:116). localStorage bearer tokens with a 7-day TTL is the standard Sanctum SPA/mobile pattern for a decoupled static client (a cookie-based SPA mode would require same-site hosting the product does not have). Net: a real defence-in-depth hardening gap (add CSP with script hashes, consider shorter TTL) with no demonstrated exploit and existing compensating controls — low, not medium.

```text
frontend-html/js/auth.js:8-11 (localStorage.setItem token); frontend-html/js/api.js:22-24 (getToken) and :50-57 (401 -> clear storage + redirect to login — acts as revalidation since every protected page calls API.get on load); backend/config/sanctum.php:55 ('expiration' => 10080); backend/routes/api.php:28 (/auth/user exists; no refresh route); backend/app/Http/Controllers/Api/AuthController.php:62-64 (createToken, no rotation). Counts: 785 style="..." attributes (not 842), 31 inline <script> blocks, 3 inline on*= handlers. No CSP header/meta anywhere. Compensating controls: server-side token revocation on deactivate/password change covered by backend/tests/Feature/{CenterStatusTest,ManagerStatusTest,ManagerTeacherStatusTest,TeacherStatusTest,TeacherProfileTest,OtpResetTest}.php; frontend-html/js/ui.js:116 escapeHtml used at all templated innerHTML sites.
```

</details>

### No global error handler or client telemetry - frontend failures in production are invisible

<a id="no-client-error-capture"></a>

`no-client-error-capture` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/js/api.js:1-85`, `frontend-html/js/config.js:1-44`, `frontend-html/js/layout.js:219-229`

**Evidence**

```text
grep for `window.onerror`, `unhandledrejection`, `addEventListener('error'` across frontend-html -> 0 matches. grep for `visibilitychange`/`document.hidden` -> 0. Errors surface only as UI.toast (58 sites) which nobody but the end user sees; layout.js:257 `setInterval(() => load(true), 60000)` polls notifications on every open tab forever with no backoff or visibility pause.
```

**Why it matters**

When a center manager in another city hits a broken flow, the team learns about it by phone, not by dashboard; there is no release-health signal to decide whether a deploy is safe. Ties directly to the 'measured' (level 4) criterion. Unpaused polling from many idle tabs at dozens of centers is avoidable API load on a single shared-hosting PHP instance.

**Recommendation**

Add a 30-line js/telemetry.js: window.onerror + unhandledrejection -> POST /api/client-errors (rate-limited, includes page, user role, app version, UA) or a hosted Sentry DSN; include an X-Client-Version header on every API call (api.js:28) so backend logs can correlate. Pause the notification interval on document.hidden and back off on failures.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Traced the cited code and re-ran the greps. Factual claims all confirmed: (1) zero matches for window.onerror / unhandledrejection / addEventListener('error') / visibilitychange / document.hidden anywhere in frontend-html (js + html); (2) api.js sends only Accept/Authorization/Content-Type — no client version header, no error reporting hook, network failures are converted to an ApiError and rethrown to the page which at most shows a toast (ui.js:9 toast(); ~87 toast call sites); (3) layout.js:257 `setInterval(() => load(true), 60000)` runs unconditionally in Layout.mount, which is invoked by all 31 protected pages, with no visibility pause or backoff (the catch at layout.js:226 silently swallows failures in silent mode, so a dead API is retried every 60s forever). No mitigation exists elsewhere: no /api/client-errors route, no Sentry/monitoring package in composer.json, config/logging.php, or DEPLOYMENT.md; backend Laravel logs only capture server-side exceptions, not JS-side breakage (e.g. a rendering bug that produces `undefined` — exactly the class of bug fixed in recent commit 37313bf, which would have been invisible to telemetry-less operators). So the finding is correct and not mitigated. However, it is overrated as medium for this product: it is an observability gap, not a defect — nothing behaves incorrectly, no security or data impact. The polling-load argument is weak: one GET /notifications per open tab per minute returning ≤30 rows (NotificationController::index, limit(30)) is negligible even on shared hosting for a small single-country deployment with dozens of centers; a manager rarely leaves multiple tabs open. The 'measured/level 4' maturity criterion is aspirational for a small-team Arabic-only center-management app. Recommendation is reasonable and cheap (a global onerror + document.hidden guard), so worth doing, but as a low-severity improvement.

```text
frontend-html/js/api.js:28-42 — headers are only Accept / Authorization / Content-Type; api.js:46-48 fetch failure -> ApiError(…,0) rethrown, no reporting. frontend-html/js/layout.js:219-229 load() swallows errors when silent; layout.js:257 `setInterval(() => load(true), 60000)` unconditional, no visibility/backoff; Layout.mount is invoked from 31 protected pages. grep across frontend-html for onerror|unhandledrejection|addEventListener('error'|visibilitychange|document.hidden -> 0 matches. ~87 toast/UI.toast call sites (ui.js:9 defines toast). No client-error route in backend/routes/api.php and no monitoring package in backend/composer.json or config/logging.php. backend NotificationController::index limit(30) — per-poll cost is tiny, weakening the load argument.
```

</details>

### API base URL chosen by hostname sniff with a hardcoded production path; no staging/env injection; README documents a stale constant

<a id="config-by-hostname-sniff"></a>

`config-by-hostname-sniff` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/js/config.js:10-12`, `frontend-html/README.md:6-10`, `DEPLOYMENT.md:14`

**Evidence**

```text
config.js:10-12 `const API_BASE_URL = (hostname === 'localhost' || hostname === '127.0.0.1') ? 'http://localhost:9090/api' : '/backend/public/api';`. README.md:8 still says `const API_BASE_URL = 'http://localhost:9090/api';` and DEPLOYMENT.md:14 says to 'update frontend-html/js/config.js (API_BASE_URL)' - neither matches the code. There is no staging branch of the ternary, no build-time substitution, and no way to point the static client at a separate API host (needed once the API is split from shared hosting or fronted by a CDN).
```

**Why it matters**

Any non-localhost hostname (a staging subdomain, a LAN IP used for phone testing, a preview deploy) silently hits the production-relative path; testers on 192.168.x.x get a broken client. The pattern also hard-couples the client to the shared-hosting layout `/backend/public/` that the .htaccess fence protects - moving the API to its own origin means editing code, not config.

**Recommendation**

Read configuration from a tiny generated `js/env.js` (window.__ENV = {API_BASE_URL, APP_VERSION, SENTRY_DSN}) written by the deploy step per environment (.cpanel.yml can echo it), falling back to the current heuristic only when absent. Fix README/DEPLOYMENT wording. Keep the same env.js contract for Flutter flavors (dev/staging/prod).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The quoted evidence is accurate. frontend-html/js/config.js:10-12 contains exactly the hostname ternary (localhost/127.0.0.1 -> http://localhost:9090/api, otherwise /backend/public/api); api.js:9,45 and ui.js:355 consume it unconditionally, so any non-localhost hostname (LAN IP for phone testing, a staging subdomain) does hit the relative production path with no override hook, no window.__ENV, no env.js anywhere in the repo. README.md:8 still documents the old constant `const API_BASE_URL = 'http://localhost:9090/api';` and DEPLOYMENT.md:13 (table row 5; auditor cited line 14 but content is the same) says to "update config.js (API_BASE_URL)" — both stale relative to the ternary. However, the finding is overrated: (1) the production branch is not an arbitrary hardcode — .cpanel.yml rsyncs frontend-html/ to public_html/ and backend/ to public_html/backend/, and backend/.htaccess + public/.htaccess fence that tree, so `/backend/public/api` is exactly correct for the only deployment that exists, and DEPLOY_LOG.md (commit f868880) records it as a deliberate decision to avoid CORS and a pinned domain. (2) The product has one environment (single cPanel shared host, one-country, small team) and no staging exists to break. (3) The only concrete regression is LAN-IP testing from a phone in dev, a developer-convenience issue trivially worked around (e.g. a local Apache with /backend/public, or a one-line edit) and not a user-facing or security defect. No behaviour is wrong in the shipped configuration; the issue is limited flexibility plus stale docs. That is a low-severity maintainability/documentation item, not medium.

```text
frontend-html/js/config.js:10-12 (hostname ternary, confirmed); frontend-html/js/api.js:9,45 and frontend-html/js/ui.js:355 (sole consumers, no override); frontend-html/README.md:8 (stale constant, confirmed); DEPLOYMENT.md:13 not :14 (row 5 "حدّث frontend-html/js/config.js (API_BASE_URL)"); .cpanel.yml lines 3-5 (frontend -> public_html/, backend -> public_html/backend/ — makes '/backend/public/api' correct for the actual production layout); backend/DEPLOY_LOG.md:28 and git commit f868880 (documented deliberate design). No env.js / window.__ENV anywhere in the repo.
```

</details>

### 842 inline style attributes and 529 hardcoded hex colours bypass the 23 design tokens defined in theme.css

<a id="inline-styles-bypass-tokens"></a>

`inline-styles-bypass-tokens` · 🟡 medium (reviewers → low) · ✅ confirmed · **LATER** · effort M (1–3 days)

**Files:** `frontend-html/css/theme.css:8-21`, `frontend-html/js/ui.js:17-25`, `frontend-html/js/layout.js:116-127`, `frontend-html/admin/students.html:415-418`

**Evidence**

```text
grep -c 'style="' across HTML+JS -> 842; hex colour literals in JS/HTML -> 529 (#7A8F82 x124, #04532F x72, #B23A48 x60, #9A7A1E x56, #9DB3A6 x50, #04361F x47, #D4AF37 x43) while theme.css:8-21 defines exactly these as --muted/--emerald/--c-danger/--gold-deep/--faint/--emerald-dark/--gold. Example: ui.js:18-21 toast colour table hardcodes the palette; layout.js:119-126 notification dropdown is ~600 chars of inline CSS.
```

**Why it matters**

A brand refresh, a dark/high-contrast mode (relevant for accessibility in a public-sector product), or a per-center theme requires touching hundreds of sites; the design-system handoff in _handoff2/ (هوية-متقن-للمطورين.css, 92 lines of tokens) cannot be adopted by swapping one file. Inline styles also force style-src 'unsafe-inline' in any CSP.

**Recommendation**

Promote the recurring inline patterns into mq-* utility/component classes (there are already precedents: mq-center-meta-item, mq-chip), replace hex literals in ui.js/layout.js with var(--token), and add a stylelint/ESLint rule forbidding new hex literals in JS. Do it incrementally per page as pages are extracted to js/pages/.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Evidence verified exactly: grep 'style="' across frontend-html HTML+JS = 842; six-digit hex literals = 529 with the same top-7 distribution (#7A8F82 x124, #04532F x72, #B23A48 x60, #9A7A1E x56, #9DB3A6 x50, #04361F x47, #D4AF37 x43), and theme.css:9-13 defines precisely these values as --muted/--emerald/--c-danger/--gold-deep/--faint/--emerald-dark/--gold. ui.js:17-22 hardcodes the toast palette; layout.js:116-127 is a ~600-char inline-CSS notification dropdown with #B23A48/#04532F/#9A7A1E/#9DB3A6 literals; admin/students.html:415-418 matches. Only 24 var(--...) references exist in HTML/JS versus 529 literals, so the tokens are effectively unused outside theme.css. The handoff file _handoff2/untitled/project/هوية-متقن-للمطورين.css exists (92 lines, though only 14 custom properties, not 92 tokens). So the finding is factually correct and not mitigated anywhere (no build step, no lint, no CSS post-processing). However the impact is overstated for this product: there is no dark mode or prefers-color-scheme handling anywhere (0 hits in theme.css), no CSP is configured in any HTML/.htaccess/PHP, and per-center theming is not a requirement — this is a pure maintainability/consistency concern with zero functional or security effect today. The CSP argument is also weak because the pages use inline <script> IIFEs, which would require 'unsafe-inline' regardless. For a small-team, single-brand, Arabic-only system, this is a low-severity code-quality debt item rather than medium.

```text
frontend-html/css/theme.css:9-13 (tokens --emerald:#04532F, --emerald-dark:#04361F, --gold:#D4AF37, --gold-deep:#9A7A1E, --muted:#7A8F82, --faint:#9DB3A6, --c-danger:#B23A48); frontend-html/js/ui.js:17-22 (toast colour table with literal #04532F/#B23A48/#9A7A1E/#2A6F8E) and :25 (cssText template); frontend-html/js/layout.js:116-127 (inline-styled notification dropdown, literals #B23A48, #04532F, #9A7A1E, #9DB3A6); frontend-html/admin/students.html:415-418 (banner with #9A7A1E/#04532F). Counts reproduced: 842 style=" attributes, 529 six-digit hex literals, only 24 var(--) usages in HTML/JS. Corrections: the handoff token file _handoff2/untitled/project/هوية-متقن-للمطورين.css is 92 lines but contains only 14 custom properties; theme.css has no prefers-color-scheme/data-theme support and no CSP header exists in the repo, so the dark-mode and CSP impacts are hypothetical.
```

</details>

### Accessibility is skin-deep: 4 of 68 labels bound with for=, no roles/aria-modal/focus trap on custom modals, 63 buttons without type

<a id="a11y-gaps-in-dynamic-ui"></a>

`a11y-gaps-in-dynamic-ui` · 🟡 medium (reviewers → low) · ✅ confirmed · **LATER** · effort M (1–3 days)

**Files:** `frontend-html/js/ui.js:139-212`, `frontend-html/js/ui.js:388-408`, `frontend-html/js/layout.js:135-137`, `frontend-html/admin/students.html:119-217`, `frontend-html/parent/dashboard.html:36`

**Evidence**

```text
grep: `<label` 68 vs `<label ... for=` 4; ` role="` 0; aria-* attributes total 10 (aria-expanded 3, aria-hidden 6, aria-label 1). formModal (ui.js:139-212) and confirmAction (ui.js:388-408) create overlays with no role=dialog/aria-modal, no initial focus, no focus trap, no Escape handler; close is mouse-only ([data-close] click, overlay mousedown). layout.js:136 uses native `confirm()` for logout. parent/dashboard.html:36 makes a card clickable via `onclick=` on a <div> with no keyboard affordance. Regex count of `<button` without `type=` in templates: 63.
```

**Why it matters**

Keyboard and screen-reader users (and public-sector procurement checklists, e.g. WCAG 2.1 AA) fail on every create/edit flow; mislabeled inputs also hurt autofill on phones. Buttons without type inside forms default to submit - a latent bug class as modals grow.

**Recommendation**

Fix once in the modal primitive (role=dialog, aria-modal, aria-labelledby, focus first field, trap Tab, Escape closes, restore focus); generate id/for pairs in UI.field; give clickable cards <a> semantics; add type="button" by default in actionBtn/templates; add axe-core to the Playwright smoke suite.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The factual core of the finding is confirmed by direct inspection. formModal (ui.js:139-212) builds an overlay div with no role/aria-modal/aria-labelledby, never moves focus into the dialog, has no keydown/Escape handler, and closes only via [data-close] click or overlay mousedown (ui.js:177-178). confirmAction (ui.js:388-408) is the same pattern (click-only, no focus management). A repo-wide grep finds zero `role=`, zero `aria-modal`, zero `tabindex`, zero `.focus(` calls, and the only keydown handlers are the Enter-to-send handlers in messages.html. Label counts match (68 `<label`, 4 with `for=`); UI.field generates `<label class="mq-label">` with no for/id pairing (ui.js:155). layout.js:136 uses native confirm() (though that is itself accessible, so it is not really an a11y defect). parent/dashboard.html:36 is a `<div onclick=...>` with no href/tabindex/keyboard handler — confirmed unreachable by keyboard.

Where the finding overstates: (1) my regex count of untyped buttons is 57, not 63 — minor. (2) The "latent bug: untyped buttons inside forms default to submit" is NOT currently manifest anywhere I checked: every untyped button (ui.js:277, 292, 446; admin/students.html close ×) sits outside a <form>, and every button inside the modal forms carries an explicit type (ui.js:165/169/170; admin/students.html add-form cancel/submit). So there is no present functional bug, only a hygiene risk. (3) A handful of the 64 unbound labels are wrapping labels (e.g., the gmode radios in admin/students.html) which are validly associated implicitly. (4) The WCAG/procurement impact framing is speculative for this product — an internal staff tool for Libyan Quran centers with no stated accessibility mandate; there is no evidence the product has keyboard-only or screen-reader users, and no test suite or backend mitigation is relevant either way.

Net: real a11y gaps in the shared modal primitive and clickable card, no functional bug, no mitigation elsewhere. Downgrading to low because the impact is usability/compliance-hygiene for an internal small-team tool rather than a correctness or security defect; the recommendation to fix once in formModal/confirmAction and actionBtn remains sensible and cheap.

```text
frontend-html/js/ui.js:140-178 formModal: plain div overlay, no role/aria-modal, no focus(), close only via [data-close] click (177) and overlay mousedown (178); ui.js:155 label emitted without for=; ui.js:388-405 confirmAction same pattern (click-only). Repo grep: 0 `role=`, 0 `aria-modal`, 0 `tabindex`, 0 `.focus(`; only keydown handlers are parent/messages.html:107 and teacher/messages.html:107 (Enter-to-send). Untyped `<button` count = 57 (not 63); none of them are inside a <form> — ui.js:277,292 (importSummaryModal, no form), ui.js:446 (actionBtn used in tables), admin/students.html:122 (× is outside the #add-form that starts at :123). All buttons inside forms have explicit type (ui.js:165,169,170; admin/students.html:209-210). layout.js:136 native confirm() (accessible, not a defect). parent/dashboard.html:36 `<div class="mq-card" onclick=...>` no href/tabindex/keydown.
```

</details>

### Residual output-handling inconsistencies: one unescaped innerHTML of server text, 5 double-escape sites, unencoded id in one API path, error keys used raw in selectors

<a id="residual-escaping-inconsistencies"></a>

`residual-escaping-inconsistencies` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/forgot-password.html:71`, `frontend-html/forgot-password.html:88-91`, `frontend-html/admin/students.html:387`, `frontend-html/admin/centers.html:39`, `frontend-html/admin/managers.html:70`, `frontend-html/admin/teachers.html:245`, `frontend-html/teacher/students.html:78`, `frontend-html/parent/child.html:35`, `frontend-html/admin/students.html:334`

**Evidence**

```text
forgot-password.html:71 `alertEl.innerHTML = msg;` where msg concatenates `res.dev_otp`, `res.dev_note`, `res.message`, `err.message` unescaped (:88-91, :107, :110) - trusted server strings today, but the only innerHTML sink of API text without escapeHtml. Double escaping in 5 places, e.g. admin/students.html:387 `UI.badge(UI.escapeHtml(s.center?.name || '—'), 'info')` while badge() (ui.js:111-113) escapes again -> a center named with '&' renders '&amp;'. parent/child.html:35 interpolates the query-string id raw into the URL path (`/parent/students/${id}?...`) whereas admin/center.html:42 and manager/teacher.html:37 use encodeURIComponent. admin/students.html:334 and 4 other pages build a CSS selector `[data-error="${k}"]` from server-provided error keys and index `err.errors[k][0]` assuming arrays (ui.js:84 guards with Array.isArray; the pages do not).
```

**Why it matters**

No exploitable XSS was found; these are consistency defects that a linter (no-unsanitized) and a single rendering layer would prevent, and the double-escape is a visible correctness bug for names containing & < > ' ".

**Recommendation**

Route forgot-password alerts through textContent + a separate <b> element for the OTP; remove the 5 redundant escapeHtml calls (or make badge accept pre-escaped HTML explicitly); encodeURIComponent all path params; centralise field-error mapping on UI.setFieldErrors(root, errors) and delete the per-page copies.

### Dead UI and stale documentation: unwired 'remember me', removed demo-accounts panel still documented, README/CLAUDE.md/launcher describe a client that no longer exists

<a id="dead-ui-and-frontend-doc-drift"></a>

`dead-ui-and-frontend-doc-drift` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/login.html:56-60`, `frontend-html/README.md:21-26`, `frontend-html/README.md:28-41`, `CLAUDE.md`, `تشغيل-المشروع.bat:27`

**Evidence**

```text
login.html:58 `<input type="checkbox" id="remember">` - grep 'remember' in js/ -> 0 uses (token is always persisted). grep 'demo-accounts|demoAccounts' in frontend-html -> 0, yet CLAUDE.md mentions it 3 times ('The login page pulls a live list from /api/public/demo-accounts') and DEPLOYMENT.md item 6 tells operators to remove 'زر التعبئة في js/pages/login.js' which does not exist. README.md:21 says demo password is `password` (CLAUDE.md: `[redacted-demo-password]`); README.md:32-34 lists admin/teacher/parent pages only - admin/users, admin/profile, admin/center, manager/* (9 pages), teacher/messages, teacher/student, parent/messages are absent; CLAUDE.md mentions users.html 0 times, manifest 0 times, describes the client as 'Bootstrap 5 RTL' (0 classes used). تشغيل-المشروع.bat:27 runs `cd ... \frontend && php artisan serve --port=9091` - `frontend/` no longer exists at all (`ls frontend` -> No such file), and paths point at C:\xampp\htdocs\MUTQENQ, not this repo.
```

**Why it matters**

New engineers (and the Flutter team using CLAUDE.md as the API/feature map) will look for features that were removed and miss 12 pages that exist; a visible 'remember me' control that does nothing erodes user trust and would fail a UX review.

**Recommendation**

Remove or wire the checkbox (sessionStorage when unchecked). Regenerate the frontend section of CLAUDE.md and README from the actual page list (33 pages, 4 roles), correct the password and API_BASE_URL statements, delete or fix the .bat launcher, and add a CI check that every frontend-html/*/*.html is mentioned in README.

### PWA is a manifest only: no service worker, SVG-only icon (no PNG 192/512, invalid apple-touch-icon), start_url login.html

<a id="pwa-manifest-without-installability-or-offline"></a>

`pwa-manifest-without-installability-or-offline` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `frontend-html/manifest.webmanifest:1-21`, `frontend-html/js/config.js:28-44`, `frontend-html/images/logo.svg`

**Evidence**

```text
config.js:30 comment: 'لا service worker (لا عمل دون اتصال بعدُ عمداً)'; grep serviceWorker/sw.js/workbox -> 0. manifest.webmanifest:13-20 has a single icon `images/logo.svg` sizes 'any'; images/ contains only logo.svg (1,251 bytes). config.js:43 sets `apple-touch-icon` to the SVG - iOS ignores SVG touch icons and shows a screenshot instead. start_url './login.html' bounces already-logged-in users through a redirect on every launch.
```

**Why it matters**

Chrome's install prompt and Play 'TWA' path want 192/512 PNG maskable icons; iOS home-screen icon is broken. Without a service worker there is no offline shell, so 'Add to Home Screen' users on flaky networks get the browser's error page. Since Flutter will be the primary mobile client, the PWA's value is limited to a bridge period - but a half-finished install experience damages trust with parents.

**Recommendation**

Either finish the minimum (add 192/512/maskable PNGs, apple-touch-icon 180 PNG, start_url './' resolving via auth.js, a tiny SW that precaches the shell and serves an Arabic offline page) or remove the PWA meta injection until Flutter ships, and say so in CLAUDE.md.

## Measured facts

| Metric | Value |
|---|---|
| HTML pages | 33 (3 public, 9 admin, 9 manager, 9 teacher, 3 parent) |
| Shared JS in js/ (lines) | 1,056 across 7 files (api 85, auth 82, config 44, layout 261, ui 455, landing 80, login 49) |
| Inline JS in HTML (lines) | 4,254 across 31 pages (largest: admin/students 495, manager/students 405) |
| Share of JS that is inline | 80.1% (4,254 / 5,310) |
| Inline CSS lines in <style> | 194 across 6 pages; theme.css 560 lines / 37.6 KB |
| Total frontend JS+CSS payload (unminified) | ~91 KB js+css from js/ and theme.css, plus ~230 KB unused Bootstrap RTL CSS from CDN per page |
| node --check syntax errors | 0 of 38 scripts |
| HTML structural errors / duplicate ids (static markup) | 0 / 0 across 33 pages |
| innerHTML / insertAdjacentHTML / textContent sinks | 144 / 10 / 39 |
| escapeHtml calls | 195; unescaped innerHTML of server text: 1 (forgot-password.html:71); double-escape sites: 5 |
| External resources | 1 CDN stylesheet on 33/33 pages (bootstrap@5.3.3 RTL) with 0 integrity attrs; 1 Google Fonts @import; Bootstrap classes used: 0 |
| Cache-busted asset references | 0 of 161 <script src> and 0 of 33 theme.css links |
| Inline style attributes / hardcoded hex colours / CSS tokens | 842 / 529 / 23 |
| Duplicated patterns | load-more pagination in 7 pages; fetchSeq race guard in 10 files; 12 hand-rolled modals in 8 files; inp() helper x3; info/meta helper x7; stat helper x8; 'جارٍ التحميل' markup x41 |
| teacher/messages vs parent/messages identical JS lines | 85 of 90 (ratio 0.94) |
| Dual API shape fallbacks (res.data.data \|\| res.data) | 13 |
| Tests / lint / build / type-check for frontend | 0 / 0 / none / none |
| Global error handlers / visibility-aware polling | 0 / 0 (notifications setInterval 60s on every page) |
| Accessibility markers | labels with for=: 4 of 68; role= attrs: 0; aria-* attrs: 10; buttons without type: 63 |
| Silent catch blocks | 8 of 92 (all comment-justified) |
| requireAuth role guards | 30 pages, each single-role (admin 9, center_manager 9, teacher 9, parent 3) |
| Frontend git history | 98 commits touching frontend-html, 2026-06-25 to 2026-09-11 |
| PWA | manifest present; service worker: none; icons: 1 SVG (no PNG 192/512) |

## Auditor notes

Verdict on keep / modularize / migrate: do NOT migrate the web client to React/Vue/Angular - with Flutter becoming the primary client for parents and teachers, a SPA rewrite would consume the same budget for little gain and the current pattern is already framework-free and small. Do a bounded modularization in this order: (1) mechanical extraction of the 31 inline scripts into js/pages/<role>/<page>.js (enables CSP, lint, tests, caching); (2) extract UI.pagedList / UI.modal / UI.field / UI.infoCell and parameterise the two messages pages; (3) ESLint + Vitest for js/ + an 8-spec Playwright smoke suite in CI next to `php artisan test`; (4) drop Bootstrap, self-host fonts, add ?v= hashing and a frontend-html/.htaccess with CSP + cache headers; (5) env.js injection and client telemetry. A light esbuild step is optional and can come later. Additional lower-severity observations not listed as findings: (a) messages pages have no polling/refresh - a thread only updates on open/send (teacher/messages.html:70-88); (b) `Auth.requireAuth` (auth.js:56-68) trusts the cached `mutqin_user` blob, so name/center_name/role changes show stale until re-login and there is no /auth/user revalidation on load (server-side gates make this safe, just stale); (c) layout.js:136 uses native window.confirm for logout while the rest of the app has UI.confirmAction - inconsistent UX; (d) `STATUS_AR[c.current_status] || c.current_status` in teacher/attendance.html:88-89 renders a server enum raw if unknown (trusted enum, negligible); (e) the design-system handoff in _handoff2/ (هوية-متقن-للمطورين.css 92 lines, mutqin.css 191, support.js 1,513) is not referenced by frontend-html at all - it is design intent, not code, and CLAUDE.md does not mention it; (f) repo carries 'Home photos/' and 'screenshots/' folders and 1.06 MB of gallery JPEGs without width/height attributes (CLS) - minor; (g) admin/students.html:26 builds a global teacherOpts() from all teachers while the modal correctly scopes by center - dead helper path retained; (h) the 'دليل-محتوى-الصفحات.md' page guide was not audited line-by-line but is likely to share the README's staleness. Doc drift summary for this dimension: CLAUDE.md describes a Bootstrap client with a demo-accounts login panel and omits 12 of 33 pages (admin/users, admin/profile, admin/center, manager/parents, manager/teacher, teacher/messages, teacher/student, parent/messages, plus the PWA manifest); frontend README lists a stale API_BASE_URL constant, wrong demo password, and only 3 of 7 page folders; DEPLOYMENT.md item 6 references a login.js fill button that does not exist; the .bat launcher targets a deleted frontend/ directory.
