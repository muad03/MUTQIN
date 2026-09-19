# Legal, IP & Open-Source Licensing

[← Enterprise Audit](../enterprise-audit.md)

**Score 34 / 100** — Unfit · maturity **L1** · weight 2%

Dependency and secrets hygiene are genuinely good (vendor/ and .env never committed, no keys in history, 78/87 production packages MIT, only one hard copyleft package, original logo, OFL fonts, PDFs and PWA icons free of third-party imagery). Everything else that a CTO needs before scaling or shipping a store app is missing: there is no LICENSE, NOTICE, ownership statement or contributor assignment while composer.json/README advertise MIT (inherited from the Laravel skeleton) over an in-process GPL-2.0-only PDF engine whose combined bundle has already been conveyed to the client's hosting account; the public landing page and the public GitHub repo carry 11 downloaded third-party photos of identifiable minors (one named in the filename) with no licence, credit or guardian consent; a minors'-data product has zero privacy policy / terms pages (a hard app-store blocker); the public repo publishes cPanel user, DB name and server layout; the brand is spelled four ways, attributed to a single center in the footer and has no documented owner. Nothing is defined or repeatable (no SBOM, no asset ledger, no licence gate, no AI-authorship policy), so this is CMMI level 1 (ad hoc). Score 34: unfit as a legal foundation for dozens of centers or a public mobile app, but the fixes are mostly small and the dependency base is clean.

## What is already strong

- Third-party code is never vendored into the repo: backend/.gitignore excludes /vendor and .env; `git ls-files backend/vendor` = 0; `git log --all --diff-filter=A -- backend/.env` = nothing; `git grep` for APP_KEY=base64:/AKIA/private-key blocks = nothing; backend/.env.production.example uses REPLACE_ME placeholders only.
- Permissive, small dependency footprint: composer.lock prod = 87 packages (78 MIT, 5 BSD-3-Clause, 1 Apache-2.0, 2 BSD-3/GPL tri-licensed with a BSD option, 1 GPL-2.0-only); dev = 39 (26 BSD-3, 13 MIT). Frontend has exactly one CDN dependency (index.html:8 `bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css`, MIT) and no vendored minified blobs.
- Brand mark is original: frontend-html/images/logo.svg is a hand-authored octagram (two rotated squares + dotted circle) with the wordmark in Amiri; the Claude Design brand board (_handoff2 uploads 'الشعار الاساسي.png') shows the same first-party mark — no third-party logo or icon set copied.
- Fonts are OFL-1.1 (Amiri, Cairo) — commercial use, embedding and app bundling are permitted; PDFs explicitly use DejaVu (backend/resources/views/pdf/layout.blade.php:6 `font-family: dejavusans`), whose Bitstream Vera licence permits redistribution and document embedding (mPDF ttfonts/DejaVuinfo.txt).
- PDF reports and PWA icons contain no photographs: layout.blade.php:45 renders a text brand `مُتقِن`, manifest.webmanifest:15 uses only `images/logo.svg`; the unlicensed gallery is confined to frontend-html/index.html:313-323.
- Demo credentials are gated by design: DashboardController.php:105 `if (! app()->environment('local') && ! config('app.debug')) return … 'data' => []` and DEPLOYMENT.md item 6 documents removal before production.
- Deployment excludes what must not be redistributed: .cpanel.yml rsync `--exclude='vendor' --exclude='.env' --exclude='storage'`.
- n8n workflow uses only core `n8n-nodes-base.*` nodes (scheduleTrigger, set, httpRequest, code, if, emailSend, noOp — no `.ee.` enterprise nodes) and is an internal-ops digest, squarely within the Sustainable Use License's 'internal business purposes'.
- Quranic reference data carries no proprietary edition: SurahReference.php is a 114-row surah→juz fact table and the 477-thumn index was generated in-house (docProps `dc:creator openpyxl`, 2026-06-21).

## Level-5 target state

A root LICENSE and NOTICE name the owning legal entity and the chosen terms (proprietary for the SaaS backend, with written IP assignments from every contributor identity and an AI-assisted-authorship statement), and composer.json/README/footers all agree with them. A CI-generated CycloneDX SBOM with a licence allow-list guards every dependency, THIRD-PARTY-NOTICES ships with each deploy bundle, and the PDF engine decision (SaaS-only mPDF, or a swapped/isolated LGPL-permissive renderer with explicitly bundled OFL fonts) is recorded. Every image, font and data asset has a ledger row (hash, source, licence, model/guardian release) — no child appears without a signed guardian consent — and the mark 'Mutqin / مُتقِن' plus the octagram device are registered in Libya in the owner's name and reserved on both app stores. Public Arabic privacy policy and terms with versioned in-app acceptance exist, and the public repository contains no hosting internals or personal material.

## What the Flutter team must know

The mobile app never links mPDF — reports arrive as HTTP PDF responses (`Content-Disposition: inline`) — so the GPL question does not touch the app binary; if the team later renders reports on-device, use the Apache-2.0 `pdf` package with OFL fonts, not a GPL port. Bundle Amiri/Cairo TTFs from Google Fonts' GitHub under assets/fonts (do not rely on the `google_fonts` package fetching at runtime on Libyan networks) and register OFL.txt via `LicenseRegistry.addLicense` so it appears in `showLicensePage`; Flutter auto-collects pub package licences, but fonts and any copied icon paths must be added manually. Store submission currently blocks on legal artefacts, not code: a legal entity to hold the developer accounts, a public privacy-policy URL (and ToS for account-based apps), a data-safety/child-data declaration, and a trademark-clear app name with one Latin spelling (recommend 'Mutqin' to match mutqin.ly; reserve the name on both stores early). Do not reuse any gallery photo in store screenshots, onboarding or splash — all 11 are unlicensed images of identifiable minors; the octagram logo is rights-clean but must be exported as outlined raster icons (1024x1024 + adaptive/maskable) because logo.svg renders the wordmark as live Amiri text. Plan for a versioned terms/privacy acceptance flag on the user payload (`/auth/user`) so the app can gate first login for all four roles once the policies exist; the API has no such field today. The public repo currently exposes the production hosting layout the app will depend on — expect the backend team to make it private and rotate hosting credentials before the app points at mutqin.ly in production.

## Findings — 13 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [11 downloaded third-party photos of identifiable minors published on the landing page and in the public repo with no licence, credit or guardian consent](#child-photos-unlicensed-no-consent) | 🔴 critical | ✅ confirmed | NOW | S |
| [No LICENSE or ownership statement anywhere, while composer.json and README advertise MIT inherited from the Laravel skeleton; multiple contributor identities with no assignment](#no-license-false-mit-declaration) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Zero privacy-policy / terms-of-use / consent pages for a product holding minors' identity, guardian phone, attendance and fingerprint data — a hard app-store blocker](#no-privacy-policy-or-terms) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [mPDF (GPL-2.0-only) is a hard in-process dependency of nine PDF routes; the combined bundle has already been conveyed to the client's hosting account under contradictory MIT/proprietary signals](#mpdf-gpl2-copyleft-in-process) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Public GitHub repo publishes production hosting internals (cPanel user, DB name, server layout, deploy procedure) and a personal desktop screenshot](#public-repo-exposes-hosting-internals) | 🟡 medium | ✅ confirmed | NOW | S |
| [The mark مُتقِن is spelled four ways in Latin, attributed to a single center in the footer, implies Awqaf supervision, and has no owner, notice or registration](#brand-trademark-ownership-unclear) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [No THIRD-PARTY-NOTICES, SBOM or licence gate; PDF font actually embedded for Arabic is chosen at runtime by mPDF and its licence is not on record](#no-sbom-third-party-notices) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [223 of 224 commits are AI co-authored and the design system is a Claude Design export, but no authorship/ownership policy or account-holder record exists](#ai-assisted-authorship-undocumented) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Brand fonts are hot-linked from Google Fonts with no self-hosted copies or OFL text; the logo icon relies on live Amiri text](#fonts-hotlinked-no-ofl-notice) | ⚪ low | ℹ️ informational | NEXT | S |
| [_handoff2 Claude Design export redistributes an unlicensed vendor runtime and 3.3 MB of design/scratch files in the product repo](#handoff-bundle-provenance) | ⚪ low | ℹ️ informational | LATER | S |
| [n8n attendance digest is within the Sustainable Use License today but the boundary is undocumented](#n8n-sustainable-use-scope) | ⚪ low | ℹ️ informational | LATER | S |
| [Thumn index and surah→juz table have no recorded source edition or verification](#quran-data-provenance-unrecorded) | ⚪ low | ℹ️ informational | LATER | S |
| [Canonical docs omit or misstate the files that create legal/ops exposure](#doc-drift-legal-surface) | ⚪ low | ℹ️ informational | LATER | S |

### 11 downloaded third-party photos of identifiable minors published on the landing page and in the public repo with no licence, credit or guardian consent

<a id="child-photos-unlicensed-no-consent"></a>

`child-photos-unlicensed-no-consent` · 🔴 critical · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `frontend-html/img/gallery/g01-halaqa-reading.jpg … g11-competition-boys.jpg (11 files)`, `Home photos/ (11 byte-identical originals incl. مصطفى-المهدوي-720x470.jpg)`, `frontend-html/index.html:305-323`, `frontend-html/index.html:310`, `frontend-html/index.html:320`, `.cpanel.yml:5`

**Evidence**

```text
md5sum pairing proves every gallery file is a byte-identical copy of a file in `Home photos/` whose original names are download artifacts: `629319058_1229473859375865_3097274146528381499_n.jpg` (Facebook-CDN pattern, IPTC 'Photoshop 3.0' block, 2048x1638) = g04; `images.jpg`, `images (1..5).jpg` (Google-Images 'Save as' pattern, 275–576 px thumbnails) = g09/g06/g05/g03/g02/g01; `DSC09960-scaled.jpg` (EXIF: 'SONY' 'ILCE-7M3' '2023:08:19 11:34:53', WordPress `-scaled` suffix, 2560x1440) = g10; `698216-1151644804.jpeg` (news-CMS id pattern) = g08; `photo_6_2024-11-24_10-21-19.jpg` (Telegram export pattern) = g11; `مصطفى-المهدوي-720x470.jpg` (a named minor + WordPress thumbnail suffix) = g07. JPEG scan: no copyright/creator/licence field in any of the 11 files; repo-wide search finds no credit, licence, model release or consent document. Visual check: g03 shows ~20 identifiable boys' faces, g07 one identifiable boy, g10 identifiable adults with name placards. Commit 7c86c22 (2026-07-17): '11 صورة حقيقية من الكتاتيب والمساجد والمسابقات الليبية'. index.html:310 markets them as 'مشاهد من حلقات الحفظ … الروح الأصيلة التي بُنيت منصة مُتقِن لخدمتها'; :320 captions one 'مجلس تحفيظ بإشراف هيئة الأوقاف'. `.cpanel.yml` rsyncs frontend-html/ to `/home/[redacted-cpanel-user]/public_html` → served on mutqin.ly. Not used in PDFs (layout.blade.php text-only brand) nor in PWA icons (manifest.webmanifest:15 → images/logo.svg).
```

**Why it matters**

Copyright infringement exposure toward the photographers/outlets (a Sony α7 III frame lifted from a WordPress site is a professional work) and, far worse for a children's-education product, publication of identifiable minors — one named inside the repository — without guardian consent, presented as 'our centers'. App-store reviewers, a ministry/Awqaf partner or any parent can raise this; Libyan child-protection and personality-rights rules apply; the files are also permanently retrievable from the public GitHub history.

**Recommendation**

Remove all 22 files and republish the landing without the gallery now (S). Make the repo private, then purge the files from history (git filter-repo + force-push) because deletion alone leaves the named-minor file retrievable. Replace with own photography backed by written Arabic guardian releases (or licensed stock with the licence id) and keep an asset ledger row per file (hash, source, licence, release id). Never reuse these images in store listings or onboarding. Adopt an intake rule: no image enters the repo without a ledger row.

<details><summary>Verification — 2 independent reviewers</summary>

**Upheld** · confidence 90% · corrected severity: critical

Every factual claim checks out. md5sum confirms all 11 frontend-html/img/gallery/g*.jpg are byte-identical to files in `Home photos/` whose names are download artifacts (Facebook CDN `629319058_..._n.jpg`, Google Images `images (N).jpg`, WordPress `-scaled`/`-720x470`, Telegram `photo_6_2024-11-24_...`, news-CMS numeric id). `file` confirms g10 carries SONY ILCE-7M3 EXIF dated 2023-08-19 (a professional camera frame). All 22 files are tracked in git (`git ls-files "Home photos"` = 11, incl. the named-minor file `مصطفى-المهدوي-720x470.jpg`), committed in 7c86c22/8afe255, and origin is https://github.com/muad03/MUTQIN which returns HTTP 200 unauthenticated (public). index.html:305-323 renders them under «من مراكزنا / مراكز التحفيظ الليبية» with captions such as «مجلس بإشراف الأوقاف»; `.cpanel.yml` rsyncs frontend-html/ to public_html with no exclusion for img/. Visual inspection of g03 and g07 confirms ~20 and 1 clearly identifiable minors' faces respectively. Repo-wide grep finds no licence, credit, model release or consent text for any image; no LICENSE/CREDITS file. There is no mitigation anywhere (no middleware/DB/tests are relevant — this is content, not code behaviour). Not applicable-to-product arguments fail: a children's Quran-education product presented to Awqaf/parents is exactly where minor-image consent matters, and Libyan-only scope does not remove copyright or personality-rights exposure. Severity: the IP exposure alone would be high; the published, named, identifiable-minor images on a public repo and live domain marketed as "our centers" justify critical. Minor caveats only: I could not confirm actual current deployment state of mutqin.ly nor whether the named file's subject is actually a minor (it is a minor per the paired g07 image), and the `strings` tool was unavailable so I relied on `file` for metadata — none of these weaken the finding.

```text
frontend-html/index.html:305-323 (gallery section, 11 <img src="img/gallery/g*.jpg">; :310 marketing copy; :320 «مجلس بإشراف الأوقاف»); .cpanel.yml:5 rsync frontend-html/ → /home/[redacted-cpanel-user]/public_html (no img exclusion); git ls-files "Home photos" = 11 files incl. مصطفى-المهدوي-720x470.jpg, md5 cf962a4f… == g07-tasmee-bench.jpg; g10-judges-panel.jpg EXIF: SONY ILCE-7M3, 2023:08:19 11:34:53, 2560x1440; commits 7c86c22 and 8afe255 on origin/master at public https://github.com/muad03/MUTQIN (HTTP 200 anonymous); no LICENSE/credit/consent text anywhere in repo.
```

---

**Upheld** · confidence 80% · corrected severity: high

Every factual claim checks out. md5sum confirms all 11 frontend-html/img/gallery/g*.jpg files are byte-identical to files in `Home photos/` whose original names are download artifacts (Facebook CDN `629319058_..._n.jpg`, `images (1..5).jpg`, `DSC09960-scaled.jpg` with embedded `SONY`/`ILCE-7M3`/`2023:08:19 11:34:53`, `photo_6_2024-11-24_...jpg`, `مصطفى-المهدوي-720x470.jpg`). All 22 files are tracked in git and present on `origin/master`; `gh repo view muad03/MUTQIN` returns `visibility: PUBLIC`. index.html:305-323 renders the gallery under "من مراكزنا / مراكز التحفيظ الليبية" with the quoted marketing copy and the "بإشراف هيئة الأوقاف" caption. `.cpanel.yml` rsyncs `frontend-html/` to `/home/[redacted-cpanel-user]/public_html`, so the gallery is served on the production site. Visual check of g07 shows one clearly identifiable boy in the foreground plus ~20 background children. Repo-wide search finds no photo licence, credit, or release (the only "License" hits are Laravel's stock README). No mitigation exists in any layer — this is a static asset problem; no middleware, test, or DB constraint can touch it.

Materiality adjustment: the finding is real and not exaggerated in substance, but "critical" overstates enterprise impact for this product. (1) It is not a security/data-integrity issue — no user data, no PII from the system itself, no breach path. (2) The photos depict public religious events (mosque competitions, Awqaf-supervised sessions) that were already published by news outlets/Facebook pages; the exposure is secondary re-use, not first publication. (3) The named-minor filename lives only in `Home photos/`, which is NOT deployed (rsync ships only `frontend-html/`), so the name is exposed only via the GitHub repo, not the website. (4) Remediation is trivial and immediate (delete 22 files, drop one HTML section, make repo private/purge history) with no product functionality lost. (5) Practical litigation likelihood in the Libyan single-country context is low, though reputational risk with Awqaf/ministry partners for a children's product is genuine. Net: a real legal/IP and child-privacy liability that must be fixed before any store listing or institutional partnership, but it is a high, not critical, finding.

```text
Confirmed as cited. Additional precision: `.cpanel.yml:4` rsyncs only `frontend-html/` → `Home photos/` (incl. the named-minor filename) is not on mutqin.ly, only on the public GitHub repo (`gh repo view muad03/MUTQIN` → PUBLIC); the 11 renamed copies under `frontend-html/img/gallery/` are what is served. Commit 7c86c22 dated 2026-07-17. EXIF markers `SONY`, `ILCE-7M3`, `2023:08:19 11:34:53` present in g10; `Photoshop 3.0` IPTC block in g04. No licence/credit/consent file anywhere in the repo (only Laravel's MIT notice in backend/README.md).
```

</details>

### No LICENSE or ownership statement anywhere, while composer.json and README advertise MIT inherited from the Laravel skeleton; multiple contributor identities with no assignment

<a id="no-license-false-mit-declaration"></a>

`no-license-false-mit-declaration` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/composer.json:2-6`, `backend/README.md:56-58`, `frontend-html/index.html:373`, `frontend-html/login.html:70`, `backend/DEPLOY_LOG.md:36`, `(missing) LICENSE / NOTICE at repo root, backend/, frontend-html/`

**Evidence**

```text
composer.json: `"name": "laravel/laravel"`, `"description": "The skeleton application for the Laravel framework."`, `"license": "MIT"` — `git log -L '/"license"/,+1:backend/composer.json'` shows it was never touched after the initial snapshot be47c33. backend/README.md:58 is the stock text 'The Laravel framework is open-sourced software licensed under the [MIT license]'. `gh repo view muad03/MUTQIN` → `"visibility":"PUBLIC","licenseInfo":null`. No LICENSE/LICENCE/COPYING/NOTICE at any level (explicit existence checks all 'missing'); 0 of 146 first-party PHP/JS/CSS files carry a copyright or @license header. Footer index.html:373 '© ٢٠٢٦ مُتقِن — جميع الحقوق محفوظة' names a brand, not a legal person. `git shortlog -sne`: three identities — muad3719-crypto (limu.edu.ly, 87 commits, 2026-06-28→08-22), muad03 (gmail, 82, 08-22→09-11), MUTQEN <claudecombo@gmail.com> (55, 06-25→07-10); local `git config user.email` is a fourth (RADWAN, limu.edu.ly). DEPLOY_LOG.md:36 hands the admin password to a separate 'صاحب المشروع' (project owner). No CLA, assignment, contract reference or work-for-hire note in the repo.
```

**Why it matters**

The repo simultaneously signals MIT (machine-readable composer metadata harvested by SBOM tools and humans reading the README) and 'all rights reserved' (no LICENSE on a public repo). A competitor could fork it citing the MIT tag; conversely no entity can prove ownership of the code to a ministry, investor or app-store developer account; with four contributor identities and a distinct 'project owner', a dispute with the commissioning center (or a university IP claim via the limu.edu.ly academic accounts) is unresolvable on paper.

**Recommendation**

Decide the model (proprietary is the default for a SaaS). Add a root LICENSE ('Copyright © 2026 <legal entity>. All rights reserved.' or the chosen OSS licence) and NOTICE; set composer.json `"license": "proprietary"` and rename the package (e.g. mutqin/backend); replace the stock README; add a one-line copyright header or a NOTICE reference. Execute written IP assignments from every contributor identity to the owning entity, covering AI-assisted work, and record the relationship with the commissioning center and the university. Keep the GitHub repo private until this is settled.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Every factual claim checks out on read-only inspection: backend/composer.json:2-6 still carries name "laravel/laravel", the skeleton description and "license": "MIT", and `git log -L` shows that line untouched since the initial snapshot be47c33; backend/README.md:58 is the stock Laravel MIT paragraph; no LICENSE/COPYING/NOTICE exists at the repo root, backend/ or frontend-html/; 0 first-party PHP/JS/CSS files under app/routes/database/config/js/css carry a copyright or @license header; the footers at index.html:373 and login.html:70 say only "© ٢٠٢٦ مُتقِن — جميع الحقوق محفوظة" (brand, no legal person); `git shortlog -sne HEAD` shows three identities (limu.edu.ly 87, gmail 82, claudecombo 55) and local user.email is a fourth limu.edu.ly address; DEPLOY_LOG.md:45 (not :36, minor line drift) records the admin password being handed to a separate "صاحب المشروع"; `gh repo view muad03/MUTQIN` confirms visibility PUBLIC with licenseInfo null. No mitigation exists anywhere (no CLA, assignment, or ownership statement in DEPLOYMENT.md or elsewhere). However the severity is overstated for this product: the README sentence is literally true (it speaks of "The Laravel framework", not this app); the root composer.json license field is unpublished skeleton boilerplate that is ubiquitous in Laravel apps and is not distributed via Packagist; copyright subsists automatically without notice under Berne, and GitHub's terms make an unlicensed public repo "all rights reserved" by default, so the "competitor forks citing MIT" scenario rests on a weak legal basis. The real exposure is the ownership/assignment gap among four contributor identities plus an external "project owner" on a public repo — a governance/contract risk rather than a code defect, and a low-effort fix. This is a legitimate, unmitigated finding, but medium rather than high.

```text
backend/composer.json:2-6 ("name": "laravel/laravel", "license": "MIT", unchanged since be47c33); backend/README.md:56-58 (stock "The Laravel framework is open-sourced software licensed under the MIT license" — true of the framework, silent on the app); frontend-html/index.html:373 and login.html:70 (brand-only © notice); backend/DEPLOY_LOG.md:45 (admin password handed to "صاحب المشروع" — auditor cited :36, actual line is 45); no LICENSE/COPYING/NOTICE at repo root, backend/, frontend-html/; 0 first-party source files with copyright/@license headers; `git shortlog -sne HEAD`: muad3719-crypto@limu.edu.ly 87, muad.st03@gmail.com 82, claudecombo@gmail.com 55; local git user.email radhwan_3682@limu.edu.ly (4th identity); `gh repo view muad03/MUTQIN` → visibility PUBLIC, licenseInfo null.
```

</details>

### Zero privacy-policy / terms-of-use / consent pages for a product holding minors' identity, guardian phone, attendance and fingerprint data — a hard app-store blocker

<a id="no-privacy-policy-or-terms"></a>

`no-privacy-policy-or-terms` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `frontend-html/index.html:337-374`, `frontend-html/login.html`, `backend/routes/api.php`, `backend/app/Http/Controllers/Api/AttendanceImportController.php`

**Evidence**

```text
`git ls-files | grep -iE 'privacy|terms|policy|consent|شروط|خصوصية|سياسة'` → no match; `grep -rn 'سياسة الخصوصية|شروط الاستخدام|privacy' frontend-html` → no match. Footer links (index.html:357-361) are only 'عن المنطة / المميزات / من مراكزنا / تسجيل الدخول'; contact block lists 'info@mutqin.ly' and 'بنغازي، ليبيا' only. api.php exposes no terms/consent acceptance endpoint or version. The schema stores students' names, national_id, guardian_name/phone, birth_date, attendance imported from fingerprint devices, parents' id_number and phone OTPs (CLAUDE.md schema section; migrations).
```

**Why it matters**

Google Play (Data safety, Families policy for child-related apps) and Apple (Guideline 5.1.1, ToS for account-based apps) both require a public privacy-policy URL — the Flutter client cannot be submitted without one. Without terms there is no contractual basis with centers or guardians for processing, no retention rule, no liability limit and no lawful-basis record for child data; sub-processors (Libyan Spider, Google Fonts, jsDelivr, SMTP/n8n) are undisclosed.

**Recommendation**

Publish an Arabic privacy policy and terms of use on stable mutqin.ly URLs (data categories, purposes, retention, guardian rights, OTP/SMS, fingerprint imports, sub-processors, contact), link them from footer/login/app, and add a versioned acceptance record (e.g. users.terms_version/accepted_at) surfaced by `/auth/user` so the mobile app can gate first login. Have Libyan counsel review the child-data provisions. Overlaps the privacy dimension; listed here because it is a legal/compliance artefact that blocks store release.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual core of the finding is confirmed. `git ls-files | grep -iE 'privacy|terms|policy|consent|شروط|خصوصية|سياسة'` returns nothing; grepping frontend-html/, backend/routes and backend/app for 'سياسة الخصوصية|شروط الاستخدام|privacy|الخصوصية|الشروط' returns nothing; no migration or model column resembles terms_version/accepted_at/consent. The footer at frontend-html/index.html:355-371 contains exactly the four links quoted (عن المنصة / المميزات / من مراكزنا / تسجيل الدخول) plus info@mutqin.ly and بنغازي، ليبيا. AttendanceImportController does handle fingerprint-device attendance exports (7 fingerprint/بصمة mentions). The schema does hold minors' names, national_id, guardian phone, parents' id_number. No mitigation exists anywhere (no middleware, no test, no page). However the finding is over-rated: (1) the "hard app-store blocker" premise rests on a Flutter client that does not exist in this repo — `git ls-files | grep -iE 'flutter|pubspec|android|ios'` is empty and no README/DEPLOYMENT mentions Flutter, Google Play or App Store; the only client is a static web app with a PWA manifest (frontend-html/manifest.webmanifest). Store policies are therefore not currently applicable. (2) Sub-processor claims (Libyan Spider, n8n, SMTP) are not evidenced in the repo (there is no SMS gateway — CLAUDE.md confirms OTP is dev-relay only). (3) This is a closed, invite-only B2B system for Libyan Quran centers (no self-registration, accounts are created by admin/managers), where the contractual relationship with centers is typically offline; there is no Libyan statute equivalent to GDPR/COPPA that mandates a published policy. It remains a genuine legal/compliance gap (no notice, retention or guardian-rights statement for child data, no consent record), so not refuted, but it is a documentation/governance gap rather than a code defect or a present release blocker: medium.

```text
Confirmed: frontend-html/index.html:355-371 footer links only عن المنصة/المميزات/من مراكزنا/تسجيل الدخول + info@mutqin.ly, بنغازي، ليبيا; `git ls-files | grep -iE 'privacy|terms|policy|consent|شروط|خصوصية|سياسة'` → empty; `grep -rniE 'privacy|الخصوصية|الشروط' frontend-html backend/routes backend/app` → empty; `grep -rniE 'terms_version|accepted_at|consent' backend/database/migrations backend/app` → empty. Not confirmed: no Flutter/mobile client in repo (`git ls-files | grep -iE 'flutter|pubspec|android|ios'` → empty; no md mentions Flutter/Play/App Store) — only frontend-html/manifest.webmanifest (PWA, start_url ./login.html). Sub-processor list (Libyan Spider, n8n, SMTP) not evidenced in repo; CLAUDE.md states no SMS gateway exists.
```

</details>

### mPDF (GPL-2.0-only) is a hard in-process dependency of nine PDF routes; the combined bundle has already been conveyed to the client's hosting account under contradictory MIT/proprietary signals

<a id="mpdf-gpl2-copyleft-in-process"></a>

`mpdf-gpl2-copyleft-in-process` · 🟠 high (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `backend/composer.json:12`, `backend/composer.lock:2444-2493`, `backend/app/Http/Controllers/Api/ReportPdfController.php:50-66`, `backend/routes/api.php:80-82,125-128,170-171`, `.cpanel.yml:4-6`, `backend/DEPLOY_LOG.md:3-9,33-36`, `frontend-html/js/config.js:10-12`, `تشغيل-المشروع.bat:23-27`

**Evidence**

```text
composer.lock: `"name": "mpdf/mpdf"` v8.3.1, `"license": ["GPL-2.0-only"]` — the only copyleft-only production package (nette/utils and nette/schema are 'BSD-3-Clause/GPL-2.0-only/GPL-3.0-only' → BSD option available). ReportPdfController.php:50 `$mpdf = new \Mpdf\Mpdf([...])` runs in the request process for 9 routes (api.php:80-82 manager, 125-128 admin, 170-171 teacher). Distribution model from the code is a single hosted instance: `.cpanel.yml` 'export DEPLOYPATH=/home/[redacted-cpanel-user]/public_html'; DEPLOY_LOG.md:1 'سجل النشر — مُتقِن على Libyan Spider (mutqin.ly)'; config.js production API is the same-origin path `'/backend/public/api'`; the on-prem launcher hard-codes the developer's own path `C:\xampp\htdocs\MUTQENQ` and its web step is broken — no customer installer exists. A copy was nevertheless conveyed: DEPLOY_LOG 2026-09-07 'رُفعت ضمن mutqin-upload.zip' to the client-controlled cPanel account, on a server with 'بلا SSH / CLI / artisan / composer / git' (so the zip necessarily carried vendor/ incl. mPDF), and the admin password 'سُلِّمت لصاحب المشروع'. mPDF offers no commercial/dual licence. No NOTICE, written offer or GPL acknowledgement exists in the repo.
```

**Why it matters**

While the server is only operated as SaaS, GPL-2.0 (no network clause) imposes no source obligation — the design decision is defensible but undocumented. However (a) the MIT/proprietary metadata is false for the combined work; (b) the zip handed to the client's account is a distribution of a GPL combined work, so the client arguably holds GPL-2.0 rights to the whole backend bundle (including redistribution), undermining any exclusive or proprietary licence to that center or future centers; (c) any per-center install, ministry source hand-over or reseller model would force GPL on the entire backend or require an engine swap; (d) the Flutter client is unaffected (it only fetches PDFs over HTTP).

**Recommendation**

Now: record the decision 'backend distributed as SaaS only; mPDF GPL-2.0-only acknowledged' in NOTICE/THIRD-PARTY-NOTICES and ensure vendor/mpdf/LICENSE.txt plus a pointer to the source (public repo + composer.lock) accompany the copy already on the client's host. Next: if on-prem or code licensing is on the roadmap, either swap to an engine that shapes Arabic RTL under LGPL/permissive terms (TCPDF LGPL-3.0 supports RTL/Arabic; dompdf LGPL-2.1 does not shape Arabic — the developer's composer cache shows a dompdf attempt), isolate rendering behind a separate process/service (Gotenberg/Chromium, MIT/Apache) so the GPL boundary is a network API, or render reports client-side in Flutter with the Apache-2.0 `pdf` package. Add a CI licence gate so no new GPL/AGPL package enters `require`.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 72% · corrected severity: low

All quoted facts check out: backend/composer.json:12 requires mpdf/mpdf ^8.3; composer.lock:2444-2493 pins v8.3.1 with license ["GPL-2.0-only"]; ReportPdfController.php:50-66 instantiates \Mpdf\Mpdf in-process and returns Output(STRING_RETURN); routes/api.php:80-82 (manager), 125-128 (admin), 170-171 (teacher) = 9 PDF routes; no NOTICE/THIRD-PARTY file exists anywhere in the repo; DEPLOY_LOG.md:33-36 records the 2026-09-07 full-package zip upload to a host with no composer, so vendor/ (incl. mPDF) was in that zip. So the finding is not factually wrong. However the legal conclusions are overstated on several points: (1) The "contradictory MIT/proprietary signals" claim is weak — composer.json "license": "MIT" is the untouched laravel/laravel skeleton default, and MIT is GPL-compatible, so an MIT-labelled app bundled with a GPL library is a lawful combination; the "جميع الحقوق محفوظة" footers (index.html:373, login.html:70) belong to the static frontend, which never touches mPDF (it only fetches PDFs over HTTP) and is therefore outside any GPL boundary. (2) The claim that the copy was conveyed to a "client-controlled" account is not established by the repo: DEPLOY_LOG says the developer uploads manually via cPanel and hands "صاحب المشروع" the *application* admin password — nothing shows the hosting account is owned by a separate legal party. If developer and operator are the same party, there is no third-party conveyance and GPL-2.0 imposes nothing (the auditor concedes SaaS is fine). (3) Even assuming conveyance to the client: PHP is interpreted, so the zip itself IS the complete corresponding source (GPL §3 satisfied inherently), and the composer package ships vendor/mpdf/mpdf/LICENSE.txt inside vendor/, so the licence text travelled with it; the only real gap is the absent explicit acknowledgement/decision record. (4) .cpanel.yml:5 excludes vendor/ from automated deploys, so mPDF is not re-conveyed on subsequent pushes. (5) There is no evidence in the repo of any exclusive/proprietary licence, reseller model, per-center installer, or ministry hand-over — the on-prem .bat is a stale dev launcher — so the "undermines exclusive licence" impact is hypothetical. Net: a real but low-impact compliance-hygiene item (add a THIRD-PARTY-NOTICES documenting mPDF GPL-2.0 and the SaaS-only decision), not a high-severity legal exposure for a single-tenant Arabic SaaS with no licensing roadmap.

```text
backend/composer.json:6 "license": "MIT" (Laravel skeleton default, GPL-compatible — no contradiction with mPDF); backend/composer.json:12 "mpdf/mpdf": "^8.3"; backend/composer.lock:2444-2445,2492-2493 mpdf/mpdf v8.3.1 GPL-2.0-only; backend/app/Http/Controllers/Api/ReportPdfController.php:50-66 in-process \Mpdf\Mpdf; backend/routes/api.php:80-82,125-128,170-171 (9 PDF routes); .cpanel.yml:5 rsync --exclude='vendor' (automated deploys do not ship mPDF); backend/DEPLOY_LOG.md:1-9 developer deploys manually via cPanel — hosting-account ownership by a separate client not established; DEPLOY_LOG.md:33-36 one-time full zip 2026-09-07 (only conveyance event); frontend-html/index.html:373 & login.html:70 "جميع الحقوق محفوظة" are frontend-only footers (frontend never links mPDF); no NOTICE/THIRD-PARTY-NOTICES/LICENSE file in repo root or backend/ (confirmed by ls/grep).
```

</details>

### Public GitHub repo publishes production hosting internals (cPanel user, DB name, server layout, deploy procedure) and a personal desktop screenshot

<a id="public-repo-exposes-hosting-internals"></a>

`public-repo-exposes-hosting-internals` · 🟡 medium · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `.cpanel.yml:4-6`, `backend/DEPLOY_LOG.md:3-15`, `_handoff2/untitled/project/uploads/pasted-1781910600697-0.png`, `screenshots/45-demo-all-accounts.png`

**Evidence**

```text
`git remote -v` → https://github.com/muad03/MUTQIN.git; `gh repo view` → `"visibility":"PUBLIC"`, created 2026-08-22, diskUsage 10266 KB. .cpanel.yml:4 `export DEPLOYPATH=/home/[redacted-cpanel-user]/public_html`. DEPLOY_LOG.md:3-5: 'المستضيف: Libyan Spider — لوحة cPanel · المستخدم `[redacted-cpanel-user]`', 'جذر الويب: `/home/[redacted-cpanel-user]/public_html/`', 'القاعدة: `[redacted-db-name]` (phpMyAdmin من cPanel)', plus 'SSH … معطَّل' and the list of server-only paths. The handoff bundle contains a 2,088,477-byte 2560x1440 full-desktop screenshot of the developer's machine (browser tabs, Chrome profile name, taskbar apps, demo-credentials panel). Mitigating: no secrets found (`git grep -E 'APP_KEY=base64:|AKIA[0-9A-Z]{16}|-----BEGIN (RSA|OPENSSH) PRIVATE'` → none; .env never committed).
```

**Why it matters**

Half of the production credential pair (cPanel user + DB name) and the exact topology of the API the mobile app will depend on are public, lowering attacker effort against cPanel/phpMyAdmin and enabling host social-engineering; personal-environment leakage is a reputational issue for a client-facing product.

**Recommendation**

Make the repo private (or move it to an organisation owned by the legal entity); relocate DEPLOY_LOG.md and the concrete .cpanel.yml paths to a private ops repo/wiki; delete the desktop screenshot and purge it from history; rotate the cPanel and DB passwords as a precaution. Keep only licence/SBOM files public if an OSS release is ever intended.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Every factual claim checks out. `git remote -v` points at https://github.com/muad03/MUTQIN.git and `gh repo view --json visibility` returns PUBLIC. All four cited paths are tracked (`git ls-files` confirms) and are on origin/master (local HEAD 37313bf == origin/master, working tree clean). `.cpanel.yml:4` contains `export DEPLOYPATH=/home/[redacted-cpanel-user]/public_html`; `backend/DEPLOY_LOG.md:3-5` names the host (Libyan Spider), cPanel user `[redacted-cpanel-user]`, web root, DB `[redacted-db-name]`, and lines 6-15 describe the disabled SSH and the server-only file list. The handoff PNG is a 2560x1440, 2,088,477-byte image committed in the initial commit be47c33. `.env` has never been committed and `.cpanel.yml` excludes `.env`/`storage`/`vendor` on deploy, so no secret material is present — the auditor already credits this.

Partial mitigating points that cap severity: on shared cPanel hosting the account username is conventionally derived from the domain (mutqin.ly -> [redacted-cpanel-user]) and the DB name follows the `{user}_` prefix convention, so an attacker gains little that the public domain does not already imply; there is no SSH exposure (line 6-7 says port 22 is refused); and no password, key, or token appears anywhere in the tracked files.

One aggravating point the auditor missed: `backend/DEPLOY_LOG.md:49` records the unified demo password `[redacted-demo-password]` for the 2026-09-10 production DB dump, and `backend/database/seeders/LibyanDataSeeder.php:40-43` hardcodes the same value for admin/manager/teacher/parent. Both entries (09-10 demo dump and 09-11 clean dump) are marked `pending` in the log, so whether the live `mutqin.ly` database currently holds accounts with this public password cannot be confirmed from the repo alone (read-only audit, no server access). If the demo dump is what is live, an admin login to the production API is trivially derivable from public files — which would push this to high. Absent confirmation, medium stands. The finding is real and not over-rated; the `demoAccounts` endpoint guard (DashboardController.php:105, hides user list unless local/APP_DEBUG) does not address any of the cited exposures.

```text
Confirmed: `.cpanel.yml:4` `export DEPLOYPATH=/home/[redacted-cpanel-user]/public_html`; `backend/DEPLOY_LOG.md:3` cPanel user `[redacted-cpanel-user]`, `:4` web root, `:5` DB `[redacted-db-name]`, `:6-7` SSH disabled, `:13-18` server-only paths. `git ls-files` shows all four cited files tracked; `gh repo view --json visibility` -> PUBLIC; local HEAD 37313bf == origin/master. Screenshot `_handoff2/untitled/project/uploads/pasted-1781910600697-0.png` is PNG 2560x1440, 2,088,477 bytes, added in be47c33. Additional (missed by auditor): `backend/DEPLOY_LOG.md:49` "كلمة المرور الموحّدة [redacted-demo-password]" for the 2026-09-10 production demo dump, matching `backend/database/seeders/LibyanDataSeeder.php:40-43` (`ADMIN_PASSWORD = '[redacted-demo-password]'` etc.); both the 09-10 demo and 09-11 clean-DB entries are marked "حالة الرفع: pending" (lines ~46, ~54), so live-DB state is unverifiable from the repo. Mitigating: no `.env` ever committed (`git log --all -- backend/.env .env` empty), `.cpanel.yml:6` excludes `.env`/`storage`; cPanel username and DB prefix are domain-derivable on shared hosting.
```

</details>

### The mark مُتقِن is spelled four ways in Latin, attributed to a single center in the footer, implies Awqaf supervision, and has no owner, notice or registration

<a id="brand-trademark-ownership-unclear"></a>

`brand-trademark-ownership-unclear` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/index.html:353`, `frontend-html/index.html:373`, `frontend-html/index.html:320`, `frontend-html/login.html:70`, `frontend-html/manifest.webmanifest:2-3`, `backend/resources/views/pdf/layout.blade.php:45,56`, `CLAUDE.md:5`, `تشغيل-المشروع.bat:23`

**Evidence**

```text
Spellings: 'MUTQEN' (CLAUDE.md:5 'MUTQEN (مُتقِن)', commit be47c33), 'MUTQIN' (GitHub repo name), 'mutqin' (domain mutqin.ly, storage keys `mutqin_token`), 'MUTQENQ' (launcher path). index.html:353 brands the platform footer with 'مركز بلال بن رباح لتحفيظ القرآن الكريم' while :373 claims '© ٢٠٢٦ مُتقِن — جميع الحقوق محفوظة'; PDF footer layout.blade.php:56 'تقرير صادر من منصة مُتقِن لإدارة مراكز التحفيظ'; caption :320 'مجلس تحفيظ بإشراف هيئة الأوقاف'. No ™/®, registration number, owner entity or trademark policy anywhere; 'متقن' is a common descriptive Arabic adjective (proficient).
```

**Why it matters**

When the product is sold to other centers nobody can show who owns the name and device mark (developer, Bilal ibn Rabah center, or the 'project owner'); a descriptive word with inconsistent transliteration is weak and prone to collision with existing Quran apps on the stores (store name reservation is first-come); the Awqaf caption risks an implied-endorsement claim from a government authority.

**Recommendation**

Fix one Latin spelling (match the domain: Mutqin) across repo, docs and store listings; run a clearance search (Libyan trademark office, Apple/Google store names, domains, existing Quran apps) and file the word + octagram device mark in Libya (classes 9/41/42) in the owning entity's name; add an ownership line to footer/PDF; make the center name a per-tenant setting rather than the platform's own footer; remove the Awqaf-supervision caption unless written permission exists.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The factual evidence largely checks out. frontend-html/index.html:353 does hardcode 'مركز بلال بن رباح لتحفيظ القرآن الكريم' in the platform footer beneath the مُتقِن logo block (:340-348), while :373 and login.html:70 print '© ٢٠٢٦ مُتقِن — جميع الحقوق محفوظة' with no named owner entity. The PDF layout (layout.blade.php:45,56) brands reports as 'منصة مُتقِن'. Latin spellings really are inconsistent: MUTQEN (CLAUDE.md:7, not :5), MUTQIN (git remote github.com/muad03/MUTQIN), mutqin (config.js storage keys, *.mutqin.ly emails), MUTQENQ (launcher path in تشغيل-المشروع.bat:23 and CLAUDE.md:30). No LICENSE file, ™/®, or trademark/ownership notice exists anywhere in the repo (grep across html/md/php returned nothing). The center name is not a tenant setting — it is a literal string; the same center is also the seeded demo center (LibyanDataSeeder.php:147), which suggests it leaked from demo data into platform branding. So the finding is not refutable as false, and there is no mitigation in code (this is not a code-behavior issue, so middleware/tests are irrelevant). However, it is exaggerated in two places: (1) the 'Awqaf supervision' item at :320 is a gallery photo figcaption ('مجلس بإشراف الأوقاف', alt 'مجلس تحفيظ بإشراف هيئة الأوقاف') describing what the photograph depicts, not a claim that the platform is supervised or endorsed by Awqaf — implied-endorsement risk is weak; (2) for a small, Arabic-only, single-country, currently single-center deployment with no store listing, trademark clearance/registration is forward-looking commercial hygiene, not a present defect. The concrete, cheap actionable items are the hardcoded center name in the platform footer and the missing owner/LICENSE line. Severity should be low rather than medium.

```text
frontend-html/index.html:340-348 (مُتقِن logo block) and :353 (hardcoded 'مركز بلال بن رباح لتحفيظ القرآن الكريم' as footer identity); :373 and frontend-html/login.html:70 ('© ٢٠٢٦ مُتقِن — جميع الحقوق محفوظة', no owner entity); frontend-html/index.html:320 is only a gallery <figcaption> 'مجلس بإشراف الأوقاف' describing a photo, not a platform claim; backend/resources/views/pdf/layout.blade.php:45,56; CLAUDE.md:7 ('MUTQEN (مُتقِن)') and :30 ('MUTQENQ' path); تشغيل-المشروع.bat:23 ('C:\xampp\htdocs\MUTQENQ'); git remote https://github.com/muad03/MUTQIN.git; frontend-html/js/config.js storage keys mutqin_token/mutqin_user; backend/database/seeders/LibyanDataSeeder.php:147 (same center is the seeded demo center). No LICENSE file or trademark notice anywhere in the repo.
```

</details>

### No THIRD-PARTY-NOTICES, SBOM or licence gate; PDF font actually embedded for Arabic is chosen at runtime by mPDF and its licence is not on record

<a id="no-sbom-third-party-notices"></a>

`no-sbom-third-party-notices` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/composer.lock`, `backend/app/Http/Controllers/Api/ReportPdfController.php:61-62`, `backend/resources/views/pdf/layout.blade.php:6,24`, `frontend-html/index.html:8`

**Evidence**

```text
composer.lock: 87 production packages (78 MIT, 5 BSD-3-Clause, 2 BSD-3/GPL-2.0/GPL-3.0, 1 GPL-2.0-only, 1 Apache-2.0) + 39 dev (26 BSD-3, 13 MIT); no notices file, no `composer licenses`/CycloneDX output, no CI. Frontend loads `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css` (MIT) in 33 HTML files. ReportPdfController.php:61-62 `$mpdf->autoScriptToLang = true; $mpdf->autoLangToFont = true;` — upstream mPDF v8.3.1 `src/Language/LanguageToFont.php` maps `case 'ar': $unifont = 'xbriyaz';` and script `case 'arab': return 'xbriyaz';`, so Arabic runs are set in XB Riyaz although the template declares `font-family: dejavusans` (layout.blade.php:6; even `.amiri { font-family: dejavusans; }` at :24). mPDF v8.3.1 ttfonts/ ships `XB Riyaz.ttf` with no dedicated licence file (only 'XW Zar Font Info.txt' → SIL OFL 1.1 next to it) and DejaVu under the Bitstream Vera licence ('The above copyright and trademark notices and this permission notice shall be included in all copies'). vendor/ is absent on this workstation, so the runtime font choice was verified against upstream sources, not a local install.
```

**Why it matters**

MIT/BSD/Apache all require preserving notices in redistributed copies (the deploy zip is one); without an SBOM the team cannot answer a ministry or customer security/licence questionnaire, cannot detect a future copyleft or vulnerable package, and cannot state which font (and licence) is embedded in every report a center prints.

**Recommendation**

Generate backend/THIRD-PARTY-NOTICES.md (`composer licenses --format=json` rendered) and a CycloneDX SBOM (`cyclonedx/cyclonedx-php-composer`) in CI with an allow-list (MIT, BSD, Apache-2.0, OFL, LGPL) that fails on GPL/AGPL additions; add a frontend section (Bootstrap 5.3.3 MIT, Amiri/Cairo OFL); pin the Arabic PDF font explicitly via mPDF `fontdata` (bundle Amiri/Cairo TTF with OFL.txt) instead of relying on autoLangToFont; produce the Flutter SBOM the same way (`flutter pub deps --json` + licence page).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Cited evidence checks out on disk. backend/app/Http/Controllers/Api/ReportPdfController.php:61-62 sets `$mpdf->autoScriptToLang = true; $mpdf->autoLangToFont = true;` with no `fontdata`/`fontDir`/`default_font` override anywhere in app/, config/ or resources/ (grep returned only default_font_size). backend/resources/views/pdf/layout.blade.php:6 and :24 declare `font-family: dejavusans`, including the misnamed `.amiri` class. In mPDF 8.x the lang->font mapping applied by autoLangToFont is unconditional for the tagged run (LanguageToFont maps 'ar' to xbriyaz), so the CSS font declaration is indeed overridden for Arabic text and the actually-embedded face is XB Riyaz, not DejaVu — the code behaves as the auditor claims. Repo-level: no LICENSE/NOTICE/THIRD-PARTY/SBOM file exists outside vendor (find over the repo returned nothing), backend/composer.json requires mpdf/mpdf ^8.3, and 33 frontend HTML files load Bootstrap 5.3.3 from jsDelivr. No mitigation exists (no CI, no licence tooling, no notices in DEPLOYMENT.md). vendor/ is absent locally so the exact bundled XB Riyaz licence text could not be inspected here, but that does not change the finding. Severity is somewhat overstated for this product: all production dependencies are permissive (MIT/BSD/Apache/OFL-style), the app is a single-tenant internal tool for Libyan memorization centres rather than redistributed software, and the notice-preservation obligations are trivially satisfiable; the residual issue is documentation/process hygiene (SBOM, pinning the PDF font explicitly, notices file), not a live legal exposure. Downgrade to low.

```text
backend/app/Http/Controllers/Api/ReportPdfController.php:50-62 — Mpdf constructed with only mode/format/default_font_size/tempDir/margins (no fontdata/fontDir/default_font), then `autoScriptToLang = true; autoLangToFont = true;`. backend/resources/views/pdf/layout.blade.php:6 `body { font-family: dejavusans; ... }` and :24 `.amiri { font-family: dejavusans; }`. backend/composer.json:12 `"mpdf/mpdf": "^8.3"`. No LICENSE/NOTICE/THIRD-PARTY/SBOM file anywhere in the repo outside vendor; vendor/ not present locally. 33 frontend-html HTML files reference https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css (frontend-html/index.html:8).
```

</details>

### 223 of 224 commits are AI co-authored and the design system is a Claude Design export, but no authorship/ownership policy or account-holder record exists

<a id="ai-assisted-authorship-undocumented"></a>

`ai-assisted-authorship-undocumented` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `git history (224 commits)`, `_handoff2/untitled/README.md:3`, `CLAUDE.md`

**Evidence**

```text
`git log --format='%b' | grep -c 'Co-Authored-By: Claude'` → 223 of 224 commits (130 'Claude Fable 5', 59 'Claude Fable 5.1', 27 'Claude Opus 4.8', 4 'Claude Opus 4.8 (1M context)', 3 'Claude Opus 5 (1M context)', all <noreply@anthropic.com>). _handoff2/untitled/README.md: 'This is a **handoff bundle** from Claude Design (claude.ai/design)'. Three human committer identities plus a generic 'MUTQEN <claudecombo@gmail.com>' account; no statement of which account holder directed the tools, no human-review record, no CLA.
```

**Why it matters**

Copyright protection for purely AI-generated code is unsettled (US Copyright Office requires human authorship; Libya's Copyright Law 9/1968 predates AI); enterprise buyers, investors and a ministry will ask who owns what. Anthropic's terms assign outputs to the customer account, but the repo does not record which of the identities held those accounts, so the chain of title has a gap.

**Recommendation**

Add an 'AI-assisted development' statement to the ownership document (tools, account holders, human direction and review practice), keep review evidence (PRs/approvals) going forward, and have every contributor identity sign an IP assignment expressly covering AI-assisted contributions to the owning entity.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The quoted evidence is accurate and reproducible: `git rev-list --count HEAD` = 224; `git log --format='%b' | grep -c 'Co-Authored-By: Claude'` = 223, split exactly as stated (130 Fable 5, 59 Fable 5.1, 27 Opus 4.8, 4 Opus 4.8 (1M), 3 Opus 5 (1M), all noreply@anthropic.com). Author/committer identities are `muad3719-crypto <muad_3719@limu.edu.ly>` (87), `muad03 <muad.st03@gmail.com>` (82) and `MUTQEN <claudecombo@gmail.com>` (55). `_handoff2/untitled/README.md:3` does say it is a Claude Design handoff bundle. There is no LICENSE, AUTHORS, CONTRIBUTING, CLA or ownership document anywhere in the tree (git ls-files finds none); DEPLOYMENT.md and CLAUDE.md say nothing about AI authorship or ownership. So the finding is not factually wrong. Mitigations that soften it: every commit carries a human author/committer identity (the AI appears only as a co-author trailer), so human direction is evidenced per commit, and the three identities plausibly resolve to one or two real people (muad03 / muad3719-crypto share the same first name; the generic MUTQEN account is the only truly anonymous one). The impact is speculative for this product (a small, single-country, Arabic-only Quran-center system with no external investors or contributors yet), and the fix is a documentation task, not a code change. I therefore keep the finding but lower it to low. One additional inconsistency worth folding in: backend/composer.json declares "license": "MIT" (Laravel skeleton default) while frontend-html/index.html:373 asserts "© ٢٠٢٦ مُتقِن — جميع الحقوق محفوظة" (all rights reserved) — the repo currently makes contradictory licensing statements.

```text
git: 224 commits, 223 with 'Co-Authored-By: Claude ... <noreply@anthropic.com>' (130/59/27/4/3 split confirmed); authors: muad3719-crypto <muad_3719@limu.edu.ly> x87, muad03 <muad.st03@gmail.com> x82, MUTQEN <claudecombo@gmail.com> x55. _handoff2/untitled/README.md:3 'This is a **handoff bundle** from Claude Design (claude.ai/design)'. No LICENSE/AUTHORS/CLA/ownership file in `git ls-files`. Contradictory statements: backend/composer.json "license": "MIT" vs frontend-html/index.html:373 '© ٢٠٢٦ مُتقِن — جميع الحقوق محفوظة'.
```

</details>

### Brand fonts are hot-linked from Google Fonts with no self-hosted copies or OFL text; the logo icon relies on live Amiri text

<a id="fonts-hotlinked-no-ofl-notice"></a>

`fonts-hotlinked-no-ofl-notice` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/css/theme.css:6`, `frontend-html/images/logo.svg`, `frontend-html/manifest.webmanifest:15`, `_handoff2/untitled/project/*.html`

**Evidence**

```text
theme.css:6 `@import url('https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Cairo:wght@400;500;600;700;800&display=swap');` is the only font source; `git ls-files | grep -iE '\.(woff2?|ttf|otf|eot)$'` → none; no OFL.txt anywhere. logo.svg renders the wordmark as `<text … font-family="Amiri, serif">`, so the PWA icon (manifest.webmanifest:15 → images/logo.svg) depends on the viewer having Amiri installed. The handoff HTML imports the same Google Fonts URL four times.
```

**Why it matters**

Amiri and Cairo are OFL-1.1 — bundling and embedding are permitted — but the OFL requires the licence text to accompany the font software once it is redistributed (Flutter bundle, self-hosted web, PDF fontdata); today the product neither ships the fonts nor the licence. Runtime dependence on Google means brand fonts silently vanish on blocked/slow Libyan networks and every visitor's IP is sent to Google; the wordmark icon renders differently per device.

**Recommendation**

Self-host Amiri/Cairo (woff2 + TTF) under frontend-html/fonts/ with OFL.txt and `font-display: swap`; convert the logo text to outlines and export a raster icon set (192/512/1024 + maskable); bundle the same TTFs in Flutter under assets/fonts and register the OFL text with `LicenseRegistry.addLicense` so it appears in the app's licences page.

### _handoff2 Claude Design export redistributes an unlicensed vendor runtime and 3.3 MB of design/scratch files in the product repo

<a id="handoff-bundle-provenance"></a>

`handoff-bundle-provenance` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `_handoff2/untitled/README.md:3`, `_handoff2/untitled/project/support.js:1`, `_handoff2/untitled/project/uploads/ (5 PNG)`, `_handoff2/untitled/project/.thumbnail`

**Evidence**

```text
README: 'This is a **handoff bundle** from Claude Design (claude.ai/design) … the `تصميم الهوية البصرية` project files' — 'untitled' is the tool's default project name, not a third-party project. support.js line 1: '// GENERATED from dc-runtime/src/*.ts — do not edit. Rebuild with `cd dc-runtime && bun run build`.' (53,975 bytes, no licence or copyright header; grep for license/copyright → none). Brand PNGs (logo variants, palette, fonts board, colour semantics) are first-party outputs of the design session; the fifth PNG is the 2 MB desktop screenshot. 14 tracked files, 3.3 MB.
```

**Why it matters**

The brand assets are the user's own outputs (Anthropic terms assign outputs to the customer), but the bundle republishes Anthropic's dc-runtime with no stated redistribution licence, bloats the product repo and blurs which design-system file is canonical (mutqin.css vs frontend-html/css/theme.css).

**Recommendation**

Move the brand PNG/CSS into design/brand/ with an ownership note; delete support.js, .thumbnail and the screenshot; keep the raw Claude Design export in a private design repo.

### n8n attendance digest is within the Sustainable Use License today but the boundary is undocumented

<a id="n8n-sustainable-use-scope"></a>

`n8n-sustainable-use-scope` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `n8n/README.md`, `n8n/mutqin-daily-attendance-digest.json`, `n8n/attendance-digest.code.js`

**Evidence**

```text
Workflow JSON uses only `n8n-nodes-base.scheduleTrigger/set/httpRequest/code/if/emailSend/noOp` (no `.ee.` enterprise nodes); README: 'it logs into the MUTQEN API, pulls today's attendance, builds an Arabic summary, and emails it'. n8n Sustainable Use License: 'You may use or modify the software only for your own internal business purposes or for non-commercial or personal use'; files with '.ee.' require an Enterprise licence.
```

**Why it matters**

Compliant as an operator-run internal digest. It stops being compliant if the digest is sold to centers as a hosted automation run on n8n, or if centers are given access to configure n8n workflows (providing n8n to third parties).

**Recommendation**

State the boundary in n8n/README (internal ops only; move the password into an n8n credential); if the digest becomes a product feature, implement it as a Laravel scheduled command using the existing notification infrastructure, or obtain an n8n Enterprise/Embed licence.

### Thumn index and surah→juz table have no recorded source edition or verification

<a id="quran-data-provenance-unrecorded"></a>

`quran-data-provenance-unrecorded` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `backend/database/seeders/AthmanSeeder.php:17`, `backend/database/data/فهرس_الأثمان_الكامل.xlsx`, `backend/app/Support/SurahReference.php:16-40`

**Evidence**

```text
AthmanSeeder.php:17 `$path = database_path('data/فهرس_الأثمان_الكامل.xlsx');` (477 rows of hizb/thumn, surah, Quranic `start_text`, page); the xlsx docProps say `dc:creator = openpyxl`, created 2026-06-21T04:24:27Z — no Mushaf edition, Tanzil/KFGQPC or other source named; SurahReference.php hard-codes the 114 surah→juz map with no citation.
```

**Why it matters**

The Quranic text is public domain and the hizb/thumn division is factual, so IP exposure is minimal; but for a memorization product the edition matters (Madinah Mushaf pagination assumed; Awqaf partners expect certified text) and, if the text was copied from a digital edition such as Tanzil, its attribution/no-modification terms apply.

**Recommendation**

Add DATA-SOURCES.md naming the edition and verification method, keep any source attribution terms, and add a checksum/unit test of the 477 start texts against the reference edition.

### Canonical docs omit or misstate the files that create legal/ops exposure

<a id="doc-drift-legal-surface"></a>

`doc-drift-legal-surface` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `CLAUDE.md:25`, `CLAUDE.md:32`, `CLAUDE.md:34`, `frontend-html/README.md`, `backend/database/seeders/LibyanDataSeeder.php:40-43`, `backend/DEPLOY_LOG.md`

**Evidence**

```text
CLAUDE.md:25 '20 feature-test files' vs 38 tracked in backend/tests/Feature; CLAUDE.md:32 demo password `[redacted-demo-password]` vs LibyanDataSeeder.php:40-43 `'[redacted-demo-password]'` and frontend-html/README.md `password`; CLAUDE.md:34 says `frontend/` still holds a leftover routes/web.php but `git ls-files frontend` is empty (untracked in 4104a4d). CLAUDE.md never mentions backend/DEPLOY_LOG.md, .cpanel.yml, n8n/, _handoff2/, Home photos/, the production host or the MessageController/AdminUserController subsystems — precisely the artefacts carrying the licence, asset and hosting exposure found above.
```

**Why it matters**

Counsel, auditors and new engineers reading the canonical document will not find the assets and deployment facts that create obligations; stale credentials in docs cause support confusion.

**Recommendation**

Add a 'Legal, assets & deployment' section to CLAUDE.md (licence, owner, asset ledger location, distribution model = SaaS, third-party notices), correct the counts/passwords, and keep DEPLOY_LOG private.

## Measured facts

| Metric | Value |
|---|---|
| Tracked files / tracked size | 320 files / 15.0 MB (GitHub diskUsage 10,266 KB); repo PUBLIC, licenseInfo null |
| LICENSE / NOTICE / COPYING files | 0 at root, backend/, frontend-html/ |
| First-party source files with copyright or @license header | 0 of 146 (backend app/routes/database/tests + frontend js/css) |
| Composer production packages by licence | 87 total: 78 MIT, 5 BSD-3-Clause, 2 BSD-3/GPL-2.0/GPL-3.0 tri-licensed (nette/utils, nette/schema), 1 GPL-2.0-only (mpdf/mpdf v8.3.1), 1 Apache-2.0 (phpoption/phpoption) |
| Composer dev packages by licence | 39 total: 26 BSD-3-Clause, 13 MIT |
| Hard copyleft packages in production | 1 (mpdf/mpdf GPL-2.0-only), used in-process by 9 PDF routes |
| Frontend third-party dependencies | 1 CDN stylesheet (bootstrap@5.3.3 RTL, MIT, in 33 HTML files) + 1 Google Fonts import (Amiri, Cairo — OFL-1.1); 0 vendored libraries; 0 local font files; 0 OFL licence texts |
| Gallery photos | 11 files, 1,083,008 bytes, byte-identical to 11 files in Home photos/ (22 files, ~2.17 MB total); 0 with licence/credit metadata; 3 with camera/CDN provenance markers; 1 filename naming a minor; used on 1 page (index.html), 0 in PDFs, 0 in PWA icons |
| Privacy policy / terms / consent pages | 0 |
| _handoff2 design bundle | 14 tracked files, 3.3 MB, incl. 2,088,477-byte desktop screenshot and 53,975-byte unlicensed dc-runtime support.js |
| screenshots/ folder | 36 PNG, 4.9 MB (demo UI incl. landing gallery) |
| Commits / AI co-authored / committer identities | 224 commits; 223 (99.6%) with Claude Co-Authored-By trailer; 3 identities in history (87/82/55 commits) + a 4th in local git config |
| Feature test files | 38 tracked (CLAUDE.md says 20) |
| Brand name spellings in Latin | 4 (MUTQEN, MUTQIN, mutqin, MUTQENQ) |
| Secrets in history | 0 found (.env never committed; no APP_KEY/AKIA/private-key matches) |
| Copies of the GPL-combined backend conveyed outside the developer | 1 (mutqin-upload.zip to client cPanel account, 2026-09-07 per DEPLOY_LOG.md) |
| n8n workflow nodes | 8, all n8n-nodes-base (0 enterprise .ee nodes) |

## Auditor notes

Read-only audit: no files were modified, no commands with side effects were run; external verification used `gh api`/WebFetch against upstream mPDF v8.3.1 and the n8n licence text because backend/vendor is absent on this workstation. Deliverables requested by the brief are embedded in the findings: (1) SBOM = metrics + finding no-sbom-third-party-notices (full histogram from composer.lock; frontend deps enumerated); (2) mPDF decision = finding mpdf-gpl2-copyleft-in-process (distribution model determined from code as single hosted SaaS at mutqin.ly; the on-prem launcher is a broken developer convenience, not a customer installer; one combined copy already conveyed to the client's host); (3) asset provenance/consent ledger = finding child-photos-unlicensed-no-consent lists every image with original filename, provenance marker, dimensions and metadata status — ledger status for all 11 is 'source unknown / no licence / no release'; other assets: logo.svg and brand PNGs first-party (rights-clean), favicon.ico stock Laravel, 36 UI screenshots first-party demo data, attendance_test.xlsx demo names, thumn xlsx generated in-house; (4) LICENSE/ownership statement = finding no-license-false-mit-declaration. Minor items not raised as findings: the hand-drawn 24x24 stroke icon paths in ui.js resemble Feather/Lucide (MIT) — if copied, add the MIT notice; nette/utils and nette/schema should be recorded under their BSD-3 option; mPDF cannot be commercially relicensed (GPL-only, no dual licence); the brief counted 10 photos and 39 tests — actual counts are 11 and 38; the file named after a minor should be treated as personal data and not propagated into reports; CLAUDE.md's `[redacted-demo-password]` password and `frontend/` leftover statements are stale. Recommended sequencing for the 2-week Flutter window: remove photos + make repo private + LICENSE/ownership + privacy policy/ToS (all S/M), then engine decision, trademark filing, SBOM gate and font self-hosting in weeks 3-8.
