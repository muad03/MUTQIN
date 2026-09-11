<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_valid_login_returns_token_and_user(): void
    {
        $teacher = $this->makeTeacher();

        $res = $this->postJson('/api/auth/login', ['email' => $teacher->email, 'password' => 'password']);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'teacher')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role']]]);
    }

    public function test_wrong_password_is_rejected_with_arabic_message(): void
    {
        $teacher = $this->makeTeacher();

        $this->postJson('/api/auth/login', ['email' => $teacher->email, 'password' => 'wrong-pass'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'بيانات الدخول غير صحيحة');
    }

    public function test_login_is_throttled_after_10_attempts(): void
    {
        $teacher = $this->makeTeacher();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['email' => $teacher->email, 'password' => 'wrong-pass'])
                ->assertStatus(422);
        }

        // المحاولة 11 خلال نفس الدقيقة → 429 (S4)
        $this->postJson('/api/auth/login', ['email' => $teacher->email, 'password' => 'wrong-pass'])
            ->assertStatus(429);
    }

    public function test_protected_route_without_token_is_401(): void
    {
        $this->getJson('/api/auth/user')->assertStatus(401);
    }
    // ===== الدخول بكود الدخول أو البريد =====

    public function test_login_by_display_code_works_for_teacher_manager_and_parent(): void
    {
        $center  = $this->makeCenter();
        $teacher = $this->makeTeacher($center);  // T1
        $manager = $this->makeManager($center);  // CA1
        $parent  = $this->makeParent();          // P1

        foreach ([[$teacher, 'T1', 'teacher'], [$manager, 'CA1', 'center_manager'], [$parent, 'P1', 'parent']] as [$u, $code, $role]) {
            $this->assertSame($code, $u->display_code);
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/auth/login', ['email' => $code, 'password' => 'password'])
                ->assertOk()
                ->assertJsonPath('data.user.id', $u->id)
                ->assertJsonPath('data.user.role', $role);
        }
    }

    public function test_login_by_email_still_works(): void
    {
        $teacher = $this->makeTeacher();

        $this->postJson('/api/auth/login', ['email' => $teacher->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $teacher->id);
    }

    public function test_login_by_code_is_case_insensitive(): void
    {
        $teacher = $this->makeTeacher(); // T1

        foreach (['t1', 'T1', ' t1 '] as $code) {
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/auth/login', ['email' => $code, 'password' => 'password'])
                ->assertOk()
                ->assertJsonPath('data.user.id', $teacher->id);
        }
    }

    public function test_unknown_code_and_wrong_password_fail_with_the_same_unified_message(): void
    {
        $teacher = $this->makeTeacher(); // T1

        $unknown = $this->postJson('/api/auth/login', ['email' => 'T999', 'password' => 'password'])->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $wrongPw = $this->postJson('/api/auth/login', ['email' => 'T1', 'password' => 'wrong-password'])->assertStatus(422);

        // نفس الرسالة في الحالتين — لا تكشف أيّ الحقلين صحيح
        $this->assertSame('بيانات الدخول غير صحيحة', $unknown->json('message'));
        $this->assertSame($unknown->json('message'), $wrongPw->json('message'));
        $this->assertSame($unknown->json('errors'), $wrongPw->json('errors'));
        $this->assertSame(0, $teacher->tokens()->count());
    }

    public function test_code_of_inactive_account_is_blocked_after_password_check(): void
    {
        $teacher = $this->makeTeacher(null, ['is_active' => false]); // T1

        $this->postJson('/api/auth/login', ['email' => 'T1', 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'هذا الحساب غير نشط، راجع إدارة المركز');
    }
}
