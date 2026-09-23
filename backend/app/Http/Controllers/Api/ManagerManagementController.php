<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * إدارة «مدراء المراكز» — لمدير النظام فقط.
 * القاعدة: مدير واحد لكل مركز كحدّ أقصى.
 */
class ManagerManagementController extends Controller
{
    /**
     * مدير «نشط» واحد لكل مركز كحدّ أقصى — العدّ يستثني المعطَّلين عمداً:
     * وإلا استحال استبدال مدير مركزٍ نشط (تعيين البديل يُرفض لوجود القديم،
     * وتعطيل القديم يُرفض لغياب البديل — جمود). مسار الاستبدال الطبيعي:
     * تعيين البديل أولاً ثم تعطيل القديم.
     */
    protected function assertSingleSupervisor($centerId, $ignoreId = null): void
    {
        $exists = User::where('role', 'center_manager')
            ->where('center_id', $centerId)
            ->where('is_active', true)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'center_id' => ['لهذا المركز مدير نشط بالفعل — مدير نشط واحد لكل مركز كحدّ أقصى'],
            ]);
        }
    }

    public function index(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => User::where('role', 'center_manager')
                ->with('center:id,name,city')
                ->latest()->orderByDesc('id')
                ->get(['id', 'name', 'display_code', 'email', 'phone', 'center_id', 'is_active', 'created_at']),
        ]);
    }

    public function store(Request $request)
    {
        // بريد مدير المركز يُولَّد بالمخطط الموحّد {الاسم اللاتيني}_{الكود}@mutqin.ly
        // (مثل muad_ca1@mutqin.ly) بعد حجز الكود — لا يُقبل من العميل.
        // كلمة المرور مطلوبة صراحةً عند الإنشاء — لا توليد صامت (درس S1).

        // تسامح مع الإدخال: من يكتب البريد كاملاً (mohammad.centeradmin@mutqin.ly)
        // نستخرج الاسم منه تلقائياً بدل رفضه — يُقصّ ما بعد @ ولاحقة .centeradmin
        $prefix = strtolower(trim((string) $request->input('email_prefix', '')));
        $prefix = preg_replace('/@.*$/', '', $prefix);
        $prefix = preg_replace('/\.centeradmin$/', '', $prefix);
        $request->merge(['email_prefix' => $prefix]);

        $request->validate([
            'name'         => 'required|string|max:255',
            'email_prefix' => ['required', 'string', 'max:40', 'regex:/^[a-z]+(\.[a-z]+)*$/'],
            'phone'        => 'nullable|string|max:20',
            'password'     => 'required|min:6|confirmed',
            'center_id'    => 'required|exists:centers,id',
        ], [
            'name.required'         => 'اسم مدير المركز مطلوب',
            'email_prefix.required' => 'الاسم اللاتيني مطلوب (مثل: muad)',
            'email_prefix.regex'    => 'الصيغة: أحرف لاتينية صغيرة (ونقطة اختيارياً)، مثل muad أو ali.faraj',
            'password.required'     => 'كلمة المرور مطلوبة',
            'password.min'          => 'كلمة المرور 6 أحرف على الأقل',
            'password.confirmed'    => 'كلمتا المرور غير متطابقتين',
            'center_id.required'    => 'يجب اختيار المركز',
        ]);

        $this->assertSingleSupervisor($request->center_id);

        // transaction: إنشاء المدير + حجز كود العرض (CA..) + بناء البريد من الكود ينجحون معاً أو يُلغون معاً
        $manager = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $prefix) {
            $u = User::create([
                'name'      => $request->name,
                'email'     => \App\Support\LoginEmail::temporary(),
                'phone'     => PhoneNumber::normalize($request->phone),
                'role'      => 'center_manager',
                'password'  => Hash::make($request->password),
                'center_id' => $request->center_id,
            ]);

            return \App\Support\LoginEmail::assign($u, $prefix);
        });

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء مدير المركز بنجاح — بريده: ' . $manager->email,
            'data' => $manager,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $manager = User::where('role', 'center_manager')->findOrFail($id);

        $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email,' . $manager->id,
            'phone'     => 'nullable|string|max:20',
            'center_id' => 'required|exists:centers,id',
        ], [
            'name.required'      => 'اسم مدير المركز مطلوب',
            'email.unique'       => 'هذا البريد مستخدم مسبقاً',
            'center_id.required' => 'يجب اختيار المركز',
        ]);

        $this->assertSingleSupervisor($request->center_id, $manager->id);

        $data = [
            'name'      => $request->name,
            'email'     => $request->email,
            'phone'     => PhoneNumber::normalize($request->phone),
            'center_id' => $request->center_id,
        ];

        if ($request->filled('password')) {
            $request->validate(['password' => 'min:6|confirmed']);
            $data['password'] = Hash::make($request->password);
        }

        $manager->update($data);

        if ($request->filled('password')) {
            $manager->recordPasswordChange('admin');
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات مدير المركز بنجاح',
            'data' => $manager,
        ]);
    }

    /**
     * تفعيل/تعطيل حساب مدير المركز — بديل الحذف نهائياً (لا تُفقد ارتباطاته
     * التاريخية). المعطَّل يُمنع من الدخول (الفحص العام في AuthController)
     * وتُبطَل توكناته فوراً. بما أن القاعدة «مدير واحد لكل مركز»، فالتعطيل
     * يترك المركز بلا مدير نشط — لذا تؤول طلباته الداخلية لمدير النظام:
     * التوجيه التلقائي يتجاهل المدير المعطَّل (StudentRequestController)،
     * ونُشعر مدراء النظام بالمعلّق كي لا يبقى بصمت (نفس أثر الحذف سابقاً).
     */
    public function toggleStatus(Request $request, $id)
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ], ['is_active.required' => 'الحالة مطلوبة']);

        $manager = User::where('role', 'center_manager')->findOrFail($id);
        $active  = $request->boolean('is_active');
        $centerId = $manager->center_id;

        $pending = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($manager, $active, $request, $centerId, &$pending) {
            $manager->update([
                'is_active'         => $active,
                'status_changed_by' => $request->user()->id,
                'status_changed_at' => now(),
            ]);

            if ($active) {
                return;
            }

            $manager->tokens()->delete(); // إبطال جلساته فوراً (آلية S1)

            // الطلبات الواردة المعلّقة إلى مركزه: تبقى معلّقة حتى تعيين/تفعيل مدير نشط
            // (مدير النظام ليس طرفاً في الطلبات ولا تؤول إليه)
            $pending = \App\Models\StudentRequest::where('status', 'pending')
                ->where('target_center_id', $centerId)
                ->count();
        });

        if (!$active && $pending > 0) {
            // تنبيه إداري فقط لمدير النظام (شأنه تعيين المديرين، لا الطلبات) — الرابط لصفحة المديرين
            $centerName = \App\Models\Center::where('id', $centerId)->value('name') ?? '—';
            \App\Notifications\InAppNotification::sendSafe(
                User::where('role', 'admin')->get(),
                'manager_deactivated',
                'مركز بلا مدير نشط وله طلبات معلّقة',
                'عُطِّل مدير مركز «' . $centerName . '» وللمركز ' . $pending . ' طلب وارد معلّق يبقى بلا مراجع حتى تعيين مدير نشط له.',
                null,
                'admin/managers.html'
            );
        }

        return response()->json([
            'success' => true,
            'message' => $active
                ? "تم تفعيل حساب مدير المركز «{$manager->name}»"
                : "تم تعطيل حساب «{$manager->name}» — لن يستطيع الدخول"
                    . ($pending > 0 ? "، ويبقى {$pending} طلب وارد معلّق حتى تعيين مدير نشط للمركز" : ''),
            'data' => ['id' => $manager->id, 'is_active' => $manager->is_active, 'pending_requests' => $pending],
        ]);
    }
}
