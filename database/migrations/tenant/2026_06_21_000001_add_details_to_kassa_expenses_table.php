<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kassa_expenses', function (Blueprint $table) {
            // P2P uchun qo'shimcha ma'lumotlar: card_last4, time (audit uchun)
            $table->json('details')->nullable()->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('kassa_expenses', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
