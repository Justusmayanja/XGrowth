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
    ];

    protected function casts(): array
    {
        return [
            'listings' => 'array',
            'topics' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
