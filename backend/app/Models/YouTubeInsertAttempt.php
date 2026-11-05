<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YouTubeInsertAttempt extends Model
{
    protected $table = 'youtube_insert_attempts';

    protected $fillable = [
        'user_id',
        'youtube_video_id',
        'reserved_on',
    ];

    protected function casts(): array
    {
        return [
            'reserved_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(YouTubeVideo::class, 'youtube_video_id');
    }
}
