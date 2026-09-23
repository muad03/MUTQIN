# Design System & Brand Identity

[← Enterprise Audit](../enterprise-audit.md)

**Score 58 / 100** — Significant risk · maturity **L2** · weight 3%

The brand identity itself is distinctive and applied with real consistency (eight-point star, emerald/gold/ivory, Amiri display + Cairo body, RTL on all 33 pages, brand colours even in mPDF reports and the PWA theme-color), and theme.css contains a genuinely mature button system (8 variants x 3 sizes, hover/active/disabled/focus-visible/loading, reduced-motion) plus solid mobile adaptations (bottom nav, bottom sheets, 44-48px touch targets, iOS 16px inputs, safe-area insets). But as a *system* it is level-2 at best: tokens exist in :root yet the pages bypass them (486 hardcoded hex values and 785 inline style attributes vs 24 var() references outside theme.css); there are two contradictory sources of truth (the June handoff bundle vs the September theme.css, with diverging button shape, paper colour, radii, and even the brand palette PNG disagreeing with every CSS file on the primary hex); there is no type scale, spacing scale, motion or elevation tokens; no dark mode; several AA contrast failures baked into the tokens; the logo is not a proper asset (six inline variants, wordmark depends on a web font that is not embedded, no PNG app icons); and no design documentation exists in the repo beyond a one-line mention. A Flutter team can reverse-engineer a theme from theme.css in a day, which keeps this out of the <40 band, but the design owner must resolve real contradictions first, so it lands at the top of the significant-risk band.

## What is already strong

- Coherent, distinctive brand applied everywhere: emerald #04532F / gold #D4AF37 / ivory #FBF7DA, eight-point-star motif, Amiri display + Cairo body. Every one of the 33 pages declares <html lang="ar" dir="rtl"> and links css/theme.css; the same palette reaches the mPDF layout (backend/resources/views/pdf/layout.blade.php:7-26 uses #04532F/#D4AF37/#FBF7DA/#B23A48/#9A7A1E) and the PWA (manifest.webmanifest:11 theme_color #04532F; js/config.js:38 meta theme-color).
- A real button system, better than the handoff: frontend-html/css/theme.css:37-138 defines 19 interaction tokens (--mq-green-hover/active, tints, disabled bg/fg, focus ring) and .mq-btn with 8 variants (primary/secondary/gold/danger/danger-outline/text/quiet/quiet-danger) x 3 sizes (40/46/54px), :focus-visible ring (line 63), :disabled, .is-loading spinner (119-131) and @media (prefers-reduced-motion:reduce) (134-138). 83 usages of class="mq-btn across pages.
- Mobile-first adaptations already encode app conventions a Flutter team can mirror: bottom navigation with min-height:48px and env(safe-area-inset-bottom) (theme.css:300-315), off-canvas drawer + scrim (317-323), modals become bottom sheets with 22px top radii and a 220ms ease-out slide (336-348), inputs forced to 16px/46px on mobile to stop iOS zoom (353), table action buttons enlarged to 40px on touch (290).
- Semantic status colours are harmonised with the brand rather than Bootstrap defaults: --c-success #04532F, --c-danger #B23A48, --c-warning #9A7A1E, --c-info #2A6F8E, --c-orange #C57B2C, --c-secondary #6B7C72 (theme.css:13-14), with matching badge/stat/alert tints, and a single JS entry point UI.badge()/attendanceBadge()/qualityBadge() (js/ui.js:89-112) so status semantics are consistent across roles.
- Namespaced classes (mq-, rp-, pc-, msg-, sd-, pf-) are used consistently and documented inline in Arabic; theme.css has 19 commits of steady evolution (2026-06-25 to 2026-09-11) rather than one-off hacks, and 147 var() references inside theme.css show the CSS layer itself is token-driven.
- The handoff bundle demonstrates that fonts can be self-hosted: _handoff2/.../موقع متقن - نسخة مستقلة.html embeds 24 @font-face woff2 blocks (Amiri 400/400i/700, Cairo 400-800) with Arabic/Latin unicode-ranges, and both fonts are SIL OFL 1.1, so bundling in a Flutter app is licence-clean.

## Level-5 target state

A single versioned tokens.json (W3C DTCG) is the source of truth for colour (semantic roles with light and dark value sets), type scale, spacing, radius, elevation, motion and z-layers; theme.css :root and a Dart MutqinTheme/ThemeData are generated from it in CI, and a lint fails the build on raw hex or off-scale values in pages. A living component catalogue page renders every mq- component in every state (including alert, progress, pagination, empty, dialog) and is the visual contract shared by web and Flutter, with WCAG AA contrast and touch-target checks automated on the tokens. The brand kit (outlined logo variants, PNG/maskable app icons, self-hosted OFL fonts also registered in mPDF, icon set as SVG + IconData) lives in the repo with a DESIGN.md that records the brand story, logo rules and RTL rules, and the design owner reviews token changes through the same PR process as code.

## What the Flutter team must know

The mobile team can build a faithful ThemeData in about a day from theme.css, but must NOT use the _handoff2 bundle as the spec: it predates the current button system, canvas colour and radii. Decisions to force before sprint 1: (1) canonical hex (CSS #04532F/#FBF7DA vs palette PNG #004F2E/#FDFDD0), (2) one 'paper' scaffold colour (four candidates exist), (3) light-only vs dark scheme, (4) bundling Amiri+Cairo (OFL, ~1-2 MB) and which weights, (5) an outlined master logo and PNG launcher icons (none exist; logo.svg text depends on a web font), (6) an icon language (the web mixes SVG and emoji -- do not port the emoji). Component semantics to mirror: button 8 variants x 3 heights (40/46/54), 10px radius, focus ring 3px white + 3px gold, loading spinner state; inputs 46px min, ivory-soft fill, 1.5px 22%-emerald border, 4px focus halo; cards 16px radius with hairline border and the brand shadow; badges pill with 6px dot; status colours success=emerald, danger #B23A48, warning gold-deep, info #2A6F8E; stat cards with 3px coloured top border; bottom sheets with 22px top radii and 220ms ease-out; sidebar gradient emerald->emerald-dark with gold active state. Fix the contrast tokens (muted, faint, gold-deep, disabled, orange) before export so the app does not inherit AA failures. Use only Directional layout primitives; all copy is Arabic, week starts Saturday, numbers in Western digits in PDFs but Arabic-Indic digits appear in the handoff mockups -- confirm digit policy for the app.

## Findings — 14 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [Design tokens exist in :root but pages hardcode hex and inline styles instead](#tokens-defined-not-consumed) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | M |
| [Handoff bundle and theme.css contradict each other (and the handoff contradicts itself)](#two-sources-of-truth) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Logo mark exists in six divergent inline variants and the SVG wordmark depends on a non-embedded font; no PNG app icons](#logo-not-an-asset) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Several core tokens fail WCAG AA where they are used for text](#contrast-failures-in-tokens) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Fonts are a render-blocking Google Fonts @import with generic fallbacks; PDFs do not use the brand fonts](#font-delivery-and-bundling) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [No dark theme or colour-scheme handling anywhere](#no-dark-mode) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Bootstrap 5 RTL CSS is loaded on all 33 pages but no Bootstrap class is used; docs still call it a Bootstrap client](#bootstrap-dead-weight-doc-drift) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [No typographic, spacing, radius, elevation, motion or z-index scales -- values are ad hoc](#no-type-space-radius-scales) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Components specified in the handoff are missing from theme.css; dialogs and alerts are unbranded or inline](#component-gaps-vs-handoff) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [Iconography is split between a 20-glyph SVG line set and 129 emoji used as icons](#iconography-emoji-vs-svg) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | M |
| [theme.css carries two overlapping token namespaces with conflicting 'muted'/'ink'/'shadow' values](#duplicate-token-namespaces) | ⚪ low | ℹ️ informational | NEXT | S |
| [No in-repo design documentation, usage rules or component catalogue; CLAUDE.md is a one-liner and the handoff README is agent-oriented](#no-design-documentation) | ⚪ low | ℹ️ informational | NEXT | S |
| [Icon-only controls lack accessible names; ARIA is nearly absent](#a11y-semantics-in-components) | ⚪ low | ℹ️ informational | NEXT | S |
| [Layout uses physical (right/left) properties; RTL is assumed rather than expressed logically](#rtl-physical-properties) | ⚪ low | ℹ️ informational | LATER | S |

### Design tokens exist in :root but pages hardcode hex and inline styles instead

<a id="tokens-defined-not-consumed"></a>

`tokens-defined-not-consumed` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `frontend-html/css/theme.css:8-22`, `frontend-html/admin/students.html:111-149`, `frontend-html/login.html:60-63`, `frontend-html/js/ui.js:17-25`

**Evidence**

```text
Census (grep over frontend-html): 486 hardcoded 6-digit hex occurrences in HTML (#7A8F82 x119, #04532F x64, #9A7A1E x52, #B23A48 x51, #9DB3A6 x46 ...) and 43 in JS, versus only 24 var(--...) references outside theme.css. 785 style="..." attributes across 33 pages (manager/students.html 78, index.html 76, admin/students.html 69). Example admin/students.html:115: `<div style="font-size:12px;color:#7A8F82;margin-top:4px;">`; js/ui.js:17-22 toast colours are a JS literal map `success: ['#04532F','rgba(4,83,47,.12)'] ...`; login.html:60-62 the primary CTA is an inline-styled <button style="...color:#FBF7DA;background:#04532F;border:none;padding:16px;border-radius:13px"> rather than .mq-btn--primary. About 10 off-token colours also appear only in pages (#C9DCD1, #1F5C3E, #1F7A52, #0A6342, #0A3D27, #F1F7F3, #F0DFA6, #EAF2EC, #C9D8CF, #7E978A).
```

**Why it matters**

There is no single machine-readable source a Flutter (or any second) client can consume; a palette change, dark mode, or accessibility fix cannot be made in one place; web and mobile will drift from day one. At dozens of centres with two clients, every visual change becomes a manual multi-file audit.

**Recommendation**

Create a canonical tokens file (W3C Design Tokens JSON, e.g. frontend-html/design/tokens.json) covering colour, type, space, radius, elevation, motion; generate theme.css :root and a Dart tokens file from it (Style Dictionary works for both). Then sweep the pages: replace inline colour/size literals with classes or var() (start with the 5 colours that make up 70% of occurrences), move UI.toast()/formModal()/confirmAction() styling to classes in theme.css.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Every factual claim reproduces exactly. Re-running the census over frontend-html gives 486 six-digit hex literals in HTML (top: #7A8F82 x119, #04532F x64, #9A7A1E x52, #B23A48 x51, #9DB3A6 x46), 43 in js/, only 24 var(--...) references outside theme.css, and 785 style="..." attributes across 33 pages. theme.css:8-22 and :37-50 do define two overlapping :root token sets (--emerald / --mq-green both = #04532F, --muted #7A8F82 vs --mq-muted #6B7A70), yet the pages bypass them: admin/students.html:115/149 hardcode color:#7A8F82, :111/141 color:#B23A48, :127-128 #04532F/#9A7A1E; js/ui.js:17-22 is a literal colour map for toasts; login.html:64-65 styles the primary CTA inline (color:#FBF7DA;background:#04532F;border-radius:13px) instead of .mq-btn--primary (which exists, 4 rules in theme.css) and even uses a 13px radius vs the 10px --mq-btn-radius token. All 10 listed off-token colours (#C9DCD1, #1F5C3E, ...) appear only in pages, never in theme.css. No mitigation exists: there is no tokens.json, no build step, no Dart/Flutter client or documentation mentioning one anywhere in the repo, so the "second client" premise is speculative. Because the hardcoded values are (mostly) identical to the token values, there is no visible inconsistency today and no functional, security, or data impact — this is purely a maintainability / theming-cost issue for a static, no-build, single-client, Arabic-only product built by a small team. That makes "high" an over-rating; medium is appropriate. The recommendation to introduce Style Dictionary + W3C tokens JSON is also over-engineered for a no-build vanilla client — sweeping pages to var()/classes and moving UI.toast()/formModal() styling into theme.css is the proportionate fix.

```text
frontend-html/css/theme.css:8-22 and :37-50 (two parallel :root token sets, e.g. --emerald and --mq-green both #04532F; --muted #7A8F82 vs --mq-muted #6B7A70); frontend-html/admin/students.html:111,115,127-128,141,149 (inline #B23A48/#7A8F82/#04532F/#9A7A1E); frontend-html/login.html:64-65 (inline CTA, border-radius:13px vs --mq-btn-radius:10px; .mq-btn--primary exists but unused here); frontend-html/js/ui.js:17-25 (toast colour literal map). Census reproduced: 486 hex in HTML, 43 in JS, 24 var() outside theme.css, 785 style= attributes over 33 pages. No tokens.json, build step, or Flutter/Dart client exists in the repo (find/grep returned nothing), so the multi-client premise is hypothetical.
```

</details>

### Handoff bundle and theme.css contradict each other (and the handoff contradicts itself)

<a id="two-sources-of-truth"></a>

`two-sources-of-truth` · 🟠 high (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `_handoff2/untitled/project/mutqin.css:11,16,62-66`, `_handoff2/untitled/project/هوية-متقن-للمطورين.css:16,25-27`, `frontend-html/css/theme.css:11,31-35,46,76`, `_handoff2/untitled/project/uploads/لوحة الألوان المستخدمة.png`

**Evidence**

```text
git log: _handoff2 was committed once (be47c33 2026-06-25) and never touched; theme.css has 19 commits through 2026-09-11. Buttons: mutqin.css:62-65 `.btn{...border-radius:var(--radius-pill)} .btn-primary{background:var(--emerald);...color:var(--ivory)}` vs theme.css:46 `--mq-btn-radius:10px` and :76 `.mq-btn--primary{background:var(--mq-green);color:#FFFFFF}`; theme.css:31-32 admits the buttons were 'imported from a separate design project «منظومة أزرار مُتقن»' not present in the repo. Background: mutqin.css:11 `--paper:#F7F3E4` vs theme.css:11 `--paper:#EAE6D4` vs the design-system page body `#F2EEDC` (نظام التصميم.dc.html:17) vs manifest.webmanifest:10 `background_color: #F1EEE3` (four 'paper' values). Handoff-internal: هوية-متقن-للمطورين.css:25-26 `--mtqn-radius:20px; --mtqn-radius-pill:44px; --mtqn-ivory-soft:#FBF9E9` vs mutqin.css:16 `--radius:16px; --radius-pill:40px` and `--ivory-soft:#FBF9EE`. The brand palette board PNG states Emerald `#004F2E` and Ivory `#FDFDD0`, while every CSS file uses `#04532F` and `#FBF7DA` (the board also lacks the dark emerald #04361F and gold-deep #9A7A1E that the code relies on).
```

**Why it matters**

A mobile team reading the handoff (which its README tells coding agents to treat as primary) would build pill buttons with ivory text on a #F7F3E4 canvas while the web ships 10px-radius buttons with white text on #EAE6D4 -- visible brand inconsistency between the two clients. The palette-board mismatch means print/marketing collateral may not match the product.

**Recommendation**

Design owner signs off one palette (recommend the CSS values, since they are what ships and pass contrast better) and one button shape; regenerate the handoff or delete _handoff2 from the repo and replace it with a versioned DESIGN.md + tokens.json; add a CI check that theme.css :root equals the generated output.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Every factual claim checks out: _handoff2/untitled/project/mutqin.css:11,16,62-65 (--paper:#F7F3E4, --radius-pill:40px, .btn pill, .btn-primary color:var(--ivory)); هوية-متقن-للمطورين.css:16,25-26 (#FBF9E9, radius 20px/44px); theme.css:11,46,76 (--paper:#EAE6D4, --mq-btn-radius:10px, color:#FFFFFF); theme.css:31-32 comment about the imported button system; نظام التصميم:17 body #F2EEDC; manifest.webmanifest:11 #F1EEE3; palette PNG shows Emerald #004F2E and Ivory #FDFDD0 (code uses #04532F/#FBF7DA); README tells coding agents to treat the design-system .dc.html as primary; git shows _handoff2 committed once (be47c33) and theme.css with 19 commits. So the finding is not refuted. However the severity is overstated: the SHIPPING product is internally consistent — all 33 frontend-html pages load css/theme.css, none load mutqin.css, and the client uses only mq-btn--primary (37 occurrences, no .btn-primary). CLAUDE.md, the actual agent guide, never mentions _handoff2 and names theme.css as the brand source. There is no mobile client, second client, or marketing pipeline in the repo, so the 'mobile team builds pill buttons' and 'collateral mismatch' impacts are hypothetical; the palette PNG deltas (#004F2E vs #04532F, #FDFDD0 vs #FBF7DA) are visually minor. The stale دليل-محتوى-الصفحات.md also points to a non-existent public/css/mutqin.css path, confirming the handoff is dead Blade-era material. This is a documentation/repo-hygiene issue (stale design export left in the repo), not a product defect — low severity, fix by deleting or clearly marking _handoff2 as superseded.

```text
Confirmed: _handoff2/untitled/project/mutqin.css:11 --paper:#F7F3E4, :16 --radius-pill:40px, :62-65 .btn pill + .btn-primary color:var(--ivory); هوية-متقن-للمطورين.css:16 --mtqn-ivory-soft:#FBF9E9, :25-26 radius 20px/44px; frontend-html/css/theme.css:11 --paper:#EAE6D4, :46 --mq-btn-radius:10px, :76 .mq-btn--primary color:#FFFFFF; نظام التصميم - مُتقن.dc.html:17 body{background:#F2EEDC}; frontend-html/manifest.webmanifest:11 background_color #F1EEE3; palette PNG: Emerald #004F2E, Ivory #FDFDD0; _handoff2/untitled/README.md instructs agents to read the design-system file first. Mitigation: grep shows 33/33 frontend-html pages link css/theme.css and 0 link mutqin.css; 37 uses of mq-btn--primary and 0 of .btn-primary in frontend-html; CLAUDE.md has no reference to _handoff2 and names css/theme.css as brand identity; no mobile client exists in the repo; دليل-محتوى-الصفحات.md:11,278 references public/css/mutqin.css (nonexistent path — stale Blade-era doc).
```

</details>

### Logo mark exists in six divergent inline variants and the SVG wordmark depends on a non-embedded font; no PNG app icons

<a id="logo-not-an-asset"></a>

`logo-not-an-asset` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `frontend-html/images/logo.svg:16`, `frontend-html/js/layout.js:64-71`, `frontend-html/index.html:142,204,346`, `frontend-html/login.html:89`, `frontend-html/js/config.js:43`, `frontend-html/manifest.webmanifest:13-19`

**Evidence**

```text
images/logo.svg:16 renders the wordmark as live text `<text ... font-family="Amiri, serif" font-size="58" fill="#D4AF37">متقن</text>` with no @font-face inside the SVG; it has gold text, no ivory disc, and 8 corner dots. layout.js:64-71 LOGO is a different mark (ivory disc r=56, green text, rects at x=46 not 44, no dots, font-size 52). index.html/login.html carry three more variants (font-size 54/56/62, baseline y=116 vs 118, ivory vs green text). The handoff mark (شاشة تسجيل الدخول.dc.html:46-56) has disc + dots + dashed inner circle. config.js:43 sets `apple-touch-icon` to the SVG (iOS ignores SVG touch icons) and manifest.webmanifest lists only `images/logo.svg sizes any` -- no 192/512 PNG, no maskable icon. logo.svg gold text on ivory measures 1.94:1 contrast.
```

**Why it matters**

On a phone home screen or in any context without the Amiri web font (iOS touch icon, Android install banner, Flutter asset, email, print) the wordmark falls back to a system serif and the brand mark is broken; six variants mean no one can say what 'the logo' is. The Flutter app needs clean launcher icons on day one.

**Recommendation**

Produce a master logo set with text converted to outlines: primary (emerald on ivory), reversed (ivory/gold on emerald), mono, and icon-only (star without wordmark), each as SVG + PNG 192/512/1024 + maskable; commit under frontend-html/images/brand/ and document clear-space/min-size rules; replace the five inline SVGs with <img>/<use> references; feed flutter_launcher_icons from the same PNGs.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

Every quoted fact checks out. images/logo.svg:16 is live `<text font-family="Amiri, serif">` with no @font-face and no background disc, gold on transparent (contrast against the manifest background #F1EEE3 is ~1.8:1). layout.js:64-71, index.html:139-143 / 199-205 / 342-347 and login.html:84-90 are five more hand-tuned inline copies that differ in disc presence/colour, rect origin (44 vs 46), stroke width (3.4-5), font-size (52-62) and baseline (116/118); the handoff mark in _handoff2/.../شاشة تسجيل الدخول.dc.html:47-56 adds the eight corner dots that only logo.svg shares. config.js:43 points `apple-touch-icon` at the SVG (iOS does not use SVG touch icons) and manifest.webmanifest lists a single `sizes:any` SVG with no PNG or maskable entry; there is also no favicon link anywhere in the frontend (only backend/public/favicon.ico for the API). No mitigation exists: no PNG brand assets are in the repo at all (the only PNGs are screenshots/), and no Flutter project exists to consume them (that part of the impact is speculative). Where the finding is overstated: inside the web app the wordmark renders correctly because css/theme.css:6 loads Amiri from Google Fonts, and the per-size stroke/font tweaks in the inline variants are plausibly deliberate optical sizing for 13px-340px renderings rather than brand drift; the real breakage is confined to installed-PWA/home-screen icons (Android will render the SVG with a system font, iOS will fall back to a page screenshot) and to reuse outside the browser. That is a polish/brand-consistency gap in an internal, Arabic-only, small-team product with no functional, security or data impact, so 'high' is inflated; medium is appropriate (real, unmitigated, cheap to fix, low user-facing consequence).

```text
frontend-html/images/logo.svg:16 `<text ... font-family="Amiri, serif" ... fill="#D4AF37">متقن</text>` (no @font-face, no disc, 8 dots); frontend-html/js/layout.js:65-71 (disc r=56 #FBF7DA, rects x=46 w=108, text #04532F size 52 y=116, no dots); frontend-html/index.html:139-143 (no disc, stroke 5, size 56 y=118), :199-205 (green disc r=58, ivory text size 62, dashed ring), :342-347 (disc r=56, size 52 y=116); frontend-html/login.html:84-90 (disc + dashed ring, size 54 y=116, no dots); _handoff2/untitled/project/شاشة تسجيل الدخول.dc.html:47-56 (disc + ring + 8 green dots); frontend-html/js/config.js:43 apple-touch-icon → images/logo.svg; frontend-html/manifest.webmanifest:13-19 single SVG icon `sizes:any`, no PNG/maskable; no `rel=icon` favicon anywhere in frontend-html; only PNGs in repo are screenshots/*.png. Mitigation for in-browser rendering: frontend-html/css/theme.css:6 @import Google Fonts Amiri 400/700. No Flutter project exists in the repo.
```

</details>

### Several core tokens fail WCAG AA where they are used for text

<a id="contrast-failures-in-tokens"></a>

`contrast-failures-in-tokens` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `frontend-html/css/theme.css:12,45,183`, `frontend-html/admin/students.html:115`, `frontend-html/login.html:79`

**Evidence**

```text
Computed ratios (node, WCAG 2.x): --faint #9DB3A6 on white 2.23:1 and on --ivory-soft 2.11:1 (used as input placeholder theme.css:156 and copyright/helper text, 46 hardcoded uses); --muted #7A8F82 on white 3.45:1 (119 hardcoded uses at 12-13px, e.g. admin/students.html:115, plus .mq-stat-lbl/.mq-date/bottom-nav labels theme.css:174,239,310); gold #D4AF37 on emerald #04532F 4.37:1 for 13.5px bold table headers (theme.css:183) and .mq-btn--gold text (line 88); --gold-deep #9A7A1E on ivory 3.75:1; disabled #A6A691 on #ECEAE0 2.05:1 (theme.css:45); --c-orange #C57B2C on white 3.36:1.
```

**Why it matters**

Parents and older teachers reading on phones in daylight will struggle with helper text, stat labels and table headers; procurement by public/educational bodies increasingly requires WCAG AA. If the tokens are exported as-is, the Flutter app inherits the failures.

**Recommendation**

Adjust token values before export: muted -> #5F7468 (5.0:1 on white, 4.75 on ivory-soft), keep #7A8F82 only for decorative/placeholder use, gold-deep -> #7F6415 (5.6:1), orange -> #A8651F (4.6:1), table-header text -> ivory #FBF7DA (8.5:1) or gold-light #E9CF6E (5.96:1), disabled fg >= #7A7A66. Add an automated contrast check on tokens.json in CI.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 75% · corrected severity: low

Core evidence verified read-only. Recomputed WCAG 2.x ratios with node against the actual token values in frontend-html/css/theme.css:12,14,45: --faint #9DB3A6 on white 2.23:1 / on --ivory-soft 2.11:1; --muted #7A8F82 on white 3.45:1 (and only 2.76:1 on the --paper body background); gold #D4AF37 on emerald #04532F 4.37:1 (th at theme.css:183 is 13.5px bold = not WCAG "large text", so 4.5:1 applies and it fails narrowly); --gold-deep on ivory 3.75:1; --c-orange on white 3.36:1; disabled fg/bg 2.05:1. Hardcoded use counts are accurate (128 x #7A8F82, 51 x #9DB3A6), and --faint is used for real informational content, not just decoration: guardian names (admin/students.html:379, admin/center.html:124), user emails (admin/teachers.html:127,243, admin/managers.html:68, admin/dashboard.html:44), "بدون معلم" (admin/students.html:392), empty-state rows, and the notification-loading text (js/layout.js:126). --muted at 12-13px is the dominant helper-text pattern (admin/students.html:115 confirmed). No mitigation exists: no alternate high-contrast theme, no prefers-contrast media query, no automated check. However, parts of the finding are overstated or wrong: (1) login.html:79 is a decorative aria-hidden SVG stroke at opacity .07 — not text, exempt, wrong citation; (2) disabled button contrast (theme.css:45, .mq-btn:disabled) is explicitly exempt under WCAG 1.4.3 "inactive user interface component", so it is not a failure; (3) the "Flutter app inherits" / "procurement requires AA" impact is speculative — there is no tokens.json, Flutter project or compliance requirement anywhere in the repo, and the product is a single-country internal tool for a small team. The real, unmitigated defect is: secondary but meaningful text (guardian names, emails, empty states, stat labels, table headers) rendered at 2.1-4.4:1 in small sizes. That is a genuine readability/accessibility issue but with no compliance driver and confined to secondary text, so medium is inflated; low is appropriate, with the concrete fix being the token adjustments proposed.

```text
frontend-html/css/theme.css:12 (--muted #7A8F82, --faint #9DB3A6), :183 (.mq-table th 13.5px bold gold on emerald, 4.37:1), :156/:250 (placeholders use --faint), :174 (.mq-stat-lbl 13px --muted), :239 (.mq-date), :310 (bottom-nav 10.5px #7A8F82), :437 (.mq-stat-sub 11.5px --faint). Real-content uses of #9DB3A6 (2.23:1): admin/students.html:379,392; admin/center.html:124; admin/teachers.html:127,243; admin/managers.html:68; admin/dashboard.html:44; js/layout.js:126. admin/students.html:115 confirmed (12px #7A8F82 helper text, 3.45:1). Incorrect citations: login.html:79 is a decorative aria-hidden SVG (not text); theme.css:45 disabled fg/bg is WCAG-exempt (inactive component). 128 hardcoded #7A8F82 and 51 hardcoded #9DB3A6 occurrences across frontend-html.
```

</details>

### Fonts are a render-blocking Google Fonts @import with generic fallbacks; PDFs do not use the brand fonts

<a id="font-delivery-and-bundling"></a>

`font-delivery-and-bundling` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `frontend-html/css/theme.css:6,17`, `backend/resources/views/pdf/layout.blade.php:6,24`, `_handoff2/untitled/project/موقع متقن - نسخة مستقلة.html`

**Evidence**

```text
theme.css:6 `@import url('https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Cairo:wght@400;500;600;700;800&display=swap');` (CSS @import serialises the fetch after theme.css) and :17 `--font-display:'Amiri',serif; --font-body:'Cairo',sans-serif;` with no Arabic-capable named fallback (on Windows this swaps to Times New Roman/Segoe UI when offline). No fonts directory exists in frontend-html. The PDF layout uses `body { font-family: dejavusans; ...}` and even `.amiri { font-family: dejavusans; }` (layout.blade.php:6,24), so reports carry brand colours but not brand type. Weight 800 is requested but used only 3 times.
```

**Why it matters**

In Libya, where connectivity to Google CDNs is variable, first paint shows a different Arabic typeface and reflows (FOUT), undermining the identity; the mobile app cannot rely on a CDN at all, so the team must decide bundling now. Reports handed to parents/centres look like a different product.

**Recommendation**

Self-host Amiri 400/700 and Cairo 400/500/600/700 as woff2 (drop 800) with <link rel=preload> and `font-display: swap`, plus a named Arabic fallback stack (e.g. 'Cairo','Segoe UI','Noto Naskh Arabic',sans-serif); register the same TTFs in mPDF fontdata so PDFs match; ship both families in the Flutter pubspec assets (both are SIL OFL 1.1 -- include the OFL.txt). Approx. 1-2 MB added to the APK/IPA.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The quoted evidence is accurate. frontend-html/css/theme.css:6 is a CSS `@import` of Google Fonts (Amiri 400/700, Cairo 400-800, display=swap) and :17 defines `--font-display:'Amiri',serif; --font-body:'Cairo',sans-serif;` with only generic fallbacks. There is no fonts directory, no `@font-face`, no `.woff` reference, no `<link rel=preload/preconnect>`, and no service worker anywhere in frontend-html (grep count 0 for each). Every public page (index.html, login.html, forgot-password.html) also hardcodes `font-family:'Amiri',serif` / `'Cairo',sans-serif` inline, so when the CDN is unreachable the browser falls to the OS generic serif/sans-serif. The PDF path is also as claimed: backend/resources/views/pdf/layout.blade.php:6 `body { font-family: dejavusans; }` and :24 `.amiri { font-family: dejavusans; }`; ReportPdfController.php:50-62 constructs Mpdf with only mode/format/size/margins/tempDir and `autoLangToFont = true`, registering no custom fontdata/fontDir — so reports use mPDF's bundled fonts, never Amiri/Cairo. Weight 800 is indeed requested and used only in ~3 places (ui.js:249, manager/attendance.html:37,46). No mitigation exists elsewhere. However, the finding is over-rated: it is a visual-polish/perf concern with no functional, data, or security impact; `display=swap` already prevents invisible text; the @import cost is one extra serial round-trip on first load and is cached thereafter; the "mobile app / Flutter pubspec" impact is speculative (no Flutter code exists in the repo); the cited `_handoff2/.../موقع متقن - نسخة مستقلة.html` is a design handoff export, not shipped product, and it actually inlines @font-face with preconnect, so it does not support the finding. For a small single-country web product this is a low-severity improvement item, not medium.

```text
frontend-html/css/theme.css:6 (@import Google Fonts, display=swap), :17 (generic-only fallbacks); frontend-html/index.html:133,179 and login.html:37,65 inline 'Amiri',serif / 'Cairo',sans-serif; no @font-face/.woff/preload/preconnect/serviceWorker anywhere in frontend-html (grep count 0); backend/resources/views/pdf/layout.blade.php:6,24 dejavusans; backend/app/Http/Controllers/Api/ReportPdfController.php:50-62 Mpdf constructed without fontdata/fontDir, autoLangToFont=true. Weight 800 used at frontend-html/js/ui.js:249, manager/attendance.html:37,46 only. _handoff2 file is a design export (already contains preconnect + inlined @font-face) and is not part of the shipped client.
```

</details>

### No dark theme or colour-scheme handling anywhere

<a id="no-dark-mode"></a>

`no-dark-mode` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/css/theme.css`, `frontend-html/manifest.webmanifest:10-11`, `frontend-html/js/config.js:38`

**Evidence**

```text
grep for prefers-color-scheme / color-scheme / data-theme across css, js and html returns 0 matches; theme-color is a fixed `#04532F`; the sidebar gradient, ivory surfaces and gold accents are all defined once for light only (theme.css:8-22).
```

**Why it matters**

Both mobile OSes default many users to dark mode; a light-only app renders with a jarring white flash and cannot honour system settings. Adding dark later without a semantic token layer (surface/onSurface/outline) means redoing the palette twice.

**Recommendation**

Design owner decides: (a) light-only, declared explicitly (`color-scheme: light`, Flutter `themeMode: ThemeMode.light`), or (b) a dark scheme. Either way, restructure tokens as semantic roles (surface, surfaceContainer, onSurface, outline, primary/onPrimary ...) so a dark map is a second value set, not a rewrite. Recommended dark base: surface #0F1A14, surfaceContainer #16241C, onSurface #E8EFE9, primary #7FBF9A, gold unchanged.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The factual core is confirmed: grep over frontend-html css/js/html/webmanifest for prefers-color-scheme, color-scheme, data-theme or data-bs-theme returns zero matches (the only 'dark' hits are the --emerald-dark token). theme.css:8-22 defines a single light palette on :root; manifest.webmanifest:11 and js/config.js:38 both hardcode theme-color #04532F. So the app is light-only and does not declare color-scheme explicitly. However the finding is over-rated and partly inaccurate: (1) the 'jarring white flash' claim is wrong — manifest background_color is #F1EEE3 (ivory) and body background is var(--paper) #EAE6D4, so an OS-dark user sees the brand ivory shell, not white; (2) a partial semantic token layer already exists (--surface, --paper, --text, --muted, --faint, --line, --hair) so 'redoing the palette twice' is speculative; (3) the Flutter themeMode recommendation is not applicable — there is no Flutter client, only static HTML/Bootstrap; (4) this is an internal Arabic admin tool for Quran centers with a deliberate emerald/gold/ivory brand identity — light-only is a legitimate design decision, not a correctness defect, and nothing malfunctions in dark OS mode (no unstyled system controls found relying on color-scheme). Net: real but cosmetic/roadmap-level; a one-line `color-scheme: light` declaration on :root closes the explicit-declaration gap. Severity low, not medium.

```text
frontend-html/css/theme.css:8-22 single :root light palette, no @media (prefers-color-scheme) anywhere in the file; theme.css:25 body background:var(--paper) (#EAE6D4, not white); frontend-html/manifest.webmanifest:10 background_color #F1EEE3 (ivory, refutes 'white flash'), :11 theme_color #04532F; frontend-html/js/config.js:38 meta theme-color #04532F hardcoded; grep -rniE 'prefers-color-scheme|color-scheme|data-theme|data-bs-theme' frontend-html (css/js/html/webmanifest) => 0 matches. Partial semantic tokens already present: theme.css:12 --surface/--text/--muted/--faint, :18 --line/--hair. No Flutter client exists in the repo (recommendation item not applicable).
```

</details>

### Bootstrap 5 RTL CSS is loaded on all 33 pages but no Bootstrap class is used; docs still call it a Bootstrap client

<a id="bootstrap-dead-weight-doc-drift"></a>

`bootstrap-dead-weight-doc-drift` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/admin/dashboard.html:7`, `frontend-html/login.html:8`, `frontend-html/css/theme.css:4,20-21`, `CLAUDE.md:10`, `frontend-html/README.md:3`

**Evidence**

```text
All 33 pages include `<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">`; grep of every class="..." in html/js finds no Bootstrap utility or component class (the only `row` hits are `pf-name-row`); no bootstrap.bundle.js is loaded anywhere (0 files). theme.css:4 says it 'coexists with Bootstrap 5 RTL via the mq- prefix' and :20-21 overrides `--bs-primary`. CLAUDE.md:10 and frontend-html/README.md:3 describe the client as 'HTML + CSS + JS + Bootstrap 5 RTL'.
```

**Why it matters**

~230 KB of render-blocking third-party CSS per page from a CDN, plus Reboot rules that silently reset typography and form controls under the brand layer; misleading documentation sends new engineers (and the mobile team choosing a component model) in the wrong direction.

**Recommendation**

Remove the Bootstrap link from all pages (verify with a visual diff of the 33 pages), delete the --bs-* overrides, and correct CLAUDE.md/README to 'vanilla HTML/CSS/JS with a custom mq- design system'. If a grid/utility layer is wanted, add a 2-3 KB custom utility set to theme.css.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The core facts check out. All 33 HTML files under frontend-html/ include `bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css` (33/33), no page loads bootstrap.bundle.js or references `bootstrap.` / `data-bs-*` in JS (0 hits). An exact-token scan of every class="..." in html/js finds essentially no Bootstrap classes: the only hit is a single bare `alert` in teacher/dashboard.html:38, and that element carries inline styles that override Bootstrap's padding/radius/margin, so it barely depends on the framework. theme.css:4 and :20-21 (the `--bs-primary` overrides) and CLAUDE.md:10 / README.md:3 read exactly as quoted. So the finding is not factually wrong and there is no mitigation elsewhere (no build step, no tree-shaking, theme.css is the only project stylesheet).

However the finding is over-rated and one part of its framing is inverted. (1) Impact: this is dead weight + doc drift with no correctness, security, or data risk; the ~230 KB is ~30 KB gzipped, served from jsDelivr with long cache headers, on an internal, single-country admin app with a small user base. That is a low-severity hygiene issue, not medium. (2) The claim that Reboot "silently resets typography and form controls under the brand layer" gets the dependency backwards: theme.css (37 KB) contains no reset of its own beyond `* {box-sizing}` and `body {margin:0}` — it defines no heading/paragraph margins, no `button`/`input` font inheritance, no `label` display, no `img` vertical-align, no `hr`/list rules. The brand layer is therefore implicitly relying on Bootstrap's Reboot for baseline normalization. Consequently the recommendation ("remove the link, verify with a visual diff") understates the work: removing Bootstrap will visibly change every page unless a small reset/normalize is added to theme.css first. The auditor's fallback ("add a 2-3 KB custom utility set") should be a reset, not a grid/utility set — no grid classes are used anywhere.

Net: real, low severity; doc drift (CLAUDE.md/README describing it as a Bootstrap client) is the most actionable part.

```text
frontend-html: 33/33 .html files include bootstrap.rtl.min.css (e.g. admin/dashboard.html:7, login.html:8); 0 files load bootstrap.bundle.js; 0 `data-bs-*` attributes; 0 `bootstrap.` JS references. Exact-token class scan: only Bootstrap class present is a bare `alert` at frontend-html/teacher/dashboard.html:38, fully inline-styled. frontend-html/css/theme.css:4 ("تتعايش مع Bootstrap 5 RTL عبر بادئة mq-"), :20-21 (`--bs-primary`, `--bs-primary-rgb`) confirmed; theme.css:23-26 shows the only baseline rules are `* {box-sizing}` and `body {margin:0;...}` — no heading/paragraph/button/input/label/img normalization, i.e. the theme currently depends on Bootstrap Reboot, so removal needs a replacement reset, not a utility set. CLAUDE.md:10 and frontend-html/README.md:3 describe the client as "HTML + CSS + JS + Bootstrap 5 RTL" (confirmed).
```

</details>

### No typographic, spacing, radius, elevation, motion or z-index scales -- values are ad hoc

<a id="no-type-space-radius-scales"></a>

`no-type-space-radius-scales` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/css/theme.css:15-16,46-49`, `frontend-html/index.html`, `frontend-html/manager/students.html`

**Evidence**

```text
Distinct px font-sizes: 24 in theme.css and 31 across pages, including seven half-pixel sizes (10.5, 11.5, 12.5, 13.5, 14.5, 15.5, 16.5px). Border-radius: 16 distinct values (8, 9, 10, 11, 12, 13, 14, 15, 18, 20, 22, 24, 28, 30, 40, 44px) while only 4 radius tokens exist and are referenced 7 times. grep for --space/--gap/--fs/--z/--motion tokens: 0. Transition durations used: .12s .15s .18s .2s .22s .25s .3s .4s .7s; z-index values 30, 50, 98, 99, 100, 1500, 1600, 1700, 2000 scattered across theme.css, ui.js and pages.
```

**Why it matters**

Without scales the Flutter team cannot map to TextTheme/ThemeData reliably and will invent their own; layouts drift between screens; small visual bugs (13.5 vs 14px, 11 vs 12px radius) multiply as more pages are added for dozens of centres.

**Recommendation**

Define and enforce: type scale (11/12/13/14/15/16/18/20/22/24/28/32/40 with Amiri for >=20 display), 4-pt spacing scale (4/8/12/16/24/32/48), radius (8/10/12/16/22/pill), elevation (3 levels), motion (fast 120ms, base 180ms, slow 250ms; ease-out), z-index layers (nav 100, sticky 50, sheet 1500, toast 2000). Encode in tokens.json and lint theme.css for raw values.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The factual evidence checks out. Counting `font-size:*px` in frontend-html/css/theme.css yields exactly 24 distinct values (including 10.5, 11.5, 12.5, 13.5, 14.5, 15.5, 16.5px); pages/js yield 31 distinct values. `border-radius:*px` across the client yields exactly 16 distinct values (8..44px) while theme.css defines only `--radius`, `--radius-sm`, `--radius-pill` (line 15) plus `--mq-btn-radius` (line 46), referenced a total of 9 times via var(). No `--space/--gap/--fs/--z/--motion` tokens exist. Transition durations .12s/.15s/.18s/.2s/.22s/.25s/.3s/.35s/.38s/.6s/.7s and z-index 30/50/98/99/100/1500/1600/1700/2000 are all present. So the finding is real and not mitigated anywhere (there is no build step, linter, or token file). However, the severity is overrated: this is a maintainability/consistency concern in a static Bootstrap client, not a functional or security defect; the "Flutter team" impact is speculative (no Flutter or mobile-port reference exists anywhere in the repo); the app is single-brand, single-country, small team, and the button system section already shows a deliberate tokenised sub-system (mq-* tokens). Half-pixel sizes and 11 vs 12px radii produce no user-visible bug. Correct rating is low.

```text
frontend-html/css/theme.css:15 defines only --radius:16px, --radius-sm:12px, --radius-pill:40px; :46 --mq-btn-radius:10px. var(--radius*) referenced 8 times, var(--mq-btn-radius) once, versus 16 distinct raw border-radius px values (12px x27, 11px x12, 10px x12, 14px x9, 8px x8, ...). theme.css has 24 distinct font-size px values (7 half-pixel), pages/js 31. z-index values 30,50,98,99,100,1500,1600,1700,2000 and transition durations .12s-.7s (11 distinct) scattered. No spacing/type/z/motion tokens. No Flutter/mobile client referenced in repo - impact claim about Flutter mapping is speculative.
```

</details>

### Components specified in the handoff are missing from theme.css; dialogs and alerts are unbranded or inline

<a id="component-gaps-vs-handoff"></a>

`component-gaps-vs-handoff` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `_handoff2/untitled/project/mutqin.css:138-191`, `frontend-html/js/ui.js:9-29,32,382`, `frontend-html/js/layout.js:136`, `frontend-html/teacher/dashboard.html:38`

**Evidence**

```text
mutqin.css defines .alert (138-150), .breadcrumb (152-157), .pagination (173-179), .progress/.progress-bar with high/mid/low thresholds (181-186), .ayah-banner (188-191), .student-avatar.male/.female (166-171), .form-grid (134-136). theme.css has none of these classes (grep returns 0). Instead: toast styling lives in a JS literal (ui.js:9-29); confirmations use the native `window.confirm` (ui.js:32 `confirmDialog`, ui.js:382 `confirmDelete`, layout.js:136 logout); teacher/dashboard.html:38 hand-rolls an alert with inline hex; form grids are handled by an attribute hack `.mq-card [style*="grid-template-columns"]` (theme.css:358).
```

**Why it matters**

Each role page re-implements the same feedback UI slightly differently; native confirm() dialogs break the brand and cannot be localised/styled on mobile web; the mobile team has no reference for alert/progress/pagination/empty-state components and will design them ad hoc.

**Recommendation**

Add mq-alert, mq-progress (with the 85/60 thresholds), mq-breadcrumb, mq-pagination, mq-avatar--male/female, mq-form-grid to theme.css; route UI.toast/confirmDialog/confirmDelete through the existing confirmAction() modal; publish a static component catalogue page (frontend-html/design/index.html) that renders every component and state -- it doubles as the visual spec for Flutter.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted evidence is accurate: _handoff2/untitled/project/mutqin.css defines .alert/.breadcrumb/.pagination/.progress(-bar high/mid/low)/.ayah-banner/.student-avatar.male|female/.form-grid, none of which exist in frontend-html/css/theme.css; UI.toast styles are an inline JS literal (ui.js:9-29); confirmDialog (ui.js:32) and confirmDelete (ui.js:382) wrap window.confirm; layout.js:136 uses native confirm for logout; teacher/dashboard.html:38 hand-rolls an alert with inline hex; theme.css:358 uses the [style*="grid-template-columns"] attribute selector. So the finding is factually correct as stated. However its impact is overstated and several listed gaps are not applicable: (1) theme.css DOES have a branded empty-state (.mq-empty, theme.css:196, used on 13 pages) and a branded avatar (.mq-avatar, theme.css:187, used on ~27 pages) — the impact sentence claiming no empty-state reference is wrong; the male/female avatar variants are moot because students carry no gender field (national_id is Libyan male format, all students male). (2) No page renders a pagination widget or a progress bar at all (lists fetch all pages via last_page batching; grep for progress/pagination markup in frontend-html returns nothing), so nothing is being 're-implemented ad hoc' there. (3) Every app page loads Bootstrap 5.3.3 RTL, so .alert/.breadcrumb/.pagination/.progress do resolve (unbranded, but not broken). (4) 'Each role page re-implements feedback UI differently' is exaggerated: toasts have exactly one implementation (UI.toast), the inline alert appears in exactly one file, confirmDialog has zero callers (dead code), confirmDelete has one caller (teacher/memorization.html:86), and the branded confirmAction() modal already exists and is used in 10 places. Real residual issues: no mq-alert/mq-form-grid classes, toast styling not in theme.css, two native confirm() paths (logout + memorization delete), and general drift between the handoff CSS and theme.css. That is a cosmetic/consistency debt with a small remediation surface, not medium.

```text
theme.css:187 .mq-avatar (branded avatar exists; gender variants N/A — no student gender field); theme.css:196 .mq-empty (branded empty-state exists, 13 usages) — contradicts impact claim; ui.js:32 confirmDialog has 0 callers outside ui.js:450 export (dead code); ui.js:381 confirmDelete has exactly 1 caller (teacher/memorization.html:86); layout.js:136 native confirm for logout; ui.js:389 confirmAction branded modal already exists with 10 usages; teacher/dashboard.html:38 is the only inline .alert in frontend-html; no .progress/.pagination/.breadcrumb markup rendered anywhere in frontend-html (lists batch all pages: admin/students.html:481, admin/teachers.html:237, admin/users.html:88, manager/attendance-review.html:102); Bootstrap 5.3.3 RTL loaded on 33 pages (e.g. admin/dashboard.html:7) so those classes resolve unbranded.
```

</details>

### Iconography is split between a 20-glyph SVG line set and 129 emoji used as icons

<a id="iconography-emoji-vs-svg"></a>

`iconography-emoji-vs-svg` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/js/ui.js:406-427,437-440`, `_handoff2/untitled/project/نظام التصميم - مُتقن.dc.html:237-239`, `frontend-html/admin/centers.html`

**Evidence**

```text
ui.js ICON_PATHS defines 20 stroke icons (home, students, teachers, parents, centers, attendance, memo, tests, logout, bell, menu, eye, edit, trash, plus, search, upload, report, requests, messages) + star(); yet grep finds 129 emoji glyphs in 30 of 35 html/js files used as UI icons (🏫 x14, ⚠ x12, ✓ x9, 📅 x7, 📞 x6, 📖 x6, 📋 x5, 👨 x5, 👥 x5, ✅ x5, 🪪, 🧑, 📝, 📊, ❌, ☰, 💾, 💬, 👤, 👁, 🎂, 🌍 ...). The handoff itself models table actions with emoji (👁 ✏️ 🗑, dc.html:237-239) and 🚪 for logout (:309).
```

**Why it matters**

Emoji render with different artwork and colours on Android, iOS, Windows and Linux, cannot be tinted to the brand, and have inconsistent optical size -- the product looks different on every device, which is exactly what a design system is meant to prevent.

**Recommendation**

Adopt one icon language: extend ICON_PATHS to cover the ~25 emoji semantics (school, warning, calendar, phone, book, id-card, save, globe, birthday ...), ban emoji-as-icon via a lint grep, and export the set as SVG assets + an icon font or Flutter IconData package so web and mobile share glyphs.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The core factual claim holds: `frontend-html/js/ui.js:411-432` defines exactly 20 stroke icons in ICON_PATHS plus `star()` (437-439), and a UTF-8 grep reproduces the auditor's numbers exactly — 129 emoji/dingbat code points across 30 of 40 html/js files, with the same top glyphs (🏫 x14, ⚠ x12, ✓ x9, 📅 x7, ...). The handoff mock `_handoff2/untitled/project/نظام التصميم - مُتقن.dc.html:237-239` really does draw table actions as 👁 ✏️ 🗑 and :309 uses 🚪 for logout. So the mixed icon language is real and not mitigated anywhere (no lint, no icon-only policy).

However the evidence is overstated in several ways, which lowers the effective severity:
1. Dead code: in `admin/dashboard.html:29-31` and `teacher/dashboard.html:27` the `stat(val,lbl,ico,kind)` helper ignores its `ico` argument and always renders `UI.star(22)`, so the 10 stat-card emoji (👨‍🏫 👥 🏫 ✅ ❌ x2) are never displayed.
2. Comments: the 3 ☰ occurrences (`index.html:19`, `js/layout.js:139`, `js/pages/landing.js:31`) are in code comments; the real hamburger is `UI.ic('menu')`.
3. Text dingbats, not colour emoji: ✓ (x9), ✕ (x2), ✦ (x2), ✎ (x1) are monochrome text characters rendered in the page font and inherit `color:` — they do tint and do not suffer the per-platform artwork problem. The "129" count of truly platform-dependent colour emoji rendered as UI icons is closer to ~100.
4. The auditor cites `frontend-html/admin/centers.html`, which contains zero emoji; the intended file is `admin/center.html` (9 occurrences).
5. Important mitigation the auditor missed: the actual implementation already diverges from the handoff for the exact examples cited. All application chrome — sidebar nav (`layout.js:79,177`), logout (`:105`), menu toggle (`:111`), bell (`:118`) — and every table row action (`ui.js:443-447 actionBtn` → SVG eye/edit/trash, used in 56 call sites) use the SVG set. Emoji remain only as decorative prefixes in headings, meta labels, alert text, a few buttons (💾 ➕ ✅ ❌) and the password-eye toggle (`ui.js:45`, `login.html:52`). So the "design system" chrome is consistent; the leak is in page-content decoration.

Impact assessment: the product is Arabic-only, single-country, primarily desktop/XAMPP web with no mobile app in the repo, so the cross-platform emoji-artwork argument is real but low stakes; there is no functional, security, or accessibility failure (emoji are inline with text labels, not sole carriers of meaning). This is a polish/consistency finding, not a medium-risk defect. Keep the finding but downgrade to low and correct the inventory.

```text
Confirmed: frontend-html/js/ui.js:411-432 (20 ICON_PATHS), :437-439 (star), :443-447 (actionBtn renders SVG eye/edit/trash — NOT emoji, contrary to the handoff mock); js/layout.js:79,105,111,118,177 (nav/logout/menu/bell all SVG). Emoji grep = 129 hits in 30/40 files, but: 10 are dead args never rendered (admin/dashboard.html:29-31 and teacher/dashboard.html:27 — stat() ignores `ico` and emits UI.star(22)); 3 ☰ are code comments (index.html:19, js/layout.js:139, js/pages/landing.js:31); ~14 are monochrome text dingbats that tint with CSS (✓ ✕ ✦ ✎). Real colour-emoji-as-icon: ~100, concentrated in manager/reports.html:57-257 (18), teacher/student.html:36-112 (13), admin/center.html:61-103 (9), admin/students.html:276-418 (8), parent/dashboard.html:44-55, manager/attendance.html:82-102, teacher/attendance.html:54-65 (💾 ✅ ❌ in buttons), ui.js:45 + login.html:52 (👁/🙈 password toggle). Citation fix: admin/centers.html has 0 emoji; auditor meant admin/center.html. Handoff _handoff2/untitled/project/نظام التصميم - مُتقن.dc.html:237-239 (👁 ✏️ 🗑) and :309 (🚪) confirmed, but the shipped code already replaced those with SVG.
```

</details>

### theme.css carries two overlapping token namespaces with conflicting 'muted'/'ink'/'shadow' values

<a id="duplicate-token-namespaces"></a>

`duplicate-token-namespaces` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/css/theme.css:8-22,37-50`

**Evidence**

```text
Block 1 (lines 8-22): `--emerald:#04532F ... --muted:#7A8F82 ... --shadow:0 8px 24px -18px rgba(4,54,31,.4)`. Block 2 (37-50): `--mq-green:#04532F` (same as --emerald), `--mq-gold:#D4AF37` (same as --gold), `--mq-red:#B23A48` (same as --c-danger), but `--mq-muted:#6B7A70` (differs from --muted), `--mq-ink:#1E2B23` (a third text colour beside --emerald-dark and --text), `--mq-shadow-sm/md` (different from --shadow). --shadow-lg from the handoff (mutqin.css:18) is absent.
```

**Why it matters**

Two names for one colour and two colours for one concept make the export to Flutter ambiguous and invite further divergence.

**Recommendation**

Merge into one namespace with semantic aliases (color.primary = emerald; color.text.muted = one value), keep the mq- interaction states as derived tokens, and delete the duplicates.

### No in-repo design documentation, usage rules or component catalogue; CLAUDE.md is a one-liner and the handoff README is agent-oriented

<a id="no-design-documentation"></a>

`no-design-documentation` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `CLAUDE.md:212`, `frontend-html/README.md:35`, `_handoff2/untitled/README.md`

**Evidence**

```text
CLAUDE.md:212 is the only design mention: '- **css/theme.css**: brand identity (emerald/gold/ivory, Amiri + Cairo fonts).' README.md:35 says 'css/theme.css الهوية (أخضر/ذهبي/عاجي، Amiri+Cairo)'. The handoff README instructs coding agents to 'recreate them pixel-perfectly' and 'Don't render these files'. No logo usage rules (clear space, minimum size, backgrounds), no do/don't list, no colour-role table, no accessibility guidance exist. The brand rationale (name meaning, eight-point star from the Libyan mushaf, colour semantics) exists only inside a PNG (دلالة الالوان وفكرة الاسم وشكل الشعار.png).
```

**Why it matters**

Knowledge lives in one designer's head and a PNG; onboarding a Flutter team or an agency means re-deriving everything; brand meaning (the star marks thumn/hizb/juz boundaries in the Libyan mushaf) will be lost.

**Recommendation**

Write frontend-html/DESIGN.md (Arabic + English): brand story, palette with roles and contrast table, type scale, spacing, components, logo rules, RTL rules, motion, accessibility; link it from CLAUDE.md; keep tokens.json next to it.

### Icon-only controls lack accessible names; ARIA is nearly absent

<a id="a11y-semantics-in-components"></a>

`a11y-semantics-in-components` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `frontend-html/js/ui.js:437-447`, `frontend-html/js/layout.js:105-112`, `frontend-html/css/theme.css:63,157`

**Evidence**

```text
grep aria-label in js/ui.js and js/layout.js: 0 matches; across the whole frontend only 8 aria-*/role attributes. UI.actionBtn() (ui.js:443-447) renders `<button class="mq-btn mq-btn--quiet mq-btn--icon mq-btn--sm">${ic('eye')}</button>` with no label; the sidebar toggle, bell and logout are icon buttons without names; UI.ic() SVGs have no aria-hidden. Focus ring exists only for .mq-btn (theme.css:63) and inputs (:157).
```

**Why it matters**

Screen-reader users (and automated accessibility audits demanded by institutional buyers) get 'button' with no purpose; the Flutter port will replicate the omission unless the spec says otherwise.

**Recommendation**

Add an `aria-label`/`title` parameter to actionBtn/ic and label the four shell buttons; mark decorative SVGs aria-hidden; extend :focus-visible to links, nav items, table action buttons. In Flutter, require Semantics(label:) on every IconButton in the shared widget set.

### Layout uses physical (right/left) properties; RTL is assumed rather than expressed logically

<a id="rtl-physical-properties"></a>

`rtl-physical-properties` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `frontend-html/css/theme.css:205,225,229-231,319`

**Evidence**

```text
theme.css uses `right:0` / `margin-right:260px` / `translateX(100%)` for the sidebar (205, 225, 230, 319) and `border-right:4px` for alerts (handoff mutqin.css:140); only one logical property (`padding-inline`, line 105) appears. All 33 pages hardcode dir="rtl" on <html>.
```

**Why it matters**

Fine for an Arabic-only product today, but any English/French UI for foreign guardians (the data model already carries nationality_type foreigner) or an LTR admin view would mirror incorrectly. Flutter uses Directionality/EdgeInsetsDirectional natively, so the spec should state the rule.

**Recommendation**

Document 'RTL-first, logical properties' as a rule; migrate theme.css to inset-inline-start / margin-inline-start / border-inline-start; in Flutter use only *Directional variants and AlignmentDirectional.

## Measured facts

| Metric | Value |
|---|---|
| theme.css size / lines / commits | 37,645 B / 560 lines / 19 commits (2026-06-25 to 2026-09-11) |
| Handoff bundle | 14 files, ~870 KB, 1 commit (2026-06-25), never updated; 5 PNG brand boards |
| CSS custom properties defined | 45 in theme.css (24 base + 2 --bs + 19 --mq interaction) ; handoff mutqin.css 27; developer CSS 14 |
| var() references | 147 inside theme.css; 24 in all HTML/JS pages |
| Hardcoded hex colours | 486 occurrences / 24 distinct in HTML; 43 / 10 in JS; ~10 colours used only in pages and absent from tokens |
| Inline style attributes | 785 in 33 HTML pages (max 78 manager/students.html, 76 index.html); 57 in JS |
| Pages with inline <style> blocks | 6 of 33 |
| Bootstrap | 5.3.3 RTL CSS linked on 33/33 pages; 0 Bootstrap classes used; 0 pages load Bootstrap JS |
| Distinct font-size values | 24 in theme.css, 31 in pages; 7 half-pixel sizes; 0 type-scale tokens |
| Distinct border-radius values | 16 (8-44px); 4 radius tokens referenced 7 times |
| Spacing / motion / z-index tokens | 0 / 0 / 0 (9 distinct z-index values 30-2000; 9 distinct transition durations) |
| Responsive coverage | 11 @media blocks; breakpoints <768, 768-1024, >1024; prefers-reduced-motion yes; prefers-color-scheme 0 |
| Button system | 8 variants x 3 sizes (40/46/54px), 6 states; 83 mq-btn usages vs 16 raw inline-styled <button> elements |
| Icon set vs emoji | 20 SVG line icons + star helper; 129 emoji glyphs used as icons in 30 files |
| Logo variants | 6 (logo.svg + 5 inline SVG blocks; font-size 52/54/56/58/62, ivory-disc/green-text vs gold-text-no-disc); PNG app icons: 0 |
| Contrast (WCAG) | faint #9DB3A6/white 2.23; muted #7A8F82/white 3.45; gold/emerald 4.37; gold-deep/ivory 3.75; disabled 2.05; orange/white 3.36; text #3D6B52/white 6.13; white/emerald 9.20 |
| Canvas ('paper') colours in circulation | 4: #EAE6D4 theme.css, #F7F3E4 mutqin.css, #F2EEDC dc body, #F1EEE3 manifest |
| Primary/ivory hex disagreement | CSS #04532F/#FBF7DA vs palette PNG #004F2E/#FDFDD0 |
| Fonts | Google Fonts @import (Amiri 400/700; Cairo 400/500/600/700/800); weight 800 used 3x; self-hosted files 0; PDF uses dejavusans |
| Accessibility hooks | aria-label in ui.js/layout.js: 0; aria/role attrs frontend-wide: 8; :focus-visible rules: 1 |
| RTL | 33/33 pages dir=rtl; theme.css physical left/right props 11 vs logical 1 |

## Auditor notes

DOC DRIFT: CLAUDE.md:10 and frontend-html/README.md:3 describe a 'Bootstrap 5 RTL' client, but no Bootstrap class or JS is used; CLAUDE.md does not mention _handoff2, the PWA manifest, the mq- button system, or the design decisions embedded in theme.css:31-35. The handoff README tells agents the design-system dc.html is the primary spec, which is no longer true.

ADDITIONAL OBSERVATIONS NOT PROMOTED TO FINDINGS: (a) The handoff login is a single centred card on a radial emerald gradient (شاشة تسجيل الدخول.dc.html:24-38) while login.html ships a two-column split card; the pasted screenshot PNG (localhost:9091/login) is the retired Blade frontend -- all three login designs differ; treat as a deliberate evolution but record it. (b) The handoff uses Arabic-Indic digits in mockups (١٢, ٣١٥) while CLAUDE.md mandates Western digits in PDFs and pages mix both -- a digit policy decision is needed. (c) Sidebar nav-link colour #E4EFE8 (handoff) vs #CFE0D6 (theme), logout #E8B4B4 vs #E7B6B6, radius 11 vs 12, topbar white vs blurred ivory-soft, stat icon 46 vs 48px, table padding 22 vs 20px -- minor drift, ~15% of component values differ; token-level drift is 1 of 26 (--paper) plus 1 missing (--shadow-lg); button component drift is 100% (shape, text colour, secondary meaning). (d) The mobile bottom-sheet rule targets modals by `div[style*=\"position:fixed\"][style*=\"inset:0\"]` (theme.css:336-348) -- fragile selector coupling to inline styles; it will break the moment modals get a class. (e) `.mq-card [style*=\"grid-template-columns\"]{grid-template-columns:1fr !important}` (theme.css:358) and index.html:63 use the same attribute-hack pattern. (f) manifest orientation is locked to portrait; tablets used by centre managers may want landscape.

DECISIONS THE DESIGN OWNER MUST MAKE (blocking the mobile theme): 1. Canonical primary/ivory hex: CSS #04532F/#FBF7DA (recommended; ships today, better contrast) or palette board #004F2E/#FDFDD0. 2. One scaffold/paper colour (recommend #EAE6D4 from theme.css or lighten to #F1EEE3 to match the manifest; pick one and update all four places). 3. Dark mode: explicit light-only, or fund a dark value set. 4. Button shape: 10px radius (theme.css) vs pill (handoff) -- recommend 10px, already in production. 5. Fonts: bundle Amiri 400/700 + Cairo 400/500/600/700 (SIL OFL 1.1 -- permitted, include OFL.txt); drop Cairo 800. 6. Logo: commission outlined master + variants + app icons; confirm whether the eight corner dots and dashed inner circle are part of the mark. 7. Icon language: SVG line set only (no emoji); choose whether to adopt Material Symbols in Flutter or port the custom 20-glyph set. 8. Digit policy (Western vs Arabic-Indic) per surface. 9. Contrast fixes to muted/faint/gold-deep/orange/disabled tokens (values proposed in finding contrast-failures-in-tokens).

FLUTTER THEMEDATA TOKEN MAP (derived from theme.css as canonical, handoff for gaps; Dart-like):
// --- colour.brand ---
emerald: Color(0xFF04532F)            // primary; buttons, table head, sidebar top
emeraldDark: Color(0xFF04361F)        // headings, onSurface strong, sidebar bottom
jade: Color(0xFF006850)               // tertiary accent ('present today')
gold: Color(0xFFD4AF37)               // secondary; active nav bg, highlights, borders
goldDeep: Color(0xFF9A7A1E)           // gold text on light (fix -> 0xFF7F6415)
ivory: Color(0xFFFBF7DA)              // onPrimary text, brand disc
ivorySoft: Color(0xFFFBF9EE)          // input fill, card header, surfaceContainerLow
paper: Color(0xFFEAE6D4)              // scaffoldBackgroundColor (DECISION #2)
surface: Color(0xFFFFFFFF)
// --- colour.text ---
textPrimary: Color(0xFF04361F)
textBody: Color(0xFF3D6B52)           // table cells, paragraphs
textMuted: Color(0xFF7A8F82)          // fix -> 0xFF5F7468 for AA
textFaint: Color(0xFF9DB3A6)          // placeholders only (decorative)
ink: Color(0xFF1E2B23)                // --mq-ink (merge with textPrimary)
// --- colour.status ---
success: Color(0xFF04532F); successTint: Color(0x1F04532F) /*12%*/; successBorder: Color(0x4004532F)
danger: Color(0xFFB23A48); dangerTint: Color(0x1FB23A48); dangerBorder: Color(0x40B23A48)
warning: Color(0xFF9A7A1E); warningTint: Color(0x2ED4AF37) /*18% gold*/; warningBorder: Color(0x66D4AF37)
info: Color(0xFF2A6F8E); infoTint: Color(0x1F2A6F8E)
orange: Color(0xFFC57B2C) /*late; fix -> 0xFFA8651F*/; orangeTint: Color(0x21C57B2C)
neutral: Color(0xFF6B7C72); neutralTint: Color(0x246B7C72)
// --- colour.interaction (buttons) ---
greenHover: Color(0xFF063E24); greenActive: Color(0xFF032B19); greenTint: Color(0xFFE7F0EA); greenTint2: Color(0xFFD8E7DD)
goldHover: Color(0xFFC19D2C); goldActive: Color(0xFFA98823); goldTint: Color(0xFFF8EFD3)
redHover: Color(0xFF96303C); redActive: Color(0xFF7C2732); redTint: Color(0xFFF6E7E9); redTint2: Color(0xFFEED4D8)
disabledBg: Color(0xFFECEAE0); disabledFg: Color(0xFFA6A691) /*fix -> 0xFF7A7A66*/
// --- colour.lines & overlays ---
line: Color(0x4DD4AF37)               // rgba(212,175,55,.30) card header / dividers
hair: Color(0x1404361F)               // rgba(4,54,31,.08) card border
inputBorder: Color(0x3804532F)        // rgba(4,83,47,.22)
focusHalo: Color(0x1A04532F)          // rgba(4,83,47,.10) 4px
scrim: Color(0x7304361F)              // rgba(4,54,31,.45) modal overlay
sidebarGradient: [emerald, emeraldDark] (top->bottom); navFg: Color(0xFFCFE0D6); navHoverBg: Color(0x14FBF7DA); navActiveBg: gold; navActiveFg: emeraldDark; logoutFg: Color(0xFFE7B6B6)
// --- typography (fontFamily: 'Cairo' body, 'Amiri' display; all weights as in CSS) ---
displayLarge: Amiri 700 38/1.22       // login h1, hero
displayMedium: Amiri 700 30/1.25      // section h2, stat value, center name
displaySmall: Amiri 700 28/1.2        // page title (24 tablet, 20 mobile)
headlineMedium: Amiri 700 24/1.25     // report section title
headlineSmall: Amiri 700 22/1.3       // card title 21-22, empty-state h3
titleLarge: Cairo 700 19/1.4          // pf-name, avatar initials use Amiri 700 17-19
titleMedium: Cairo 700 16/1.5
titleSmall: Cairo 700 14/1.5          // form labels, table th (13.5)
bodyLarge: Cairo 500 15/1.6           // inputs, paragraphs
bodyMedium: Cairo 500 14/1.6          // table cells, nav (14.5/600)
bodySmall: Cairo 600 13/1.5           // stat labels, badges, helper (12.5)
labelLarge: Cairo 700 15.5/1          // button md (14 sm, 17 lg)
labelMedium: Cairo 700 13/1           // badge, chip (12.5)
labelSmall: Cairo 700 10.5/1          // bottom-nav label, brand sub
quran: Amiri 400 34-42/1.7            // ayah banner
letterSpacing: 0 (Arabic); code/display-code: +0.4, LTR
// --- shape ---
radiusXs: 8 (code chip); radiusSm: 10 (button, meta tile); radiusMd: 12 (input, avatar, nav item); radiusLg: 16 (card, stat); radiusXl: 22 (bottom sheet top); radiusModal: 28 (login card); radiusPill: 40 (badge 30, chip)
// --- spacing (proposed 4-pt scale; observed 4/5/6/8/10/11/12/14/16/18/20/22/24/26/28/30/32) ---
space1: 4; space2: 8; space3: 12; space4: 16; space5: 20; space6: 24; space8: 32; space12: 48; pagePadding: 32 desktop / 20 tablet / 14 mobile; cardPadding: 24 / 16 mobile; cardHeaderPadding: 18x24 / 14x16
// --- sizing ---
buttonHeight: sm 40, md 46, lg 54; iconButton: same square; inputMinHeight: 46 (font 16 on mobile); touchTargetMin: 44; bottomNavItemMin: 48; tableActionBtn: 32 desktop / 40 touch; avatar: 38-42 (lg 54-68); statIcon: 46-48 (40 mobile); sidebarWidth: 260 (225 tablet, drawer min(290, 84vw) mobile); topbarHeight ~ 72
// --- elevation ---
shadowCard: BoxShadow(color: Color(0x6604361F), offset: (0,8), blur: 24, spread: -18)
shadowLg: BoxShadow(color: Color(0x8004361F), offset: (0,26), blur: 60, spread: -34)
shadowBtnSm: BoxShadow(color: Color(0x2904532F), offset: (0,1), blur: 2)
shadowBtnMd: BoxShadow(color: Color(0x3804532F), offset: (0,4), blur: 12)
shadowModal: BoxShadow(color: Color(0x73000000), offset: (0,40), blur: 90, spread: -30)
shadowDrawer: BoxShadow(color: Color(0x8C04361F), offset: (-24,0), blur: 60, spread: -20)
focusRing: outer 3px white + 3px gold (box-shadow 0 0 0 3px #FFF, 0 0 0 6px gold)
// --- motion ---
durationPress: 120ms; durationFast: 180ms (hover, nav); durationBase: 220-250ms (sheet-up, drawer; Curves.easeOut); durationToastFade: 300ms; durationProgress: 400ms; spinner: 700ms linear; hoverLift: translateY(-1) on primary/gold/danger; respect MediaQuery.disableAnimations
// --- z / layers ---
sticky: 50; sidebar: 100; overlay: 99; bottomNav: 98; sheet/modal: 1500-1700; toast: 2000
// --- component recipes ---
badge: pill, 5x13 padding, 13/700, 1px border @25% + 6px dot in currentColor
statCard: surface, hair border, 3px top border in kind colour, 16 radius, icon tile 48 @13 radius with 10-18% tint
tableHeader: emerald bg, gold text 13.5/700 (recommend ivory for AA); rowDivider rgba(4,54,31,.07); rowHover rgba(4,83,47,.03)
progress: 10px track rgba(4,54,31,.08), pill; fill emerald >=85%, orange 60-84%, danger <60%
alert: 14x18 padding, 13 radius, 4px inline-start border in kind colour, 10-16% tint, 24px round mark
displayCode: LTR, Cairo 700 12.5, emerald on gold 14% tint, 1px gold 40% border, 8 radius
emptyState: dashed rgba(4,83,47,.3) border, 48x24 padding, centred, star icon @45% opacity
// --- brand assets to produce ---
logo: eight-point star (two squares rotated 45deg, stroke gold 4/200 viewBox) + dashed inner circle r50 + 8 corner dots r3.6 + wordmark 'متقن' Amiri 700 (outline it); variants primary (ivory disc r56, emerald text), reversed (emerald disc, ivory text, gold star), mono; icon-only star for launcher; clear space = 1 dot-radius x 4; min size 24px
