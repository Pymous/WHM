<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('eve_universe', function (Blueprint $table) {
            $table->string('item_id')->primary();
            $table->string('type')->index();
            $table->json('content');
            $table->timestamps();

            // Create a unique constraint for item_id and type to prevent duplicates
            $table->unique(['item_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eve_universe');
    }
};
