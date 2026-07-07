<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Партия — bitta mol kirimi (bitta postavshikdan, bitta sanada).
 * Ичiga N ta IMEI (inventories) va/yoki aksessuar (accessories) kiradi.
 *
 * Manba: doimiy postavshik (supplier_id) YOKI kо'chadagi odam (supplier_name matn).
 * Ikkalasidan biri bo'lishi shart (CHECK).
 *
 * payment_mode:
 *   - 'paid'   — darrov to'langan (naqд/investor; hozirgi xarid oqimi)
 *   - 'credit' — nasiya (postavshik balansiga qarz sifatida tushadi)
 * (Hisob-kitob mantig'i 2-bosqichda ulanadi; hozircha ustun default 'paid'.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_name')->nullable();     // walk-in (supplier_id = null)
            $table->string('invoice_number')->nullable();    // накладная №
            $table->date('batch_date');
            $table->string('payment_mode')->default('paid'); // paid | credit
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        // Manba majburiy: postavshik yoki walk-in nom bo'lishi shart.
        DB::statement('ALTER TABLE supply_batches ADD CONSTRAINT supply_batches_source_chk
            CHECK (supplier_id IS NOT NULL OR supplier_name IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_batches');
    }
};
