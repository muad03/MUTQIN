<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ArabicText;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * «جميع المستخدمين» للأدمن — عرض فقط (GET /admin/users):
 *  - كل صفوف users بأعمدة صريحة (لا password ولا remember_token ولا أي حقل حسّاس).
 *  - ?role=admin|center_manager|teacher|parent (افتراضي الكل) · ?status=active|inactive|all (افتراضي active).
 *  - ?q= بحث موحّد: الاسم المطبَّع (ArabicText) · الكود (T5 / CA5 / P5 / 5 / ٥) · الهاتف المطبَّع · البريد.
 *  - المركز بتحميل مسبق (استعلام واحد إضافي — لا N+1) · ترقيم 20/صفحة.
 * لا تعديل ولا تعطيل ولا إضافة من هنا — الصلاحيات تبقى في مساراتها القائمة.
 */
class AdminUserController extends Controller
{
    public const ROLES = ['admin', 'center_manager', 'teacher', 'parent'];

    public function index(Request $request)
    {
        $query = User::query()
            ->select(['id', 'name', 'display_code', 'email', 'phone', 'role', 'center_id', 'is_active', 'created_at'])
            ->with('center:id,name')
            ->orderByRaw("FIELD(role,'admin','center_manager','teacher','parent')")
            ->orderBy('name');

        $role = $request->input('role');
        if (in_array($role, self::ROLES, true)) {
            $query->where('role', $role);
        }

        $status = $request->input('status', 'active');
        if ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status !== 'all') {
            $query->where('is_active', true);
        }

        if (($q = trim((string) $request->get('q', ''))) !== '') {
            $norm   = ArabicText::normalize($q);
            $like   = '%' . $norm . '%';
            $digits = strtr($q, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
            // الهاتف: عند 4 أرقام فأكثر فقط — وإلا طابق 't1' كل هاتف يحوي الرقم 1
            $phone  = strlen(preg_replace('/\D/', '', $digits)) >= 4 ? PhoneNumber::normalize($q) : null;

            // الكود: بادئة + رقم (t5 = T5) تطابق تام، أو رقم مجرد يطابق أي بادئة (5 → T5/CA5/P5)
            $codeExact  = preg_match('/^\s*([a-zA-Z]{1,2})\s*(\d+)\s*$/u', $digits, $m) ? strtoupper($m[1]) . (int) $m[2] : null;
            $codeNumber = preg_match('/^\s*(\d+)\s*$/u', $digits, $m2) ? (int) $m2[1] : null;

            $query->where(function ($w) use ($like, $q, $codeExact, $codeNumber, $phone) {
                $w->whereRaw(ArabicText::sqlNormalize('name') . ' LIKE ?', [$like])
                  ->orWhere('email', 'like', '%' . strtolower($q) . '%');
                if ($codeExact) {
                    $w->orWhere('display_code', $codeExact);
                }
                if ($codeNumber !== null) {
                    $w->orWhereRaw("display_code REGEXP ?", ['^[A-Z]+' . $codeNumber . '$']);
                }
                if ($phone) {
                    $w->orWhere('phone', 'like', "%{$phone}%");
                }
            });
        }

        $page = $query->paginate(20)->withQueryString();
        $page->getCollection()->transform(fn (User $u) => [
            'id'           => $u->id,
            'name'         => $u->name,
            'display_code' => $u->display_code,
            'email'        => $u->email,
            'phone'        => $u->phone,
            'role'         => $u->role,
            'center_id'    => $u->center_id,
            'center_name'  => $u->center?->name,
            'is_active'    => (bool) $u->is_active,
            'created_at'   => $u->created_at,
        ]);

        return response()->json(['success' => true, 'data' => $page]);
    }
}
