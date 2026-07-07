<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Bitta do'konda bir vaqtda faqat bitta OCHIQ smena bo'lishi mumkin.
     * Partial unique index — application-level tekshiruv (ShiftService::open)
     * poygada o'tkazib yuborsa ham DB rad etadi.
     */
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS cash_shifts_one_open_per_shop
             ON cash_shifts (shop_id) WHERE status = 'open'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cash_shifts_one_open_per_shop');
    }
};
