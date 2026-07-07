<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram aktivatsiya kodlari (OTP).
 *
 * Admin biror entity (user/customer/investor) uchun kod generatsiya qiladi;
 * foydalanuvchi kodni botga yuboradi → webhook uni tasdiqlab, tg_users yozadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tg_otps', function (Blueprint $table) {
            $table->id();
            $table->string('otp', 20)->unique();       // aktivatsiya kodi
            $table->string('model', 30);               // 'user' | 'customer' | 'investor'
            $table->unsignedBigInteger('model_id');
            $table->smallInteger('status')->default(0); // 0=new, 1=used
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['model', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_otps');
    }
};
