<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number');
            $table->string('barcode')->index();
            $table->integer('quantity');
            $table->integer('sold_quantity')->default(0);
            $table->integer('consigned_quantity')->default(0);
            $table->decimal('purchase_price', 12, 2);
            $table->decimal('sell_price', 12, 2);
            $table->text('notes')->nullable();
            $table->foreignId('consignment_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investor_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessories');
    }
};
