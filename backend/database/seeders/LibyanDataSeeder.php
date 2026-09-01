<?php

namespace Database\Seeders;

use App\Models\Center;
use App\Models\User;
use App\Support\PhoneNumber;
use Database\Seeders\Data\LibyanNames;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * البذر الليبي الشامل — يستبدل بيانات الديمو القديمة بالكامل ببيانات توليدية
 * ليبية تغطي كل حقل يقبل بيانات (4 مراكز، 14 محفّظاً، 130 طالباً، ~70 ولي أمر،
 * حضور 8 أسابيع، حفظ وختمات حسابية، اختبارات، طلبات نقل، رسائل، إشعارات).
 *
 * قواعد حاكمة (لا تُخالَف):
 *  - يعمل في بيئة local حصراً — يتوقف بخطأ عربي خارجها.
 *  - display_code عبر App\Support\DisplayCode حصراً (بادئات C/CA/T/S)؛
 *    أولياء الأمور والأدمن بلا كود (NULL بتصميم مقصود).
 *  - الهاتف يُطبَّع بـ App\Support\PhoneNumber إلى 09xxxxxxxx.
 *  - attendances: unique(student_id,date) — التواريخ تُولَّد بحلقة مضمونة
 *    الفرادة لا بالعشوائية، وcenter_id يُملأ دائماً.
 *  - الأسبوع يبدأ السبت (Carbon::SATURDAY) والتواريخ بتوقيت Africa/Tripoli.
 *  - الحفظ يُتحقق منه عبر SurahReference (اكتمال الجزء = اكتمال كل سوره)،
 *    والختمة حسابية: 114 سورة كاملة يرصدها progress() تلقائياً.
 *  - قاعدة «أساسي واحد لكل مركز» تُفحص برمجياً بعد البذر — الاختلال يوقف
 *    البذر بخطأ عربي.
 *  - لا بذر لجداول revisions/tajweed_evaluations (بلا مستهلك)، ولا مساس بأثمان.
 */
class LibyanDataSeeder extends Seeder
{
    // ============================================================
    // كلمات المرور الموحّدة الجديدة — لا توليد صامت (قرار معتمد).
    // تُخزَّن عبر Hash::make دائماً وتُطبع في الـconsole عند نهاية البذر.
    // ============================================================
    public const ADMIN_PASSWORD   = 'mutqin2027';
    public const MANAGER_PASSWORD = 'mutqin2027';
    public const TEACHER_PASSWORD = 'mutqin2027';
    public const PARENT_PASSWORD  = 'mutqin2027';

    // نطاقات محجوزة للبذر الليبي — منفصلة كلياً عن نطاقات الـseeders القديمة
    // (DatabaseSeeder: هواتف 0913x/0914x وأرقام 1990000000xx —
    //  ExtraDataSeeder: هواتف 09255/09266/0918x وأرقام 1700000000xx)
    public const NATIONAL_ID_BASE = 150000000000; // تُسبق بـ 1 (ذكر) — synthetic
    public const PHONE_PREFIXES   = ['0912', '0925', '0944', '0945'];

    // ما تُنشئه المراحل ويحتاجه اللاحق منها (داخل نفس التشغيلة)
    private ?User $admin = null;
    /** @var Center[] */
    private array $centers = [];            // بالترتيب: بلال بن رباح، الفويهات، القوارشة، المرج
    /** @var array<int, User[]> مفهرسة بمعرّف المركز */
    private array $teachersByCenter = [];
    /** @var User[] المدراء النشطون مفهرسون بمعرّف المركز */
    private array $managersByCenter = [];
    private int $phoneSeq = 0;              // عدّاد هواتف فريدة من النطاقات المحجوزة

    /** هاتف ليبي فريد `09xxxxxxxx` من النطاقات المحجوزة — حتمي لا عشوائي. */
    private function nextPhone(): string
    {
        $n = $this->phoneSeq++;
        $prefix = self::PHONE_PREFIXES[$n % count(self::PHONE_PREFIXES)];

        return PhoneNumber::normalize($prefix . str_pad((string) (300000 + intdiv($n, 4)), 6, '0', STR_PAD_LEFT));
    }

    /**
     * إنشاء مستخدم ببريد نهائي {نقحرة}.{id}@domain — بخطوتين كنمط النظام
     * (البريد النهائي يحتاج id لا يوجد إلا بعد الإدراج). display_code يحجزه
     * خطاف User::creating تلقائياً (T/CA حسب الدور) — لا يُكتب يدوياً.
     */
    private function makeUser(string $arabicName, string $latin, string $role, string $password, array $attrs = [], string $domain = 'mutqin.ly'): User
    {
        $u = User::create(array_merge([
            'name'     => $arabicName,
            'email'    => 'tmp-' . Str::random(16) . '@mutqin.ly',
            'phone'    => $this->nextPhone(),
            'role'     => $role,
            'password' => Hash::make($password),
        ], $attrs));
        $u->email = "{$latin}.{$u->id}@{$domain}";
        $u->save();

        return $u;
    }

    public function run(): void
    {
        // حارس البيئة: البذر الليبي لبيئة التطوير المحلية حصراً
        if (! app()->environment('local')) {
            throw new \RuntimeException(
                'البذر الليبي يعمل في بيئة local فقط — البيئة الحالية: ' . app()->environment()
            );
        }

        $this->command->info('🇱🇾 البذر الليبي الشامل — البدء (بيئة local ✓)');

        DB::transaction(function () {
            $this->seedCentersAndStaff();   // المرحلة 2: الأدمن + المراكز + المحفّظون + المدراء
            $this->seedFamilies();          // المرحلة 3: أولياء الأمور + الطلاب + الإخوة
            $this->seedAttendance();        // المرحلة 4: حضور 8 أسابيع + التصحيحات
            $this->seedMemorization();      // المرحلة 5: الحفظ + الختمات + الاختبارات
            $this->seedRequestsAndComms();  // المرحلة 6: الطلبات + الرسائل + الإشعارات + سجلات كلمة المرور
            $this->seedEdgeCases();         // المرحلة 7: نشط/غير نشط + حالات الحافّة
        });

        $this->assertOnePrimaryPerCenter();
        $this->printCredentials();
    }

    // ============================================================
    // المراحل — تُملأ تباعاً (كل مرحلة commit مستقل بموافقة صريحة)
    // ============================================================

    /** المرحلة 2 — الأدمن + المراكز الأربعة + 14 محفّظاً + 4 مدراء مراكز. */
    private function seedCentersAndStaff(): void
    {
        // 1) مدير النظام — اسم ليبي جديد، والبريد اصطلاح ثابت admin@mutqin.ly (بلا كود عرض)
        $this->admin = User::create([
            'name'     => 'عبدالرزاق منصور الفيتوري',
            'email'    => 'admin@mutqin.ly',
            'phone'    => $this->nextPhone(),
            'role'     => 'admin',
            'password' => Hash::make(self::ADMIN_PASSWORD),
        ]);

        // 2) المراكز الأربعة — display_code (C{n}) يحجزه خطاف Center::creating ذرّياً
        $centersData = [
            ['مركز بلال بن رباح لتحفيظ القرآن', 'بنغازي', 'شارع الببسي', 5], // الرئيسي — الأكبر
            ['مركز الإمام نافع لتحفيظ القرآن',  'بنغازي', 'الفويهات',    4],
            ['مركز الفرقان لتحفيظ القرآن',      'بنغازي', 'القوارشة',    3],
            ['مركز المرج لتحفيظ القرآن',        'المرج',  'وسط المدينة', 2], // بلا مدير — fallback الأدمن
        ];
        $teacherCounts = [];
        foreach ($centersData as [$name, $city, $address, $tCount]) {
            $c = Center::create([
                'name'    => $name,
                'city'    => $city,
                'address' => $address,
                'phone'   => $this->nextPhone(),
            ]);
            $this->centers[] = $c;
            $teacherCounts[$c->id] = $tCount;
        }

        // 3) المحفّظون الـ14 — أسماء ثلاثية حتمية (لا عشوائية في الهوية)،
        //    «محفظ أساسي» واحد بالضبط لكل مركز (الأول فيه) والبقية معاونون
        $males    = array_keys(LibyanNames::MALE);
        $families = array_keys(LibyanNames::FAMILIES);
        $t = 0;
        foreach ($this->centers as $ci => $center) {
            $this->teachersByCenter[$center->id] = [];
            for ($k = 0; $k < $teacherCounts[$center->id]; $k++, $t++) {
                $first  = $males[$t % count($males)];
                $middle = $males[($t + 7) % count($males)];
                $family = $families[$t % count($families)];
                $teacher = $this->makeUser(
                    "{$first} {$middle} {$family}",
                    LibyanNames::latin($first, $family),
                    'teacher',
                    self::TEACHER_PASSWORD,
                    [
                        'center_id' => $center->id,
                        'type'      => $k === 0 ? 'محفظ أساسي' : 'محفظ معاون',
                    ]
                );
                $this->teachersByCenter[$center->id][] = $teacher;
            }
        }

        // 4) مدراء المراكز — نشط لكل مركز من الثلاثة الأولى (المرج بلا مدير عمداً)،
        //    بريدهم {نقحرة}.centeradmin@mutqin.ly بلا لاحقة id (اصطلاح النظام)
        $managersData = [
            ['عبدالسلام خالد المسماري', 'abdulsalam.almismari', 0],
            ['الصادق جمعة الترهوني',    'alsadiq.altarhuni',    1],
            ['مفتاح إدريس العواكلي',    'muftah.alawakli',      2],
        ];
        foreach ($managersData as [$name, $latin, $centerIdx]) {
            $m = User::create([
                'name'      => $name,
                'email'     => "{$latin}.centeradmin@mutqin.ly",
                'phone'     => $this->nextPhone(),
                'role'      => 'center_manager',
                'center_id' => $this->centers[$centerIdx]->id,
                'password'  => Hash::make(self::MANAGER_PASSWORD),
            ]);
            $this->managersByCenter[$m->center_id] = $m;
        }

        // مدير رابع معطَّل بجانب المدير النشط لمركز بلال بن رباح — يثبت أن
        // التعطيل لا يترك المركز مكشوفاً (القاعدة «مدير واحد» تخص النشطين عملياً)
        User::create([
            'name'              => 'ميلود عمران الدرسي',
            'email'             => 'miloud.aldarsi.centeradmin@mutqin.ly',
            'phone'             => $this->nextPhone(),
            'role'              => 'center_manager',
            'center_id'         => $this->centers[0]->id,
            'password'          => Hash::make(self::MANAGER_PASSWORD),
            'is_active'         => false,
            'status_changed_by' => $this->admin->id,
            'status_changed_at' => now()->subWeeks(3),
        ]);

        $this->command->info('✓ المرحلة 2: أدمن + 4 مراكز + 14 محفّظاً (أساسي واحد/مركز) + 4 مدراء (أحدهم معطَّل، والمرج بلا مدير)');
    }

    /** المرحلة 3 — ~70 ولي أمر + 130 طالباً (إخوة مرتبطون، 10 بلا محفّظ 4/3/2/1). */
    private function seedFamilies(): void
    {
        // تُملأ في المرحلة 3
    }

    /** المرحلة 4 — حضور 8 أسابيع (سبت→خميس، فرادة مضمونة، center_id مملوء) + ~40 تصحيحاً. */
    private function seedAttendance(): void
    {
        // تُملأ في المرحلة 4
    }

    /** المرحلة 5 — الحفظ العكسي 30→1 + 3-4 ختمات حسابية + اختبارات 6 أسابيع. */
    private function seedMemorization(): void
    {
        // تُملأ في المرحلة 5
    }

    /** المرحلة 6 — 12 طلب نقل + ~15 محادثة + إشعارات + password_change_logs. */
    private function seedRequestsAndComms(): void
    {
        // تُملأ في المرحلة 6
    }

    /** المرحلة 7 — ~8% معطَّلون بحقول التدقيق + كل حالات الحافّة الإلزامية. */
    private function seedEdgeCases(): void
    {
        // تُملأ في المرحلة 7
    }

    // ============================================================
    // فحوص ما بعد البذر
    // ============================================================

    /** قاعدة «محفّظ أساسي واحد لكل مركز» — الاختلال يوقف البذر بخطأ عربي. */
    private function assertOnePrimaryPerCenter(): void
    {
        $violations = DB::table('users')
            ->select('center_id', DB::raw('COUNT(*) AS n'))
            ->where('role', 'teacher')
            ->where('type', 'محفظ أساسي')
            ->groupBy('center_id')
            ->having('n', '>', 1)
            ->pluck('n', 'center_id');

        if ($violations->isNotEmpty()) {
            throw new \RuntimeException(
                'اختلّت قاعدة «محفّظ أساسي واحد لكل مركز» — مراكز مخالفة: '
                . $violations->keys()->implode('، ') . '. أوقف البذر.'
            );
        }

        $this->command->info('✓ قاعدة الأساسي الواحد سليمة في كل المراكز');
    }

    /** طباعة حسابات الدخول وكلمات المرور — بلا توليد صامت. */
    private function printCredentials(): void
    {
        $this->command->info('');
        $this->command->info('🔑 حسابات الدخول (البذر الليبي):');
        $this->command->info('   مدير النظام : admin@mutqin.ly / ' . self::ADMIN_PASSWORD);
        $this->command->info('   مدراء المراكز: {نقحرة}.centeradmin@mutqin.ly / ' . self::MANAGER_PASSWORD);
        $this->command->info('   المحفّظون   : {نقحرة}.{id}@mutqin.ly / ' . self::TEACHER_PASSWORD);
        $this->command->info('   أولياء الأمور: {نقحرة}.{id}@parent.mutqin.ly / ' . self::PARENT_PASSWORD);
        $this->command->info('   (القائمة الحيّة تُعرض في صفحة الدخول من /api/public/demo-accounts)');
    }
}
