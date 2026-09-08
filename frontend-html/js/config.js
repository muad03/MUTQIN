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

// ===== PWA: يُحقن في كل صفحة (config.js أول ما يُحمَّل) =====
// «إضافة للشاشة الرئيسية» تفتح الموقع كتطبيق مستقل بلا شريط متصفح،
// مع لون حالة بلون الهوية — لا service worker (لا عمل دون اتصال بعدُ عمداً).
(function () {
    const add = (tag, attrs) => {
        const el = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
        document.head.appendChild(el);
    };
    add('link', { rel: 'manifest', href: APP_ROOT + 'manifest.webmanifest' });
    add('meta', { name: 'theme-color', content: '#04532F' });
    add('meta', { name: 'mobile-web-app-capable', content: 'yes' });
    add('meta', { name: 'apple-mobile-web-app-capable', content: 'yes' });
    add('meta', { name: 'apple-mobile-web-app-status-bar-style', content: 'default' });
    add('meta', { name: 'apple-mobile-web-app-title', content: 'مُتقِن' });
    add('link', { rel: 'apple-touch-icon', href: APP_ROOT + 'images/logo.svg' });
})();
