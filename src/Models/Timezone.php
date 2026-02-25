<?php

namespace SmartTill\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Timezone extends Model
{
    protected $fillable = [
        'name',
        'offset',
    ];

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'country_timezone');
    }
}

