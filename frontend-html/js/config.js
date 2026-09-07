/* ============================================================
   إعدادات مركزية — مُتقِن (frontend-html)
   غيّر API_BASE_URL إن غيّرت منفذ الـ API.
   ============================================================ */

// عنوان الـ API الأساسي (الباك إند Laravel) — يُحدَّد وقت التشغيل حسب المضيف:
// - تطوير محلي (localhost / 127.0.0.1): خادم artisan على المنفذ 9090.
// - الإنتاج (استضافة مشتركة، دومين واحد): مسار نسبي على نفس الدومين حيث
//   تعيش شجرة لارافيل في /backend/ — لا CORS ولا منفذ ولا اسم دومين مثبّت.
const API_BASE_URL = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')
    ? 'http://localhost:9090/api'
    : '/backend/public/api';

// جذر الموقع — يُكتشف تلقائياً من موقع هذا الملف (js/config.js)
// حتى تعمل الروابط من الصفحات الجذرية ومن المجلدات الفرعية (admin/ teacher/ parent/)
const APP_ROOT = (function () {
    const s = document.currentScript;
    if (s && s.src) return s.src.replace(/js\/config\.js(?:\?.*)?(?:#.*)?$/, '');
    return '/';
})();

// مفاتيح التخزين المحلي
const STORAGE_TOKEN = 'mutqin_token';
const STORAGE_USER  = 'mutqin_user';

window.MutqinConfig = { API_BASE_URL, APP_ROOT, STORAGE_TOKEN, STORAGE_USER };
