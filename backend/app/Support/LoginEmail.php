<?php

namespace App\Support;

use App\Models\User;

/**
 * بريد الدخول المولَّد للأدوار ذات كود العرض (محفّظ T، مدير مركز CA، ولي أمر P):
 *   {الاسم اللاتيني}_{الكود بحروف صغيرة}@mutqin.ly   —   muad_t1 · muad_ca1 · ahmed_p1
 *
 * يُبنى بعد توليد الكود (خطاف creating) داخل المعاملة نفسها، على خطوتين كنمط
 * النظام: إنشاء ببريد مؤقت ثم ضبط النهائي. لا يُقبل من العميل. الأدمن بلا كود
 * فيبقى بريده كما هو. الحسابات القائمة لا تُرحَّل.
 */
class LoginEmail
{
    public const DOMAIN = 'mutqin.ly';

    /** بريد مؤقت فريد للإدراج الأول (يُستبدل فوراً بالنهائي). */
    public static function temporary(): string
    {
        return 'pending-' . \Illuminate\Support\Str::uuid() . '@' . self::DOMAIN;
    }

    /** الصيغة النهائية من الاسم اللاتيني وكود العرض. */
    public static function build(string $latin, string $displayCode): string
    {
        return strtolower(trim($latin)) . '_' . strtolower($displayCode) . '@' . self::DOMAIN;
    }

    /**
     * يضبط البريد النهائي لمستخدم أُنشئ للتو (يحمل كوده) ويحفظه.
     * التكرار رغم الكود حالة نظرية (الكود فريد نظامياً) — إن وقعت تُوقف العملية بخطأ عربي.
     */
    public static function assign(User $user, string $latin): User
    {
        if (empty($user->display_code)) {
            throw new \RuntimeException('لا يمكن بناء بريد الدخول: المستخدم بلا كود عرض');
        }

        $email = self::build($latin, $user->display_code);
        if (User::where('email', $email)->where('id', '!=', $user->id)->exists()) {
            throw new \RuntimeException("بريد الدخول المولَّد «{$email}» مستخدم مسبقاً رغم الكود — أوقف الإنشاء وراجع إدارة النظام");
        }

        $user->forceFill(['email' => $email])->save();

        return $user;
    }
}
