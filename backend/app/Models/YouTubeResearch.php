<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YouTubeResearch extends Model
{
    protected $table = 'youtube_research';

    protected $fillable = [
        'user_id',
        'listings',
        'topics',
        'api_cache',
        'quota_date',
        'quota_used',
    ];

    protected function casts(): array
    {
        return [
            'listings' => 'array',
            'topics' => 'array',
            'api_cache' => 'array',
            'quota_date' => 'date',
            'quota_used' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
