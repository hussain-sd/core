<?php

namespace SmartTill\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Country extends Model
{
    protected $fillable = [
        'name',
        'code',
        'code3',
        'numeric_code',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    public function currencies(): BelongsToMany
    {
        return $this->belongsToMany(Currency::class, 'country_currency');
    }

    public function timezones(): BelongsToMany
    {
        return $this->belongsToMany(Timezone::class, 'country_timezone');
    }
}

