<?php

namespace Tests\Unit;

use App\Support\SurahReference;
use PHPUnit\Framework\TestCase;

/**
 * الأجزاء التي لا تبدأ فيها سورة (2 داخل البقرة، 5 داخل النساء) تتبع سورتها
 * الممتدة: طالب بكل الـ114 سورة يُتمّ 30 جزءاً نزولاً حتى 1، وnamesOfJuz(2/5)
 * تعيد السورة الممتدة لا مصفوفة فارغة.
 */
class SurahReferenceJuzGapTest extends TestCase
{
    public function test_all_114_surahs_complete_thirty_juz_down_to_one(): void
    {
        $p = SurahReference::progress(array_keys(SurahReference::SURAHS));

        $this->assertSame(30, $p['completed_count']);
        $this->assertSame(1, $p['completed_down_to']);
        $this->assertSame(1, $p['reached_juz']);
        $this->assertTrue($p['reached_juz_done']);
        $this->assertSame('الفاتحة', $p['last_surah']);
    }

    public function test_every_juz_from_1_to_30_has_at_least_one_surah(): void
    {
        for ($j = 1; $j <= 30; $j++) {
            $this->assertNotEmpty(SurahReference::namesOfJuz($j), "الجزء {$j} بلا سور");
        }
        $this->assertSame(['البقرة'], SurahReference::namesOfJuz(2));
        $this->assertSame(['النساء'], SurahReference::namesOfJuz(5));
        $this->assertSame(['الفاتحة', 'البقرة'], SurahReference::namesOfJuz(1)); // الخريطة الخام بلا تغيير
        $this->assertSame([], SurahReference::namesOfJuz(31)); // خارج النطاق
    }

    public function test_juz_2_and_5_complete_only_with_their_extended_surah(): void
    {
        $all = array_keys(SurahReference::SURAHS);

        // بلا البقرة: السلسلة المتّصلة من 30 تقف عند 3 (الجزءان 2 و1 غير مكتملين)
        $noBaqara = array_values(array_diff($all, ['البقرة']));
        $p = SurahReference::progress($noBaqara);
        $this->assertSame(28, $p['completed_count']);
        $this->assertSame(3, $p['completed_down_to']);

        // بلا النساء: تقف عند 6 (الجزءان 5 و4 غير مكتملين)
        $noNisa = array_values(array_diff($all, ['النساء']));
        $p = SurahReference::progress($noNisa);
        $this->assertSame(25, $p['completed_count']);
        $this->assertSame(6, $p['completed_down_to']);
    }

    public function test_reached_juz_logic_is_unchanged(): void
    {
        // جزء عمّ وحده: وصل إلى 30 وأتمّه، مكتمل واحد
        $p = SurahReference::progress(SurahReference::namesOfJuz(30));
        $this->assertSame(30, $p['reached_juz']);
        $this->assertTrue($p['reached_juz_done']);
        $this->assertSame(1, $p['completed_count']);
        $this->assertSame(30, $p['completed_down_to']);

        // الناس + الملك فقط: وصل إلى 29 ولم يُتمّه، ولا جزء مكتمل
        $p = SurahReference::progress(['الناس', 'الملك']);
        $this->assertSame(29, $p['reached_juz']);
        $this->assertFalse($p['reached_juz_done']);
        $this->assertSame(0, $p['completed_count']);
        $this->assertNull($p['completed_down_to']);
    }
}
