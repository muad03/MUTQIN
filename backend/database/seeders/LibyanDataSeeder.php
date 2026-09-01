<?php

namespace Database\Seeders;

use App\Models\Center;
use App\Models\Student;
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
    /** @var Student[] كل الطلاب المبذورين */
    private array $students = [];
    /** @var array<int, Student[]> مفهرسة بمعرّف المركز */
    private array $studentsByCenter = [];
    /** @var User[] أولياء الأمور */
    private array $parents = [];
    private int $phoneSeq = 0;              // عدّاد هواتف فريدة من النطاقات المحجوزة
    private int $natIdSeq = 0;              // عدّاد أرقام وطنية فريدة (synthetic)
    /** @var int[] الختّامون — لا يوقَفون في مرحلة الحالات */
    private array $khatmaStudentIds = [];

    /** رقم وطني ليبي synthetic فريد: بادئة الجنس (1 ذكر / 2 أنثى) + 11 رقماً. */
    private function nextNationalId(bool $female): string
    {
        return ($female ? '2' : '1') . str_pad((string) (50000000001 + $this->natIdSeq++), 11, '0', STR_PAD_LEFT);
    }

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

    /**
     * المرحلة 3 — 70 ولي أمر + 130 طالباً:
     *  - أسر حتمية (لا عشوائية في الهوية): 30 أسرة بابن واحد + 25 باثنين +
     *    10 بثلاثة + 5 بأربعة = 130 ابناً، الإخوة بنفس العائلة والمركز
     *    وولي أمر واحد (منطق ParentResolver: هوية → هاتف → بريد — كلها فريدة).
     *  - التوزيع على المراكز بحصص 45/35/30/20 حسب الحجم.
     *  - ~70% برقم وطني (1 ذكر / 2 أنثى) و~30% بدونه، و5 طلاب أجانب بأسماء
     *    جنسياتهم، و10 بلا محفّظ (4/3/2/1) بحقل former_teacher_name مملوءاً.
     *  - كل الحقول الاختيارية تُملأ في أغلب الصفوف (هاتف، عمر، ميلاد، ولي…).
     */
    private function seedFamilies(): void
    {
        $males    = array_keys(LibyanNames::MALE);
        $females  = array_keys(LibyanNames::FEMALE);
        $families = array_keys(LibyanNames::FAMILIES);

        // حصص المراكز (بترتيب إنشائها) وعدّاد ما تبقّى منها
        $quota = [];
        foreach ([45, 35, 30, 20] as $idx => $q) {
            $quota[$this->centers[$idx]->id] = $q;
        }
        // «بلا محفّظ» لكل مركز: 4/3/2/1 — يُقتطعون من آخر طلاب كل مركز
        $noTeacherQuota = [];
        foreach ([4, 3, 2, 1] as $idx => $q) {
            $noTeacherQuota[$this->centers[$idx]->id] = $q;
        }
        // محفّظون سابقون (أسماء عرضية لحقل former_teacher_name)
        $formerTeachers = ['الشيخ عبدالله سالم الزوي', 'الشيخ رمضان علي الشلوي', 'الشيخ سليمان أحمد الجازوي'];

        // بنية الأسر: 5×4 + 10×3 + 25×2 + 30×1 = 130 ابناً لـ70 أسرة —
        // الأكبر أولاً (first-fit decreasing): الأسر الكبيرة تحجز أولاً
        // وأسر الابن الواحد تسدّ بقايا الحصص، فلا يتبقى فراغ لا يتّسع
        $familySizes = array_merge(array_fill(0, 5, 4), array_fill(0, 10, 3), array_fill(0, 25, 2), array_fill(0, 30, 1));

        $centerIds     = array_keys($quota);
        $centerTotals  = $quota;             // الحصة الكاملة الثابتة لكل مركز
        $teacherCursor = [];                 // توزيع دوري على محفّظي كل مركز
        $assignedCount = [];                 // ترتيب الطالب داخل مركزه (0-based)
        $studentSeq    = 0;
        $foreignNats   = ['تشاد', 'مصر', 'تونس', 'سوريا', 'النيجر'];

        foreach ($familySizes as $f => $size) {
            // مركز الأسرة: أول مركز تتّسع حصته المتبقية لكل الأبناء (الإخوة معاً)
            $centerId = null;
            foreach ($centerIds as $cid) {
                if ($quota[$cid] >= $size) {
                    $centerId = $cid;
                    break;
                }
            }
            if ($centerId === null) {
                throw new \RuntimeException("خلل في موزّع الأسر: لا مركز يتّسع لأسرة من {$size} أبناء — راجع الحصص");
            }
            $quota[$centerId] -= $size;

            $family      = $families[$f % count($families)];
            $fatherFirst = $males[($f * 3) % count($males)];
            // ~13% من الأولياء أمهات (أسماء نسائية) — البقية آباء
            $motherled   = ($f % 8) === 7;
            $guardFirst  = $motherled ? $females[$f % count($females)] : $fatherFirst;
            $guardName   = "{$guardFirst} {$family}";
            $foreignFam  = ($f % 14) === 6; // ~5 أسر أجنبية

            // ولي الأمر: هوية (id_number) لـ~80% + هاتف فريد + بريد فريد — أعمدة
            // منطق ParentResolver الثلاثة، وبلا display_code (بتصميم مقصود)
            $parent = $this->makeUser(
                $guardName,
                LibyanNames::latin($guardFirst, $family),
                'parent',
                self::PARENT_PASSWORD,
                [
                    'nationality_type' => $foreignFam ? 'foreigner' : 'libyan',
                    'nationality_name' => $foreignFam ? $foreignNats[$f % count($foreignNats)] : null,
                    'id_number'        => (! $foreignFam && ($f % 5) !== 4)
                        ? $this->nextNationalId($motherled)
                        : null,
                ],
                'parent.mutqin.ly'
            );
            $this->parents[] = $parent;

            for ($k = 0; $k < $size; $k++, $studentSeq++) {
                $i = $studentSeq;
                $female = ($i % 5) < 2; // ~40% طالبات
                $first  = $female ? $females[($i * 7) % count($females)] : $males[($i * 11) % count($males)];
                $name   = "{$first} {$fatherFirst} {$family}";

                // 10 بلا محفّظ (4/3/2/1): الأواخر N من حصة كل مركز — موضع الطالب
                // داخل مركزه يحسم الأمر حتمياً، بمحفّظ سابق مذكور للعرض
                $pos = $assignedCount[$centerId] = ($assignedCount[$centerId] ?? -1) + 1;
                $noTeacher = $pos >= $centerTotals[$centerId] - $noTeacherQuota[$centerId];
                $teacher = null;
                if (! $noTeacher) {
                    $list = $this->teachersByCenter[$centerId];
                    $cur  = $teacherCursor[$centerId] ?? 0;
                    $teacher = $list[$cur % count($list)];
                    $teacherCursor[$centerId] = $cur + 1;
                }

                $age = ($i % 10) === 9 ? 18 + ($i % 28) : 6 + ($i % 12); // ~10% بالغون حتى 45

                $student = Student::create([
                    'name'             => $name,
                    'birth_date'       => now()->subYears($age)->startOfYear()->addDays(($i * 17) % 360),
                    'phone'            => $age >= 13 ? $this->nextPhone() : null, // هاتف للمراهقين فما فوق
                    'national_id'      => ($foreignFam || ($i % 20) >= 15) ? null : $this->nextNationalId($female), // ~70% إجمالاً (الأجانب بلا رقم ليبي دائماً)
                    'nationality_type' => $foreignFam ? 'foreigner' : 'libyan',
                    'nationality_name' => $foreignFam ? $foreignNats[$f % count($foreignNats)] : null,
                    'age'              => $age,
                    'guardian_name'    => $guardName,
                    'guardian_phone'   => $parent->phone,
                    'center_id'        => $centerId,
                    'teacher_id'       => $teacher?->id,
                    'former_teacher_name' => $noTeacher ? $formerTeachers[$i % count($formerTeachers)] : null,
                    'parent_id'        => $parent->id,
                    'enrollment_date'  => now()->subMonths(3 + ($i % 18))->subDays($i % 28),
                    'is_active'        => true,
                ]);
                $this->students[] = $student;
                $this->studentsByCenter[$centerId][] = $student;
            }
        }

        $noTeacherTotal = collect($this->students)->whereNull('teacher_id')->count();
        $this->command->info('✓ المرحلة 3: ' . count($this->parents) . ' ولي أمر + ' . count($this->students)
            . ' طالباً (' . $noTeacherTotal . ' بلا محفّظ، '
            . collect($this->students)->whereNotNull('national_id')->count() . ' برقم وطني)');
    }

    /**
     * المرحلة 4 — حضور 8 أسابيع للخلف:
     *  - الأسبوع يبدأ السبت (Carbon::SATURDAY) والجمعة عطلة — 6 أيام/أسبوع.
     *  - الفرادة مضمونة بالبناء: حلقة (طالب × تاريخ) لا عشوائية فيها —
     *    unique(student_id, date) لا يمكن أن يصطدم.
     *  - center_id يُملأ دائماً من مركز الطالب (بلا NULL).
     *  - النسب حتمية بمعادلة توزيع: ~80% حاضر / ~12% غائب / ~8% متأخر.
     *  - إدخال بالدفعات (500) — لا استعلام لكل صف.
     *  - ثم ~40 تصحيحاً (10/مركز): غائب→حاضر بيد مدير المركز المسؤول
     *    (المرج بلا مدير ⇒ المصحِّح الأدمن — fallback) وcorrected_at بعد
     *    created_at بساعات.
     */
    private function seedAttendance(): void
    {
        // أيام الفترة: من سبتِ ما قبل 7 أسابيع حتى اليوم، بلا جُمَع
        $start = today()->startOfWeek(\Carbon\Carbon::SATURDAY)->subWeeks(7);
        $dates = [];
        for ($d = $start->copy(); $d->lte(today()); $d->addDay()) {
            if (! $d->isFriday()) {
                $dates[] = $d->toDateString();
            }
        }

        $rows = [];
        foreach ($this->students as $s) {
            foreach ($dates as $di => $date) {
                // معادلة حتمية للتوزيع: 0..79 حاضر، 80..91 غائب، 92..99 متأخر
                $h = ($s->id * 31 + $di * 7) % 100;
                $status = $h < 80 ? 'present' : ($h < 92 ? 'absent' : 'late');

                $rows[] = [
                    'student_id'  => $s->id,
                    'teacher_id'  => $s->teacher_id,
                    'center_id'   => $s->center_id,               // مملوء دائماً
                    'date'        => $date,
                    'time'        => $status === 'absent' ? null
                        : sprintf('0%d:%02d', $status === 'late' ? 8 : 7, ($s->id * 7 + $di * 13) % 60),
                    'status'      => $status,
                    'notes'       => $status === 'absent' ? 'غياب دون عذر مبلَّغ'
                        : ($status === 'late' ? 'تأخر عن موعد الحلقة' : null),
                    // ~ثلث السجلات من استيراد البصمة (يظهر «بصمة» في شاشة المراجعة)
                    'imported_at' => ($di % 3 === 0) ? ($date . ' 09:30:00') : null,
                    'created_at'  => $date . ' 08:00:00',
                    'updated_at'  => $date . ' 08:00:00',
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('attendances')->insert($chunk);
        }

        // التصحيحات: 10 سجلات «غائب» لكل مركز تُقلب «حاضر» بتدقيق كامل —
        // المصحِّح مدير المركز النشط، والمرج بلا مدير ⇒ الأدمن (fallback)
        $corrected = 0;
        foreach ($this->centers as $center) {
            $correctorId = isset($this->managersByCenter[$center->id])
                ? $this->managersByCenter[$center->id]->id
                : $this->admin->id;

            $ids = DB::table('attendances')
                ->where('center_id', $center->id)
                ->where('status', 'absent')
                ->orderBy('id')
                ->limit(10)
                ->pluck('id');

            DB::table('attendances')->whereIn('id', $ids)->update([
                'status'       => 'present',
                'notes'        => 'صُحّح: حاضر — خطأ من جهاز البصمة',
                'corrected_by' => $correctorId,
                'corrected_at' => DB::raw('DATE_ADD(created_at, INTERVAL 6 HOUR)'),
            ]);
            $corrected += count($ids);
        }

        $this->command->info('✓ المرحلة 4: ' . count($rows) . ' سجل حضور على ' . count($dates)
            . ' يوماً (سبت→خميس) + ' . $corrected . ' تصحيحاً بتدقيق كامل');
    }

    /**
     * المرحلة 5 — الحفظ + الختمات + الاختبارات الأسبوعية:
     *  - الحفظ عكسي (الناس ← الفاتحة): قائمة السور من SurahReference معكوسةً
     *    بترتيب المصحف — لا أرقام أجزاء يدوية، juz المخزّن من المرجع نفسه.
     *  - مستويات متفاوتة حتمية (8..77 سورة)، و4 طلاب أكملوا 114 سورة —
     *    الختمة «حسابية»: يرصدها SurahReference::progress() بلا جدول خاص.
     *  - اختبارات 6 أسابيع × كل طالب، يوم السبت (بداية الأسبوع المعتمدة)،
     *    وأسئلة أثمان حقيقية من فهرس athman المبذور، والنتيجة الكلية متسقة
     *    مع الأسئلة (راسب = ثمن راسب واحد على الأقل بنص خطأ عربي).
     *  - إدخال بالدفعات (500) — لا خطافات على هذه الجداول.
     */
    private function seedMemorization(): void
    {
        // ترتيب الحفظ العكسي: الناس أولاً نزولاً نحو الفاتحة (عكس ترتيب المصحف)
        $reversed = array_reverse(array_keys(\App\Support\SurahReference::SURAHS)); // 114
        $surahJuz = \App\Support\SurahReference::SURAHS;

        // 4 ختمات: طلاب نشطون بمحفّظين من مراكز مختلفة (مواضع حتمية)
        $khatmaIds = collect($this->students)
            ->filter(fn ($s) => $s->teacher_id !== null)
            ->values()
            ->only([2, 38, 72, 105])
            ->pluck('id')
            ->all();
        $this->khatmaStudentIds = $khatmaIds; // المرحلة 7 تستثنيهم من الإيقاف

        $memoRows = [];
        $qualities = ['excellent', 'good', 'good', 'average', 'excellent', 'good', 'average', 'weak']; // مزيج مرجّح
        foreach ($this->students as $s) {
            // مستوى الطالب: كم سورة حفظ من البداية العكسية — الختّامون 114
            $count = in_array($s->id, $khatmaIds, true) ? 114 : (8 + (($s->id * 13) % 70));

            for ($k = 0; $k < $count; $k++) {
                $surah = $reversed[$k];
                $juz   = $surahJuz[$surah];
                // صفحة تقريبية معقولة: الجزء ≈ 20 صفحة (بيانات عرضية لا يعتمد عليها النظام)
                $pageFrom = ($juz - 1) * 20 + 2 + ($k % 17);

                $memoRows[] = [
                    'student_id' => $s->id,
                    'teacher_id' => $s->teacher_id,
                    'date'       => today()->subDays(($count - $k) * 2)->toDateString(), // الأقدم أولاً
                    'surah_name' => $surah,
                    'juz'        => $juz,                     // من المرجع — لا يدوي
                    'hizb'       => min(60, $juz * 2),
                    'page_from'  => $pageFrom,
                    'page_to'    => $pageFrom + ($k % 3),
                    'eighth'     => ($k % 4 === 0) ? 'الثمن ' . (1 + ($k % 8)) : null,
                    'quality'    => $qualities[($s->id + $k) % count($qualities)],
                    'notes'      => ($k % 5 === 0)
                        ? 'تسميع متقن، ضبط جيد لأحكام المدود'
                        : (($k % 7 === 3) ? 'يحتاج تثبيت أواخر السورة' : null),
                    'created_at' => now()->subDays(($count - $k) * 2),
                    'updated_at' => now()->subDays(($count - $k) * 2),
                ];
            }
        }
        foreach (array_chunk($memoRows, 500) as $chunk) {
            DB::table('memorizations')->insert($chunk);
        }

        // ===== الاختبارات الأسبوعية: 6 أسابيع × كل طالب، سبتيّة، بأثمان حقيقية =====
        $thumns = DB::table('athman')->orderBy('global_order')->pluck('start_text')->all();

        $testRows = [];
        foreach ($this->students as $s) {
            for ($w = 5; $w >= 0; $w--) {
                $examDate = today()->startOfWeek(\Carbon\Carbon::SATURDAY)->subWeeks($w);
                $fail = (($s->id * 5 + $w) % 8) === 0; // ~12% راسب
                $testRows[] = [
                    'student_id' => $s->id,
                    'teacher_id' => $s->teacher_id,
                    'exam_date'  => $examDate->toDateString(),
                    'result'     => $fail ? 'راسب' : 'ناجح',
                    'notes'      => $fail
                        ? 'لم يثبّت المقرر جيداً — يُعاد الاختبار الأسبوع القادم'
                        : 'اجتاز التسميع بتقدير حسن',
                    'created_at' => $examDate->copy()->addHours(11),
                    'updated_at' => $examDate->copy()->addHours(11),
                ];
            }
        }
        foreach (array_chunk($testRows, 500) as $chunk) {
            DB::table('weekly_tests')->insert($chunk);
        }

        // أسئلة الأثمان (2-4 لكل اختبار) متسقة مع النتيجة الكلية:
        // «راسب» = أول ثمن راسب بنص خطأ عربي، و«ناجح» = كل الأثمان ناجحة
        $tests = DB::table('weekly_tests')->orderBy('id')->get(['id', 'student_id', 'result']);
        $qRows = [];
        foreach ($tests as $ti => $t) {
            $qCount = 2 + ($ti % 3);
            for ($q = 0; $q < $qCount; $q++) {
                $failed = $t->result === 'راسب' && $q === 0;
                $qRows[] = [
                    'weekly_test_id' => $t->id,
                    'student_id'     => $t->student_id,
                    'eighth_start'   => $thumns[($ti * 3 + $q * 7) % count($thumns)],
                    'result'         => $failed ? 'راسب' : 'ناجح',
                    'mistake'        => $failed ? 'خلط بين الآيات المتشابهة وتردد في بداية الثمن' : null,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];
            }
        }
        foreach (array_chunk($qRows, 500) as $chunk) {
            DB::table('weekly_test_questions')->insert($chunk);
        }

        $this->command->info('✓ المرحلة 5: ' . count($memoRows) . ' سجل حفظ (4 ختمات كاملة 114 سورة) + '
            . count($testRows) . ' اختباراً سبتياً بـ' . count($qRows) . ' ثمناً من فهرس الأثمان');
    }

    /**
     * المرحلة 6 — الطلبات والرسائل والإشعارات وسجلات كلمة المرور:
     *  - 12 طلباً بأحادية الاعتماد القائمة (status + admin_note فقط):
     *    4 نقل معتمَدة (الطالب فعلاً عند الوجهة وfrom_* تاريخه السابق) +
     *    3 معلّقة (منها داخلي لمركز له مدير + داخلي للمرج ⇒ إشعاره للأدمن fallback) +
     *    3 مرفوضة بأسباب عربية مكتوبة + 2 إضافة بلقطة بيانات كاملة.
     *  - ~15 محادثة ولي↔محفّظ حول حالة الابن، مقروء وغير مقروء بالاتجاهين.
     *  - إشعارات لكل دور (مطابقة لصيغة InAppNotification المخزّنة) مزيجاً.
     *  - password_change_logs بالطرق الثلاث otp/self/admin مع مزامنة عدّادات users.
     */
    private function seedRequestsAndComms(): void
    {
        $now = now();
        [$c1, $c2, $c3, $marj] = $this->centers;
        $t = fn (Center $c, int $i) => $this->teachersByCenter[$c->id][$i % count($this->teachersByCenter[$c->id])];
        $stuOf = fn (Center $c, int $i) => $this->studentsByCenter[$c->id][$i % count($this->studentsByCenter[$c->id])];

        // ===== 12 طلباً =====
        $requests = [];
        // 4 نقل معتمَدة: الطالب حالياً عند محفّظ الوجهة، وfrom_* مصدره السابق
        foreach ([[$c1, $c2, 5], [$c2, $c3, 8], [$c3, $c1, 11], [$marj, $c1, 14]] as $ri => [$fromC, $toC, $si]) {
            $student = $stuOf($toC, $si);
            $requests[] = [
                'type' => 'transfer', 'status' => 'approved',
                'requested_by' => $student->teacher_id ?? $t($toC, 0)->id,
                'target_center_id' => $toC->id, 'target_teacher_id' => $student->teacher_id ?? $t($toC, 0)->id,
                'student_id' => $student->id, 'national_id' => $student->national_id,
                'nationality_type' => $student->nationality_type, 'student_name' => $student->name,
                'from_center_id' => $fromC->id, 'from_teacher_id' => $t($fromC, 1)->id,
                'admin_note' => null,
                'created_at' => $now->copy()->subWeeks(5 - $ri), 'updated_at' => $now->copy()->subWeeks(5 - $ri)->addDay(),
            ];
        }
        // 3 معلّقة: داخلي بمركز له مدير + داخلي بالمرج (fallback أدمن) + عابر للمراكز
        $pendingSpecs = [
            [$c1, $c1, 20],   // داخلي — يذهب لمدير مركز بلال
            [$marj, $marj, 3], // داخلي بالمرج — لا مدير ⇒ الأدمن
            [$c2, $c3, 17],   // عابر — الأدمن
        ];
        foreach ($pendingSpecs as $pi => [$fromC, $toC, $si]) {
            $student = $stuOf($fromC, $si);
            $requester = $t($toC, 2 + $pi); // محفّظ الوجهة
            if ($requester->id === $student->teacher_id) { // لا يطلب المرء طالبه
                $requester = $t($toC, 3 + $pi);
            }
            $requests[] = [
                'type' => 'transfer', 'status' => 'pending',
                'requested_by' => $requester->id,
                'target_center_id' => $toC->id, 'target_teacher_id' => $requester->id,
                'student_id' => $student->id, 'national_id' => $student->national_id,
                'nationality_type' => $student->nationality_type, 'student_name' => $student->name,
                'from_center_id' => $student->center_id, 'from_teacher_id' => $student->teacher_id,
                'admin_note' => null,
                'created_at' => $now->copy()->subDays(3 + $pi), 'updated_at' => $now->copy()->subDays(3 + $pi),
            ];
        }
        // 3 مرفوضة بأسباب عربية
        $rejections = [
            'المحفّظ المستهدف مكتمل العدد هذا الفصل — يُعاد الطلب بعد شهر',
            'ولي الأمر لم يوافق على النقل بعد التواصل معه هاتفياً',
            'بيانات الطالب ناقصة: لا رقم وطني ولا شهادة ميلاد مرفقة',
        ];
        foreach ($rejections as $ji => $note) {
            $fromC = $this->centers[$ji % 3];
            $toC   = $this->centers[($ji + 1) % 3];
            $student = $stuOf($fromC, 25 + $ji);
            $requester = $t($toC, $ji);
            $requests[] = [
                'type' => 'transfer', 'status' => 'rejected',
                'requested_by' => $requester->id,
                'target_center_id' => $toC->id, 'target_teacher_id' => $requester->id,
                'student_id' => $student->id, 'national_id' => $student->national_id,
                'nationality_type' => $student->nationality_type, 'student_name' => $student->name,
                'from_center_id' => $student->center_id, 'from_teacher_id' => $student->teacher_id,
                'admin_note' => $note,
                'created_at' => $now->copy()->subWeeks(2)->subDays($ji), 'updated_at' => $now->copy()->subWeeks(2)->subDays($ji)->addHours(20),
            ];
        }
        // 2 إضافة بلقطة كاملة (معتمَد + معلّق)
        foreach ([['approved', $c2, 'قيس رمضان الكيلاني', 'qais'], ['pending', $c3, 'حمزة عياد السنوسي', 'hamza']] as $ai => [$st, $c, $name, $latin]) {
            $requester = $t($c, 1 + $ai);
            $requests[] = [
                'type' => 'add', 'status' => $st,
                'requested_by' => $requester->id,
                'target_center_id' => $c->id, 'target_teacher_id' => $requester->id,
                'student_id' => null,
                'national_id' => '1' . str_pad((string) (59900000001 + $ai), 11, '0', STR_PAD_LEFT),
                'nationality_type' => 'libyan', 'student_name' => $name,
                'age' => 9 + $ai, 'phone' => $this->nextPhone(),
                'guardian_name' => 'رمضان الكيلاني', 'guardian_phone' => $this->nextPhone(),
                'guardian_email' => $latin . '.guardian@parent.mutqin.ly',
                'guardian_nationality_type' => 'libyan',
                'guardian_id_number' => '1' . str_pad((string) (59900000101 + $ai), 11, '0', STR_PAD_LEFT),
                'from_center_id' => null, 'from_teacher_id' => null,
                'admin_note' => null,
                'created_at' => $now->copy()->subDays(6 + $ai), 'updated_at' => $now->copy()->subDays(5 + $ai),
            ];
        }
        // توحيد أعمدة الدفعة (إدخال الدُفعات يتطلب نفس المفاتيح في كل صف)
        $reqDefaults = [
            'student_id' => null, 'national_id' => null, 'nationality_type' => 'libyan',
            'nationality_name' => null, 'student_name' => null, 'age' => null, 'phone' => null,
            'guardian_name' => null, 'guardian_phone' => null, 'guardian_email' => null,
            'guardian_nationality_type' => 'libyan', 'guardian_nationality_name' => null,
            'guardian_id_number' => null, 'from_center_id' => null, 'from_teacher_id' => null,
            'admin_note' => null,
        ];
        DB::table('student_requests')->insert(array_map(fn ($r) => array_merge($reqDefaults, $r), $requests));

        // ===== ~15 محادثة ولي↔محفّظ (حول حالة الابن حصراً) =====
        $threads = collect($this->students)
            ->filter(fn ($s) => $s->teacher_id && $s->parent_id)
            ->values()
            ->filter(fn ($s, $i) => $i % 8 === 0)  // ~15 طالباً موزّعين
            ->take(15)->values();

        $dialog = [
            ['parent',  'السلام عليكم شيخنا، كيف مستوى %s في الحفظ هذا الأسبوع؟'],
            ['teacher', 'وعليكم السلام ورحمة الله. %s مجتهد والحمد لله، أتم مقرر الأسبوع وضبط أحكام المد.'],
            ['parent',  'الله يبارك فيكم. هل يحتاج مراجعة إضافية في البيت؟'],
            ['teacher', 'يُستحسن تثبيت آخر سورتين قبل حلقة السبت، عشر دقائق يومياً تكفي.'],
            ['parent',  'أبشر شيخنا، جزاكم الله خيراً على المتابعة.'],
        ];
        $msgRows = [];
        foreach ($threads as $ti => $s) {
            $msgCount = 2 + ($ti % 4); // 2..5 رسائل
            for ($m = 0; $m < $msgCount; $m++) {
                [$role, $tpl] = $dialog[$m];
                $sent = $now->copy()->subDays(10 - $ti % 7)->addHours($m * 3);
                // الأحدث في نصف الخيوط غير مقروء (بالاتجاهين) لتظهر شارات غير المقروء
                $unreadLast = ($m === $msgCount - 1) && ($ti % 2 === 0);
                $msgRows[] = [
                    'student_id' => $s->id,
                    'sender_id' => $role === 'parent' ? $s->parent_id : $s->teacher_id,
                    'sender_role' => $role,
                    'body' => sprintf($tpl, mb_substr($s->name, 0, mb_strpos($s->name, ' ') ?: null)),
                    'read_at' => $unreadLast ? null : $sent->copy()->addHours(2),
                    'created_at' => $sent, 'updated_at' => $sent,
                ];
            }
        }
        DB::table('messages')->insert($msgRows);

        // ===== إشعارات لكل دور (صيغة InAppNotification المخزّنة حرفياً) =====
        $notif = function (User $to, string $type, string $title, string $body, ?int $ref, string $link, bool $read, int $daysAgo) {
            return [
                'id' => (string) Str::uuid(),
                'type' => \App\Notifications\InAppNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $to->id,
                'data' => json_encode(['type' => $type, 'title' => $title, 'body' => $body, 'ref_id' => $ref, 'link' => $link], JSON_UNESCAPED_UNICODE),
                'read_at' => $read ? now()->subDays($daysAgo)->addHours(5) : null,
                'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
            ];
        };
        $nRows = [];
        // الأدمن: طلبات معلّقة (منها المرج fallback) — مزيج مقروء/غير مقروء
        $nRows[] = $notif($this->admin, 'request_created', 'طلب جديد بانتظار الموافقة', 'طلب نقل داخلي بمركز المرج (بلا مدير) آل إليك.', null, 'admin/requests.html', false, 2);
        $nRows[] = $notif($this->admin, 'request_created', 'طلب جديد بانتظار الموافقة', 'محفّظ أرسل طلب نقل عابر للمراكز.', null, 'admin/requests.html', true, 4);
        // المدراء النشطون: الداخلي لمركزهم
        foreach ($this->managersByCenter as $cid => $mgr) {
            $nRows[] = $notif($mgr, 'request_created', 'طلب نقل داخلي بمركزك', 'محفّظ من مركزك طلب نقل طالب إليه — بانتظار اعتمادك.', null, 'manager/requests.html', $cid !== $c1->id, 3);
        }
        // محفّظون: موافقة ورفض ورسالة
        $nRows[] = $notif($t($c1, 0), 'request_approved', 'تمت الموافقة على طلبك', 'اعتُمد نقل الطالب إلى حلقتك.', null, 'teacher/requests.html', false, 1);
        $nRows[] = $notif($t($c2, 0), 'request_rejected', 'تم رفض طلبك', 'رُفض الطلب: ' . $rejections[0], null, 'teacher/requests.html', true, 5);
        // أولياء وأمهات: حفظ واختبار ورسائل — مزيج
        foreach ($threads->take(6) as $pi => $s) {
            $p = User::find($s->parent_id);
            $nRows[] = $notif($p, 'memorization_added', 'تسجيل حفظ جديد', 'سجّل المحفّظ حفظاً جديداً لابنك «' . $s->name . '».', $s->id, 'parent/child.html?id=' . $s->id, $pi % 2 === 0, 1 + $pi);
            if ($pi < 3) {
                $nRows[] = $notif($p, 'test_added', 'اختبار أسبوعي جديد', 'سُجّل اختبار أسبوعي لابنك «' . $s->name . '» — النتيجة: ناجح.', $s->id, 'parent/child.html?id=' . $s->id, false, $pi + 1);
            }
            $teacher = User::find($s->teacher_id);
            $nRows[] = $notif($teacher, 'message_received', 'رسالة جديدة بخصوص «' . $s->name . '»', 'ولي الأمر أرسل رسالة جديدة.', $s->id, 'teacher/messages.html?student=' . $s->id, $pi % 3 === 0, $pi + 1);
        }
        DB::table('notifications')->insert($nRows);

        // ===== سجلات تغيير كلمة المرور (otp / self / admin) مع مزامنة عدّادات users =====
        $pwdTargets = [
            [$t($c1, 1), 'self', 12],  [$t($c2, 1), 'admin', 20],
            [$t($c3, 0), 'otp', 8],    [$this->parents[3], 'otp', 15],
            [$this->parents[9], 'otp', 6], [$this->parents[15], 'self', 25],
            [$this->managersByCenter[$c2->id], 'admin', 30],
        ];
        $logRows = [];
        foreach ($pwdTargets as [$u, $method, $daysAgo]) {
            $at = now()->subDays($daysAgo);
            $logRows[] = [
                'user_id' => $u->id, 'changed_at' => $at, 'method' => $method,
                'created_at' => $at, 'updated_at' => $at,
            ];
            DB::table('users')->where('id', $u->id)->update([
                'password_changed_count' => DB::raw('password_changed_count + 1'),
                'password_last_changed_at' => $at,
            ]);
        }
        DB::table('password_change_logs')->insert($logRows);

        $this->command->info('✓ المرحلة 6: ' . count($requests) . ' طلباً + ' . count($msgRows) . ' رسالة في '
            . $threads->count() . ' محادثة + ' . count($nRows) . ' إشعاراً + ' . count($logRows) . ' سجل كلمة مرور');
    }

    /**
     * المرحلة 7 — نشط/غير نشط وحالات الحافّة:
     *  - ~8% من الطلاب (10) والمحفّظين (1 معاون) وأولياء الأمور (5) يُعطَّلون
     *    بحقول التدقيق (status_changed_by = مدير المركز المسؤول أو الأدمن).
     *    المدراء عندهم معطَّل واحد أصلاً (المرحلة 2)، والمراكز تبقى نشطة
     *    (8% من 4 ≈ 0 — وتعطيل مركز يقفل حساباته عن بروفة الدخول).
     *  - كل موقوف عنده حضور وحفظ واختبارات سابقة بالفعل (بُذرت له في 4-5)
     *    — برهان «صفر فقدان بيانات».
     *  - أول أسرة (4 أبناء): ابن موقوف وإخوته نشطون — حالة ولي الأمر المطلوبة.
     *  - الختّامون الأربعة مستثنون من الإيقاف (تبقى ختماتهم ظاهرة).
     *  - بقية الحالات الإلزامية بُذرت في مراحلها: 10 بلا محفّظ بformer_teacher_name،
     *    39 بلا رقم وطني، المرج بلا مدير.
     */
    private function seedEdgeCases(): void
    {
        $changedAt = now()->subDays(9);
        $auditFor = fn (?int $centerId) => isset($this->managersByCenter[$centerId])
            ? $this->managersByCenter[$centerId]->id
            : $this->admin->id;

        // 1) الطلاب: ابن من الأسرة الرباعية الأولى + كل 13 طالباً حتى 10، بلا ختّامين
        $firstFamilyChild = collect($this->students)->firstWhere('parent_id', $this->parents[0]->id);
        $suspendIds = [$firstFamilyChild->id];
        foreach ($this->students as $i => $s) {
            if (count($suspendIds) >= 10) {
                break;
            }
            if ($i % 13 === 5 && ! in_array($s->id, $this->khatmaStudentIds, true) && ! in_array($s->id, $suspendIds, true)) {
                $suspendIds[] = $s->id;
            }
        }
        foreach (collect($this->students)->whereIn('id', $suspendIds)->groupBy('center_id') as $centerId => $group) {
            DB::table('students')->whereIn('id', $group->pluck('id'))->update([
                'is_active' => false,
                'status_changed_by' => $auditFor($centerId),
                'status_changed_at' => $changedAt,
            ]);
        }

        // 2) محفّظ معاون واحد يُعطَّل (ليس أساسياً — قاعدة الأساسي الواحد تبقى سليمة)
        $inactiveTeacher = $this->teachersByCenter[$this->centers[0]->id][2]; // معاون بمركز بلال
        DB::table('users')->where('id', $inactiveTeacher->id)->update([
            'is_active' => false,
            'status_changed_by' => $this->admin->id,
            'status_changed_at' => $changedAt,
        ]);

        // 3) خمسة أولياء أمور يُعطَّلون — ليس بينهم ولي الأسرة الرباعية (يجب أن
        //    يدخل ليرى ابنه الموقوف بجانب النشطين)
        $inactiveParents = collect($this->parents)
            ->filter(fn ($p, $i) => $i % 14 === 9 && $p->id !== $this->parents[0]->id)
            ->take(5);
        DB::table('users')->whereIn('id', $inactiveParents->pluck('id'))->update([
            'is_active' => false,
            'status_changed_by' => $this->admin->id,
            'status_changed_at' => $changedAt,
        ]);

        $this->command->info('✓ المرحلة 7: أُوقف ' . count($suspendIds) . ' طلاب (منهم ابن الأسرة الرباعية) + محفّظ معاون + '
            . $inactiveParents->count() . ' أولياء — كلٌّ بسجلّات سابقة كاملة وحقول تدقيق');
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
