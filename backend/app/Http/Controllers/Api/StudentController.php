<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Models\Center;
use App\Models\Attendance;
use App\Models\Memorization;
use App\Support\ArabicText;
use App\Support\ParentResolver;
use App\Support\Percentage;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentController extends Controller
{
    /**
     * حضور الرقم الوطني في التحقق.
     * ⬅️ للتبديل لاحقاً إلى "إلزامي" غيّر هذا السطر فقط من 'nullable' إلى 'required'.
     */
    const NATIONAL_ID_PRESENCE = 'nullable';

    /**
     * قواعد رقم هوية الطالب حسب الجنسية (فريد بين الطلاب دائماً).
     *  - ليبي:   12 رقماً يبدأ بـ1 (ذكر) أو 2 (أنثى) — الصيغة الليبية الحالية.
     *  - أجنبي:  نص حرّ (جواز/إقامة) بحدّ 32 حرفاً، بلا صيغة ليبية.
     * @param int|null $ignoreId معرّف الطالب لتجاهله في فحص التفرّد (عند التعديل)
     */
    protected function studentIdentityRules(string $natType, $ignoreId = null): array
    {
        $unique = 'unique:students,national_id' . ($ignoreId ? ',' . $ignoreId : '');
        $format = $natType === 'foreigner'
            ? ['string', 'max:32']
            : ['digits:12', 'regex:/^[12]\d{11}$/'];
        return array_merge([self::NATIONAL_ID_PRESENCE], $format, [$unique]);
    }

    /**
     * قواعد رقم هوية ولي الأمر حسب الجنسية — بلا قيد unique في التحقّق،
     * لأن التفرّد يُفرض منطقياً عند الإنشاء فقط (وإلا تعذّر ربط الابن الثاني بنفس الرقم).
     */
    protected function parentIdentityRules(string $natType): array
    {
        return $natType === 'foreigner'
            ? ['nullable', 'string', 'max:32']
            : ['nullable', 'digits:12', 'regex:/^[12]\d{11}$/'];
    }

    protected function nationalIdMessages(): array
    {
        return [
            'national_id.digits'   => 'الرقم الوطني يجب أن يكون 12 رقماً',
            'national_id.regex'    => 'الرقم الوطني الليبي يبدأ بـ 1 (ذكر) أو 2 (أنثى) ويتكوّن من 12 رقماً',
            'national_id.unique'   => 'هذا الرقم الوطني مسجّل لطالب آخر',
            'national_id.required' => 'الرقم الوطني مطلوب',
            'age.between'          => 'العمر يجب أن يكون بين 1 و120 سنة',
            'nationality_name.required_if'          => 'اسم الجنسية مطلوب للطالب الأجنبي',
            'guardian_nationality_name.required_if' => 'اسم الجنسية مطلوب لولي الأمر الأجنبي',
            'guardian_id_number.digits' => 'الرقم الوطني لولي الأمر يجب أن يكون 12 رقماً',
            'guardian_id_number.regex'  => 'الرقم الوطني الليبي لولي الأمر يبدأ بـ 1/2 ويتكوّن من 12 رقماً',
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Student::with(['center', 'teacher'])->latest();

        if ($user->isCenterManager()) {
            $query->where('center_id', $user->center_id); // مدير المركز: طلاب مركزه فقط
        } elseif (!$user->isAdmin()) {
            $query->where('teacher_id', $user->id); // المحفّظ: طلابه فقط
        }

        // فلاتر اختيارية (تُدمج مع AND؛ الفلترة في الـ Backend)
        if ($request->filled('center_id')) {
            $query->where('center_id', (int) $request->center_id);
        }
        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', (int) $request->teacher_id);
        }
        if (in_array($request->input('nationality'), ['libyan', 'foreigner'], true)) {
            $query->where('nationality_type', $request->input('nationality'));
        }
        if ($request->boolean('missing_national_id')) {
            $query->whereNull('national_id'); // جاهزية الأوقاف: بلا رقم وطني
        }

        // فلتر الحالة — الافتراضي «النشطون فقط» اتساقاً مع التقارير والحضور
        // والبصمة (كلها تستثني الموقوف). status=inactive/all لمراجعته صراحةً.
        $status = $request->input('status', 'active');
        if ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status !== 'all') {
            $query->where('is_active', true);
        }

        // بحث حي اختياري q في «كل الأعمدة»: اسم الطالب/ولي الأمر، الرقم الوطني، الهاتف،
        // اسم المركز، اسم المعلم (والسابق)، والجنسية («ليبي»/«أجنبي»/اسمها) — كلها مطبَّعة.
        // يتجمّع مع الفلاتر أعلاه بـ AND ويعمل مع الترقيم.
        if (($q = trim((string) $request->get('q', ''))) !== '') {
            $norm = ArabicText::normalize($q);
            // شرطا الرقم/الهاتف فقط لاستعلام يحوي أرقاماً — وإلا صار normalize(نص عربي)
            // فارغاً فطابق LIKE '%%' كلَّ من له هاتف (خلل مطابقة زائفة)
            $digits = PhoneNumber::normalize($q);
            $like = '%' . $norm . '%';
            $query->where(function ($w) use ($q, $norm, $digits, $like) {
                $w->whereRaw(ArabicText::sqlNormalize('name') . ' LIKE ?', [$like])
                  ->orWhereRaw(ArabicText::sqlNormalize('guardian_name') . ' LIKE ?', [$like])
                  ->orWhereRaw(ArabicText::sqlNormalize('former_teacher_name') . ' LIKE ?', [$like])
                  ->orWhereRaw(ArabicText::sqlNormalize('nationality_name') . ' LIKE ?', [$like])
                  ->orWhereHas('center', fn ($c) => $c->whereRaw(ArabicText::sqlNormalize('name') . ' LIKE ?', [$like]))
                  ->orWhereHas('teacher', fn ($t) => $t->whereRaw(ArabicText::sqlNormalize('name') . ' LIKE ?', [$like]));
                // كلمتا التصنيف: «ليبي» و«أجنبي» (بعد التطبيع: اجنبي)
                if (mb_strpos($norm, 'ليبي') !== false) {
                    $w->orWhere('nationality_type', 'libyan');
                }
                if (mb_strpos($norm, 'اجنبي') !== false) {
                    $w->orWhere('nationality_type', 'foreigner');
                }
                if ($digits) {
                    $w->orWhere('national_id', 'like', "%{$q}%")
                      ->orWhere('phone', 'like', "%{$digits}%");
                }
            });
        }

        if ($request->has('all') && $request->all == 1) {
            $students = $query->get();
        } else {
            // ترقيم من الخادم (data + current_page/last_page/total ضمن كائن الترقيم)
            $students = $query->paginate(20)->withQueryString();
        }

        return response()->json([
            'success' => true,
            'data' => $students
        ]);
    }

    /**
     * معاينة كود الطالب التالي (S{n}) قبل الحفظ — للأدمن ومدير المركز.
     * قراءة فقط: لا يحجز الرقم (لو أُلغي النموذج لا يُهدر كود). الرقم تقديري
     * والفعلي يُخصَّص لحظة الحفظ ذرّياً.
     */
    public function nextCode()
    {
        $code = \App\Support\DisplayCode::preview('student');
        return response()->json([
            'success' => true,
            'data' => [
                'code'   => $code,                               // S121
                'number' => (int) preg_replace('/\D/', '', $code), // 121 — رقم جهاز البصمة
            ],
        ]);
    }

    public function store(Request $request)
    {
        // يخدم مسارين: الأدمن (POST /students) ومدير المركز (POST /manager/students).
        // ينشئ الطالب ويربطه بحساب ولي أمر (role='parent').
        $user = $request->user();

        // أمان — مدير المركز: center_id يُفرض من نطاقه لا من الطلب (يتجاهل أي
        // قيمة يرسلها العميل)، فلا يضيف طالباً لمركز آخر مهما زوّر الجسم.
        if ($user->isCenterManager()) {
            $request->merge(['center_id' => $user->center_id]);
        }
        // display_code لا يُقرأ من الطلب إطلاقاً — يولّده خطاف Student::creating
        // (المصفوفة أدناه صريحة ولا تتضمّنه)، فأي قيمة واردة من العميل مُهمَلة.

        $natType  = $request->input('nationality_type', 'libyan');
        $gNatType = $request->input('guardian_nationality_type', 'libyan');

        $request->validate([
            'name'              => 'required|string|max:255',
            'nationality_type'  => 'nullable|in:libyan,foreigner',
            'nationality_name'  => 'required_if:nationality_type,foreigner|nullable|string|max:100', // اسم الجنسية للأجنبي
            'national_id'       => $this->studentIdentityRules($natType), // اختياري؛ الصيغة الليبية تُفرض للّيبي فقط
            'center_id'         => 'required|exists:centers,id',
            // المعلّم بدور teacher (S3) وينتمي للمركز المختار (لا محفّظ من مركز آخر)
            'teacher_id'        => ['nullable', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', 'teacher')->where('center_id', $request->input('center_id'))],
            'phone'             => 'nullable|string|max:20',
            'age'               => 'nullable|integer|between:1,120',
            // ولي أمر موجود (الوضع B): يجب أن يكون مستخدماً بدور parent
            'parent_id'         => ['nullable', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', 'parent')],
            'guardian_name'     => 'nullable|string|max:255',
            'guardian_email'    => 'nullable|email|max:255',
            'guardian_phone'    => 'nullable|string|max:20',
            'guardian_password' => 'nullable|string|min:6',
            'guardian_nationality_type' => 'nullable|in:libyan,foreigner',
            'guardian_nationality_name' => 'required_if:guardian_nationality_type,foreigner|nullable|string|max:100',
            'guardian_id_number'        => $this->parentIdentityRules($gNatType), // المعرّف الموحّد لولي الأمر
        ], array_merge([
            'name.required'           => 'اسم الطالب مطلوب',
            'center_id.required'      => 'يجب اختيار المركز',
            'teacher_id.exists'       => 'المعلّم المختار غير صالح أو لا ينتمي للمركز المختار',
            'parent_id.exists'        => 'ولي الأمر المختار غير صالح',
            'guardian_email.email'    => 'بريد ولي الأمر غير صحيح',
            'guardian_password.min'   => 'كلمة مرور ولي الأمر يجب أن تكون 6 أحرف على الأقل',
        ], $this->nationalIdMessages()));

        // ترتيب حسم ولي الأمر:
        //  (B) parent_id موجود → ربط مباشر بحساب موجود، بلا إنشاء حساب جديد.
        //  (A) بيانات ولي → المنطق الموحّد ParentResolver (هوية → هاتف مطبَّع → بريد، وإلا إنشاء).
        //  (C) لا شيء → null.
        // transaction: إنشاء ولي الأمر + الطالب + حجز كود العرض (S..) تنجح كلها أو تُلغى معاً
        $student = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $natType, $gNatType) {
            $parentId = $request->filled('parent_id')
                ? (int) $request->parent_id
                : ParentResolver::resolve([
                    'name'             => $request->guardian_name,
                    'email'            => $request->guardian_email,
                    'phone'            => $request->guardian_phone,
                    'password'         => $request->guardian_password,
                    'nationality_type' => $gNatType,
                    'nationality_name' => $request->guardian_nationality_name,
                    'id_number'        => $request->guardian_id_number,
                ])?->id;

            return Student::create([
                'name'             => $request->name,
                'national_id'      => $request->national_id ?: null, // اختياري (وطني لو ليبي / جواز لو أجنبي)
                'nationality_type' => $natType,
                'nationality_name' => $natType === 'foreigner' ? $request->nationality_name : null,
                'phone'            => PhoneNumber::normalize($request->phone),
                'age'              => $request->age,
                'center_id'        => $request->center_id,
                'teacher_id'       => $request->teacher_id,
                'parent_id'        => $parentId,
                'guardian_name'    => $request->guardian_name,   // يبقى للعرض فقط
                'guardian_phone'   => PhoneNumber::normalize($request->guardian_phone),  // يبقى للعرض فقط
                'enrollment_date'  => now(),
                'is_active'        => true,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة الطالب وربطه بولي أمره بنجاح',
            'data' => $student->load(['center', 'teacher', 'parent'])
        ], 201);
    }

    /**
     * بحث أولياء الأمور (admin فقط) — لاختيار ولي أمر موجود عند إضافة طالب.
     * يطابق الاسم (متجاهلاً التشكيل) أو الهاتف (تطابق جزئي). GET /api/parents/search?q=
     */
    /**
     * بحث ولي أمر موجود لمدير المركز — بالرقم الوطني (id_number) حصراً:
     *  - center_manager فقط (فوق بوابة manager) ومقيّد بدور parent.
     *  - يعيد الاسم + الرقم الوطني + عدد الأبناء فقط (لا هاتف ولا بريد ولا قائمة أبناء).
     *  - الربط لاحقاً يتم بـparent_id_number في POST /manager/students.
     */
    public function managerSearchParents(Request $request)
    {
        if (!$request->user()->isCenterManager()) {
            return response()->json(['success' => false, 'message' => 'هذا البحث لمدير المركز فقط'], 403);
        }

        // أرقام فقط (تُقبل الأرقام العربية) — 3 أرقام على الأقل قبل البحث
        $digits = preg_replace('/\D/u', '', strtr((string) $request->get('q', ''),
            ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));
        if (strlen($digits) < 3) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $parents = User::where('role', 'parent')
            ->where('id_number', 'like', $digits . '%')
            ->withCount('children')
            ->orderBy('id_number')
            ->limit(10)
            ->get()
            ->map(fn ($p) => [
                'name'           => $p->name,
                'id_number'      => $p->id_number,
                'children_count' => $p->children_count,
            ]);

        return response()->json(['success' => true, 'data' => $parents]);
    }
    public function searchParents(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $qName = ArabicText::stripTashkeel($q); // أسماء القاعدة بلا تشكيل أصلاً

        $parents = User::where('role', 'parent')
            ->where(function ($w) use ($q, $qName) {
                $w->where('phone', 'like', "%{$q}%")
                  ->orWhere('name', 'like', "%{$qName}%");
            })
            ->withCount('children')
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(fn ($p) => [
                'id'             => $p->id,
                'name'           => $p->name,
                'phone'          => $p->phone,
                'email'          => $p->email,
                'children_count' => $p->children_count,
            ]);

        return response()->json(['success' => true, 'data' => $parents]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $student = Student::with(['center', 'teacher'])->findOrFail($id);

        if (!$user->isAdmin() && $student->teacher_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بالوصول لهذا الطالب'
            ], 403);
        }

        // ملاحظة: كانت تُجلب هنا «المراجعات» (revisions) وتُعاد بلا أي مستهلك في الواجهة —
        // أُزيل الجلبُ الميت (6ج). الجدول والموديل باقيان لبناء الميزة لاحقاً.
        $memorizations  = $student->memorizations()->with('teacher')->latest()->take(10)->get();
        $attendances    = $student->attendances()->with('teacher')->latest()->take(10)->get();
        $weeklyTests     = $student->weeklyTests()->with(['teacher', 'questions'])->latest()->take(10)->get(); // تحميل أجزاء الاختبار (الأثمان) مع الاختبارات الأسبوعية لصفحة تفاصيل الطالب

        return response()->json([
            'success' => true,
            'data' => [
                'student' => $student,
                'memorizations' => $memorizations,
                'attendances' => $attendances,
                'weeklyTests' => $weeklyTests
            ]
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $student = Student::findOrFail($id);

        if (!$user->isAdmin() && $student->teacher_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بتعديل هذا الطالب'
            ], 403);
        }

        if ($user->isAdmin()) {
            $natType = $request->input('nationality_type', $student->nationality_type ?: 'libyan');
            $request->validate([
                'name' => 'required|string|max:255',
                'nationality_type' => 'nullable|in:libyan,foreigner',
                'nationality_name' => 'required_if:nationality_type,foreigner|nullable|string|max:100',
                'national_id' => $this->studentIdentityRules($natType, $student->id), // يتجاهل الطالب نفسه في فحص التفرّد
                'phone' => 'nullable|string|max:20',
                'age' => 'nullable|integer|between:1,120',
                'center_id' => 'required|exists:centers,id',
                'teacher_id' => ['nullable', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', 'teacher')->where('center_id', $request->input('center_id'))],
            ], array_merge([
                'name.required' => 'اسم الطالب مطلوب',
                'center_id.required' => 'يجب اختيار المركز',
                'teacher_id.exists' => 'المعلّم المختار غير صالح أو لا ينتمي للمركز المختار',
            ], $this->nationalIdMessages()));

            $student->update([
                'name' => $request->name,
                'national_id' => $request->national_id ?: null,
                'nationality_type' => $natType,
                'nationality_name' => $natType === 'foreigner' ? $request->nationality_name : null,
                'phone' => $request->phone,
                'age' => $request->age,
                'center_id' => $request->center_id,
                'teacher_id' => $request->teacher_id,
            ]);
        } else {
            // المحفّظ لا يُسند طالباً لغيره ولا يفكّ إسناده — كان الحقل يُسقَط بصمت،
            // والآن رفض صريح (مدير المركز وحده عبر PUT /manager/students/{id}/teacher)
            if ($request->has('teacher_id')) {
                return response()->json([
                    'success' => false,
                    'message' => 'تغيير محفّظ الطالب من صلاحية مدير المركز فقط',
                ], 403);
            }

            $request->validate([
                'name'           => 'required|string|max:255',
                'phone'          => 'nullable|string|max:20',
                'age'            => 'nullable|integer|between:1,120',
                'guardian_name'  => 'nullable|string|max:255',
                'guardian_phone' => 'nullable|string|max:20',
            ], [
                'name.required' => 'اسم الطالب مطلوب',
            ]);

            $student->update([
                'name'           => $request->name,
                'phone'          => $request->phone,
                'age'            => $request->age,
                'guardian_name'  => $request->guardian_name,
                'guardian_phone' => $request->guardian_phone,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات الطالب بنجاح',
            'data' => $student
        ]);
    }

    /**
     * إيقاف/تفعيل الطالب — بديل الحذف نهائياً (قرار معتمد). الحذف كان يمحو
     * كل تاريخه فعلياً: attendances/memorizations/weekly_tests مربوطة به
     * بـ cascadeOnDelete. الموقوف يبقى بسجلّه كاملاً لكنه يخرج من الحضور
     * والبصمة والتقارير (is_active مُحترم في تلك المسارات أصلاً).
     * الصلاحية: مدير النظام لأي طالب، ومدير المركز لطلاب مركزه حصراً.
     */
    public function toggleStatus(Request $request, $id)
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ], ['is_active.required' => 'الحالة مطلوبة']);

        $user    = $request->user();
        $student = Student::findOrFail($id);

        // مدير المركز مضيَّق بمركزه — لا يمسّ طالب مركز آخر
        if ($user->isCenterManager() && (int) $student->center_id !== (int) $user->center_id) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الطالب ليس من طلاب مركزك',
            ], 403);
        }

        $active = $request->boolean('is_active');
        $student->update([
            'is_active'         => $active,
            'status_changed_by' => $user->id,
            'status_changed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $active
                ? "تم تفعيل الطالب «{$student->name}» — عاد إلى قوائم الحضور والتقارير"
                : "تم إيقاف الطالب «{$student->name}» — خرج من الحضور والبصمة والتقارير، وسجلّه محفوظ",
            'data' => ['id' => $student->id, 'is_active' => $student->is_active],
        ]);
    }
    /**
     * تغيير محفّظ الطالب أو فكّ إسناده — مدير المركز حصراً، وضمن مركزه فقط:
     *  - teacher_id = NULL قيمة صالحة صراحةً (الطالب يصبح «بدون محفّظ» — لا إسناد تلقائي لأحد).
     *  - المحفّظ الجديد يجب أن يكون نشطاً ومن مركز المدير نفسه، وإلا 422 عربية.
     *  - الطالب الموقوف يُغيَّر محفّظه أيضاً (لا شرط على is_active).
     *  - يحفظ اسم المحفّظ السابق في former_teacher_name للعرض («محفّظ سابق: فلان»).
     *  - لا إشعار لهذا الإجراء.
     */
    public function changeTeacher(Request $request, $id)
    {
        $user = $request->user();

        // الدور محسوم في الباك لا في الواجهة (فوق بوابة manager)
        if (!$user->isCenterManager()) {
            return response()->json([
                'success' => false,
                'message' => 'تغيير محفّظ الطالب من صلاحية مدير المركز فقط',
            ], 403);
        }

        // «present» لا «required»: NULL قيمة مقصودة، أما غياب الحقل كلياً فخطأ
        $request->validate([
            'teacher_id' => 'present|nullable|integer',
        ], [
            'teacher_id.present' => 'حقل المحفّظ مطلوب (أرسل NULL لفكّ الإسناد)',
            'teacher_id.integer' => 'معرّف المحفّظ غير صالح',
        ]);

        $student = Student::findOrFail($id);

        if ((int) $student->center_id !== (int) $user->center_id) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الطالب ليس من طلاب مركزك',
            ], 403);
        }

        $teacher = null;
        if ($request->input('teacher_id') !== null) {
            $teacher = User::where('id', (int) $request->input('teacher_id'))
                ->where('role', 'teacher')
                ->where('center_id', $user->center_id)
                ->where('is_active', true)
                ->first();

            if (!$teacher) {
                return response()->json([
                    'success' => false,
                    'message' => 'المحفّظ المختار غير صالح',
                    'errors'  => ['teacher_id' => ['يجب أن يكون المحفّظ نشطاً ومن محفّظي مركزك']],
                ], 422);
            }
        }

        $previous = $student->teacher; // قد يكون null
        $newId    = $teacher?->id;

        if ((int) $student->teacher_id === (int) $newId && $student->teacher_id !== null) {
            // نفس المحفّظ — لا تغيير ولا مساس بالمحفّظ السابق
        } else {
            $student->update([
                'teacher_id'          => $newId,
                'former_teacher_name' => $previous ? $previous->name : $student->former_teacher_name,
            ]);
        }

        $student->load('teacher');

        return response()->json([
            'success' => true,
            'message' => $teacher
                ? "تم إسناد الطالب «{$student->name}» إلى المحفّظ «{$teacher->name}»"
                : "تم فكّ إسناد الطالب «{$student->name}» — أصبح بدون محفّظ",
            'data' => [
                'id'                  => $student->id,
                'teacher_id'          => $student->teacher_id,
                'teacher'             => $student->teacher ? ['id' => $student->teacher->id, 'name' => $student->teacher->name] : null,
                'former_teacher_name' => $student->former_teacher_name,
            ],
        ]);
    }

    // =============================================
    // مسارات ولي الأمر — Parent Routes
    // =============================================

    /**
     * جلب أبناء ولي الأمر مع ملخص البيانات
     * (الربط عبر students.parent_id = معرّف ولي الأمر الحالي)
     */
    public function parentChildren(Request $request)
    {
        $parentId = $request->user()->id;

        $students = Student::where('parent_id', $parentId)
            ->with(['center', 'teacher'])
            ->get();

        // استعلامان مجمّعان لكل الأبناء (بدل 3 استعلامات لكل ابن — N+1)
        $ids = $students->pluck('id');
        $attStats = Attendance::whereIn('student_id', $ids)
            ->selectRaw('student_id, COUNT(*) AS total, SUM(status = "present") AS present')
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');
        $lastMemos = Memorization::whereIn('student_id', $ids)
            ->orderByDesc('date')->orderByDesc('id')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($g) => $g->first());

        $result = $students->map(function ($student) use ($attStats, $lastMemos) {
            $lastMemo = $lastMemos->get($student->id);

            // ملخص الحضور (نسبة الحضور)
            $stat = $attStats->get($student->id);
            $totalAttendance = (int) ($stat->total ?? 0);
            $presentCount = (int) ($stat->present ?? 0);
            $attendancePercent = Percentage::of($presentCount, $totalAttendance);

            return [
                'id' => $student->id,
                'name' => $student->name,
                'age' => $student->age,
                'is_active' => $student->is_active, // الابن الموقوف يبقى ظاهراً بشارة (لا يُخفى بلا تفسير)
                'center' => $student->center ? $student->center->name : '--',
                'center_city' => $student->center ? $student->center->city : '--',
                'teacher_name' => $student->teacher ? $student->teacher->name : '--',
                'last_surah' => $lastMemo ? $lastMemo->surah_name : '--',
                'last_memo_date' => $lastMemo ? $lastMemo->date : null,
                'attendance_percent' => $attendancePercent,
                'total_attendance_days' => $totalAttendance,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }

    /**
     * جلب تفاصيل طالب واحد لولي الأمر.
     * السجلات الثلاثة (حفظ/حضور/اختبارات) مرقّمة من الخادم — 5 لكل صفحة بمعاملات
     * مستقلة (memo_page / att_page / tests_page) حتى «عرض المزيد» في كل سجل وحده.
     * الملخّصات تُحسب على كل السجلات باستعلامات تجميعية، لا على الصفحة الظاهرة.
     * فحص ملكية ولي الأمر يسبق أي استعلام سجلات — ابن غيره → 403.
     */
    public function parentStudentDetails(Request $request, $id)
    {
        $student = Student::with(['center', 'teacher'])->findOrFail($id);

        // التحقق من أن الطالب من أبناء ولي الأمر الحالي (قبل أي جلب سجلات)
        if ($student->parent_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الطالب غير مسجل تحت ولايتك'
            ], 403);
        }

        // سجل الحفظ — مرقّم (الأحدث أولاً)
        $memorizations = Memorization::where('student_id', $student->id)
            ->latest('date')->latest('id')
            ->paginate(5, ['*'], 'memo_page')
            ->through(fn ($m) => [
                'surah_name' => $m->surah_name,
                'quality' => $m->quality,
                'date' => $m->date,
                'notes' => $m->notes,
                'page_from' => $m->page_from,
                'page_to' => $m->page_to,
            ]);

        // سجل الحضور — مرقّم
        $statusMap = ['present' => 'حاضر', 'absent' => 'غائب', 'late' => 'متأخر'];
        $dayNames = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $attendances = Attendance::where('student_id', $student->id)
            ->latest('date')->latest('id')
            ->paginate(5, ['*'], 'att_page')
            ->through(fn ($a) => [
                'date' => $a->date,
                'day' => $dayNames[\Carbon\Carbon::parse($a->date)->dayOfWeek] ?? '--',
                'status' => $statusMap[$a->status] ?? $a->status,
                'status_raw' => $a->status,
                'notes' => $a->notes,
            ]);

        // ملخص الحضور على كل السجلات (استعلام تجميعي واحد — لا يتأثر بالترقيم)
        $att = Attendance::where('student_id', $student->id)
            ->selectRaw('COUNT(*) AS total, SUM(status = "present") AS present, SUM(status = "absent") AS absent, SUM(status = "late") AS late')
            ->first();
        $totalAttendance = (int) ($att->total ?? 0);
        $presentCount = (int) ($att->present ?? 0);

        // سجل الاختبارات الأسبوعية — مرقّم (نموذج الأثمان: نتيجة كلية + سؤال لكل ثمن)
        $weeklyTests = \App\Models\WeeklyTest::with('questions')
            ->where('student_id', $student->id)
            ->latest('exam_date')->latest('id')
            ->paginate(5, ['*'], 'tests_page')
            ->through(fn ($t) => [
                'date'            => $t->exam_date,
                'result'          => $t->result,
                'notes'           => $t->notes,
                'questions_count' => $t->questions->count(),
                'passed_count'    => $t->questions->where('result', 'ناجح')->count(),
                'failed_count'    => $t->questions->where('result', 'راسب')->count(),
                'questions'       => $t->questions->map(fn ($q) => [
                    'eighth_start' => $q->eighth_start,
                    'result'       => $q->result,
                    'mistake'      => $q->mistake,
                ])->values(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'student' => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'age' => $student->age,
                    'phone' => $student->phone,
                    'guardian_name' => $student->guardian_name,
                    'guardian_phone' => $student->guardian_phone,
                    'center' => $student->center ? $student->center->name : '--',
                    'center_city' => $student->center ? $student->center->city : '--',
                    'teacher_name' => $student->teacher ? $student->teacher->name : '--',
                    'enrollment_date' => $student->enrollment_date,
                    'is_active' => $student->is_active, // موقوف: يُعرض لولي الأمر بشارة، وسجلّه كامل
                ],
                // كائنات ترقيم: data + current_page/last_page/total
                'memorizations' => $memorizations,
                'attendances' => $attendances,
                'weekly_tests' => $weeklyTests,
                'tests_summary' => [
                    'total'       => \App\Models\WeeklyTest::where('student_id', $student->id)->count(),
                    'last_result' => \App\Models\WeeklyTest::where('student_id', $student->id)
                        ->latest('exam_date')->latest('id')->value('result'), // الأحدث دائماً
                ],
                'attendance_summary' => [
                    'total' => $totalAttendance,
                    'present' => $presentCount,
                    'absent' => (int) ($att->absent ?? 0),
                    'late' => (int) ($att->late ?? 0),
                    'percent' => Percentage::of($presentCount, $totalAttendance),
                ],
            ]
        ]);
    }
}
