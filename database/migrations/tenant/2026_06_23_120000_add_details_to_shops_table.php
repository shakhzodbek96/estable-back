<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('name');   // S3 key (tenant-prefixed)
            $table->text('address')->nullable()->after('image_path');  // to'liq manzil
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('map_url')->nullable()->after('longitude');  // yandex/google maps havola
            $table->json('working_hours')->nullable()->after('map_url'); // {mon:{open,close,closed}, ...}
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'image_path',
                'address',
                'latitude',
                'longitude',
                'map_url',
                'working_hours',
            ]);
        });
    }
};
