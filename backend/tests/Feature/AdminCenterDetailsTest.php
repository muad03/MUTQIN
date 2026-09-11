<?php

namespace Tests\Feature;

use App\Models\Attendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * صفحة تفاصيل المركز (أدمن) — GET /api/centers/{id}/stats · /teachers · /students:
 * غير الأدمن (مدير مركز، محفّظ، ولي أمر) → 403 من البوابة، والأدمن يحصل على
 * إحصائيات مجمّعة صحيحة وقائمتين مرقّمتين 5/صفحة بالنشطين افتراضياً.
 */
class AdminCenterDetailsTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_non_admin_roles_are_forbidden_on_all_three_endpoints(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center);
        $parent  = $this->makeParent();

        foreach ([$manager, $teacher, $parent] as $user) {
            $token = $this->loginToken($user);
            foreach (['stats', 'teachers', 'students'] as $seg) {
                $this->authed($token)->getJson("/api/centers/{$center->id}/{$seg}")->assertForbidden();
            }
        }
    }

    public function test_admin_gets_aggregated_stats_and_active_manager(): void
    {
        $center   = $this->makeCenter();
        $manager  = $this->makeManager($center, ['name' => 'مدير نشط']);
        $this->makeManager($center, ['is_active' => false]); // معطَّل — لا يُعرض
        $t1       = $this->makeTeacher($center);
        $t2       = $this->makeTeacher($center, ['is_active' => false]);
        $s1       = $this->makeStudent($t1);
        $s2       = $this->makeStudent($t1);
        $s3       = $this->makeStudent($t1, null, ['teacher_id' => null]);          // نشط بلا محفّظ
        $s4       = $this->makeStudent($t1, null, ['is_active' => false]);          // موقوف
        $s5       = $this->makeStudent($t1, null, ['is_active' => false, 'teacher_id' => null]); // موقوف بلا محفّظ — لا يُعدّ

        // حضور هذا الشهر للنشطين: 3 حاضر + 1 غائب → 75%
        $d = today()->toDateString();
        Attendance::create(['student_id' => $s1->id, 'teacher_id' => $t1->id, 'center_id' => $center->id, 'date' => $d, 'status' => 'present']);
        Attendance::create(['student_id' => $s2->id, 'teacher_id' => $t1->id, 'center_id' => $center->id, 'date' => $d, 'status' => 'present']);
        Attendance::create(['student_id' => $s3->id, 'teacher_id' => null,    'center_id' => $center->id, 'date' => $d, 'status' => 'present']);
        Attendance::create(['student_id' => $s1->id, 'teacher_id' => $t1->id, 'center_id' => $center->id, 'date' => today()->subDay()->toDateString(), 'status' => 'absent']);
        // حضور طالب موقوف — خارج الحساب
        Attendance::create(['student_id' => $s4->id, 'teacher_id' => $t1->id, 'center_id' => $center->id, 'date' => $d, 'status' => 'absent']);

        $r = $this->authed($this->loginToken($this->makeAdmin()))
            ->getJson("/api/centers/{$center->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.center.id', $center->id)
            ->assertJsonPath('data.stats.students_active', 3)
            ->assertJsonPath('data.stats.students_inactive', 2)
            ->assertJsonPath('data.stats.students_without_teacher', 1)
            ->assertJsonPath('data.stats.teachers_active', 1)
            ->assertJsonPath('data.stats.teachers_inactive', 1)
            ->assertJsonPath('data.stats.attendance_month.total', 4)
            ->assertJsonPath('data.stats.attendance_month.present', 3)
            ->assertJsonPath('data.stats.attendance_today.present', 3)
            ->assertJsonPath('data.manager.id', $manager->id)
            ->assertJsonPath('data.manager.name', 'مدير نشط');

        // نسبة الحضور من الشهر الحالي (قد يقع الأمس في شهر سابق أول الشهر)
        $this->assertContains($r->json('data.stats.attendance_percent'), [75, 100]);
    }

    public function test_center_without_active_manager_returns_null_manager(): void
    {
        $center = $this->makeCenter();
        $this->makeManager($center, ['is_active' => false]);

        $this->authed($this->loginToken($this->makeAdmin()))
            ->getJson("/api/centers/{$center->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.manager', null);
    }

    public function test_lists_are_paginated_by_five_and_default_to_active(): void
    {
        $center  = $this->makeCenter();
        $other   = $this->makeCenter(['name' => 'مركز آخر']);
        $teacher = $this->makeTeacher($center, ['name' => 'أ محفّظ']);
        $this->makeTeacher($center, ['is_active' => false]);
        $this->makeTeacher($other); // مركز آخر — لا يظهر
        for ($i = 0; $i < 7; $i++) {
            $this->makeStudent($teacher, null, ['name' => "طالب {$i}"]);
        }
        $this->makeStudent($teacher, null, ['is_active' => false, 'name' => 'طالب موقوف']);
        $this->makeStudent($this->makeTeacher($other), null, ['name' => 'طالب مركز آخر']);
        $token = $this->loginToken($this->makeAdmin());

        // المحفّظون: النشط فقط افتراضياً، بعدد طلابه النشطين
        $t = $this->authed($token)->getJson("/api/centers/{$center->id}/teachers")->assertOk();
        $this->assertSame(1, $t->json('data.total'));
        $this->assertSame(5, $t->json('data.per_page'));
        $this->assertSame($teacher->id, $t->json('data.data.0.id'));
        $this->assertSame(7, $t->json('data.data.0.active_students_count'));
        $this->assertSame(2, $this->authed($token)->getJson("/api/centers/{$center->id}/teachers?status=all")->json('data.total'));

        // الطلاب: 7 نشطين → صفحتان (5 + 2)، الموقوف وطالب المركز الآخر خارجهما
        $p1 = $this->authed($token)->getJson("/api/centers/{$center->id}/students")->assertOk();
        $this->assertSame(7, $p1->json('data.total'));
        $this->assertSame(5, $p1->json('data.per_page'));
        $this->assertSame(2, $p1->json('data.last_page'));
        $this->assertCount(5, $p1->json('data.data'));
        $this->assertSame('أ محفّظ', $p1->json('data.data.0.teacher.name')); // المحفّظ محمَّل مسبقاً

        $p2 = $this->authed($token)->getJson("/api/centers/{$center->id}/students?page=2")->assertOk();
        $this->assertCount(2, $p2->json('data.data'));

        $this->assertSame(8, $this->authed($token)->getJson("/api/centers/{$center->id}/students?status=all")->json('data.total'));
        $this->assertSame(1, $this->authed($token)->getJson("/api/centers/{$center->id}/students?status=inactive")->json('data.total'));
    }

    public function test_unknown_center_is_404_for_admin(): void
    {
        $this->authed($this->loginToken($this->makeAdmin()))
            ->getJson('/api/centers/999999/stats')
            ->assertNotFound();
    }
}
