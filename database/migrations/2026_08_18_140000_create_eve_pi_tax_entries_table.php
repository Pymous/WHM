<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eve_pi_tax_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('corporation_id')->index();
            $table->unsignedBigInteger('journal_id');
            $table->unsignedTinyInteger('wallet_division');
            $table->string('ref_type', 64);
            $table->decimal('amount', 20, 2);
            $table->decimal('balance', 20, 2)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->unsignedBigInteger('first_party_id')->nullable();
            $table->unsignedBigInteger('second_party_id')->nullable();
            $table->unsignedBigInteger('payer_id')->nullable()->index();
            $table->string('payer_name')->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('context_id_type', 32)->nullable();
            $table->unsignedBigInteger('planet_id')->nullable()->index();
            $table->string('planet_name')->nullable();
            $table->text('description')->nullable();
            $table->text('reason')->nullable();
            $table->decimal('tax', 20, 4)->nullable();
            $table->unsignedBigInteger('tax_receiver_id')->nullable();
            $table->timestamps();

            $table->unique(['corporation_id', 'journal_id']);
            $table->index(['corporation_id', 'occurred_at']);
            $table->index(['corporation_id', 'ref_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eve_pi_tax_entries');
    }
};
