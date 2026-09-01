<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * البذر القياسي: فهرس الأثمان المرجعي (بيانات حقيقية ثابتة) ثم البذر
     * الليبي الشامل (بيانات تجريبية توليدية بالكامل — بديل الديمو القديم).
     *
     * ExtraDataSeeder بقي ملفاً ولا يُستدعى — بياناته القديمة لا تُخلط
     * بالبيانات الليبية (يُشغَّل يدوياً فقط إن أُريد، وغير منصوح به بعد الآن).
     */
    public function run(): void
    {
        $this->call(AthmanSeeder::class);      // فهرس الأثمان (477) — idempotent
        $this->call(LibyanDataSeeder::class);  // البذر الليبي الشامل
    }
}
