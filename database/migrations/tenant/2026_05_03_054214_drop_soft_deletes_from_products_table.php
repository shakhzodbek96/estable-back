<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SoftDeletes products jadvalidan olib tashlanadi.
 *
 * Sabab: deleted_at NULL bo'lmagan satrlar `name` unique constraint'ini
 * "ushlab" turardi — o'chirib qayta qo'shishda 23505 (duplicate key) xatosi
 * yuzaga kelardi. Endi delete = HARD delete (FK cascadeOnDelete bilan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'deleted_at')) {
            // Soft-deleted (deleted_at != NULL) satrlarni TIKLAYMIZ — ma'lumotlar
            // yo'qolmasin. Unique constraint `name` ustida edi (deleted_at'ga
            // qaramay), shuning uchun NULL qilsak konflikt yuzaga kelmaydi.
            DB::table('products')
                ->whereNotNull('deleted_at')
                ->update(['deleted_at' => null]);

            Schema::table('products', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('products', 'deleted_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }
};
