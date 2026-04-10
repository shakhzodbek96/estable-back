<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consignment_id')->constrained()->cascadeOnDelete();
            $table->string('item_type'); // serial, bulk
            $table->unsignedBigInteger('inventory_id')->nullable();
            $table->unsignedBigInteger('accessory_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->integer('sold_quantity')->default(0);
            $table->integer('returned_quantity')->default(0);
            $table->decimal('agreed_price', 12, 2);
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_items');
    }
};
