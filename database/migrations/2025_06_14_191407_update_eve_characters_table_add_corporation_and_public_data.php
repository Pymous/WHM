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
        Schema::table('eve_characters', function (Blueprint $table) {
            $table->json('public_data')->nullable()->after('is_valid');
            $table->unsignedBigInteger('corporation_id')->nullable()->after('is_valid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('eve_characters', function (Blueprint $table) {
            $table->dropColumn(['corporation_id', 'alliance_id', 'public_data']);
        });
    }
};
