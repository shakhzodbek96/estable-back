<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // "Цвет", "Память"
            $table->string('type')->default('text');      // text|number|date|boolean|select
            $table->jsonb('options')->nullable();         // select uchun: ["Чёрный","Белый"]
            $table->string('unit')->nullable();           // "ГБ", "см" ...
            $table->string('applies_to')->default('serial'); // serial|bulk|both
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Bir ro'yxat (applies_to) ichida nom takrorlanmasin
            $table->unique(['name', 'applies_to']);
            $table->index(['applies_to', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_definitions');
    }
};
