<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Markaziy (central) — yagona info-bot konfiguratsiyasi (bitta qator).
 * Token markaziy admin panelidan set qilinadi; secret .env'da.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_configs', function (Blueprint $table) {
            $table->id();
            $table->text('bot_token')->nullable();
            $table->string('bot_username')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_configs');
    }
};
