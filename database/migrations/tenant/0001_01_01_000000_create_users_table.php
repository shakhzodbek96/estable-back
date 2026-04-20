<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('phone', 9)->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->boolean('must_change_password')->default(false);
            $table->string('role')->default('seller');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        // Sessions jadvali ataylab yo'q: Estable API-only, Sanctum bearer token bilan ishlaydi.
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
