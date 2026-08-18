<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvePiTaxEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'corporation_id',
        'journal_id',
        'wallet_division',
        'ref_type',
        'amount',
        'balance',
        'occurred_at',
        'first_party_id',
        'second_party_id',
        'payer_id',
        'payer_name',
        'context_id',
        'context_id_type',
        'planet_id',
        'planet_name',
        'description',
        'reason',
        'tax',
        'tax_receiver_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'tax' => 'decimal:4',
        'occurred_at' => 'immutable_datetime',
    ];
}
