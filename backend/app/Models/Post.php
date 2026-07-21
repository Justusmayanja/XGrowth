<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    protected $fillable = [
        'user_id',
        'connected_account_id',
        'trend_id',
        'platform_post_id',
        'content_type',
        'content',
        'status',
        'virality_predicted',
        'impressions',
        'likes',
        'reposts',
        'replies',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'virality_predicted' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }

    public function trend(): BelongsTo
    {
        return $this->belongsTo(Trend::class);
    }
}
