<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central: tenants.created_by_admin_id — qaysi super admin tenantni yaratganini
 * qayd qiladi. Nullable (eski tenantlar va seederlar uchun).
 * `nullOnDelete()` — admin o'chirilsa tenant yozuvi yo'qolmaydi, faqat
 * created_by null bo'ladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->after('phone');
            $table->foreign('created_by_admin_id')
                ->references('id')->on('admin_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['created_by_admin_id']);
            $table->dropColumn('created_by_admin_id');
        });
    }
};
