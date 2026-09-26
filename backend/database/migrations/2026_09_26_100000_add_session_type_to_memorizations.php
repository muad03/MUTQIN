<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds session_type to the memorizations table.
 *
 * Values:
 *  - new      → حفظ جديد  (the student is memorising a new surah)
 *  - revision → مراجعة    (the student is revising a previously memorised surah)
 *
 * Defaults to 'new' so all existing rows keep their current meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memorizations', function (Blueprint $table) {
            $table->enum('session_type', ['new', 'revision'])
                  ->default('new')
                  ->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('memorizations', function (Blueprint $table) {
            $table->dropColumn('session_type');
        });
    }
};
