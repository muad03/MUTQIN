<?php

namespace Tests\Feature;

use App\Models\Memorization;
use App\Support\SurahReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * تحقق تسجيل الحفظ — POST /api/memorizations: الصفحات بين 1 و604 والنهاية لا تسبق
 * البداية، والجزء ضمن مدى السورة المشتق من SurahReference، برسائل عربية.
 */
class MemorizationValidationTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    private function payload(array $over = []): array
    {
        return array_merge(['date' => '2026-07-15', 'surah_name' => 'الناس', 'quality' => 'good'], $over);
    }

    public function test_juz_range_is_derived_from_the_reference(): void
    {
        $this->assertSame([30, 30], SurahReference::juzRangeOf('الناس'));
        $this->assertSame([1, 1],   SurahReference::juzRangeOf('الفاتحة'));
        $this->assertSame([1, 3],   SurahReference::juzRangeOf('البقرة'));
        $this->assertSame([4, 6],   SurahReference::juzRangeOf('النساء'));
        $this->assertSame([29, 30], SurahReference::juzRangeOf('المرسلات'));
        $this->assertNull(SurahReference::juzRangeOf('سورة غير موجودة'));
    }

    public function test_invalid_pages_are_rejected_with_arabic_messages(): void
    {
        $teacher = $this->makeTeacher();
        $s = $this->makeStudent($teacher);
        $token = $this->loginToken($teacher);

        // من 600 إلى 10
        $r = $this->authed($token)->postJson('/api/memorizations', $this->payload(['student_id' => $s->id, 'page_from' => 600, 'page_to' => 10]))
            ->assertStatus(422)->assertJsonValidationErrors(['page_to']);
        $this->assertStringContainsString('لا يمكن أن تسبق', $r->json('errors.page_to.0'));

        // خارج المصحف
        $this->app['auth']->forgetGuards();
        $r = $this->authed($token)->postJson('/api/memorizations', $this->payload(['student_id' => $s->id, 'page_from' => 700, 'page_to' => 701]))
            ->assertStatus(422)->assertJsonValidationErrors(['page_from', 'page_to']);
        $this->assertStringContainsString('604', $r->json('errors.page_from.0'));

        // ليس عدداً
        $this->app['auth']->forgetGuards();
        $r = $this->authed($token)->postJson('/api/memorizations', $this->payload(['student_id' => $s->id, 'page_from' => 'abc']))
            ->assertStatus(422)->assertJsonValidationErrors(['page_from']);
        $this->assertStringContainsString('عدداً صحيحاً', $r->json('errors.page_from.0'));

        $this->assertSame(0, Memorization::count());
    }

    public function test_juz_outside_the_surah_range_is_rejected_and_inside_is_accepted(): void
    {
        $teacher = $this->makeTeacher();
        $s = $this->makeStudent($teacher);
        $token = $this->loginToken($teacher);

        // الناس في الجزء 5 → مرفوض بالعربية
        $r = $this->authed($token)->postJson('/api/memorizations', $this->payload(['student_id' => $s->id, 'juz' => 5]))
            ->assertStatus(422)->assertJsonValidationErrors(['juz']);
        $this->assertStringContainsString('الجزء 30', $r->json('errors.juz.0'));

        // البقرة في الجزء 5 → مرفوض (تمتد 1..3)
        $this->app['auth']->forgetGuards();
        $r = $this->authed($token)->postJson('/api/memorizations', $this->payload(['student_id' => $s->id, 'surah_name' => 'البقرة', 'juz' => 5]))
            ->assertStatus(422)->assertJsonValidationErrors(['juz']);
        $this->assertStringContainsString('من الجزء 1 إلى 3', $r->json('errors.juz.0'));
        $this->assertSame(0, Memorization::count());

        // مقبول: الناس 30 · البقرة 2 (جزء ممتد) · بلا جزء · صفحات سليمة
        foreach ([
            ['juz' => 30],
            ['surah_name' => 'البقرة', 'juz' => 2],
            ['surah_name' => 'الفلق'],
            ['surah_name' => 'الملك', 'juz' => 29, 'page_from' => 562, 'page_to' => 564],
        ] as $ok) {
            $this->app['auth']->forgetGuards();
            $this->authed($token)->postJson('/api/memorizations', $this->payload(array_merge(['student_id' => $s->id], $ok)))->assertCreated();
        }
        $this->assertSame(4, Memorization::count());
    }
}
