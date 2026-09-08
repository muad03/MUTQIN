<?php

namespace Tests\Feature;

use App\Models\StudentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * تفعيل/تعطيل مدير المركز بديلاً عن الحذف: المعطَّل لا يدخل، توكناته تُبطَل
 * فوراً، التبديل لمدير النظام حصراً، ولا يُوجَّه إليه طلب جديد — الطلبات
 * الواردة المعلّقة تبقى معلّقة (مدير النظام ليس طرفاً) حتى تعيين مدير نشط.
 */
class ManagerStatusTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_inactive_manager_cannot_log_in(): void
    {
        $manager = $this->makeManager(null, ['is_active' => false]);

        $this->postJson('/api/auth/login', ['email' => $manager->email, 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'هذا الحساب غير نشط، راجع إدارة المركز');

        // النشط يدخل عادياً
        $active = $this->makeManager();
        $this->postJson('/api/auth/login', ['email' => $active->email, 'password' => 'password'])->assertOk();
    }

    public function test_deactivation_revokes_tokens_and_records_audit(): void
    {
        $admin   = $this->makeAdmin();
        $manager = $this->makeManager();
        $mToken  = $this->loginToken($manager);

        // التوكن يعمل قبل التعطيل
        $this->authed($mToken)->getJson('/api/manager/dashboard')->assertOk();
        $this->assertSame(1, $manager->tokens()->count());

        $this->flushHeaders();
        $this->authed($this->loginToken($admin))
            ->putJson("/api/admin/managers/{$manager->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.pending_requests', 0);

        // الجلسات أُنهيت فوراً — لا انتظار للدخول التالي
        $this->assertSame(0, $manager->tokens()->count());
        $this->flushHeaders();
        $this->authed($mToken)->getJson('/api/manager/dashboard')->assertUnauthorized();

        $fresh = $manager->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame($admin->id, $fresh->status_changed_by);
        $this->assertNotNull($fresh->status_changed_at);

        // إعادة التفعيل تُعيد الدخول
        $this->flushHeaders();
        $this->authed($this->loginToken($admin))
            ->putJson("/api/admin/managers/{$manager->id}/status", ['is_active' => true])->assertOk();
        $this->flushHeaders();
        $this->postJson('/api/auth/login', ['email' => $manager->email, 'password' => 'password'])->assertOk();
    }

    public function test_only_admin_can_toggle_manager_status(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center);

        // مدير المركز لا يبدّل حالة نفسه
        $this->authed($this->loginToken($manager))
            ->putJson("/api/admin/managers/{$manager->id}/status", ['is_active' => false])->assertForbidden();

        // المحفّظ ممنوع كذلك
        $this->flushHeaders();
        $this->authed($this->loginToken($teacher))
            ->putJson("/api/admin/managers/{$manager->id}/status", ['is_active' => false])->assertForbidden();

        $this->assertTrue($manager->fresh()->is_active);
    }

    public function test_manager_deletion_route_is_gone(): void
    {
        $admin   = $this->makeAdmin();
        $manager = $this->makeManager();

        $this->authed($this->loginToken($admin))
            ->deleteJson("/api/admin/managers/{$manager->id}")->assertStatus(405);

        $this->assertDatabaseHas('users', ['id' => $manager->id]); // باقٍ بكل ارتباطاته
    }

    public function test_pending_requests_are_not_routed_to_admin_while_manager_is_inactive(): void
    {
        $admin   = $this->makeAdmin();
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center);
        $this->makeLegacyAddRequest($teacher); // طلب وارد معلّق

        // التعطيل يبلّغ مدير النظام إدارياً بالمعلّق الذي ينتظر مديراً (لا يؤول إليه)
        $r = $this->authed($this->loginToken($admin))
            ->putJson("/api/admin/managers/{$manager->id}/status", ['is_active' => false])->assertOk();
        $this->assertSame(1, $r->json('data.pending_requests'));
        $this->assertStringContainsString('يبقى 1 طلب وارد معلّق', $r->json('message'));
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame('admin/managers.html', $admin->notifications()->first()->data['link']);
        $this->assertSame(0, $manager->notifications()->count());

        // وبعده: لا يُقبل طلب نقل جديد إلى مركزه (لا مدير نشط) ولا يؤول لمدير النظام
        $other = $this->makeCenter();
        $managerO = $this->makeManager($other);
        $sO = $this->makeStudent($this->makeTeacher($other));
        $this->flushHeaders();
        $this->authed($this->loginToken($managerO))->postJson('/api/manager/student-requests', [
            'student_id' => $sO->id, 'target_center_id' => $center->id,
        ])->assertStatus(422);

        $this->assertSame(0, $manager->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());   // التنبيه الإداري فقط
        $this->assertSame(1, StudentRequest::where('status', 'pending')->count());
    }
}
