<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attribute_definitions', function (Blueprint $table) {
            $table->string('icon', 50)->nullable()->after('name');        // lucide icon nomi
            $table->string('icon_color', 9)->nullable()->after('icon');   // hex: #RRGGBB
        });
    }

    public function down(): void
    {
        Schema::table('attribute_definitions', function (Blueprint $table) {
            $table->dropColumn(['icon', 'icon_color']);
        });
    }
};
