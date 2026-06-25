<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            // Dinamik xususiyatlar snapshot'i: [{"id":5,"name":"Материал","type":"text","value":"Кожа"}]
            $table->jsonb('custom_attributes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            $table->dropColumn('custom_attributes');
        });
    }
};
