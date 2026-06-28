<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pg_trgm extension — ILIKE '%...%' (substring) qidiruvlari uchun trigram GIN
 * indekslarini yoqadi. Extension butun baza uchun bir marta `public` schema'ga
 * o'rnatiladi; tenant indekslari opclass'ni `public.gin_trgm_ops` deb schema-qualify
 * qiladi (tenant search_path'da public yo'q).
 *
 * Bu CENTRAL migration — bir marta ishlaydi (tenant'lar bo'yicha takrorlanmaydi).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public');
    }

    public function down(): void
    {
        // Extension'ni o'chirmaymiz — unga bog'liq tenant GIN indekslari CASCADE bilan
        // buziladi. Umumiy resurs sifatida o'z holicha qoldiriladi.
    }
};
