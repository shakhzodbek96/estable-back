<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    /**
     * Central DB dagi tenants jadvali.
     * Har bir qator = bitta biznes egasi (partner).
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();

            // Biznes egasi haqida asosiy ma'lumot (Central Admin Panelda ko'rinadi)
            $table->string('name');                 // "Aziz elektronika"
            $table->string('owner_name')->nullable(); // biznes egasi ismi
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();

            // Subscription / holati
            $table->string('plan')->default('trial');            // trial, basic, pro, enterprise
            $table->string('status')->default('active');         // active, suspended, trial_expired, archived
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();

            // Texnik
            $table->string('db_name')->nullable();               // yaratilgan tenant DB nomi (audit uchun)
            $table->timestamp('last_seen_at')->nullable();       // oxirgi faoliyat vaqti

            $table->timestamps();

            // Stancl virtualcolumn uchun — qo'shimcha fieldlar JSON shaklida shu ustunga tushadi.
            $table->json('data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}
