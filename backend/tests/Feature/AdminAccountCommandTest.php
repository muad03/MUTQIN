<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * أمر mutqin:admin — الطريق الوحيد لإنشاء حساب مدير نظام ببريد يختاره صاحب
 * المشروع (لا تسجيل ذاتي في النظام، ولا نقطة API لإنشاء أدمن).
 * الاختبار يثبت أن الحساب الناتج يدخل فعلاً من /api/auth/login.
 */
class AdminAccountCommandTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_command_creates_admin_account_that_can_login(): void
    {
        $this->artisan('mutqin:admin', [
            'email'      => 'owner@example.com',
            '--name'     => 'صاحب النظام',
            '--password' => 'strong-pass-1',
        ])->assertSuccessful();

        $user = User::where('email', 'owner@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->is_active);

        $this->postJson('/api/auth/login', ['email' => 'owner@example.com', 'password' => 'strong-pass-1'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'admin');
    }

    public function test_command_resets_existing_account_and_revokes_its_tokens(): void
    {
        $teacher = $this->makeTeacher(null, ['email' => 'owner@example.com']);
        $oldToken = $this->loginToken($teacher);

        $this->artisan('mutqin:admin', [
            'email'      => 'owner@example.com',
            '--password' => 'new-strong-pass',
        ])->assertSuccessful();

        // كلمة المرور القديمة لم تعد تعمل، والجديدة تدخل بدور admin
        $this->postJson('/api/auth/login', ['email' => 'owner@example.com', 'password' => 'password'])
            ->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'owner@example.com', 'password' => 'new-strong-pass'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'admin');

        // التوكن القديم أُبطل (S1)
        $this->app['auth']->forgetGuards();
        $this->authed($oldToken)->getJson('/api/auth/user')->assertStatus(401);

        $user = User::where('email', 'owner@example.com')->first();
        $this->assertNull($user->center_id); // الأدمن بلا مركز — وإلا منعه فحص المركز المعطَّل
    }

    public function test_sql_only_does_not_touch_the_database(): void
    {
        $this->artisan('mutqin:admin', [
            'email'      => 'nobody@example.com',
            '--password' => 'strong-pass-1',
            '--sql-only' => true,
        ])->assertSuccessful();

        $this->assertNull(User::where('email', 'nobody@example.com')->first());
    }

    public function test_short_password_and_bad_email_are_rejected(): void
    {
        $this->artisan('mutqin:admin', ['email' => 'owner@example.com', '--password' => 'short'])
            ->assertFailed();

        $this->artisan('mutqin:admin', ['email' => 'not-an-email', '--password' => 'strong-pass-1'])
            ->assertFailed();

        $this->assertSame(0, User::where('role', 'admin')->count());
    }
}
