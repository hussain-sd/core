<?php

namespace SmartTill\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Currency extends Model
{
    protected $fillable = [
        'name',
        'code',
        'decimal_places',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
        ];
    }

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'country_currency');
    }
}

