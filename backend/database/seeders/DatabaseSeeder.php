<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Athman;
use App\Models\Center;
use App\Models\Memorization;
use App\Models\Message;
use App\Models\Revision;
use App\Models\Student;
use App\Models\StudentRequest;
use App\Models\TajweedEvaluation;
use App\Models\User;
use App\Models\WeeklyTest;
use App\Models\WeeklyTestQuestion;
use App\Support\SurahReference;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * البذر الأساسي — بيانات تجريبية ليبية واقعية وكاملة لكل جداول النظام.
 *
 * التغطية: 13 مدينة ليبية (طرابلس، بنغازي، مصراتة، البيضاء، درنة، طبرق،
 * أجدابيا، سبها، الزاوية، زليتن، الخمس، المرج، شحات) بأسماء عائلات وأحياء
 * وأرقام هواتف وأرقام وطنية بالصيغ الليبية المتعارف عليها.
 *
 * يمسح البيانات القديمة أولاً ثم يبني قاعدة نظيفة متّسقة العلاقات:
 *   مدير النظام → المراكز → مدراء المراكز → المحفّظون → أولياء الأمور → الطلاب
 *   → الحضور والحفظ والمراجعة والتجويد والاختبارات → الطلبات والرسائل والإشعارات.
 *
 * التشغيل:  php artisan migrate:fresh --seed   أو   php artisan db:seed
 */
class DatabaseSeeder extends Seeder
{
    /** كلمة المرور الموحّدة لكل الحسابات التجريبية (تُعرض في صفحة الدخول). */
    private const DEMO_PASSWORD = 'mutqin2026';

    /** ترتيب الحفظ العكسي: يبدأ بالناس (جزء 30) نزولاً نحو الملك (جزء 29). */
    private const REVERSE_ORDER = [
        'الناس', 'الفلق', 'الإخلاص', 'المسد', 'النصر', 'الكافرون', 'الكوثر', 'الماعون',
        'قريش', 'الفيل', 'الهمزة', 'العصر', 'التكاثر', 'القارعة', 'العاديات', 'الزلزلة',
        'البينة', 'القدر', 'العلق', 'التين', 'الشرح', 'الضحى', 'الليل', 'الشمس', 'البلد',
        'الفجر', 'الغاشية', 'الأعلى', 'الطارق', 'البروج', 'الانشقاق', 'المطففين',
        'الانفطار', 'التكوير', 'عبس', 'النازعات', 'النبأ',
        'المرسلات', 'الإنسان', 'القيامة', 'المدثر', 'المزمل', 'الجن', 'نوح', 'المعارج',
        'الحاقة', 'القلم', 'الملك',
    ];

    /** صفحة بداية كل سورة في مصحف المدينة (604 صفحات) — لجزأي 29 و30. */
    private const SURAH_PAGE = [
        'الملك' => 562, 'القلم' => 564, 'الحاقة' => 566, 'المعارج' => 568, 'نوح' => 570,
        'الجن' => 572, 'المزمل' => 574, 'المدثر' => 575, 'القيامة' => 577, 'الإنسان' => 578,
        'المرسلات' => 580, 'النبأ' => 582, 'النازعات' => 583, 'عبس' => 585, 'التكوير' => 586,
        'الانفطار' => 587, 'المطففين' => 587, 'الانشقاق' => 589, 'البروج' => 590,
        'الطارق' => 591, 'الأعلى' => 591, 'الغاشية' => 592, 'الفجر' => 593, 'البلد' => 594,
        'الشمس' => 595, 'الليل' => 595, 'الضحى' => 596, 'الشرح' => 596, 'التين' => 597,
        'العلق' => 597, 'القدر' => 598, 'البينة' => 598, 'الزلزلة' => 599, 'العاديات' => 599,
        'القارعة' => 600, 'التكاثر' => 600, 'العصر' => 601, 'الهمزة' => 601, 'الفيل' => 601,
        'قريش' => 602, 'الماعون' => 602, 'الكوثر' => 602, 'الكافرون' => 603, 'النصر' => 603,
        'المسد' => 603, 'الإخلاص' => 604, 'الفلق' => 604, 'الناس' => 604,
    ];

    /** أسماء الأثمان بالترتيب — لوصف حقل eighth في سجل الحفظ. */
    private const THUMN_NAMES = [
        1 => 'الثمن الأول', 2 => 'الثمن الثاني', 3 => 'الثمن الثالث', 4 => 'الثمن الرابع',
        5 => 'الثمن الخامس', 6 => 'الثمن السادس', 7 => 'الثمن السابع', 8 => 'الثمن الثامن',
    ];

    private array $counts = [];

    public function run(): void
    {
        mt_srand(20260825); // بذرة ثابتة → نفس البيانات في كل تشغيل (قابلة للتكرار)

        $this->wipe();

        // فهرس الأثمان المرجعي (477 ثمناً) — بيانات مرجعية ثابتة لا تُختلق
        $this->call(AthmanSeeder::class);

        $password = Hash::make(self::DEMO_PASSWORD);

        $admin    = $this->seedAdmin($password);
        $centers  = $this->seedCenters();
        $managers = $this->seedManagers($centers, $password);
        $teachers = $this->seedTeachers($centers, $password);
        [$parents, $students] = $this->seedFamilies($centers, $teachers, $admin, $password);

        $this->seedAttendance($students, $managers);
        $this->seedMemorization($students);
        $this->seedRevisions($students);
        $this->seedTajweed($students);
        $this->seedWeeklyTests($students);
        $this->seedStudentRequests($centers, $teachers, $students, $admin);
        $this->seedMessages($students);
        $this->seedNotifications($students, $admin, $managers);
        $this->seedPasswordChangeLogs($teachers, $parents, $admin);

        $this->report();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // مسح البيانات السابقة
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * يحذف كل البيانات التشغيلية ويصفّر عدّادات أكواد العرض، بترتيب آمن
     * (تعطيل فحص المفاتيح الأجنبية أثناء التفريغ فقط) — البنية لا تُمسّ.
     */
    private function wipe(): void
    {
        $tables = [
            'messages', 'notifications', 'weekly_test_questions', 'weekly_tests',
            'tajweed_evaluations', 'revisions', 'memorizations', 'attendances',
            'student_requests', 'otp_resets', 'password_change_logs',
            'personal_access_tokens', 'students', 'users', 'centers',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // عدّادات أكواد العرض (S/T/CA/C) تعود للصفر مع قاعدة نظيفة
        DB::table('code_sequences')->update(['value' => 0]);

        $this->command?->info('تم مسح البيانات السابقة (البنية والـ migrations لم تُمسّ).');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // مساعدات
    // ─────────────────────────────────────────────────────────────────────────

    /** ينشئ مستخدماً ببريد مؤقت ثم يضبط البريد النهائي {latin}.{id}@domain. */
    private function createUser(array $attrs, string $latin, string $domain = 'mutqin.ly'): User
    {
        $user = User::create(array_merge($attrs, [
            'email' => 'tmp-' . Str::random(18) . '@mutqin.ly',
        ]));
        $user->forceFill([
            'email'             => $latin . '.' . $user->id . '@' . $domain,
            'email_verified_at' => now()->subDays(mt_rand(30, 300)),
        ])->save();

        return $user;
    }

    /** إدراج مجمّع سريع على دفعات (جداول بلا hooks على creating). */
    private function bulk(string $table, array $rows, int $chunk = 500): void
    {
        foreach (array_chunk($rows, $chunk) as $part) {
            DB::table($table)->insert($part);
        }
        $this->counts[$table] = ($this->counts[$table] ?? 0) + count($rows);
    }

    private function pick(array $arr)
    {
        return $arr[mt_rand(0, count($arr) - 1)];
    }

    /** رقم وطني ليبي: 12 خانة — 1 للذكر / 2 للأنثى + سنة الميلاد + تسلسل. */
    private function nationalId(string $gender, int $birthYear, int $serial): string
    {
        return ($gender === 'f' ? '2' : '1') . $birthYear . str_pad((string) $serial, 7, '0', STR_PAD_LEFT);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1) مدير النظام
    // ─────────────────────────────────────────────────────────────────────────

    private function seedAdmin(string $password): User
    {
        $admin = User::create([
            'name'             => 'معاذ ناجي الأطرش',
            'email'            => 'admin@mutqin.ly',
            'phone'            => '0913000001',
            'role'             => 'admin',
            'password'         => $password,
            'nationality_type' => 'libyan',
            'nationality_name' => 'ليبيا',
            'id_number'        => $this->nationalId('m', 1985, 1000001),
            'is_active'        => true,
        ]);
        $admin->forceFill(['email_verified_at' => now()->subYear()])->save();

        return $admin;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2) المراكز — مدينة لكل مركز، بعنوان وحي ورقم أرضي بمفتاح المدينة
    // ─────────────────────────────────────────────────────────────────────────

    private function seedCenters(): array
    {
        $data = [
            ['مركز عقبة بن نافع لتحفيظ القرآن الكريم',   'طرابلس', 'تاجوراء — شارع الشط، خلف جامع عقبة',        '0214441201', true],
            ['مركز معاذ بن جبل لتحفيظ القرآن الكريم',    'بنغازي', 'الفويهات — شارع دبي، بجوار جامع الرحمة',     '0615552202', true],
            ['مركز الفرقان لتحفيظ القرآن الكريم',        'مصراتة', 'الزروق — شارع طرابلس، قرب سوق الثلاثاء',     '0516663203', true],
            ['مركز النور لتحفيظ القرآن الكريم',          'البيضاء', 'وسط المدينة — بجوار الجامع الكبير',          '0847774204', true],
            ['مركز الصحابة لتحفيظ القرآن الكريم',        'درنة',   'حي الفتائح — شارع الجبل الأخضر',             '0818885205', true],
            ['مركز الرضوان لتحفيظ القرآن الكريم',        'طبرق',   'حي النصر — قرب ميدان الشهداء',               '0879996206', true],
            ['مركز المتقين لتحفيظ القرآن الكريم',        'أجدابيا', 'وسط المدينة — شارع الجمهورية',               '0641117207', true],
            ['مركز الهدى لتحفيظ القرآن الكريم',          'سبها',   'حي المهدية — شارع محمد المقريف',             '0712228208', true],
            ['مركز البيان لتحفيظ القرآن الكريم',         'الزاوية', 'حي الحرشة — بجوار مسجد بلال بن رباح',        '0233339209', true],
            ['مركز الزاوية الأسمرية لتحفيظ القرآن الكريم', 'زليتن',  'المدينة القديمة — قرب الزاوية الأسمرية',      '0524440210', true],
            ['مركز التقوى لتحفيظ القرآن الكريم',         'الخمس',  'سوق الخميس — شارع المستشفى القديم',           '0315551211', true],
            ['مركز السنابل لتحفيظ القرآن الكريم',        'المرج',  'المرج الجديدة — الحي الأول، قرب البلدية',     '0676662212', true],
            // مركز مسجّل ولم يُفتتح بعد — لتغطية حالة «مركز غير مفعّل» بلا تعطيل أي حساب
            ['مركز الفتح لتحفيظ القرآن الكريم',          'شحات',   'وسط شحات — قرب الملعب البلدي',                '0857773213', false],
        ];

        $centers = [];
        foreach ($data as [$name, $city, $address, $phone, $active]) {
            $centers[] = Center::create([
                'name'      => $name,
                'city'      => $city,
                'address'   => $address,
                'phone'     => $phone,
                'is_active' => $active,
            ]);
        }
        $this->counts['centers'] = count($centers);

        return $centers;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3) مدراء المراكز — واحد لكل مركز عامل، بريد {latin}.centeradmin@mutqin.ly
    // ─────────────────────────────────────────────────────────────────────────

    private function seedManagers(array $centers, string $password): array
    {
        $data = [
            ['عبدالحكيم علي البشتي',        'abdulhakim', '0913000010', 1968],
            ['السنوسي محمد العقوري',        'alsanusi',   '0923000011', 1971],
            ['الطيب خالد الساعدي',          'altayeb',    '0913000012', 1975],
            ['جمال عبدالله الحاسي',         'jamal',      '0943000013', 1969],
            ['بشير عمران المنفي',           'bashir',     '0933000014', 1973],
            ['عبدالسلام سعد العبيدي',       'abdulsalam', '0913000015', 1966],
            ['مفتاح صالح المغربي',          'miftah',     '0923000016', 1977],
            ['أسامة محمد سيف النصر',        'osama',      '0943000017', 1980],
            ['نصرالدين علي الشريف',         'nasruddin',  '0933000018', 1972],
            ['عبدالرؤوف مصطفى الشوشان',     'abdulraouf', '0913000019', 1974],
            ['رمضان مفتاح الحاراتي',        'ramadan',    '0923000020', 1970],
            ['يوسف إبراهيم البرغثي',        'youssef',    '0943000021', 1979],
        ];

        $managers = [];
        foreach ($data as $i => [$name, $latin, $phone, $year]) {
            $m = User::create([
                'name'             => $name,
                'email'            => $latin . '.centeradmin@mutqin.ly',
                'phone'            => $phone,
                'role'             => 'center_manager',
                'center_id'        => $centers[$i]->id,
                'password'         => $password,
                'nationality_type' => 'libyan',
                'nationality_name' => 'ليبيا',
                'id_number'        => $this->nationalId('m', $year, 2000001 + $i),
                'is_active'        => true,
            ]);
            $m->forceFill(['email_verified_at' => now()->subDays(mt_rand(60, 400))])->save();
            $managers[] = $m;
        }

        return $managers;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4) المحفّظون — محفّظ أساسي واحد لكل مركز + معاونون
    // ─────────────────────────────────────────────────────────────────────────

    private function seedTeachers(array $centers, string $password): array
    {
        // [الاسم، اللاتيني، فهرس المركز، النوع، الهاتف، سنة الميلاد، الجنسية، اسم الجنسية]
        $data = [
            ['عبدالباسط عمران الككلي',   'abdulbaset.alkakli',   0,  'محفظ أساسي', '0913100001', 1976, 'libyan',    'ليبيا'],
            ['الهادي رمضان الغرياني',    'alhadi.algharyani',    0,  'محفظ معاون', '0923100002', 1988, 'libyan',    'ليبيا'],
            ['خالد مصطفى بن غشير',       'khaled.binghashir',    0,  'محفظ معاون', '0933100003', 1991, 'libyan',    'ليبيا'],
            ['نوري الصادق العريبي',      'nuri.alaribi',         1,  'محفظ أساسي', '0943100004', 1974, 'libyan',    'ليبيا'],
            ['أحمد علي المسماري',        'ahmed.almismari',      1,  'محفظ معاون', '0913100005', 1986, 'libyan',    'ليبيا'],
            ['عبدالرحمن محمود بوهدمة',   'abdulrahman.bouhadma', 1,  'محفظ معاون', '0923100006', 1993, 'libyan',    'ليبيا'],
            ['فرج امحمد الدرقاش',        'faraj.aldarqash',      2,  'محفظ أساسي', '0933100007', 1972, 'libyan',    'ليبيا'],
            ['يوسف مفتاح القصير',        'youssef.alqasir',      2,  'محفظ معاون', '0943100008', 1990, 'libyan',    'ليبيا'],
            ['إبراهيم منصور الحاسي',     'ibrahim.alhasi',       3,  'محفظ أساسي', '0913100009', 1978, 'libyan',    'ليبيا'],
            ['عمر خالد العبيدي',         'omar.alobeidi',        3,  'محفظ معاون', '0923100010', 1989, 'libyan',    'ليبيا'],
            ['محمود فرج الدرسي',         'mahmoud.aldarsi',      4,  'محفظ أساسي', '0933100011', 1975, 'libyan',    'ليبيا'],
            ['معاذ ناجي الشلوي',         'muadh.alshalwi',       4,  'محفظ معاون', '0943100012', 1992, 'libyan',    'ليبيا'],
            ['سالم عبدالله القطعاني',    'salem.alqataani',      5,  'محفظ أساسي', '0913100013', 1970, 'libyan',    'ليبيا'],
            ['أنس محمد بوزيد',           'anas.bouzid',          5,  'محفظ معاون', '0923100014', 1987, 'libyan',    'ليبيا'],
            ['محمد سالم المجبري',        'mohamed.almijbari',    6,  'محفظ أساسي', '0933100015', 1973, 'libyan',    'ليبيا'],
            ['صلاح الدين عياد الزوي',    'salahuddin.alzawi',    6,  'محفظ معاون', '0943100016', 1985, 'libyan',    'ليبيا'],
            ['عبدالمجيد أحمد التاورغي',  'abdulmajid.altawergi', 7,  'محفظ أساسي', '0913100017', 1971, 'libyan',    'ليبيا'],
            ['الطيب حسن أبوبكر',         'altayeb.abubakr',      7,  'محفظ معاون', '0923100018', 1984, 'foreigner', 'تشاد'],
            ['علي محمد الطشاني',         'ali.altashani',        8,  'محفظ أساسي', '0933100019', 1977, 'libyan',    'ليبيا'],
            ['وليد عبدالسلام العجيلي',   'walid.alojaili',       8,  'محفظ معاون', '0943100020', 1990, 'libyan',    'ليبيا'],
            ['مفتاح علي الأسمري',        'miftah.alasmari',      9,  'محفظ أساسي', '0913100021', 1968, 'libyan',    'ليبيا'],
            ['عبدالله رمضان الششتاوي',   'abdullah.alshashtawi', 9,  'محفظ معاون', '0923100022', 1986, 'libyan',    'ليبيا'],
            ['أسامة فتحي الحاراتي',      'osama.alharati',       10, 'محفظ أساسي', '0933100023', 1979, 'libyan',    'ليبيا'],
            ['نبيل عمر السويحلي',        'nabil.alsuwaihli',     10, 'محفظ معاون', '0943100024', 1994, 'libyan',    'ليبيا'],
            ['الصديق عبدالكريم البرغثي', 'alsiddiq.albarghathi', 11, 'محفظ أساسي', '0913100025', 1969, 'libyan',    'ليبيا'],
            ['رمزي إبراهيم العرفي',      'ramzi.alorfi',         11, 'محفظ معاون', '0923100026', 1988, 'libyan',    'ليبيا'],
        ];

        $teachers = [];
        foreach ($data as $i => [$name, $latin, $ci, $type, $phone, $year, $natType, $natName]) {
            $teachers[] = $this->createUser([
                'name'             => $name,
                'phone'            => $phone,
                'role'             => 'teacher',
                'password'         => $password,
                'center_id'        => $centers[$ci]->id,
                'type'             => $type,
                'nationality_type' => $natType,
                'nationality_name' => $natName,
                'id_number'        => $natType === 'libyan'
                    ? $this->nationalId('m', $year, 3000001 + $i)
                    : 'TD' . (7100000 + $i), // جواز/إقامة لغير الليبي
                'is_active'        => true,
            ], $latin);
        }

        return $teachers;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5) الأُسر — ولي أمر واحد لكل أسرة (أب أو أم) + أبناؤه طلاباً
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * كل صف: [اسم ولي الأمر، اللاتيني، الجنس، سنة ميلاده، فهرس المحفّظ،
     *          الجنسية، اسم الجنسية، [أسماء الأبناء الأولى]]
     * الإخوة يتشاركون حساب ولي أمر واحد (نفس الهاتف) — كما يقتضي منطق النظام.
     */
    private function familyData(): array
    {
        return [
            ['عمر مصطفى بن عاشور',   'omar.binashour',      'm', 1979, 0,  'libyan',    'ليبيا',   ['الزبير', 'البراء']],
            ['سالم أحمد الرقيعي',    'salem.alruqaii',      'm', 1982, 0,  'libyan',    'ليبيا',   ['حذيفة']],
            ['فتحي عبدالله الجضران', 'fathi.aljadran',      'm', 1977, 0,  'libyan',    'ليبيا',   ['عبدالملك', 'أنس']],
            ['فاطمة علي بن سعود',    'fatima.binsaoud',     'f', 1984, 1,  'libyan',    'ليبيا',   ['سراج']],
            ['نبيل رمضان البرعصي',   'nabil.albarassi',     'm', 1980, 1,  'libyan',    'ليبيا',   ['أويس', 'معاذ']],
            ['رجب سالم المجدوب',     'rajab.almajdoub',     'm', 1975, 2,  'libyan',    'ليبيا',   ['صهيب']],
            ['صلاح خالد الحصادي',    'salah.alhasadi',      'm', 1983, 2,  'libyan',    'ليبيا',   ['الأمين', 'عثمان']],
            ['جلال محمد بن غزي',     'jalal.binghazi',      'm', 1978, 3,  'libyan',    'ليبيا',   ['وسيم']],
            ['فوزي إبراهيم الجبالي', 'fawzi.aljabali',      'm', 1981, 3,  'libyan',    'ليبيا',   ['معتصم', 'الطاهر']],
            ['رافع عياد أبوزقية',    'rafa.abuzaqia',       'm', 1976, 4,  'libyan',    'ليبيا',   ['المنتصر']],
            ['منير عبدالسلام الرياني', 'munir.alriani',     'm', 1985, 4,  'libyan',    'ليبيا',   ['قصي', 'أيمن']],
            ['مريم مفتاح الفلاح',    'mariam.alfallah',     'f', 1986, 5,  'libyan',    'ليبيا',   ['عبدالمولى']],
            ['عياد سعد القرقني',     'ayad.alqarqani',      'm', 1974, 5,  'libyan',    'ليبيا',   ['الطاهر', 'سالم']],
            ['محمد علي الورفلي',     'mohamed.alwarfali',   'm', 1979, 6,  'libyan',    'ليبيا',   ['عبدالرحمن']],
            ['عبدالرحمن محمود الزوي', 'abdulrahman.alzawi', 'm', 1980, 6,  'libyan',    'ليبيا',   ['مصعب', 'حمزة']],
            ['يوسف مفتاح القذافي',   'youssef.alqadhafi',   'm', 1977, 7,  'libyan',    'ليبيا',   ['عبدالله']],
            ['آمنة عبدالله الشريف',  'amna.alsharif',       'f', 1987, 7,  'libyan',    'ليبيا',   ['إبراهيم', 'خالد']],
            ['إبراهيم منصور الفيتوري', 'ibrahim.alfituri',  'm', 1973, 8,  'libyan',    'ليبيا',   ['يعقوب']],
            ['عمر خالد التركي',      'omar.alturki',        'm', 1984, 8,  'libyan',    'ليبيا',   ['زكريا', 'يحيى']],
            ['محمود فرج الشريف',     'mahmoud.alsharif',    'm', 1978, 9,  'libyan',    'ليبيا',   ['إسحاق']],
            ['سارة أحمد المنفي',     'sara.almanfi',        'f', 1988, 9,  'libyan',    'ليبيا',   ['آدم', 'نوح']],
            ['عبدالسلام علي بوقعيقيص', 'abdulsalam.bouqaiqis', 'm', 1975, 10, 'libyan', 'ليبيا',   ['طلحة']],
            ['أحمد علي الفاخري',     'ahmed.alfakhri',      'm', 1982, 10, 'libyan',    'ليبيا',   ['الزبير', 'سعد']],
            ['خليفة رمضان الدرسي',   'khalifa.aldarsi',     'm', 1976, 11, 'libyan',    'ليبيا',   ['أسامة']],
            ['مصطفى صالح الشلوي',    'mustafa.alshalwi',    'm', 1985, 11, 'libyan',    'ليبيا',   ['بلال', 'عمار']],
            ['سعيد محمد القطعاني',   'saeed.alqataani',     'm', 1979, 12, 'libyan',    'ليبيا',   ['خبيب']],
            ['علي عبدالله بوزيد',    'ali.bouzid',          'm', 1983, 12, 'libyan',    'ليبيا',   ['سيف', 'مالك']],
            ['محمد سالم الحاسي',     'mohamed.alhasi',      'm', 1977, 13, 'libyan',    'ليبيا',   ['إلياس']],
            ['نوري أحمد بوهدمة',     'nuri.bouhadma',       'm', 1986, 13, 'libyan',    'ليبيا',   ['أنس', 'رياض']],
            ['فرج عمران المجبري',    'faraj.almijbari',     'm', 1974, 14, 'libyan',    'ليبيا',   ['قتيبة']],
            ['وليد مفتاح الزوي',     'walid.alzawi',        'm', 1981, 14, 'libyan',    'ليبيا',   ['هيثم', 'زياد']],
            ['محمد الأمين إدريس',    'mohamed.idris',       'm', 1980, 15, 'foreigner', 'السودان', ['عبدالرحيم']],
            ['طارق سعد المسماري',    'tareq.almismari',     'm', 1984, 15, 'libyan',    'ليبيا',   ['الحسن', 'الحسين']],
            ['عبدالمجيد علي الحساوي', 'abdulmajid.alhasawi', 'm', 1978, 16, 'libyan',   'ليبيا',   ['ياسين']],
            ['أحمد محمود التاورغي',  'ahmed.altawergi',     'm', 1982, 16, 'libyan',    'ليبيا',   ['براء', 'أنيس']],
            ['موسى عيسى دياب',       'moussa.diab',         'm', 1979, 17, 'foreigner', 'تشاد',    ['هارون']],
            ['حسن عبدالنبي المصري',  'hassan.almasri',      'm', 1983, 17, 'foreigner', 'مصر',     ['مصطفى', 'كريم']],
            ['علي محمد الطشاني',     'ali.altashani.p',     'm', 1976, 18, 'libyan',    'ليبيا',   ['أمجد']],
            ['رمضان خالد العجيلي',   'ramadan.alojaili',    'm', 1985, 18, 'libyan',    'ليبيا',   ['شعيب', 'صالح']],
            ['مفتاح سالم الأسمري',   'miftah.alasmari.p',   'm', 1972, 19, 'libyan',    'ليبيا',   ['عبدالقادر']],
            ['عبدالله علي الششتاوي', 'abdullah.alshashtawi.p', 'm', 1986, 19, 'libyan', 'ليبيا',   ['نذير', 'بشير']],
            ['فتحي رمضان الحاراتي',  'fathi.alharati',      'm', 1977, 20, 'libyan',    'ليبيا',   ['أويس']],
            ['عمر سالم السويحلي',    'omar.alsuwaihli',     'm', 1984, 20, 'libyan',    'ليبيا',   ['جابر', 'ثابت']],
            ['عبدالكريم أحمد البرغثي', 'abdulkarim.albarghathi', 'm', 1975, 21, 'libyan', 'ليبيا', ['المهدي']],
            ['إبراهيم فرج العرفي',   'ibrahim.alorfi',      'm', 1983, 21, 'libyan',    'ليبيا',   ['نبيل', 'رشيد']],
            ['خالد مصطفى الكوافي',   'khaled.alkawafi',     'm', 1980, 22, 'libyan',    'ليبيا',   ['وائل']],
            ['سالم عمر المسلاتي',     'salem.almisallati',   'm', 1981, 22, 'libyan',    'ليبيا',   ['أدهم', 'ياسر']],
            ['عبدالناصر فتحي بن سعيد', 'abdulnaser.binsaeed', 'm', 1979, 23, 'libyan',    'ليبيا',   ['مؤمن', 'باسل']],
            ['حسين محمد السويحلي',    'hussein.alsuwaihli',  'm', 1984, 23, 'libyan',    'ليبيا',   ['طارق']],
            ['عبدالله سعد العواكلي',  'abdullah.alawakli',   'm', 1977, 24, 'libyan',    'ليبيا',   ['عمران', 'فارس']],
            ['ناجي مفتاح بوجواري',    'naji.boujwari',       'm', 1983, 24, 'libyan',    'ليبيا',   ['سند']],
            ['الصديق أحمد الحاسي',    'alsiddiq.alhasi',     'm', 1980, 25, 'libyan',    'ليبيا',   ['نعيم', 'مهند']],
            ['مصطفى علي الدرسي',      'mustafa.aldarsi',     'm', 1986, 25, 'libyan',    'ليبيا',   ['رائد']],
        ];
    }

    private function seedFamilies(array $centers, array $teachers, User $admin, string $password): array
    {
        // هاتف الطالب الشخصي يبدأ من هذا التسلسل؛ هاتف ولي الأمر من تسلسل آخر
        $mobilePrefixes = ['091', '092', '093', '094'];

        $parents  = [];
        $students = [];
        $sNo = 0;
        $pNo = 0;

        // طلاب سيُتركون بلا محفّظ (انتقل محفّظهم) ومعهم اسم المحفّظ السابق
        $orphanEvery   = 17; // كل الطالب رقم 17 → teacher_id = null
        $inactiveEvery = 15; // كل الطالب رقم 15 → طالب غير مفعّل (يبقى في السجل)

        foreach ($this->familyData() as $f) {
            [$gName, $gLatin, $gGender, $gYear, $tIdx, $natType, $natName, $children] = $f;

            $teacher  = $teachers[$tIdx];
            $centerId = $teacher->center_id;

            $gPhone = $mobilePrefixes[$pNo % 4] . str_pad((string) (2100000 + $pNo), 7, '0', STR_PAD_LEFT);
            $parent = $this->createUser([
                'name'             => $gName,
                'phone'            => $gPhone,
                'role'             => 'parent',
                'password'         => $password,
                'nationality_type' => $natType,
                'nationality_name' => $natName,
                'id_number'        => $natType === 'libyan'
                    ? $this->nationalId($gGender, $gYear, 4000001 + $pNo)
                    : strtoupper(substr($gLatin, 0, 2)) . (5200000 + $pNo), // رقم جواز للأجنبي
                'is_active'        => true,
            ], $gLatin, 'parent.mutqin.ly');
            $parents[] = $parent;
            $pNo++;

            // اسم العائلة = آخر كلمتين من اسم ولي الأمر (اسم الأب + اللقب)
            // لقب الأسرة = آخر كلمة، مسبوقةً بأداة النسب إن وُجدت (بن / أبو / آل)
            $parts    = preg_split('/\s+/', trim($gName));
            $family   = end($parts);
            $prev     = count($parts) > 1 ? $parts[count($parts) - 2] : null;
            if (in_array($prev, ['بن', 'ابن', 'أبو', 'ابو', 'بو', 'آل', 'ولد'], true)) {
                $family = $prev . ' ' . $family;
            }
            // الأب: يُنسب الابن إليه (اسم الأب + اللقب)؛ الأم: يُنسب للقب الأسرة وحده
            $surname = $gGender === 'f' ? $family : $parts[0] . ' ' . $family;

            foreach ($children as $childFirst) {
                $age       = mt_rand(7, 17);
                $birthYear = (int) now()->year - $age;
                $birthDate = Carbon::create($birthYear, mt_rand(1, 12), mt_rand(1, 28));

                $isInactive = ($sNo % $inactiveEvery === $inactiveEvery - 1);
                $isOrphan   = ($sNo % $orphanEvery === $orphanEvery - 1);

                $students[] = Student::create([
                    'name'             => $childFirst . ' ' . $surname,
                    'birth_date'       => $birthDate->toDateString(),
                    'phone'            => $mobilePrefixes[$sNo % 4] . str_pad((string) (4400000 + $sNo), 7, '0', STR_PAD_LEFT),
                    'national_id'      => $natType === 'libyan'
                        ? $this->nationalId('m', $birthYear, 6000001 + $sNo)
                        : null, // الطالب الأجنبي بلا رقم وطني ليبي
                    'nationality_type' => $natType,
                    'nationality_name' => $natName,
                    'age'              => $age,
                    'guardian_name'    => $gName,
                    'guardian_phone'   => $gPhone,
                    'center_id'        => $centerId,
                    'teacher_id'       => $isOrphan ? null : $teacher->id,
                    'former_teacher_name' => $isOrphan ? $teacher->name : null,
                    'parent_id'        => $parent->id,
                    'enrollment_date'  => now()->subMonths(mt_rand(4, 30))->startOfMonth()->addDays(mt_rand(0, 20)),
                    'is_active'        => !$isInactive,
                    'status_changed_by' => $isInactive ? $admin->id : null,
                    'status_changed_at' => $isInactive ? now()->subDays(mt_rand(20, 120)) : null,
                ]);
                $sNo++;
            }
        }

        $this->counts['users']    = User::count();
        $this->counts['students'] = count($students);

        return [$parents, $students];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6) الحضور — 60 يوماً ما عدا الجمعة، للطلاب المفعّلين وحدهم
    // ─────────────────────────────────────────────────────────────────────────

    private function seedAttendance(array $students, array $managers): void
    {
        $absentNotes = [
            'غياب بعذر — مرض، أُبلغ ولي الأمر هاتفياً.',
            'غياب غير مبرر، تم التواصل مع ولي الأمر.',
            'سفر مع الأسرة خارج المدينة.',
            'ارتباط بامتحانات المدرسة النظامية.',
        ];
        $lateNotes = [
            'تأخر عشر دقائق عن بداية الحلقة.',
            'تأخر بسبب ازدحام الطريق، حضر بقية الحلقة.',
            'تأخر ربع ساعة — نُبّه ولي الأمر.',
        ];
        $presentNotes = [
            'حضور مبكر ومشاركة جيدة في الحلقة.',
            'حضر في الموعد وأتم تسميعه كاملاً.',
            null, null, null, // الأغلب بلا ملاحظة (كما في الواقع)
        ];

        // كل مدير مركز يصحّح بعض السجلات المستوردة من جهاز البصمة
        $managerByCenter = [];
        foreach ($managers as $m) {
            $managerByCenter[$m->center_id] = $m;
        }

        $rows  = [];
        $start = now()->subDays(60)->startOfDay();

        foreach ($students as $student) {
            if (!$student->is_active) {
                continue; // الطلاب غير المفعّلين يسقطون من الحضور (قاعدة النظام)
            }

            for ($d = 0; $d <= 60; $d++) {
                $date = $start->copy()->addDays($d);
                if ($date->isFriday()) {
                    continue; // الجمعة عطلة
                }

                $r = mt_rand(1, 100);
                $status = $r > 91 ? 'absent' : ($r > 81 ? 'late' : 'present');

                // نصف السجلات مستوردة من جهاز البصمة، والباقي إدخال يدوي
                $imported = ($d % 2 === 0);
                $time = $status === 'absent'
                    ? null
                    : sprintf('%02d:%02d', $status === 'late' ? mt_rand(17, 18) : mt_rand(16, 17), mt_rand(0, 59));

                $corrected = $imported && mt_rand(1, 100) > 96 && isset($managerByCenter[$student->center_id]);

                $rows[] = [
                    'student_id'   => $student->id,
                    'teacher_id'   => $student->teacher_id,
                    'center_id'    => $student->center_id,
                    'date'         => $date->toDateString(),
                    'time'         => $time,
                    'status'       => $status,
                    'notes'        => $status === 'absent' ? $this->pick($absentNotes)
                        : ($status === 'late' ? $this->pick($lateNotes) : $this->pick($presentNotes)),
                    'imported_at'  => $imported ? $date->copy()->setTime(20, 30)->toDateTimeString() : null,
                    'corrected_by' => $corrected ? $managerByCenter[$student->center_id]->id : null,
                    'corrected_at' => $corrected ? $date->copy()->addDay()->setTime(9, 15)->toDateTimeString() : null,
                    'created_at'   => $date->copy()->setTime(18, 0)->toDateTimeString(),
                    'updated_at'   => $date->copy()->setTime(18, 0)->toDateTimeString(),
                ];
            }
        }

        // teacher_id إلزامي في جدول الحضور — طلاب بلا محفّظ لا سجل حضور لهم
        $rows = array_values(array_filter($rows, fn ($r) => $r['teacher_id'] !== null));

        $this->bulk('attendances', $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7) الحفظ — تسلسل عكسي متّسق: الناس ← ... ← الملك، والجزء مشتق من السورة
    // ─────────────────────────────────────────────────────────────────────────

    /** يبني خريطة: اسم السورة => [الحزب، رقم الثمن، نص بداية أول ثمن] من فهرس الأثمان. */
    private function athmanBySurah(): array
    {
        $map = [];
        foreach (Athman::orderBy('global_order')->get(['hizb', 'thumn_in_hizb', 'surah_name', 'start_text']) as $a) {
            $map[$a->surah_name][] = [
                'hizb'  => $a->hizb,
                'thumn' => $a->thumn_in_hizb,
                'text'  => $a->start_text,
            ];
        }
        return $map;
    }

    private function pageRange(string $surah): array
    {
        $from = self::SURAH_PAGE[$surah];
        // page_to = صفحة بداية السورة التالية في ترتيب المصحف (أو آخر صفحة)
        $idx = array_search($surah, self::REVERSE_ORDER, true);
        $to  = $idx === 0 ? 604 : (self::SURAH_PAGE[self::REVERSE_ORDER[$idx - 1]] ?? 604);

        return [$from, max($from, $to)];
    }

    private function seedMemorization(array $students): void
    {
        $athman = $this->athmanBySurah();
        $notes  = [
            'excellent' => [
                'حفظ متقن ومخارج حروف واضحة، ما شاء الله.',
                'تسميع بلا خطأ واحد مع التزام تام بأحكام التجويد.',
                'أداء ممتاز وصوت جميل، يُشجَّع على المواصلة.',
            ],
            'good' => [
                'تسميع جيد مع تردد بسيط في أواخر السورة.',
                'حفظ جيد، يحتاج تثبيت الآيات المتشابهة.',
                'أداء جيد مع ملاحظة على سرعة القراءة.',
            ],
            'average' => [
                'يحتاج إعادة الحفظ قبل الانتقال للسورة التالية.',
                'تسميع مقبول مع أخطاء في المدود، يُعاد غداً.',
            ],
            'weak' => [
                'الحفظ ضعيف هذا اليوم — يُطلب من ولي الأمر المتابعة في البيت.',
                'يحتاج إلى مراجعة زمن المد الطبيعي وحكم الإظهار.',
            ],
        ];

        $rows = [];
        foreach ($students as $student) {
            if ($student->teacher_id === null) {
                continue; // teacher_id إلزامي في جدول الحفظ
            }

            // عمق التقدّم: كم سورة أنجز الطالب من الناس نزولاً (حسب عمره وأقدميته)
            $depth = min(count(self::REVERSE_ORDER), max(4, (int) round($student->age * mt_rand(9, 22) / 10)));

            $date = Carbon::parse($student->enrollment_date)->max(now()->subDays(150));
            for ($i = 0; $i < $depth; $i++) {
                $surah = self::REVERSE_ORDER[$i];
                [$from, $to] = $this->pageRange($surah);

                $q = mt_rand(1, 100);
                $quality = $q > 92 ? 'weak' : ($q > 78 ? 'average' : ($q > 45 ? 'good' : 'excellent'));

                $entry = isset($athman[$surah]) ? $athman[$surah][0] : null;

                $rows[] = [
                    'student_id' => $student->id,
                    'teacher_id' => $student->teacher_id,
                    'date'       => $date->toDateString(),
                    'surah_name' => $surah,
                    // الجزء مشتق من المرجع الثابت — لا إدخال يدوي متضارب
                    'juz'        => SurahReference::SURAHS[$surah],
                    'hizb'       => $entry['hizb'] ?? (int) ceil(SurahReference::SURAHS[$surah] * 2),
                    'page_from'  => $from,
                    'page_to'    => $to,
                    'eighth'     => $entry
                        ? (self::THUMN_NAMES[$entry['thumn']] . ' من الحزب ' . $entry['hizb'])
                        : 'الثمن الأول من الحزب 60',
                    'quality'    => $quality,
                    'notes'      => $this->pick($notes[$quality]),
                    'created_at' => $date->copy()->setTime(18, 30)->toDateTimeString(),
                    'updated_at' => $date->copy()->setTime(18, 30)->toDateTimeString(),
                ];

                $date = $date->copy()->addDays(mt_rand(2, 5));
                if ($date->gt(now())) {
                    break;
                }
            }
        }

        $this->bulk('memorizations', $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8) المراجعة — على ما سبق حفظه فعلاً
    // ─────────────────────────────────────────────────────────────────────────

    private function seedRevisions(array $students): void
    {
        $notes = [
            'تمت المراجعة بامتياز تام ولله الحمد.',
            'مراجعة جيدة، يُنصح بتثبيت نهايات الآيات المتشابهة.',
            'مراجعة مقبولة مع نسيان في موضعين، تُعاد الأسبوع القادم.',
            'يحتاج إلى ورد مراجعة يومي في البيت.',
            'مراجعة ممتازة مع تحسّن ملحوظ في أحكام الغنة.',
        ];

        $rows = [];
        foreach ($students as $student) {
            if ($student->teacher_id === null) {
                continue;
            }

            $done = Memorization::where('student_id', $student->id)->pluck('surah_name')->all();
            if (empty($done)) {
                continue;
            }

            $date  = now()->subDays(mt_rand(40, 55));
            $count = mt_rand(6, 14);
            for ($i = 0; $i < $count && $date->lte(now()); $i++) {
                $surah = $this->pick($done);
                [$from, $to] = $this->pageRange($surah);

                $q = mt_rand(1, 100);
                $quality = $q > 90 ? 'weak' : ($q > 74 ? 'average' : ($q > 40 ? 'good' : 'excellent'));

                $rows[] = [
                    'student_id' => $student->id,
                    'teacher_id' => $student->teacher_id,
                    'date'       => $date->toDateString(),
                    'surah_name' => $surah,
                    'page_from'  => $from,
                    'page_to'    => $to,
                    'quality'    => $quality,
                    'notes'      => $this->pick($notes),
                    'created_at' => $date->copy()->setTime(19, 0)->toDateTimeString(),
                    'updated_at' => $date->copy()->setTime(19, 0)->toDateTimeString(),
                ];

                $date = $date->copy()->addDays(mt_rand(3, 6));
            }
        }

        $this->bulk('revisions', $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9) تقييمات التجويد
    // ─────────────────────────────────────────────────────────────────────────

    private function seedTajweed(array $students): void
    {
        $notes = [
            'مخارج الحروف والصفات ممتازة، يُراجع أحكام النون الساكنة والتنوين.',
            'يحتاج إلى ضبط زمن المد المتصل والمنفصل (أربع حركات).',
            'أداء متزن في الوقف والابتداء، مع ملاحظة على القلقلة الكبرى.',
            'تحسّن واضح في الإخفاء الشفوي مقارنة بالتقييم السابق.',
            'يُنصح بحضور دورة الأحكام المسائية لتقوية الصفات.',
        ];

        $rows = [];
        foreach ($students as $student) {
            if ($student->teacher_id === null) {
                continue;
            }

            $times = mt_rand(2, 4);
            for ($t = $times; $t >= 1; $t--) {
                $date = now()->subWeeks($t * 3)->startOfWeek(Carbon::SATURDAY)->addDays(2);
                if ($date->gt(now())) {
                    continue;
                }

                $makharij = mt_rand(6, 10);
                $sifat    = mt_rand(6, 10);
                $madd     = mt_rand(5, 10);
                $waqf     = mt_rand(6, 10);

                $rows[] = [
                    'student_id'     => $student->id,
                    'teacher_id'     => $student->teacher_id,
                    'date'           => $date->toDateString(),
                    'makharij_score' => $makharij,
                    'sifat_score'    => $sifat,
                    'madd_score'     => $madd,
                    'waqf_score'     => $waqf,
                    'total_score'    => $makharij + $sifat + $madd + $waqf,
                    'notes'          => $this->pick($notes),
                    'created_at'     => $date->copy()->setTime(17, 45)->toDateTimeString(),
                    'updated_at'     => $date->copy()->setTime(17, 45)->toDateTimeString(),
                ];
            }
        }

        $this->bulk('tajweed_evaluations', $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10) الاختبارات الأسبوعية + أسئلة الأثمان (نص البداية من فهرس الأثمان)
    // ─────────────────────────────────────────────────────────────────────────

    private function seedWeeklyTests(array $students): void
    {
        $athmanRows = Athman::where('hizb', '>=', 55)
            ->orderBy('global_order')
            ->get(['hizb', 'thumn_in_hizb', 'surah_name', 'start_text'])
            ->all();

        $mistakes = [
            'خطأ في ترتيب الآيتين الخامسة والسادسة.',
            'نسيان آية كاملة في وسط الثمن.',
            'تبديل كلمة «يعملون» بـ«يفعلون».',
            'خطأ في حكم الإدغام بغنة وتوقف عند التذكّر.',
            'تردد طويل احتاج معه إلى فتح المصحف.',
        ];
        $passNotes = [
            'أداء متميز في التسميع واجتاز الاختبار بتقدير ممتاز.',
            'اجتاز جميع الأثمان بلا خطأ يُذكر، بارك الله فيه.',
            'نتيجة جيدة مع ملاحظة بسيطة على سرعة الأداء.',
        ];
        $failNotes = [
            'لم يستعد جيداً للمراجعة المقررة هذا الأسبوع، يُعاد الاختبار.',
            'رسب في ثمن واحد فقط — يُعاد اختباره فيه الأسبوع القادم.',
            'يحتاج إلى متابعة أسرية أدق قبل إعادة الاختبار.',
        ];

        $tests     = [];
        $questions = [];

        foreach ($students as $student) {
            if ($student->teacher_id === null) {
                continue;
            }

            $weeks = mt_rand(4, 7);
            for ($w = $weeks; $w >= 1; $w--) {
                $examDate = now()->subWeeks($w)->startOfWeek(Carbon::SATURDAY)->addDays(4); // الأربعاء
                if ($examDate->gt(now())) {
                    continue;
                }

                // 2..4 أثمان لكل اختبار من فهرس الأثمان الحقيقي
                $picked = [];
                $n = mt_rand(2, 4);
                for ($i = 0; $i < $n; $i++) {
                    $picked[] = $this->pick($athmanRows);
                }

                $qRows = [];
                $failed = false;
                foreach ($picked as $a) {
                    $ok = mt_rand(1, 100) > 18;
                    $failed = $failed || !$ok;
                    $qRows[] = [
                        'student_id'   => $student->id,
                        'eighth_start' => $a->start_text,
                        'result'       => $ok ? 'ناجح' : 'راسب',
                        'mistake'      => $ok ? null : $this->pick($mistakes),
                        'created_at'   => $examDate->copy()->setTime(17, 0)->toDateTimeString(),
                        'updated_at'   => $examDate->copy()->setTime(17, 0)->toDateTimeString(),
                    ];
                }

                $tests[] = [
                    'row' => [
                        'student_id' => $student->id,
                        'teacher_id' => $student->teacher_id,
                        'exam_date'  => $examDate->toDateString(),
                        'result'     => $failed ? 'راسب' : 'ناجح',
                        'notes'      => $failed ? $this->pick($failNotes) : $this->pick($passNotes),
                        'created_at' => $examDate->copy()->setTime(17, 0)->toDateTimeString(),
                        'updated_at' => $examDate->copy()->setTime(17, 0)->toDateTimeString(),
                    ],
                    'questions' => $qRows,
                ];
            }
        }

        // إدراج الاختبارات ثم ربط الأسئلة بمعرّفاتها
        $firstId = DB::table('weekly_tests')->max('id') ?? 0;
        $this->bulk('weekly_tests', array_column($tests, 'row'));

        $ids = DB::table('weekly_tests')->where('id', '>', $firstId)->orderBy('id')->pluck('id')->all();
        foreach ($tests as $k => $t) {
            foreach ($t['questions'] as $q) {
                $questions[] = array_merge($q, ['weekly_test_id' => $ids[$k]]);
            }
        }

        $this->bulk('weekly_test_questions', $questions);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11) طلبات الطلاب — إضافة/نقل بالحالات الثلاث (معلّق/مقبول/مرفوض)
    // ─────────────────────────────────────────────────────────────────────────

    private function seedStudentRequests(array $centers, array $teachers, array $students, User $admin): void
    {
        $rows = [];
        $now  = now();

        // (أ) طلبات إضافة طالب جديد — لقطة بيانات الطالب وولي أمره
        $newStudents = [
            ['يونس عبدالحفيظ الزليتني', 9,  'عبدالحفيظ سالم الزليتني', 'زليتن',  0, 'pending',  null],
            ['عبدالرزاق منير الغرياني', 12, 'منير أحمد الغرياني',      'طرابلس', 1, 'approved', 'طلب مستوفٍ — تمت إضافة الطالب إلى حلقة المحفّظ.'],
            ['سيف الإسلام رجب الترهوني', 14, 'رجب علي الترهوني',       'طرابلس', 2, 'rejected', 'العمر خارج الفئة المستهدفة لهذه الحلقة، يُحوَّل إلى حلقة المساء.'],
            ['أحمد ياسين الشيباني',     8,  'ياسين محمد الشيباني',     'مصراتة', 6, 'pending',  null],
            ['عبدالعزيز فرج المسلاتي',  11, 'فرج عمر المسلاتي',        'الخمس',  22, 'approved', 'تمت الموافقة بعد التأكد من الرقم الوطني.'],
            ['محمد الأمين بشير النوبي', 10, 'بشير عثمان النوبي',       'سبها',   16, 'pending',  null],
        ];

        foreach ($newStudents as $i => [$sName, $age, $gName, $city, $tIdx, $status, $note]) {
            $teacher = $teachers[$tIdx];
            $rows[] = [
                'type'                      => 'add',
                'status'                    => $status,
                'requested_by'              => $teacher->id,
                'target_center_id'          => $teacher->center_id,
                'target_teacher_id'         => $teacher->id,
                'student_id'                => null,
                'national_id'               => $this->nationalId('m', (int) $now->year - $age, 7100001 + $i),
                'nationality_type'          => 'libyan',
                'nationality_name'          => 'ليبيا',
                'student_name'              => $sName,
                'age'                       => $age,
                'phone'                     => '0915' . str_pad((string) (500001 + $i), 6, '0', STR_PAD_LEFT),
                'guardian_name'             => $gName,
                'guardian_phone'            => '0925' . str_pad((string) (600001 + $i), 6, '0', STR_PAD_LEFT),
                'guardian_email'            => null,
                'guardian_nationality_type' => 'libyan',
                'guardian_nationality_name' => 'ليبيا',
                'guardian_id_number'        => $this->nationalId('m', 1980 + $i, 7200001 + $i),
                'from_center_id'            => null,
                'from_teacher_id'           => null,
                'admin_note'                => $note,
                'created_at'                => $now->copy()->subDays(30 - $i * 4)->toDateTimeString(),
                'updated_at'                => $now->copy()->subDays(28 - $i * 4)->toDateTimeString(),
            ];
        }

        // (ب) طلبات نقل لطلاب موجودين — داخلية (نفس المركز) وخارجية (بين مركزين)
        $transferable = array_values(array_filter($students, fn ($s) => $s->is_active && $s->teacher_id));
        $plan = [
            [0,  'pending',  true,  null],
            [7,  'approved', true,  'نقل داخلي بين حلقتي المركز — موافقة مدير المركز.'],
            [14, 'rejected', false, 'المركز المستقبل مكتمل العدد هذا الفصل.'],
            [21, 'pending',  false, null],
            [28, 'approved', false, 'تمت الموافقة بعد موافقة ولي الأمر خطياً.'],
            [35, 'pending',  true,  null],
            [42, 'approved', true,  'نقل داخلي لتناسب المستوى مع حلقة المحفّظ المعاون.'],
            [49, 'rejected', false, 'الطالب مسجّل بالفعل في مركز آخر بنفس الرقم الوطني.'],
        ];

        foreach ($plan as $i => [$sIdx, $status, $internal, $note]) {
            if (!isset($transferable[$sIdx])) {
                continue;
            }
            $student = $transferable[$sIdx];

            // المحفّظ المستقبل: من نفس المركز (نقل داخلي) أو من مركز آخر
            $candidates = array_values(array_filter(
                $teachers,
                fn ($t) => $internal
                    ? ($t->center_id === $student->center_id && $t->id !== $student->teacher_id)
                    : ($t->center_id !== $student->center_id)
            ));
            if (empty($candidates)) {
                continue;
            }
            $target = $candidates[$i % count($candidates)];

            $rows[] = [
                'type'                      => 'transfer',
                'status'                    => $status,
                'requested_by'              => $target->id, // المحفّظ المستقبل هو من يطلب
                'target_center_id'          => $target->center_id,
                'target_teacher_id'         => $target->id,
                'student_id'                => $student->id,
                'national_id'               => $student->national_id,
                'nationality_type'          => $student->nationality_type,
                'nationality_name'          => $student->nationality_name,
                'student_name'              => $student->name,
                'age'                       => $student->age,
                'phone'                     => $student->phone,
                'guardian_name'             => $student->guardian_name,
                'guardian_phone'            => $student->guardian_phone,
                'guardian_email'            => $student->parent?->email,
                'guardian_nationality_type' => $student->nationality_type,
                'guardian_nationality_name' => $student->nationality_name,
                'guardian_id_number'        => $student->parent?->id_number,
                'from_center_id'            => $student->center_id,
                'from_teacher_id'           => $student->teacher_id,
                'admin_note'                => $note,
                'created_at'                => $now->copy()->subDays(26 - $i * 3)->toDateTimeString(),
                'updated_at'                => $now->copy()->subDays(24 - $i * 3)->toDateTimeString(),
            ];
        }

        $this->bulk('student_requests', $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 12) الرسائل — محادثات ولي الأمر ↔ المحفّظ حول ابن محدّد
    // ─────────────────────────────────────────────────────────────────────────

    private function seedMessages(array $students): void
    {
        $parentMsgs = [
            'السلام عليكم شيخ، كيف مستوى الولد في التسميع هذا الأسبوع؟',
            'جزاك الله خيراً على متابعتك، سنراجع معه الورد في البيت إن شاء الله.',
            'اعتذر عن غياب اليوم، الولد عنده امتحان في المدرسة.',
            'هل يمكن تأجيل اختبار هذا الأسبوع ليوم الخميس؟',
            'بارك الله فيكم، لاحظنا تحسناً كبيراً في تلاوته.',
            'سنسافر إلى بنغازي لمدة أسبوع، هل نأخذ له ورداً يراجعه هناك؟',
        ];
        $teacherMsgs = [
            'وعليكم السلام، مستواه جيد ولله الحمد، يحتاج فقط تثبيت المتشابهات.',
            'أنصح بمراجعة ربع صفحة يومياً بعد صلاة الفجر.',
            'لا بأس، نسأل الله له التوفيق في امتحاناته.',
            'تم تسجيل الغياب بعذر، وسنعوّض الحصة يوم السبت إن شاء الله.',
            'حضوره منتظم وأداؤه في التجويد تحسّن كثيراً هذا الشهر.',
            'نعم، سأرسل له ورد المراجعة للأسبوع القادم.',
        ];

        $rows    = [];
        $threads = array_values(array_filter(
            $students,
            fn ($s) => $s->is_active && $s->teacher_id && $s->parent_id
        ));

        foreach (array_slice($threads, 0, 30) as $k => $student) {
            $count = mt_rand(3, 6);
            $when  = now()->subDays(mt_rand(10, 40));

            for ($i = 0; $i < $count; $i++) {
                $fromParent = ($i % 2 === 0);
                $isLast     = ($i === $count - 1);

                $rows[] = [
                    'student_id'  => $student->id,
                    'sender_id'   => $fromParent ? $student->parent_id : $student->teacher_id,
                    'sender_role' => $fromParent ? 'parent' : 'teacher',
                    'body'        => $fromParent
                        ? $parentMsgs[($k + $i) % count($parentMsgs)]
                        : $teacherMsgs[($k + $i) % count($teacherMsgs)],
                    // آخر رسالة في ثلث المحادثات تبقى غير مقروءة (شارة «جديد»)
                    'read_at'     => ($isLast && $k % 3 === 0)
                        ? null
                        : $when->copy()->addHours(mt_rand(1, 20))->toDateTimeString(),
                    'created_at'  => $when->toDateTimeString(),
                    'updated_at'  => $when->toDateTimeString(),
                ];

                $when = $when->copy()->addHours(mt_rand(3, 36));
                if ($when->gt(now())) {
                    $when = now()->subHours(mt_rand(1, 5));
                }
            }
        }

        $this->bulk('messages', $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 13) الإشعارات داخل التطبيق (قناة قاعدة البيانات)
    // ─────────────────────────────────────────────────────────────────────────

    private function seedNotifications(array $students, User $admin, array $managers): void
    {
        $rows = [];
        $push = function (int $userId, string $type, string $title, string $body, ?int $refId, ?string $link, Carbon $at, bool $read) use (&$rows) {
            $rows[] = [
                'id'              => (string) Str::uuid(),
                'type'            => \App\Notifications\InAppNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id'   => $userId,
                'data'            => json_encode([
                    'type' => $type, 'title' => $title, 'body' => $body,
                    'ref_id' => $refId, 'link' => $link,
                ], JSON_UNESCAPED_UNICODE),
                'read_at'         => $read ? $at->copy()->addHours(2)->toDateTimeString() : null,
                'created_at'      => $at->toDateTimeString(),
                'updated_at'      => $at->toDateTimeString(),
            ];
        };

        $qualityLabels = ['excellent' => 'ممتاز', 'good' => 'جيد', 'average' => 'مقبول', 'weak' => 'ضعيف'];

        // إشعارات أولياء الأمور: آخر حفظ + آخر اختبار لكل ابن
        foreach (array_slice($students, 0, 60) as $i => $student) {
            if (!$student->parent_id) {
                continue;
            }

            $mem = Memorization::where('student_id', $student->id)->latest('date')->first();
            if ($mem) {
                $push(
                    $student->parent_id, 'memorization_added', 'تسجيل حفظ جديد',
                    'سجّل المحفّظ حفظاً جديداً لابنك «' . $student->name . '»: سورة ' . $mem->surah_name
                        . ' (التقييم: ' . ($qualityLabels[$mem->quality] ?? $mem->quality) . ').',
                    $student->id, 'parent/child.html?id=' . $student->id,
                    Carbon::parse($mem->date)->setTime(18, 40), $i % 3 !== 0
                );
            }

            $test = WeeklyTest::where('student_id', $student->id)->latest('exam_date')->first();
            if ($test) {
                $push(
                    $student->parent_id, 'test_added', 'اختبار أسبوعي جديد',
                    'تم تسجيل اختبار أسبوعي لابنك «' . $student->name . '» — النتيجة: ' . $test->result . '.',
                    $test->id, 'parent/child.html?id=' . $student->id,
                    Carbon::parse($test->exam_date)->setTime(17, 10), $i % 4 !== 0
                );
            }
        }

        // إشعارات الطلبات: المعلّقة إلى مدير المركز (داخلية) أو مدير النظام
        $managerByCenter = [];
        foreach ($managers as $m) {
            $managerByCenter[$m->center_id] = $m;
        }

        foreach (StudentRequest::where('status', 'pending')->get() as $req) {
            $internal = $req->from_center_id !== null
                && (int) $req->from_center_id === (int) $req->target_center_id;
            $recipient = $internal && isset($managerByCenter[$req->target_center_id])
                ? $managerByCenter[$req->target_center_id]->id
                : $admin->id;

            $push(
                $recipient, 'request_created', 'طلب جديد بانتظار الموافقة',
                'طلب ' . ($req->type === 'add' ? 'إضافة' : 'نقل') . ' الطالب «' . $req->student_name . '» بانتظار مراجعتك.',
                $req->id, $recipient === $admin->id ? 'admin/requests.html' : 'manager/requests.html',
                Carbon::parse($req->created_at), false
            );
        }

        // إشعارات نتيجة الطلبات إلى المحفّظ صاحب الطلب
        foreach (StudentRequest::whereIn('status', ['approved', 'rejected'])->get() as $req) {
            $approved = $req->status === 'approved';
            $push(
                $req->requested_by,
                $approved ? 'request_approved' : 'request_rejected',
                $approved ? 'تمت الموافقة على طلبك' : 'تم رفض طلبك',
                ($approved ? 'تمت الموافقة على ' : 'تم رفض ')
                    . ($req->type === 'add' ? 'إضافة' : 'نقل') . ' الطالب «' . $req->student_name . '»'
                    . ($req->admin_note ? ' — ' . $req->admin_note : '.'),
                $req->id, 'teacher/requests.html',
                Carbon::parse($req->updated_at), (bool) mt_rand(0, 1)
            );
        }

        // إشعارات الرسائل غير المقروءة
        foreach (Message::whereNull('read_at')->get() as $msg) {
            $student = $students[0];
            foreach ($students as $s) {
                if ($s->id === $msg->student_id) {
                    $student = $s;
                    break;
                }
            }
            $receiver = $msg->sender_role === 'parent' ? $student->teacher_id : $student->parent_id;
            if (!$receiver) {
                continue;
            }

            $push(
                $receiver, 'message_received', 'رسالة جديدة بخصوص «' . $student->name . '»',
                ($msg->sender_role === 'parent' ? 'ولي الأمر' : 'المحفّظ') . ': ' . mb_substr($msg->body, 0, 80),
                $student->id,
                ($msg->sender_role === 'parent' ? 'teacher' : 'parent') . '/messages.html?student=' . $student->id,
                Carbon::parse($msg->created_at), false
            );
        }

        $this->bulk('notifications', $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 14) سجل تغيير كلمات المرور (تدقيق) — لبعض الحسابات
    // ─────────────────────────────────────────────────────────────────────────

    private function seedPasswordChangeLogs(array $teachers, array $parents, User $admin): void
    {
        $sample = array_merge(
            array_slice($teachers, 0, 8),
            array_slice($parents, 0, 6),
            [$admin]
        );

        $rows = [];
        foreach ($sample as $i => $user) {
            $method = ['self', 'otp', 'admin'][$i % 3];
            $times  = mt_rand(1, 2);

            for ($t = $times; $t >= 1; $t--) {
                $at = now()->subDays(mt_rand(15, 200));
                $rows[] = [
                    'user_id'    => $user->id,
                    'changed_at' => $at->toDateTimeString(),
                    'method'     => $method,
                    'created_at' => $at->toDateTimeString(),
                    'updated_at' => $at->toDateTimeString(),
                ];
            }

            // عدّاد التغيير على المستخدم نفسه (يتّسق مع عدد أسطر السجل)
            $user->forceFill([
                'password_changed_count'   => $times,
                'password_last_changed_at' => now()->subDays(mt_rand(15, 200)),
            ])->save();
        }

        $this->bulk('password_change_logs', $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تقرير ختامي
    // ─────────────────────────────────────────────────────────────────────────

    private function report(): void
    {
        $this->counts['users']    = User::count();
        $this->counts['students'] = Student::count();
        $this->counts['centers']  = Center::count();
        $this->counts['athman']   = Athman::count();

        ksort($this->counts);
        $this->command?->newLine();
        $this->command?->info('═══ ملخّص البذر ═══');
        foreach ($this->counts as $table => $n) {
            $this->command?->line(sprintf('  %-24s %6d', $table, $n));
        }
        $this->command?->newLine();
        $this->command?->info('كلمة المرور لكل الحسابات التجريبية: ' . self::DEMO_PASSWORD);
        $this->command?->line('  مدير النظام:  admin@mutqin.ly');
        $this->command?->line('  مدير مركز:    {latin}.centeradmin@mutqin.ly');
        $this->command?->line('  محفّظ:        {latin}.{id}@mutqin.ly');
        $this->command?->line('  ولي أمر:      {latin}.{id}@parent.mutqin.ly');
    }
}
