<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->morphs('imageable'); // imageable_type + imageable_id (+ index)
            $table->string('path');      // S3 kaliti: <tenant>/<model>/<uuid>.<ext>
            $table->boolean('is_primary')->default(false); // asosiy (muqova) rasm
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['imageable_type', 'imageable_id', 'is_primary'], 'images_primary_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
