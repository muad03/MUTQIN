<?php

namespace Tests\Feature;

use App\Models\StudentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * الفصل بين مدير النظام (admin) ومدير المركز (center_manager) في طلبات النقل بين المراكز:
 * مدير المركز المصدر (A) يُنشئ الطلب → مدير المركز المستهدف (B) وحده يستلمه ويعتمده/يرفضه.
 * مدير النظام ليس طرفاً، ومدير مركز ثالث لا يرى شيئاً، والمحفّظ لا يُنشئ ولا يدير طلبات نقل.
 */
class StudentTransferRequestTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    /** مركزان بمديرين ومحفّظ وطالب في A — الوضع القياسي لكل الاختبارات. */
    protected function scenario(): array
    {
        $admin    = $this->makeAdmin();
        $centerA  = $this->makeCenter(['name' => 'مركز أ']);
        $centerB  = $this->makeCenter(['name' => 'مركز ب']);
        $managerA = $this->makeManager($centerA);
        $managerB = $this->makeManager($centerB);
        $teacherA = $this->makeTeacher($centerA);
        $teacherB = $this->makeTeacher($centerB);
        $student  = $this->makeStudent($teacherA, null, ['national_id' => '155500000010']);

        return compact('admin', 'centerA', 'centerB', 'managerA', 'managerB', 'teacherA', 'teacherB', 'student');
    }

    protected function createTransfer(array $s, array $extra = [])
    {
        return $this->authed($this->loginToken($s['managerA']))->postJson('/api/manager/student-requests', array_merge([
            'student_id'       => $s['student']->id,
            'target_center_id' => $s['centerB']->id,
        ], $extra));
    }

    // 1 + 2 + 3 + 9: المصدر يُنشئ، المستهدف يستلم (قائمة + إشعار)، مدير النظام لا يستلم، ولا رابط admin/requests.html
    public function test_source_manager_creates_and_only_target_manager_is_notified(): void
    {
        $s = $this->scenario();

        $this->createTransfer($s)->assertStatus(201)
            ->assertJsonPath('data.request.type', 'transfer')
            ->assertJsonPath('data.request.direction', 'outgoing');

        $req = StudentRequest::latest('id')->first();
        $this->assertSame($s['centerA']->id, $req->from_center_id);
        $this->assertSame($s['centerB']->id, $req->target_center_id);
        $this->assertSame($s['managerA']->id, $req->requested_by);
        $this->assertNull($req->target_teacher_id, 'محفّظ الوجهة يُعيَّن عند الاعتماد لا عند الإنشاء');

        // المستلم الوحيد: مدير المركز المستهدف
        $this->assertSame(1, $s['managerB']->notifications()->count(), 'مدير المركز المستهدف لم يُشعَر');
        $notif = $s['managerB']->notifications()->first();
        $this->assertSame('request_created', $notif->data['type']);
        $this->assertSame('manager/requests.html', $notif->data['link']);

        $this->assertSame(0, $s['admin']->notifications()->count(), 'مدير النظام أُشعر بطلب نقل بين المراكز');
        $this->assertSame(0, $s['managerA']->notifications()->count());
        $this->assertSame(0, $s['teacherA']->notifications()->count());
        $this->assertSame(0, $s['teacherB']->notifications()->count());

        // لا إشعار في النظام كله يحمل رابط صفحة الأدمن المحذوفة
        $this->assertSame(0, DatabaseNotification::where('data->link', 'admin/requests.html')->count());

        // قائمة المستهدف: الطلب وارد؛ قائمة المصدر: الطلب صادر
        $inB = collect($this->authed($this->loginToken($s['managerB']))->getJson('/api/manager/student-requests')->json('data'));
        $this->assertSame('incoming', $inB->firstWhere('id', $req->id)['direction']);
        $inA = collect($this->authed($this->loginToken($s['managerA']))->getJson('/api/manager/student-requests')->json('data'));
        $this->assertSame('outgoing', $inA->firstWhere('id', $req->id)['direction']);
    }

    // 4: مدير مركز ثالث لا يرى الطلب ولا يعتمده
    public function test_third_center_manager_cannot_see_or_act_on_the_request(): void
    {
        $s = $this->scenario();
        $managerC = $this->makeManager($this->makeCenter(['name' => 'مركز ج']));
        $this->createTransfer($s)->assertStatus(201);
        $req = StudentRequest::latest('id')->first();

        $tokenC = $this->loginToken($managerC);
        $ids = collect($this->authed($tokenC)->getJson('/api/manager/student-requests?status=all')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($req->id), 'مدير مركز ثالث رأى طلباً لا يخصه');

        $this->authed($tokenC)->postJson("/api/manager/student-requests/{$req->id}/approve")->assertStatus(403);
        $this->authed($tokenC)->postJson("/api/manager/student-requests/{$req->id}/reject")->assertStatus(403);
        $this->assertSame('pending', $req->fresh()->status);

        // ومدير المركز المصدر نفسه لا يعتمد طلبه الصادر
        $this->authed($this->loginToken($s['managerA']))
            ->postJson("/api/manager/student-requests/{$req->id}/approve")->assertStatus(403);
    }

    // 5: المستهدف يعتمد → الطالب يُنقل إلى مركز B (مع تعيين محفّظ من B) وصاحب الطلب يُشعَر
    public function test_target_manager_approves_and_student_moves(): void
    {
        $s = $this->scenario();
        $this->createTransfer($s)->assertStatus(201);
        $req = StudentRequest::latest('id')->first();

        $this->authed($this->loginToken($s['managerB']))
            ->postJson("/api/manager/student-requests/{$req->id}/approve", ['target_teacher_id' => $s['teacherB']->id])
            ->assertOk();

        $student = $s['student']->fresh();
        $this->assertSame($s['centerB']->id, $student->center_id, 'الطالب لم يُنقل إلى المركز المستهدف');
        $this->assertSame($s['teacherB']->id, $student->teacher_id);
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertSame($s['teacherB']->id, $req->fresh()->target_teacher_id);

        $types = $s['managerA']->notifications()->get()->pluck('data.type');
        $this->assertTrue($types->contains('request_approved'), 'مدير المركز المصدر لم يُشعَر بالموافقة');
        $this->assertSame('manager/requests.html', $s['managerA']->notifications()->first()->data['link']);
        $this->assertSame(0, $s['admin']->notifications()->count());
    }

    public function test_approve_without_teacher_moves_student_unassigned_and_rejects_foreign_teacher(): void
    {
        $s = $this->scenario();
        $this->createTransfer($s)->assertStatus(201);
        $req = StudentRequest::latest('id')->first();
        $tokenB = $this->loginToken($s['managerB']);

        // محفّظ من مركز آخر → 422 ولا نقل
        $this->authed($tokenB)->postJson("/api/manager/student-requests/{$req->id}/approve", ['target_teacher_id' => $s['teacherA']->id])
            ->assertStatus(422);
        $this->assertSame($s['centerA']->id, $s['student']->fresh()->center_id);

        // بلا محفّظ → يُنقل بلا محفّظ ويحتفظ باسم محفّظه السابق
        $this->authed($tokenB)->postJson("/api/manager/student-requests/{$req->id}/approve")->assertOk();
        $student = $s['student']->fresh();
        $this->assertSame($s['centerB']->id, $student->center_id);
        $this->assertNull($student->teacher_id);
        $this->assertSame($s['teacherA']->name, $student->former_teacher_name);
    }

    // 6: المستهدف يرفض → لا تغيير على الطالب وصاحب الطلب يُشعَر بالسبب
    public function test_target_manager_rejects_with_reason(): void
    {
        $s = $this->scenario();
        $this->createTransfer($s)->assertStatus(201);
        $req = StudentRequest::latest('id')->first();

        $this->authed($this->loginToken($s['managerB']))
            ->postJson("/api/manager/student-requests/{$req->id}/reject", ['admin_note' => 'المركز مكتمل العدد'])
            ->assertOk();

        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertSame($s['centerA']->id, $s['student']->fresh()->center_id);
        $notif = $s['managerA']->notifications()->get()->firstWhere('data.type', 'request_rejected');
        $this->assertNotNull($notif);
        $this->assertStringContainsString('المركز مكتمل العدد', $notif->data['body']);
    }

    // 7: المحفّظ لا يُنشئ ولا يدير طلبات نقل — ومدير النظام ليس طرفاً (المسارات القديمة زالت)
    public function test_teacher_and_admin_cannot_create_or_manage_transfers(): void
    {
        $s = $this->scenario();
        $tokenT = $this->loginToken($s['teacherB']);

        // المحفّظ: لا مسار طلبات له إطلاقاً (404) ولا صف
        $this->authed($tokenT)->postJson('/api/student-requests', ['type' => 'transfer', 'student_id' => $s['student']->id])
            ->assertStatus(404);
        $this->assertSame(0, StudentRequest::count());
        // ومسار مدير المركز محجوب عنه بالحارس
        $this->authed($tokenT)->postJson('/api/manager/student-requests', [
            'student_id' => $s['student']->id, 'target_center_id' => $s['centerB']->id,
        ])->assertStatus(403);

        $this->createTransfer($s)->assertStatus(201);
        $req = StudentRequest::latest('id')->first();
        $this->authed($tokenT)->postJson("/api/manager/student-requests/{$req->id}/approve")->assertStatus(403);
        $this->authed($tokenT)->getJson('/api/manager/student-requests')->assertStatus(403);

        // مدير النظام: لا مسار له للطلبات إطلاقاً (404) ومسارات مدير المركز محجوبة (403)
        $tokenA = $this->loginToken($s['admin']);
        $this->authed($tokenA)->getJson('/api/admin/student-requests')->assertStatus(404);
        $this->authed($tokenA)->postJson("/api/admin/student-requests/{$req->id}/approve")->assertStatus(404);
        $this->authed($tokenA)->postJson("/api/manager/student-requests/{$req->id}/approve")->assertStatus(403);
        $this->assertSame('pending', $req->fresh()->status);
    }

    // نطاق الإنشاء: طالب من مركز آخر / مركز مستهدف = مركزه / بلا مدير نشط / تكرار
    public function test_create_scope_and_validation(): void
    {
        $s = $this->scenario();
        $tokenA = $this->loginToken($s['managerA']);
        $studentB = $this->makeStudent($s['teacherB']);

        // طالب من مركز آخر → 403
        $this->authed($tokenA)->postJson('/api/manager/student-requests', [
            'student_id' => $studentB->id, 'target_center_id' => $s['centerA']->id,
        ])->assertStatus(403);

        // المستهدف = مركزه → 422
        $this->authed($tokenA)->postJson('/api/manager/student-requests', [
            'student_id' => $s['student']->id, 'target_center_id' => $s['centerA']->id,
        ])->assertStatus(422);

        // مركز بلا مدير نشط → 422 (مدير النظام ليس بديلاً)
        $centerC = $this->makeCenter(['name' => 'مركز ج']);
        $this->authed($tokenA)->postJson('/api/manager/student-requests', [
            'student_id' => $s['student']->id, 'target_center_id' => $centerC->id,
        ])->assertStatus(422);
        $this->assertSame(0, $s['admin']->notifications()->count());

        // صحيح ثم تكرار لنفس الطالب → 422
        $this->createTransfer($s)->assertStatus(201);
        $this->createTransfer($s)->assertStatus(422);
        $this->assertSame(1, StudentRequest::count());

        // قائمة المراكز المستهدفة: بلا مركزه
        $ids = collect($this->authed($tokenA)->getJson('/api/manager/centers')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($s['centerA']->id));
        $this->assertTrue($ids->contains($s['centerB']->id));
    }
}
