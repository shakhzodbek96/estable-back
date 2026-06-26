<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_contact_id')->constrained('debt_contacts')->cascadeOnDelete();
            $table->string('type');                       // credit | debit
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('usd'); // usd | uzs
            $table->string('comment', 255)->nullable();
            $table->date('entry_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['debt_contact_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_entries');
    }
};
