<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Center;
use Illuminate\Http\Request;

class CenterController extends Controller
{
    public function index(Request $request)
    {
        $query = Center::withCount(['students', 'teachers'])->latest();

        // ?active=1 — المراكز النشطة فقط: لقوائم الاختيار (إضافة طالب/محفّظ/مدير)
        // كي لا يُضاف أحد لمركز معطَّل. صفحة إدارة المراكز والتقارير تعرض الكل.
        if ($request->boolean('active')) {
            $query->where('is_active', true);
        }

        if ($request->has('all') && $request->all == 1) {
            $centers = $query->get();
        } else {
            $centers = $query->paginate(10);
        }

        return response()->json([
            'success' => true,
            'data' => $centers
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'nullable|string|max:255',
        ], [
            'name.required' => 'اسم المركز مطلوب',
        ]);

        $center = Center::create($request->only(['name', 'city', 'address', 'phone', 'is_active']));

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة المركز بنجاح',
            'data' => $center
        ], 201);
    }

    /**
     * تفاصيل المركز لنافذة العرض: بياناته + عدد الطلاب والمحفّظين + قائمة
     * المحفّظين بنوعهم وحالتهم. فعّال: استعلام للمركز (بعدّين مجمّعين عبر
     * withCount) + استعلام واحد للمحفّظين — بلا N+1 مهما كثُر المحفّظون.
     */
    public function show($id)
    {
        $center = Center::withCount(['students', 'teachers'])->findOrFail($id);

        $teachers = \App\Models\User::where('role', 'teacher')
            ->where('center_id', $center->id)
            ->orderByRaw("FIELD(type,'محفظ أساسي','محفظ معاون')") // الأساسي أولاً
            ->orderBy('name')
            ->get(['id', 'name', 'display_code', 'type', 'is_active', 'email']);

        return response()->json([
            'success' => true,
            'data' => [
                'id'             => $center->id,
                'display_code'   => $center->display_code,
                'name'           => $center->name,
                'city'           => $center->city,
                'address'        => $center->address,
                'phone'          => $center->phone,
                'is_active'      => $center->is_active,
                'students_count' => $center->students_count,
                'teachers_count' => $center->teachers_count,
                'teachers'       => $teachers,
            ],
        ]);
    }

    /**
     * إحصائيات صفحة تفاصيل المركز (أدمن) — استعلامات مجمّعة فقط، بلا N+1:
     *  - الطلاب: نشط/موقوف/بلا محفّظ في استعلام واحد (SUM شرطية).
     *  - المحفّظون: نشط/موقوف في استعلام واحد.
     *  - حضور الشهر الحالي (طلاب المركز النشطون): إجمالي/حاضر/متأخر/غائب في استعلام واحد.
     *  - حضور اليوم: حاضر/متأخر/غائب في استعلام واحد.
     *  - اختبارات الشهر الحالي: إجمالي/ناجح في استعلام واحد.
     *  - مدير المركز النشط (أو null → الواجهة تعرض «لا يوجد مدير للمركز»).
     * نسبة الحضور = حاضر ÷ الإجمالي (نفس تعريف ReportService::centerData).
     */
    public function stats($id)
    {
        $center = Center::findOrFail($id);
        $now = now();

        $students = \App\Models\Student::where('center_id', $center->id)
            ->selectRaw('COALESCE(SUM(is_active = 1), 0) AS active, COALESCE(SUM(is_active = 0), 0) AS inactive, COALESCE(SUM(is_active = 1 AND teacher_id IS NULL), 0) AS without_teacher')
            ->first();

        $teachers = \App\Models\User::where('role', 'teacher')->where('center_id', $center->id)
            ->selectRaw('COALESCE(SUM(is_active = 1), 0) AS active, COALESCE(SUM(is_active = 0), 0) AS inactive')
            ->first();

        $activeIds = \App\Models\Student::where('center_id', $center->id)->where('is_active', true)->pluck('id');

        $monthAtt = \App\Models\Attendance::whereIn('student_id', $activeIds)
            ->whereMonth('date', $now->month)->whereYear('date', $now->year)
            ->selectRaw("COUNT(*) AS total, COALESCE(SUM(status = 'present'), 0) AS present, COALESCE(SUM(status = 'late'), 0) AS late, COALESCE(SUM(status = 'absent'), 0) AS absent")
            ->first();

        $todayAtt = \App\Models\Attendance::whereIn('student_id', $activeIds)
            ->where('date', today()->toDateString())
            ->selectRaw("COALESCE(SUM(status = 'present'), 0) AS present, COALESCE(SUM(status = 'late'), 0) AS late, COALESCE(SUM(status = 'absent'), 0) AS absent")
            ->first();

        $monthTests = \App\Models\WeeklyTest::whereIn('student_id', $activeIds)
            ->whereMonth('exam_date', $now->month)->whereYear('exam_date', $now->year)
            ->selectRaw("COUNT(*) AS total, COALESCE(SUM(result = 'ناجح'), 0) AS passed")
            ->first();

        $manager = \App\Models\User::where('role', 'center_manager')
            ->where('center_id', $center->id)
            ->where('is_active', true)
            ->first(['id', 'name', 'display_code', 'email', 'phone', 'is_active', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'center' => [
                    'id'           => $center->id,
                    'display_code' => $center->display_code,
                    'name'         => $center->name,
                    'city'         => $center->city,
                    'address'      => $center->address,
                    'phone'        => $center->phone,
                    'is_active'    => $center->is_active,
                    'created_at'   => $center->created_at,
                ],
                'stats' => [
                    'students_active'          => (int) $students->active,
                    'students_inactive'        => (int) $students->inactive,
                    'students_without_teacher' => (int) $students->without_teacher,
                    'teachers_active'          => (int) $teachers->active,
                    'teachers_inactive'        => (int) $teachers->inactive,
                    'attendance_percent'       => \App\Support\Percentage::of((int) $monthAtt->present, (int) $monthAtt->total),
                    'attendance_month'         => [
                        'total'   => (int) $monthAtt->total,
                        'present' => (int) $monthAtt->present,
                        'late'    => (int) $monthAtt->late,
                        'absent'  => (int) $monthAtt->absent,
                    ],
                    'attendance_today'         => [
                        'present' => (int) $todayAtt->present,
                        'late'    => (int) $todayAtt->late,
                        'absent'  => (int) $todayAtt->absent,
                    ],
                    'tests_month'              => [
                        'total'        => (int) $monthTests->total,
                        'passed'       => (int) $monthTests->passed,
                        'pass_percent' => \App\Support\Percentage::of((int) $monthTests->passed, (int) $monthTests->total),
                    ],
                    'month' => $now->month,
                    'year'  => $now->year,
                ],
                'manager' => $manager,
            ],
        ]);
    }

    /**
     * محفّظو المركز لصفحة التفاصيل (أدمن) — مرقّم 5/صفحة، النشطون افتراضياً
     * (?status=inactive|all)، الأساسي أولاً ثم الاسم، مع عدد طلابه النشطين (withCount).
     */
    public function teachers(Request $request, $id)
    {
        $center = Center::findOrFail($id);

        $query = \App\Models\User::where('role', 'teacher')
            ->where('center_id', $center->id)
            ->withCount(['students as active_students_count' => fn ($q) => $q->where('is_active', true)])
            ->orderByRaw("FIELD(type,'محفظ أساسي','محفظ معاون')")
            ->orderBy('name');

        $status = $request->input('status', 'active');
        if ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status !== 'all') {
            $query->where('is_active', true);
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate(5, ['id', 'name', 'display_code', 'type', 'is_active', 'email', 'phone'])->withQueryString(),
        ]);
    }

    /**
     * طلاب المركز لصفحة التفاصيل (أدمن) — مرقّم 5/صفحة، النشطون افتراضياً
     * (?status=inactive|all)، بمحفّظ كل طالب محمَّلاً مسبقاً (لا N+1).
     */
    public function students(Request $request, $id)
    {
        $center = Center::findOrFail($id);

        $query = \App\Models\Student::where('center_id', $center->id)
            ->with('teacher:id,name,display_code')
            ->orderBy('name');

        $status = $request->input('status', 'active');
        if ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status !== 'all') {
            $query->where('is_active', true);
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate(5, ['id', 'name', 'display_code', 'age', 'national_id', 'guardian_name', 'teacher_id', 'former_teacher_name', 'is_active'])->withQueryString(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $center = Center::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'nullable|string|max:255',
        ]);

        $center->update($request->only(['name', 'city', 'address', 'phone', 'is_active']));

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات المركز بنجاح',
            'data' => $center
        ]);
    }

    /**
     * تفعيل/تعطيل المركز — بديل الحذف نهائياً (الحذف يفقد كل تاريخ المركز).
     * قرار معتمد: المركز المعطَّل «مغلق» فعلياً — يُمنع منتسبوه (محفّظوه ومدير
     * مركزه، وهم وحدهم من يحملون center_id) من الدخول، وتُبطَل جلساتهم فوراً
     * وإلا واصلوا العمل حتى انتهاء التوكن (7 أيام). أولياء الأمور والطلاب لا
     * يتأثرون (الطلاب ليسوا مستخدمين، وأولياء الأمور بلا مركز).
     */
    public function toggleStatus(Request $request, $id)
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ], ['is_active.required' => 'الحالة مطلوبة']);

        $center = Center::findOrFail($id);
        $active = $request->boolean('is_active');

        $affected = \Illuminate\Support\Facades\DB::transaction(function () use ($center, $active) {
            $center->update(['is_active' => $active]);

            if ($active) {
                return 0;
            }

            // إبطال جلسات منتسبي المركز فوراً (نفس آلية S1)
            $members = \App\Models\User::where('center_id', $center->id)->get();
            foreach ($members as $m) {
                $m->tokens()->delete();
            }
            return $members->count();
        });

        return response()->json([
            'success' => true,
            'message' => $active
                ? "تم تفعيل مركز «{$center->name}»"
                : "تم تعطيل مركز «{$center->name}» — أُنهيت جلسات {$affected} من منتسبيه ولن يستطيعوا الدخول",
            'data' => ['id' => $center->id, 'is_active' => $center->is_active, 'affected_members' => $affected],
        ]);
    }
}
