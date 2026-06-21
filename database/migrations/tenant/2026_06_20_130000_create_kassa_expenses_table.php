<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kassa_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();
            $table->string('type');       // kategoriya: salary|rent|purchase|expense|withdrawal
            $table->string('method');     // cash|card|p2p
            $table->string('currency');   // usd|uzs
            $table->decimal('amount', 12, 2);   // original summa (original valyutada)
            $table->decimal('rate', 12, 2)->default(0);
            $table->string('status')->default('new'); // new|accepted|rejected
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kassa_expenses');
    }
};
