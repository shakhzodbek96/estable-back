<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram obunachilar — chat_id ↔ tizim entity (user/customer/investor) bog'lanishi.
 *
 * Bitta chat = bitta qator. `model` + `model_id` polimorf bog'lanish (nullable —
 * bog'lanmagan guruh/kanal chat'lari ham ro'yxatga tushishi mumkin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tg_users', function (Blueprint $table) {
            $table->id();
            // Polimorf bog'lanish: 'user' | 'customer' | 'investor' (kelajakda kengaytiriladi)
            $table->string('model', 30)->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            // Telegram chat identifikatori (shaxs/guruh/kanal) — bitta chat bitta qator
            $table->string('chat_id', 100)->unique();
            $table->string('name')->nullable();       // Telegram ismi
            $table->string('username')->nullable();    // @username (bo'lsa)
            $table->string('type', 20)->nullable();    // private | group | supergroup | channel
            $table->timestamps();

            $table->index(['model', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_users');
    }
};
