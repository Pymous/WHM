<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EveCorporation extends Model
{
    protected $fillable = [
        'corporation_id',
        'alliance_id',
        'name',
        'ticker',
        'description',
        'url',
        'ceo_id',
        'creator_id',
        'date_founded',
        'home_station_id',
        'member_count',
        'tax_rate',
        'war_eligible',
    ];

    protected $casts = [
        'corporation_id' => 'integer',
        'alliance_id' => 'integer',
        'ceo_id' => 'integer',
        'creator_id' => 'integer',
        'date_founded' => 'datetime',
        'home_station_id' => 'integer',
        'member_count' => 'integer',
        'tax_rate' => 'float',
        'war_eligible' => 'boolean',
    ];

    protected $table = 'eve_corporations';

    protected $primaryKey = 'corporation_id';

    public $incrementing = false; // Corporation ID is not auto-incrementing

    public $timestamps = true; // Use timestamps for created_at and updated_at
}
