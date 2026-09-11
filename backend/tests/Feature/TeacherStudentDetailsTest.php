<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Memorization;
use App\Models\WeeklyTest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * صفحة تفاصيل الطالب للمحفّظ — GET /api/students/{id}/details و/day:
 * طالب محفّظ آخر → 403 عربية · يوم بلا سجلات → الرسالة المعتمدة حرفياً ·
 * التاريخ الافتراضي «اليوم» بتوقيت Africa/Tripoli لا UTC · صيغة التاريخ تُتحقّق (422) ·
 * لا بيانات ولي أمر في الاستجابة.
 */
class TeacherStudentDetailsTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_student_of_another_teacher_is_forbidden(): void
    {
        $center = $this->makeCenter();
        $mine   = $this->makeTeacher($center);
        $other  = $this->makeTeacher($center);
        $s      = $this->makeStudent($other);
        $token  = $this->loginToken($mine);

        $r = $this->authed($token)->getJson("/api/students/{$s->id}/details")->assertForbidden();
        $this->assertStringContainsString('غير مصرح', $r->json('message'));
        $this->authed($token)->getJson("/api/students/{$s->id}/day?date=2026-07-15")->assertForbidden();
    }

    public function test_details_return_own_student_without_guardian_fields(): void
    {
        $teacher = $this->makeTeacher();
        $s = $this->makeStudent($teacher, null, ['name' => 'أحمد', 'is_active' => false, 'guardian_name' => 'ولي سرّي', 'guardian_phone' => '0918888888']);
        Attendance::create(['student_id' => $s->id, 'teacher_id' => $teacher->id, 'center_id' => $s->center_id, 'date' => '2026-07-15', 'status' => 'late', 'notes' => 'تأخر قليلاً']);
        Attendance::create(['student_id' => $s->id, 'teacher_id' => $teacher->id, 'center_id' => $s->center_id, 'date' => '2026-07-16', 'status' => 'absent']);
        WeeklyTest::create(['student_id' => $s->id, 'teacher_id' => $teacher->id, 'exam_date' => '2026-07-18', 'result' => 'ناجح', 'notes' => 'جيد']);
        Memorization::create(['student_id' => $s->id, 'teacher_id' => $teacher->id, 'date' => '2026-07-15', 'surah_name' => 'الناس', 'juz' => 30, 'quality' => 'excellent', 'notes' => 'متقن']);

        $r = $this->authed($this->loginToken($teacher))->getJson("/api/students/{$s->id}/details")
            ->assertOk()
            ->assertJsonPath('data.student.name', 'أحمد')
            ->assertJsonPath('data.student.is_active', false) // الموقوف يُعرض لا يُخفى
            ->assertJsonPath('data.attendance.late', 1)
            ->assertJsonPath('data.attendance.absent', 1)
            ->assertJsonPath('data.attendance.percent', 0)
            ->assertJsonPath('data.tests.passed', 1)
            ->assertJsonPath('data.progress.reached_juz', 30)
            ->assertJsonPath('data.memorization_quality.excellent', 1)
            ->assertJsonPath('data.recent.attendances.0.status', 'absent')   // الأحدث أولاً
            ->assertJsonPath('data.recent.attendances.1.notes', 'تأخر قليلاً') // الملاحظات متاحة للمحفّظ
            ->assertJsonPath('data.recent.tests.0.notes', 'جيد');

        $content = $r->getContent();
        $this->assertStringNotContainsString('ولي سرّي', $content);
        $this->assertStringNotContainsString('0918888888', $content);
        $this->assertStringNotContainsString('guardian', $content);
        $this->assertStringNotContainsString('parent', $content);
        $this->assertSame('متقن', $r->json('data.recent.memorizations.0.notes'));
    }

    public function test_day_without_records_returns_exact_arabic_message(): void
    {
        $teacher = $this->makeTeacher();
        $s = $this->makeStudent($teacher);
        Attendance::create(['student_id' => $s->id, 'teacher_id' => $teacher->id, 'center_id' => $s->center_id, 'date' => '2026-07-15', 'status' => 'present']);

        $r = $this->authed($this->loginToken($teacher))->getJson("/api/students/{$s->id}/day?date=2026-07-16")
            ->assertOk()
            ->assertJsonPath('data.empty', true)
            ->assertJsonPath('data.message', 'لا توجد سجلات لهذا الطالب في هذا التاريخ.')
            ->assertJsonPath('data.attendance', null);
        $this->assertSame([], $r->json('data.memorizations'));
        $this->assertSame([], $r->json('data.tests'));
    }

    public function test_day_with_records_returns_attendance_memorization_and_test_with_notes(): void
    {
        $teacher = $this->makeTeacher();
        $s = $this->makeStudent($teacher);
        Attendance::create(['student_id' => $s->id, 'teacher_id' => $teacher->id, 'center_id' => $s->center_id, 'date' => '2026-07-15', 'time' => '07:10:00', 'status' => 'late', 'notes' => 'تأخر عن الحلقة']);
        Memorization::create(['student_id' => $s->id, 'teacher_id' => $teacher->id, 'date' => '2026-07-15', 'surah_name' => 'الفلق', 'juz' => 30, 'quality' => 'good', 'notes' => 'يحتاج تثبيتاً']);
        $t = WeeklyTest::create(['student_id' => $s->id, 'teacher_id' => $teacher->id, 'exam_date' => '2026-07-15', 'result' => 'راسب', 'notes' => 'يُعاد']);
        $t->questions()->create(['student_id' => $s->id, 'eighth_start' => 'قل أعوذ برب الفلق', 'result' => 'راسب', 'mistake' => 'خلط بين الآيات']);

        $this->authed($this->loginToken($teacher))->getJson("/api/students/{$s->id}/day?date=2026-07-15")
            ->assertOk()
            ->assertJsonPath('data.empty', false)
            ->assertJsonPath('data.message', null)
            ->assertJsonPath('data.attendance.status', 'late')
            ->assertJsonPath('data.attendance.notes', 'تأخر عن الحلقة')
            ->assertJsonPath('data.memorizations.0.surah_name', 'الفلق')
            ->assertJsonPath('data.memorizations.0.quality', 'good')
            ->assertJsonPath('data.memorizations.0.notes', 'يحتاج تثبيتاً')
            ->assertJsonPath('data.tests.0.result', 'راسب')
            ->assertJsonPath('data.tests.0.questions.0.mistake', 'خلط بين الآيات');
    }

    public function test_default_date_is_today_in_tripoli_timezone_not_utc(): void
    {
        // 00:30 بتوقيت طرابلس يوم 12 = 22:30 UTC يوم 11 — الافتراضي يجب أن يكون يوم 12
        Carbon::setTestNow(Carbon::parse('2026-09-12 00:30:00', 'Africa/Tripoli'));
        $teacher = $this->makeTeacher();
        $s = $this->makeStudent($teacher);
        Attendance::create(['student_id' => $s->id, 'teacher_id' => $teacher->id, 'center_id' => $s->center_id, 'date' => '2026-09-12', 'status' => 'present']);
        Attendance::create(['student_id' => $s->id, 'teacher_id' => $teacher->id, 'center_id' => $s->center_id, 'date' => '2026-09-11', 'status' => 'absent']);

        $this->authed($this->loginToken($teacher))->getJson("/api/students/{$s->id}/day")
            ->assertOk()
            ->assertJsonPath('data.date', '2026-09-12')
            ->assertJsonPath('data.attendance.status', 'present');
    }

    public function test_invalid_date_format_is_rejected_with_arabic_message(): void
    {
        $teacher = $this->makeTeacher();
        $s = $this->makeStudent($teacher);

        $r = $this->authed($this->loginToken($teacher))->getJson("/api/students/{$s->id}/day?date=15/07/2026")
            ->assertStatus(422)->assertJsonValidationErrors(['date']);
        $this->assertStringContainsString('Y-m-d', $r->json('errors.date.0'));
    }
}
