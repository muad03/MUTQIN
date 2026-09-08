<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\StudentRequest;
use App\Models\Student;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Support\ParentResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * نظام طلبات الطلاب — مدير المركز (center_manager) هو المرجع الوحيد للطلبات،
 * ومدير النظام (admin) والمحفّظ (teacher) ليسا طرفين فيها (لا مسارات لهما إطلاقاً).
 *
 *  - طلب النقل (transfer): يُنشئه مدير المركز المصدر (A) لطالب من مركزه إلى مركز آخر (B)
 *    → يستقبله ويعتمده/يرفضه مدير المركز المستهدف (B) وحده.
 *  - طلب الإضافة (add): لم يعد يُنشأ (أُلغيت صفحة «طلباتي» ومساراتها) — الصفوف القديمة
 *    المعلّقة تبقى قابلة للاعتماد/الرفض من مدير مركزها حتى تُصفّى.
 *
 * مسارات مدير المركز: managerIndex / managerStore / approve / reject  (مجموعة manager)
 */
class StudentRequestController extends Controller
{
    /** مدير المركز النشط لمركز ما (مدير واحد كحدّ أقصى لكل مركز) — أو null. */
    protected function activeManagerOf(?int $centerId): ?User
    {
        if (!$centerId) {
            return null;
        }
        return User::where('role', 'center_manager')
            ->where('center_id', $centerId)
            ->where('is_active', true)
            ->first();
    }

    // =============================================
    // مسارات مدير المركز — Center Manager Routes
    // =============================================

    /**
     * طلبات مدير المركز (المعلّقة افتراضياً؛ ?status=all للكل):
     *  - الواردة (incoming): كل طلب وجهته مركزه (نقل من مركز آخر إليه، أو إضافة قديمة معلّقة) — يعتمدها/يرفضها.
     *  - الصادرة (outgoing): طلبات النقل التي أنشأها من مركزه إلى مركز آخر — للمتابعة فقط.
     * طلبات المراكز الأخرى لا تظهر إطلاقاً.
     */
    public function managerIndex(Request $request)
    {
        $c = $request->user()->center_id;
        $query = StudentRequest::with(['requestedBy', 'targetCenter', 'targetTeacher', 'fromCenter', 'fromTeacher', 'student'])
            ->where(function ($w) use ($c) {
                $w->where('target_center_id', $c)
                  ->orWhere(function ($o) use ($c) {
                      $o->where('type', 'transfer')->where('from_center_id', $c);
                  });
            })
            ->latest();

        $status = $request->get('status', 'pending');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn ($r) => $this->present($r, true, $c)),
        ]);
    }

    /**
     * إنشاء طلب نقل طالب من مركز المدير (المصدر) إلى مركز آخر (المستهدف).
     * المصدر يُفرض من حساب المدير، والطالب يجب أن يكون من مركزه، والمستهدف مركز نشط
     * آخر له مدير نشط (هو المستلم الوحيد للإشعار). محفّظ الوجهة اختياري — يعيّنه
     * مدير المركز المستهدف عند الاعتماد.
     */
    public function managerStore(Request $request)
    {
        $user = $request->user();
        $c = (int) $user->center_id;

        $request->validate([
            'student_id'        => 'required|integer|exists:students,id',
            'target_center_id'  => 'required|integer|exists:centers,id',
            'target_teacher_id' => 'nullable|integer',
        ], [
            'student_id.required'       => 'يجب تحديد الطالب المراد نقله',
            'student_id.exists'         => 'الطالب المحدّد غير موجود',
            'target_center_id.required' => 'يجب تحديد المركز المستهدف',
            'target_center_id.exists'   => 'المركز المستهدف غير موجود',
        ]);

        $student = Student::with('teacher')->findOrFail($request->student_id);

        // النطاق: الطالب من مركز المدير فقط (لا يُنشئ طلباً عن طلاب مراكز أخرى)
        if ((int) $student->center_id !== $c) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الطالب ليس من مركزك — لا يمكنك طلب نقله',
            ], 403);
        }
        if (!$student->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'الطالب موقوف — فعّله أولاً قبل طلب نقله',
            ], 422);
        }

        $targetCenterId = (int) $request->target_center_id;
        if ($targetCenterId === $c) {
            return response()->json([
                'success' => false,
                'message' => 'المركز المستهدف هو مركزك نفسه — اختر مركزاً آخر',
            ], 422);
        }
        $targetCenter = Center::find($targetCenterId);
        if (!$targetCenter || !$targetCenter->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'المركز المستهدف غير نشط',
            ], 422);
        }

        // المستلم الوحيد: مدير المركز المستهدف النشط (مدير النظام ليس طرفاً في النقل)
        $targetManager = $this->activeManagerOf($targetCenterId);
        if (!$targetManager) {
            return response()->json([
                'success' => false,
                'message' => 'المركز المستهدف بلا مدير نشط لاستقبال طلب النقل',
            ], 422);
        }

        // محفّظ الوجهة (اختياري): محفّظ نشط ضمن المركز المستهدف
        $targetTeacherId = null;
        if ($request->filled('target_teacher_id')) {
            $tt = User::where('id', $request->target_teacher_id)
                ->where('role', 'teacher')->where('center_id', $targetCenterId)
                ->where('is_active', true)->first();
            if (!$tt) {
                return response()->json([
                    'success' => false,
                    'message' => 'المحفّظ المحدّد ليس محفّظاً نشطاً ضمن المركز المستهدف',
                    'errors'  => ['target_teacher_id' => ['المحفّظ المحدّد ليس محفّظاً نشطاً ضمن المركز المستهدف']],
                ], 422);
            }
            $targetTeacherId = $tt->id;
        }

        // منع تكرار طلب نقل معلّق لنفس الطالب (من أي جهة)
        $dup = StudentRequest::where('type', 'transfer')
            ->where('status', 'pending')
            ->where('student_id', $student->id)
            ->exists();
        if ($dup) {
            return response()->json([
                'success' => false,
                'message' => 'يوجد طلب نقل معلّق لهذا الطالب قيد المراجعة',
            ], 422);
        }

        $req = StudentRequest::create([
            'type'              => 'transfer',
            'status'            => 'pending',
            'requested_by'      => $user->id,
            'student_id'        => $student->id,
            'national_id'       => $student->national_id,
            'nationality_type'  => $student->nationality_type ?: 'libyan',
            'nationality_name'  => $student->nationality_name,
            'student_name'      => $student->name,
            'from_center_id'    => $c,
            'from_teacher_id'   => $student->teacher_id,
            'target_center_id'  => $targetCenterId,
            'target_teacher_id' => $targetTeacherId,
        ]);

        InAppNotification::sendSafe(
            $targetManager,
            'request_created',
            'طلب نقل وارد بانتظار الموافقة',
            'مدير مركز «' . ($user->center->name ?? '—') . '» طلب نقل الطالب «' . $req->student_name . '» إلى مركزك.',
            $req->id,
            'manager/requests.html'
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلب النقل لمدير مركز «' . $targetCenter->name . '»، سيُنفَّذ بعد موافقته',
            'data'    => ['request' => $this->present($req->fresh(['requestedBy', 'targetCenter', 'targetTeacher', 'fromCenter', 'fromTeacher']), true, $c)],
        ], 201);
    }

    /**
     * نطاق الاعتماد/الرفض: مدير المركز المستهدف وحده (target_center = مركزه).
     * الطلبات الصادرة من مركزه إلى غيره، وطلبات المراكز الأخرى → 403.
     * أي حساب آخر (بما فيه مدير النظام) ليس طرفاً → 403.
     */
    protected function assertManagerScope(Request $request, StudentRequest $req): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->isCenterManager() || !$user->center_id) {
            return response()->json([
                'success' => false,
                'message' => 'اعتماد الطلبات من صلاحية مدير المركز المستهدف فقط',
            ], 403);
        }
        if ((int) $req->target_center_id !== (int) $user->center_id) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الطلب ليس وارداً إلى مركزك — يعتمده مدير المركز المستهدف',
            ], 403);
        }
        return null;
    }

    /**
     * موافقة مدير المركز المستهدف — تُنفّذ التغيير الفعلي على students داخل معاملة:
     *  - add      → ينشئ الطالب (مع ربط ولي الأمر) بعد إعادة فحص التكرار.
     *  - transfer → ينقل الطالب (center_id/teacher_id) إلى مركزه؛ محفّظ الوجهة اختياري
     *               (target_teacher_id في الجسم يغلب ما في الطلب) وإلا بقي الطالب بلا محفّظ.
     * يعيد التحقّق من أن محفّظ الوجهة (إن وُجد) ما زال محفّظاً ضمن مركز الوجهة.
     */
    public function approve(Request $request, $id)
    {
        $req = StudentRequest::findOrFail($id);

        if ($guard = $this->assertManagerScope($request, $req)) {
            return $guard;
        }

        if ($req->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'تمت معالجة هذا الطلب مسبقاً',
            ], 422);
        }

        $request->validate(['target_teacher_id' => 'nullable|integer']);
        $teacherId = $request->filled('target_teacher_id') ? (int) $request->target_teacher_id : $req->target_teacher_id;

        // الأمان: محفّظ الوجهة (إن حُدّد) يجب أن يكون محفّظاً ضمن مركز الوجهة (قاعدة «المحفّظ ينتمي للمركز»)
        $targetTeacher = null;
        if ($teacherId) {
            $targetTeacher = User::where('id', $teacherId)
                ->where('role', 'teacher')
                ->where('center_id', $req->target_center_id)
                ->first();
            if (!$targetTeacher) {
                return response()->json([
                    'success' => false,
                    'message' => 'المحفّظ المستهدف ليس محفّظاً ضمن المركز المستهدف — لا يمكن تنفيذ الطلب',
                ], 422);
            }
        }

        if ($req->type === 'add') {
            // صف إضافة قديم (قبل إلغاء طلبات المحفّظ) — يحمل محفّظه دائماً
            if (!$targetTeacher) {
                return response()->json([
                    'success' => false,
                    'message' => 'المحفّظ مقدّم الطلب لم يعد محفّظاً ضمن المركز — لا يمكن تنفيذ الطلب',
                ], 422);
            }

            // إعادة فحص التكرار بالرقم الوطني (قد يكون أُضيف بين الإرسال والموافقة)
            if ($req->national_id && Student::where('national_id', $req->national_id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'أصبح هناك طالب مسجّل بنفس الرقم الوطني. ارفض هذا الطلب — نقله يكون بطلب من مدير مركزه.',
                ], 422);
            }

            $student = DB::transaction(function () use ($req, $targetTeacher) {
                $parentId = $this->resolveParentId($req);

                $student = Student::create([
                    'name'             => $req->student_name,
                    'national_id'      => $req->national_id ?: null,
                    'nationality_type' => $req->nationality_type ?: 'libyan',
                    'nationality_name' => $req->nationality_type === 'foreigner' ? $req->nationality_name : null,
                    'phone'            => $req->phone,
                    'age'              => $req->age,
                    'center_id'        => $req->target_center_id,
                    'teacher_id'       => $targetTeacher->id,
                    'parent_id'        => $parentId,
                    'guardian_name'    => $req->guardian_name,
                    'guardian_phone'   => $req->guardian_phone,
                    'enrollment_date'  => now(),
                    'is_active'        => true,
                ]);

                $req->update(['status' => 'approved', 'student_id' => $student->id]);
                return $student;
            });

            InAppNotification::sendSafe(
                $req->requestedBy,
                'request_approved',
                'تمت الموافقة على طلبك',
                'تمت الموافقة على طلب إضافة الطالب «' . $req->student_name . '» وإضافته لمركزك.',
                $req->id,
                $this->requesterLink($req)
            );

            return response()->json([
                'success' => true,
                'message' => 'تمت الموافقة وإنشاء الطالب بنجاح',
                'data'    => $student->load(['center', 'teacher', 'parent']),
            ]);
        }

        // transfer
        $student = Student::with('teacher')->find($req->student_id);
        if (!$student) {
            return response()->json([
                'success' => false,
                'message' => 'الطالب المطلوب نقله لم يعد موجوداً',
            ], 422);
        }

        DB::transaction(function () use ($req, $student, $targetTeacher) {
            $student->update([
                'center_id'  => $req->target_center_id,
                'teacher_id' => $targetTeacher?->id,
                // بلا محفّظ وجهة: نحفظ اسم محفّظه السابق للعرض («محفّظ سابق: فلان») حتى يُوزَّع
                'former_teacher_name' => $targetTeacher ? $student->former_teacher_name : ($student->teacher->name ?? $student->former_teacher_name),
            ]);
            $req->update(['status' => 'approved', 'target_teacher_id' => $targetTeacher?->id]);
        });

        InAppNotification::sendSafe(
            $req->requestedBy,
            'request_approved',
            'تمت الموافقة على طلبك',
            'وافق مدير مركز «' . ($req->targetCenter->name ?? '—') . '» على نقل الطالب «' . $req->student_name . '» إليه.',
            $req->id,
            $this->requesterLink($req)
        );

        return response()->json([
            'success' => true,
            'message' => $targetTeacher
                ? 'تمت الموافقة ونقل الطالب إلى المحفّظ «' . $targetTeacher->name . '»'
                : 'تمت الموافقة ونقل الطالب إلى مركزك بلا محفّظ — وزّعه من صفحة الطلاب',
            'data'    => $student->load(['center', 'teacher']),
        ]);
    }

    /**
     * رفض الطلب — لا تغيير على students، فقط الحالة + سبب اختياري.
     */
    public function reject(Request $request, $id)
    {
        $req = StudentRequest::findOrFail($id);

        if ($guard = $this->assertManagerScope($request, $req)) {
            return $guard;
        }

        if ($req->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'تمت معالجة هذا الطلب مسبقاً',
            ], 422);
        }

        $request->validate(
            ['admin_note' => 'nullable|string|max:500'],
            ['admin_note.max' => 'السبب طويل جداً (500 حرف كحدّ أقصى)']
        );

        $req->update([
            'status'     => 'rejected',
            'admin_note' => $request->admin_note,
        ]);

        $body = 'تم رفض طلب ' . $this->kindLabel($req) . ' الطالب «' . $req->student_name . '».';
        if ($request->filled('admin_note')) {
            $body .= ' السبب: ' . $request->admin_note;
        }
        InAppNotification::sendSafe($req->requestedBy, 'request_rejected', 'تم رفض طلبك', $body, $req->id, $this->requesterLink($req));

        return response()->json([
            'success' => true,
            'message' => 'تم رفض الطلب',
        ]);
    }

    // =============================================
    // مساعدات داخلية
    // =============================================

    /**
     * حسم ولي الأمر عند الموافقة — عبر المنطق الموحّد {@see ParentResolver}
     * (هوية → هاتف مطبَّع → بريد؛ إنشاء بكلمة عشوائية؛ فشل واضح 422 بالعربية —
     * لم يعد فشلاً صامتاً: طلبٌ ببيانات ولي ناقصة يظهر سببه لمدير المركز ليُكمل أو يرفض).
     */
    protected function resolveParentId(StudentRequest $req): ?int
    {
        return ParentResolver::resolve([
            'name'             => $req->guardian_name,
            'email'            => $req->guardian_email,
            'phone'            => $req->guardian_phone,
            'nationality_type' => $req->guardian_nationality_type ?: 'libyan',
            'nationality_name' => $req->guardian_nationality_name,
            'id_number'        => $req->guardian_id_number,
        ])?->id;
    }

    /** تسمية نوع الطلب للنصوص. */
    protected function kindLabel(StudentRequest $req): string
    {
        return $req->type === 'transfer' ? 'نقل' : 'إضافة';
    }

    /** صفحة النتيجة لصاحب الطلب (مدير مركز → «الطلبات»؛ محفّظ في صف إضافة قديم → «طلابي» — لا صفحة طلبات له). */
    protected function requesterLink(StudentRequest $req): string
    {
        return $req->requestedBy?->isCenterManager() ? 'manager/requests.html' : 'teacher/students.html'; // صف إضافة قديم لمحفّظ: بلا صفحة طلبات — يرى النتيجة في «طلابي»
    }

    /**
     * تحويل الطلب لصيغة عرض موحّدة للواجهة.
     * @param bool     $detailed هل نضمّن مقدّم الطلب ومحفّظ الوجهة (صفحة مدير المركز)؟
     * @param int|null $centerId مركز المدير — لتحديد اتجاه الطلب (incoming/outgoing) من منظوره.
     */
    protected function present(StudentRequest $r, bool $detailed = false, ?int $centerId = null): array
    {
        $out = [
            'id'             => $r->id,
            'type'           => $r->type,
            'status'         => $r->status,
            'student_name'   => $r->student_name,
            'national_id'    => $r->national_id,
            'target_center'  => $r->targetCenter->name ?? '—',
            'from_center'    => $r->fromCenter->name ?? null,
            'from_teacher'   => $r->fromTeacher->name ?? null,
            'admin_note'     => $r->admin_note,
            'created_at'     => $r->created_at,
        ];

        if ($detailed) {
            $out['requested_by']   = $r->requestedBy->name ?? '—';
            $out['target_teacher'] = $r->targetTeacher->name ?? null;
            $out['student_id']     = $r->student_id;
            if ($centerId !== null) {
                $out['direction'] = (int) $r->target_center_id === (int) $centerId ? 'incoming' : 'outgoing';
            }
        }

        return $out;
    }
}
