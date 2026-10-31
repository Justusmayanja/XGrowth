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
        'voice_niche',
        'voice_description',
        'voice_audience',
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

    public function hasOwnVoice(): bool
    {
        return trim((string) $this->voice_niche) !== ''
            || trim((string) $this->voice_description) !== ''
            || trim((string) $this->voice_audience) !== '';
    }

    public function nicheFor(?AccountProfile $profile): string
    {
        $own = trim((string) $this->voice_niche);

        return $own !== '' ? $own : ($profile?->resolvedNiche() ?? '');
    }

    public function descriptionFor(?AccountProfile $profile): string
    {
        $own = trim((string) $this->voice_description);

        return $own !== '' ? $own : trim((string) ($profile?->description ?? ''));
    }

    public function audienceFor(?AccountProfile $profile): string
    {
        $own = trim((string) $this->voice_audience);

        return $own !== '' ? $own : trim((string) ($profile?->audience ?? ''));
    }
}
