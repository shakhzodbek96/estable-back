<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            $table->foreignId('transaction_id')
                ->nullable()
                ->after('investor_id')
                ->constrained('transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_id');
        });
    }
};
