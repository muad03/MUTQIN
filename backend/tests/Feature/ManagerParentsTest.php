<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * صفحة أولياء الأمور لمدير المركز — GET /api/manager/parents (عرض فقط):
 * الارتباط بالمركز عبر الأبناء النشطين فقط · ولي أمر من مركز آخر لا يظهر ·
 * أبناء المركز الآخر لا تُعرض · دور آخر → 403 · فلتر الحالة والبحث والترقيم.
 */
class ManagerParentsTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_other_roles_are_forbidden(): void
    {
        $center  = $this->makeCenter();
        $teacher = $this->makeTeacher($center);

        foreach ([$this->makeAdmin(), $teacher, $this->makeParent()] as $u) {
            $this->authed($this->loginToken($u))->getJson('/api/manager/parents')->assertForbidden();
        }
    }

    public function test_only_parents_with_active_children_in_own_center_and_only_those_children(): void
    {
        $centerA = $this->makeCenter(['name' => 'مركز أ']);
        $centerB = $this->makeCenter(['name' => 'مركز ب']);
        $managerA = $this->makeManager($centerA);
        $teacherA = $this->makeTeacher($centerA);
        $teacherB = $this->makeTeacher($centerB);

        $shared  = $this->makeParent(['name' => 'ولي مشترك', 'id_number' => '199000000001', 'phone' => '0911111111']); // ابن في أ وابن في ب
        $onlyB   = $this->makeParent(['name' => 'ولي مركز ب']);
        $noKids  = $this->makeParent(['name' => 'ولي بلا أبناء']);
        $onlyInactiveA = $this->makeParent(['name' => 'ولي ابن موقوف']);

        $this->makeStudent($teacherA, $shared, ['name' => 'ابن في أ']);
        $this->makeStudent($teacherB, $shared, ['name' => 'ابن سرّي في ب']);
        $this->makeStudent($teacherB, $onlyB, ['name' => 'طالب ب']);
        $this->makeStudent($teacherA, $onlyInactiveA, ['name' => 'موقوف في أ', 'is_active' => false]);

        $r = $this->authed($this->loginToken($managerA))->getJson('/api/manager/parents')->assertOk();
        $rows = $r->json('data.data');

        $this->assertSame(1, $r->json('data.total'));
        $this->assertSame(20, $r->json('data.per_page'));
        $this->assertSame('ولي مشترك', $rows[0]['name']);
        $this->assertSame('P1', $rows[0]['display_code']);
        $this->assertSame('0911111111', $rows[0]['phone']);
        $this->assertSame('199000000001', $rows[0]['id_number']);
        $this->assertTrue($rows[0]['is_active']);

        // أبناؤه في هذا المركز فقط
        $this->assertSame(['ابن في أ'], array_column($rows[0]['children'], 'name'));
        $this->assertSame(1, $rows[0]['children_in_center_count']);
        $this->assertStringNotContainsString('ابن سرّي في ب', $r->getContent());
        $this->assertStringNotContainsString('ولي مركز ب', $r->getContent());
        $this->assertStringNotContainsString('ولي بلا أبناء', $r->getContent());
        $this->assertStringNotContainsString('ولي ابن موقوف', $r->getContent());
    }

    public function test_status_filter_and_search(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center);
        $active   = $this->makeParent(['name' => 'أحمد الفيتوري', 'phone' => '0912345678', 'id_number' => '199000000007']); // P1
        $inactive = $this->makeParent(['name' => 'سالم الزوي', 'is_active' => false]);                                       // P2
        $this->makeStudent($teacher, $active);
        $this->makeStudent($teacher, $inactive);
        $token = $this->loginToken($manager);

        $this->assertSame(1, $this->authed($token)->getJson('/api/manager/parents')->json('data.total'));                    // النشطون افتراضياً
        $this->assertSame(1, $this->authed($token)->getJson('/api/manager/parents?status=inactive')->json('data.total'));
        $this->assertSame(2, $this->authed($token)->getJson('/api/manager/parents?status=all')->json('data.total'));

        // بحث مطبَّع بالاسم (احمد = أحمد) · بالكود (p1 / ١) · بالهاتف بصيغة دولية · بالرقم الوطني
        foreach (['احمد', 'p1', '١', '+218 91 234 5678', '1990000000'] as $q) {
            $this->assertSame(1, $this->authed($token)->getJson('/api/manager/parents?q=' . urlencode($q))->json('data.total'), "q={$q}");
            $this->assertSame($active->id, $this->authed($token)->getJson('/api/manager/parents?q=' . urlencode($q))->json('data.data.0.id'), "q={$q}");
        }
        $this->assertSame(0, $this->authed($token)->getJson('/api/manager/parents?q=' . urlencode('لا أحد'))->json('data.total'));
    }
}
