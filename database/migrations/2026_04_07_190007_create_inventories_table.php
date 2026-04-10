<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('serial_number')->index();
            $table->string('extra_serial_number')->nullable();
            $table->decimal('purchase_price', 12, 2);
            $table->decimal('extra_cost', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2);
            $table->decimal('sold_price', 12, 2)->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->string('status')->default('in_stock')->index();
            $table->boolean('has_box')->default(true);
            $table->text('notes')->nullable();
            $table->string('state')->default('new'); // new, used
            $table->foreignId('consignment_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('shop_id');
        });

        DB::statement("
            ALTER TABLE inventories ADD CONSTRAINT check_ownership
            CHECK (NOT (investor_id IS NOT NULL AND consignment_item_id IS NOT NULL))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
