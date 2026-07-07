<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поставщики — mol sotib olinadigan doimiy postavshiklar.
 *
 * Kо'chadagi (walk-in) odam bu yerga yozilmaydi — u supply_batches.supplier_name
 * matn maydonida saqlanadi (supplier_id = null).
 *
 * `balance` = BIZNING postavshikka qarzimiz (musbat = shuncha qarzdormiz). Nasiyaga
 * mol olinganda oshadi, to'langanda kamayadi (2/3-bosqich).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('balance', 12, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
