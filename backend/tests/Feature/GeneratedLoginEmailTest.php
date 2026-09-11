<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * بريد الدخول المولَّد {الاسم اللاتيني}_{الكود}@mutqin.ly لكل الأدوار ذات الكود:
 * محفّظان بالاسم اللاتيني نفسه → بريدان مختلفان (t1/t2) · مدير مركز ca1 · ولي أمر p1 ·
 * البريد لا يُقبل من العميل (الأدمن يرسل email_prefix لا email) · يُعاد في استجابة الإنشاء.
 */
class GeneratedLoginEmailTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    private function teacherPayload(array $over = []): array
    {
        return array_merge([
            'name' => 'أحمد علي', 'email_prefix' => 'ahmed.ali', 'type' => 'محفظ معاون',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ], $over);
    }

    public function test_two_teachers_with_same_latin_name_get_different_emails_from_their_codes(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $token   = $this->loginToken($manager);

        $a = $this->authed($token)->postJson('/api/manager/teachers', $this->teacherPayload())->assertCreated();
        $this->app['auth']->forgetGuards();
        $b = $this->authed($token)->postJson('/api/manager/teachers', $this->teacherPayload())->assertCreated();

        $this->assertSame('ahmed.ali_t1@mutqin.ly', $a->json('data.email')); // يُعاد في الاستجابة لنافذة النجاح
        $this->assertSame('ahmed.ali_t2@mutqin.ly', $b->json('data.email'));
        $this->assertSame('T1', $a->json('data.display_code'));
        $this->assertSame(2, User::where('role', 'teacher')->count());
        $this->assertStringContainsString('ahmed.ali_t1@mutqin.ly', $a->json('message'));
    }

    public function test_admin_created_teacher_uses_prefix_and_ignores_client_email(): void
    {
        $center = $this->makeCenter();
        $token  = $this->loginToken($this->makeAdmin());

        // بريد مرسَل من العميل بلا اسم لاتيني → 422 (البريد لا يُقبل)
        $this->authed($token)->postJson('/api/teachers', array_merge($this->teacherPayload(['center_id' => $center->id]),
            ['email' => 'hacker@evil.com', 'email_prefix' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['email_prefix']);

        // مع اسم لاتيني (وبريد دخيل يُتجاهل) → البريد المولَّد فقط
        $this->app['auth']->forgetGuards();
        $r = $this->authed($token)->postJson('/api/teachers', array_merge($this->teacherPayload(['center_id' => $center->id]),
            ['email' => 'hacker@evil.com']))
            ->assertCreated();
        $this->assertSame('ahmed.ali_t1@mutqin.ly', $r->json('data.email'));
        $this->assertSame(0, User::where('email', 'hacker@evil.com')->count());

        // البريد الكامل الملصوق يُقصّ إلى الاسم اللاتيني
        $this->app['auth']->forgetGuards();
        $r2 = $this->authed($token)->postJson('/api/teachers', $this->teacherPayload(['center_id' => $center->id, 'email_prefix' => 'sara.omar@mutqin.ly']))
            ->assertCreated();
        $this->assertSame('sara.omar_t2@mutqin.ly', $r2->json('data.email'));
    }

    public function test_manager_email_is_built_from_ca_code(): void
    {
        $center = $this->makeCenter();
        $r = $this->authed($this->loginToken($this->makeAdmin()))->postJson('/api/admin/managers', [
            'name' => 'معاذ', 'email_prefix' => 'muad', 'center_id' => $center->id,
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();

        $this->assertSame('muad_ca1@mutqin.ly', $r->json('data.email'));
        $this->assertSame('CA1', $r->json('data.display_code'));
    }

    public function test_new_parent_email_is_built_from_p_code_and_client_email_is_matching_only(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $token   = $this->loginToken($manager);

        $r = $this->authed($token)->postJson('/api/manager/students', [
            'name' => 'طالب', 'age' => 9,
            'guardian_name' => 'Ahmed', 'guardian_phone' => '0917777777', 'guardian_password' => 'secret123',
            'guardian_email' => 'client@evil.com', // لا يُعتمد بريداً للحساب الجديد
        ])->assertCreated();

        $parent = User::find($r->json('data.parent_id'));
        $this->assertSame('P1', $parent->display_code);
        $this->assertSame('ahmed_p1@mutqin.ly', $parent->email);
        $this->assertSame(0, User::where('email', 'client@evil.com')->count());

        // بريد العميل ما زال مفتاح مطابقة لحساب ولي أمر قائم
        $existing = $this->makeParent(['email' => 'known@parent.ly', 'phone' => '0919191919']);
        $this->app['auth']->forgetGuards();
        $r2 = $this->authed($token)->postJson('/api/manager/students', [
            'name' => 'ابن ثانٍ', 'age' => 7, 'guardian_name' => 'x', 'guardian_phone' => '0919191919', 'guardian_email' => 'known@parent.ly',
        ])->assertCreated();
        $this->assertSame($existing->id, $r2->json('data.parent_id'));

        // ولي الأمر الجديد يدخل ببريده المولَّد وبكوده
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'ahmed_p1@mutqin.ly', 'password' => 'secret123'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'p1', 'password' => 'secret123'])->assertOk();
    }
}
