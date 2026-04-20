<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central schema'da (public) `personal_access_tokens` jadvali.
 *
 * Bu jadval CENTRAL ADMIN tokenlari uchun — `App\Models\AdminUser` modeliga
 * polymorphic bog'lanadi. Tenant user tokenlari har tenant schema'sida
 * alohida `personal_access_tokens` jadvalida saqlanadi, shu sababli
 * kross-kontekst token ko'rinmaydi (tenant user token'i admin endpointlar'da
 * ishlamaydi va aksincha).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name', 500);
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
