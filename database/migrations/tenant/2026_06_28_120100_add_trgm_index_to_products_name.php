<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * products.name bo'yicha ILIKE '%...%' qidiruvini tezlashtirish uchun trigram GIN
 * index. Aksessuar (Inventory bulk tab) va serial tovar — ikkalasi ham nom bo'yicha
 * `whereHas('product', name ILIKE %x%)` qiladi; bu index ikkalasiga ham xizmat qiladi.
 *
 * Opclass `public.gin_trgm_ops` deb schema-qualify qilingan — tenant search_path
 * faqat tenant schema'siga o'rnatiladi, public bo'lmaydi.
 *
 * Talab: pg_trgm extension (central migration: enable_pg_trgm_extension).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS products_name_trgm_idx ON products USING gin (name public.gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_name_trgm_idx');
    }
};
