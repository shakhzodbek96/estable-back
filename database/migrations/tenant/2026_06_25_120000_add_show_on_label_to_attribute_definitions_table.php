<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attribute_definitions', function (Blueprint $table) {
            // Etiketkada (yorliqda) chiqarilsinmi — galochka
            $table->boolean('show_on_label')->default(false)->after('applies_to');
        });
    }

    public function down(): void
    {
        Schema::table('attribute_definitions', function (Blueprint $table) {
            $table->dropColumn('show_on_label');
        });
    }
};
