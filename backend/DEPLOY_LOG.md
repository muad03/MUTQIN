# سجل النشر — مُتقِن على Libyan Spider (mutqin.ly)

> **المستضيف**: Libyan Spider — لوحة cPanel · المستخدم `mutqinly` · ‏PHP 8.3
> **جذر الويب**: `/home/mutqinly/public_html/` (الواجهة فيه مباشرة + `public_html/backend/` شجرة لارافيل)
> **القاعدة**: `mutqinly_mutqin` (phpMyAdmin من cPanel)
> **‏SSH**: يظهر في اللوحة لكنه **معطَّل من المستضيف** (Connection refused على 22) —
> عملياً بلا SSH / CLI / artisan / composer / git.
> **النشر يدوي حصراً** عبر cPanel File Manager (‏Upload + Extract للحزم،
> وEdit للصق الملفات المفردة). لا شيء يصل الخادم من تلقاء نفسه.
>
> (تاريخ: الاستضافة الأولى كانت InfinityFree على mutqin.xo.je — انتُقل عنها في 2026-09-08.)
>
> **ملفات خادمية لا تُدهس ولا تُحذف في أي رفعة** (أُنشئت يدوياً وليست في المستودع):
> - `public_html/backend/.env`
> - `public_html/backend/bootstrap/cache/`
> - `public_html/backend/storage/framework/cache/data/`
> - `public_html/backend/storage/framework/sessions/`
> - `public_html/backend/storage/framework/views/`
> - `public_html/backend/storage/logs/`
>
> كل commit جديد = مدخل هنا. الهجرات تُرفق بـSQL خام (لا artisan migrate على
> الخادم). مفاتيح .env الجديدة تُسرد صراحة وتُضاف للخادم يدوياً.
> **حالة الرفع يغيّرها صاحب المشروع وحده بعد الرفع الفعلي.**

---

## f868880 + fbf2d40 + def7f70 + fbe25fc — 2026-09-07 (الحالة المنشورة الأولية)
**ما تغيّر:** حزمة تجهيز النشر الأربعة: API_BASE_URL وقت التشغيل (محلي/إنتاج) · تضييق CORS عبر CORS_ALLOWED_ORIGINS بافتراضي مغلق · حجب HTTP عن شجرة لارافيل (backend/.htaccess) مع منح public/ · قالب .env.production.example
**الملفات:**
  - frontend-html/js/config.js  (modified)
  - backend/config/cors.php  (modified)
  - backend/.htaccess  (added)
  - backend/public/.htaccess  (modified)
  - backend/.env.production.example  (added)
**يحتاج رفع؟** yes — **رُفعت ضمن mutqin-upload.zip مع قاعدة الديمو mutqin-demo-2026-09-07-clean.sql**
**طريقة الرفع:** re-zip (الحزمة الكاملة الأولى) + sql (استيراد القاعدة في phpMyAdmin)
**حالة الرفع:** done

## fa75475 — 2026-09-08
**ما تغيّر:** تحديث سجل النشر بحقائق الانتقال إلى Libyan Spider (cPanel، mutqin.ly، public_html، القاعدة mutqinly_mutqin، SSH معطَّل من المستضيف) واستبدال كل ذكر لـInfinityFree/htdocs
**الملفات:**
  - backend/DEPLOY_LOG.md  (modified)
**يحتاج رفع؟** no (ملف تتبّع في المستودع)
**طريقة الرفع:** none
**حالة الرفع:** pending

## 0959ee9 — 2026-09-08
**ما تغيّر:** تجربة موبايل بنمط التطبيقات: شريط تنقل سفلي ثابت لكل دور (+«المزيد» يفتح الدرج) · النماذج صحائف سفلية بعرض كامل · حقول 16px (بلا زوم iOS) وأهداف لمس 44px+ · PWA (manifest + theme-color + إضافة للشاشة الرئيسية كتطبيق مستقل)
**الملفات:**
  - css/theme.css  (modified)
  - js/layout.js  (modified)
  - js/config.js  (modified)
  - manifest.webmanifest  (added)
**يحتاج رفع؟** yes
**طريقة الرفع:** edit-in-place للثلاثة المعدَّلة (public_html/css/theme.css و public_html/js/layout.js و public_html/js/config.js) + رفع public_html/manifest.webmanifest الجديد
**حالة الرفع:** pending

## de7e38b — 2026-09-08
**ما تغيّر:** إصلاح دخول الهاتف: كيبورد iOS يحشر مسافة بعد النقطة داخل البريد فيفشل تحقق الصيغة — الآن تُزال كل المسافات من البريد قبل الإرسال، وحقل البريد يعطّل autocapitalize/autocorrect
**الملفات:**
  - js/pages/login.js  (modified)
  - login.html  (modified)
**يحتاج رفع؟** yes
**طريقة الرفع:** edit-in-place (ملفان صغيران — لصق عبر File Manager Edit: public_html/js/pages/login.js و public_html/login.html)
**حالة الرفع:** pending

## إنشاء هذا السجل — 2026-09-07
**ما تغيّر:** إنشاء DEPLOY_LOG.md نفسه (أداة تتبّع محلية)
**الملفات:**
  - backend/DEPLOY_LOG.md  (added)
**يحتاج رفع؟** no (ملف تتبّع في المستودع — لا يلزم الخادم)
**طريقة الرفع:** none
**حالة الرفع:** pending
