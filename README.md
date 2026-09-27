# مُتقِن (MUTQEN)

نظام إدارة مراكز تحفيظ القرآن الكريم — Management System for Quran-Memorization Centers.

## نظرة عامة على المشروع (Project Overview)

يتكون مشروع مُتقِن من ثلاثة أجزاء رئيسية:
1. **`backend/`**: واجهة برمجة تطبيقات Laravel 11 REST API مع قاعدة بيانات MySQL (`mutqin_db`) تعمل على المنفذ **9090**.
2. **`flutter-app/`**: تطبيق Flutter متكامل للأجهزة المحمولة وسطح المكتب والويب يدعم الأدوار الأربعة (مدير عام، مدير مركز، محفظ، ولي أمر).
3. **`frontend-html/`**: واجهة ويب خفيفة (Vanilla HTML + Bootstrap 5 RTL + JS) تستهلك الـ API مباشرة.

---

## تشغيل المشروع (Running the System)

### 1. تشغيل الخادم الخلفي (Backend API)

تأكد من تشغيل خادم MySQL، ثم من داخل مجلد `backend/`:
```bash
# تثبيت الاعتماديات (في حال لم يتم تثبيتها مسبقاً)
php composer.phar install

# تشغيل خادم الـ API مع تحديد المضيف (0.0.0.0) على المنفذ 9090
php artisan serve --host=0.0.0.0 --port=9090
```

التحقق من عمل الخادم:
```bash
curl http://localhost:9090/api/public/stats
```

---

### 2. تشغيل تطبيق الهاتف (Flutter App)

من داخل مجلد `flutter-app/`:

```bash
# تثبيت الحزم وتوليد ملفات الترجمة (للمرة الأولى فقط)
flutter pub get
flutter gen-l10n

# التشغيل على محاكي iOS (تجاوز فحص pub.dev لتسريع الإقلاع)
flutter run --no-pub -d "iPhone 17" --dart-define=APP_ENV=dev

# التشغيل على جهاز macOS (تطبيق مكتبي)
flutter run --no-pub -d macos --dart-define=APP_ENV=dev

# التشغيل على هاتف أندرويد متصل بالـ USB
flutter run --no-pub -d <device-id> --dart-define=APP_ENV=dev --dart-define=API_BASE_URL_DEV=http://<MAC_IP>:9090/api

# التشغيل على محاكي Android
flutter run --no-pub -d android --dart-define=APP_ENV=dev --dart-define=API_BASE_URL_DEV=http://10.0.2.2:9090/api

# التشغيل على المتصفح (Chrome)
flutter run --no-pub -d chrome --dart-define=APP_ENV=dev
```

> **أزرار التحكم أثناء التشغيل من الـ Terminal:**
> - اضغط **`r`** لإجراء **Hot Reload** فوري لتحديث الواجهة.
> - اضغط **`R`** لإجراء **Hot Restart** لإعادة تهيئة حالة التطبيق.
> - اضغط **`q`** لإغلاق التطبيق.

للمزيد من تفاصيل التطوير والتحقق والاختبارات، راجع [flutter-app/README.md](flutter-app/README.md).

---

### 3. تشغيل واجهة الويب الثابتة (Frontend HTML)

من داخل مجلد `frontend-html/`:
```bash
php -S localhost:8080
```
ثم افتح المتصفح على: `http://localhost:8080/login.html`

---

## حسابات تجريبية (Demo Accounts)

كلمة المرور لجميع الحسابات التجريبية: **`mutqin2027`**

يمكن تسجيل الدخول باستخدام البريد الإلكتروني أو الرموز التعريفية المختصرة (Display Codes):

| الدور (Role) | الرمز السريع (Quick Code) | البريد الإلكتروني النموذجي | كلمة المرور |
| :--- | :--- | :--- | :--- |
| **المدير العام (Admin)** | — | `admin@mutqin.ly` | `mutqin2027` |
| **مدير المركز (Center Manager)** | `ca1` | `abdulsalam.almismari.centeradmin@mutqin.ly` | `mutqin2027` |
| **المحفظ (Teacher)** | `t1` | `mohamed.almaghrabi.2@mutqin.ly` | `mutqin2027` |
| **ولي الأمر (Parent)** | `p1` | `mohamed.almaghrabi.9@parent.mutqin.ly` | `mutqin2027` |

> **ملاحظة:** يتم تغذية الحسابات التجريبية وبيانات المراكز الليبية من خلال `LibyanDataSeeder` (`php artisan db:seed --class=LibyanDataSeeder`).

