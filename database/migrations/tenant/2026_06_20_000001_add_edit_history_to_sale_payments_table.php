<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            // Tahrirlash tarixi (summa/valyuta/kurs o'zgarganda audit yozuvi):
            // [{field, from, to, by, by_name, at}]
            $table->json('edit_history')->nullable()->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropColumn('edit_history');
        });
    }
};
