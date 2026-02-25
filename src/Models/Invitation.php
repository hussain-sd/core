<?php

namespace SmartTill\Core\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class Invitation extends Model
{
    use Notifiable;

    protected $fillable = [
        'email',
        'store_id',
        'invited_by',
        'role_id',
        'token',
        'accepted_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return ! is_null($this->accepted_at);
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    public static function createInvitation(string $email, Store $store, User $invitedBy, ?int $roleId = null): self
    {
        return self::query()->create([
            'email' => $email,
            'store_id' => $store->id,
            'invited_by' => $invitedBy->id,
            'role_id' => $roleId,
            'token' => Str::random(32),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }
}
