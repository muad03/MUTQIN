<?php

namespace Tests\Feature;

use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * التقارير على مستوى النظام تعدّ المراكز النشطة فقط — توحيداً مع لوحة الأدمن
 * (overview وallCentersData كانا يعدّان الموقوفة أيضاً).
 */
class ReportsActiveCentersTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_overview_and_all_centers_report_exclude_inactive_centers_like_the_dashboard(): void
    {
        $active   = $this->makeCenter(['name' => 'مركز نشط']);
        $inactive = $this->makeCenter(['name' => 'مركز موقوف', 'is_active' => false]);
        $reports  = app(ReportService::class);

        $overview = $reports->overview(now()->month, now()->year);
        $this->assertSame(1, $overview['centers']);

        $all = $reports->allCentersData(now()->month, now()->year);
        $this->assertCount(1, $all['rows']);
        $this->assertSame('مركز نشط', $all['rows'][0]['center']->name);

        // اللوحة تعدّ النشطة فقط أصلاً — الرقمان متطابقان الآن
        $dash = $this->authed($this->loginToken($this->makeAdmin()))->getJson('/api/dashboard')->assertOk();
        $this->assertSame($overview['centers'], $dash->json('data.stats.total_centers'));
    }
}
