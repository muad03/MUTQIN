<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * بذر إنتاجي نظيف — قاعدة فارغة جاهزة للبيانات الحقيقية:
 *  - فهرس الأثمان المرجعي (بيانات ثابتة لا تجريبية).
 *  - حساب مدير النظام الوحيد admin@mutqin.ly — كلمة مروره الأولية تُقرأ من
 *    ADMIN_INITIAL_PASSWORD (متغيّر بيئة أو وسيط) ولا تُولَّد صامتة (قرار معتمد)،
 *    ويُنصح بتغييرها من صفحة الملف بعد أول دخول.
 *  - لا مراكز ولا محفّظين ولا طلاب ولا أولياء أمور ولا سجلات.
 *
 * التشغيل:  ADMIN_INITIAL_PASSWORD='...' php artisan migrate:fresh --seed --seeder=ProductionSeeder
 * (عدّادات code_sequences تبدأ من صفر بالهجرات نفسها: أول مركز C1، أول محفّظ T1..)
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) (getenv('ADMIN_INITIAL_PASSWORD') ?: env('ADMIN_INITIAL_PASSWORD', ''));
        if (strlen($password) < 8) {
            throw new \RuntimeException('حدّد كلمة مرور أولية لمدير النظام عبر ADMIN_INITIAL_PASSWORD (8 أحرف على الأقل) — لا توليد صامت.');
        }

        $this->call(AthmanSeeder::class); // idempotent

        if (User::where('email', 'admin@mutqin.ly')->exists()) {
            $this->command->warn('حساب admin@mutqin.ly موجود مسبقاً — لم يُمسّ.');
        } else {
            User::create([
                'name'     => 'مدير النظام',
                'email'    => 'admin@mutqin.ly',
                'role'     => 'admin',
                'password' => Hash::make($password),
            ]);
            $this->command->info('✓ أُنشئ حساب مدير النظام admin@mutqin.ly بكلمة المرور الأولية المحدَّدة — غيّرها بعد أول دخول.');
        }

        $this->command->info('✓ القاعدة نظيفة: فهرس الأثمان + مدير النظام فقط — لا بيانات تجريبية.');
    }
}
