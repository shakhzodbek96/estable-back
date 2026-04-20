<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estable Central Admin Panel foydalanuvchilari (super admin).
 *
 * Tenant user'lar'dan butunlay alohida — bu jadval faqat central schema'da
 * (public) joylashadi va faqat `admin.estable.uz` frontend orqali ishlatiladi.
 * Hozircha faqat bitta super admin bo'ladi, lekin schema kelajakda ko'p
 * operator qo'shish imkoniyatini ochiq qoldiradi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
