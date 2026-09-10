<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * تغيير محفّظ الطالب من مدير المركز — PUT /api/manager/students/{id}/teacher:
 * تغيير من محفّظ لآخر · NULL صريحة (بدون محفّظ) · طالب خارج مركزه 403 ·
 * محفّظ من مركز آخر أو موقوف 422 · المحفّظ نفسه لا يغيّر teacher_id عبر PUT /students (403).
 */
class ManagerChangeTeacherTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_manager_moves_student_to_another_active_teacher_of_own_center(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $old     = $this->makeTeacher($center, ['name' => 'المحفّظ القديم']);
        $new     = $this->makeTeacher($center, ['name' => 'المحفّظ الجديد']);
        $student = $this->makeStudent($old);

        $this->authed($this->loginToken($manager))
            ->putJson("/api/manager/students/{$student->id}/teacher", ['teacher_id' => $new->id])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.teacher_id', $new->id)
            ->assertJsonPath('data.teacher.name', 'المحفّظ الجديد');

        $fresh = $student->fresh();
        $this->assertSame($new->id, $fresh->teacher_id);
        $this->assertSame('المحفّظ القديم', $fresh->former_teacher_name); // التاريخ محفوظ للعرض
    }

    public function test_null_unassigns_student_even_when_inactive_and_never_auto_assigns(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center, ['name' => 'محفّظ سابق']);
        $this->makeTeacher($center); // محفّظ آخر متاح — يجب ألا يُسند إليه تلقائياً
        $student = $this->makeStudent($teacher, null, ['is_active' => false]); // طالب موقوف: التغيير مسموح

        $this->authed($this->loginToken($manager))
            ->putJson("/api/manager/students/{$student->id}/teacher", ['teacher_id' => null])
            ->assertOk()
            ->assertJsonPath('data.teacher_id', null)
            ->assertJsonPath('data.teacher', null)
            ->assertJsonPath('data.former_teacher_name', 'محفّظ سابق');

        $fresh = $student->fresh();
        $this->assertNull($fresh->teacher_id);
        $this->assertFalse($fresh->is_active); // الحالة لم تُمسّ
        $this->assertSame('محفّظ سابق', $fresh->former_teacher_name);
    }

    public function test_missing_teacher_id_field_is_rejected_null_must_be_explicit(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center);
        $student = $this->makeStudent($teacher);

        $this->authed($this->loginToken($manager))
            ->putJson("/api/manager/students/{$student->id}/teacher", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['teacher_id']);

        $this->assertSame($teacher->id, $student->fresh()->teacher_id);
    }

    public function test_student_of_another_center_is_forbidden(): void
    {
        $centerA = $this->makeCenter(['name' => 'مركز أ']);
        $centerB = $this->makeCenter(['name' => 'مركز ب']);
        $managerA = $this->makeManager($centerA);
        $teacherA = $this->makeTeacher($centerA);
        $teacherB = $this->makeTeacher($centerB);
        $studentB = $this->makeStudent($teacherB);

        $r = $this->authed($this->loginToken($managerA))
            ->putJson("/api/manager/students/{$studentB->id}/teacher", ['teacher_id' => $teacherA->id])
            ->assertForbidden();
        $this->assertStringContainsString('مركزك', $r->json('message'));

        $this->assertSame($teacherB->id, $studentB->fresh()->teacher_id); // لم يتغيّر
    }

    public function test_teacher_from_another_center_or_inactive_is_rejected_422(): void
    {
        $centerA = $this->makeCenter(['name' => 'مركز أ']);
        $centerB = $this->makeCenter(['name' => 'مركز ب']);
        $manager  = $this->makeManager($centerA);
        $teacherA = $this->makeTeacher($centerA);
        $teacherB = $this->makeTeacher($centerB);
        $inactive = $this->makeTeacher($centerA, ['is_active' => false]);
        $student  = $this->makeStudent($teacherA);
        $token    = $this->loginToken($manager);

        foreach ([$teacherB->id, $inactive->id, 999999] as $bad) {
            $r = $this->authed($token)
                ->putJson("/api/manager/students/{$student->id}/teacher", ['teacher_id' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('success', false);
            $this->assertStringContainsString('مركزك', $r->json('errors.teacher_id.0'));
        }

        $this->assertSame($teacherA->id, $student->fresh()->teacher_id);
    }

    public function test_teacher_sending_teacher_id_on_student_update_gets_explicit_403(): void
    {
        $center  = $this->makeCenter();
        $teacher = $this->makeTeacher($center);
        $other   = $this->makeTeacher($center);
        $student = $this->makeStudent($teacher);
        $token   = $this->loginToken($teacher);

        // كان يُسقَط بصمت — الآن رفض صريح، ولا تغيير على الطالب
        $r = $this->authed($token)
            ->putJson("/api/students/{$student->id}", ['name' => 'اسم جديد', 'teacher_id' => $other->id])
            ->assertForbidden();
        $this->assertStringContainsString('مدير المركز', $r->json('message'));
        $this->assertSame($teacher->id, $student->fresh()->teacher_id);
        $this->assertNotSame('اسم جديد', $student->fresh()->name);

        // بلا teacher_id يبقى التعديل العادي متاحاً كما كان
        $this->authed($token)
            ->putJson("/api/students/{$student->id}", ['name' => 'اسم جديد'])
            ->assertOk();
        $this->assertSame('اسم جديد', $student->fresh()->name);
    }
}
