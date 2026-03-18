<?php

namespace SmartTill\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use SmartTill\Core\Enums\CustomerStatus;
use SmartTill\Core\Traits\HasStoreScopedReference;

class Customer extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasApiTokens, HasFactory, HasStoreScopedReference, Notifiable, SoftDeletes;

    protected $fillable = [
        'store_id',
        'name',
        'phone',
        'email',
        'password',
        'address',
        'status',
        'ntn',
        'cnic',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'password' => 'hashed',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Store::class);
    }

    public static function transactionMorphTypes(): array
    {
        return [
            self::class,
            'App\\Models\\Customer',
            'customer',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function scopeWithPendingBalance(Builder $query): Builder
    {
        return $query->addSelect([
            'pending_balance_raw' => Transaction::query()
                ->select('amount_balance')
                ->whereColumn('store_id', 'customers.store_id')
                ->whereColumn('transactionable_id', 'customers.id')
                ->whereIn('transactionable_type', self::transactionMorphTypes())
                ->latest('id')
                ->limit(1),
        ]);
    }
}
