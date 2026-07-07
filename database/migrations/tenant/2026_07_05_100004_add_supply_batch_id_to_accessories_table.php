<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aksessuar partiyasini supply_batch'ga bog'lash. Mavjud `invoice_number` ustuni
 * saqlanadi (eski ma'lumot uchun); yangi kirimlarda накладная partiyada bo'ladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            $table->foreignId('supply_batch_id')->nullable()->after('consignment_item_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supply_batch_id');
        });
    }
};
