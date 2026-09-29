# Engineering Process Maturity (CMMI lens)

[← Enterprise Audit](../enterprise-audit.md)

**Score 54 / 100** — Significant risk · maturity **L2** · weight 3%

The repository shows unusually strong *personal* engineering discipline for a solo project: 223/224 commits carry a rationale body, median commit touches 2 files, features are decomposed into explicit stages, and 73% of controller changes are covered by a feature-test commit within the same stage series (38 feature-test files, 178 test methods, 958 assertions, 0 php -l failures across 148 files). Architecture decisions are captured in a living CLAUDE.md and a DEPLOYMENT.md checklist, and no .env has ever entered history. However, against an enterprise level-5 bar the *institutional* layer is absent: 0 pull requests, 0 code reviews, 0 CI workflows, 0 branch protection rules, 0 releases, 1 ad-hoc tag, no CHANGELOG, no semver, no issue tracker usage (0 issues), single developer under 3 git identities, direct pushes to master. Production deployment is manual cPanel file pasting with migrations hand-converted to raw SQL; the deploy log's own rule ("every commit = an entry") is broken (68 commits since the last recorded 'done' deploy, 7 pending vs 1 done) and it contradicts the newer .cpanel.yml git-deploy. Repo hygiene is weak (15 MiB tracked incl. a 3.5 MB composer.phar, 4.7 MiB orphaned screenshots, a 1 MiB photo folder that is a byte-identical duplicate of the gallery, a 3.2 MiB design handoff bundle, stray dev scripts) and documentation has drifted materially (CLAUDE.md says 20 test files vs 38; whole subsystems undocumented; two root files still describe a Blade app that no longer exists). CMMI level 2 (repeatable): practices are consistently repeated by one person but nothing is defined, enforced, or measured at the organisation level. Score 54: functional discipline that a CTO would recognise, but the missing gates (review, CI, release, reproducible deploy) are a significant risk before adding a mobile team and dozens of centers.

## What is already strong

- Commit message quality is genuinely enterprise-grade: 223/224 commits have a descriptive body with rationale, scope and often live-verification notes (e.g. e14452e 'Fix silent backend flaws' lists 5 defects and 'Verified live: ...'; 2b5f96e explains the test DB choice and a latent Auth::attempt flaw the tests exposed). Median 2 files/commit, p90 5, only 4 commits touch >15 files (one is the 192-file initial snapshot).
- Staged decomposition of features is systematic: 96/224 subjects carry 'stage N / Part N / Phase N / Slice' markers; a typical series is schema -> service -> endpoint -> UI -> feature tests (e.g. Display code stages 1-6 on 2026-07-16, Manager attendance review stages 2-6 on 2026-07-22).
- Test culture: 38 feature-test files + SurahReferenceJuzGapTest unit, 178 test methods, 958 assertions, 4641 test LOC vs 7072 app LOC (0.66:1). 19/85 controller commits ship tests in the same commit and a further 43 get a dedicated test( commit within 4 commits (62/85 = 73% coverage-by-stage). Tests log in through the real /auth/login so Sanctum abilities are production ones (2b5f96e body).
- Security-conscious commit history: S1/S3/S4/D1 P0 fixes landed early (b6ef0f5, 8de311d, ffe4e14, 1c52ee8); CORS narrowed to env-driven allowlist (backend/config/cors.php:14-16); ProductionSeeder refuses passwords <8 chars and reads ADMIN_INITIAL_PASSWORD from env (backend/database/seeders/ProductionSeeder.php:24-25); no .env ever tracked in any revision (git log --all --name-only | grep '.env$' -> empty).
- Living architecture documentation: CLAUDE.md (29.6 KB, 6 revisions) documents the dual role+token-ability model, business rules, gotchas (Saturday week, Tripoli TZ, Western digits in mPDF) and a full route table; DEPLOYMENT.md provides a 9-item pre-production checklist; backend/DEPLOY_LOG.md records per-deploy file lists, upload method and status.
- Syntax health: php -l passes on all 148 first-party PHP files (app/, routes/, tests/, database/, config/) with 0 failures; .gitattributes enforces 'text=auto eol=lf'; .editorconfig present; vendor/, storage/ and bootstrap/cache correctly ignored.
- Runtime environment switching removed a hard-coded API host: frontend-html/js/config.js:10-12 picks http://localhost:9090/api on localhost and a relative /backend/public/api in production, so no per-deploy edit is needed.

## Level-5 target state

Trunk-based development on a protected `master` where every change arrives via a pull request that references an issue, passes a required CI job (MySQL-backed `artisan test`, `pint --test`, larastan, `php -l`, later Playwright smoke), and updates CLAUDE.md/CHANGELOG in the same diff. Releases are semver tags cut by a release PR whose CHANGELOG is generated from enforced conventional commits; one deploy pipeline (git-based cPanel or SSH) ships exactly a tag, runs migrations itself, and stamps the deployed version into `/api/public/version`. The repo is private, contains no binaries beyond product assets, has a human README/CONTRIBUTING/SECURITY, an ADR directory for load-bearing decisions, and DORA metrics plus per-release retros drive continuous tuning (CMMI 4-5).

## What the Flutter team must know

The mobile team inherits a backend with excellent commit narratives and a real feature-test suite, but no enforced gate: they must not assume `master` is green or deployed. Today production lags master by 68 commits, so endpoints the app would naturally use (login by display code T1/CA1/P1 from a1cb65d, GET /admin/users, GET /manager/parents, /manager/teachers/{id}/performance, /students/{id}/details|day, PUT /manager/students/{id}/teacher) are on master but not recorded as deployed — pin the app to a tagged release and demand a `/api/public/version` endpoint before integration testing. There is no OpenAPI/Postman contract and no `/v1` prefix, so the app team must read routes/api.php plus controllers directly and agree an API-versioning/deprecation policy before the first store build (installed apps cannot be hot-fixed like the static web client). The consistent `{success,message,data,errors}` envelope and Arabic 422 messages are stable and can be relied on. Expect to coordinate via PRs with CI once branch protection exists; until then any backend change can break the client silently.

## Findings — 15 live

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [Zero CI, zero pull requests, zero code review — every commit is a direct push to master](#no-ci-no-review-gate) | 🔴 critical<br>_reviewers → high_ | ✅ confirmed | NOW | S |
| [Production deploy is manual cPanel file-pasting with hand-written SQL migrations; prod is 68 commits behind master](#manual-sql-deploy-prod-lag) | 🔴 critical<br>_reviewers → high_ | ✅ confirmed | NOW | M |
| [No releases, no CHANGELOG, no semantic versioning, one ad-hoc tag](#no-release-process) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Single developer under three git identities; issue tracker unused; two orphaned unmerged branches](#single-dev-three-identities-no-tracker) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [CLAUDE.md and DEPLOYMENT.md have drifted from the code (undocumented subsystems, wrong counts, obsolete instructions)](#docs-drift-claude-md) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [GitHub repository is public and commits hosting internals (cPanel user, DB name, server paths) and demo credentials](#public-repo-exposes-hosting-details) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Conventional-commit prefixes adopted only since 2026-08-22 and inconsistently (29%); mixed English/Arabic; 31% of subjects exceed 72 chars](#conventional-commits-partial) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [15 MiB of tracked files: composer.phar, orphaned screenshots, duplicated photos, a design handoff bundle and stray dev scripts](#repo-hygiene-binaries) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [.cpanel.yml rsyncs tests, dev scripts, composer.phar and DEPLOY_LOG into the production webroot](#cpanel-deploy-ships-dev-artifacts) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Zero automated tests for 5,248 lines of frontend JS; no static analysis or formatter enforced for PHP](#no-frontend-tests-no-static-analysis) | 🟡 medium | ✅ confirmed | NEXT | M |
| [Root-level launcher and Arabic page guide still describe the retired Blade application](#stale-root-artifacts-blade-era) | ⚪ low | ℹ️ informational | NEXT | S |
| [No root README; backend/README.md is the untouched Laravel boilerplate](#no-root-readme-onboarding) | ⚪ low | ℹ️ informational | NEXT | S |
| [Retired nested backend/.git is said to be 'backed up' but the backup location is undocumented](#nested-git-backup-untraceable) | ⚪ low | ℹ️ informational | LATER | S |
| [Architecture decisions live only in commit bodies and CLAUDE.md prose — no ADRs, no decision index](#decisions-only-in-commit-bodies) | ⚪ low | ℹ️ informational | LATER | M |
| [No measurement of engineering flow (DORA), no retrospectives, no SLOs — nothing to move from level 2 to 4](#no-metrics-no-retros) | ⚪ low | ℹ️ informational | LATER | M |

### Zero CI, zero pull requests, zero code review — every commit is a direct push to master

<a id="no-ci-no-review-gate"></a>

`no-ci-no-review-gate` · 🔴 critical (reviewers → high) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `.github/ (absent)`, `backend/phpunit.xml:21-27`, `backend/composer.json:69-77`

**Evidence**

```text
`ls -la .github` -> 'no .github dir'; `gh api repos/muad03/MUTQIN/actions/workflows --jq .total_count` -> 0; `gh api 'repos/muad03/MUTQIN/pulls?state=all'` -> 0; `git log --merges | wc -l` -> 0; `gh api .../branches/master/protection` -> HTTP 404; `gh api .../rulesets` -> 0. 224 commits, 3 collaborators on the GitHub repo, none reviewed. laravel/pint is in require-dev (composer.json:72) but there is no pint.json and no commit ever mentions running it. The only test-run evidence is self-reported in commit bodies ('35 tests green', '119 اختباراً خضراء').
```

**Why it matters**

Nothing prevents a broken or insecure change from landing on the branch the mobile team will build against. The dual role+ability security model is guarded only by tests that nobody is forced to run. Adding a Flutter team without a review gate multiplies the risk of silent API-contract breaks.

**Recommendation**

Add .github/workflows/ci.yml running on push + pull_request: services: mysql:8 (create mutqin_test), `php composer.phar install`, `php artisan migrate --env=testing`, `php artisan test`, `vendor/bin/pint --test`, and `find backend -name '*.php' -not -path '*/vendor/*' | xargs -n1 php -l`. Enable branch protection on master: require PR, require the CI check, dismiss stale approvals, no force-push. Even a solo developer should merge via PR so the CI result is recorded.

<details><summary>Verification — 2 independent reviewers</summary>

**Upheld** · confidence 92% · corrected severity: high

Every factual claim checks out from the repo and the GitHub API: no .github/ directory anywhere (root or backend/), `gh api .../actions/workflows` total_count=0, `gh api .../pulls?state=all` returns 0, branch protection returns HTTP 404, `git log --merges` is 0 across 224 commits with three distinct authors (MUTQEN 55, muad03 82, muad3719-crypto 87), no pint.json, no git hooks installed (.git/hooks has only samples), no composer script that runs tests or pint, and the only commit mentioning a 'workflow' is an n8n digest, not CI. No mitigation exists elsewhere: the 38 Feature test files run only when a developer chooses to run them locally against a MySQL `mutqin_test` DB; nothing enforces it. One aggravating fact the auditor missed: `.cpanel.yml` at the repo root is a cPanel Git-deploy hook that rsyncs `frontend-html/` and `backend/` (with `--delete`) straight into `/home/[redacted-cpanel-user]/public_html` on every push — so an unreviewed, untested push to master is also an immediate production deploy. Minor evidence nit: laravel/pint is at composer.json:18 (require-dev), not :72; lines 69-77 are the config/platform block. This does not change the finding. Severity: 'critical' is defensible given auto-deploy-on-push to production with no gate, though for a small single-country team the more conventional rating is high; I keep it at high because the dual-gate security model is at least covered by an existing test suite (the tests exist, they are just not enforced), and the fix is cheap and purely process-level rather than an exploitable defect in code.

```text
.github/ absent at repo root and in backend/; `gh api repos/muad03/MUTQIN/actions/workflows --jq .total_count` -> 0; `gh api 'repos/muad03/MUTQIN/pulls?state=all'` -> 0 PRs; `gh api repos/muad03/MUTQIN/branches/master/protection` -> HTTP 404; `git log --merges | wc -l` -> 0 of 224 commits; authors: MUTQEN 55 / muad03 82 / muad3719-crypto 87; backend/composer.json:18 `"laravel/pint": "^1.13"` (require-dev) with no pint.json and no composer script invoking pint or `artisan test` (composer.json:37-50 scripts block only has Laravel defaults); .git/hooks contains only *.sample files; backend/phpunit.xml:25-26 `DB_CONNECTION=mysql`, `DB_DATABASE=mutqin_test` (tests require a local MySQL DB, run manually only); AGGRAVATING: .cpanel.yml:1-7 auto-deploys every push of master via `rsync -a --delete` of frontend-html/ and backend/ into /home/[redacted-cpanel-user]/public_html — unreviewed pushes go straight to production.
```

---

**Upheld** · confidence 80% · corrected severity: medium

Facts confirmed read-only: no .github/ dir at repo root or under backend/, no pint.json, 224 commits with 0 merge commits, single branch master plus two stray remote `claude/*` branches, no git hooks (.githooks/.husky/core.hooksPath absent), 3 distinct authors (MUTQEN 55, muad03 82, muad3719-crypto 87). phpunit.xml pins tests to a MySQL `mutqin_test` DB (so CI needs a MySQL service, as the auditor recommends). The GitHub API results (0 workflows, 0 PRs, no protection) were not re-queried here, but the local git history (0 merges, 224 direct commits on master) corroborates them. So the finding is factually accurate.

Materiality, however, is overstated. Mitigations that exist: (1) a real, substantial test suite — 38 feature-test files, ~4,400 lines — that specifically covers the role+ability matrix, ownership, throttling, OTP, scoping; (2) the process is documented and enforced-by-convention: CLAUDE.md instructs `php artisan test` and DEPLOYMENT.md item 9 makes 'tests green before every deploy' a mandatory pre-production step; (3) commit bodies show tests are run routinely (95 of the commit bodies reference tests/green). The gap is that nothing *mechanically* forces this. (4) Product context: a small (3-contributor, effectively solo-plus-assistants) Arabic-only, single-country project that per DEPLOYMENT.md is still pre-production — there is no deployed system for a broken push to break yet. (5) The 'Flutter/mobile team' impact claim has no basis in the repo (no mention of flutter/mobile in any .md), so that multiplier is speculative.

'Critical' should be reserved for exploitable defects or data loss. Absence of CI/branch protection is a process-maturity gap with a cheap, well-defined fix; the security model has a strong automated guard that is simply not wired to a gate. That is a medium finding (high only once the API is deployed or a second team actually starts consuming it). Recommendation stands and is correct, including the MySQL service requirement dictated by phpunit.xml.

```text
Confirmed: `ls .github` -> absent (root and backend/); `ls backend/pint.json` -> absent; `git log --oneline | wc -l` -> 224; `git log --merges | wc -l` -> 0; `git config core.hooksPath` -> unset, no .githooks/.husky; authors: MUTQEN 55 / muad03 82 / muad3719-crypto 87. Mitigations: backend/tests/Feature -> 38 files, ~4,426 lines covering role/ability matrix; backend/phpunit.xml:21-27 pins `DB_CONNECTION=mysql`, `DB_DATABASE=mutqin_test` (CI needs a MySQL service); DEPLOYMENT.md section 1 row 9 mandates `php artisan test` before every deploy; CLAUDE.md 'Gotchas' states tests must stay green; 95 commit bodies reference test runs. DEPLOYMENT.md frames the system as pre-production ('قائمة ما قبل الإنتاج'); no repo document mentions Flutter or a mobile team.
```

</details>

### Production deploy is manual cPanel file-pasting with hand-written SQL migrations; prod is 68 commits behind master

<a id="manual-sql-deploy-prod-lag"></a>

`manual-sql-deploy-prod-lag` · 🔴 critical (reviewers → high) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/DEPLOY_LOG.md:8-9`, `backend/DEPLOY_LOG.md:21-22`, `backend/DEPLOY_LOG.md:28-36`, `.cpanel.yml:1-7`

**Evidence**

```text
DEPLOY_LOG.md:8-9: 'النشر يدوي حصراً عبر cPanel File Manager (Upload + Extract للحزم، وEdit للصق الملفات المفردة)'; :21-22: 'الهجرات تُرفق بـSQL خام (لا artisan migrate على الخادم)'. Status lines: `grep -c 'حالة الرفع:** done'` -> 1, `pending` -> 7. `git rev-list --count fbe25fc..HEAD` -> 68 commits since the only 'done' entry (2026-09-07). Two migrations were added after that deploy (2026_09_08_100000_make_target_teacher_nullable..., 2026_09_11_100000_add_parent_code_sequence.php). DEPLOY_LOG last updated at 788ee03 (2026-09-11) with 17 commits after it, violating its own rule at :21 'كل commit جديد = مدخل هنا'. Meanwhile commit 9831f1c (2026-09-11) added .cpanel.yml for 'نشر تلقائي من Git Version Control' — the log still describes manual-only deploys, so the two mechanisms are undocumented relative to each other.
```

**Why it matters**

The Flutter team will code against master while production exposes an older API: login-by-code (a1cb65d), GET /admin/users, GET /manager/parents, /manager/teachers/{id}/performance, /students/{id}/details, PUT /manager/students/{id}/teacher all exist on master but are unrecorded as deployed. Hand-converted SQL migrations have no automated parity check with the migration files, and there is no rollback path. Scaling to dozens of centers on this process guarantees drift incidents.

**Recommendation**

Pick one deploy path and delete the other from the docs. Preferred: the .cpanel.yml git-pull deploy plus a post-deploy step that runs migrations (if the host truly has no CLI, add a token-protected `artisan migrate --force` HTTP trigger executed by the pipeline, or move to a host with SSH). Record the deployed commit hash in a `/api/public/version` endpoint so drift is observable. Cut a tagged release before the mobile sprint and deploy exactly that tag.

<details><summary>Verification — 2 independent reviewers</summary>

**Upheld** · confidence 80% · corrected severity: high

Quoted evidence is accurate: backend/DEPLOY_LOG.md:6-9 states SSH is disabled by the host and deployment is manual-only via cPanel File Manager; :21-22 states migrations ship as raw SQL with no artisan migrate; the status counts are exactly 1 'done' (fbe25fc, 2026-09-07) and 7 'pending'; `git rev-list --count fbe25fc..HEAD` = 68 and `788ee03..HEAD` = 17; the two post-deploy migrations (2026_09_08 target_teacher nullable, 2026_09_11 parent code sequence) exist; .cpanel.yml (9831f1c, 2026-09-11) is a 7-line rsync deploy that is never mentioned in DEPLOY_LOG.md (grep for cpanel/git finds only the 'no git' line at :7), and it runs no migration step. All six cited routes exist on master (routes/api.php:59,60,66,112,143 plus login-by-code commits) and none has a deploy entry; there is no /api/public/version route.

However, the headline is over-stated in two ways. (1) 'prod is 68 commits behind master' is not a verified fact — it is inferred from a self-maintained status field that :23 says only the owner flips after an actual upload. Since the owner added .cpanel.yml for cPanel Git Version Control, code may well have been pulled to prod without the log being updated; the repo gives no observable production state either way (that unobservability is itself the real defect). (2) Migration drift is partially mitigated: entry e7c50f9 (:39-46) is a full clean SQL dump that explicitly states it includes both post-deploy migrations (يغني ... عن SQL الهجرتين 09-08 و09-11), and the project has a 119-test suite guarding the code. The constraint is a hosting-provider limitation (SSH refused on 22) rather than a team choice, and the product is a small single-host Libyan deployment. The finding stands as a genuine process gap — two undocumented, mutually inconsistent deploy paths, a stale log violating its own rule, hand-written SQL without parity check or rollback, and no way to observe the deployed version before a mobile team builds against the API — but 'critical' overstates a risk that is unverified and partly mitigated; 'high' is appropriate given the imminent Flutter dependency.

```text
backend/DEPLOY_LOG.md:6-9 (SSH refused, manual-only), :21-23 (raw SQL migrations; status flipped only by owner after real upload — so 'pending' ≠ confirmed not-deployed), :37 (only 'done' entry fbe25fc 2026-09-07), :39-46 (e7c50f9 clean dump stated to subsume both post-deploy migrations — partial mitigation of migration drift). .cpanel.yml:1-7 (rsync-only deploy, no migrate step, added in 9831f1c 2026-09-11, never referenced in DEPLOY_LOG.md). backend/routes/api.php:59,60,66,112,143 — routes with no deploy record; no `version` route anywhere in api.php. git: rev-list fbe25fc..HEAD=68, 788ee03..HEAD=17 (both confirmed).
```

---

**Upheld** · confidence 80% · corrected severity: medium

Facts check out: DEPLOY_LOG.md:6-9 states SSH is disabled by the host and deploys are manual via cPanel File Manager; :21-22 says migrations ship as raw SQL and every commit must get a log entry; status lines are 1 done / 7 pending; `git rev-list --count fbe25fc..HEAD` = 68 and `788ee03..HEAD` = 17; .cpanel.yml (9831f1c, 2026-09-11) is not mentioned anywhere in DEPLOY_LOG.md or DEPLOYMENT.md, and it only rsyncs files (excludes vendor, runs no composer/migrate). The listed post-deploy API additions (GET /admin/users 9161d59, /manager/parents fd4a2e2, parent login codes cb260ec, /students/{id}/details 511cf22, /manager/teachers/{id}/performance 9d3c1cb, changeTeacher 542ebaa) are real and unrecorded as deployed. So the process gap is genuine and not mitigated by any code layer, test, or middleware.

Materiality, however, does not support 'critical': (1) The system is pre-launch — DEPLOY_LOG.md:39-46 describes the pending production state as an empty DB with only the admin account ('قاعدة فارغة جاهزة للبيانات الحقيقية'); the currently deployed instance holds demo data from 2026-09-07, so no real users or data are exposed to the lag. (2) The '68 commits' span four calendar days (2026-09-07 to 2026-09-11) of a small-team sprint, not months of drift. (3) The 'hand-written SQL with no parity check' concern is largely mitigated: DEPLOY_LOG.md:40 and :52 show the pending DB artefacts are full `migrate:fresh --seed` dumps that include both post-deploy migrations (explicitly: 'يغني ... عن SQL الهجرتين (09-08 و09-11) لأنه يشملهما'), so schema parity comes from Laravel itself, and a full dump with DROP TABLE IF EXISTS is a crude but real rollback path. (4) The manual process is forced by an external host constraint (SSH refused on port 22), and .cpanel.yml is the team already moving toward the auditor's recommended path; the defect is that the two mechanisms are undocumented relative to each other, not that automation is absent. (5) No security, data-integrity, or availability defect results; the impact is coordination risk for the Flutter team, which is a documentation/release-management issue.

Net: real CMMI-lens process weakness (stale deploy log, unreconciled deploy mechanisms, no version endpoint), best rated medium. The recommendation to tag a release and expose a version endpoint before the mobile sprint is sound.

```text
backend/DEPLOY_LOG.md:6-9 (SSH disabled by host; manual cPanel deploy); :21-23 (raw SQL migrations; 'every commit = an entry'); :37 done vs :46,:55,:64,:72,:83,:92,:100 pending; :39-46 pending production state is an EMPTY DB (no real users yet); :40 and :52 pending DB artefacts are full migrate:fresh dumps that already include migrations 2026_09_08_100000 and 2026_09_11_100000 (parity via Laravel, not hand-conversion). .cpanel.yml:5-7 rsync-only (excludes vendor/.env/storage; no composer, no migrate) — unreferenced in DEPLOY_LOG.md/DEPLOYMENT.md (grep -i 'cpanel.yml' returns nothing). git: fbe25fc..HEAD = 68 commits over 2026-09-07..2026-09-11; 788ee03..HEAD = 17; post-deploy API commits 9161d59, fd4a2e2, cb260ec, 511cf22, 9d3c1cb, 542ebaa confirmed. No /api/public/version route in backend/routes/api.php.
```

</details>

### No releases, no CHANGELOG, no semantic versioning, one ad-hoc tag

<a id="no-release-process"></a>

`no-release-process` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/composer.json`, `frontend-html/manifest.webmanifest`, `backend/config/app.php`

**Evidence**

```text
`git tag` -> only `requests-restructure-2026-09-08` (a lightweight commit tag, `git cat-file -t` -> commit, not annotated); `gh api .../releases --jq length` -> 0; `find . -name 'CHANGELOG*'` -> none; `grep -nE '"version"' backend/composer.json frontend-html/manifest.webmanifest` -> no matches; no APP_VERSION in config/app.php. The .gitattributes even export-ignores a CHANGELOG.md that does not exist.
```

**Why it matters**

A mobile app must pin to a known API version and know what changed between builds. Without tags/CHANGELOG, the mobile team cannot answer 'which backend does build 1.0.3 need?' and support cannot correlate a bug report with a deployed version.

**Recommendation**

Adopt semver tags (v1.0.0 at the pre-mobile freeze), generate CHANGELOG.md from conventional commits (e.g. git-cliff or release-please), expose the tag via a `/api/public/version` endpoint and in manifest.webmanifest, and tag every production deploy. Make 'tag + CHANGELOG entry' part of the definition of done for a release PR.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The quoted evidence is accurate: `git tag` lists only `requests-restructure-2026-09-08` (a lightweight commit tag, `git cat-file -t` -> commit); `gh api .../releases --jq length` -> 0; no CHANGELOG* anywhere outside vendor; no `"version"` key in backend/composer.json or frontend-html/manifest.webmanifest; no version/APP_VERSION in backend/config/app.php; no `/api/public/version` route, no API-version header or `/v1/` prefix in routes/api.php or frontend js. `backend/.gitattributes:10` does export-ignore a non-existent CHANGELOG.md (this is Laravel's stock skeleton file, not a project decision). So the finding is factually correct and not refuted.

However it is over-rated. Mitigation the auditor missed: `backend/DEPLOY_LOG.md` (100 lines, 7 dated entries from 2026-09-07 to 2026-09-11) is a hand-maintained per-deploy log keyed by commit hash, listing changed files, raw SQL for migrations, new .env keys and upload status — it is a de-facto changelog plus deploy record and answers "which commit is on the server". Context also weakens the impact claim: there is exactly one production instance (cPanel on mutqin.ly, manual upload, no SSH), one static web client that ships in the same repo/commit as the API, and no mobile app or second API consumer exists in the repo (grep for mobile/flutter/android hits only DEPLOY_LOG.md incidentally). The "build 1.0.3 needs which backend?" scenario is hypothetical roadmap, not a current defect. Missing semver tags and a version endpoint is a real process gap that will matter once an independently-released client exists, so it stays a valid finding — but for a single-deploy, same-repo client today it is medium, not high.

```text
Confirmed: `git tag` -> requests-restructure-2026-09-08 only (type commit); `gh api releases --jq length` -> 0; no CHANGELOG* in repo; no "version" in backend/composer.json or frontend-html/manifest.webmanifest; no version in backend/config/app.php or routes/api.php; backend/.gitattributes:10 `CHANGELOG.md export-ignore` (stock Laravel skeleton line). Mitigation not cited: backend/DEPLOY_LOG.md — commit-hash-keyed deploy log with entries at lines 27, 39, 48, 57, 66, 74, 85 (2026-09-07..09-11), each listing changed files, raw migration SQL, .env keys and upload status; latest commit 37313bf (2026-09-11) is covered by the e7c50f9 entry era. No mobile client or second API consumer exists in the repo; the frontend ships in the same commit as the API and there is one manually-deployed production instance.
```

</details>

### Single developer under three git identities; issue tracker unused; two orphaned unmerged branches

<a id="single-dev-three-identities-no-tracker"></a>

`single-dev-three-identities-no-tracker` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `origin/claude/fix-error-l1qqjk`, `origin/claude/libya-database-seeding-3mr2er`

**Evidence**

```text
`git shortlog -sn HEAD`: muad3719-crypto <muad_3719@limu.edu.ly> 87, muad03 <muad.st03@gmail.com> 82, MUTQEN <claudecombo@gmail.com> 55 — commit 707b0d4 'chore: verify identity' is an empty commit. `gh api .../issues` (excluding PRs) -> 0 issues ever. `git branch -a` shows origin/claude/fix-error-l1qqjk (1 ahead, 67 behind, NOT merged — contains MutqinAdminCommand + AdminAccountCommandTest.php, 273 insertions, a production admin-bootstrap fix) and origin/claude/libya-database-seeding-3mr2er (1 ahead, 81 behind, NOT merged, 1659-line seeder rewrite). All 224 commits are unsigned (`git log --format=%G?` -> 224 N).
```

**Why it matters**

Bus factor of 1; no traceability from a change to a requirement or bug report; a completed, tested production fix (admin account command) is silently rotting on a branch. When a mobile team joins, there is no shared backlog or ownership map.

**Recommendation**

Normalise git identity (one name/email, .mailmap for history). Triage the two claude/* branches now: merge or delete with a note. Turn on GitHub Issues + a Project board as the single backlog; require every PR to reference an issue. Add CODEOWNERS (backend/, frontend-html/, later mobile/) once the second engineer arrives.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

All quoted facts verified read-only against the repo: `git shortlog -sne HEAD` gives exactly muad3719-crypto 87 / muad03 82 / MUTQEN <claudecombo@gmail.com> 55; commit 707b0d4 'chore: verify identity' by muad03 is empty (no diffstat); `git log --format=%G?` is 224 N over 224 commits; no .mailmap, no .github/ (no CODEOWNERS, no templates); `gh api` shows has_issues=true but 0 issues and 0 PRs ever; both origin/claude/* branches are NOT ancestors of master (67/1 and 81/1 ahead/behind). So the finding is factually correct and not refuted. However it is over-rated: (1) origin/claude/libya-database-seeding-3mr2er (Aug 25, muad03) is not 'rotting' but superseded — master received a larger 7-stage LibyanDataSeeder series on 2026-09-01 (74ec195..3752e88), a demo reduction on 09-10, and ProductionSeeder on 09-11; that branch is simply stale and should be deleted, no value lost. (2) origin/claude/fix-error-l1qqjk (Sep 8, author 'Claude <noreply@anthropic.com>' — a fourth identity the auditor missed) adds MutqinAdminCommand + AdminAccountCommandTest; its core purpose (bootstrapping an admin account on production) was partly addressed 3 days later on master by ProductionSeeder (reads ADMIN_INITIAL_PASSWORD, creates admin@mutqin.ly, backend/database/seeders/ProductionSeeder.php:24-40). What master still lacks is the branch's SQL-output path for a CLI-less host and the ability to use a custom admin email, so a real but smaller gap remains. (3) The identity split is two emails of one person plus a project account plus a Claude author — a hygiene issue, not a security or correctness defect; unsigned commits are the norm for a solo project. Net: real process-maturity gap (no backlog, orphan branch triage, identity normalisation), but not 'high' for a single-developer product; medium.

```text
git shortlog -sne HEAD: muad3719-crypto <muad_3719@limu.edu.ly> 87; muad03 <muad.st03@gmail.com> 82; MUTQEN <claudecombo@gmail.com> 55; plus branch author Claude <noreply@anthropic.com> (7bf08f7). 707b0d4 empty. 224/224 commits %G?=N. No .mailmap, no .github/. gh api repos/muad03/MUTQIN: has_issues=true, issues=0, pulls=0. origin/claude/fix-error-l1qqjk: 67 behind/1 ahead, NOT-MERGED, 6 files +273 (MutqinAdminCommand, AdminAccountCommandTest, bootstrap/app.php). origin/claude/libya-database-seeding-3mr2er: 81 behind/1 ahead, NOT-MERGED, but superseded by master commits 74ec195..3752e88 (2026-09-01 LibyanDataSeeder stages 1-7), c80e8f5 (09-10), e7c50f9 ProductionSeeder (09-11). backend/database/seeders/ProductionSeeder.php:24-40 already bootstraps admin@mutqin.ly from ADMIN_INITIAL_PASSWORD, partially covering the orphan admin-command branch's intent (missing: custom email, SQL-only output for CLI-less hosting). backend/app/Console/Commands does not exist on master.
```

</details>

### CLAUDE.md and DEPLOYMENT.md have drifted from the code (undocumented subsystems, wrong counts, obsolete instructions)

<a id="docs-drift-claude-md"></a>

`docs-drift-claude-md` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `CLAUDE.md:25`, `CLAUDE.md:18`, `DEPLOYMENT.md:11`, `DEPLOYMENT.md:13`, `DEPLOYMENT.md:14`, `backend/routes/api.php:47-49`, `backend/routes/api.php:59-66`, `backend/routes/api.php:112`, `backend/routes/api.php:143-149`

**Evidence**

```text
CLAUDE.md:25 says '# 20 feature-test files' — `git ls-files backend/tests/Feature | wc -l` -> 38. `grep -c` in CLAUDE.md for MessageController, AdminUserController, admin/users, webmanifest, _handoff2, n8n, cpanel, ProductionSeeder, LibyanDataSeeder, DEPLOY_LOG, changeTeacher, 'performance' -> all 0, yet routes/api.php:47-49 and :147-149 define parent/teacher /messages, :112 GET /admin/users, :60 GET /manager/parents, :66 /manager/teachers/{id}/performance, :59 PUT /manager/students/{id}/teacher, :143-144 /students/{id}/details|day. CLAUDE.md last touched at a07327d (2026-09-08); 30 commits since. DEPLOYMENT.md:11 still instructs replacing `allowed_origins => ['*']` although cors.php:14-16 already reads CORS_ALLOWED_ORIGINS (commit fbf2d40); :13 says to edit API_BASE_URL although config.js:10-12 is runtime-detected (f868880); :14 says remove the demo panel although b2501ae removed it. CLAUDE.md:18 hard-codes 'PHP is only available at C:\xampp\php\php.exe' — on this machine `ls /c/xampp` -> not found.
```

**Why it matters**

The onboarding doc for humans and AI agents is the primary process artefact here; when it misdescribes the test suite size, omits five subsystems and gives obsolete deploy steps, new contributors (and the mobile team reading it for API behaviour) will build on wrong assumptions.

**Recommendation**

Make 'docs updated in the same PR' a checklist item in a PR template. Immediately: sync CLAUDE.md route table and controller list with routes/api.php, fix the test count, add Messaging/AdminUsers/ManagerParents/TeacherStudentDetails/ProductionSeeder/n8n/.cpanel.yml/PWA sections; rewrite DEPLOYMENT.md items 3, 5, 6 to reflect current code; replace machine-specific paths with a documented `PHP_BIN` convention.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 92% · corrected severity: medium

Every factual claim in the finding checks out against the working tree. `git ls-files backend/tests/Feature | wc -l` returns 38 while CLAUDE.md:25 says "20 feature-test files". routes/api.php:47-49 and :147-149 define parent/teacher `/messages` (MessageController), :112 `GET /admin/users` (AdminUserController), :59 `PUT /manager/students/{id}/teacher` (changeTeacher), :60 `GET /manager/parents`, :66 `/manager/teachers/{id}/performance`, :143-144 `/students/{id}/details|day` — none of MessageController/AdminUserController/admin/users/changeTeacher/performance/ProductionSeeder/n8n/cpanel appear in CLAUDE.md (grep count 0). CLAUDE.md was last modified at a07327d (2026-09-08) with 60 commits since (auditor said 30 — understated, not overstated). Repo root also contains untracked-in-docs `ProductionSeeder.php`, `LibyanDataSeeder.php`, `n8n/`, `.cpanel.yml`, `_handoff2`. DEPLOYMENT.md item 3 still says replace `allowed_origins => ['*']` while cors.php:14-16 already reads `CORS_ALLOWED_ORIGINS` with a closed default; item 5 says edit `API_BASE_URL` while config.js:10-12 is hostname-detected; item 6 tells the deployer to remove a demo panel that commit b2501ae already deleted (no demo/fill references remain in login.html). `C:\xampp\php\php.exe` does not exist on this machine (Herd is installed instead), so the hard-coded command lines in CLAUDE.md fail as written. No mitigation found: there is no PR template, no CI, and no generated docs. However, this is purely a process/documentation defect — the code itself behaves correctly, security gates are intact, and the stale deploy instructions are conservative (following them would not weaken security; they just point at things already done). For a small single-country team with an AI-agent-oriented CLAUDE.md, the realistic harm is wasted effort and mis-scoped agent work, not a production incident. Severity is better rated medium than high.

```text
CLAUDE.md:25 "20 feature-test files" vs `git ls-files backend/tests/Feature | wc -l` = 38. CLAUDE.md last changed at a07327d (2026-09-08); `git rev-list --count a07327d..HEAD` = 60 (auditor said 30). Undocumented routes: backend/routes/api.php:47-49 (parent messages, MessageController), :59 (PUT /manager/students/{id}/teacher → StudentController@changeTeacher), :60 (GET /manager/parents → CenterManagerController@parents), :61 (managerSearchParents), :66 (teacherPerformance), :112 (GET /admin/users → AdminUserController), :143-144 (teacherDetails/teacherDay), :147-149 (teacher messages). grep -c for MessageController|AdminUserController|admin/users|changeTeacher|performance|ProductionSeeder|n8n|cpanel in CLAUDE.md = 0. Undocumented repo items: backend/database/seeders/ProductionSeeder.php, LibyanDataSeeder.php; root n8n/, .cpanel.yml, _handoff2. DEPLOYMENT.md:11 (item 3) vs backend/config/cors.php:14-16 env('CORS_ALLOWED_ORIGINS') with default ['http://localhost:8080']; DEPLOYMENT.md:13 (item 5) vs frontend-html/js/config.js:10-12 hostname-based API_BASE_URL; DEPLOYMENT.md:14 (item 6) vs commit b2501ae (demo panel already removed; no demo/fill strings in login.html). CLAUDE.md:18 path C:\xampp\php\php.exe absent on this machine (`ls /c/xampp/php/php.exe` → No such file; Herd at C:\Users\HP\.config\herd\bin instead).
```

</details>

### GitHub repository is public and commits hosting internals (cPanel user, DB name, server paths) and demo credentials

<a id="public-repo-exposes-hosting-details"></a>

`public-repo-exposes-hosting-details` · 🟠 high (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/DEPLOY_LOG.md:3-5`, `backend/DEPLOY_LOG.md:14-19`, `backend/database/seeders/LibyanDataSeeder.php:40-43`, `CLAUDE.md:32`

**Evidence**

```text
`gh api repos/muad03/MUTQIN --jq .private` -> false. DEPLOY_LOG.md:3-5: 'المستخدم `[redacted-cpanel-user]`', '/home/[redacted-cpanel-user]/public_html/', 'القاعدة: `[redacted-db-name]` (phpMyAdmin من cPanel)'; :14-19 enumerate server-only file paths. LibyanDataSeeder.php:40-43: `ADMIN_PASSWORD = '[redacted-demo-password]'` etc.; CLAUDE.md:32 documents demo password `[redacted-demo-password]` and the email scheme. DEPLOY_LOG.md:36 notes a demo DB dump was uploaded to production on 2026-09-07 with those accounts.
```

**Why it matters**

An attacker gets the cPanel username, database name, exact server layout and the demo password scheme for free; if the demo dump is still live in prod (entry e7c50f9 to replace it is marked 'pending'), the documented credentials may work. Also exposes photos of children in Home photos/ — already public via the landing page, but now also in a forkable repo.

**Recommendation**

Make the repository private now (S). Move DEPLOY_LOG.md and any host-specific runbook to a private ops repo or wiki; keep only a generic DEPLOYMENT.md in the code repo. Confirm the production DB no longer contains seeder accounts (ProductionSeeder rollout) and rotate the cPanel password since the username is public.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

Verified facts: `gh api repos/muad03/MUTQIN` returns private=false / visibility=public, and `git remote -v` points at that repo. backend/DEPLOY_LOG.md is tracked and lines 3-5 do name the cPanel user `[redacted-cpanel-user]`, web root `/home/[redacted-cpanel-user]/public_html/` and DB `[redacted-db-name]`; lines 14-19 list server-only paths. LibyanDataSeeder.php:40-43 hard-codes `[redacted-demo-password]` for all four roles. `Home photos/` (11 images) is tracked. So the disclosure itself is real, and the finding cannot be fully refuted.

However the impact as stated is largely wrong or already mitigated, so it is over-rated:
1. The core claimed risk — "if the demo dump is still live in prod, the documented credentials may work" — does not hold. A read-only probe of production `GET https://mutqin.ly/backend/public/api/public/stats` returns `{"centers":1,"users":3,"students":0}`; the demo dump (c80e8f5) had 50 students, 5 teachers, 26 parents. The demo data set is not live; the DB already holds a clean/real-data state, and ProductionSeeder.php:24-25 reads the admin password from `ADMIN_INITIAL_PASSWORD` (min 8 chars) rather than hard-coding it. The 'pending' status line in DEPLOY_LOG is simply stale documentation.
2. `GET /api/public/demo-accounts` on production returns `data: []` — DashboardController::demoAccounts (lines 101-107) returns an empty list outside local/APP_DEBUG, so no account emails are enumerated from the live system.
3. The auditor's evidence CLAUDE.md:32 (`[redacted-demo-password]`) is itself stale — the seeder uses `[redacted-demo-password]`; neither is a production credential, both are local-demo passwords.
4. The cPanel username alone does not grant access; DEPLOY_LOG:6-7 notes SSH is disabled by the host, and cPanel still requires the password (plus whatever host-side lockout). DB name is useless without DB access (no remote MySQL exposure evidenced). The child photos are already served publicly from the landing page, as the auditor concedes — the repo adds forkability, not new exposure.

What remains is a genuine but modest information-disclosure/process-hygiene issue: a public repo leaking hosting layout and a cPanel username that makes targeted phishing/credential-stuffing marginally easier. Making the repo private and moving DEPLOY_LOG.md out is cheap and still recommended, but the severity should be low rather than high; the "rotate cPanel password" and "confirm prod DB has no seeder accounts" items are prudent but the second is already effectively satisfied.

```text
Confirmed: `gh api repos/muad03/MUTQIN --jq '.private,.visibility'` -> false / public. backend/DEPLOY_LOG.md:3-5 (cPanel user `[redacted-cpanel-user]`, `/home/[redacted-cpanel-user]/public_html/`, DB `[redacted-db-name]`), :6-7 (SSH disabled by host), :14-19 (server-only paths); tracked in git (`git ls-files`). backend/database/seeders/LibyanDataSeeder.php:40-43 `= '[redacted-demo-password]'` (CLAUDE.md:32 `[redacted-demo-password]` is stale, not the seeder's value). `Home photos/` — 11 tracked images. Mitigations: backend/database/seeders/ProductionSeeder.php:24-25 admin password from env `ADMIN_INITIAL_PASSWORD` (>=8 chars, no hard-coded default); backend/app/Http/Controllers/Api/DashboardController.php:101-107 demoAccounts returns [] outside local/debug. Live read-only probe (2026-09-14): `GET https://mutqin.ly/backend/public/api/public/stats` -> {"centers":1,"users":3,"students":0}; `GET .../api/public/demo-accounts` -> {"data":[]} — the 50-student/26-parent demo dump is not in production, so the seeded demo credentials are not usable against prod.
```

</details>

### Conventional-commit prefixes adopted only since 2026-08-22 and inconsistently (29%); mixed English/Arabic; 31% of subjects exceed 72 chars

<a id="conventional-commits-partial"></a>

`conventional-commits-partial` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `git log (all)`

**Evidence**

```text
`git log --format=%s | grep -Ec '^(feat|fix|test|docs|chore|style|refactor|perf)(\(...\))?:'` -> 64 of 224 (feat 42, test 11, style 4, fix 4, chore 2, docs 1). First prefixed commit is 1003e0f on 2026-08-22; the 2026-09-07..09-08 deploy series reverts to unprefixed Arabic ('نشر: ...', 'سجل: ...'), and 31 of 74 Arabic-subject commits have no prefix (e.g. 37313bf, d509387, 49dbadb). Subject length median 66, max 146; 70/224 exceed 72 chars. Language flips English (Jun-Aug) -> Arabic (Sep).
```

**Why it matters**

Automated CHANGELOG/semver derivation and commit-lint gates are impossible with 71% non-conforming history; bilingual subjects hurt `git log --oneline` scanning and tooling. Body quality is excellent, so this is a formatting gap, not a communication gap.

**Recommendation**

Adopt commitlint (config-conventional) via a husky/lefthook hook and in CI; decide one subject language (Arabic body is fine, keep the type/scope token Latin: `feat(manager): ...`); enforce <=72-char subjects. Document allowed scopes (auth, students, manager, teacher-ui, seed, deploy, docs).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 90% · corrected severity: low

Every quantitative claim reproduces exactly against the repo history (read-only git log): 224 commits; 64 match a Conventional Commits prefix (28.6%); first prefixed commit is 1003e0f on 2026-08-22 (preceded only by 52fbd46/86d8bc3 on 2026-08-21, which are also prefixed — so the adoption date is 2026-08-21, a one-day quibble); 74 subjects contain Arabic and 31 of them are unprefixed (e.g. 37313bf, 49dbadb, d509387, and the 2026-09-08 'سجل:'/'إصلاح:' series); character-length stats are median 66, max 146, 70/224 > 72 chars. No mitigation exists anywhere: no commitlint/husky/lefthook config, no .github/ CI, no core.hooksPath, no non-sample hooks in .git/hooks, no package.json at repo root. So the finding is factually correct and unmitigated. However it is over-rated at medium: this is a single-developer, Arabic-only, single-country project with no automated release/CHANGELOG/semver pipeline that the non-conforming history would break; the 'impact' is hypothetical tooling that the project does not use. Recent commits (Sept 2026) are actually mostly conformant with Latin type/scope tokens and Arabic descriptions (feat(manager): ..., test(students): ...), so the trend already matches the recommendation. Commit bodies are acknowledged to be high quality, so there is no communication or traceability loss. This is a low-severity process-hygiene observation, not a medium engineering risk.

```text
git log --format=%s | wc -l -> 224; grep -Ec '^(feat|fix|test|docs|chore|style|refactor|perf)(\([^)]*\))?:' -> 64. First prefixed commits: 86d8bc3 and 52fbd46 on 2026-08-21 (not 2026-08-22; 1003e0f is the third). Arabic-containing subjects: 74; unprefixed among them: 31. Char-length stats (UTF-8 aware): median 66, max 146, 70 > 72 chars (byte-count would show 92 > 72, max 250). No enforcement tooling: repo root has only .cpanel.yml, .gitignore, CLAUDE.md, DEPLOYMENT.md, backend/, frontend-html/, n8n/ — no .github/, no commitlint/husky/lefthook, core.hooksPath unset, .git/hooks contains only samples. Latest 15 commits (2026-09-11) are ~11/15 conformant with Latin type(scope) + Arabic description, i.e. already following the recommended style.
```

</details>

### 15 MiB of tracked files: composer.phar, orphaned screenshots, duplicated photos, a design handoff bundle and stray dev scripts

<a id="repo-hygiene-binaries"></a>

`repo-hygiene-binaries` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/composer.phar`, `screenshots/`, `Home photos/`, `_handoff2/untitled/project/uploads/pasted-1781910600697-0.png`, `backend/attendance_test.xlsx`, `backend/check_excel.php:4`, `backend/generate_test_excel.php:40`, `.gitignore:1-9`

**Evidence**

```text
`git count-objects -vH` -> size-pack 9.92 MiB; tracked working tree 14.99 MiB; 66 binary files. composer.phar 3,565,131 bytes. screenshots/ 4.74 MiB / 36 files, last touched in the initial commit be47c33 and referenced by nothing (`git grep -l 'screenshots/'` -> none). Home photos/ 1.03 MiB / 11 files — all 11 are md5-identical to frontend-html/img/gallery/g01..g11 (e.g. 260d5b5a... = DSC09960-scaled.jpg = g10-judges-panel.jpg). _handoff2/ 3.18 MiB incl. a 2.09 MB pasted PNG and a 730 KB standalone HTML. backend/attendance_test.xlsx is only referenced by two one-off scripts committed to backend root (check_excel.php:4, generate_test_excel.php:40); FingerprintImportTest builds its own xlsx in memory (lines 22-27). Root .gitignore is 9 lines (zip, .shots-tmp, node_modules, OS files) — nothing for phar/xlsx/scratch.
```

**Why it matters**

Clone and CI time grow with every screenshot; dev scripts and a phar get rsynced to the production webroot by .cpanel.yml (see separate finding); duplicate assets invite edits to the wrong copy; a design-tool export sits at repo root with no owner. At dozens of centers and a mobile repo this is the kind of debt that makes CI slow and reviews noisy.

**Recommendation**

Delete screenshots/, Home photos/, backend/check_excel.php, backend/generate_test_excel.php, backend/attendance_test.xlsx; move _handoff2/ to a docs/design branch or Figma; stop tracking composer.phar (install Composer in CI, document `composer.phar` download in README). Extend .gitignore with `*.phar`, `*.xlsx` (except database/data/), `screenshots/`. Consider `git filter-repo` later to shrink history once the repo is private and collaborators re-clone.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Every factual claim checks out: `git count-objects -vH` size-pack 9.92 MiB; tracked tree 14.996 MiB over 320 files; 67 binary-charset files; backend/composer.phar 3,565,131 bytes; screenshots/ = 36 tracked files, only ever touched in the root commit be47c33, and `git grep -l 'screenshots/'` returns nothing; all 11 files in "Home photos/" are md5-identical to files in frontend-html/img/gallery/ (11 duplicate hashes); _handoff2/ contains a 2,088,477-byte pasted PNG; backend/attendance_test.xlsx is referenced only by backend/check_excel.php:4 and backend/generate_test_excel.php:40, while FingerprintImportTest builds its xlsx in memory (lines 22-30); root .gitignore has no phar/xlsx/screenshot rules; .cpanel.yml rsyncs backend/ excluding only vendor/.env/storage/bootstrap/cache, so the phar and the two scratch scripts do land in production. The finding is therefore real. However its impact is overstated: (1) backend/.htaccess (tracked) issues `Require all denied` / `Deny from all` for the entire backend tree, so the deployed scratch scripts and phar are not web-reachable — the production-exposure angle is already mitigated; (2) composer.phar is a deliberate, documented choice (CLAUDE.md:16,21: "no global Composer — use the bundled backend/composer.phar"), not an accident, though pinning it in git is still poor practice; (3) a 10 MiB pack / 15 MiB checkout is small in absolute terms — the "CI slow at dozens of centers" framing does not follow (centers are DB rows, not repo growth), and there is no CI pipeline in the repo to slow down. What remains is genuine but low-stakes hygiene debt: orphaned screenshots, byte-identical duplicate photos that invite edits to the wrong copy, a design export at repo root, and two one-off scripts committed to backend root. Severity should be low, not medium.

```text
Confirmed: size-pack 9.92 MiB; tracked 14.996 MiB / 320 files / 67 binaries; screenshots/ 36 files, sole commit be47c33, zero references; Home photos/ 11 files all md5-equal to frontend-html/img/gallery/*; _handoff2/untitled/project/uploads/pasted-1781910600697-0.png 2,088,477 B; attendance_test.xlsx referenced only by backend/check_excel.php:4 and backend/generate_test_excel.php:40; FingerprintImportTest.php:22-30 builds xlsx in memory. Mitigations the finding omits: backend/.htaccess:10-19 (`Require all denied` + `Deny from all`) blocks HTTP access to the whole deployed backend/ tree, so rsynced scripts/phar are not web-reachable; CLAUDE.md:16,21 documents composer.phar as the intentional bundled Composer for this XAMPP setup (no global Composer). No CI config exists in the repo, so the "CI time" impact is hypothetical.
```

</details>

### .cpanel.yml rsyncs tests, dev scripts, composer.phar and DEPLOY_LOG into the production webroot

<a id="cpanel-deploy-ships-dev-artifacts"></a>

`cpanel-deploy-ships-dev-artifacts` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `.cpanel.yml:5-7`, `backend/.htaccess`

**Evidence**

```text
.cpanel.yml:6: `rsync -a --delete --exclude='vendor' --exclude='.env' --exclude='storage' --exclude='bootstrap/cache' backend/ $DEPLOYPATH/backend/` — no exclusion for tests/, composer.phar, check_excel.php, generate_test_excel.php, attendance_test.xlsx, DEPLOY_LOG.md, phpunit.xml, .env.production.example. Only backend/.htaccess (commit def7f70) stands between HTTP and this tree.
```

**Why it matters**

Defence-in-depth violation: a single .htaccess misconfiguration on shared hosting exposes the test suite (which documents every authorization edge), the deploy log with server layout, and executable one-off PHP scripts. Also wastes deploy time.

**Recommendation**

Add `--exclude='tests' --exclude='*.phar' --exclude='*.xlsx' --exclude='DEPLOY_LOG.md' --exclude='phpunit.xml' --exclude='*.example'` to the backend rsync, or better, build a release artifact in CI (composer install --no-dev, remove dev files) and deploy that. Delete the stray scripts (see hygiene finding).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Evidence confirmed verbatim: .cpanel.yml:6 rsyncs backend/ into $DEPLOYPATH/backend/ (public_html/backend/) excluding only vendor, .env, storage, bootstrap/cache. backend/ currently contains tests/, composer.phar (3.5 MB), check_excel.php, generate_test_excel.php, attendance_test.xlsx, DEPLOY_LOG.md, phpunit.xml, .env.example and .env.production.example — none excluded, and backend/.gitignore does not ignore them either, so they are all in the repo and would be shipped. So the finding is factually correct. However it is overrated: (1) backend/.htaccess (commit def7f70) is a dual-syntax deny-all (mod_authz_core `Require all denied` + mod_access_compat fallback) with an explicit `Require all granted` only in backend/public/.htaccess — the tree is fenced. (2) That same single fence is already the only thing protecting .env, config/, database/ and app/ on the server (they must exist there for the API to work), which are far more sensitive than the test suite or a composer.phar; adding tests/ and a deploy log to the tree adds little marginal exposure — if .htaccess fails, the .env DB credentials are the real loss, not phpunit.xml. (3) The one-off scripts are benign (read a local xlsx / write a sample xlsx) and both `require 'vendor/autoload.php'` — they are not exploitable in any meaningful way beyond what index.php already offers. (4) DEPLOY_LOG.md contains server layout and DB name but no secrets (it explicitly notes dumps are "without tokens"). (5) DEPLOY_LOG.md also records that SSH is disabled and deployment is manual via cPanel File Manager, so .cpanel.yml is at most a secondary path. Net: a valid hygiene / defence-in-depth nit, not a medium-severity security finding. Recommendation (add excludes, delete stray scripts) is reasonable.

```text
.cpanel.yml:6 confirmed (excludes only vendor/.env/storage/bootstrap/cache). backend/ listing contains tests/, composer.phar, check_excel.php, generate_test_excel.php, attendance_test.xlsx, DEPLOY_LOG.md, phpunit.xml, .env.example, .env.production.example — all tracked (backend/.gitignore ignores none of them). Mitigation: backend/.htaccess:10-18 `Require all denied` + `Order allow,deny / Deny from all`; backend/public/.htaccess:3-11 explicit grant for public/ only. DEPLOY_LOG.md header: SSH disabled, deployment manual via File Manager; dumps "بلا توكنات" (no tokens); .env and storage/ live on the server behind the same .htaccess regardless.
```

</details>

### Zero automated tests for 5,248 lines of frontend JS; no static analysis or formatter enforced for PHP

<a id="no-frontend-tests-no-static-analysis"></a>

`no-frontend-tests-no-static-analysis` · 🟡 medium · ✅ confirmed · **NEXT** · effort M (1–3 days)

**Files:** `frontend-html/js/ui.js`, `frontend-html/js/api.js`, `frontend-html/*/**.html`, `backend/composer.json:69-77`

**Evidence**

```text
`git ls-files frontend-html | grep -iE 'test|spec|jest|cypress|playwright'` -> only the unrelated page teacher/weekly-tests.html. Inline `<script>` LOC across 33 HTML pages = 4,192 + 1,056 in js/*.js = 5,248 untested lines. Backend: no pint.json, no phpstan.neon/larastan, no psalm; composer.json require-dev has laravel/pint but no script invokes it. CLAUDE.md:8 says the frontend has 'no build step', so there is also no bundler-level type or lint check.
```

**Why it matters**

Frontend regressions (e.g. c9ee27d 'نموذج إضافة الطالب كان يعلق بصمت', 37313bf admin rendering teacher pages as undefined) are found by hand. For the mobile sprint the API-side matters more, but PHPStan at level 5+ would catch contract regressions (nullable fields, wrong return shapes) that the Flutter client would otherwise discover at runtime.

**Recommendation**

Backend now: add larastan (level 5, raise over time) and `pint --test` to CI. Frontend next: add Playwright smoke tests for the four role login + one CRUD each against a seeded DB, run in CI. Longer term consider moving page IIFEs into js/pages/*.js files so they can be unit-tested with Vitest.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 88% · corrected severity: medium

Confirmed read-only: (1) `git ls-files frontend-html | grep -iE 'test|spec|jest|cypress|playwright|vitest|eslint'` returns only teacher/weekly-tests.html — zero frontend tests, no ESLint/Prettier config, no frontend package.json. (2) backend/ has no pint.json, phpstan.neon, larastan, or psalm; composer.json require-dev lists laravel/pint (line ~19) but no script in `scripts` invokes it. backend/package.json is the untouched Laravel Vite skeleton (tailwind/vite only), irrelevant to the static frontend-html client. (3) No CI at all: `git ls-files` finds no .github/, gitlab-ci, husky, or pre-commit hooks — only backend/.editorconfig and phpunit.xml. So nothing enforces even the existing phpunit suite. Minor factual correction: js/*.js totals 927 LOC (api 85, auth 82, config 44, layout 261, ui 455), not 1,056; inline <script> LOC across 33 HTML pages is 4,192 as claimed, so ~5,119 untested frontend lines. Mitigation that tempers severity: the backend has a real 40-file feature-test suite (tests/Feature, incl. RoleMatrixTest, OwnershipTest, etc.) covering the API contract/security model that a mobile client would depend on, which addresses the most important half of the auditor's impact claim; PHPStan would add value but the API side is not untested. Frontend is a small vanilla IIFE client for a small single-country team, so Playwright is reasonable but the lack of unit tests is the norm for this architecture. Given no CI enforcement of anything and zero frontend/static checks, but a solid backend test suite, medium is fair — perhaps low-to-medium. Keeping medium as stated.

```text
frontend-html/js/*.js = 927 LOC (not 1,056): api.js 85, auth.js 82, config.js 44, layout.js 261, ui.js 455; inline <script> LOC across 33 HTML pages = 4,192 (confirmed) -> ~5,119 untested frontend lines. backend/composer.json require-dev "laravel/pint": "^1.13" present, `scripts` block contains only post-autoload-dump/post-update-cmd/post-root-package-install/post-create-project-cmd — no pint/phpstan invocation. No pint.json, phpstan.neon, psalm.xml in backend/. No CI or hooks tracked in git (`git ls-files | grep -iE '\.github|gitlab-ci|husky|pre-commit'` -> empty; only backend/.editorconfig, backend/phpunit.xml). backend/package.json is the stock Laravel Vite/Tailwind skeleton, unrelated to frontend-html. Mitigation: backend/tests/Feature has 40 test files (RoleMatrixTest, OwnershipTest, CenterManagerTest, StudentTransferRequestTest, etc.) plus tests/Unit/SurahReferenceJuzGapTest.php, so the API contract is covered by runtime tests even without static analysis.
```

</details>

### Root-level launcher and Arabic page guide still describe the retired Blade application

<a id="stale-root-artifacts-blade-era"></a>

`stale-root-artifacts-blade-era` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `تشغيل-المشروع.bat:25-27`, `دليل-محتوى-الصفحات.md:60`, `دليل-محتوى-الصفحات.md:71`, `دليل-محتوى-الصفحات.md:81`

**Evidence**

```text
تشغيل-المشروع.bat:27: `cd /d C:\xampp\htdocs\MUTQENQ\frontend && php artisan serve --port=9091` — `ls frontend` -> 'no frontend/ dir' (4104a4d untracked its last file on 2026-07-17). دليل-محتوى-الصفحات.md contains 31 references to `*.blade.php` app views (e.g. :60 `welcome.blade.php`, :71 `auth/login.blade.php`, :81 `admin/dashboard.blade.php`) while the only tracked blade files are 7 PDF templates under backend/resources/views/pdf/. Both files untouched since the initial commit be47c33 (2026-06-25); CLAUDE.md:35 itself flags the .bat as 'partly stale'.
```

**Why it matters**

Misleads new contributors and any AI agent that indexes the repo; signals that dead artefacts are not pruned as part of the process.

**Recommendation**

Delete تشغيل-المشروع.bat or rewrite it for the static client (php -S on frontend-html/), and either rewrite دليل-محتوى-الصفحات.md against the current 33 HTML pages or move it to an archive/ folder with a deprecation header. Add a quarterly 'docs freshness' review to the process.

### No root README; backend/README.md is the untouched Laravel boilerplate

<a id="no-root-readme-onboarding"></a>

`no-root-readme-onboarding` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `backend/README.md:1-5`, `CLAUDE.md`

**Evidence**

```text
`ls README* readme*` at repo root -> 'NO ROOT README'. backend/README.md:1 is the stock Laravel logo markdown ('<p align="center"><a href="https://laravel.com"...'). All onboarding knowledge lives in CLAUDE.md, which is addressed to an AI coding agent ('This file provides guidance to Claude Code').
```

**Why it matters**

A human engineer (or the mobile team) landing on GitHub sees a Laravel boilerplate README and an Arabic .bat; the actual setup, ports, test DB and role model are buried in an agent-oriented file.

**Recommendation**

Add a root README.md (Arabic + English summary) with: architecture diagram, prerequisites, `make`/script one-liners for backend + frontend + tests, link to CLAUDE.md for deep architecture, CONTRIBUTING.md with the PR/commit/DoD rules, and SECURITY.md with a disclosure contact.

### Retired nested backend/.git is said to be 'backed up' but the backup location is undocumented

<a id="nested-git-backup-untraceable"></a>

`nested-git-backup-untraceable` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `CLAUDE.md:12`

**Evidence**

```text
CLAUDE.md:12: 'a stale nested `backend/.git` was retired and backed up'. `ls backend/.git` -> absent (correct). `git grep -niE 'backend/\.git|\.git\.bak|git-backup'` across CLAUDE.md, DEPLOYMENT.md, DEPLOY_LOG.md -> only that sentence; no path, no date, no hash of the retired HEAD. No zip/bak found under the repo or C:/Users/HP/SRS.
```

**Why it matters**

Pre-2026-06-25 history (the initial commit is a 192-file 'system snapshot') is unrecoverable from the repo; if the backup was on another machine it is effectively lost. Low severity because the current repo is self-sufficient, but it is a traceability gap.

**Recommendation**

Locate the backup, push it as an orphan branch `archive/backend-pre-unification` or a separate archived repo, and record its location + last commit hash in CLAUDE.md. If it cannot be found, say so explicitly in the docs.

### Architecture decisions live only in commit bodies and CLAUDE.md prose — no ADRs, no decision index

<a id="decisions-only-in-commit-bodies"></a>

`decisions-only-in-commit-bodies` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `CLAUDE.md:96`, `git log 37313bf`, `git log b2d018b`

**Evidence**

```text
`git ls-files | grep -iE 'adr|decision|rfc|docs/'` -> none. Decisions are recorded ad hoc: CLAUDE.md:96 '(approved decision)' for activate/deactivate-instead-of-delete; 37313bf body 'TeacherMiddleware يبقى يقبل الأدمن عبر API (قرار معتمد)'; b2d018b 'the week starts on Saturday (confirmed by the center's expert)'; DEPLOYMENT.md §4 lists 'future notes'. The reverse-order memorization rule, one-primary-per-center, and manager-as-sole-request-authority (a07327d, 21 files) have no dated decision record with alternatives considered.
```

**Why it matters**

As the team grows, the 'why' behind load-bearing constraints (dual role+ability tokens, no hard deletes, Saturday weeks) will be re-litigated or accidentally reversed. The mobile team needs these as first-class references, not archaeology.

**Recommendation**

Create docs/adr/ with MADR-format records, back-fill the 6-8 load-bearing decisions from CLAUDE.md and commit bodies, and require an ADR for any PR that changes auth, schema, or a business rule.

### No measurement of engineering flow (DORA), no retrospectives, no SLOs — nothing to move from level 2 to 4

<a id="no-metrics-no-retros"></a>

`no-metrics-no-retros` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `git log (cadence)`

**Evidence**

```text
Commit cadence is bursty: 22 active days across 79 calendar days; 46 commits on 2026-09-11 alone, then 0 on 09-12..09-14; monthly Jun 19 / Jul 96 / Aug 28 / Sep 81. No deployment frequency, lead-time, change-failure or MTTR data exists (DEPLOY_LOG has 8 entries, 1 done). No incident log; the only post-incident write-up style is inside commit bodies (e.g. e14452e 'Code-review sweep for issues that never surface as visible errors').
```

**Why it matters**

Without measurement the process cannot be tuned; with a mobile client in the field, change-failure rate and MTTR become customer-visible.

**Recommendation**

After CI + tagged releases exist, derive DORA metrics automatically (GitHub Actions + release tags), hold a short written retro per release, define 2-3 SLOs (API availability, p95 latency on /dashboard, OTP delivery) once monitoring exists.

## Measured facts

| Metric | Value |
|---|---|
| commits_total | 224 (2026-06-25 → 2026-09-11, 79 calendar days, 22 active days; monthly Jun 19 / Jul 96 / Aug 28 / Sep 81; max 46 on 2026-09-11) |
| authors | 3 git identities for one human (muad3719-crypto 87, muad03 82, MUTQEN 55); 223/224 commits carry a Claude Co-Authored-By trailer; 0 GPG-signed |
| branches_tags_releases | master + 2 stale unmerged remote branches (1 ahead/67 behind, 1 ahead/81 behind); 1 lightweight tag; 0 GitHub releases; 0 merge commits; 0 reverts |
| github_process | public repo, 3 collaborators, 0 PRs, 0 issues, 0 Actions workflows, 0 rulesets, branch protection 404 (none) |
| conventional_commits | 64/224 (29%) prefixed (feat 42, test 11, style 4, fix 4, chore 2, docs 1); 74 Arabic subjects, 31 of them unprefixed; 70/224 subjects >72 chars; median subject 66 chars; 223/224 have a body |
| commit_atomicity | median 2 files/commit, p90 5, 4 commits >15 files (192-file initial snapshot, 23, 21, 17) |
| test_with_code_discipline | 85 controller-touching commits; 19 (22%) include tests in the same commit; 43 more get a test commit within 4 commits → 62/85 (73%) covered by stage; 44 commits touch backend/tests |
| test_suite | 38 Feature files + 1 Unit (+2 Example), 178 test methods, 958 assertions, 4,641 test LOC vs 7,072 app LOC (0.66:1); MySQL mutqin_test DB; php -l 148/148 pass |
| frontend_untested | 33 HTML pages, 4,192 inline <script> LOC + 1,056 shared js LOC, 0 tests, 0 lint config |
| repo_size_hygiene | pack 9.92 MiB; 320 tracked files, 14.99 MiB; 66 binaries; composer.phar 3.57 MB; screenshots 4.74 MiB/36 (orphaned since initial commit); _handoff2 3.18 MiB/14; Home photos 1.03 MiB/11 (11/11 md5-duplicates of frontend-html/img/gallery); 3 stray dev files in backend root |
| deploy_drift | 68 commits since last DEPLOY_LOG 'done' (fbe25fc, 2026-09-07); log status 1 done / 7 pending; 17 commits since log last updated; 2 migrations post-deploy; 35 migrations total |
| docs_drift | CLAUDE.md 29.6 KB, 6 revisions, last 2026-09-08 (30 commits ago); claims 20 test files vs 38; 0 mentions of MessageController/AdminUserController/n8n/.cpanel.yml/webmanifest/ProductionSeeder; DEPLOYMENT.md items 3/5/6 obsolete; 2 root files describe a Blade app with 31 blade.php refs |
| secrets_hygiene | 0 .env files ever tracked in any revision; .env.example/.env.production.example placeholders only; n8n workflow uses PUT_PASSWORD_HERE; demo passwords ([redacted-demo-password]/2027) and cPanel user + DB name documented in a public repo |

## Auditor notes

Additional observations not promoted to findings: (1) DEPLOYMENT.md:15 mandates daily mysqldump backups but nothing in the repo or deploy log shows backups are scheduled — treat as an ops item for the infrastructure dimension. (2) The n8n/ automation (attendance digest) logs in with an admin email/password stored in the workflow config node rather than a scoped service token — process-wise it is fine (placeholder committed), but it creates a long-lived admin credential outside the app's audit trail. (3) The `.gitattributes` export-ignores `.github`, `CHANGELOG.md`, `.styleci.yml` — Laravel boilerplate that hints at a CI/CHANGELOG layout never adopted. (4) CLAUDE.md:35 already self-flags the stale .bat, showing awareness without a pruning habit. (5) The two unmerged `claude/*` branches were authored by an AI session (author 'Claude' on 7bf08f7) — worth a policy on how agent-produced branches are reviewed and landed. (6) I could not run `artisan test` (would write to the mutqin_test DB, and XAMPP PHP is absent on this machine); syntax lint was run with Herd's PHP 8.2.29 at C:\\Users\\HP\\.config\\herd\\bin\\php82\\php.exe — itself evidence that the docs' 'PHP exists only at C:\\xampp' is machine-specific. (7) Doc-drift note for the brief: the injected CLAUDE.md says '20 feature-test files'; the live count is 38 feature + 1 unit (+2 Laravel Example stubs) = 41 test classes. Minimal 2-week upgrade set (all S): private repo, CI workflow, branch protection + PR-only, commitlint hook, v1.0.0 tag + CHANGELOG, /api/public/version, issue tracker on, CLAUDE.md/DEPLOYMENT.md resync, .cpanel.yml excludes. Later: ADRs, Playwright, larastan level-up, DORA, retros, SLOs.
