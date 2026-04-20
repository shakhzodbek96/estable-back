<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 12, 2);
            $table->string('currency')->default('usd');
            $table->decimal('rate', 12, 2);
            $table->boolean('is_credit');
            $table->string('type');
            $table->date('transaction_date')->nullable();
            $table->json('details')->nullable();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('accepted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('investor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
