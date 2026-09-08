<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * مسارات المحفّظ للطلبات أُلغيت من الأساس (لا «طلباتي» ولا إنشاء)، ومدير النظام ليس طرفاً.
 * صفوف الإضافة القديمة المعلّقة يصفّيها مدير مركزها: اعتماد → إنشاء الطالب + إشعار صاحب الطلب؛ رفض → إشعار بالسبب.
 */
class StudentRequestNotificationTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_teacher_request_routes_are_gone(): void
    {
        $teacher = $this->makeTeacher();
        $token = $this->loginToken($teacher);

        $this->authed($token)->getJson('/api/student-requests')->assertStatus(404);
        $this->authed($token)->postJson('/api/student-requests', [
            'type' => 'add', 'name' => 'طالب', 'national_id' => '155500000001',
        ])->assertStatus(404);
        $this->authed($token)->postJson('/api/student-requests', ['type' => 'transfer', 'student_id' => 1])->assertStatus(404);
        $this->assertSame(0, \App\Models\StudentRequest::count());
    }

    public function test_approve_legacy_add_creates_student_and_notifies_requester(): void
    {
        $admin   = $this->makeAdmin();
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center);
        $req = $this->makeLegacyAddRequest($teacher, ['student_name' => 'طالب للموافقة']);

        $this->authed($this->loginToken($manager))
            ->postJson("/api/manager/student-requests/{$req->id}/approve")
            ->assertOk();

        // الطالب أُنشئ لدى المحفّظ صاحب الطلب
        $this->assertDatabaseHas('students', [
            'name' => 'طالب للموافقة', 'teacher_id' => $teacher->id, 'center_id' => $teacher->center_id,
        ]);
        // وصاحب الطلب أُشعر بالموافقة برابط «طلابي» (لا صفحة طلبات له)
        $notif = $teacher->notifications()->get()->firstWhere('data.type', 'request_approved');
        $this->assertNotNull($notif);
        $this->assertSame('teacher/students.html', $notif->data['link']);
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_reject_legacy_add_notifies_requester_with_reason(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center);
        $req = $this->makeLegacyAddRequest($teacher, ['student_name' => 'طالب للرفض']);

        $this->authed($this->loginToken($manager))
            ->postJson("/api/manager/student-requests/{$req->id}/reject", ['admin_note' => 'بيانات ناقصة'])
            ->assertOk();

        $notif = $teacher->notifications()->get()->firstWhere('data.type', 'request_rejected');
        $this->assertNotNull($notif, 'لم يصل إشعار الرفض');
        $this->assertStringContainsString('بيانات ناقصة', $notif->data['body']);
        // ولم يُنشأ طالب
        $this->assertDatabaseMissing('students', ['name' => 'طالب للرفض']);
    }
}
