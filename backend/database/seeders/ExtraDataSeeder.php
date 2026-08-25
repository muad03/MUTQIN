<?php

namespace Database\Seeders;

use App\Models\Athman;
use App\Models\Center;
use App\Models\Student;
use App\Models\User;
use App\Support\SurahReference;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * بيانات ليبية إضافية — تُضاف فوق البذر الأساسي ولا تمسحه.
 *
 * توسّع النظام إلى ست مدن ليبية أخرى (غريان، سرت، بني وليد، ترهونة،
 * صبراتة، الأبيار) بمراكزها ومدرائها ومحفّظيها وأُسرها وسجلات حلقاتها:
 *   - محفّظ أساسي واحد لكل مركز جديد (تُفرض القاعدة عند البذر).
 *   - مدير مركز واحد لكل مركز جديد ببريد {latin}.centeradmin@mutqin.ly.
 *   - الإخوة يتشاركون حساب ولي أمر واحد (نفس الهاتف والرقم الوطني).
 *   - نطاقات أرقام/هواتف/بريد مستقلة عن البذر الأساسي فلا تتعارض معه.
 *
 * التشغيل:  php artisan db:seed --class=ExtraDataSeeder
 * (إعادة تشغيله تضيف دفعة جديدة فوق السابقة — بيانات جديدة لا نسخة مكرّرة.)
 */
class ExtraDataSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'mutqin2026';

    /** ترتيب الحفظ العكسي (جزء 30 ثم 29) — نفس مرجع البذر الأساسي. */
    private const REVERSE_ORDER = [
        'الناس', 'الفلق', 'الإخلاص', 'المسد', 'النصر', 'الكافرون', 'الكوثر', 'الماعون',
        'قريش', 'الفيل', 'الهمزة', 'العصر', 'التكاثر', 'القارعة', 'العاديات', 'الزلزلة',
        'البينة', 'القدر', 'العلق', 'التين', 'الشرح', 'الضحى', 'الليل', 'الشمس', 'البلد',
        'الفجر', 'الغاشية', 'الأعلى', 'الطارق', 'البروج', 'الانشقاق', 'المطففين',
        'الانفطار', 'التكوير', 'عبس', 'النازعات', 'النبأ',
        'المرسلات', 'الإنسان', 'القيامة', 'المدثر', 'المزمل', 'الجن', 'نوح', 'المعارج',
        'الحاقة', 'القلم', 'الملك',
    ];

    /** صفحة بداية السورة في مصحف المدينة (جزءا 29 و30). */
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

    private array $counts = [];

    public function run(): void
    {
        // نطاق مستقل لكل دفعة إضافية → لا تصادم في الهواتف والأرقام الوطنية
        $batch = (int) (Center::count() + User::count());
        mt_srand(90000 + $batch);

        $password = Hash::make(self::DEMO_PASSWORD);
        $admin    = User::where('role', 'admin')->first();

        $centers  = $this->seedCenters();
        $this->seedManagers($centers, $password, $batch);
        $teachers = $this->seedTeachers($centers, $password, $batch);
        $students = $this->seedFamilies($teachers, $password, $batch, $admin);

        $this->seedRecords($students);

        $this->report();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function createUser(array $attrs, string $latin, string $domain = 'mutqin.ly'): User
    {
        $user = User::create(array_merge($attrs, [
            'email' => 'tmp-' . Str::random(18) . '@mutqin.ly',
        ]));
        $user->forceFill([
            'email'             => $latin . '.' . $user->id . '@' . $domain,
            'email_verified_at' => now()->subDays(mt_rand(20, 200)),
        ])->save();

        return $user;
    }

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

    private function nationalId(string $gender, int $birthYear, int $serial): string
    {
        return ($gender === 'f' ? '2' : '1') . $birthYear . str_pad((string) $serial, 7, '0', STR_PAD_LEFT);
    }

    /** بريد لاتيني فريد لمدير المركز حتى مع تكرار تشغيل البذر. */
    private function uniqueManagerEmail(string $prefix): string
    {
        $base  = $prefix;
        $try   = $base;
        $suffix = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];
        foreach ($suffix as $s) {
            $try = $base . ($s === '' ? '' : '.' . $s);
            if (!User::where('email', $try . '.centeradmin@mutqin.ly')->exists()) {
                return $try . '.centeradmin@mutqin.ly';
            }
        }
        return $base . '.' . Str::lower(Str::random(4)) . '.centeradmin@mutqin.ly';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1) مراكز في ست مدن ليبية أخرى
    // ─────────────────────────────────────────────────────────────────────────

    private function seedCenters(): array
    {
        $data = [
            ['مركز الاستقامة لتحفيظ القرآن الكريم', 'غريان',     'حي الياسمين — قرب مستشفى غريان التعليمي', '0414441401'],
            ['مركز البشائر لتحفيظ القرآن الكريم',   'سرت',       'الجيزة البحرية — شارع دبي',                 '0544442402'],
            ['مركز الوفاء لتحفيظ القرآن الكريم',    'بني وليد',  'حي الشوارف — بجوار الجامع الكبير',          '0322443403'],
            ['مركز الإخلاص لتحفيظ القرآن الكريم',   'ترهونة',    'وسط المدينة — شارع السوق',                  '0325444404'],
            ['مركز الصفا لتحفيظ القرآن الكريم',     'صبراتة',    'حي الحنشير — قرب الموقع الأثري',            '0244445405'],
            ['مركز الأنوار لتحفيظ القرآن الكريم',   'الأبيار',   'الحي الشرقي — قرب مجمع المدارس',            '0674446406'],
        ];

        $centers = [];
        foreach ($data as [$name, $city, $address, $phone]) {
            $centers[] = Center::create([
                'name'      => $name,
                'city'      => $city,
                'address'   => $address,
                'phone'     => $phone,
                'is_active' => true,
            ]);
        }
        $this->counts['centers'] = count($centers);

        return $centers;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2) مدير لكل مركز جديد
    // ─────────────────────────────────────────────────────────────────────────

    private function seedManagers(array $centers, string $password, int $batch): void
    {
        $data = [
            ['عبدالمنعم سالم الغرياني',  'abdulmunim', '0913200101', 1972],
            ['خيري عبدالله القذافي',      'khairi',     '0923200102', 1976],
            ['الطاهر محمد الورفلي',       'altaher',    '0933200103', 1968],
            ['سليمان أحمد الترهوني',      'suleiman',   '0943200104', 1974],
            ['عبدالحميد فرج الصبراتي',    'abdulhamid', '0913200105', 1981],
            ['فرحات علي العبيدي',         'farhat',     '0923200106', 1970],
        ];

        foreach ($data as $i => [$name, $latin, $phone, $year]) {
            $m = User::create([
                'name'             => $name,
                'email'            => $this->uniqueManagerEmail($latin),
                'phone'            => $phone,
                'role'             => 'center_manager',
                'center_id'        => $centers[$i]->id,
                'password'         => $password,
                'nationality_type' => 'libyan',
                'nationality_name' => 'ليبيا',
                'id_number'        => $this->nationalId('m', $year, 8000001 + $batch * 100 + $i),
                'is_active'        => true,
            ]);
            $m->forceFill(['email_verified_at' => now()->subDays(mt_rand(30, 250))])->save();
        }
        $this->counts['center_managers'] = count($data);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3) المحفّظون — أساسي واحد لكل مركز، والبقية معاونون
    // ─────────────────────────────────────────────────────────────────────────

    private function seedTeachers(array $centers, string $password, int $batch): array
    {
        $data = [
            ['عبدالناصر سعد الزوام',    'abdulnaser.alzuwam',   0, 'محفظ أساسي', '0913300201', 1975],
            ['المهدي عاشور الغرياني',   'almahdi.algharyani',   0, 'محفظ معاون', '0923300202', 1990],
            ['الصادق عوض المغربي',      'alsadiq.almaghrabi',   1, 'محفظ أساسي', '0933300203', 1973],
            ['عصام فرج بالنور',         'issam.balnour',        1, 'محفظ معاون', '0943300204', 1989],
            ['نادر حسين الأوجلي',       'nader.alawjali',       2, 'محفظ أساسي', '0913300205', 1971],
            ['محمد سالم الورفلي',       'mohamed.alwarfali.t',  2, 'محفظ معاون', '0923300206', 1987],
            ['وليد عبدالسلام بن حليم',  'walid.binhalim',       3, 'محفظ أساسي', '0933300207', 1978],
            ['عادل منصور الأسطى',       'adel.alosta',          3, 'محفظ معاون', '0943300208', 1992],
            ['حاتم عبدالجليل الصبراتي', 'hatem.alsabrati',      4, 'محفظ أساسي', '0913300209', 1976],
            ['أيمن رجب الزنتاني',       'ayman.alzintani',      4, 'محفظ معاون', '0923300210', 1991],
            ['عبدالحفيظ سعيد العواكلي', 'abdulhafiz.alawakli',  5, 'محفظ أساسي', '0933300211', 1969],
            ['سفيان مفتاح الجازوي',     'sufyan.aljazwi',       5, 'محفظ معاون', '0943300212', 1988],
        ];

        $teachers = [];
        $primaryTaken = [];

        foreach ($data as $i => [$name, $latin, $ci, $type, $phone, $year]) {
            $centerId = $centers[$ci]->id;

            // فرض قاعدة «محفّظ أساسي واحد لكل مركز» عند البذر أيضاً
            if ($type === 'محفظ أساسي') {
                $alreadyPrimary = isset($primaryTaken[$centerId]) || User::where('role', 'teacher')
                    ->where('type', 'محفظ أساسي')->where('center_id', $centerId)->exists();
                if ($alreadyPrimary) {
                    $type = 'محفظ معاون';
                } else {
                    $primaryTaken[$centerId] = true;
                }
            }

            $teachers[] = $this->createUser([
                'name'             => $name,
                'phone'            => $phone,
                'role'             => 'teacher',
                'password'         => $password,
                'center_id'        => $centerId,
                'type'             => $type,
                'nationality_type' => 'libyan',
                'nationality_name' => 'ليبيا',
                'id_number'        => $this->nationalId('m', $year, 8100001 + $batch * 100 + $i),
                'is_active'        => true,
            ], $latin);
        }
        $this->counts['teachers'] = count($teachers);

        return $teachers;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4) الأُسر والطلاب
    // ─────────────────────────────────────────────────────────────────────────

    private function seedFamilies(array $teachers, string $password, int $batch, ?User $admin): array
    {
        // [ولي الأمر، اللاتيني، الجنس، سنة الميلاد، فهرس المحفّظ، الأبناء]
        $families = [
            ['محمد سالم الغرياني',    'mohamed.algharyani',  'm', 1980, 0,  ['أنس', 'معاذ']],
            ['أحمد علي الزوام',       'ahmed.alzuwam',       'm', 1984, 0,  ['الطيب']],
            ['فاطمة أحمد الرقيق',     'fatima.alraqiq',      'f', 1986, 1,  ['عبدالرحمن', 'سليم']],
            ['يوسف مفتاح المغربي',    'youssef.almaghrabi',  'm', 1979, 1,  ['حمزة']],
            ['إبراهيم منصور القذافي', 'ibrahim.alqadhafi',   'm', 1977, 2,  ['سيف الدين', 'راشد']],
            ['عمر خالد الأوجلي',      'omar.alawjali',       'm', 1983, 2,  ['نوفل']],
            ['محمود فرج الورفلي',     'mahmoud.alwarfali',   'm', 1975, 3,  ['عبدالعزيز', 'تميم']],
            ['آمنة علي بالنور',       'amna.balnour',        'f', 1988, 3,  ['أحمد']],
            ['عبدالرحمن محمود الترهوني', 'abdulrahman.alterhouni', 'm', 1981, 4, ['فيصل', 'مروان']],
            ['مريم سالم بن حليم',     'mariam.binhalim',     'f', 1985, 4,  ['خطاب']],
            ['سالم عبدالله الأسطى',   'salem.alosta',        'm', 1978, 5,  ['أيوب', 'إدريس']],
            ['فرج مصطفى المسلاتي',    'faraj.almisallati',   'm', 1982, 5,  ['غيث']],
            ['علي محمد الصبراتي',     'ali.alsabrati',       'm', 1976, 6,  ['مهدي', 'أمين']],
            ['خالد رمضان الزنتاني',   'khaled.alzintani',    'm', 1987, 6,  ['وسام']],
            ['مصطفى سعيد الجازوي',    'mustafa.aljazwi',     'm', 1974, 7,  ['حارث', 'جواد']],
            ['نوري عبدالسلام العواكلي', 'nuri.alawakli',     'm', 1985, 7,  ['شادي']],
            ['رمضان فتحي الحاسي',     'ramadan.alhasi',      'm', 1979, 8,  ['بدر', 'ضياء']],
            ['حسن عمر التواتي',       'hassan.altawati',     'm', 1983, 8,  ['نصر']],
            ['عبدالسلام أحمد الفيتوري', 'abdulsalam.alfituri', 'm', 1972, 9, ['فهد', 'صابر']],
            ['طارق محمود المنفي',     'tareq.almanfi',       'm', 1986, 9,  ['لؤي']],
            ['عثمان علي البرغثي',     'othman.albarghathi',  'm', 1980, 10, ['نائل', 'سراج']],
            ['سعيد فرج العبيدي',      'saeed.alobeidi',      'm', 1984, 10, ['مازن']],
            ['جمال مصطفى الشلوي',     'jamal.alshalwi',      'm', 1977, 11, ['وليد', 'ماجد']],
            ['بشير سالم الدرسي',      'bashir.aldarsi',      'm', 1988, 11, ['رامي']],
        ];

        $prefixes = ['091', '092', '093', '094'];
        $students = [];
        $sNo = 0;

        foreach ($families as $f) {
            [$gName, $gLatin, $gGender, $gYear, $tIdx, $children] = $f;

            $teacher = $teachers[$tIdx];
            $gPhone  = $prefixes[$sNo % 4] . str_pad((string) (5100000 + $batch * 10 + $sNo), 7, '0', STR_PAD_LEFT);

            $parent = $this->createUser([
                'name'             => $gName,
                'phone'            => $gPhone,
                'role'             => 'parent',
                'password'         => $password,
                'nationality_type' => 'libyan',
                'nationality_name' => 'ليبيا',
                'id_number'        => $this->nationalId($gGender, $gYear, 8200001 + $batch * 100 + $sNo),
                'is_active'        => true,
            ], $gLatin, 'parent.mutqin.ly');

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

                $students[] = Student::create([
                    'name'             => $childFirst . ' ' . $surname,
                    'birth_date'       => Carbon::create($birthYear, mt_rand(1, 12), mt_rand(1, 28))->toDateString(),
                    'phone'            => $prefixes[$sNo % 4] . str_pad((string) (5400000 + $batch * 10 + $sNo), 7, '0', STR_PAD_LEFT),
                    'national_id'      => $this->nationalId('m', $birthYear, 8300001 + $batch * 100 + $sNo),
                    'nationality_type' => 'libyan',
                    'nationality_name' => 'ليبيا',
                    'age'              => $age,
                    'guardian_name'    => $gName,
                    'guardian_phone'   => $gPhone,
                    'center_id'        => $teacher->center_id,
                    'teacher_id'       => $teacher->id,
                    'former_teacher_name' => null,
                    'parent_id'        => $parent->id,
                    'enrollment_date'  => now()->subMonths(mt_rand(2, 20))->startOfMonth()->addDays(mt_rand(0, 20)),
                    'is_active'        => true,
                    'status_changed_by' => null,
                    'status_changed_at' => null,
                ]);
                $sNo++;
            }
        }

        $this->counts['parents']  = count($families);
        $this->counts['students'] = count($students);

        return $students;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5) سجلات الحلقة للطلاب الجدد: حضور + حفظ + مراجعة + تجويد + اختبارات
    // ─────────────────────────────────────────────────────────────────────────

    private function pageRange(string $surah): array
    {
        $from = self::SURAH_PAGE[$surah];
        $idx  = array_search($surah, self::REVERSE_ORDER, true);
        $to   = $idx === 0 ? 604 : (self::SURAH_PAGE[self::REVERSE_ORDER[$idx - 1]] ?? 604);

        return [$from, max($from, $to)];
    }

    private function seedRecords(array $students): void
    {
        $athman = [];
        foreach (Athman::orderBy('global_order')->get(['hizb', 'thumn_in_hizb', 'surah_name', 'start_text']) as $a) {
            $athman[$a->surah_name] ??= ['hizb' => $a->hizb, 'thumn' => $a->thumn_in_hizb];
        }
        $athmanTexts = Athman::where('hizb', '>=', 55)->pluck('start_text')->all();

        $thumnNames = [1 => 'الثمن الأول', 2 => 'الثمن الثاني', 3 => 'الثمن الثالث', 4 => 'الثمن الرابع',
                       5 => 'الثمن الخامس', 6 => 'الثمن السادس', 7 => 'الثمن السابع', 8 => 'الثمن الثامن'];

        $attendance = $memo = $revisions = $tajweed = $tests = [];
        $testQuestions = [];

        $absentNotes  = ['غياب بعذر — مرض.', 'غياب غير مبرر، أُبلغ ولي الأمر.', 'سفر مع الأسرة.'];
        $lateNotes    = ['تأخر عشر دقائق عن بداية الحلقة.', 'تأخر بسبب ازدحام الطريق.'];
        $presentNotes = ['حضور مبكر ومشاركة جيدة.', null, null, null];
        $memoNotes    = [
            'excellent' => ['حفظ متقن ومخارج حروف واضحة.', 'تسميع بلا خطأ، بارك الله فيه.'],
            'good'      => ['حفظ جيد مع تردد بسيط في الآيات الأخيرة.', 'أداء جيد يحتاج تثبيت المتشابهات.'],
            'average'   => ['تسميع مقبول مع أخطاء في المدود.', 'يُعاد الحفظ قبل الانتقال للسورة التالية.'],
            'weak'      => ['يحتاج متابعة أسرية أدق هذا الأسبوع.', 'ضعف في الحفظ — يُعاد غداً.'],
        ];
        $revNotes  = ['مراجعة ممتازة ولله الحمد.', 'مراجعة جيدة مع نسيان في موضع واحد.', 'يحتاج ورد مراجعة يومياً.'];
        $tajNotes  = ['يحتاج ضبط زمن المد المتصل.', 'مخارج الحروف ممتازة، يُراجع أحكام التنوين.', 'تحسّن واضح في الغنة.'];
        $mistakes  = ['نسيان آية في وسط الثمن.', 'خطأ في ترتيب آيتين.', 'تردد طويل احتاج معه إلى التلقين.'];

        $start = now()->subDays(35)->startOfDay();

        foreach ($students as $student) {
            // — الحضور (35 يوماً ما عدا الجمعة)
            for ($d = 0; $d <= 35; $d++) {
                $date = $start->copy()->addDays($d);
                if ($date->isFriday()) {
                    continue;
                }
                $r = mt_rand(1, 100);
                $status = $r > 90 ? 'absent' : ($r > 80 ? 'late' : 'present');

                $attendance[] = [
                    'student_id'  => $student->id,
                    'teacher_id'  => $student->teacher_id,
                    'center_id'   => $student->center_id,
                    'date'        => $date->toDateString(),
                    'time'        => $status === 'absent' ? null
                        : sprintf('%02d:%02d', $status === 'late' ? 18 : mt_rand(16, 17), mt_rand(0, 59)),
                    'status'      => $status,
                    'notes'       => $status === 'absent' ? $this->pick($absentNotes)
                        : ($status === 'late' ? $this->pick($lateNotes) : $this->pick($presentNotes)),
                    'imported_at' => $d % 2 === 0 ? $date->copy()->setTime(20, 30)->toDateTimeString() : null,
                    'corrected_by' => null,
                    'corrected_at' => null,
                    'created_at'  => $date->copy()->setTime(18, 0)->toDateTimeString(),
                    'updated_at'  => $date->copy()->setTime(18, 0)->toDateTimeString(),
                ];
            }

            // — الحفظ (تسلسل عكسي من الناس نزولاً)
            $depth = min(count(self::REVERSE_ORDER), max(4, (int) round($student->age * mt_rand(8, 18) / 10)));
            $date  = Carbon::parse($student->enrollment_date)->max(now()->subDays(120));
            $doneSurahs = [];

            for ($i = 0; $i < $depth && $date->lte(now()); $i++) {
                $surah = self::REVERSE_ORDER[$i];
                [$from, $to] = $this->pageRange($surah);
                $q = mt_rand(1, 100);
                $quality = $q > 92 ? 'weak' : ($q > 78 ? 'average' : ($q > 45 ? 'good' : 'excellent'));
                $entry = $athman[$surah] ?? null;
                $doneSurahs[] = $surah;

                $memo[] = [
                    'student_id' => $student->id,
                    'teacher_id' => $student->teacher_id,
                    'date'       => $date->toDateString(),
                    'surah_name' => $surah,
                    'juz'        => SurahReference::SURAHS[$surah],
                    'hizb'       => $entry['hizb'] ?? 60,
                    'page_from'  => $from,
                    'page_to'    => $to,
                    'eighth'     => $entry ? ($thumnNames[$entry['thumn']] . ' من الحزب ' . $entry['hizb'])
                        : 'الثمن الأول من الحزب 60',
                    'quality'    => $quality,
                    'notes'      => $this->pick($memoNotes[$quality]),
                    'created_at' => $date->copy()->setTime(18, 30)->toDateTimeString(),
                    'updated_at' => $date->copy()->setTime(18, 30)->toDateTimeString(),
                ];
                $date = $date->copy()->addDays(mt_rand(2, 5));
            }

            // — المراجعة على ما حُفظ فعلاً
            $rDate = now()->subDays(mt_rand(25, 34));
            for ($i = 0, $n = mt_rand(4, 9); $i < $n && $rDate->lte(now()) && $doneSurahs; $i++) {
                $surah = $this->pick($doneSurahs);
                [$from, $to] = $this->pageRange($surah);
                $q = mt_rand(1, 100);

                $revisions[] = [
                    'student_id' => $student->id,
                    'teacher_id' => $student->teacher_id,
                    'date'       => $rDate->toDateString(),
                    'surah_name' => $surah,
                    'page_from'  => $from,
                    'page_to'    => $to,
                    'quality'    => $q > 90 ? 'weak' : ($q > 74 ? 'average' : ($q > 40 ? 'good' : 'excellent')),
                    'notes'      => $this->pick($revNotes),
                    'created_at' => $rDate->copy()->setTime(19, 0)->toDateTimeString(),
                    'updated_at' => $rDate->copy()->setTime(19, 0)->toDateTimeString(),
                ];
                $rDate = $rDate->copy()->addDays(mt_rand(3, 6));
            }

            // — تقييمات التجويد
            for ($t = mt_rand(1, 3); $t >= 1; $t--) {
                $tDate = now()->subWeeks($t * 3)->startOfWeek(Carbon::SATURDAY)->addDays(2);
                if ($tDate->gt(now())) {
                    continue;
                }
                $mk = mt_rand(6, 10); $sf = mt_rand(6, 10); $md = mt_rand(5, 10); $wq = mt_rand(6, 10);

                $tajweed[] = [
                    'student_id'     => $student->id,
                    'teacher_id'     => $student->teacher_id,
                    'date'           => $tDate->toDateString(),
                    'makharij_score' => $mk, 'sifat_score' => $sf,
                    'madd_score'     => $md, 'waqf_score'  => $wq,
                    'total_score'    => $mk + $sf + $md + $wq,
                    'notes'          => $this->pick($tajNotes),
                    'created_at'     => $tDate->copy()->setTime(17, 45)->toDateTimeString(),
                    'updated_at'     => $tDate->copy()->setTime(17, 45)->toDateTimeString(),
                ];
            }

            // — الاختبارات الأسبوعية بمستوى الأثمان
            for ($w = mt_rand(3, 5); $w >= 1; $w--) {
                $examDate = now()->subWeeks($w)->startOfWeek(Carbon::SATURDAY)->addDays(4);
                if ($examDate->gt(now())) {
                    continue;
                }

                $qRows  = [];
                $failed = false;
                for ($i = 0, $n = mt_rand(2, 4); $i < $n; $i++) {
                    $ok = mt_rand(1, 100) > 18;
                    $failed = $failed || !$ok;
                    $qRows[] = [
                        'student_id'   => $student->id,
                        'eighth_start' => $this->pick($athmanTexts),
                        'result'       => $ok ? 'ناجح' : 'راسب',
                        'mistake'      => $ok ? null : $this->pick($mistakes),
                        'created_at'   => $examDate->copy()->setTime(17, 0)->toDateTimeString(),
                        'updated_at'   => $examDate->copy()->setTime(17, 0)->toDateTimeString(),
                    ];
                }

                $tests[] = [
                    'student_id' => $student->id,
                    'teacher_id' => $student->teacher_id,
                    'exam_date'  => $examDate->toDateString(),
                    'result'     => $failed ? 'راسب' : 'ناجح',
                    'notes'      => $failed
                        ? 'رسب في ثمن — يُعاد اختباره الأسبوع القادم.'
                        : 'اجتاز جميع الأثمان بتقدير جيد، بارك الله فيه.',
                    'created_at' => $examDate->copy()->setTime(17, 0)->toDateTimeString(),
                    'updated_at' => $examDate->copy()->setTime(17, 0)->toDateTimeString(),
                ];
                $testQuestions[] = $qRows;
            }
        }

        $this->bulk('attendances', $attendance);
        $this->bulk('memorizations', $memo);
        $this->bulk('revisions', $revisions);
        $this->bulk('tajweed_evaluations', $tajweed);

        $firstId = DB::table('weekly_tests')->max('id') ?? 0;
        $this->bulk('weekly_tests', $tests);
        $ids = DB::table('weekly_tests')->where('id', '>', $firstId)->orderBy('id')->pluck('id')->all();

        $flat = [];
        foreach ($testQuestions as $k => $group) {
            foreach ($group as $q) {
                $flat[] = array_merge($q, ['weekly_test_id' => $ids[$k]]);
            }
        }
        $this->bulk('weekly_test_questions', $flat);
    }

    private function report(): void
    {
        ksort($this->counts);
        $this->command?->newLine();
        $this->command?->info('═══ البيانات الإضافية (أُضيفت فوق الموجود) ═══');
        foreach ($this->counts as $k => $n) {
            $this->command?->line(sprintf('  %-24s %6d', $k, $n));
        }
        $this->command?->info('كلمة المرور للحسابات الجديدة: ' . self::DEMO_PASSWORD);
    }
}
