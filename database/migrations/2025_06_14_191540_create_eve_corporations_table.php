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
        Schema::create('eve_corporations', function (Blueprint $table) {
            $table->unsignedBigInteger('corporation_id')->primary();
            $table->unsignedBigInteger('alliance_id')->nullable();
            $table->string('name');
            $table->string('ticker');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('ceo_id')->nullable();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->timestamp('date_founded')->nullable();
            $table->unsignedBigInteger('home_station_id')->nullable();
            $table->integer('member_count')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0.0);
            $table->string('url')->nullable();
            $table->boolean('war_eligible')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eve_corporations');
    }
};
