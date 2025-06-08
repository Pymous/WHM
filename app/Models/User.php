<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'discord_id',
        'discord_data',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the EVE characters associated with the user.
     */
    public function eveCharacters(): HasMany
    {
        return $this->hasMany(EveCharacter::class);
    }

    /**
     * Get the primary EVE character associated with the user.
     */
    public function primaryEveCharacter()
    {
        return $this->eveCharacters()->where('is_primary', true)->first();
    }

    /**
     * Check if the user has any EVE characters.
     */
    public function hasEveCharacters(): bool
    {
        return $this->eveCharacters()->exists();
    }
}
