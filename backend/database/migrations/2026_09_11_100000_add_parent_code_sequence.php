<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * عدّاد كود ولي الأمر (P1, P2..) في code_sequences — نفس آلية الطلاب/المحفّظين.
 * لا backfill: أولياء الأمور القائمون يبقون بلا كود (قرار معتمد)، والتوليد للجدد
 * عبر خطاف User::creating.
 *
 * SQL خام مكافئ (للخادم بلا artisan):
 *   INSERT INTO code_sequences (name, value) SELECT 'parent', 0
 *   WHERE NOT EXISTS (SELECT 1 FROM code_sequences WHERE name = 'parent');
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('code_sequences')->where('name', 'parent')->exists()) {
            DB::table('code_sequences')->insert(['name' => 'parent', 'value' => 0]);
        }
    }

    public function down(): void
    {
        DB::table('code_sequences')->where('name', 'parent')->delete();
    }
};
