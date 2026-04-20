<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason'); // defect, customer_change_mind, warranty, other
            $table->text('reason_note')->nullable();
            $table->string('return_type'); // refund, exchange_same, exchange_different
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->string('refund_method')->nullable(); // cash, card, p2p
            $table->foreignId('new_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->decimal('price_difference', 12, 2)->nullable();
            $table->string('item_condition'); // resellable, needs_repair, defective_unusable
            $table->boolean('transfers_to_shop')->default(false);
            $table->string('status')->default('pending'); // pending, completed, rejected
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};
