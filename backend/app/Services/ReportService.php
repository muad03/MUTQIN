<?php

namespace App\Services;

use App\Models\User;
use App\Models\Center;
use App\Models\Student;
use App\Models\Attendance;
use App\Models\Memorization;
use App\Models\WeeklyTest;
use App\Support\Percentage;

/**
 * خدمة التقارير — كل منطق التجميع في مكان واحد، يُعاد استخدامه من:
 *  - ReportController (استجابات JSON)
 *  - ReportPdfController (ملفات PDF)
 * بلا تكرار للمنطق.
 */
class ReportService
{
    // عتبات الطلاب المتعثّرين (ثوابت بسيطة قابلة للتعديل)
    const ATTENDANCE_THRESHOLD = 70; // نسبة الحضور التي تحتها يُعتبر متعثّراً
    const FAIL_THRESHOLD       = 2;  // عدد مرات الرسوب التي عندها فأكثر يُعتبر متعثّراً

    // نسبة مئوية آمنة (تفوّض إلى المصدر الموحّد)
    protected function pct(int $part, int $total): int
    {
        return Percentage::of($part, $total);
    }

    /**
     * تقرير طالب واحد لشهر/سنة (نفس تجميع ReportController@student القديم).
     */
    public function studentData(Student $student, $month, $year): array
    {
        $student->loadMissing(['center', 'teacher']);

        $attendances = Attendance::where('student_id', $student->id)
            ->whereMonth('date', $month)->whereYear('date', $year)
            ->orderBy('date')->get();

        $present = $attendances->where('status', 'present')->count();
        $absent  = $attendances->where('status', 'absent')->count();
        $late    = $attendances->where('status', 'late')->count();
        $total   = $present + $absent + $late;

        $memorizations = Memorization::where('student_id', $student->id)
            ->whereMonth('date', $month)->whereYear('date', $year)
            ->orderBy('date')->get();

        $tests = WeeklyTest::where('student_id', $student->id)
            ->whereMonth('exam_date', $month)->whereYear('exam_date', $year)
            ->with('questions')->orderBy('exam_date')->get();

        $passed = $tests->where('result', 'ناجح')->count();
        $failed = $tests->where('result', 'راسب')->count();

        return [
            'student'           => $student,
            'attendances'       => $attendances,
            'memorizations'     => $memorizations,
            'tests'             => $tests,
            'present'           => $present,
            'absent'            => $absent,
            'late'              => $late,
            'total'             => $total,
            'attendancePercent' => $this->pct($present, $total),
            'passed'            => $passed,
            'failed'            => $failed,
            'passRate'          => $this->pct($passed, $tests->count()),
            'month'             => (int) $month,
            'year'              => (int) $year,
        ];
    }

    /**
     * صف ملخّص لطالب (نسبة حضور + نسبة نجاح) — يُستخدم في تقارير المجموعات.
     */
    public function studentSummaryRow(Student $s, $month, $year): array
    {
        $att = Attendance::where('student_id', $s->id)
            ->whereMonth('date', $month)->whereYear('date', $year)->get();
        $p = $att->where('status', 'present')->count();
        $a = $att->where('status', 'absent')->count();
        $l = $att->where('status', 'late')->count();
        $tot = $p + $a + $l;

        $tests = WeeklyTest::where('student_id', $s->id)
            ->whereMonth('exam_date', $month)->whereYear('exam_date', $year)->get();
        $tc = $tests->count();
        $passed = $tests->where('result', 'ناجح')->count();
        $failed = $tests->where('result', 'راسب')->count();

        return [
            'student'           => $s,
            'present'           => $p,
            'absent'            => $a,
            'late'              => $l,
            'attendancePercent' => $this->pct($p, $tot),
            'tests'             => $tc,
            'passed'            => $passed,
            'failed'            => $failed,
            'passRate'          => $this->pct($passed, $tc),
        ];
    }

    /**
     * تقرير مجموعة طلاب معلّم — صف لكل طالب + إجماليات.
     */
    public function teacherGroupData(User $teacher, $month, $year): array
    {
        $students = Student::where('teacher_id', $teacher->id)
            ->where('is_active', true)->with('center')->orderBy('name')->get();

        $rows = $students->map(fn ($s) => $this->studentSummaryRow($s, $month, $year));
        $avgAtt = $rows->count() ? (int) round($rows->avg('attendancePercent')) : 0;
        $avgPass = $rows->count() ? (int) round($rows->avg('passRate')) : 0;

        return [
            'teacher'    => $teacher->loadMissing('center'),
            'rows'       => $rows,
            'studentsCount' => $rows->count(),
            'avgAttendance' => $avgAtt,
            'avgPassRate'   => $avgPass,
            'month'      => (int) $month,
            'year'       => (int) $year,
        ];
    }

    /**
     * بيانات مركز شاملة.
     */
    public function centerData(Center $center, $month, $year): array
    {
        $teachers = User::where('role', 'teacher')->where('center_id', $center->id)->count();
        $students = Student::where('center_id', $center->id)->where('is_active', true)->get();
        $ids = $students->pluck('id');

        $att = Attendance::whereIn('student_id', $ids)
            ->whereMonth('date', $month)->whereYear('date', $year)->get();
        $present = $att->where('status', 'present')->count();

        $tests = WeeklyTest::whereIn('student_id', $ids)
            ->whereMonth('exam_date', $month)->whereYear('exam_date', $year)->get();
        $passed = $tests->where('result', 'ناجح')->count();
        $failed = $tests->where('result', 'راسب')->count();

        return [
            'center'        => $center,
            'teachersCount' => $teachers,
            'studentsCount' => $students->count(),
            'avgAttendance' => $this->pct($present, $att->count()),
            'testsCount'    => $tests->count(),
            'passed'        => $passed,
            'failed'        => $failed,
            'passRate'      => $this->pct($passed, $tests->count()),
            'month'         => (int) $month,
            'year'          => (int) $year,
        ];
    }

    /**
     * كل المراكز (صف لكل مركز).
     */
    public function allCentersData($month, $year): array
    {
        // النشطة فقط — توحيداً مع لوحة الأدمن (المركز الموقوف يبقى في التاريخ لا في التقارير)
        $rows = Center::where('is_active', true)->orderBy('name')->get()->map(fn ($c) => $this->centerData($c, $month, $year));
        return ['rows' => $rows, 'month' => (int) $month, 'year' => (int) $year];
    }

    /**
     * تقرير أداء المعلمين (مرتّب حسب الأداء تنازلياً).
     * $centerId اختياري: null → كل المحفّظين (الأدمن)، مُمرَّر → محفّظو المركز فقط.
     */
    public function teachersPerformance($month, $year, ?int $centerId = null): array
    {
        $teachers = User::where('role', 'teacher')
            ->when($centerId, fn ($q) => $q->where('center_id', $centerId))
            ->with('center')->get();

        $rows = $teachers->map(function ($t) use ($month, $year) {
            $students = Student::where('teacher_id', $t->id)->where('is_active', true)->get();
            $ids = $students->pluck('id');

            $att = Attendance::whereIn('student_id', $ids)
                ->whereMonth('date', $month)->whereYear('date', $year)->get();
            $tests = WeeklyTest::whereIn('student_id', $ids)
                ->whereMonth('exam_date', $month)->whereYear('exam_date', $year)->get();
            $passed = $tests->where('result', 'ناجح')->count();

            return [
                'teacher'           => $t,
                'studentsCount'     => $students->count(),
                'attendancePercent' => $this->pct($att->where('status', 'present')->count(), $att->count()),
                'testsCount'        => $tests->count(),
                'passRate'          => $this->pct($passed, $tests->count()),
            ];
        })
        ->sortByDesc(fn ($r) => $r['passRate'] + $r['attendancePercent'] / 2) // الأداء = نجاح + نصف الحضور
        ->values();

        return ['rows' => $rows, 'month' => (int) $month, 'year' => (int) $year];
    }

    /**
     * الطلاب المتعثّرون (حضور منخفض أو رسوب متكرر).
     * $centerId اختياري: null → كل النظام (سلوك الأدمن كما هو)، مُمرَّر →
     * مركز واحد فقط. تصفية الطلاب الأولى فقط تتغيّر — يبقى العدد 3 استعلامات.
     */
    public function atRiskStudents($month, $year, ?int $centerId = null): array
    {
        $students = Student::where('is_active', true)
            ->when($centerId, fn ($q) => $q->where('center_id', $centerId))
            ->with(['center', 'teacher'])->get();

        // استعلامان مجمّعان للجميع (بدل استعلامين لكل طالب — N+1)
        $attStats = Attendance::whereIn('student_id', $students->pluck('id'))
            ->whereMonth('date', $month)->whereYear('date', $year)
            ->selectRaw('student_id, COUNT(*) AS total, SUM(status = "present") AS present')
            ->groupBy('student_id')
            ->get()->keyBy('student_id');
        $failCounts = WeeklyTest::whereIn('student_id', $students->pluck('id'))
            ->whereMonth('exam_date', $month)->whereYear('exam_date', $year)
            ->where('result', 'راسب')
            ->selectRaw('student_id, COUNT(*) AS c')
            ->groupBy('student_id')
            ->get()->keyBy('student_id');

        $rows = collect();

        foreach ($students as $s) {
            $stat  = $attStats->get($s->id);
            $total = (int) ($stat->total ?? 0);
            $pct = $this->pct((int) ($stat->present ?? 0), $total);

            $failed = (int) ($failCounts->get($s->id)->c ?? 0);

            $lowAtt = $total > 0 && $pct < self::ATTENDANCE_THRESHOLD;
            $manyFails = $failed >= self::FAIL_THRESHOLD;

            if ($lowAtt || $manyFails) {
                $reasons = [];
                if ($lowAtt) $reasons[] = 'حضور منخفض';
                if ($manyFails) $reasons[] = 'رسوب متكرر';
                $rows->push([
                    'student'           => $s,
                    'attendancePercent' => $pct,
                    'attendanceDays'    => $total,
                    'fails'             => $failed,
                    'reason'            => implode(' + ', $reasons),
                ]);
            }
        }

        $rows = $rows->sortBy('attendancePercent')->values();

        return [
            'rows'          => $rows,
            'month'         => (int) $month,
            'year'          => (int) $year,
            'attThreshold'  => self::ATTENDANCE_THRESHOLD,
            'failThreshold' => self::FAIL_THRESHOLD,
        ];
    }

    /**
     * ملخّص تقدّم الحفظ — مؤشّر «أكمل القرآن» (كل الأجزاء الـ30) ومتوسّط
     * الأجزاء المكتملة. مبنيّ على SurahReference بعد جلب سور الطلاب في
     * استعلام واحد (بلا N+1). $centerId اختياري (null = كل النظام).
     * ملاحظة: «الختمات» ميزة غير مبنية بعد — هذا مؤشّر إتمامٍ حاليّ محسوب،
     * لا عدّاد ختمات تاريخي ولا منسوب لمحفّظ.
     */
    public function progressSummary($month, $year, ?int $centerId = null): array
    {
        $students = Student::where('is_active', true)
            ->when($centerId, fn ($q) => $q->where('center_id', $centerId))
            ->get(['id']);

        $bySurah = Memorization::whereIn('student_id', $students->pluck('id'))
            ->whereNotNull('surah_name')
            ->get(['student_id', 'surah_name'])
            ->groupBy('student_id');

        $completedQuran = 0;
        $sumJuz = 0;
        foreach ($students as $s) {
            $names = ($bySurah[$s->id] ?? collect())->pluck('surah_name')->all();
            $p = \App\Support\SurahReference::progress($names);
            $sumJuz += $p['completed_count'];
            if ($p['completed_count'] === 30) $completedQuran++;
        }

        return [
            'studentsCount'   => $students->count(),
            'completedQuran'  => $completedQuran,                                        // أتمّوا كل القرآن حالياً
            'avgCompletedJuz' => $students->count() ? round($sumJuz / $students->count(), 1) : 0,
        ];
    }

    /**
     * تقارير إدارة المركز (المجموعة 2) — كلها لمركز واحد، مبنية على
     * استعلامات مجمّعة (بلا N+1 مهما كثُر المحفّظون/الطلاب):
     *  - أداء كل محفّظ: عدد طلابه، نسبة حضورهم، متوسّط تقدّم حفظهم، من أكمل القرآن
     *  - توزيع الطلاب (عدد لكل محفّظ) — لكشف الاختلال
     *  - طلاب بلا محفّظ: قائمة + عدد
     *  - ملخّص المركز العام
     */
    public function centerManagement(int $centerId, $month, $year): array
    {
        $teachers = User::where('role', 'teacher')->where('center_id', $centerId)
            ->orderBy('name')->get(['id', 'name', 'type']);
        $students = Student::where('center_id', $centerId)->where('is_active', true)
            ->get(['id', 'name', 'display_code', 'teacher_id']);
        $ids = $students->pluck('id');

        // استعلام مجمّع واحد للحضور + استعلام واحد لسور الحفظ
        $attStats = Attendance::whereIn('student_id', $ids)
            ->whereMonth('date', $month)->whereYear('date', $year)
            ->selectRaw('student_id, COUNT(*) AS total, SUM(status = "present") AS present')
            ->groupBy('student_id')->get()->keyBy('student_id');
        $bySurah = Memorization::whereIn('student_id', $ids)->whereNotNull('surah_name')
            ->get(['student_id', 'surah_name'])->groupBy('student_id');

        // تقدّم كل طالب (مرة واحدة) + تجميع في الذاكرة
        $progressOf = [];
        foreach ($students as $s) {
            $names = ($bySurah[$s->id] ?? collect())->pluck('surah_name')->all();
            $progressOf[$s->id] = \App\Support\SurahReference::progress($names)['completed_count'];
        }

        $byTeacher = $students->groupBy('teacher_id');

        // صف أداء لكل محفّظ
        $teacherRows = $teachers->map(function ($t) use ($byTeacher, $attStats, $progressOf) {
            $mine = $byTeacher->get($t->id, collect());
            $present = 0; $totalAtt = 0; $sumJuz = 0; $completed = 0;
            foreach ($mine as $s) {
                $st = $attStats->get($s->id);
                $present  += (int) ($st->present ?? 0);
                $totalAtt += (int) ($st->total ?? 0);
                $sumJuz   += $progressOf[$s->id];
                if ($progressOf[$s->id] === 30) $completed++;
            }
            return [
                'teacher'          => ['id' => $t->id, 'name' => $t->name, 'type' => $t->type],
                'studentsCount'    => $mine->count(),
                'attendancePercent'=> $this->pct($present, $totalAtt),
                'avgCompletedJuz'  => $mine->count() ? round($sumJuz / $mine->count(), 1) : 0,
                'completedQuran'   => $completed,
            ];
        })->values();

        // طلاب بلا محفّظ
        $noTeacher = $byTeacher->get(null, collect())->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->name, 'display_code' => $s->display_code,
        ])->values();

        // ملخّص المركز
        $totalPresent = $attStats->sum('present');
        $totalAtt = $attStats->sum('total');
        $completedQuran = collect($progressOf)->filter(fn ($c) => $c === 30)->count();

        return [
            'teacherRows'   => $teacherRows,
            'noTeacher'     => ['count' => $noTeacher->count(), 'rows' => $noTeacher],
            'summary'       => [
                'teachersCount'  => $teachers->count(),
                'studentsCount'  => $students->count(),
                'avgAttendance'  => $this->pct((int) $totalPresent, (int) $totalAtt),
                'completedQuran' => $completedQuran,
            ],
            'month' => (int) $month, 'year' => (int) $year,
        ];
    }

    /**
     * الإحصاء العام للنظام.
     */
    public function overview($month, $year): array
    {
        $tests = WeeklyTest::whereMonth('exam_date', $month)->whereYear('exam_date', $year)->get();
        $passed = $tests->where('result', 'ناجح')->count();

        $memorizations = Memorization::whereMonth('date', $month)->whereYear('date', $year)->count();

        return [
            'centers'        => Center::where('is_active', true)->count(), // كاللوحة: النشطة فقط
            'teachers'       => User::where('role', 'teacher')->count(),
            'students'       => Student::where('is_active', true)->count(),
            'parents'        => User::where('role', 'parent')->count(),
            'testsCount'     => $tests->count(),
            'passed'         => $passed,
            'failed'         => $tests->where('result', 'راسب')->count(),
            'passRate'       => $this->pct($passed, $tests->count()),
            'memorizations'  => $memorizations,
            'month'          => (int) $month,
            'year'           => (int) $year,
        ];
    }

    // ============================================================
    // تقارير مدير المركز الثلاثة (كل السجلات — لا مدى زمني): مركز · محفّظ · طالب.
    // استعلامات مجمّعة فقط (بلا N+1)، والنشطون فقط. التعريفات نفسها المعتمدة أعلاه:
    // نسبة الحضور = حاضر ÷ الإجمالي، نسبة الغياب = غائب ÷ الإجمالي، الختمة = 30 جزءاً
    // مكتملاً (progress) — التعريف القائم، لا عدّاد ختمات تاريخياً.
    // «متقدّم» و«متفوّق» بلا تعريف في النظام فلا تُحسب.
    // ============================================================

    /** تجميع مشترك لمجموعة طلاب: حضور + اختبارات + حفظ (تقدّم كل طالب وجودة السجلات). */
    protected function aggregateAllTime($studentIds): array
    {
        $ids = collect($studentIds)->values();

        $att = Attendance::whereIn('student_id', $ids)
            ->selectRaw("COUNT(*) AS total, COALESCE(SUM(status = 'present'), 0) AS present, COALESCE(SUM(status = 'late'), 0) AS late, COALESCE(SUM(status = 'absent'), 0) AS absent")
            ->first();
        $tests = WeeklyTest::whereIn('student_id', $ids)
            ->selectRaw("COUNT(*) AS total, COALESCE(SUM(result = 'ناجح'), 0) AS passed, COALESCE(SUM(result = 'راسب'), 0) AS failed")
            ->first();
        $quality = Memorization::whereIn('student_id', $ids)
            ->selectRaw('quality, COUNT(*) AS c')->groupBy('quality')->pluck('c', 'quality');
        $bySurah = Memorization::whereIn('student_id', $ids)->whereNotNull('surah_name')
            ->get(['student_id', 'surah_name'])->groupBy('student_id');

        $progressOf = [];
        $sumJuz = 0; $khatmat = 0;
        foreach ($ids as $id) {
            $p = \App\Support\SurahReference::progress(($bySurah[$id] ?? collect())->pluck('surah_name')->all());
            $progressOf[$id] = $p;
            $sumJuz += $p['completed_count'];
            if ($p['completed_count'] === 30) $khatmat++;
        }

        $attTotal = (int) $att->total;
        return [
            'attendance' => [
                'total'          => $attTotal,
                'present'        => (int) $att->present,
                'late'           => (int) $att->late,
                'absent'         => (int) $att->absent,
                'percent'        => $this->pct((int) $att->present, $attTotal),
                'absent_percent' => $this->pct((int) $att->absent, $attTotal),
            ],
            'tests' => [
                'total'        => (int) $tests->total,
                'passed'       => (int) $tests->passed,
                'failed'       => (int) $tests->failed,
                'pass_percent' => $this->pct((int) $tests->passed, (int) $tests->total),
            ],
            'memorization' => [
                'records'           => (int) $quality->sum(),
                'quality'           => [
                    'excellent' => (int) ($quality['excellent'] ?? 0),
                    'good'      => (int) ($quality['good'] ?? 0),
                    'average'   => (int) ($quality['average'] ?? 0),
                    'weak'      => (int) ($quality['weak'] ?? 0),
                ],
                'avg_completed_juz' => $ids->count() ? round($sumJuz / $ids->count(), 1) : 0,
                'khatmat'           => $khatmat,
            ],
            'progressOf' => $progressOf,
        ];
    }

    /** تقرير المركز (كل السجلات): أعداد + حضور/غياب + اختبارات + حفظ وختمات. */
    public function centerAllTime(int $centerId): array
    {
        $students = Student::where('center_id', $centerId)->where('is_active', true)->pluck('id');
        $agg = $this->aggregateAllTime($students);
        unset($agg['progressOf']);

        return array_merge([
            'students_count' => $students->count(),
            'teachers_count' => User::where('role', 'teacher')->where('center_id', $centerId)->where('is_active', true)->count(),
        ], $agg);
    }

    /** تقرير محفّظ (كل السجلات): بياناته + المجاميع + صف لكل طالب نشط عنده. */
    public function teacherAllTime(User $teacher): array
    {
        $students = Student::where('teacher_id', $teacher->id)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'display_code', 'age']);
        $ids = $students->pluck('id');
        $agg = $this->aggregateAllTime($ids);
        $progressOf = $agg['progressOf'];
        unset($agg['progressOf']);

        $attBy = Attendance::whereIn('student_id', $ids)
            ->selectRaw("student_id, COUNT(*) AS total, COALESCE(SUM(status = 'present'), 0) AS present, COALESCE(SUM(status = 'absent'), 0) AS absent")
            ->groupBy('student_id')->get()->keyBy('student_id');
        $testsBy = WeeklyTest::whereIn('student_id', $ids)
            ->selectRaw("student_id, COUNT(*) AS total, COALESCE(SUM(result = 'ناجح'), 0) AS passed")
            ->groupBy('student_id')->get()->keyBy('student_id');

        $rows = $students->map(function ($s) use ($attBy, $testsBy, $progressOf) {
            $a = $attBy->get($s->id); $t = $testsBy->get($s->id); $p = $progressOf[$s->id];
            return [
                'id' => $s->id, 'name' => $s->name, 'display_code' => $s->display_code, 'age' => $s->age,
                'attendance_percent' => $this->pct((int) ($a->present ?? 0), (int) ($a->total ?? 0)),
                'absent'             => (int) ($a->absent ?? 0),
                'attendance_total'   => (int) ($a->total ?? 0),
                'tests_total'        => (int) ($t->total ?? 0),
                'tests_passed'       => (int) ($t->passed ?? 0),
                'pass_percent'       => $this->pct((int) ($t->passed ?? 0), (int) ($t->total ?? 0)),
                'completed_juz'      => $p['completed_count'],
                'reached_juz'        => $p['reached_juz'],
                'completed_quran'    => $p['completed_count'] === 30,
            ];
        })->values();

        return array_merge([
            'teacher' => [
                'id' => $teacher->id, 'name' => $teacher->name, 'display_code' => $teacher->display_code,
                'type' => $teacher->type, 'is_active' => $teacher->is_active, 'email' => $teacher->email, 'phone' => $teacher->phone,
            ],
            'students_count' => $students->count(),
        ], $agg, ['students' => $rows]);
    }

    /** تقرير طالب شامل (كل السجلات): بياناته والمحفّظ وولي الأمر + حضور + اختبارات + مقدار القرآن المُتمّ والجزء الحالي والختمة. */
    public function studentAllTime(Student $student): array
    {
        $student->loadMissing(['center:id,name', 'teacher:id,name,display_code,type', 'parent:id,name,phone,email']);
        $agg = $this->aggregateAllTime([$student->id]);
        $p = $agg['progressOf'][$student->id];
        unset($agg['progressOf']);

        $tests = WeeklyTest::where('student_id', $student->id)->orderByDesc('exam_date')->orderByDesc('id')
            ->limit(20)->get(['id', 'exam_date', 'result', 'notes']);
        $recent = Memorization::where('student_id', $student->id)->orderByDesc('date')->orderByDesc('id')
            ->limit(10)->get(['id', 'date', 'surah_name', 'juz', 'quality', 'notes']);

        return array_merge([
            'student' => [
                'id' => $student->id, 'name' => $student->name, 'display_code' => $student->display_code,
                'age' => $student->age, 'national_id' => $student->national_id, 'phone' => $student->phone,
                'nationality_type' => $student->nationality_type, 'nationality_name' => $student->nationality_name,
                'enrollment_date' => $student->enrollment_date, 'is_active' => $student->is_active,
                'center_name' => $student->center->name ?? null,
                'former_teacher_name' => $student->former_teacher_name,
            ],
            'teacher' => $student->teacher ? [
                'id' => $student->teacher->id, 'name' => $student->teacher->name,
                'display_code' => $student->teacher->display_code, 'type' => $student->teacher->type,
            ] : null,
            'parent' => $student->parent ? [
                'id' => $student->parent->id, 'name' => $student->parent->name, 'phone' => $student->parent->phone,
            ] : ['id' => null, 'name' => $student->guardian_name, 'phone' => $student->guardian_phone],
            'progress' => [
                'completed_juz'     => $p['completed_count'],
                'completed_down_to' => $p['completed_down_to'],
                'completion_percent'=> $this->pct($p['completed_count'], 30),
                'reached_juz'       => $p['reached_juz'],
                'reached_juz_done'  => $p['reached_juz_done'],
                'last_surah'        => $p['last_surah'],
                'completed_quran'   => $p['completed_count'] === 30,
                'khatmat'           => $p['completed_count'] === 30 ? 1 : 0,
            ],
        ], $agg, ['recent_tests' => $tests, 'recent_memorizations' => $recent]);
    }
}
