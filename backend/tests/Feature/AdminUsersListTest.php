<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * «جميع المستخدمين» للأدمن — GET /api/admin/users (عرض فقط):
 * غير الأدمن → 403 · فلتر الدور والحالة (النشطون افتراضياً) · لا كلمة مرور ولا
 * remember_token في الاستجابة · بحث بالاسم/الكود/الهاتف/البريد · المركز بلا N+1.
 */
class AdminUsersListTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_non_admin_roles_are_forbidden(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center);

        foreach ([$manager, $teacher, $this->makeParent()] as $u) {
            $this->authed($this->loginToken($u))->getJson('/api/admin/users')->assertForbidden();
        }
    }

    public function test_role_and_status_filters_and_no_sensitive_fields(): void
    {
        $center  = $this->makeCenter(['name' => 'مركز الفرقان']);
        $admin   = $this->makeAdmin();
        $manager = $this->makeManager($center);
        $t1      = $this->makeTeacher($center);
        $t2      = $this->makeTeacher($center, ['is_active' => false]);
        $parent  = $this->makeParent();
        $token   = $this->loginToken($admin);

        // الافتراضي: النشطون فقط (الأدمن + المدير + محفّظ + ولي أمر = 4)
        $r = $this->authed($token)->getJson('/api/admin/users')->assertOk();
        $this->assertSame(4, $r->json('data.total'));
        $this->assertSame(20, $r->json('data.per_page'));

        // لا حقول حسّاسة في أي صف
        foreach ($r->json('data.data') as $row) {
            $this->assertArrayNotHasKey('password', $row);
            $this->assertArrayNotHasKey('remember_token', $row);
            $this->assertArrayNotHasKey('id_number', $row);
            $this->assertSame(['id', 'name', 'display_code', 'email', 'phone', 'role', 'center_id', 'center_name', 'is_active', 'created_at'], array_keys($row));
        }
        $this->assertStringNotContainsString('$2y$', $r->getContent()); // لا هاش كلمة مرور

        // الأدمن بلا كود ولا مركز
        $adminRow = collect($r->json('data.data'))->firstWhere('role', 'admin');
        $this->assertNull($adminRow['display_code']);
        $this->assertNull($adminRow['center_name']);

        // المحفّظ بمركزه (تحميل مسبق)
        $teacherRow = collect($r->json('data.data'))->firstWhere('id', $t1->id);
        $this->assertSame('مركز الفرقان', $teacherRow['center_name']);
        $this->assertSame('T1', $teacherRow['display_code']);

        // فلتر الدور
        $this->assertSame(1, $this->authed($token)->getJson('/api/admin/users?role=teacher')->json('data.total'));
        $this->assertSame(2, $this->authed($token)->getJson('/api/admin/users?role=teacher&status=all')->json('data.total'));
        $this->assertSame($t2->id, $this->authed($token)->getJson('/api/admin/users?role=teacher&status=inactive')->json('data.data.0.id'));
        $this->assertSame(1, $this->authed($token)->getJson('/api/admin/users?role=parent')->json('data.total'));
        $this->assertSame(4, $this->authed($token)->getJson('/api/admin/users?role=hacker')->json('data.total')); // دور غير معروف → يُتجاهل
        $this->assertSame(5, $this->authed($token)->getJson('/api/admin/users?status=all')->json('data.total'));
    }

    public function test_search_by_name_code_phone_and_email(): void
    {
        $center = $this->makeCenter();
        $admin  = $this->makeAdmin();
        $t      = $this->makeTeacher($center, ['name' => 'أحمد الفيتوري', 'phone' => '0912345678', 'email' => 'ahmed_t1@mutqin.ly']); // T1
        $this->makeManager($center, ['name' => 'سالم']); // CA1
        $token  = $this->loginToken($admin);

        foreach (['احمد', 't1', 'T1', '+218 91 234 5678', 'ahmed_t1'] as $q) {
            $res = $this->authed($token)->getJson('/api/admin/users?q=' . urlencode($q));
            $this->assertSame(1, $res->json('data.total'), "q={$q}");
            $this->assertSame($t->id, $res->json('data.data.0.id'), "q={$q}");
        }
        // الرقم المجرد يطابق أي بادئة: 1 → T1 وCA1
        $this->assertSame(2, $this->authed($token)->getJson('/api/admin/users?q=1')->json('data.total'));
        $this->assertSame(0, $this->authed($token)->getJson('/api/admin/users?q=' . urlencode('لا أحد'))->json('data.total'));
    }
}
