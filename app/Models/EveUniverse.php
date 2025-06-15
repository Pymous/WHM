<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;

class EveUniverse extends Model
{
    use HasFactory;

    protected $table = 'eve_universe';
    protected $primaryKey = 'item_id';
    protected $fillable = [
        'item_id',
        'type',
        'content'
    ];

    protected $casts = [
        'content' => 'array',
    ];


    public function getNameAttribute()
    {
        // Based on the type, return the appropriate name, which will be one of the fields in the content
        // For type == invNames, it's content.itemName
        // For type == types, it's content.name

        switch ($this->type) {
            case 'invNames':
                return $this->content['itemName'] ?? '';
            case 'types':
                return $this->content['name'] ?? '';
            default:
                break;
        }
        return '';
    }


    /**
     * Scope a query to only include users of a given type.
     */
    #[Scope]
    protected function ofType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }
}
