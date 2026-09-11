<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * إضافة الطالب من مدير المركز مع ولي الأمر — POST /api/manager/students:
 * ولي أمر موجود (بالرقم الوطني) لا يُكرَّر · الهاتف وكلمة المرور إلزاميان عند الإنشاء ·
 * صيغة الرقم الوطني الليبي · الكود المُرسَل من العميل يُتجاهل · سباق التزامن يعيد المطابقة ·
 * بحث ولي الأمر يعيد الاسم والرقم وعدد الأبناء فقط.
 */
class ManagerAddStudentGuardianTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    private function manager(): array
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);

        return [$center, $manager, $this->loginToken($manager)];
    }

    public function test_existing_parent_linked_by_id_number_is_not_duplicated(): void
    {
        [, , $token] = $this->manager();
        $parent = $this->makeParent(['name' => 'ولي موجود', 'id_number' => '199000000001']);

        // المسار [أ]: ربط بالرقم الوطني — بلا إنشاء
        $r = $this->authed($token)->postJson('/api/manager/students', [
            'name' => 'الابن الأول', 'age' => 9,
            'parent_id_number' => '199000000001',
        ])->assertCreated();
        $this->assertSame($parent->id, $r->json('data.parent_id'));

        // المسار [ب] برقم وطني يطابق ولياً موجوداً: يُطابَق ولا يُنشأ (ولا تلزم كلمة مرور)
        $this->app['auth']->forgetGuards();
        $r2 = $this->authed($token)->postJson('/api/manager/students', [
            'name' => 'الابن الثاني', 'age' => 7,
            'guardian_id_number' => '199000000001', 'guardian_name' => 'ولي موجود', 'guardian_phone' => '0911111111',
        ])->assertCreated();
        $this->assertSame($parent->id, $r2->json('data.parent_id'));

        $this->assertSame(1, User::where('role', 'parent')->count());
        $this->assertSame(2, Student::where('parent_id', $parent->id)->count());
    }

    public function test_unknown_parent_id_number_is_rejected(): void
    {
        [, , $token] = $this->manager();

        $this->authed($token)->postJson('/api/manager/students', [
            'name' => 'طالب', 'age' => 9, 'parent_id_number' => '199000000009',
        ])->assertStatus(422)->assertJsonValidationErrors(['parent_id_number']);

        $this->assertSame(0, Student::count());
    }

    public function test_phone_and_password_are_required_when_creating_a_new_parent(): void
    {
        [, , $token] = $this->manager();

        // بلا هاتف (مسار مدير المركز) → 422 على الهاتف
        $this->authed($token)->postJson('/api/manager/students', [
            'name' => 'طالب', 'age' => 9,
            'guardian_name' => 'ولي جديد', 'guardian_id_number' => '199000000002', 'guardian_password' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors(['guardian_phone']);

        // بلا كلمة مرور → 422 عربية على كلمة المرور — لا توليد صامت
        $this->app['auth']->forgetGuards();
        $r = $this->authed($token)->postJson('/api/manager/students', [
            'name' => 'طالب', 'age' => 9,
            'guardian_name' => 'ولي جديد', 'guardian_id_number' => '199000000002', 'guardian_phone' => '0912222222',
        ])->assertStatus(422)->assertJsonValidationErrors(['guardian_password']);
        $this->assertStringContainsString('كلمة مرور', $r->json('errors.guardian_password.0'));

        $this->assertSame(0, Student::count());
        $this->assertSame(0, User::where('role', 'parent')->count()); // لا حساب نصف مكتوب
    }

    public function test_new_parent_gets_generated_email_and_can_log_in_with_given_password(): void
    {
        [, , $token] = $this->manager();

        $r = $this->authed($token)->postJson('/api/manager/students', [
            'name' => 'طالب', 'age' => 9,
            'guardian_name' => 'محمد فرج', 'guardian_id_number' => '199000000003',
            'guardian_phone' => '0913333333', 'guardian_password' => 'secret123',
        ])->assertCreated();

        $parent = User::find($r->json('data.parent_id'));
        $this->assertSame('parent', $parent->role);
        $this->assertSame('199000000003', $parent->id_number);
        $this->assertSame('P1', $parent->display_code);
        $this->assertMatchesRegularExpression('/^[a-z0-9.]+_p1@mutqin\.ly$/', $parent->email); // {نقحرة}_{كود}@mutqin.ly

        // كلمة المرور هي المُرسَلة حرفياً (لا عشوائية)
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $parent->email, 'password' => 'secret123'])->assertOk();
    }

    public function test_libyan_national_id_format_is_enforced_for_student_and_guardian(): void
    {
        [, , $token] = $this->manager();

        $this->authed($token)->postJson('/api/manager/students', [
            'name' => 'طالب', 'age' => 9, 'national_id' => '399000000001', // لا يبدأ بـ1/2
        ])->assertStatus(422)->assertJsonValidationErrors(['national_id']);

        $this->app['auth']->forgetGuards();
        $this->authed($token)->postJson('/api/manager/students', [
            'name' => 'طالب', 'age' => 9,
            'guardian_name' => 'ولي', 'guardian_id_number' => '12345', // ليس 12 رقماً
            'guardian_phone' => '0914444444', 'guardian_password' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors(['guardian_id_number']);

        $this->assertSame(0, Student::count());
        $this->assertSame(0, User::where('role', 'parent')->count());
    }

    public function test_client_sent_display_code_is_ignored_and_generated_server_side(): void
    {
        [, , $token] = $this->manager();

        $r = $this->authed($token)->postJson('/api/manager/students', [
            'name' => 'طالب', 'age' => 9, 'display_code' => 'S777',
            'guardian_name' => 'ولي', 'guardian_phone' => '0915555555', 'guardian_password' => 'secret123',
        ])->assertCreated();

        $this->assertSame('S1', $r->json('data.display_code'));
        $this->assertDatabaseMissing('students', ['display_code' => 'S777']);
    }

    public function test_concurrent_parent_creation_rematches_instead_of_500(): void
    {
        [, , $token] = $this->manager();

        // محاكاة السباق: قبل إدراج ولي الأمر مباشرةً يُدرج طلبٌ آخر حساباً بالرقم الوطني نفسه
        $competitorId = null;
        User::creating(function (User $u) use (&$competitorId) {
            if ($u->role === 'parent' && $competitorId === null) {
                $competitorId = DB::table('users')->insertGetId([
                    'name' => 'ولي متسابق', 'email' => 'racer@parent.mutqin.ly', 'phone' => '0916666666',
                    'role' => 'parent', 'password' => bcrypt('x'), 'id_number' => '199000000006',
                    'nationality_type' => 'libyan', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $r = $this->authed($token)->postJson('/api/manager/students', [
            'name' => 'طالب السباق', 'age' => 9,
            'guardian_name' => 'ولي متسابق', 'guardian_id_number' => '199000000006',
            'guardian_phone' => '0916666666', 'guardian_password' => 'secret123',
        ])->assertCreated();

        $this->assertNotNull($competitorId);
        $this->assertSame($competitorId, $r->json('data.parent_id')); // أُعيدت المطابقة بالحساب السابق
        $this->assertSame(1, User::where('id_number', '199000000006')->count());
    }

    /** «بدون ولي أمر»: الطالب يُحفظ بـparent_id = NULL، وأي حقول ولي أمر مرسَلة مع none تُتجاهل فلا يُنشأ حساب. */
    public function test_student_without_guardian_is_saved_with_null_parent_and_stray_fields_are_ignored(): void
    {
        [, , $token] = $this->manager();

        // المسار الثالث الصريح — مع حقول ولي أمر دخيلة (بلا هاتف ولا كلمة مرور، لكن الخادم يتجاهلها أصلاً)
        $r = $this->authed($token)->postJson('/api/manager/students', [
            'name' => 'طالب بلا ولي', 'age' => 9, 'guardian_mode' => 'none',
            'guardian_name' => 'دخيل', 'guardian_id_number' => '199000000008', 'guardian_phone' => '0918888888',
            'guardian_password' => 'secret123', 'guardian_email' => 'stray@x.ly',
        ])->assertCreated();
        $this->assertNull($r->json('data.parent_id'));
        $this->assertNull($r->json('data.guardian_name'));
        $this->assertSame(0, User::where('role', 'parent')->count());

        // بلا أي بيانات ولي أمر إطلاقاً → أيضاً NULL (لا ربط تلقائي بأي حساب قائم)
        $this->makeParent(['id_number' => '199000000009', 'phone' => '0919999999']);
        $this->app['auth']->forgetGuards();
        $r2 = $this->authed($token)->postJson('/api/manager/students', ['name' => 'طالب آخر', 'age' => 8])->assertCreated();
        $this->assertNull($r2->json('data.parent_id'));

        // ومسار الأدمن كذلك
        $this->app['auth']->forgetGuards();
        $r3 = $this->authed($this->loginToken($this->makeAdmin()))->postJson('/api/students', [
            'name' => 'طالب أدمن', 'age' => 10, 'center_id' => Student::first()->center_id, 'guardian_mode' => 'none',
            'guardian_name' => 'دخيل', 'guardian_phone' => '0917777777', 'guardian_password' => 'secret123',
        ])->assertCreated();
        $this->assertNull($r3->json('data.parent_id'));
        $this->assertSame(1, User::where('role', 'parent')->count()); // الحساب القائم فقط — لا جديد
        $this->assertSame(3, Student::whereNull('parent_id')->count());
    }

    public function test_parent_search_by_id_number_returns_minimal_fields_and_is_manager_only(): void
    {
        [$center, , $token] = $this->manager();
        $parent = $this->makeParent(['name' => 'ولي للبحث', 'id_number' => '199000000007', 'phone' => '0917777777']);
        $teacher = $this->makeTeacher($center);
        $this->makeStudent($teacher, $parent);

        $r = $this->authed($token)->getJson('/api/manager/parents/search?q=1990000000')->assertOk();
        $this->assertCount(1, $r->json('data'));
        $this->assertSame(['name', 'id_number', 'children_count'], array_keys($r->json('data.0')));
        $this->assertSame('199000000007', $r->json('data.0.id_number'));
        $this->assertSame(1, $r->json('data.0.children_count'));

        // أقل من 3 أرقام → لا نتائج (لا تسريب بقائمة كاملة)
        $this->assertSame([], $this->authed($token)->getJson('/api/manager/parents/search?q=19')->json('data'));

        // المحفّظ والأدمن خارج هذا المسار
        $this->authed($this->loginToken($teacher))->getJson('/api/manager/parents/search?q=1990000000')->assertForbidden();
        $this->authed($this->loginToken($this->makeAdmin()))->getJson('/api/manager/parents/search?q=1990000000')->assertForbidden();
    }
}
