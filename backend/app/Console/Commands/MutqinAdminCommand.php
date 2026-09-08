<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * إنشاء/إعادة ضبط حساب «مدير النظام» ببريد يختاره صاحب المشروع.
 *
 * لماذا هذا الأمر موجود؟
 *  - لا يوجد تسجيل ذاتي في النظام: كل الحسابات تُنشأ من الداخل، وحساب الأدمن
 *    الوحيد هو ما يبذره LibyanDataSeeder (admin@mutqin.ly). أي بريد آخر —
 *    بريد شخصي مثلاً — لا وجود له في القاعدة، فيردّ /auth/login بـ422
 *    «البريد الإلكتروني أو كلمة المرور غير صحيحة». هذه هي رسالة «لا يوجد
 *    مستخدم بهذا البريد» نفسها (مقصودة: لا تكشف أي البريدين مسجَّل).
 *  - استضافة الإنتاج (InfinityFree) بلا SSH/CLI/artisan — راجع DEPLOY_LOG.md —
 *    فلا يمكن تشغيل هذا الأمر هناك. لذلك يطبع الأمر أيضاً جملة SQL جاهزة
 *    تُلصق في phpMyAdmin على قاعدة الإنتاج، مبنية على نفس التجزئة (bcrypt).
 *
 * أمثلة:
 *   php artisan mutqin:admin muad0060@gmail.com --name="معاذ"
 *   php artisan mutqin:admin muad0060@gmail.com --sql-only     # لا يمسّ القاعدة المحلية
 */
class MutqinAdminCommand extends Command
{
    protected $signature = 'mutqin:admin
                            {email : بريد حساب مدير النظام}
                            {--name= : الاسم الظاهر (افتراضي: مدير النظام)}
                            {--password= : كلمة المرور (تُطلب تفاعلياً وبلا إظهار إن لم تُمرَّر)}
                            {--sql-only : لا تلمس القاعدة المحلية — اطبع جملة SQL للإنتاج فقط}';

    protected $description = 'إنشاء/إعادة ضبط حساب مدير نظام محلياً + طباعة SQL جاهزة لاستضافة بلا CLI';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('البريد الإلكتروني غير صحيح: ' . $email);
            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: 'مدير النظام'));

        $password = (string) ($this->option('password') ?: $this->secret('كلمة المرور الجديدة'));
        if (mb_strlen($password) < 8) {
            // الدخول يقبل 6 أحرف، لكن حساب الأدمن يفتح كل شيء — نشدّد هنا.
            $this->error('كلمة المرور يجب أن تكون 8 أحرف على الأقل.');
            return self::FAILURE;
        }
        if (!$this->option('password') && $password !== $this->secret('أعد كتابة كلمة المرور')) {
            $this->error('كلمتا المرور غير متطابقتين.');
            return self::FAILURE;
        }

        // تجزئة واحدة تُستخدم في القاعدة المحلية وفي جملة SQL معاً — حتى تكون
        // كلمة المرور واحدة في البيئتين بلا لبس.
        $hash = Hash::make($password);

        if ($this->option('sql-only')) {
            $this->line('');
            $this->warn('وضع --sql-only: لم تُمسّ القاعدة المحلية.');
        } else {
            $created = $this->upsertLocalUser($email, $name, $hash);
            $this->info($created
                ? "✔ أُنشئ حساب مدير نظام محلياً: {$email}"
                : "✔ أُعيد ضبط الحساب المحلي (دور=admin، نشط، توكنات مُبطَلة): {$email}");
        }

        $this->printProductionSql($email, $name, $hash);

        return self::SUCCESS;
    }

    /**
     * إنشاء المستخدم أو إعادة ضبطه محلياً.
     * الموجود مسبقاً: تُصفَّر كلمة مروره ويُرفَع إلى admin ويُفعَّل، وتُبطل توكناته
     * (S1 — نفس سلوك recordPasswordChange في بقية النظام).
     * @return bool true إن كان إنشاءً جديداً
     */
    private function upsertLocalUser(string $email, string $name, string $hash): bool
    {
        return DB::transaction(function () use ($email, $name, $hash) {
            $user = User::where('email', $email)->first();

            if (!$user) {
                User::create([
                    'name'      => $name,
                    'email'     => $email,
                    'role'      => 'admin',
                    'password'  => $hash, // خاصية hashed لا تعيد التجزئة لقيمة مجزّأة أصلاً
                    'is_active' => true,
                ]);
                return true;
            }

            $user->forceFill([
                'name'      => $name,
                'role'      => 'admin',
                'password'  => $hash,
                'is_active' => true,
                'center_id' => null, // الأدمن ليس منتسباً لمركز — وإلا منعه فحص المركز المعطَّل
                'type'      => null,
            ])->save();

            $user->recordPasswordChange('admin');

            return false;
        });
    }

    /**
     * جملة SQL جاهزة للصق في phpMyAdmin على قاعدة الإنتاج (لا artisan هناك).
     * INSERT ... ON DUPLICATE KEY UPDATE يعمل إنشاءً أو تحديثاً حسب فرادة البريد.
     */
    private function printProductionSql(string $email, string $name, string $hash): void
    {
        $q = fn (string $v) => "'" . str_replace("'", "''", $v) . "'";

        $sql = <<<SQL

-- ============================================================
-- مُتقِن — حساب مدير النظام على الإنتاج
-- نفّذه في phpMyAdmin > قاعدة الإنتاج > SQL (الاستضافة بلا CLI).
-- كلمة المرور مجزّأة bcrypt — لا تظهر هنا ولا تُخزَّن نصاً.
-- ============================================================
INSERT INTO `users` (`name`, `email`, `role`, `password`, `is_active`, `created_at`, `updated_at`)
VALUES ({$q($name)}, {$q($email)}, 'admin', {$q($hash)}, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `name`       = VALUES(`name`),
  `role`       = 'admin',
  `is_active`  = 1,
  `center_id`  = NULL,
  `type`       = NULL,
  `password`   = VALUES(`password`),
  `updated_at` = NOW();

-- إبطال أي توكنات قديمة لهذا الحساب بعد تغيير كلمة المرور (S1)
DELETE FROM `personal_access_tokens`
WHERE `tokenable_type` LIKE '%User'
  AND `tokenable_id` = (SELECT `id` FROM `users` WHERE `email` = {$q($email)} LIMIT 1);

SQL;

        $this->line($sql);
    }
}
