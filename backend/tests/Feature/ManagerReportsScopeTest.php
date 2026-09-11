<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Memorization;
use App\Models\WeeklyTest;
use App\Support\SurahReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * صفحة تقارير مدير المركز بأقسامها الثلاثة (كل السجلات):
 * GET /api/manager/reports/center · /teacher/{id} · /student/{id}
 * نطاق المركز يُفرض في الباك: محفّظ أو طالب من مركز آخر → 403 عربية،
 * وغير المدير → 403 من البوابة، والأرقام مجمّعة صحيحة على النشطين فقط.
 */
class ManagerReportsScopeTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_teacher_and_student_of_another_center_are_forbidden(): void
    {
        $centerA = $this->makeCenter(['name' => 'مركز أ']);
        $centerB = $this->makeCenter(['name' => 'مركز ب']);
        $managerA = $this->makeManager($centerA);
        $teacherB = $this->makeTeacher($centerB);
        $studentB = $this->makeStudent($teacherB, null, ['name' => 'طالب سرّي']);
        $token = $this->loginToken($managerA);

        $r1 = $this->authed($token)->getJson("/api/manager/reports/teacher/{$teacherB->id}")->assertForbidden();
        $this->assertStringContainsString('مركزك', $r1->json('message'));

        $r2 = $this->authed($token)->getJson("/api/manager/reports/student/{$studentB->id}")->assertForbidden();
        $this->assertStringContainsString('مركزك', $r2->json('message'));
        $this->assertStringNotContainsString('طالب سرّي', $r2->getContent());
    }

    public function test_non_manager_roles_are_forbidden_on_all_three(): void
    {
        $center  = $this->makeCenter();
        $teacher = $this->makeTeacher($center);
        $student = $this->makeStudent($teacher);

        foreach ([$this->makeAdmin(), $teacher, $this->makeParent()] as $u) {
            $token = $this->loginToken($u);
            $this->authed($token)->getJson('/api/manager/reports/center')->assertForbidden();
            $this->authed($token)->getJson("/api/manager/reports/teacher/{$teacher->id}")->assertForbidden();
            $this->authed($token)->getJson("/api/manager/reports/student/{$student->id}")->assertForbidden();
        }
    }

    public function test_center_report_aggregates_all_records_of_active_students_only(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center);
        $this->makeTeacher($center, ['is_active' => false]);
        $s1 = $this->makeStudent($teacher);
        $s2 = $this->makeStudent($teacher);
        $s3 = $this->makeStudent($teacher, null, ['is_active' => false]);
        $other = $this->makeStudent($this->makeTeacher($this->makeCenter(['name' => 'آخر'])));

        // سجلات عبر أشهر مختلفة — كل السجلات تُحتسب (لا مدى زمني)
        Attendance::create(['student_id' => $s1->id, 'teacher_id' => $teacher->id, 'center_id' => $center->id, 'date' => '2026-01-10', 'status' => 'present']);
        Attendance::create(['student_id' => $s1->id, 'teacher_id' => $teacher->id, 'center_id' => $center->id, 'date' => '2026-03-10', 'status' => 'absent']);
        Attendance::create(['student_id' => $s2->id, 'teacher_id' => $teacher->id, 'center_id' => $center->id, 'date' => '2025-11-10', 'status' => 'late']);
        Attendance::create(['student_id' => $s2->id, 'teacher_id' => $teacher->id, 'center_id' => $center->id, 'date' => '2025-12-10', 'status' => 'present']);
        Attendance::create(['student_id' => $s3->id, 'teacher_id' => $teacher->id, 'center_id' => $center->id, 'date' => '2026-03-11', 'status' => 'absent']); // موقوف
        Attendance::create(['student_id' => $other->id, 'teacher_id' => $other->teacher_id, 'center_id' => $other->center_id, 'date' => '2026-03-11', 'status' => 'absent']); // مركز آخر
        WeeklyTest::create(['student_id' => $s1->id, 'teacher_id' => $teacher->id, 'exam_date' => '2026-02-01', 'result' => 'ناجح']);
        WeeklyTest::create(['student_id' => $s2->id, 'teacher_id' => $teacher->id, 'exam_date' => '2025-10-01', 'result' => 'راسب']);
        WeeklyTest::create(['student_id' => $s2->id, 'teacher_id' => $teacher->id, 'exam_date' => '2025-09-01', 'result' => 'ناجح']);

        $rows = [];
        foreach (SurahReference::SURAHS as $surah => $juz) {
            $rows[] = ['student_id' => $s1->id, 'teacher_id' => $teacher->id, 'date' => '2026-01-01', 'surah_name' => $surah, 'juz' => $juz, 'quality' => 'excellent', 'created_at' => now(), 'updated_at' => now()];
        }
        Memorization::insert($rows);
        Memorization::create(['student_id' => $s2->id, 'teacher_id' => $teacher->id, 'date' => '2026-01-01', 'surah_name' => 'الناس', 'juz' => 30, 'quality' => 'weak']);

        $r = $this->authed($this->loginToken($manager))->getJson('/api/manager/reports/center')
            ->assertOk()
            ->assertJsonPath('data.students_count', 2)
            ->assertJsonPath('data.teachers_count', 1)
            ->assertJsonPath('data.attendance.total', 4)
            ->assertJsonPath('data.attendance.present', 2)
            ->assertJsonPath('data.attendance.absent', 1)
            ->assertJsonPath('data.attendance.percent', 50)
            ->assertJsonPath('data.attendance.absent_percent', 25)
            ->assertJsonPath('data.tests.total', 3)
            ->assertJsonPath('data.tests.passed', 2)
            ->assertJsonPath('data.tests.pass_percent', 67)
            ->assertJsonPath('data.memorization.khatmat', 1)
            ->assertJsonPath('data.memorization.avg_completed_juz', 15)
            ->assertJsonPath('data.memorization.quality.excellent', 114)
            ->assertJsonPath('data.memorization.quality.weak', 1);

        $this->assertArrayNotHasKey('advanced', $r->json('data'));
    }

    public function test_teacher_and_student_reports_return_expected_shape(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center, ['name' => 'الشيخ سالم']);
        $parent  = $this->makeParent(['name' => 'ولي الأمر', 'phone' => '0919999999']);
        $student = $this->makeStudent($teacher, $parent, ['name' => 'أحمد']);
        $this->makeStudent($teacher, null, ['is_active' => false]);
        Attendance::create(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'center_id' => $center->id, 'date' => '2026-01-10', 'status' => 'absent']);
        WeeklyTest::create(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'exam_date' => '2026-02-01', 'result' => 'ناجح']);
        foreach (SurahReference::namesOfJuz(30) as $surah) {
            Memorization::create(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'date' => '2026-01-01', 'surah_name' => $surah, 'juz' => 30, 'quality' => 'good']);
        }
        $token = $this->loginToken($manager);

        $t = $this->authed($token)->getJson("/api/manager/reports/teacher/{$teacher->id}")->assertOk();
        $this->assertSame('الشيخ سالم', $t->json('data.teacher.name'));
        $this->assertSame(1, $t->json('data.students_count')); // النشط فقط
        $this->assertCount(1, $t->json('data.students'));
        $this->assertSame(1, $t->json('data.students.0.completed_juz'));
        $this->assertSame(0, $t->json('data.attendance.percent'));
        $this->assertSame(100, $t->json('data.attendance.absent_percent'));

        $s = $this->authed($token)->getJson("/api/manager/reports/student/{$student->id}")->assertOk();
        $this->assertSame('أحمد', $s->json('data.student.name'));
        $this->assertSame('الشيخ سالم', $s->json('data.teacher.name'));
        $this->assertSame('ولي الأمر', $s->json('data.parent.name'));
        $this->assertSame(1, $s->json('data.attendance.absent'));
        $this->assertSame(100, $s->json('data.tests.pass_percent'));
        $this->assertSame(1, $s->json('data.progress.completed_juz'));
        $this->assertSame(30, $s->json('data.progress.reached_juz'));
        $this->assertTrue($s->json('data.progress.reached_juz_done'));
        $this->assertSame(3, $s->json('data.progress.completion_percent')); // 1/30
        $this->assertSame(0, $s->json('data.progress.khatmat'));
        $this->assertCount(1, $s->json('data.recent_tests'));
    }
}
