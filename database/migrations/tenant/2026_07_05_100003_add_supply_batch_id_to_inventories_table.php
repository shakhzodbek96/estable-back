<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serial (IMEI) tovarni partiyaga bog'lash. Nullable — eski tovarlarda partiya yo'q,
 * partiya olib tashlansa null bo'ladi (tovar qoladi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->foreignId('supply_batch_id')->nullable()->after('consignment_item_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supply_batch_id');
        });
    }
};
