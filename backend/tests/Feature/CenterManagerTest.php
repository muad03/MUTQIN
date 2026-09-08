<?php

namespace Tests\Feature;

use App\Models\StudentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * «مدير المركز» (center_manager): مصفوفة الأدوار، تضييق النطاق بمركزه،
 * مدير واحد لكل مركز، واعتماد الطلبات الواردة إلى مركزه (مدير النظام ليس طرفاً).
 */
class CenterManagerTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    // ===== مصفوفة الأدوار الرباعية =====

    public function test_manager_routes_matrix(): void
    {
        $admin   = $this->makeAdmin();
        $teacher = $this->makeTeacher();
        $parent  = $this->makeParent();
        $manager = $this->makeManager();

        $tokens = [
            'admin'   => $this->loginToken($admin),
            'teacher' => $this->loginToken($teacher),
            'parent'  => $this->loginToken($parent),
            'manager' => $this->loginToken($manager),
        ];

        // مسارات مدير المركز: له 200، ولغيره 403
        foreach (['/api/manager/dashboard', '/api/manager/teachers', '/api/manager/students', '/api/manager/student-requests'] as $route) {
            $this->authed($tokens['manager'])->getJson($route)->assertOk();
            foreach (['admin', 'teacher', 'parent'] as $other) {
                $this->authed($tokens[$other])->getJson($route)->assertStatus(403, "فشل: {$other} على {$route}");
            }
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
            $this->getJson($route)->assertStatus(401);
        }

        // مدير المركز على مسارات غيره → 403
        foreach (['/api/teachers', '/api/students', '/api/parent/children', '/api/admin/managers'] as $route) {
            $this->authed($tokens['manager'])->getJson($route)->assertStatus(403, "فشل: manager على {$route}");
        }
    }

    public function test_manager_token_cannot_escalate_even_if_role_tampered(): void
    {
        $manager = $this->makeManager();
        $token   = $this->loginToken($manager);

        $manager->update(['role' => 'admin']); // عبث بالدور بعد إصدار التوكن

        // قدرة التوكن 'manager' فقط — لا تبلغ مسارات مدير النظام ('*')
        $this->authed($token)->getJson('/api/teachers')->assertStatus(403);
    }

    // ===== تضييق النطاق بالمركز =====

    public function test_manager_scope_is_his_center_only(): void
    {
        $centerA = $this->makeCenter(['name' => 'مركز الفرقان']);
        $centerB = $this->makeCenter(['name' => 'مركز الصحابة']);
        $managerA = $this->makeManager($centerA);
        $teacherA = $this->makeTeacher($centerA);
        $teacherB = $this->makeTeacher($centerB);
        $this->makeStudent($teacherA);
        $this->makeStudent($teacherB);

        $token = $this->loginToken($managerA);

        // قائمة محفّظيه: محفّظو مركزه فقط
        $r = $this->authed($token)->getJson('/api/manager/teachers?all=1');
        $this->assertSame([$teacherA->id], collect($r->json('data'))->pluck('id')->all());

        // قائمة طلابه: طلاب مركزه فقط
        $r = $this->authed($token)->getJson('/api/manager/students');
        foreach ($r->json('data.data') as $s) {
            $this->assertSame($centerA->id, $s['center_id'], 'ظهر طالب من مركز آخر!');
        }

        // محفّظ مركز آخر: عرض/تعديل → 404
        $this->authed($token)->getJson('/api/manager/teachers/' . $teacherB->id)->assertStatus(404);
        $this->authed($token)->putJson('/api/manager/teachers/' . $teacherB->id, [
            'name' => 'اختراق', 'email' => $teacherB->email, 'type' => 'محفظ معاون',
        ])->assertStatus(404);

        // محفّظ مركزه: التعديل يعمل
        $this->authed($token)->putJson('/api/manager/teachers/' . $teacherA->id, [
            'name' => 'اسم محدث', 'email' => $teacherA->email, 'type' => 'محفظ معاون',
        ])->assertOk();
    }

    public function test_manager_approves_only_requests_targeting_his_center(): void
    {
        $centerA = $this->makeCenter();
        $centerB = $this->makeCenter();
        $managerA = $this->makeManager($centerA);
        $managerB = $this->makeManager($centerB);
        $tA = $this->makeTeacher($centerA);
        $tB = $this->makeTeacher($centerB);

        // صف إضافة قديم وارد إلى A، وآخر إلى B (لا يخص مدير A)
        $incoming = $this->makeLegacyAddRequest($tA, ['student_name' => 'طالب وارد']);
        $foreign  = $this->makeLegacyAddRequest($tB, ['student_name' => 'طالب مركز آخر']);

        $token = $this->loginToken($managerA);

        // قائمة المدير: الوارد إلى مركزه فقط
        $ids = collect($this->authed($token)->getJson('/api/manager/student-requests')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($incoming->id));
        $this->assertFalse($ids->contains($foreign->id));

        // طلب مركز آخر → 403؛ الوارد → اعتماد يُنشئ الطالب لدى المحفّظ
        $this->authed($token)->postJson("/api/manager/student-requests/{$foreign->id}/approve")
            ->assertStatus(403);
        $this->authed($token)->postJson("/api/manager/student-requests/{$incoming->id}/approve")
            ->assertOk();
        $this->assertDatabaseHas('students', ['name' => 'طالب وارد', 'teacher_id' => $tA->id, 'center_id' => $centerA->id]);
        $this->assertSame(0, $managerB->notifications()->where('data->type', 'request_approved')->count());
    }

    // ===== مدير واحد لكل مركز + تعطيل المدير =====

    public function test_one_manager_per_center_max(): void
    {
        $admin  = $this->makeAdmin();
        $center = $this->makeCenter();
        $this->makeManager($center);
        $token = $this->loginToken($admin);

        $this->authed($token)->postJson('/api/admin/managers', [
            'name' => 'مدير ثانٍ', 'email_prefix' => 'second.manager',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'center_id' => $center->id,
        ])->assertStatus(422)
          ->assertJsonPath('errors.center_id.0', 'لهذا المركز مدير بالفعل — مدير واحد لكل مركز كحدّ أقصى');
    }

    public function test_manager_creation_builds_scheme_email_and_requires_password(): void
    {
        $admin  = $this->makeAdmin();
        $center = $this->makeCenter();
        $token  = $this->loginToken($admin);

        // بلا كلمة مرور → 422 (لا توليد صامت)
        $this->authed($token)->postJson('/api/admin/managers', [
            'name' => 'مدير بلا كلمة', 'email_prefix' => 'no.pass', 'center_id' => $center->id,
        ])->assertStatus(422);

        // إنشاء سليم → البريد بمخطط {prefix}.centeradmin@mutqin.ly
        $r = $this->authed($token)->postJson('/api/admin/managers', [
            'name' => 'معاذ', 'email_prefix' => 'muad',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'center_id' => $center->id,
        ])->assertStatus(201);
        $this->assertSame('muad.centeradmin@mutqin.ly', $r->json('data.email'));

        // نفس الاسم اللاتيني لمركز آخر → 422 برسالة واضحة (البريد محجوز)
        $center2 = $this->makeCenter();
        $this->authed($token)->postJson('/api/admin/managers', [
            'name' => 'معاذ آخر', 'email_prefix' => 'muad',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'center_id' => $center2->id,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['email_prefix']);
    }

    public function test_transfer_request_notifies_target_manager_only_and_never_admin(): void
    {
        $admin = $this->makeAdmin();
        $centerA = $this->makeCenter();
        $centerB = $this->makeCenter();
        $managerA = $this->makeManager($centerA);
        $managerB = $this->makeManager($centerB);
        $tA = $this->makeTeacher($centerA);
        $s = $this->makeStudent($tA);

        $this->authed($this->loginToken($managerA))->postJson('/api/manager/student-requests', [
            'student_id' => $s->id, 'target_center_id' => $centerB->id,
        ])->assertStatus(201);

        $this->assertSame(1, $managerB->notifications()->count(), 'مدير المركز المستهدف لم يُشعَر');
        $this->assertSame(0, $managerA->notifications()->count());
        $this->assertSame(0, $admin->notifications()->count(), 'مدير النظام أُشعر رغم أنه ليس طرفاً');
        $this->assertSame(0, $tA->notifications()->count());
    }

    /** بديل الحذف: التعطيل — الطلبات الواردة المعلّقة تبقى معلّقة (لا تؤول لمدير النظام) حتى تفعيل/تعيين مدير. */
    public function test_deactivating_manager_keeps_pending_requests_and_only_informs_admin(): void
    {
        $admin   = $this->makeAdmin();
        $center  = $this->makeCenter(['name' => 'مركز الاختبار']);
        $manager = $this->makeManager($center);
        $t = $this->makeTeacher($center);

        // طلب وارد معلّق (صف إضافة قديم)
        $this->makeLegacyAddRequest($t, ['student_name' => 'طالب معلّق']);

        $adminNotifsBefore = $admin->notifications()->count();
        $this->authed($this->loginToken($admin))
            ->putJson('/api/admin/managers/' . $manager->id . '/status', ['is_active' => false])
            ->assertOk()->assertJsonPath('data.pending_requests', 1);

        // مدير النظام يُبلَّغ إدارياً فقط (رابط صفحة المديرين لا الطلبات) والطلب يبقى معلّقاً
        $this->assertSame($adminNotifsBefore + 1, $admin->notifications()->count());
        $latest = $admin->notifications()->latest()->first();
        $this->assertSame('manager_deactivated', $latest->data['type']);
        $this->assertSame('admin/managers.html', $latest->data['link']);

        $req = StudentRequest::where('student_name', 'طالب معلّق')->first();
        $this->assertSame('pending', $req->status);

        // إعادة التفعيل: المدير يعتمده عادياً
        $this->authed($this->loginToken($admin))
            ->putJson('/api/admin/managers/' . $manager->id . '/status', ['is_active' => true])->assertOk();
        $this->authed($this->loginToken($manager))
            ->postJson("/api/manager/student-requests/{$req->id}/approve")->assertOk();
    }
}
