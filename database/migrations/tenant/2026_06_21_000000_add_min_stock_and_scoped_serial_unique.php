<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kritik qoldiq ogohlantirish nuqtasi (null — ogohlantirish yo'q)
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('min_stock')->nullable()->after('name');
        });

        // Serial uniqueligi faqat SKLADDA turgan (in_stock) tovarlarga tegishli.
        // Sotilgan/hisobdan chiqarilgan serial qayta sotib olinib kiritilishi mumkin
        // (tarix sifatida bir nechta yozuv qoladi, lekin in_stock'da bittadan ortiq bo'lmaydi).
        DB::statement(
            "CREATE UNIQUE INDEX inventories_serial_in_stock_unique ".
            "ON inventories (serial_number) WHERE status = 'in_stock'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inventories_serial_in_stock_unique');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('min_stock');
        });
    }
};
