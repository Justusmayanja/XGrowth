<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectedAccount extends Model
{
    protected $fillable = [
        'user_id',
        'platform',
        'platform_account_id',
        'username',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'is_active',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile(): HasMany
    {
        return $this->hasMany(AccountProfile::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
