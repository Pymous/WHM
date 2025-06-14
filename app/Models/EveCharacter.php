<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EveCharacter extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'character_id',
        'name',
        'is_primary',
        'is_valid',
        'public_data',
        'corporation_id',
        'esi_access_token',
        'esi_refresh_token',
        'esi_expires_at',
        'esi_scopes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_primary' => 'boolean',
        'is_valid' => 'boolean',
        'esi_expires_at' => 'datetime',
    ];

    /**
     * Get the user that owns the character.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if ESI tokens are expired.
     */
    public function isTokenExpired(): bool
    {
        return !$this->esi_expires_at || $this->esi_expires_at->isPast();
    }

    public function makePrimary()
    {
        $this->update(['is_primary' => true]);
        // Set all other characters to not primary
        $this->user->eveCharacters()
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);
    }

    public function corporation()
    {
        return $this->belongsTo(EveCorporation::class, 'corporation_id', 'corporation_id');
    }
}
