/* ============================================================
   صفحة تسجيل الدخول — مُتقِن
   ============================================================ */
(function () {
    // إن كان مسجّلاً مسبقاً، حوّله للوحته
    Auth.redirectIfLoggedIn();

    const form    = document.getElementById('login-form');
    const submit  = document.getElementById('login-submit');
    const alertEl = document.getElementById('login-alert');

    // إظهار/إخفاء كلمة المرور
    UI.bindPasswordToggle('password', 'toggle-pass');

    function showAlert(msg) {
        alertEl.textContent = '✕ ' + msg;
        alertEl.style.display = 'flex';
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        alertEl.style.display = 'none';
        UI.setFieldErrors(null);

        // إزالة كل المسافات لا الأطراف فقط: كيبورد الهاتف (iOS خاصة) يحشر مسافة
        // بعد النقطة داخل العنوان فيفشل تحقق الصيغة — والبريد لا يحوي مسافات شرعاً
        const email = document.getElementById('email').value.replace(/\s+/g, '');
        const password = document.getElementById('password').value;

        submit.disabled = true;
        const original = submit.textContent;
        submit.textContent = 'جارٍ الدخول...';

        try {
            const user = await Auth.login(email, password);
            UI.toast('تم تسجيل الدخول بنجاح', 'success');
            Auth.redirectByRole(user.role);
        } catch (err) {
            // أخطاء حقول التحقق (422) تُعرض تحت الحقول، وإلا تنبيه عام
            if (err.errors && Object.keys(err.errors).length) {
                UI.setFieldErrors(err.errors);
            }
            showAlert(err.message || 'تعذّر تسجيل الدخول');
        } finally {
            submit.disabled = false;
            submit.textContent = original;
        }
    });
})();
