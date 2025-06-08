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
        // Add a discord_id column and a discord_data JSON
        Schema::table('users', function (Blueprint $table) {
            $table->string('discord_id')->nullable()->after('remember_token');
            $table->json('discord_data')->nullable()->after('discord_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the discord_id and discord_data columns
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['discord_id', 'discord_data']);
        });
    }
};
