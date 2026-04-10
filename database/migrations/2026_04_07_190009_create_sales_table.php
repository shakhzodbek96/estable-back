<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->date('sale_date')->default(DB::raw('CURRENT_DATE'))->index();
            $table->decimal('total_price', 12, 2);
            $table->string('payment_method');
            $table->foreignId('investor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sold_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
