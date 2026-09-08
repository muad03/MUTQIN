# سجل النشر — مُتقِن على InfinityFree (mutqin.xo.je)

> الخادم بلا SSH / CLI / artisan / composer / git — **كل تغيير يُرفع يدوياً**
> عبر File Manager أو FTP. لا شيء يصل الخادم من تلقاء نفسه.
>
> **ملفات خادمية لا تُدهس ولا تُحذف في أي رفعة** (أُنشئت يدوياً وليست في المستودع):
> - `htdocs/backend/.env`
> - `htdocs/backend/bootstrap/cache/`
> - `htdocs/backend/storage/framework/cache/data/`
> - `htdocs/backend/storage/framework/sessions/`
> - `htdocs/backend/storage/framework/views/`
> - `htdocs/backend/storage/logs/`
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

## de7e38b — 2026-09-08
**ما تغيّر:** إصلاح دخول الهاتف: كيبورد iOS يحشر مسافة بعد النقطة داخل البريد فيفشل تحقق الصيغة — الآن تُزال كل المسافات من البريد قبل الإرسال، وحقل البريد يعطّل autocapitalize/autocorrect
**الملفات:**
  - js/pages/login.js  (modified)
  - login.html  (modified)
**يحتاج رفع؟** yes
**طريقة الرفع:** edit-in-place (ملفان صغيران — لصق عبر File Manager Edit: htdocs/js/pages/login.js و htdocs/login.html)
**حالة الرفع:** pending

## إنشاء هذا السجل — 2026-09-07
**ما تغيّر:** إنشاء DEPLOY_LOG.md نفسه (أداة تتبّع محلية)
**الملفات:**
  - backend/DEPLOY_LOG.md  (added)
**يحتاج رفع؟** no (ملف تتبّع في المستودع — لا يلزم الخادم)
**طريقة الرفع:** none
**حالة الرفع:** pending
