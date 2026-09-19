# Localization, Arabic & Regional Rules

[← Enterprise Audit](../enterprise-audit.md)

**Score 56 / 100** — Significant risk · maturity **L2** · weight 1%

The product is consistently Arabic-first and regionally careful (33/33 pages lang=ar dir=rtl with Bootstrap RTL, Africa/Tripoli timezone pinned in Laravel and n8n, Saturday-Friday week handled explicitly, centralized Arabic text normalization, Libyan phone/national-id rules that accept both genders, ar-LY date formatting with Western digits). But there is no localization architecture at all: zero Laravel lang files while the production env template sets APP_LOCALE=ar (so any validation rule without a hand-written message renders as a raw 'validation.*' key in production and English in dev), no exception-rendering layer (default English 'Unauthenticated.' / 'No query results for model [App\Models\X]' / '(and N more errors)' leak through and break the {success,...} envelope), Arabic display strings persisted as DB enum values (users.type, weekly_tests.result), Arabic prose and web .html links persisted inside notifications, no Accept-Language negotiation and no machine-readable error codes, surah identity keyed on byte-exact Arabic spelling, ~1,660 hardcoded Arabic literals in the web client with no string table, and naive pluralization. Regional correctness earns real credit; the absence of any i18n substrate and a live production copy defect keep it in the 'significant risk' band for a CTO planning a multi-role mobile client.

## What is already strong

- RTL/lang discipline is total on the web client: all 33 HTML pages declare `<html lang="ar" dir="rtl">` (grep count 33/33), load `bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css`, and `manifest.webmanifest` carries `"lang": "ar", "dir": "rtl"`.
- Regional time rules are explicit and consistent: `config/app.php:70` `'timezone' => 'Africa/Tripoli'`; the Libyan week is coded as `now()->startOfWeek(\Carbon\Carbon::SATURDAY)` / `endOfWeek(FRIDAY)` in `DashboardController.php:71` and `ReportController.php:94-95`; the n8n digest pins `"timezone": "Africa/Tripoli"` (`n8n/mutqin-daily-attendance-digest.json:287`) and computes dates with `$now.setZone('Africa/Tripoli')` (:103); the frontend avoids the UTC-midnight bug with a local `todayStr()` (`js/ui.js:131-134`).
- Arabic text normalization is centralized and well-designed in `app/Support/ArabicText.php`: tashkeel/tatweel stripping, alef/ya/ta-marbuta/hamza unification, `normalizeQuery()` strips leading و/ف/ال, and `sqlNormalize()` mirrors the same rules inside MySQL so «احمد» matches «أحمد» — covered by `MemorizationJuzSearchTest` and `PaginationSearchTest`.
- Libyan phone handling is a single source of truth: `app/Support/PhoneNumber::normalize` maps Arabic-Indic digits, strips +218/00218, and yields `09xxxxxxxx`; it is applied on every write path (students, teachers, ParentResolver) and tested in `PhoneNormalizationTest`.
- National identity rules are nationality-aware and gender-neutral: `StudentController.php:36-38` enforces `digits:12` + `regex:/^[12]\d{11}$/` only for `nationality_type=libyan` (free `max:32` for foreigners), and the message (`:57`) explicitly documents 1=male / 2=female — so girls' centers are not blocked by the ID rule (CLAUDE.md's 'Libyan male format' is doc drift).
- Every hand-written API `message` is Arabic (82 occurrences, 74 distinct strings across `app/`), 99 custom validation message keys cover the most common failure paths, and 403s from the four middleware classes are Arabic (`AdminMiddleware.php:19` 'هذه الصفحة للمديرين فقط').
- Display codes and identifiers are bidi-isolated where rendered: `.mq-code { direction:ltr; ... }` (`css/theme.css:190-193`) plus dedicated `.pf-input--ltr`/`.pf-item-val--ltr` classes and 33 inline `direction:ltr` isolations for national IDs and generated emails.
- Frontend date display uses the correct regional locale: `toLocaleDateString('ar-LY', ...)` in `js/ui.js:126` and `js/layout.js:82` — ar-LY resolves to Gregorian with Latin digits, matching Libyan convention; PDFs render Western digits per the documented mPDF constraint and use ASCII filenames (`ReportPdfController.php:100-168`).
- Search accepts Arabic-Indic numerals for codes (`TeacherController.php:28` maps ٠-٩ then matches `T5`/`5`/`٥`), and the fingerprint import normalizes device IDs the same way (`AttendanceImportController.php:153`) and parses `DD/MM/YYYY` as the regional default (:218-220).

## Level-5 target state

A level-5 MUTQEN treats Arabic as the first of N locales: Laravel ships `lang/ar` (and later `lang/en`) with every validation, attribute and API message keyed and resolved through a `SetLocale` middleware (Accept-Language, default ar), and every error response carries a stable machine code beside the localized message. All persisted domain values are language-neutral codes (teacher type, test result, surah number, notification type+params, platform-neutral deep-link targets), with display labels resolved by clients from one shared glossary (ARB/JSON) that both the web client and the Flutter apps consume, including ICU plural forms and gender agreement. Regional rules (Africa/Tripoli, Saturday–Friday week, +218 phones, national-ID pattern, digit normalization incl. U+0660/U+06F0 ranges) live in one config exposed to clients, PDFs use the brand Arabic fonts with `ar_LY` formatting, and CI guards it all: a lint that blocks new Arabic literals in controllers, tests asserting codes not prose, and a locale-coverage report per release.

## What the Flutter team must know

What the mobile team must know: (1) The API returns Arabic prose only — no `code` field; branch on HTTP status and, until fixed, expect Laravel-default English bodies for 401 ('Unauthenticated.'), 404 ('No query results for model [App\\Models\\X] N') and the 422 summary suffix '(and N more errors)', plus raw 'validation.*' keys in production for rules without custom messages; display `errors[field][0]` but never rely on `message` alone. (2) Enum contract is mixed: send English codes for attendance (`present|absent|late`), quality (`excellent|good|average|weak`), nationality (`libyan|foreigner`), request status; but byte-exact Arabic for teacher `type` ('محفظ أساسي' | 'محفظ معاون') and test `result` ('ناجح' | 'راسب'). (3) Surahs are identified by exact Arabic name from `GET /memorizations/surahs` (array of strings, no numbers); cache that list and never transliterate or normalize before sending. (4) Dates: `date`/`exam_date`/attendance `time` are wall-clock Africa/Tripoli values without offset ('YYYY-MM-DD', 'HH:mm:ss'); `created_at`/`updated_at` are ISO-8601 UTC ('…Z'). Week is Saturday–Friday; use `Intl.defaultLocale = 'ar_LY'` (Latin digits, Gregorian) and format on device; ignore `created_ago`. (5) Normalize Arabic-Indic and Extended Arabic-Indic digits client-side before sending national IDs, id_number, OTP, login codes and page numbers — the server only normalizes phones and search. (6) Notifications carry web `link` paths ('parent/child.html?id=12', 'manager/requests.html'); build a mapping table to routes and parse ids from the query string; title/body are pre-rendered Arabic. (7) Fonts: bundle Cairo (body) and Amiri (display); use `Directionality`/`\\u2068…\\u2069` to isolate codes like S12/T5/CA1 and generated emails. (8) Write ARB strings with ICU plurals from day one (Arabic zero/one/two/few/many/other); an `en` ARB is only meaningful after the backend adds message codes and Accept-Language support.

## Findings — 14 live, 1 refuted

| Finding | Severity | Verified | When | Effort |
|---|---|---|---|---|
| [No Laravel lang/ar files while production sets APP_LOCALE=ar — uncovered validation rules render as raw 'validation.*' keys](#no-lang-files-raw-keys-in-prod) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [No API exception-rendering layer: default English 401/404/422-summary messages leak and break the {success,...} envelope](#no-exception-renderer-english-defaults) | 🟠 high<br>_reviewers → medium_ | ✅ confirmed | NOW | S |
| [Arabic display strings are persisted as domain enum values (teacher type, test result) and compared as literals across both tiers](#arabic-strings-as-db-enums) | 🟠 high<br>_reviewers → low_ | ✅ confirmed | NOW | M |
| [API speaks only Arabic prose — no stable error/message codes and no Accept-Language handling; tests pin exact Arabic copy](#no-error-codes-no-locale-negotiation) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | M |
| [Arabic-Indic digit normalization is partial (phone + search only) and copy-pasted 7 times; identity fields, login codes and OTP reject ٠-٩ input](#arabic-indic-digits-partial) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [In-app notifications persist Arabic prose, web .html links and server-formatted relative time — not consumable by a localized mobile client](#notifications-persist-prose-and-web-links) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NOW | S |
| [Web client hardcodes ~1,660 Arabic literals/text nodes across 33 pages with no string table, glossary or i18n scaffolding](#frontend-no-string-table) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | L |
| [PDF reports use DejaVu Sans for Arabic (not the Amiri/Cairo brand fonts) and the generic Carbon 'ar' locale instead of 'ar_LY'](#pdf-fonts-and-carbon-ar-locale) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | NEXT | S |
| [Count phrases use a single Arabic form regardless of number (Arabic has 6 CLDR plural categories)](#naive-arabic-pluralization) | 🟡 medium<br>_reviewers → low_ | ✅ confirmed | LATER | S |
| [Regional constants (week start, day/month names, phone country code, ID regex) are scattered literals rather than one configuration](#regional-rules-scattered) | ⚪ low | ℹ️ informational | NEXT | S |
| [Fingerprint import contract is bound to exact Arabic header/status words and assumes DD/MM/YYYY without validating the parsed date](#import-contract-locale-bound) | ⚪ low | ℹ️ informational | NEXT | S |
| [No Hijri (Umm al-Qura) calendar anywhere for a Quran-memorization product](#no-hijri-calendar) | ⚪ low | ℹ️ informational | LATER | M |
| [RTL/bidi handled via 33 inline `direction:ltr` duplications and physical CSS properties; brand fonts loaded by render-blocking external @import](#rtl-css-inline-duplication-and-fonts) | ⚪ low | ℹ️ informational | LATER | S |
| [Parent-facing copy assumes a son ('ابنك') while the ID rule accepts girls; CLAUDE.md misstates the gender constraint and the email scheme](#gendered-copy-and-doc-drift) | ⚪ low | ℹ️ informational | LATER | S |

### No Laravel lang/ar files while production sets APP_LOCALE=ar — uncovered validation rules render as raw 'validation.*' keys

<a id="no-lang-files-raw-keys-in-prod"></a>

`no-lang-files-raw-keys-in-prod` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/config/app.php:83`, `backend/config/app.php:85`, `backend/.env.production.example:29`, `backend/.env.example:7`, `backend/app/Http/Controllers/Api/AttendanceController.php:52`, `backend/app/Http/Controllers/Api/CenterController.php:35`, `backend/app/Http/Controllers/Api/StudentController.php:196`

**Evidence**

```text
`ls backend/lang backend/resources/lang` → neither exists (only resources/{css,js,views}); `git log --all -- backend/lang backend/resources/lang` → empty (never existed). config/app.php:83 `'locale' => env('APP_LOCALE', 'en')`, :85 `'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en')`. .env.production.example:29-30 `APP_LOCALE=ar` / `APP_FALLBACK_LOCALE=ar`. AttendanceController.php:52-56 `$request->validate(['date' => 'required|date', 'attendance' => 'required|array', 'confirm' => 'nullable|boolean'])` with no message array; CenterController.php:35-40 covers only `name.required` (not `name.max`, `city.max`); StudentController.php:196-228 has ~25 rules and ~18 messages (e.g. `nationality_type.in`, `center_id.exists`, `phone.max`, `age.integer`, `guardian_email.max` uncovered). Repo-wide: 99 custom message keys vs ≈265 rule tokens. Laravel's Translator returns the key itself when neither locale nor fallback has the group, so in production these surface as literal `validation.required` / `validation.max`; in dev (locale en) they surface in English — the two environments disagree, so developers never see the production behaviour.
```

**Why it matters**

End users (and every Flutter screen that displays `errors[field][0]`) will see 'validation.max' or English text for a large share of validation failures once real data hits edge cases (long names, invalid enum values, bad dates). It also blocks any second language: there is no translation substrate to extend.

**Recommendation**

Publish `lang/ar/validation.php`, `lang/ar/auth.php`, `lang/ar/passwords.php` (e.g. via `laravel-lang/common` or `artisan lang:publish` + Arabic set) with an `attributes` map for every field name (الاسم، رقم الهاتف…); set `APP_LOCALE=ar` and `APP_FALLBACK_LOCALE=ar` in `.env.example` too so dev == prod; add a feature test that triggers an uncovered rule and asserts the message contains no 'validation.' prefix and no Latin letters. Move per-controller message arrays into `lang/ar/validation.php` `custom` keys over time.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The factual claims check out. `backend/lang` and `backend/resources/lang` do not exist and are not git-tracked; `config/app.php:83,85` default locale/fallback to `en`; `.env.example:7-8` = en/en while `.env.production.example:29-30` = ar/ar. Laravel 11's FileLoader searches the app lang path plus the framework's bundled `Translation/lang` dir, which ships only `en`; with locale=ar and fallback=ar neither locale has the `validation` group, so `Translator::get('validation.max')` returns the key and `Validator::getMessage()` falls through to the raw `validation.max` string. The frontend (`ui.js:84,186`) prints `errors[field][0]` verbatim, and `api.js:66` surfaces `data.message`, which for `$request->validate()` is Laravel's summarize() — first error plus an untranslated English "(and N more errors)" suffix. Cited controllers confirm the gaps: AttendanceController.php:52-56 has no message array; CenterController.php:35-40 covers only `name.required`; StudentController.php:196-228 covers ~9 of ~25 rules (no messages for `name.max`, `nationality_type.in`, `center_id.exists`, `phone.max`, `age.integer/between`, `guardian_email.max`, `guardian_name.max`, etc.). No mitigations found: bootstrap/app.php has no exception renderer or locale middleware; the 37 feature-test 422 assertions check status only, never message text; phpunit.xml sets no locale, so tests run under `en` and would not catch the raw-key behaviour anyway. Could not execute PHP to demonstrate live (no vendor/ in this checkout), so confirmation is by source reading of the framework behaviour. Severity is overrated though: this is a UX/copy defect on edge-case validation failures (the most common failures — required fields and the custom domain rules — do have Arabic messages, and the HTML forms carry `required` attributes), with no security, data-integrity, or availability impact, in an Arabic-only single-country product. Medium is appropriate.

```text
backend/config/app.php:83 `'locale' => env('APP_LOCALE', 'en')`, :85 `'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en')`; backend/.env.example:7-8 `APP_LOCALE=en` / `APP_FALLBACK_LOCALE=en`; backend/.env.production.example:29-30 `APP_LOCALE=ar` / `APP_FALLBACK_LOCALE=ar`; `git ls-files | grep lang/` → nothing, no backend/lang or resources/lang directory. backend/app/Http/Controllers/Api/AttendanceController.php:52-56 validate() with no messages; CenterController.php:35-40 messages only `name.required`; StudentController.php:196-228 ~25 rules, ~9 messages + nationalIdMessages(). frontend-html/js/ui.js:84 and :186 render `errors[field][0]` verbatim; frontend-html/js/api.js:66 uses `data.message` (Laravel summarize(): first error + English "(and N more errors)"). backend/bootstrap/app.php: withExceptions() empty, no locale middleware. tests/Feature: 37 `assertStatus(422)`/`assertJsonValidationErrors` calls, none assert message text; phpunit.xml sets no APP_LOCALE.
```

</details>

### No API exception-rendering layer: default English 401/404/422-summary messages leak and break the {success,...} envelope

<a id="no-exception-renderer-english-defaults"></a>

`no-exception-renderer-english-defaults` · 🟠 high (reviewers → medium) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/bootstrap/app.php:22`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:52`, `backend/app/Http/Controllers/Api/CenterController.php:58`, `frontend-html/js/api.js:63`, `frontend-html/js/ui.js:202`

**Evidence**

```text
bootstrap/app.php:22-24 `->withExceptions(function (Exceptions $exceptions): void { // })` (empty); `ls app/Exceptions` → 'no app/Exceptions'; `grep -rn renderable|ModelNotFoundException app bootstrap` → none. `grep -rn 'findOrFail|firstOrFail' app` → 37 sites, e.g. WeeklyTestController.php:52 `Student::findOrFail($request->student_id)`. With Laravel defaults these return `{"message":"No query results for model [App\\Models\\Student] 7"}` (English, leaks class name, no `success` key); Sanctum 401 returns `{"message":"Unauthenticated."}`; ValidationException returns `{message, errors}` without `success`, and its summary appends the untranslated `(and :count more errors)` → e.g. 'اسم الطالب مطلوب (and 2 more errors)'. The web client hides this only because api.js:63 treats `!res.ok` as failure and ui.js:202 `toast(err.message || 'فشل الحفظ', 'danger')` shows the mixed-language summary.
```

**Why it matters**

A Flutter client parsing the documented envelope strictly will mis-handle these responses, and users will read English/mixed strings on the most common error classes (expired session, stale record, multi-field validation). Model class names are exposed in 404 bodies.

**Recommendation**

In `withExceptions` register renderers for `ValidationException`, `AuthenticationException`, `ModelNotFoundException`/`NotFoundHttpException`, `AuthorizationException`, `ThrottleRequestsException`, `HttpException` that always emit `{success:false, code:'VALIDATION_FAILED'|'UNAUTHENTICATED'|'NOT_FOUND'|..., message: __('errors.'.$code), errors}` when the request expects JSON; drop the 'and N more' summary in favour of `message = first error`. Add a test per exception class asserting the envelope and Arabic text.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: medium

The cited evidence is accurate. backend/bootstrap/app.php:22-24 has an empty withExceptions closure; there is no app/Exceptions directory; no renderable/ModelNotFoundException handling anywhere in app/, bootstrap/, or routes/; there is no lang/ or resources/lang directory; and config/app.php:83-85 defaults locale and fallback_locale to 'en' (.env.example:7 also sets APP_LOCALE=en). WeeklyTestController.php:52 and CenterController.php:58 do call findOrFail. With Laravel 11 defaults on a JSON request: ModelNotFoundException is converted to NotFoundHttpException carrying the message "No query results for model [App\Models\Student] N" (emitted even with APP_DEBUG=false because it is an HttpException), AuthenticationException yields {"message":"Unauthenticated."}, and ValidationException::summarize appends the untranslated "(and :count more errors)" to the first Arabic message; none of these carry the `success` key. I could not execute the handler to confirm empirically (vendor/ is absent and no XAMPP PHP exists on this machine), so this rests on Laravel 11 framework behaviour, which is well established. Mitigations found: frontend-html/js/api.js:51-57 replaces every 401 with an Arabic message and redirects, and api.js:64 treats !res.ok as failure so the missing `success` key is harmless for the web client; ui.js:201-202 still renders per-field Arabic errors, but the toast shows the mixed-language summary, and a stale-record 404 toast shows the raw English "No query results for model [...]" string. The finding is therefore real but overstated: the only client in the repo is the web client, which already handles 401 and the envelope shape; the Flutter-client scenario is hypothetical; the model-class-name leak is low sensitivity; the remaining user-visible defects are the "(and N more errors)" suffix on multi-field validation toasts and English text on rare stale-record 404s. Note also a related, broader issue the auditor did not name: with locale 'en' and no lang files, any validation rule lacking a custom message (e.g. WeeklyTestController.php:37-50 only customizes 5 of its rules) produces a fully English field error. Severity should be medium, not high.

```text
backend/bootstrap/app.php:22-24 empty withExceptions; no backend/app/Exceptions dir, no backend/lang dir; backend/config/app.php:83 'locale' => env('APP_LOCALE','en'), :85 fallback 'en'; backend/.env.example:7 APP_LOCALE=en; backend/app/Http/Controllers/Api/WeeklyTestController.php:37-50 custom messages cover only 5 rules (others fall back to English framework text), :52 Student::findOrFail; frontend-html/js/api.js:51-57 overrides 401 with Arabic + redirect (mitigates the Unauthenticated case), :64-70 uses !res.ok so missing `success` key does not break the web client, but passes data.message (English 404 / mixed validation summary) through; frontend-html/js/ui.js:201-202 shows per-field errors then toasts err.message. No Flutter/mobile client exists in the repo.
```

</details>

### Arabic display strings are persisted as domain enum values (teacher type, test result) and compared as literals across both tiers

<a id="arabic-strings-as-db-enums"></a>

`arabic-strings-as-db-enums` · 🟠 high (reviewers → low) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/database/migrations/2026_05_11_100154_update_tables_for_mutqen_v2.php:13`, `backend/database/migrations/2026_05_11_100154_update_tables_for_mutqen_v2.php:23`, `backend/database/migrations/2026_06_19_074100_create_weekly_test_questions_table.php:19`, `backend/app/Http/Controllers/Api/TeacherController.php:66`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:42`, `backend/app/Http/Controllers/Api/CenterController.php:62`, `backend/app/Support/PrimaryTeacherRule.php:20`, `backend/app/Services/ReportService.php:55`, `frontend-html/admin/center.html:115`, `frontend-html/js/ui.js:108`

**Evidence**

```text
Migration :13 `$table->enum('type', ['محفظ أساسي', 'محفظ معاون'])`, :23 `$table->enum('result', ['ناجح', 'راسب'])`, weekly_test_questions :19 same. Validation `'type' => 'required|in:محفظ أساسي,محفظ معاون'` (TeacherController.php:66, CenterManagerController.php:123/403), `'questions.*.result' => 'required|in:ناجح,راسب'` (WeeklyTestController.php:42/133). Ordering by Arabic literal: CenterController.php:62 `->orderByRaw("FIELD(type,'محفظ أساسي','محفظ معاون')")`. Business rule keyed on Arabic: PrimaryTeacherRule.php:20 `if ($type !== 'محفظ أساسي')`. ReportService.php:55 `$tests->where('result', 'ناجح')`. Frontend: ui.js:108 `return result === 'ناجح' ? badge('ناجح','success') : badge('راسب','danger')`, admin/center.html:115 `t.type === 'محفظ أساسي'`. Counts: 22 backend + 13 frontend literal comparisons. By contrast attendance status, memorization quality, nationality_type, request status and roles are English codes (`present|absent|late`, `excellent|good|average|weak`, `libyan|foreigner`, `pending|approved|rejected`).
```

**Why it matters**

These values are part of the API contract, so any client must send byte-exact Arabic (a hamza or spacing variant → 422). Adding English or any second UI language is impossible without a data migration, and the inconsistency (half codes, half Arabic) makes the contract hard to document for the mobile team.

**Recommendation**

Introduce language-neutral codes (`primary|assistant`, `pass|fail`) as the canonical API values now: add computed `type_code`/`result_code` to responses and accept both code and legacy Arabic on input via a small normaliser (`Rule::in(['primary','assistant','محفظ أساسي','محفظ معاون'])` + mapping before persistence). Then migrate the enum columns and update PrimaryTeacherRule/ReportService/frontend in one pass; keep display labels client-side.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Every cited line exists and reads as quoted: enum('type', ['محفظ أساسي','محفظ معاون']) and enum('result', ['ناجح','راسب']) in the migrations; `in:` validation rules with the Arabic literals in TeacherController/WeeklyTestController; FIELD() ordering in CenterController; PrimaryTeacherRule keyed on `$type !== 'محفظ أساسي'`; ReportService counting `where('result','ناجح')`; frontend literal comparisons in ui.js and center.html. No mitigation exists: models have no casts/accessors mapping these to codes, and there is no normaliser for hamza/spacing variants. So the finding is factually correct and not handled.

However, it is not a correctness defect in the deployed system: the only client (frontend-html) sends the values from fixed <select>/option lists whose values are byte-identical to the validation list (admin/teachers.html:25, manager/teachers.html:109, teacher/weekly-tests.html:79-80), so the 422-on-variant path is never hit in practice; the DB enum guarantees the two tiers can never disagree on stored values; and the feature tests (ManagerAddTeacherTest, WeeklyTestUpdateTest, ManagerReportsScopeTest, etc.) exercise these exact literals so any drift would be caught. The product is explicitly Arabic-only/single-country and a second UI language is not a stated requirement. The impact is therefore an API-contract/maintainability and future-i18n concern (a hypothetical mobile client must copy the exact strings), not a live behaviour bug or data-integrity risk. 'high' overstates it; 'low' (design/tech-debt) is the appropriate rating. The recommendation (dual-accept codes, computed *_code fields) is reasonable if a mobile client or i18n is actually planned, otherwise it is optional.

```text
Confirmed as quoted: backend/database/migrations/2026_05_11_100154_update_tables_for_mutqen_v2.php:13,23; 2026_06_19_074100_create_weekly_test_questions_table.php:19; TeacherController.php:66; WeeklyTestController.php:42; CenterController.php:62; PrimaryTeacherRule.php:20,25; ReportService.php:55-56; frontend-html/js/ui.js:108; frontend-html/admin/center.html:115. Mitigating context: frontend sends values only from fixed option lists with identical bytes — frontend-html/admin/teachers.html:25, frontend-html/manager/teachers.html:109, frontend-html/teacher/weekly-tests.html:79-80; feature tests using the literals: backend/tests/Feature/ManagerAddTeacherTest.php, WeeklyTestUpdateTest.php, ManagerReportsScopeTest.php, ManagerTeacherPerformanceTest.php. No casts/accessors on User.php:23, WeeklyTest.php:13, WeeklyTestQuestion.php:13 (plain fillable).
```

</details>

### API speaks only Arabic prose — no stable error/message codes and no Accept-Language handling; tests pin exact Arabic copy

<a id="no-error-codes-no-locale-negotiation"></a>

`no-error-codes-no-locale-negotiation` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/AuthController.php:42`, `backend/app/Http/Middleware/AdminMiddleware.php:19`, `backend/tests/Feature/AuthLoginTest.php:31`, `backend/tests/Feature/CenterStatusTest.php:66`, `backend/tests/Feature/ManagerAttendanceReviewTest.php:60`

**Evidence**

```text
`grep -rn 'Accept-Language|setLocale|getPreferredLanguage' app bootstrap routes` → no matches; `ls app/Http/Middleware` → only the four role gates (no SetLocale). 82 responses hardcode `'message' => '<Arabic>'` (74 distinct) with no `code` field, e.g. AuthController.php:42-46 `'message' => 'هذا الحساب غير نشط، راجع إدارة المركز', 'errors' => ['email' => [...]]`. Tests assert prose: AuthLoginTest.php:31 `->assertJsonPath('message', 'بيانات الدخول غير صحيحة')`, CenterStatusTest.php:66, ManagerAttendanceReviewTest.php:60 `->assertJsonPath('message', 'خارج نطاق صلاحيتك')` (5 such assertions).
```

**Why it matters**

A mobile client cannot branch on outcomes (inactive account vs inactive center vs bad credentials all 403/401 with different prose), cannot localize, and any copy edit breaks tests. Enterprise clients need machine-readable codes to drive UX and analytics.

**Recommendation**

Add `code` to the envelope (`AUTH_INVALID_CREDENTIALS`, `ACCOUNT_INACTIVE`, `CENTER_INACTIVE`, `FORBIDDEN_SCOPE`, `PRIMARY_TEACHER_EXISTS`, …) via a `ApiResponse::error($code, $status, $errors)` helper that resolves `message` through `__("api.$code")` from `lang/ar/api.php`; add a `SetLocale` middleware reading `Accept-Language` (allow-list ar, default ar); switch tests to assert `code`. This is the prerequisite for an `en` ARB set in Flutter.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Evidence verified as factually correct: no Accept-Language/setLocale anywhere in backend/app, bootstrap, routes (grep empty); app/Http/Middleware contains only the four role gates; ~90 hardcoded `'message' => '<Arabic>'` responses and zero `'code' =>` fields in app/Http; no lang/ directory exists at all; the cited test lines (AuthLoginTest.php:31, CenterStatusTest.php:66, ManagerAttendanceReviewTest.php:60) do pin exact Arabic prose (10 `assertJsonPath('message', ...)` assertions total). Nothing elsewhere mitigates it (no ApiResponse helper, no FormRequest layer, no translation files). However the severity is overstated for this product: CLAUDE.md declares the system Arabic/RTL-only for one country; the only real client (frontend-html) never branches on message prose — api.js just surfaces `data.message` to a toast and branches solely on HTTP status (api.js:51, ui.js:358), so there is no current correctness defect or breakage path. The "mobile client cannot branch" impact is hypothetical (no such client exists), and HTTP statuses already separate bad credentials (422) from inactive account/center (403). The concrete present-day cost is limited to test brittleness on copy edits (10 assertions) and a missing prerequisite for a future i18n/mobile roadmap. This is an architecture/enhancement gap, not a bug, so low rather than medium. Side note found while checking: config/app.php:83 sets locale 'en' with no lang/ar, so any validation rule lacking a custom message (36 validate() calls, 58 custom message blocks) would emit English framework text — a related but separate localization finding.

```text
backend/app/Http/Controllers/Api/AuthController.php:43-47 hardcoded Arabic message + errors.email, no code field; backend/app/Http/Middleware/AdminMiddleware.php:17-21 same; grep for 'code' => in backend/app/Http → 0 hits; backend/lang and backend/resources/lang do not exist; config/app.php:83 locale='en' (no Arabic translation set); tests/Feature: 10 assertJsonPath('message', …) prose assertions; frontend-html/js/api.js:51,67 and js/ui.js:358 branch only on HTTP status and merely display data.message — no client depends on prose, so no current runtime defect.
```

</details>

### Arabic-Indic digit normalization is partial (phone + search only) and copy-pasted 7 times; identity fields, login codes and OTP reject ٠-٩ input

<a id="arabic-indic-digits-partial"></a>

`arabic-indic-digits-partial` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Support/PhoneNumber.php:19`, `backend/app/Http/Controllers/Api/TeacherController.php:28`, `backend/app/Http/Controllers/Api/AdminUserController.php:46`, `backend/app/Http/Controllers/Api/CenterManagerController.php:228`, `backend/app/Http/Controllers/Api/MemorizationController.php:38`, `backend/app/Http/Controllers/Api/StudentController.php:292`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:153`, `backend/app/Http/Controllers/Api/AuthController.php:36`, `backend/app/Http/Controllers/Api/StudentController.php:38`

**Evidence**

```text
The literal map `strtr($q, ['٠'=>'0','١'=>'1',…,'٩'=>'9'])` appears at TeacherController:28, AdminUserController:46, CenterManagerController:228, MemorizationController:38, StudentController:292, AttendanceImportController:153 and PhoneNumber.php:19-22 (7 copies; `grep -rn 'x{0660}|06F0' app` → 0, so Extended Arabic-Indic ۰-۹ used by Persian/Urdu keyboards is never handled). Writes are not normalized: StudentController.php:38 `['digits:12', 'regex:/^[12]\d{11}$/']` on `national_id`/`guardian_id_number` with no `$request->merge` of normalized digits, so `١١٩٩٠١٢٣٤٥٦٧` fails with the misleading message 'الرقم الوطني يجب أن يكون 12 رقماً'. Login: AuthController.php:36 `whereRaw('UPPER(display_code) = ?', [mb_strtoupper($login)])` — `T٥` never matches although search accepts it. ParentResolver.php:33 uses `$g['id_number']` raw.
```

**Why it matters**

Arabic mobile keyboards default to Arabic-Indic digits in several Android/iOS locales; parents and teachers on phones will hit confusing 422s on national IDs and fail to log in with codes. Duplicated maps drift (one path handles ۰-۹ someday, others not).

**Recommendation**

Add `ArabicText::toWesternDigits(?string)` covering U+0660-0669 and U+06F0-06F9, replace the 7 copies, and normalize `national_id`, `id_number`, `parent_id_number`, `guardian_id_number`, `otp`, `email`/login code, `age`, `page_from/to` via a `NormalizeDigits` middleware on the api group (or FormRequest `prepareForValidation`). Document for Flutter that normalization also happens server-side.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The factual claims check out on the actual code paths. (1) The literal ٠-٩→0-9 strtr map exists verbatim in 7 places: TeacherController.php:28, AdminUserController.php:46, CenterManagerController.php:228, MemorizationController.php:38, StudentController.php:292, AttendanceImportController.php:153 and PhoneNumber.php:19-22; grep for U+06F0/۰ across app/ returns nothing. (2) Writes are not normalized: StudentController::studentIdentityRules/parentIdentityRules (lines 33-50) apply ['digits:12','regex:/^[12]\d{11}$/'] directly to request input with no merge/prepareForValidation, and there is no global middleware — bootstrap/app.php only registers the four role aliases, and app/Http/Middleware holds only those four classes. ParentResolver::resolve (line 33) uses $g['id_number'] raw while phone goes through PhoneNumber::normalize. (3) AuthController::login line 36 matches UPPER(display_code) against the raw trimmed input, so "T٥" cannot match "T5"; forgotPasswordVerify (line 169) uses 'digits:6' on otp, which rejects Arabic-Indic digits. (4) No frontend guard: ui.js:56 normalizes digits only inside normalizeSearch (client-side table filtering); formModal submits raw values, and the students forms only use inputmode="numeric". No feature test covers Arabic-Indic input on write paths (only MemorizationJuzSearchTest covers search). So the finding is real and unmitigated. However the severity is overstated for this product: MUTQEN is single-country (Libya), where Western digits are the everyday and official norm on IDs, phones and keyboards (unlike Egypt/Gulf), so ٠-٩ entry into national-ID fields is an edge case rather than the default; Extended Arabic-Indic (Persian/Urdu) is irrelevant here; login codes are Latin-prefixed (T/CA/P) so mixing an Arabic digit requires a deliberate keyboard switch; the failure mode is a 422 with an Arabic message, not data corruption or a security hole; and the most common Arabic-digit entry point (search) is already handled. The duplication is a maintainability smell, not a defect. Corrected severity: low.

```text
backend/app/Http/Controllers/Api/StudentController.php:33-50 (digits:12 / regex applied to raw national_id and guardian_id_number; no normalization); backend/app/Support/ParentResolver.php:33 ($idNumber = $g['id_number'] ?? null, raw, vs phone normalized on :34); backend/app/Http/Controllers/Api/AuthController.php:35-37 (display_code lookup on raw input) and :169 ('otp' => 'required|digits:6'); backend/bootstrap/app.php:15-20 (only role middleware aliases, no digit-normalizing middleware); backend/app/Http/Middleware/ contains only Admin/CenterManager/Parent/Teacher middleware; frontend-html/js/ui.js:56 (digit normalization only in client-side normalizeSearch, not on form submit); 7 identical strtr maps confirmed at the cited lines; grep -rn '06F0|۰' backend/app → 0 hits; only backend/tests/Feature/MemorizationJuzSearchTest.php:49 tests Arabic-digit input (search path).
```

</details>

### In-app notifications persist Arabic prose, web .html links and server-formatted relative time — not consumable by a localized mobile client

<a id="notifications-persist-prose-and-web-links"></a>

`notifications-persist-prose-and-web-links` · 🟡 medium (reviewers → low) · ✅ confirmed · **NOW** · effort S (<1 day)

**Files:** `backend/app/Notifications/InAppNotification.php:40`, `backend/app/Http/Controllers/Api/MemorizationController.php:191`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:98`, `backend/app/Http/Controllers/Api/StudentRequestController.php:184`, `backend/app/Http/Controllers/Api/MessageController.php:188`, `backend/app/Http/Controllers/Api/NotificationController.php:30`

**Evidence**

```text
InAppNotification.php:40-46 stores `{type, title, body, ref_id, link}` where title/body are final Arabic strings: MemorizationController.php:194-198 `'تسجيل حفظ جديد', 'سجّل المحفّظ حفظاً جديداً لابنك «' . $student->name . '»: سورة ' . $request->surah_name … 'parent/child.html?id=' . $student->id`; WeeklyTestController.php:98 `'تم تسجيل اختبار أسبوعي لابنك «…»'`; StudentRequestController.php:184 `'manager/requests.html'`; MessageController.php:188 `'/messages.html?student=' . $student->id`; ManagerManagementController.php:192 `'admin/managers.html'`. NotificationController.php:30 `'created_ago' => Carbon::parse($n->created_at)->locale('ar')->diffForHumans()` formats presentation text on the server.
```

**Why it matters**

Mobile cannot deep-link from `parent/child.html?id=…`, cannot re-render history in another language or with correct gender ('ابنك' for a daughter), and relative time computed server-side ignores the device clock/locale. Historical notifications are frozen in one wording forever.

**Recommendation**

Store `type` + structured `params` (`student_id`, `student_name`, `surah_name`, `result`, `request_id`) and a platform-neutral `target` (`{screen:'child', id:12}`); render `title/body` at read time via `__("notifications.$type.title", $params)` (web keeps Arabic) and let clients map `target` to routes. Drop `created_ago`; clients format from `created_at` (ISO-8601 UTC).

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 80% · corrected severity: low

The quoted code is accurate: InAppNotification::toArray (lines 39-45) persists {type,title,body,ref_id,link} with fully rendered Arabic title/body; MemorizationController.php:191-199, WeeklyTestController.php:94-101, StudentRequestController.php:180-187, MessageController.php:181-189 and ManagerManagementController.php:187-194 all pass final Arabic prose and web paths ('parent/child.html?id=…', 'manager/requests.html', 'admin/managers.html', '…/messages.html?student=…'); NotificationController.php:30 emits server-formatted 'created_ago' in Arabic. So the factual description is correct and the design is indeed web-and-Arabic-bound. However the impact is speculative: there is no mobile client anywhere in the repo (no flutter/react-native/android/ios references in any .md or code), no i18n infrastructure at all (no backend/lang or resources/lang directory, zero __()/trans() calls in app/, config locale 'en' unused), and the product is explicitly Arabic-only/single-country per CLAUDE.md. The frontend already consumes the shape as designed (layout.js:207-241 prefixes link with APP_ROOT and shows created_ago), and 'created_at' is also returned alongside 'created_ago' (NotificationController.php:29), so a future client could format time itself. The 'ابنك for a daughter' gender point is a real wording issue (national ID accepts 1=male/2=female per StudentController.php:29,57, and students carry no gender column), but it is a copy nit, not a localization defect. Tests (StudentRequestNotificationTest.php:49) assert the web link string, so the schema is deliberate and locked. Net: a legitimate architectural-debt observation for a hypothetical mobile/multilingual future, no functional impact today. Real but overrated; downgrade to low.

```text
backend/app/Notifications/InAppNotification.php:39-45 (stores rendered title/body + web link); backend/app/Http/Controllers/Api/NotificationController.php:29-30 (returns both created_at ISO and created_ago Arabic string — clients are not forced to use created_ago); frontend-html/js/layout.js:207,213,241 (sole consumer; APP_ROOT + link, displays created_ago); backend/tests/Feature/StudentRequestNotificationTest.php:49 asserts link 'teacher/students.html'; no backend/lang directory, no __()/trans() usage in backend/app, no mobile client in repo; students have no gender column (national_id first digit 1/2 is the only gender signal, StudentController.php:29,57).
```

</details>

### Web client hardcodes ~1,660 Arabic literals/text nodes across 33 pages with no string table, glossary or i18n scaffolding

<a id="frontend-no-string-table"></a>

`frontend-no-string-table` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort L (1–2 weeks)

**Files:** `frontend-html/admin/students.html`, `frontend-html/index.html`, `frontend-html/manager/students.html`, `frontend-html/js/ui.js`, `frontend-html/js/layout.js`, `frontend-html/admin/reports.html:24`, `frontend-html/teacher/reports.html:24`

**Evidence**

```text
Counts (LC_ALL=C.UTF-8 grep): 1,247 non-comment lines containing Arabic in html+js; 844 quoted Arabic literals inside JS/HTML strings; 816 Arabic text nodes (`>…<`). Top files: admin/students.html 137 lines, index.html 107, manager/students.html 101, js/ui.js 82, admin/teachers.html 75. `grep -rniE 'i18n|translate|__\(|t\(' js/` → nothing. Month names duplicated verbatim: admin/reports.html:24 and teacher/reports.html:24 `const MONTHS = ['يناير','فبراير',…]`; enum labels live in ui.js (`qualityBadge` :98-106, `attendanceBadge`, `resultBadge`).
```

**Why it matters**

Acceptable for a single-language web admin, but there is no canonical glossary for the Flutter team to build ARB files from — they will re-transcribe copy from HTML, drift from the web, and terminology (محفّظ/معاون/ثمن/جزء) will diverge across four role apps. Any future `en` UI means touching all 33 pages.

**Recommendation**

Extract a single JSON/ARB glossary (`i18n/ar.json`) of UI strings and domain labels (roles, teacher types, attendance/quality/result labels, month/day names), consumed by the web via a tiny `t(key)` helper and by Flutter via `intl` ARB generation; start with the shared enum/label layer (ui.js badges, layout.js nav, formModal buttons) rather than a big-bang rewrite.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

The factual evidence holds: with LC_ALL=C.UTF-8, 1,483 lines across the 40 html/js files in frontend-html/ contain Arabic script (the auditor's 1,247 non-comment figure is in the same range); `grep -rniE 'i18n|translate|__\(|\bt\('` over js/ matches only a CSS `translateX` in ui.js:14, so there is no string table or t() helper; `const MONTHS = [...]` is duplicated verbatim at admin/reports.html:24 and teacher/reports.html:24; enum labels are hardcoded in ui.js attendanceBadge (:89), qualityBadge (:98), resultBadge (:108). There is no mitigation elsewhere either: backend/ has no lang/ directory and its controllers/services hardcode the same Arabic enum labels (19 hits for ممتاز/جيد جداً/حاضر/غائب in Api controllers + Services), so the API is not a substitute glossary. However the stated impact is largely speculative: a repo-wide search for "flutter" or ".arb" returns nothing, so the "Flutter team / four role apps" scenario is not grounded in this codebase, and CLAUDE.md defines the product as Arabic/RTL-only for a single country. Hardcoded Arabic in a deliberately single-language vanilla-JS admin client is a maintainability/duplication observation (drift risk between the two MONTHS arrays, badge labels vs backend labels), not a correctness or user-facing defect. It is real but over-rated; low, not medium.

```text
frontend-html: 1,483 Arabic-bearing lines across 40 html/js files (LC_ALL=C.UTF-8 grep -P '[\x{0600}-\x{06FF}]'). No i18n scaffolding: only match for i18n|translate|t( in js/ is the CSS transform at js/ui.js:14. Duplicated month table: admin/reports.html:24 and teacher/reports.html:24 (identical `const MONTHS = ['يناير',...]`). Enum labels hardcoded in js/ui.js:89 attendanceBadge, :98 qualityBadge, :108 resultBadge. Backend has no backend/lang directory and hardcodes the same Arabic enum labels in app/Http/Controllers/Api/*.php and app/Services/*.php (19 occurrences), so there is no canonical glossary on either side. No Flutter/ARB references exist anywhere in the repo, so the cross-platform-drift impact is hypothetical.
```

</details>

### PDF reports use DejaVu Sans for Arabic (not the Amiri/Cairo brand fonts) and the generic Carbon 'ar' locale instead of 'ar_LY'

<a id="pdf-fonts-and-carbon-ar-locale"></a>

`pdf-fonts-and-carbon-ar-locale` · 🟡 medium (reviewers → low) · ✅ confirmed · **NEXT** · effort S (<1 day)

**Files:** `backend/resources/views/pdf/layout.blade.php:6`, `backend/resources/views/pdf/layout.blade.php:24`, `backend/app/Http/Controllers/Api/ReportPdfController.php:31`, `backend/app/Http/Controllers/Api/ReportPdfController.php:50`, `backend/app/Http/Controllers/Api/NotificationController.php:30`, `frontend-html/css/theme.css:17`

**Evidence**

```text
layout.blade.php:6 `body { font-family: dejavusans; … }` and :24 `.amiri { font-family: dejavusans; }` (the class named after the brand font maps to DejaVu). ReportPdfController.php:50-62 constructs `new \Mpdf\Mpdf([...])` with no `fontDir`/`fontdata` for Amiri or Cairo, relying on `autoLangToFont = true`. Web brand: theme.css:17 `--font-display:'Amiri',serif; --font-body:'Cairo',sans-serif`. Period label: ReportPdfController.php:31 `Carbon::create($year, $month, 1)->locale('ar')->isoFormat('MMMM YYYY')` — Carbon's generic `ar` locale (inherited from Moment) uses the combined Levantine/Egyptian month names (e.g. 'أيلول سبتمبر'), whereas Libyan usage and the frontend (`MONTHS = ['يناير',…]`) use 'سبتمبر'; Carbon ships `ar_LY`. (vendor/ is not checked in, so verify the rendered label locally.) NotificationController.php:30 also uses `->locale('ar')`.
```

**Why it matters**

Printed/exported reports are the artefact parents and ministry contacts keep; DejaVu Arabic looks unprofessional next to the web brand and the month label may read as foreign. Inconsistent locale identifiers (`ar` server vs `ar-LY` client) invite subtle divergences (digits, month names).

**Recommendation**

Bundle Amiri + Cairo TTFs (both OFL) into mPDF via `fontDir`/`fontdata` and set them in layout.blade.php; standardise on `ar_LY` for every Carbon `locale()` call (or better, return ISO dates and let clients format); add a snapshot test of `periodLabel()` output.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 72% · corrected severity: low

Evidence verified as quoted. backend/resources/views/pdf/layout.blade.php:6 sets `body { font-family: dejavusans; ... }` and :24 `.amiri { font-family: dejavusans; }`; the `.amiri` class is not referenced by any PDF template (student.blade.php, teacher-group.blade.php, admin/*) — it is dead CSS, so that half of the evidence is a nit, not a defect. ReportPdfController.php:50-62 builds mPDF with only mode/format/font-size/tempDir/margins — no `fontDir`/`fontdata`, and there is no fonts directory in the repo (resources/fonts, public/fonts, storage/fonts all absent), so no Amiri/Cairo TTF is bundled; the web brand (theme.css:17) indeed uses Amiri/Cairo. periodLabel() (:31) uses `->locale('ar')->isoFormat('MMMM YYYY')`; NotificationController.php:30 uses `->locale('ar')->diffForHumans()`. composer.lock pins nesbot/carbon 3.11.4, whose Moment-derived `ar` locale uses the combined Levantine/Egyptian month names (e.g. 'أيلول سبتمبر'), while the frontend (admin/reports.html:24, teacher/reports.html:24) uses plain 'سبتمبر'; Carbon ships `ar_LY` with the plain names. I could NOT execute this locally: vendor/ is not installed in this checkout and C:\xampp\php\php.exe does not exist in this environment, so the rendered label is asserted from knowledge of Carbon's ar.php, not observed. No test covers periodLabel (ManagerReportsTest only asserts 200 + application/pdf). Mitigations/exaggerations: mPDF's bundled DejaVu Sans has full Arabic glyph coverage and mPDF applies OTL shaping, so the PDF is legible and correct — this is branding polish, not a rendering bug; `ar` vs `ar_LY` for diffForHumans produces the same relative-time strings (ar_LY inherits ar), so the NotificationController citation has no user-visible effect; the product is single-country/Arabic-only so 'locale identifier inconsistency' is not a real divergence risk beyond the one month-name label. The only concrete user-visible defect is the month label wording in printed reports. Real but overrated: low, not medium.

```text
backend/resources/views/pdf/layout.blade.php:6 `body { font-family: dejavusans; ... }`; :24 `.amiri { font-family: dejavusans; }` — `.amiri` is unused by any template under resources/views/pdf/ (dead CSS). backend/app/Http/Controllers/Api/ReportPdfController.php:31 `Carbon::create($year, $month, 1)->locale('ar')->isoFormat('MMMM YYYY')` — Carbon 3.11.4 (composer.lock:2678-2679) `ar` locale yields combined month names ('أيلول سبتمبر') vs frontend-html/admin/reports.html:24 and teacher/reports.html:24 `MONTHS = ['يناير',…,'سبتمبر',…]`. ReportPdfController.php:50-59 mPDF options contain no fontDir/fontdata; no fonts directory exists in backend/. NotificationController.php:30 `->locale('ar')->diffForHumans()` — no observable difference vs ar_LY. tests/Feature/ManagerReportsTest.php:82-83 only asserts 200/application/pdf; no test of periodLabel. Not executable here: backend/vendor absent and C:\xampp\php\php.exe not present.
```

</details>

### Count phrases use a single Arabic form regardless of number (Arabic has 6 CLDR plural categories)

<a id="naive-arabic-pluralization"></a>

`naive-arabic-pluralization` · 🟡 medium (reviewers → low) · ✅ confirmed · **LATER** · effort S (<1 day)

**Files:** `frontend-html/admin/center.html:84`, `frontend-html/admin/center.html:88`, `frontend-html/admin/students.html:285`, `frontend-html/manager/students.html:356`, `frontend-html/manager/teacher.html:89`, `frontend-html/parent/child.html:115`, `frontend-html/teacher/student.html:146`, `frontend-html/js/ui.js:243`

**Evidence**

```text
12 sites of `${n} <noun>`: admin/center.html:84 `${st.attendance_month?.total ?? 0} سجلاً`, :88 `… اختباراً`; admin/students.html:285 and manager/students.html:356 `${p.children_count} أبناء)`; manager/teacher.html:89 `${students.length} طالباً نشطاً`; teacher/student.html:146 `${x.questions?.length || 0} أثمان`; ui.js:243 `${r.ignored_other_teachers} سجلاً`. `grep -rn 'Intl.PluralRules|plural' js/` → none. Renders '1 أبناء', '2 سجلاً', '0 اختباراً' — ungrammatical (correct: ابن واحد، ابنان، 3 أبناء، 11 ابناً).
```

**Why it matters**

Visible quality signal to Arabic-speaking customers and inspectors; the same shortcut, if copied into Flutter, multiplies across four apps. ICU plurals are free in ARB — but only if the copy is written with plural cases from day one.

**Recommendation**

Add `UI.count(n, {zero, one, two, few, many, other})` using `new Intl.PluralRules('ar')` on the web; in Flutter use ICU `{count, plural, =0{…} =1{…} =2{…} few{…} many{…} other{…}}` in ARB for every count string in the glossary.

<details><summary>Verification — 1 independent reviewer</summary>

**Upheld** · confidence 85% · corrected severity: low

Evidence verified at every cited line: admin/center.html:84 `… من ${total} سجلاً`, :88 `… ${total} اختباراً`; admin/students.html:285 and manager/students.html:356 `(${p.children_count} أبناء)`; manager/teacher.html:89 `${students.length} طالباً نشطاً`; parent/child.html:115 `${tsum.total ?? 0} اختباراً`; teacher/student.html:146 `${x.questions?.length || 0} أثمان`; js/ui.js:243 `تم تجاهل ${r.ignored_other_teachers} سجلاً`. No `Intl.PluralRules`, no plural helper, and no ternary plural forms anywhere under frontend-html/. No mitigation exists in backend, tests, or UI helpers — this is purely presentation copy. So the finding is factually correct, but overstated in severity: (1) the chosen forms are the tamyiz-singular forms (سجلاً / اختباراً / طالباً) that are grammatically correct for 11–99 and widely accepted colloquially for larger numbers, so most rendered values (monthly attendance totals, ignored rows, test counts) read correctly; the clearly wrong cases are 1, 2 and 3–10 (e.g. "1 أبناء", "3 سجلاً", "0 اختباراً"). (2) The guardian-picker `أبناء` case is the most visible one since sibling counts are typically 1–2. (3) The "multiplies across four Flutter apps" impact is speculative — there is no Flutter/ARB code in the repo. (4) The product is an internal, single-country admin tool; no functional, data, or security consequence. Real but cosmetic: low severity.

```text
Confirmed as cited: frontend-html/admin/center.html:84,88; admin/students.html:285; manager/students.html:356; manager/teacher.html:89; parent/child.html:115; teacher/student.html:146; js/ui.js:243. Additional context: manager/teacher.html:89 already hides the phrase when count is 0 but still renders "1 طالباً نشطاً". No plural helper or Intl.PluralRules exists in frontend-html/js/. No Flutter/ARB sources exist in the repository, so the "four apps" impact claim is unsupported by the codebase.
```

</details>

### Regional constants (week start, day/month names, phone country code, ID regex) are scattered literals rather than one configuration

<a id="regional-rules-scattered"></a>

`regional-rules-scattered` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/DashboardController.php:71`, `backend/app/Http/Controllers/Api/ReportController.php:94`, `backend/app/Http/Controllers/Api/StudentController.php:778`, `backend/app/Http/Controllers/Api/StudentController.php:38`, `backend/app/Http/Controllers/Api/StudentController.php:50`, `backend/app/Support/PhoneNumber.php:31`, `frontend-html/admin/reports.html:24`, `frontend-html/teacher/reports.html:24`

**Evidence**

```text
Saturday/Friday week appears twice as literals (DashboardController.php:71, ReportController.php:94-95) with the comment 'الأسبوع الليبي: السبت → الجمعة (مؤكَّد من خبير المركز)'; `$dayNames = ['الأحد', …]` inline at StudentController.php:778; the national-id regex `/^[12]\d{11}$/` is repeated at :38 and :50; country code handling `'00218'`/`'218'` inline in PhoneNumber.php:31-34; month names duplicated in two frontend pages. No `config/mutqin.php` or equivalent (`ls backend/config` shows only framework files).
```

**Why it matters**

Scaling to dozens of centers (or a second country) means hunting literals; a future rule change (e.g., ministry changes ID format, a center works Sunday–Thursday) requires edits in several controllers and both clients.

**Recommendation**

Create `config/mutqin.php` (`week_start`, `week_end`, `phone.country_code`, `national_id.pattern`, `locale`, `digits_policy`) and a `Regional` support class; expose the non-secret subset via `/api/public/config` so web and Flutter read the same values.

### Fingerprint import contract is bound to exact Arabic header/status words and assumes DD/MM/YYYY without validating the parsed date

<a id="import-contract-locale-bound"></a>

`import-contract-locale-bound` · ⚪ low · ℹ️ informational · **NEXT** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/AttendanceImportController.php:60`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:71`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:218`, `backend/app/Http/Controllers/Api/AttendanceImportController.php:262`

**Evidence**

```text
:60-65 `$h(1) === 'الاسم' && $h(2) === 'التاريخ' && $h(3) === 'الوقت'`, `$h(4) === 'الحالة'` — exact equality, no `ArabicText::normalize` (an 'الإسم' header or English device headers are rejected with :71 'ترويسة ملف Excel غير مطابقة'). :262-266 `$statusMap = ['حاضر' => 'present', 'غائب' => 'absent', 'متأخر' => 'late']` exact keys. :218-220 `if (preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}$/', $dateStr)) { … sprintf('%04d-%02d-%02d', $parts[2], $parts[1], $parts[0]); }` — a US-formatted export '09/14/2026' matches and yields '2026-14-09' with no `checkdate()`.
```

**Why it matters**

Devices sold in Libya ship with mixed-language firmware; a header in English or a hamza variant blocks the whole upload, and a US-format date silently produces invalid rows (DB error or zero date) instead of a per-row Arabic error.

**Recommendation**

Normalize headers/statuses with `ArabicText::normalize` and accept an English alias set; validate parsed dates with `checkdate()` and add a per-row reason for month>12; document the accepted file dialects in the manager UI.

### No Hijri (Umm al-Qura) calendar anywhere for a Quran-memorization product

<a id="no-hijri-calendar"></a>

`no-hijri-calendar` · ⚪ low · ℹ️ informational · **LATER** · effort M (1–3 days)

**Files:** `backend/app/Http/Controllers/Api/ReportPdfController.php:31`, `frontend-html/js/layout.js:82`, `frontend-html/js/ui.js:126`

**Evidence**

```text
`grep -rniE 'hijri|هجري|umalqura|islamic' --include=*.php --include=*.js --include=*.html .` → 0 code hits (only seeder person names). All date rendering is Gregorian: layout.js:82 `toLocaleDateString('ar-LY', { weekday:'long', year:'numeric', month:'long', day:'numeric' })`, ui.js:126, PDF period label Gregorian `MMMM YYYY`.
```

**Why it matters**

Not a defect — Libya runs civil life on the Gregorian calendar — but Quran centers schedule around Ramadan, Eid and the Islamic school year; monthly reports, Ramadan intensives and Hijri-year certificates are plausible customer asks. Deciding now avoids a schema change later.

**Recommendation**

Product decision to record explicitly. If wanted: keep storing ISO Gregorian dates, add a dual Gregorian/Hijri display option in clients (`Intl.DateTimeFormat('ar-LY-u-ca-islamic-umalqura')` on web, `hijri`/`intl` in Flutter) and an optional Hijri month filter on reports.

### RTL/bidi handled via 33 inline `direction:ltr` duplications and physical CSS properties; brand fonts loaded by render-blocking external @import

<a id="rtl-css-inline-duplication-and-fonts"></a>

`rtl-css-inline-duplication-and-fonts` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `frontend-html/css/theme.css:6`, `frontend-html/css/theme.css:17`, `frontend-html/css/theme.css:190`, `frontend-html/manager/students.html:130`, `frontend-html/admin/students.html:382`, `frontend-html/manager/requests.html:102`, `frontend-html/login.html:50`

**Evidence**

```text
`grep -rnoE 'direction:\s*ltr' --include=*.html --include=*.js --include=*.css .` → 33 hits, 28 of them inline styles such as manager/students.html:130 `<span style="direction:ltr;display:inline-block;font-weight:600;color:#04532F;">${UI.escapeHtml(s.national_id)}</span>` (identical block at admin/students.html:382), vs 5 reusable classes in theme.css. theme.css physical vs logical: 9 `margin/padding-left|right`/`left:`/`right:` vs 1 `inline-start/end`; login.html:50 `style="padding-left:44px;"`. Select options cannot carry spans: manager/requests.html:102 label `${s.display_code} — ${s.name}` has no bidi isolate (`&lrm;`/`⁨` never used: grep → 0). theme.css:6 `@import url('https://fonts.googleapis.com/css2?family=Amiri…&family=Cairo…')`; fallbacks are generic `serif`/`sans-serif` with no Arabic-capable system font.
```

**Why it matters**

Maintainability of RTL correctness across 33 pages is by copy-paste; a single class change won't propagate. The PWA ('display: standalone') depends on Google Fonts at runtime — on a weak Libyan mobile connection text flashes fallback glyphs and offline mode (planned per config.js comment) would lose the brand type entirely.

**Recommendation**

Promote the inline isolations to `.mq-ltr`/`.mq-code` utilities, wrap mixed strings in `<bdi>` or U+2068/U+2069 for option labels, migrate theme.css to logical properties, self-host Amiri/Cairo woff2 with `font-display: swap` and an Arabic-capable fallback stack (`'Cairo','Segoe UI','Noto Sans Arabic',Tahoma,sans-serif`).

### Parent-facing copy assumes a son ('ابنك') while the ID rule accepts girls; CLAUDE.md misstates the gender constraint and the email scheme

<a id="gendered-copy-and-doc-drift"></a>

`gendered-copy-and-doc-drift` · ⚪ low · ℹ️ informational · **LATER** · effort S (<1 day)

**Files:** `backend/app/Http/Controllers/Api/MemorizationController.php:195`, `backend/app/Http/Controllers/Api/WeeklyTestController.php:98`, `backend/app/Http/Controllers/Api/StudentController.php:57`, `backend/app/Support/LoginEmail.php:27`, `CLAUDE.md:68`, `CLAUDE.md:85`

**Evidence**

```text
MemorizationController.php:195 `'سجّل المحفّظ حفظاً جديداً لابنك «' . $student->name . '»…'`, WeeklyTestController.php:98 `'تم تسجيل اختبار أسبوعي لابنك «…»'` — masculine for every student; `grep -rn 'gender|الجنس' app database/migrations` → no gender column to drive agreement. StudentController.php:57 message documents `1 (ذكر) أو 2 (أنثى)` and the regex `^[12]\d{11}$` accepts both, yet CLAUDE.md:68 says 'national_id is optional, unique, Libyan male format'. CLAUDE.md:85/:165 say email scheme `{latin}.centeradmin@mutqin.ly` but LoginEmail.php:27 builds `strtolower($latin) . '_' . strtolower($displayCode) . '@' . 'mutqin.ly'` (e.g. `muad_ca1@mutqin.ly`), and ManagerManagementController.php:60 merely strips a pasted `.centeradmin` suffix.
```

**Why it matters**

Girls' halaqat are a large share of Libyan Quran centers; 'your son' in every notification to a daughter's parent is a credibility issue. Stale docs about identity and email formats will mislead the mobile team writing validation and login UX.

**Recommendation**

Add an optional `gender` (or derive from Libyan ID first digit when libyan) and select agreement forms via the message table (`ابنك/ابنتك`), or use neutral wording ('للطالب/ة «…»'). Update CLAUDE.md lines 25, 68, 85, 165 (tests=40 files, ID accepts 1|2, email `{latin}_{code}@mutqin.ly`).

## Refuted by verification (1) — kept for transparency

### Surah identity in the API is the byte-exact Arabic name — no numeric surah key, no normalization on input

<a id="surah-identity-exact-arabic-name"></a>

`surah-identity-exact-arabic-name` · 🟡 medium · ❌ refuted · **NOW** · effort M (1–3 days)

**Files:** `backend/app/Support/SurahReference.php:20`, `backend/app/Http/Controllers/Api/MemorizationController.php:134`, `backend/app/Http/Controllers/Api/MemorizationController.php:234`, `backend/database/migrations/2024_01_01_000040_create_memorizations_table.php`

**Evidence**

```text
SurahReference.php:20 `public const SURAHS = ['الفاتحة' => 1, 'البقرة' => 1, … ]` keyed by Arabic spelling incl. hamza forms ('الأنعام', 'الإسراء'). MemorizationController.php:134 `'surah_name' => ['required','string', Rule::in(array_keys(SurahReference::SURAHS))]` — exact match, `ArabicText::normalize` is not applied, so 'الانعام' → 422 'اسم السورة غير معروف'. :229-235 `surahs()` returns `array_keys(SurahReference::SURAHS)` — names only, no `{number, name}`. `memorizations.surah_name` is a `string` column; progress is computed by matching names (`SurahReference::progress`).
```

**Why it matters**

Every client must embed or fetch the canonical Arabic spellings and never localize/transliterate them; an English UI cannot show 'Al-An'am' without a parallel table; typos or hamza variants from any non-web client silently produce 422s. The stable, universal key (1–114 mushaf order) already exists in the reference but is not exposed.

**Recommendation**

Return `[{number, name_ar, juz_start, juz_end}]` from `/memorizations/surahs`; accept `surah_number` (preferred) or `surah_name` normalized through `ArabicText::normalize` on write; add a `surah_number` column backfilled from the name so reports/progress key on the integer. Same for `weekly_test_questions.eighth_start` (free `string`).

<details><summary>Verification — 1 independent reviewer</summary>

**Refuted** · confidence 70% · corrected severity: low

The quoted evidence is accurate: SurahReference::SURAHS (SurahReference.php:20-44) is keyed by canonical Arabic spellings; MemorizationController::store (line 133) validates surah_name with Rule::in(array_keys(SURAHS)) without ArabicText::normalize; surahs() (lines 229-236) returns names only; memorizations.surah_name is a plain string column. However, as a CORRECTNESS finding it does not hold up and the product-fit mitigations are complete for this system:
1. The only client is frontend-html, and teacher/memorization.html:34/47 populates a `<select>` from `/memorizations/surahs`, so the browser can only ever submit a canonical spelling. There is no non-web client, no English UI, and no transliteration requirement in this Arabic-only, single-country product.
2. The strict exact-match is a deliberate, documented decision (controller comment at lines 131-132: a typo previously slipped through silently and fell out of progress computation). Rejecting a non-canonical spelling with a 422 and an Arabic message ('اسم السورة غير معروف — اختر سورة من القائمة') is the intended fail-closed behavior, not a silent failure as the impact text claims. MemorizationValidationTest covers this store validation.
3. The read/progress path already normalizes: SurahReference::progress() (lines 166-173) runs every stored name through Athman::normalize -> ArabicText::normalize before matching, so hamza/alef variants in legacy data still count toward progress in MemorizationController::studentsProgress and ReportService (lines 288, 328, 429). The one residual exact-match read path is the `?juz=` filter (namesOfJuz whereIn at MemorizationController.php:31/46), which only matters for pre-validation legacy rows with non-canonical spellings — a data-quality edge, not an API design defect.
The recommendation (numeric surah key, {number,name} payload, backfilled surah_number column) is a reasonable API-ergonomics/i18n roadmap item, but it addresses a hypothetical future client that does not exist. Nothing behaves incorrectly today; medium severity is not justified. Treat as informational/low design note.

```text
frontend-html/teacher/memorization.html:34 `{ name: 'surah_name', type: 'select', options: surahs.map(s => ({ value: s, label: s })) }` and :47 `API.get('/memorizations/surahs')` — the sole client submits canonical names from a dropdown. backend/app/Http/Controllers/Api/MemorizationController.php:131-133 — exact-match is deliberate ("خطأ إملائي كان يمرّ بصمت فيسقط السجل من حساب التقدّم"). backend/app/Support/SurahReference.php:166-173 — progress() normalizes stored names via Athman::normalize (ArabicText::normalize, Athman.php:32-34), so read-side matching is not byte-exact. Residual exact-match read path: MemorizationController.php:31,46 `whereIn('surah_name', SurahReference::namesOfJuz(...))`. Tests: backend/tests/Feature/MemorizationValidationTest.php covers store validation with canonical names.
```

</details>

## Measured facts

| Metric | Value |
|---|---|
| HTML pages with lang="ar" dir="rtl" | 33 / 33 |
| Laravel lang files (lang/ or resources/lang) | 0 (never existed in git history) |
| Configured locale | dev: en/en (config/app.php:83, .env.example:7); prod template: ar/ar (.env.production.example:29-30) |
| validate() calls in controllers | 36 |
| Custom Arabic validation message keys | 99 (vs ≈265 rule tokens incl. non-failing nullable/string) |
| Hardcoded Arabic 'message' => responses | 82 occurrences, 74 distinct |
| Backend PHP lines with Arabic string literals (non-comment) | 392 |
| Frontend Arabic quoted literals / Arabic text nodes / lines with Arabic | 844 / 816 / 1,247 |
| Arabic-Indic digit map copies | 7 (6 controllers + PhoneNumber); Extended Arabic-Indic (U+06F0-06F9) handled: 0 |
| Arabic enum literal comparisons | 22 backend + 13 frontend (users.type, weekly_tests.result) |
| Inline direction:ltr isolations vs CSS classes | 28 inline / 5 classes (33 total) |
| Physical vs logical CSS direction properties in theme.css | 9 vs 1 |
| findOrFail/firstOrFail sites (default English 404) | 37 |
| Naive count+noun pluralization sites | 12 |
| Tests asserting exact Arabic message prose | 5 assertJsonPath('message', …) + 18 assertJsonValidationErrors |
| Feature/Unit test files | 38 + 2 = 40 (CLAUDE.md says 20) |
| Hijri calendar references in code | 0 |
| PDF views / Arabic font used | 7 blade views / dejavusans (brand fonts Amiri+Cairo unused in PDF) |
| External runtime font/CSS dependencies | Google Fonts @import (Amiri, Cairo), jsDelivr bootstrap.rtl.min.css 5.3.3 on 33 pages |
| Accept-Language / SetLocale handling | none |

## Auditor notes

Additional lower-priority observations not listed as findings: (a) `js/ui.js:124 fmtDate` does `new Date('YYYY-MM-DD')` which parses as UTC midnight — correct in Libya (UTC+2) but shows the previous day for any viewer west of UTC (diaspora parents). (b) Phone inputs are rendered as plain `type: 'text'` (no `type: 'tel'`/`inputmode`; `inputmode` appears only in forgot-password.html and manager/students.html), so Arabic mobile keyboards default to Arabic-Indic digits — compounding the digit finding. (c) `ArabicText::sqlNormalize` wraps the column in 9 nested REPLACE() calls, making normalized name search non-sargable at scale (a stored `name_norm` column with an index is the usual fix — performance dimension). (d) `StudentController::show` (:777-786) returns both Arabic labels (`status`, `day`) and raw codes (`status_raw`) — a good pattern that should be applied consistently (ReportService.php:244 emits `'حضور منخفض'` reasons without a code). (e) Percentages are rendered as `${n}%` without locale-aware placement. (f) The design handoff in `_handoff2/` fixes fonts/colors/RTL but contains no i18n or terminology guidance. Doc drift confirmed against code: CLAUDE.md:25 '20 feature-test files' (actual 38 Feature + 2 Unit); CLAUDE.md:68 'Libyan male format' (regex accepts 1 or 2); CLAUDE.md:85/165 email scheme `{latin}.centeradmin@mutqin.ly` (code: `LoginEmail::build` → `{latin}_{code}@mutqin.ly`); CLAUDE.md does not mention that config locale is `en` in dev and that no lang files exist. Caveat: `vendor/` is not present in the checkout, so claims about Laravel's translator fallback and Carbon's generic `ar` month names are based on framework source knowledge and should be confirmed by one local request/tinker run.
