<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debt_entries', function (Blueprint $table) {
            // Qaytarish sanasi (ixtiyoriy) — aniq sana yoki nisbiy muddatdan hisoblangan
            $table->date('due_date')->nullable()->after('entry_date');
        });
    }

    public function down(): void
    {
        Schema::table('debt_entries', function (Blueprint $table) {
            $table->dropColumn('due_date');
        });
    }
};
