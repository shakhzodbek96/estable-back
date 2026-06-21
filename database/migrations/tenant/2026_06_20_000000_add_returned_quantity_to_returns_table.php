<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            // Qaytarilgan miqdor — bulk (aksessuar) uchun qisman qaytarishni qo'llab-quvvatlaydi.
            // Serial uchun doim 1. Eski yozuvlar uchun NULL (= butun sotuv qatori) deb qaraladi.
            $table->unsignedInteger('returned_quantity')->nullable()->after('sale_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });
    }
};
