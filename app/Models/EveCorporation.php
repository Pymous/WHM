<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EveCorporation extends Model
{
    protected $fillable = [
        'corporation_id',
        'name',
        'ticker',
        'description',
        'url',
        'ceo_id',
        'ceo_name',
        'founded_at',
        'member_count',
        'shares',
        'tax_rate',
        'public_data', // JSON field for additional public data
    ];
    protected $casts = [
        'corporation_id' => 'integer',
        'ceo_id' => 'integer',
        'founded_at' => 'datetime',
        'member_count' => 'integer',
        'shares' => 'integer',
        'tax_rate' => 'float',
        'public_data' => 'json', // Cast public_data to JSON
    ];
    protected $table = 'eve_corporations';
    protected $primaryKey = 'corporation_id';
    public $incrementing = false; // Corporation ID is not auto-incrementing
    public $timestamps = true; // Use timestamps for created_at and updated_at
}
