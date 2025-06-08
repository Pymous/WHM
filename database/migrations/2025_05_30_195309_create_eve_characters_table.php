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
        Schema::create('eve_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('character_id')->unique();
            $table->string('name');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_valid')->default(true);
            $table->text('esi_access_token')->nullable();
            $table->text('esi_refresh_token')->nullable();
            $table->timestamp('esi_expires_at')->nullable();
            $table->text('esi_scopes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eve_characters');
    }
};
