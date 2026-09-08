<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلب النقل بين المراكز يُنشئه مدير المركز المصدر ولا يعرف محفّظي المركز المستهدف،
 * فيُترك target_teacher_id فارغاً ويعيّنه مدير المركز المستهدف لحظة الاعتماد
 * (أو يبقى الطالب «بلا محفّظ» ليُوزَّع لاحقاً). طلبات الإضافة تبقى تحمل محفّظها.
 *
 * SQL خام مكافئ (للخادم بلا artisan):
 *   ALTER TABLE student_requests MODIFY target_teacher_id BIGINT UNSIGNED NULL;
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('target_teacher_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('student_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('target_teacher_id')->nullable(false)->change();
        });
    }
};
