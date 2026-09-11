<?php

namespace Tests\Feature;

use App\Models\Memorization;
use App\Support\SurahReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * بعد إصلاح الأجزاء الخالية من سور البدء: فلتر ?juz=2 و?juz=5 على سجلات الحفظ
 * يعيد سجلات (البقرة/النساء)، وتقدّم طالب بكل الـ114 سورة = 30 جزءاً من نقطة النهاية.
 */
class MemorizationJuzGapFilterTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_juz_2_and_5_filters_return_the_extended_surah_records(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent($teacher);
        $token   = $this->loginToken($teacher);

        foreach (['البقرة', 'النساء', 'الناس'] as $surah) {
            Memorization::create([
                'student_id' => $student->id, 'teacher_id' => $teacher->id,
                'date' => '2026-07-15', 'surah_name' => $surah, 'juz' => SurahReference::SURAHS[$surah],
                'quality' => 'good',
            ]);
        }

        $j2 = $this->authed($token)->getJson('/api/memorizations?juz=2')->assertOk();
        $this->assertSame(1, $j2->json('data.total'));
        $this->assertSame('البقرة', $j2->json('data.data.0.surah_name'));

        $this->app['auth']->forgetGuards();
        $j5 = $this->authed($token)->getJson('/api/memorizations?juz=5')->assertOk();
        $this->assertSame(1, $j5->json('data.total'));
        $this->assertSame('النساء', $j5->json('data.data.0.surah_name'));

        // جزء 1 ما زال يعيد الفاتحة والبقرة (الخريطة الخام لم تتغيّر) — هنا البقرة فقط مسجّلة
        $this->app['auth']->forgetGuards();
        $this->assertSame(1, $this->authed($token)->getJson('/api/memorizations?juz=1')->json('data.total'));
    }

    public function test_student_with_all_114_surahs_shows_thirty_completed_juz_from_endpoint(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent($teacher);
        $token   = $this->loginToken($teacher);

        $rows = [];
        foreach (SurahReference::SURAHS as $surah => $juz) {
            $rows[] = [
                'student_id' => $student->id, 'teacher_id' => $teacher->id,
                'date' => '2026-07-15', 'surah_name' => $surah, 'juz' => $juz, 'quality' => 'good',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        Memorization::insert($rows);

        $r = $this->authed($token)->getJson('/api/memorizations/students-progress')->assertOk();
        $rowsOut = $r->json('data.data') ?? $r->json('data');
        $mine = collect($rowsOut)->firstWhere('id', $student->id) ?? collect($rowsOut)->firstWhere('student_id', $student->id);

        $this->assertNotNull($mine, 'الطالب غير موجود في تقدّم الطلاب');
        $this->assertSame(30, $mine['completed_count']);
        $this->assertSame(1, $mine['completed_down_to']);
        $this->assertSame(1, $mine['reached_juz']);
        $this->assertTrue($mine['reached_juz_done']);
    }
}
