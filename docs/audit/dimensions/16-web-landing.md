# Landing Page & Public Surface

[← Enterprise Audit](../enterprise-audit.md)

**Score 54 / 100** — Significant risk · maturity **L2** · weight 3%

The public surface is visually excellent and brand-faithful (tokens/fonts byte-match the _handoff2 design system, correct lang/dir, semantic HTML, responsive, reduced-motion aware, safe login/OTP backend semantics). But measured against a public enterprise landing that must front dozens of centers and an app-store listing, it has structural gaps: zero legal pages (privacy/terms) although the product processes minors' data; a forgot-password flow that can never deliver a code in production (sendOtp only logs) and excludes managers/admins; no acquisition CTA or reachable contact (email as plain text, no phone/WhatsApp/form); gallery photos of identifiable children copied byte-for-byte from Facebook/Google-Images/news downloads with no attribution or consent record; a dead public /public/demo-accounts endpoint that dumps every user's name+email whenever APP_DEBUG=true; 233 KB of unused Bootstrap plus a serial @import font chain; 1.08 MB of unoptimized JPEGs; no OG/favicon/robots/sitemap; no security or cache headers on the static host and a README with stale credentials deployed to the web root. No analytics, no Lighthouse budget, and no tests for the public endpoints, so nothing about this surface is measured. That is "significant risk" (40-59) with a well-executed design on top, hence 54, maturity 2 (repeatable patterns, not defined or measured).

## What is already strong

- Brand fidelity is genuinely high: frontend-html/css/theme.css:8-13 tokens (--emerald:#04532F, --emerald-dark:#04361F, --jade:#006850, --gold:#D4AF37, --gold-deep:#9A7A1E, --ivory:#FBF7DA) are identical to _handoff2/untitled/project/هوية-متقن-للمطورين.css:11-19; fonts are exactly Amiri/Cairo (22 + 4 font-family declarations, none off-brand); a hex diff of index.html/login.html against the handoff found only 3 tint shades (#C9DCD1,#CFE0D6,#F0DFA6) not traceable to a design file; the eight-point-star motif is reused consistently in nav, hero, ayah divider, login brand panel and images/logo.svg.
- Correct international/semantic foundations: `<html lang="ar" dir="rtl">` on all three public pages; descriptive `<title>` + `<meta name=description>` (index.html:6-7); header/nav/section/footer landmarks (DOM check: header 1, nav 1, section 7, footer 1); exactly one H1; all 11 gallery images carry Arabic alt text and loading="lazy" (index.html:309-319).
- Responsive implementation is real, not nominal: unified breakpoints 1024/768/480/360 (index.html:39-64), gallery grid 3->2->1 (index.html:70-72); verified in-browser at 375x812 that document.scrollWidth == 375 (no horizontal overflow) and .hero-art zoom 0.75 applies; burger menu has aria-label + aria-expanded toggled in landing.js:32-43; CTAs meet 44px min-height (.pill, index.html:17).
- Motion is accessible and progressive: `@media (prefers-reduced-motion: reduce)` blocks in index.html:123-131 and login.html:20; scroll-reveal adds .reveal from JS only (landing.js:54-77 comment: without JS or with reduced motion the page stays fully visible) and bails when IntersectionObserver is absent.
- Login hardening on the public edge: throttle:10,1 on /auth/login (routes/api.php:16-17); unified Arabic 422 message that does not reveal which field failed (AuthController.php:80-87); deactivated user/center checked only after password verification to avoid leaking account state (AuthController.php:39-59); iOS keyboard whitespace stripped from the identifier (login.js:25-27) with autocapitalize/autocorrect/spellcheck off (login.html:44).
- OTP reset backend is correctly conservative: neutral response regardless of phone existence (AuthController.php:117-126), hashed OTP, 10-minute expiry, 5-attempt cap, dev_otp strictly gated on environment('local') with an explicit comment warning against APP_DEBUG gating (AuthController.php:149-155); throttles 5/min and 10/min on the two endpoints (routes/api.php:20-21).
- Public stats are fail-safe: landing.js:10-24 keeps hardcoded fallbacks on network failure (observed: ERR_CONNECTION_REFUSED to localhost:9090 left the page intact); numbers rendered in Arabic-Indic digits via ar() (landing.js:6-8).
- PWA scaffolding is centralised: config.js:31-44 injects manifest, theme-color #04532F, standalone/apple meta into every page; manifest.webmanifest sets lang/dir rtl, standalone, portrait.
- Production environment template is safe by default: backend/.env.production.example sets APP_ENV=production, APP_DEBUG=false, LOG_LEVEL=error and CORS_ALLOWED_ORIGINS; config/cors.php is env-driven with a closed default (http://localhost:8080), not '*'.

## Level-5 target state

A level-5 public surface is a measured marketing and trust asset, not just a pretty door to the login. It carries legal pages (privacy, terms, data-deletion), a real acquisition path (center registration/demo form, phone/WhatsApp), store badges and deep-link files (assetlinks.json / apple-app-site-association) once the Flutter app ships, and OG/favicon/robots/sitemap/JSON-LD so every WhatsApp share and search result looks intentional. It ships under a Lighthouse budget (LCP < 2.5 s on 3G, images < 300 KB total, self-hosted or preconnected fonts, no unused CSS), behind hardening headers (HSTS, CSP, X-Frame-Options, cache policy), with the only public API endpoints cached, throttled and covered by feature tests. Photos are owned/licensed with consent records, the OTP flow actually delivers via an SMS provider for every self-service role, and analytics plus a synthetic uptime check on /public/stats make the page's health and conversion measurable release over release.

## What the Flutter team must know

The mobile team inherits this surface in four concrete ways. (1) Store submission is blocked until a privacy-policy URL exists on the public domain — the landing must host it (finding no-legal-pages). (2) The app will reuse /auth/login, /auth/forgot-password/request and /verify; login accepts email OR display code (T1/CA1/P1) in the `email` field with min-6 password and returns Arabic 422s; the OTP flow never sends SMS today and only serves parent/teacher, so the app must not promise a code until a gateway exists and needs a manager fallback (finding forgot-password-dead-end). (3) /api/public/stats and /api/public/demo-accounts are unauthenticated, unthrottled and uncached; do not call demo-accounts from the app and expect it to be removed. (4) Brand assets: the only logo is images/logo.svg with a webfont-dependent <text> wordmark — the app needs outlined/PNG icon exports (192/512/maskable/1024 store icon) and the palette from theme.css:8-13 (#04532F/#04361F/#D4AF37/#FBF7DA, Amiri+Cairo); the gallery photos must not be reused in store screenshots (provenance/consent). Deep-link files for universal links are not present yet; plan them with the landing's future .htaccess.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [No privacy policy or terms anywhere on the public surface (blocks app-store submission for a minors'-data product)](#no-legal-pages) | 🔴 critical<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Forgot-password page promises an SMS code that is never sent in production, and excludes managers/admins](#forgot-password-dead-end) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Landing has no lead-capture path: only 'login' CTAs, contact is plain text, and the footer brands the platform as a single center](#no-acquisition-cta) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Gallery photos of identifiable minors are byte-identical copies of files downloaded from Facebook/Google Images/news sites with no attribution or consent record](#gallery-photos-provenance) | 🟠 high | ✅ confirmed | NEXT | S |
| [Dead public endpoint /api/public/demo-accounts still dumps every user's name+email+role whenever APP_DEBUG=true, unthrottled and untested](#demo-accounts-endpoint-debug-gated) | 🟡 medium | ✅ confirmed | NOW | S |
| [233 KB Bootstrap RTL CSS is render-blocking on all three public pages yet no Bootstrap class is used; fonts load through a serial @import with no preconnect or SRI](#unused-bootstrap-and-font-chain) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [1.08 MB of unoptimized JPEGs; largest is 2560x1440 served into a 751x235 slot; no srcset/WebP; one image is upscaled](#gallery-images-unoptimized) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [No Open Graph/Twitter cards, canonical, favicon, robots.txt, sitemap or structured data; heading levels skip](#seo-social-meta-missing) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [PWA/home-screen icon set is SVG-only with a webfont-dependent wordmark; iOS and Android install paths degrade](#pwa-manifest-icons) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [On phones the login form sits below a full-height brand panel, and the nav login pill wraps to two lines](#login-mobile-layout) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [No .htaccess for the static root: no HSTS/CSP/X-Frame-Options, no caching, and README.md with stale credentials is deployed to public_html](#static-host-no-headers-readme-exposed) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| ['تذكّرني' checkbox is decorative — never read; the token is always persisted in localStorage](#remember-me-dead-control) | ⚪ low | ℹ️ informational | NEXT | S |
| [Accessibility gaps on the three public pages: unlabeled inputs on forgot-password, missing autocomplete hints, emoji-only toggle, alerts without role](#public-forms-a11y-gaps) | ⚪ low | ℹ️ informational | NEXT | S |
| [Docs, screenshots and the content guide describe a login demo panel, Blade pages and CORS settings that no longer exist](#doc-drift-public-surface) | ⚪ low | ℹ️ informational | NEXT | S |
| [/public/stats is unthrottled and uncached, counts inactive users, and the two hardcoded fallback sets on the page contradict each other](#public-stats-uncached-inconsistent) | ⚪ low | ℹ️ informational | LATER | S |

### No privacy policy or terms anywhere on the public surface (blocks app-store submission for a minors'-data product)

<a id="no-legal-pages"></a>

`no-legal-pages` · 🔴 critical (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `frontend-html/index.html:355-371`, `frontend-html/login.html:70`, `frontend-html/forgot-password.html:56`

**Evidence**

```text
grep -rniE 'الخصوصية|privacy|شروط الاستخدام|terms' frontend-html/*.html js/*.js js/pages/*.js -> no matches. HTTP probe over the static root: privacy.html 404, terms.html 404. Footer 'روابط' column (index.html:357-363) lists only #about/#features/#gallery/login.html; copyright line index.html:373 has no legal links. The product stores children's names, ages, national_id, attendance and guardians' phones (Student model per CLAUDE.md).
```

**Why it matters**

Apple App Store Review 5.1.1 and Google Play's User Data policy require a publicly hosted privacy-policy URL in the store listing; without it the Flutter app cannot be submitted. A platform processing minors' data across dozens of centers with no published terms/privacy/data-retention statement is also a direct liability for the operator and for each center.

**Recommendation**

Add privacy.html and terms.html (Arabic, brand template) covering data collected per role, minors' data, retention, deletion requests, contact; link them in the landing footer, login footer, manifest and the future app 'About' screen. Add a data-deletion request contact (store requirement).

<details><summary>Verification — 2 independent reviewers</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual evidence holds: grep for الخصوصية|privacy|شروط الاستخدام|terms|سياسة across frontend-html/*.html, js/, and manifest.webmanifest returns zero matches; frontend-html/ contains only index.html, login.html, forgot-password.html plus role folders (no privacy.html/terms.html); the index footer 'روابط' column (index.html:357-363) links only #about/#features/#gallery/login.html, the contact column lists only info@mutqin.ly + بنغازي، ليبيا, and the copyright lines (index.html:373, login.html:70, forgot-password.html:56) carry no legal links. manifest.webmanifest has no privacy/terms field either. No backend route or resource serves legal text. So the gap is real and unmitigated. However the severity is inflated: (1) there is no Flutter/mobile app anywhere in this repo — only a PWA manifest — so 'blocks app-store submission' is a hypothetical about a product that does not exist yet, not a current defect; (2) this is a legal/content omission, not a code-correctness or security failure, and nothing in the running system behaves incorrectly because of it; (3) the deployment target is a single-country (Libya), Arabic-only, operator-provisioned system with no self-registration, so no consent-at-signup flow is missing on the public surface; the actual data controllers (centers) provision accounts offline. It remains a legitimate finding worth fixing before any public launch or store listing, and minors' data raises its weight, but it is a medium-severity compliance/roadmap item, not critical.

```text
frontend-html/index.html:357-363 (footer links: #about, #features, #gallery, login.html only); frontend-html/index.html:373, frontend-html/login.html:70, frontend-html/forgot-password.html:56 (copyright lines, no legal links); frontend-html/manifest.webmanifest (PWA manifest, no privacy URL); repo root has no Flutter/mobile project directory (ls: backend, frontend-html, n8n, screenshots, _handoff2, 'Home photos'), so the app-store blocker is prospective, not current.
```

---

**Upheld** · confidence 85% · corrected severity: medium

Factual core confirmed: grep over frontend-html (html/js/json) for الخصوصية|privacy|شروط الاستخدام|terms|سياسة returns nothing; frontend-html/ contains only index.html, login.html, forgot-password.html plus role folders (no privacy.html/terms.html); index.html footer 'روابط' column (lines 357-363) links only #about/#features/#gallery/login.html; copyright lines (index.html:373, login.html:70, forgot-password.html:56) carry no legal links; backend/routes/api.php has no privacy/terms/legal route and there are no Blade views for such pages. Nothing elsewhere mitigates this (it is a content/compliance gap, not something middleware, casts, DB constraints or feature tests can cover). However the severity is overstated: (1) the headline impact rests on a Flutter app and store submission that do not exist anywhere in the repo, git history, DEPLOYMENT.md or README — the only 'app' is a PWA manifest.webmanifest, which needs no store listing and no privacy URL; the store-blocker is speculative future work, not a present defect. (2) The product is Libya-only (manifest description, footer 'بنغازي، ليبيا', .ly domain); Libya has no enforced comprehensive data-protection statute equivalent to GDPR/COPPA, so 'direct liability' is asserted, not demonstrated. (3) Accounts are provisioned only by admins/center managers (CLAUDE.md: no self-registration) and data is entered by staff about enrolled students under an existing offline relationship between centers and guardians — there is no public sign-up funnel where consent/terms would gate anything. (4) No data exfiltration, security or correctness bug is involved; a missing policy page does not change what is stored or who can read it. Net: a real governance/compliance gap that should be fixed before any store submission or wider rollout (cheap to add), but it is not a 'critical' finding in an engineering audit — medium is appropriate; it would rise to high only if/when a native app store release is actually planned.

```text
frontend-html/index.html:357-363 (footer links: #about, #features, #gallery, login.html only); frontend-html/index.html:373, login.html:70, forgot-password.html:56 (copyright lines, no legal links); frontend-html/manifest.webmanifest:1-20 (PWA manifest, Libya-only description, no Flutter/native app anywhere in repo — `git log` and DEPLOYMENT.md/README.md contain no flutter/app-store/play references); backend/routes/api.php has no privacy/terms/legal route; grep -rniE 'الخصوصية|privacy|شروط الاستخدام|terms|سياسة' frontend-html --include=*.html,*.js,*.json → no matches.
```

</details>

### Forgot-password page promises an SMS code that is never sent in production, and excludes managers/admins

<a id="forgot-password-dead-end"></a>

`forgot-password-dead-end` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:204-211`, `backend/app/Http/Controllers/Api/AuthController.php:122`, `backend/app/Http/Controllers/Api/AuthController.php:181`, `frontend-html/forgot-password.html:23`, `frontend-html/forgot-password.html:88-92`

**Evidence**

```text
AuthController.php:207-210: `Log::info("OTP password-reset for user #{$user->id} ..."); // TODO(SMS): SmsGateway::send(...)` — the single send point only writes a log line. User lookup is `User::whereIn('role', ['parent', 'teacher'])` (lines 122 and 181), so center_manager/admin get the neutral 'sent' message but can never reset. forgot-password.html:91 shows 'إن كان الرقم مسجّلاً فستصل الشفرة.' to the user; the page subtitle (line 23) says 'لولي الأمر والمحفّظ'. DEPLOYMENT.md item 4 acknowledges: 'بدونها لا يمكن لولي الأمر/المحفّظ استعادة كلمة المرور ذاتياً في الإنتاج'.
```

**Why it matters**

Every locked-out parent or teacher on the public site (and in the Flutter app, which will reuse these endpoints) is told a code is on its way and waits forever; managers get the same message with no path at all. At dozens of centers this becomes the #1 support ticket and erodes trust in the login surface on day one.

**Recommendation**

Integrate an SMS provider (Libyana/Madar/Twilio-style) behind sendOtp() with a queued/logged delivery record; until then, hide the OTP form behind a feature flag and show 'اتصل بإدارة مركزك' with the center phone. Extend the flow to center_manager (admin can stay manual). Add a feature test that asserts the SMS gateway is invoked and that non-local env never returns dev_otp.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Every factual claim checks out on trace. AuthController::sendOtp() (lines 216-220) only calls Log::info and carries the TODO(SMS) comment; no SmsGateway, mail, queue, or n8n hook exists anywhere in backend/ (.env.production.example and config/ have no SMS key). Both forgotPasswordRequest (line 132) and forgotPasswordVerify (line 184) filter `whereIn('role', ['parent','teacher'])`, so a center_manager or admin phone gets the neutral 'sent' message and can never complete step 2. The frontend (forgot-password.html lines 40, 91) tells the user a 6-digit code was sent, and login.html:61 links to that page, so the dead end is reachable from the public surface. DEPLOYMENT.md item 4 explicitly concedes the gap. OtpResetTest only asserts dev_otp is absent outside local; nothing asserts actual delivery. Partial mitigations exist: admins can set teacher passwords (TeacherController::update line 169-176) and manager passwords (ManagerManagementController::update 126-133), managers can set their teachers' passwords (CenterManagerController 422), and the exclusion of admin/manager from OTP is a deliberate design choice documented in the code comment (line 131). However, parents have NO manual reset path at all (no admin/manager endpoint updates a parent's password; ParentResolver only sets it at creation), so a locked-out parent is truly stranded in production. The finding is real but the honest framing is 'documented known gap, misleading UX' rather than a defect that surprises the team; the manager exclusion is by design and managers have an admin-driven fallback. I lower severity to medium: it is a pre-launch deployment blocker already listed in DEPLOYMENT.md, not a hidden defect, and the copy is already hedged ('إن كان الرقم مسجّلاً'). The strongest residual point is the missing manual reset path for parents.

```text
backend/app/Http/Controllers/Api/AuthController.php:216-220 sendOtp() = Log::info only + TODO(SMS); :132 and :184 whereIn('role',['parent','teacher']); :131 comment shows admin exclusion is intentional. frontend-html/forgot-password.html:40 ('أُرسلت شفرة...'), :91; frontend-html/login.html:61 links the page. DEPLOYMENT.md:12 documents the gap as a pre-launch item. Mitigations: TeacherController.php:169-176, ManagerManagementController.php:126-133, CenterManagerController.php:422-424 allow admin/manager to set teacher/manager passwords manually. No mitigation for parents: StudentController.php/ParentResolver.php set guardian password only at creation; no endpoint updates a parent password. tests/Feature/OtpResetTest.php:22-33 asserts only dev_otp absence, not delivery.
```

</details>

### Landing has no lead-capture path: only 'login' CTAs, contact is plain text, and the footer brands the platform as a single center

<a id="no-acquisition-cta"></a>

`no-acquisition-cta` · 🟠 high (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/index.html:153`, `frontend-html/index.html:183-185`, `frontend-html/index.html:273`, `frontend-html/index.html:353`, `frontend-html/index.html:366-370`

**Evidence**

```text
All three CTAs are `data-login-link href="login.html"` (index.html:153, 183, 273); the secondary hero button is an in-page anchor `href="#features"` (184). Contact column (366-370) is `<li>info@mutqin.ly</li><li>بنغازي، ليبيا</li>` — no mailto:, tel:, WhatsApp or form (grep for 'mailto:|tel:|wa.me' returned nothing). Footer line 353 reads 'مركز بلال بن رباح لتحفيظ القرآن الكريم' under the platform logo. There is no 'سجّل مركزك / اطلب عرضاً' section; the content guide's CTA section ('سجّل الآن، تحدّث إلى معلّم', دليل-محتوى-الصفحات.md:66) was never implemented.
```

**Why it matters**

A landing meant to scale to dozens of centers gives a prospective center director nothing to click except a login he does not have; the only contact is an email he must retype. Attributing the platform to one center also undermines the multi-tenant SaaS positioning and may confuse other centers' parents.

**Recommendation**

Add a 'للمراكز' section with a request-demo/registration form (or at least mailto:/tel:/WhatsApp deep links) and a clear phone; move the Bilal-ibn-Rabah credit into a 'شركاؤنا/المراكز المنضمة' strip; add an 'App coming soon' / store-badge placeholder block for the Flutter launch.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The quoted evidence is accurate: frontend-html/index.html has three `data-login-link href="login.html"` CTAs (lines 153, 183, 273), the secondary hero button is `href="#features"` (184), the contact column is plain-text `<li>info@mutqin.ly</li><li>بنغازي، ليبيا</li>` (366-370) with no mailto:/tel:/wa.me anywhere in the file, and line 353 credits «مركز بلال بن رباح لتحفيظ القرآن الكريم». However the finding's severity and framing are exaggerated. (1) It is a marketing/content gap, not a correctness or security defect — nothing malfunctions. (2) The product deliberately has no self-registration (CLAUDE.md: parents/teachers/managers are created server-side only), so a 'register your center' form would have no backend to land on; the only real gap is a missing mailto:/tel: deep link. (3) The single-center attribution reflects an approved decision recorded in the repo: backend/database/seeders/LibyanDataSeeder.php:54 says «مركز واحد: بلال بن رباح (قرار معتمد: ديمو بمركز واحد و50 طالباً)» — the platform is currently deployed for one center, so the footer credit is intentional, not an error. (4) The cited content guide (دليل-محتوى-الصفحات.md:66) describes an older Blade landing (`welcome.blade.php`) with student-oriented CTAs («سجّل الآن، تحدّث إلى معلّم»), not a center-acquisition form; it is a stale spec, not an unimplemented requirement for B2B lead capture. The 'SaaS positioning for dozens of centers' premise is the auditor's assumption, not a documented product goal. Real but minor: downgrade to low.

```text
frontend-html/index.html:153,183,273 (login CTAs), :184 (#features anchor), :338 (footer is the #contact target), :366-370 (plain-text email/city, no mailto:/tel:), :353 (Bilal ibn Rabah credit). Mitigating context: backend/database/seeders/LibyanDataSeeder.php:54 «قرار معتمد: ديمو بمركز واحد» (single-center deployment is an approved decision); CLAUDE.md 'No self-registration' (registration form has no backend by design); دليل-محتوى-الصفحات.md:59-66 describes the older welcome.blade.php with student CTAs, not a center lead-capture section.
```

</details>

### Gallery photos of identifiable minors are byte-identical copies of files downloaded from Facebook/Google Images/news sites with no attribution or consent record

<a id="gallery-photos-provenance"></a>

`gallery-photos-provenance` · 🟠 high · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/img/gallery/g04-listening.jpg`, `frontend-html/img/gallery/g07-tasmee-bench.jpg`, `frontend-html/img/gallery/g11-competition-boys.jpg`, `Home photos/629319058_1229473859375865_3097274146528381499_n.jpg`, `Home photos/مصطفى-المهدوي-720x470.jpg`, `frontend-html/index.html:309-319`

**Evidence**

```text
Byte sizes match exactly between 'Home photos/' and img/gallery/: 629319058_1229473859375865_..._n.jpg (Facebook CDN naming) 145874 B == g04-listening.jpg; مصطفى-المهدوي-720x470.jpg (WordPress news thumbnail named after a person) 65334 B == g07-tasmee-bench.jpg; photo_6_2024-11-24_10-21-19.jpg (Telegram export) 52802 B == g11-competition-boys.jpg; images (1..5).jpg (Google Images downloads) == g02/g03/g05/g06/g01; DSC09960-scaled.jpg 393872 B == g10-judges-panel.jpg. Commit 7c86c22 describes them as '11 صورة حقيقية من الكتاتيب والمساجد'. grep for 'مصدر الصور|photo credit|attribution' -> none. Captions (index.html:309-319) present them as the platform's own centers ('من مراكزنا').
```

**Why it matters**

Publishing third-party photographs of identifiable children on a commercial landing page (and, by extension, in store listings) without a licence or guardian consent is a copyright and personality-rights exposure for the operator, and reputationally toxic for a Quran-centre brand if a parent recognises their child.

**Recommendation**

Replace with photos the operator owns (with written guardian consent for minors) or licensed stock; keep a consent/licence register per image; remove 'Home photos/' from the repo. Until then, blur faces or drop the section.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: high

Every factual claim checks out. (1) MD5 hashes confirm all 11 files in frontend-html/img/gallery/ are byte-identical to the 11 files in the git-tracked 'Home photos/' directory (both directories are tracked: `git ls-files` returns 11 entries each), e.g. g04-listening.jpg == 629319058_1229473859375865_3097274146528381499_n.jpg (6882b25c...), g07-tasmee-bench.jpg == مصطفى-المهدوي-720x470.jpg (cf962a4f...), g11-competition-boys.jpg == photo_6_2024-11-24_10-21-19.jpg (45f75d0e...), g10-judges-panel.jpg == DSC09960-scaled.jpg (260d5b5a...), g01/g02/g03/g05/g06/g09 == images (5)/(4)/(3)/(2)/(1)/images.jpg. The source filenames follow Facebook-CDN, Google-Images-download, Telegram-export and WordPress-thumbnail naming conventions. (2) Commit 7c86c22 body literally says «11 صورة حقيقية من الكتاتيب والمساجد والمسابقات الليبية». (3) index.html:303-320 wraps the gallery under the kicker «من مراكزنا» and the tagline presents them as scenes from Libyan centers; no credit/licence/source text exists anywhere in the page or docs (grep for مصدر الصور/photo credit/attribution/license/رخصة returns nothing). (4) Opening g11-competition-boys.jpg confirms clearly identifiable, close-up faces of two children. There is no mitigation possible in code (no middleware, test, or config addresses content provenance), and nothing in the repo records consent or licence. Only caveats: the exact origin sites are inferred from filenames, not proven, and the auditor's mention of "store listings" is speculative (there are none in this repo); the operator appears to be an academic team (author email at limu.edu.ly), which slightly lowers commercial exposure but not the personality-rights/copyright issue on a publicly served landing page. Severity 'high' is defensible for a compliance/reputational finding; it is not a code-correctness defect, so if the audit weights technical defects higher it could be scored medium, but I keep high given identifiable minors on a public page with no consent trail.

```text
MD5 identity (verified): frontend-html/img/gallery/g04-listening.jpg == "Home photos/629319058_1229473859375865_3097274146528381499_n.jpg" (6882b25c270b0a357f73fe7a56684519); g07-tasmee-bench.jpg == "Home photos/مصطفى-المهدوي-720x470.jpg" (cf962a4f806cba4e930a70d7cf11889e); g11-competition-boys.jpg == "Home photos/photo_6_2024-11-24_10-21-19.jpg" (45f75d0eb1ead0ce0d925ef17beadf1d); g10-judges-panel.jpg == "Home photos/DSC09960-scaled.jpg"; g08-louh-arches.jpg == "Home photos/698216-1151644804.jpeg"; g01/g02/g03/g05/g06/g09 == images (5)/(4)/(3)/(2)/(1)/images.jpg. Both directories are git-tracked (git ls-files: 11 + 11). Commit 7c86c22 message: «11 صورة حقيقية من الكتاتيب والمساجد والمسابقات الليبية». frontend-html/index.html:303-320 — kicker «من مراكزنا» (line 306), captions lines 310-320, no attribution anywhere. g11-competition-boys.jpg visually confirmed: close-up identifiable faces of two minors.
```

</details>

### Dead public endpoint /api/public/demo-accounts still dumps every user's name+email+role whenever APP_DEBUG=true, unthrottled and untested

<a id="demo-accounts-endpoint-debug-gated"></a>

`demo-accounts-endpoint-debug-gated` · 🟡 medium · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/DashboardController.php:101-121`, `backend/routes/api.php:23`, `backend/app/Http/Controllers/Api/AuthController.php:149-152`

**Evidence**

```text
DashboardController.php:105: `if (! app()->environment('local') && ! config('app.debug')) { return ... [] }` then `User::orderByRaw(...)->get(['name','email','role'])` for ALL users. routes/api.php:23 registers it with no throttle. The frontend stopped using it in commit b2501ae (2026-09-11, 'حذف لوحة البيانات التجريبية… نقطة النهاية العامة /public/demo-accounts بقيت كما هي'); grep across frontend-html finds no caller. AuthController.php:150-151 explicitly warns '⚠️ خطير: لا تعتمد على APP_DEBUG — قد يبقى مفعّلاً بالخطأ في الإنتاج/staging' — the opposite rule is applied here. grep 'demo' tests/ -> no test covers the gate.
```

**Why it matters**

Once the API is public for the mobile app, a staging or misconfigured production box with APP_DEBUG=true exposes the full directory of parents and teachers (names + emails) to anyone, enumerable in one unauthenticated GET. Dead code on a public edge is pure attack surface.

**Recommendation**

Delete the route and method (the login page no longer needs it). If a dev helper is wanted, gate it strictly on environment('local') like dev_otp and add a feature test asserting an empty payload in 'production' and 'staging' with debug=true.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Every factual claim checks out on trace. backend/app/Http/Controllers/Api/DashboardController.php:105 gates on `! app()->environment('local') && ! config('app.debug')` — i.e. the dump is served whenever EITHER env is local OR APP_DEBUG is true — then lines 109-111 run `User::...->get(['name','email','role'])` over every user with no role or is_active filter (inactive/deactivated accounts included). routes/api.php:23 registers the route with no throttle, and bootstrap/app.php adds no global API throttle (Laravel 11 applies none by default), so the endpoint is unthrottled and unauthenticated. grep across frontend-html finds zero callers (only the route and the method itself match); commit b2501ae (2026-09-11) removed the login-page consumer and explicitly left the endpoint in place, so it is dead code on the public edge. grep of backend/tests for 'demo' or 'public/' returns nothing — no test covers the gate. AuthController.php:150-153 documents the opposite, stricter rule for dev_otp ('do not rely on APP_DEBUG'), confirming the inconsistency. Mitigations found: DEPLOYMENT.md step 1 instructs APP_DEBUG=false/APP_ENV=production, which is procedural only; .env.example ships APP_DEBUG=true, so a copied .env on staging would expose the list. Parent emails are synthetic (`{latin}.{id}@parent.mutqin.ly`) but names are real and teacher/manager emails are login identifiers usable for credential attacks. The one argument for downgrading: with APP_DEBUG=true in production, Laravel's own error pages would already leak far more (env, DB credentials), so this endpoint is not the marginal worst consequence of that misconfig; but the finding is real, unmitigated in code, and the fix (delete route + method, or gate on environment('local') with a test) is trivial. Medium is a fair rating; not refuted.

```text
backend/app/Http/Controllers/Api/DashboardController.php:105 gate `if (! app()->environment('local') && ! config('app.debug'))` (debug alone suffices to pass); :109-111 `User::orderByRaw(...)->orderBy('id')->get(['name','email','role'])` with no is_active/role filter. backend/routes/api.php:23 route has no throttle; backend/bootstrap/app.php registers only the four role aliases, no global api throttle. backend/.env.example:2,4 ship APP_ENV=local / APP_DEBUG=true. Frontend: no reference to demo-accounts anywhere under frontend-html (removed in b2501ae). backend/tests: no test references demo or /public/. DEPLOYMENT.md:9 is the only (procedural) mitigation. Note: CLAUDE.md still says the login page pulls from /api/public/demo-accounts — stale documentation.
```

</details>

### 233 KB Bootstrap RTL CSS is render-blocking on all three public pages yet no Bootstrap class is used; fonts load through a serial @import with no preconnect or SRI

<a id="unused-bootstrap-and-font-chain"></a>

`unused-bootstrap-and-font-chain` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/index.html:8`, `frontend-html/login.html:8`, `frontend-html/forgot-password.html:7`, `frontend-html/css/theme.css:6`

**Evidence**

```text
curl: bootstrap.rtl.min.css bytes=232911 (time 1.02 s). Class inventory of index.html (grep -oE 'class="[^"]+"') yields only project classes (card-soft, role-card, sec, pill, gallery-grid…); login.html and forgot-password.html use only mq-* classes. theme.css:6 `@import url('https://fonts.googleapis.com/css2?...')` — the browser must download theme.css before it can even request the 12 KB font CSS, then the woff2 files; no `<link rel=preconnect>` (the handoff prototype موقع متقن.dc.html:20-22 had preconnect + direct link). No `integrity=`/`crossorigin` on the CDN link.
```

**Why it matters**

First contentful paint on Libyan mobile networks is delayed by a ~230 KB third-party stylesheet that contributes nothing, plus a three-hop font waterfall; the unused CSS also widens the CSP/supply-chain surface (no SRI).

**Recommendation**

Drop the Bootstrap link from index/login/forgot-password (keep it for the app pages that use it); move the fonts link into <head> with preconnect to fonts.googleapis.com/fonts.gstatic.com (or self-host Amiri/Cairo woff2 with font-display:swap); add SRI hashes where a CDN stays.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The core facts check out. frontend-html/index.html:8, login.html:8 and forgot-password.html:7 each load https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css with no integrity/crossorigin, and none of the three pages contains a <link rel=preconnect>. A class inventory of the markup confirms only project classes (index: card-soft/role-card/pill/lnav/..., login: mq-*/login-grid/brand-side, forgot: mq-*/fp-btn). I also traced the JS those pages load: js/pages/landing.js only toggles project classes (in/reveal/open); js/pages/login.js uses ids only; UI.toast (js/ui.js:9-30) and UI.setFieldErrors build fully inline-styled elements with no Bootstrap classes; forgot-password's inline script adds no classes. css/theme.css:6 does fetch fonts via a serial @import, so the theme.css → fonts CSS → woff2 waterfall exists. However the finding is overstated in two ways: (1) the "233 KB" figure is the uncompressed size — jsDelivr serves the file brotli-compressed at 33,054 bytes (Content-Encoding: br, immutable 1-year cache), measured at ~0.3 s, so the real payload cost is ~7x smaller than claimed; (2) "contributes nothing" is not strictly true — Bootstrap's Reboot layer (box-sizing, heading margins, line-height 1.5, button/input font inheritance, img vertical-align) is implicitly relied on by the inline-styled public markup, so removing the link is not a zero-risk drop and would need a small reset added to theme.css (theme.css:24-25 only covers box-sizing and body). SRI on a version-pinned, immutable CDN asset is defense-in-depth for a small internal Libyan admin tool, not a material supply-chain exposure. Real but minor performance/hygiene item — downgrade to low.

```text
frontend-html/index.html:8, login.html:8, forgot-password.html:7 — Bootstrap RTL CSS link, no integrity/crossorigin, no preconnect anywhere in the three files (grep -c preconnect → 0, grep -c integrity= → 0). css/theme.css:6 — @import of Google Fonts CSS (serial waterfall). Class inventories: index.html uses only project classes; login.html uses brand-side/form-side/login-btn/login-grid/mq-*; forgot-password.html uses fp-btn/mq-*. js/pages/landing.js, js/pages/login.js, js/ui.js:9-30 (toast) and setFieldErrors (ui.js:79) inject no Bootstrap classes. Correction: curl -H 'Accept-Encoding: br' → Content-Length 33054, Content-Encoding br, cache-control immutable, time 0.31 s — the over-the-wire cost is ~33 KB, not 233 KB. Bootstrap Reboot normalization is still in effect on these pages, so dropping the link requires adding a minimal reset (theme.css:24-25 currently has only box-sizing + body margin/font).
```

</details>

### 1.08 MB of unoptimized JPEGs; largest is 2560x1440 served into a 751x235 slot; no srcset/WebP; one image is upscaled

<a id="gallery-images-unoptimized"></a>

`gallery-images-unoptimized` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/img/gallery/g10-judges-panel.jpg`, `frontend-html/img/gallery/g08-louh-arches.jpg`, `frontend-html/img/gallery/g04-listening.jpg`, `frontend-html/img/gallery/g01-halaqa-reading.jpg`, `frontend-html/index.html:66-69`

**Evidence**

```text
du: gallery total 1,083,008 bytes across 11 files. In-browser naturalWidth x naturalHeight vs rendered: g10 2560x1440 (393,872 B) -> 751x235; g04 2048x1638 (145,874 B) -> 368x235; g08 1024x682 (233,924 B) -> 368x235; g01 275x300 -> 368x235 (upscaled, blurry). No `srcset`/`sizes`, no WebP/AVIF, no width/height attributes (CLS is only mitigated by the fixed CSS height at index.html:68). Files are byte-identical to the originals in 'Home photos/' (never re-encoded).
```

**Why it matters**

Mobile visitors pay ~1 MB for a decorative section; the largest file alone exceeds a sensible total image budget for a landing page and the section is the first thing a center director scrolls to on 3G.

**Recommendation**

Re-encode to ~800px-wide WebP/AVIF with JPEG fallback (target <40 KB each), add srcset/sizes and explicit width/height, replace the 275x300 upscale, and keep loading=lazy.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 90% · corrected severity: low

The factual claims check out. frontend-html/img/gallery/ holds 11 JPEGs totalling 1,083,008 bytes; measured pixel dimensions match the auditor exactly (g10 2560x1440 / 393,872 B; g04 2048x1638 / 145,874 B; g08 1024x682 / 233,924 B; g01 275x300 / 17,043 B). The markup at index.html:313-323 uses plain <img src loading="lazy"> with no srcset/sizes/width/height/<picture>, and the CSS at index.html:65-74 fixes every tile to height:235px with object-fit:cover (the g-wide tile spans 2 columns), so a 2560px-wide image really is decoded into a ~750px slot on desktop and a 275x300 image is really upscaled to fill 368x235 (only ~235x235 of it is used after cover-cropping, so the upscale is from ~215px to ~368px). All 11 gallery files are md5-identical to files in 'Home photos/' (11 duplicate hashes), confirming no re-encoding. No .htaccess or server-side image handling exists in frontend-html/ that would mitigate this.

However, the severity is overstated. Mitigations already present: every gallery <img> carries loading="lazy" and the section (#gallery, line 305) sits well below the hero, so none of this weight is on the critical path or in the initial payload — it is fetched only when the visitor scrolls near it; the fixed 235px CSS height already prevents layout shift (the auditor concedes this); the three heavy files (g04, g08, g10 = 774 KB) are the real problem, the other eight average ~39 KB and are already near the auditor's own 40 KB target. This is a static landing page for a single-country internal management system, not a conversion-critical marketing site, and the issue has no correctness, security, or data impact. It is a legitimate but minor performance/polish item: low, not medium.

```text
frontend-html/index.html:65-74 (.gallery-grid img{width:100%;height:235px;object-fit:cover} ; .g-wide{grid-column:span 2}); frontend-html/index.html:305 (#gallery section, below hero/vision/stats); frontend-html/index.html:313-323 (11 <img src="img/gallery/..." loading="lazy"> with no srcset/sizes/width/height). Measured: g01 275x300 17,043 B; g02 563x355 38,662 B; g03 547x365 36,804 B; g04 2048x1638 145,874 B; g05 576x384 34,002 B; g06 496x367 33,681 B; g07 720x470 65,334 B; g08 1024x682 233,924 B; g09 447x447 31,010 B; g10 2560x1440 393,872 B; g11 1080x722 52,802 B; total 1,083,008 B. md5 of all 11 gallery files matches files in 'Home photos/' (never re-encoded). Only g04+g08+g10 (774 KB) exceed a ~40 KB budget; all are lazy-loaded and below the fold.
```

</details>

### No Open Graph/Twitter cards, canonical, favicon, robots.txt, sitemap or structured data; heading levels skip

<a id="seo-social-meta-missing"></a>

`seo-social-meta-missing` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/index.html:3-9`, `frontend-html/index.html:265-268`, `frontend-html/index.html:357`, `frontend-html/js/config.js:43`

**Evidence**

```text
Head of index.html contains only charset, viewport, description, title, two stylesheets (DOM dump confirms 14 head children incl. injected PWA metas, none og:/twitter:/canonical/icon). Static probes: robots.txt 404, sitemap.xml 404, favicon.ico 404 (the only icon hint is apple-touch-icon -> images/logo.svg injected by config.js:43). grep 'og:|twitter:|canonical|ld+json' -> none. Heading outline: H2 'وليُّ الأمر يتابع ابنه بنفسه' followed directly by H4s (index.html:268, 277-283); footer H4s without any H2/H3 ancestor (357, 365). No <main> landmark (DOM: main 0).
```

**Why it matters**

Links shared on WhatsApp/Facebook — the dominant channels in Libya — render with no image/title card; search engines get no canonical/sitemap; the browser tab shows a blank favicon; screen-reader users get an inconsistent outline.

**Recommendation**

Add og:title/description/image (1200x630 brand card), twitter:card, canonical, favicon.ico + PNG icons, robots.txt + sitemap.xml at the web root, Organization/SoftwareApplication JSON-LD, wrap content in <main>, and fix h4->h3 in the parent band and footer.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The cited evidence is accurate. frontend-html/index.html:3-9 contains only charset, viewport, description, title and two stylesheets; a grep for og:/twitter:/canonical/ld+json/rel="icon"/<main returns nothing in index.html (no <link rel="icon"> anywhere in frontend-html). No robots.txt, sitemap.xml or favicon.ico exists in the frontend-html tree; the only icon hints are the manifest.webmanifest icon (images/logo.svg, injected via config.js:37) and the apple-touch-icon link (config.js:43) — neither is a conventional favicon, so the tab-icon claim holds in most browsers. Heading outline: h2 at :269 is followed directly by h4s at :278/280/282/284; footer h4s at :357/:366 have no h2/h3 ancestor. No <main> landmark. Nothing in backend middleware or elsewhere mitigates any of this (it is purely static-client concern). So the finding is factually correct and not mitigated. However, severity is overstated: this is a login-gated management system for a handful of Quran centers in one city; the landing page is not a marketing acquisition funnel, search ranking is irrelevant to the product's function, and the heading-level skips are minor a11y polish (h1/h2/h3 structure is otherwise sound at :179/:224/:230-255/:294-309). Missing OG cards and favicon are cosmetic. Downgrade to low.

```text
frontend-html/index.html:3-9 (head: charset, viewport, description, title, 2 stylesheets only; no og:/twitter:/canonical/icon); index.html:269 h2 followed by h4 at :278,:280,:282,:284; footer h4 at :357,:366 with no h2/h3 ancestor; no <main> in file. No robots.txt/sitemap.xml/favicon.ico anywhere under frontend-html/. Only icon hints: frontend-html/manifest.webmanifest icons[0].src=images/logo.svg and js/config.js:43 apple-touch-icon -> images/logo.svg (no rel="icon" in any HTML file).
```

</details>

### PWA/home-screen icon set is SVG-only with a webfont-dependent wordmark; iOS and Android install paths degrade

<a id="pwa-manifest-icons"></a>

`pwa-manifest-icons` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/manifest.webmanifest:13-20`, `frontend-html/js/config.js:43`, `frontend-html/images/logo.svg:20`

**Evidence**

```text
manifest.webmanifest icons: single entry `{"src":"images/logo.svg","sizes":"any","type":"image/svg+xml","purpose":"any"}` — no 192/512 PNG, no `maskable`. config.js:43 injects `<link rel=apple-touch-icon href=images/logo.svg>` (Safari does not accept SVG here). logo.svg:20 draws the wordmark with `<text font-family="Amiri, serif">` — Amiri is a Google webfont not present when the OS rasterises the icon, so it falls back to a system serif. background_color #F1EEE3 (manifest:11) is not in the palette. start_url is ./login.html.
```

**Why it matters**

The 'add to home screen' route that the team advertises as the interim mobile app (config.js comment) shows a generic or mis-rendered icon on iOS and a cropped one on Android adaptive launchers — precisely the surface parents see daily.

**Recommendation**

Export PNG icons 192/512 (+ maskable with safe-zone padding) with the wordmark converted to outlines; add apple-touch-icon 180x180 PNG; align background_color to #FBF7DA; add manifest screenshots and `id`.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Evidence verified as quoted. frontend-html/manifest.webmanifest:13-20 has exactly one icon entry (images/logo.svg, sizes "any", purpose "any", no maskable); frontend-html/images/ contains only logo.svg (no PNG anywhere in the client — grep across all HTML for .png/apple-touch-icon returns nothing else); config.js:43 injects apple-touch-icon pointing at the SVG for every page (config.js is the first script loaded on all pages, no page overrides it). logo.svg:18 (auditor said :20; the file is 19 lines) draws the wordmark with font-family="Amiri, serif" and has a transparent background. Behaviourally the claims hold: iOS Safari ignores SVG apple-touch-icons and falls back to a page screenshot; Android Chrome accepts the SVG but with no maskable variant and a transparent background the adaptive launcher shrinks/plates it; the Amiri webfont is not available to the OS rasteriser so the Arabic wordmark falls back to a system serif. background_color #F1EEE3 indeed does not appear in css/theme.css (palette uses --ivory #FBF7DA, --paper #EAE6D4). No mitigation exists (no service worker, no per-page link tags, no alternative icon assets). However, the finding is over-rated: it is purely cosmetic (icon appearance on home screen), affects no functionality, data, or security; the PWA is a lightweight "open as standalone" convenience per the config.js comment, not something the repo actually advertises as an interim mobile app (the auditor's impact framing overstates the comment). Trivial to fix by exporting two PNGs. Correct severity is low.

```text
frontend-html/manifest.webmanifest:13-20 (single SVG icon, purpose any, no maskable); frontend-html/manifest.webmanifest:11 (background_color #F1EEE3 not in css/theme.css:11 palette: --ivory #FBF7DA / --paper #EAE6D4); frontend-html/js/config.js:43 (apple-touch-icon -> images/logo.svg, injected on every page); frontend-html/images/logo.svg:18 (not :20 — file is 19 lines) `<text ... font-family="Amiri, serif" ...>متقن</text>`, transparent background; frontend-html/images/ contains only logo.svg — no PNG fallbacks anywhere in the client.
```

</details>

### On phones the login form sits below a full-height brand panel, and the nav login pill wraps to two lines

<a id="login-mobile-layout"></a>

`login-mobile-layout` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/login.html:21-25`, `frontend-html/login.html:74`, `frontend-html/index.html:17`, `frontend-html/index.html:153`

**Evidence**

```text
login.html:23 `.brand-side { order:1 !important; padding:40px 32px !important; }` at max-width:860px puts the 560px-min brand block first; at the emulated 375x812 viewport the email input renders at the bottom edge of the first screen (screenshot). index.html `.pill` (line 17) has no white-space:nowrap (the handoff prototype's nav link at موقع متقن.dc.html has `white-space:nowrap`); measured at 375px: pill 72x113 px (two lines), header 104 px tall.
```

**Why it matters**

Parents on phones — the majority of daily logins — must scroll before they can type; the two-line CTA and oversized header waste ~15% of the first viewport on every landing visit.

**Recommendation**

At ≤860px set .form-side order:1 and collapse the brand panel to a slim header strip; add white-space:nowrap to .pill and hide the logo subtitle under 480px.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted code exists and behaves as described. login.html:21-25 at max-width:860px collapses the grid to one column and explicitly keeps `.brand-side { order:1 }` / `.form-side { order:2 }`, so the brand panel (148px logo block + 42px Amiri heading + tagline + 26px ayah + 80px vertical padding, roughly 450-500px of content, on top of body padding 40px) is stacked above the form on phones; with the back-home pill, a 38px h1 and a subtitle before the first input, the email field landing near the bottom of a 812px viewport is consistent with the layout math. No other stylesheet overrides this (theme.css has no login-specific rules). index.html:17 `.pill` indeed lacks `white-space:nowrap`; the nav (line 136: flex, padding 16px 32px reduced to 12px 16px under 768px, gap 24px) holds a ~180px logo block and a right group of pill (~150px natural) + 44px burger + 12px gap, which exceeds the ~343px available at 375px, so flex shrinks the pill and its two-word label wraps — the auditor's 72x113px measurement is plausible. theme.css's nowrap rules (lines 58, 559) apply to .mq-btn and chips, not to .pill. Nothing mitigates either point. However, the impact is overstated: the form remains fully usable with one scroll, nothing is hidden or broken, the nav still renders a tappable CTA, and this is purely cosmetic/UX polish on a static page. That is a low-severity usability finding, not medium.

```text
frontend-html/login.html:21-25 (mobile media query keeps .brand-side order:1 before .form-side order:2); login.html:30 (grid min-height:560px, grid-template-columns collapsed by the media query); login.html:74-95 (brand panel content stacked first on phones). frontend-html/index.html:17 (.pill has no white-space:nowrap); index.html:136 (nav flex container, gap:24px) and :48-50 (≤767.98px only shrinks nav padding to 12px 16px, does not constrain the pill); css/theme.css:58 and :559 (nowrap exists only for .mq-btn and chip classes, not .pill).
```

</details>

### No .htaccess for the static root: no HSTS/CSP/X-Frame-Options, no caching, and README.md with stale credentials is deployed to public_html

<a id="static-host-no-headers-readme-exposed"></a>

`static-host-no-headers-readme-exposed` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `.cpanel.yml:5`, `frontend-html/README.md:23-28`, `backend/.htaccess:1-20`

**Evidence**

```text
.cpanel.yml:5 `rsync -a --delete --exclude='backend' … frontend-html/ $DEPLOYPATH/` copies the whole folder, and `git ls-files frontend-html | grep -i readme` -> frontend-html/README.md (served as https://<domain>/README.md). That README lists '🔑 حسابات تجريبية (كلمة المرور: `password`)' with admin@mutqin.ly / teacher1@mutqin.ly / parent1@mutqin.ly. `find . -iname .htaccess` finds only backend/.htaccess and backend/public/.htaccess — nothing for the static root, so no Strict-Transport-Security, Content-Security-Policy, X-Frame-Options, Referrer-Policy, Cache-Control/expires for the 1 MB gallery, and no HTTPS redirect. index.html:184 uses an inline `onmouseover` handler and 98 inline style attributes, which would block a strict CSP later.
```

**Why it matters**

The login page (which holds Bearer tokens in localStorage) ships with no clickjacking or transport-hardening headers, and a public file advertises the admin email and the historical password scheme.

**Recommendation**

Add frontend-html/.htaccess with HTTPS redirect, HSTS, X-Frame-Options DENY, X-Content-Type-Options, Referrer-Policy, a CSP (after moving inline styles/handlers), long-lived cache for img/css/js; exclude README.md (and any *.md) in .cpanel.yml; rewrite the README without credentials.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Evidence verified. `.cpanel.yml:5` rsyncs `frontend-html/` wholesale into `/home/[redacted-cpanel-user]/public_html` with only `backend`, `.well-known`, `cgi-bin` excluded, and `frontend-html/README.md` is tracked in git, so it is deployed to the web root and served as a static file. `frontend-html/README.md:23-28` does list admin@mutqin.ly / teacher1@mutqin.ly / parent1@mutqin.ly under "كلمة المرور: `password`". `git ls-files | grep htaccess` returns only `backend/.htaccess` and `backend/public/.htaccess`; the static root has none, and a repo-wide grep for Strict-Transport-Security / X-Frame-Options / Content-Security-Policy / Referrer-Policy / X-Content-Type-Options returns zero hits in any .php/.html/.js/.htaccess/.yml — there is no header middleware in `bootstrap/app.php` either (it only registers the four role aliases). `index.html:184` does carry the inline onmouseover/onmouseout handlers, and there are 69 lines with `style="` attributes. So the finding is factually correct and not mitigated anywhere in code or config.

Mitigating context that lowers severity rather than refutes: (1) the README's password `password` is stale — the actual seeders use `[redacted-demo-password]` (ExtraDataSeeder) and `[redacted-demo-password]` (LibyanDataSeeder), so the file leaks the real admin email address and the historical scheme, not a working credential; (2) DEPLOYMENT.md item 8 already instructs changing seeder passwords before go-live; (3) `DashboardController@demoAccounts` deliberately returns an empty list outside local/debug, which the README leak partially undoes for three emails — a real but small information disclosure; (4) the login page keeps the token in localStorage and sends it via Authorization header, so clickjacking of the login form cannot steal tokens, and a cPanel host may enforce HTTPS/HSTS at the server level (cannot be confirmed from the repo). Net: real hygiene gap (missing security headers + a deployed doc naming the admin email), but no exploitable path on its own — low rather than medium for this small single-tenant deployment.

```text
.cpanel.yml:5 — `rsync -a --delete --exclude='backend' --exclude='.well-known' --exclude='cgi-bin' frontend-html/ $DEPLOYPATH/` (no *.md exclusion). frontend-html/README.md:23-28 — demo table with admin@mutqin.ly and password `password`; that password is stale: backend/database/seeders/ExtraDataSeeder.php:35 uses '[redacted-demo-password]' and backend/database/seeders/LibyanDataSeeder.php:40-43 use '[redacted-demo-password]'. backend/app/Http/Controllers/Api/DashboardController.php:105-107 hides demo accounts outside local/debug (the README leak bypasses this for 3 emails). backend/public/.htaccess:1-45 — only access grant + Laravel rewrite rules, no Header directives; backend/.htaccess:10-19 — deny-all only. No security-header string exists anywhere in the repo (grep for Strict-Transport-Security|X-Frame-Options|Content-Security-Policy|Referrer-Policy|X-Content-Type-Options across .php/.html/.js/.htaccess/.yml -> 0 hits). frontend-html/index.html:184 — inline onmouseover/onmouseout handlers confirmed. DEPLOYMENT.md:16-17 already lists HTTPS and demo-password rotation as pre-launch tasks.
```

</details>

### 'تذكّرني' checkbox is decorative — never read; the token is always persisted in localStorage

<a id="remember-me-dead-control"></a>

`remember-me-dead-control` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/login.html:56-60`, `frontend-html/js/pages/login.js:27-35`, `frontend-html/js/auth.js:8-11`

**Evidence**

```text
grep -rn 'remember' frontend-html/js frontend-html/login.html -> only login.html:58 `<input type="checkbox" id="remember" …>`. login.js reads #email and #password only (lines 27-28); auth.js:8-11 `localStorage.setItem(C.STORAGE_TOKEN, token)` unconditionally.
```

**Why it matters**

Users on shared center computers who leave the box unchecked still stay logged in for the 7-day token lifetime; a visible control that does nothing is a trust and audit smell on the most sensitive public page.

**Recommendation**

Either implement it (unchecked -> sessionStorage / logout on tab close and a shorter token) or remove the control.

### Accessibility gaps on the three public pages: unlabeled inputs on forgot-password, missing autocomplete hints, emoji-only toggle, alerts without role

<a id="public-forms-a11y-gaps"></a>

`public-forms-a11y-gaps` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/forgot-password.html:29-30`, `frontend-html/forgot-password.html:42-47`, `frontend-html/login.html:44-52`, `frontend-html/login.html:40`, `frontend-html/js/ui.js:38-46`

**Evidence**

```text
DOM check on forgot-password.html: all three `<label class="mq-label">` have no `for` and inputs #phone/#otp/#new-password report labelled=false; #otp lacks autocomplete="one-time-code", #new-password lacks autocomplete="new-password". login.html:44/50: #email has no autocomplete="username", #password no autocomplete="current-password". #toggle-pass (login.html:51-52) has only `title`, content is the emoji 👁/🙈 swapped in ui.js:45, no aria-label/aria-pressed. #login-alert and #fp-alert have no role="alert"/aria-live. No skip link (DOM: skipLink false).
```

**Why it matters**

Password managers and iOS/Android OTP autofill do not engage on the OTP flow; screen-reader users get unlabeled fields and silent error alerts on the two pages every user must pass through.

**Recommendation**

Add for/id pairs, autocomplete=username/current-password/new-password/one-time-code, aria-label + aria-pressed on the toggle, role=alert on the alert boxes, and a skip link to <main>.

### Docs, screenshots and the content guide describe a login demo panel, Blade pages and CORS settings that no longer exist

<a id="doc-drift-public-surface"></a>

`doc-drift-public-surface` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `CLAUDE.md:206`, `DEPLOYMENT.md:14`, `frontend-html/README.md:6-7,23-28`, `دليل-محتوى-الصفحات.md:60-76`, `screenshots/01-landing.png`, `screenshots/44-login-demo-accounts.png`

**Evidence**

```text
CLAUDE.md:206 'login.html (unified login, demo-accounts panel)' — panel deleted in b2501ae (2026-09-11). DEPLOYMENT.md item 6 still instructs removing 'زر التعبئة في js/pages/login.js' and item 3 says cors.php has `allowed_origins => ['*']`, but config/cors.php reads CORS_ALLOWED_ORIGINS with a localhost:8080 default. frontend-html/README.md:6 shows `API_BASE_URL = 'http://localhost:9090/api'` (config.js:10-12 is host-dependent) and lists password `password` / teacher1@mutqin.ly (seeders now use [redacted-demo-password] and generated emails). دليل-محتوى-الصفحات.md:60 documents `welcome.blade.php` with nav items 'البرامج، الرمز والاسم' and an 'ابدأ الآن' CTA that the static landing never had. screenshots/01-landing.png shows a footer 'طرابلس، ليبيا' (code: بنغازي, index.html:369) and no gallery; 02/03/44/45 show the deleted demo panel. CLAUDE.md states PHP lives at C:\xampp\php\php.exe — Test-Path returned False on this machine.
```

**Why it matters**

Anyone onboarding (including the Flutter team reading CLAUDE.md for the public endpoints) is steered to a UI and settings that are gone; stale screenshots would be wrong if reused for store listings.

**Recommendation**

Regenerate the four public screenshots, delete or archive دليل-محتوى-الصفحات.md, fix DEPLOYMENT.md items 3/6 and the frontend README, and update CLAUDE.md's public-surface lines.

### /public/stats is unthrottled and uncached, counts inactive users, and the two hardcoded fallback sets on the page contradict each other

<a id="public-stats-uncached-inconsistent"></a>

`public-stats-uncached-inconsistent` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `backend/routes/api.php:22`, `backend/app/Http/Controllers/Api/DashboardController.php:124-134`, `frontend-html/index.html:187-191`, `frontend-html/index.html:331-333`

**Evidence**

```text
routes/api.php:22 `Route::get('/public/stats', …)` has no throttle (the auth routes do). DashboardController.php:129-131 runs three COUNT(*) per hit; `'users' => User::count()` includes inactive accounts and every role. Hero trust bar fallbacks (index.html:187-191) are ٨ / ٥٠ / ٦٠ while the stats band (331-333) shows ٣١٥+ / ٣+ / ٢٠+; when the API is unreachable both remain on screen (observed with ERR_CONNECTION_REFUSED).
```

**Why it matters**

A bot hammering the landing turns into unbounded DB queries; visitors offline from the API see two contradictory 'social proof' numbers on one page; the users figure is not a meaningful public metric.

**Recommendation**

Cache the payload (Cache::remember 5-15 min), add throttle:60,1 and Cache-Control on the response, count active users only, and unify the fallbacks (or hide the bars until data arrives).

## Measured facts

| Metric | Value |
|---|---|
| Public pages | 3 (index.html 34,596 B; login.html 8,027 B; forgot-password.html 7,738 B) |
| Render-blocking CSS on public pages | bootstrap.rtl.min.css 232,911 B (0 Bootstrap classes used) + theme.css 37,645 B + Google Fonts CSS 12,076 B via @import |
| Gallery image weight | 1,083,008 B across 11 JPEGs; largest 393,872 B (2560x1440 shown at 751x235); smallest 17,043 B; 1 image upscaled (275x300 -> 368x235) |
| Images with alt / lazy | 11/11 alt, 11/11 loading=lazy, 0/11 srcset, 0/11 width/height attrs |
| Brand token match vs _handoff2 | 6/6 core colours identical; 2 fonts identical; 3 tint hexes + manifest #F1EEE3 not in any design file |
| SEO/social tags | title 1, description 1, og:* 0, twitter:* 0, canonical 0, icon link 0, JSON-LD 0; robots.txt/sitemap.xml/favicon.ico all 404 |
| Legal pages | 0 (privacy 404, terms 404) |
| Contact channels on landing | 1 plain-text email, 0 mailto/tel/WhatsApp/form |
| Inline styles / inline handlers in index.html | 98 style attributes, 1 onmouseover handler |
| Mobile checks (375x812) | no horizontal overflow (scrollWidth 375); header 104 px; nav pill 72x113 px (2 lines); login form below brand panel |
| Public API endpoints | 2 (/public/stats, /public/demo-accounts) — 0 throttled, 0 cached, 0 feature tests |
| Feature test files | 38 in tests/Feature (40 *Test.php total); 0 touch the public endpoints; OtpResetTest covers forgot-password |
| OTP self-service roles | 2 of 4 (parent, teacher); SMS gateway: none (Log::info only) |
| Analytics/telemetry on landing | 0 (no gtag/plausible/matomo/sentry) |
| Tracked non-product assets | Home photos 1.1 MB (11 files), _handoff2 3.3 MB (incl. one 2.09 MB PNG), screenshots 4.9 MB (36 files) — not deployed by .cpanel.yml |

## Auditor notes

Verification method: all three public pages were served read-only from frontend-html via `python -m http.server 8089` and rendered in the browser pane at 1366x900 and 375x812 (the API on :9090 was intentionally not started, so /public/stats returned ERR_CONNECTION_REFUSED and the hardcoded fallbacks were observed). The browser pane turned out to be shared with another audit session (a tab was redirected to :8098/admin/dashboard.html mid-check), so a dedicated tab was used for the final forgot-password check; no repo file was created or modified. Note that PHP is not present at C:\xampp\php\php.exe on this machine, contrary to CLAUDE.md. Additional minor observations not promoted to findings: (a) the landing hero's `.reveal` pattern briefly renders content at partial opacity right after load (screenshot caught it mid-transition) — acceptable progressive enhancement; (b) copyright year '© ٢٠٢٦' is hardcoded on all three pages; (c) config.js production API path '/backend/public/api' advertises the Laravel tree layout under the web root (protected by backend/.htaccess Require all denied — deployment dimension); (d) routes/web.php '/' returns a JSON 'Welcome to MUTQEN API Backend' banner — harmless but unbranded; (e) no assetlinks.json / apple-app-site-association for future deep links; (f) no English/hreflang variant — acceptable for the Libyan market; (g) the 429 throttle response from Laravel is the default English 'Too Many Attempts.' with no custom handler in bootstrap/app.php (withExceptions is empty), so the login alert would show English text after 10 failed attempts — worth an Arabic override; (h) the handoff prototype's 'الرمز والاسم' (brand story) section was dropped from the implementation, which is a content choice, not a defect. Screenshots 01/02/03/44/45 in screenshots/ are stale relative to the code (old footer city, no gallery, deleted demo panel) and should not be reused for marketing or store listings.
