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
 * تفاصيل المحفّظ وأداؤه لمدير المركز — GET /api/manager/teachers/{id}/performance:
 * محفّظ مركز آخر → 403 عربية (لا تسريب لطلابه)، غير المدير → 403 من البوابة،
 * والمدير يحصل على إحصائيات مجمّعة صحيحة وجدول طلابه النشطين، والمعطَّل يُعرض بحالته.
 */
class ManagerTeacherPerformanceTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_teacher_of_another_center_is_forbidden_with_arabic_message(): void
    {
        $centerA = $this->makeCenter(['name' => 'مركز أ']);
        $centerB = $this->makeCenter(['name' => 'مركز ب']);
        $managerA = $this->makeManager($centerA);
        $teacherB = $this->makeTeacher($centerB);
        $this->makeStudent($teacherB, null, ['name' => 'طالب سرّي في ب']);

        $r = $this->authed($this->loginToken($managerA))
            ->getJson("/api/manager/teachers/{$teacherB->id}/performance")
            ->assertForbidden();

        $this->assertStringContainsString('مركزك', $r->json('message'));
        $this->assertStringNotContainsString('طالب سرّي', $r->getContent()); // لا تسريب
    }

    public function test_non_manager_roles_are_forbidden_by_the_gate(): void
    {
        $center  = $this->makeCenter();
        $teacher = $this->makeTeacher($center);

        foreach ([$this->makeAdmin(), $teacher, $this->makeParent()] as $u) {
            $this->authed($this->loginToken($u))
                ->getJson("/api/manager/teachers/{$teacher->id}/performance")
                ->assertForbidden();
        }
    }

    public function test_manager_gets_teacher_info_aggregated_stats_and_active_students(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center, ['name' => 'الشيخ خالد', 'type' => 'محفظ أساسي']);
        $s1 = $this->makeStudent($teacher, null, ['name' => 'أحمد']);
        $s2 = $this->makeStudent($teacher, null, ['name' => 'بلال']);
        $s3 = $this->makeStudent($teacher, null, ['name' => 'موقوف', 'is_active' => false]);
        $this->makeStudent($this->makeTeacher($center), null, ['name' => 'طالب محفّظ آخر']);

        $d = today()->toDateString();
        Attendance::create(['student_id' => $s1->id, 'teacher_id' => $teacher->id, 'center_id' => $center->id, 'date' => $d, 'status' => 'present']);
        Attendance::create(['student_id' => $s2->id, 'teacher_id' => $teacher->id, 'center_id' => $center->id, 'date' => $d, 'status' => 'absent']);
        Attendance::create(['student_id' => $s3->id, 'teacher_id' => $teacher->id, 'center_id' => $center->id, 'date' => $d, 'status' => 'absent']); // موقوف — خارج الحساب
        WeeklyTest::create(['student_id' => $s1->id, 'teacher_id' => $teacher->id, 'exam_date' => $d, 'result' => 'ناجح']);
        WeeklyTest::create(['student_id' => $s2->id, 'teacher_id' => $teacher->id, 'exam_date' => $d, 'result' => 'راسب']);

        // s1 أتمّ القرآن (114 سورة)، s2 جزء عمّ فقط
        $rows = [];
        foreach (SurahReference::SURAHS as $surah => $juz) {
            $rows[] = ['student_id' => $s1->id, 'teacher_id' => $teacher->id, 'date' => $d, 'surah_name' => $surah, 'juz' => $juz, 'quality' => 'good', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (SurahReference::namesOfJuz(30) as $surah) {
            $rows[] = ['student_id' => $s2->id, 'teacher_id' => $teacher->id, 'date' => $d, 'surah_name' => $surah, 'juz' => 30, 'quality' => 'good', 'created_at' => now(), 'updated_at' => now()];
        }
        Memorization::insert($rows);

        $r = $this->authed($this->loginToken($manager))
            ->getJson("/api/manager/teachers/{$teacher->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.teacher.name', 'الشيخ خالد')
            ->assertJsonPath('data.teacher.type', 'محفظ أساسي')
            ->assertJsonPath('data.teacher.is_active', true)
            ->assertJsonPath('data.stats.students_active', 2)
            ->assertJsonPath('data.stats.students_inactive', 1)
            ->assertJsonPath('data.stats.attendance_percent', 50)
            ->assertJsonPath('data.stats.tests_month.pass_percent', 50)
            ->assertJsonPath('data.stats.performance_score', 75) // 50 + 50/2
            ->assertJsonPath('data.stats.avg_completed_juz', 15.5) // (30 + 1) / 2
            ->assertJsonPath('data.stats.khatmat', 1);

        $names = collect($r->json('data.students'))->pluck('name')->all();
        $this->assertSame(['أحمد', 'بلال'], $names); // النشطون فقط، بلا طلاب المحفّظ الآخر
        $this->assertTrue($r->json('data.students.0.completed_quran'));
        $this->assertSame(30, $r->json('data.students.0.completed_juz'));
        $this->assertSame(1, $r->json('data.students.1.completed_juz'));
        $this->assertSame(100, $r->json('data.students.0.attendance_percent'));
    }

    public function test_inactive_teacher_and_teacher_without_students_are_shown(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center, ['is_active' => false]);

        $this->authed($this->loginToken($manager))
            ->getJson("/api/manager/teachers/{$teacher->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.teacher.is_active', false)
            ->assertJsonPath('data.stats.students_active', 0)
            ->assertJsonPath('data.stats.khatmat', 0)
            ->assertJsonPath('data.students', []);
    }
}
