<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('item_type'); // serial, bulk
            $table->foreignId('inventory_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('accessory_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->integer('warranty_months')->nullable();
            $table->text('warranty_note')->nullable();
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE sale_items ADD CONSTRAINT check_item_reference
            CHECK (
                (item_type = 'serial' AND inventory_id IS NOT NULL AND accessory_id IS NULL)
                OR
                (item_type = 'bulk' AND accessory_id IS NOT NULL AND inventory_id IS NULL)
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
