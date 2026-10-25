<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class YouTubeSeries extends Model
{
    protected $table = 'youtube_series';
    protected $fillable = [
        'user_id',
        'title',
        'premise',
        'characters',
        'outline',
        'next_episode',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'characters' => 'array',
            'outline' => 'array',
            'next_episode' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(YouTubeVideo::class);
    }

    /** @return array<string, mixed> */
    public function toDesk(?string $holdsUntil): array
    {
        return [
            'title' => $this->title,
            'premise' => $this->premise,
            'next_episode' => $this->next_episode,
            'holds_until' => $holdsUntil,
            'characters' => $this->characters ?? [],
            'outline' => $this->outline ?? [],
        ];
    }
}
