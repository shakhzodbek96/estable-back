<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Markaziy (central) — yagona bot bilan aloqada bo'lgan barcha chatlar reestri.
 *
 * Bitta bot butun SaaS uchun ishlagani sababli obunachilar ro'yxati MARKAZIY.
 * Markaziy admin bu yerdan userlarni bloklashi yoki guruh/kanaldan chiqishi mumkin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tg_users', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 100)->unique();
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->string('type', 20)->nullable();    // private | group | supergroup | channel
            $table->string('status', 20)->default('active'); // active | blocked
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_users');
    }
};
