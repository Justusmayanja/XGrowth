<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Post extends Model
{
    protected $fillable = [
        'user_id',
        'connected_account_id',
        'trend_id',
        'platform_post_id',
        'content_type',
        'content',
        'media_path',
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

    /**
     * Generated X drafts go cold with the moment. After two days unposted they are removed.
     * TikTok and YouTube drafts are left alone.
     */
    public static function expireUnpostedX(int $days = 2, ?int $userId = null): int
    {
        $query = static::query()
            ->where('status', 'draft')
            ->where('created_at', '<', now()->subDays($days))
            ->where('content_type', 'not like', 'tiktok%')
            ->where('content_type', 'not like', 'youtube%');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $deleted = 0;
        $query->orderBy('id')->chunkById(100, function ($posts) use (&$deleted) {
            foreach ($posts as $post) {
                if (is_string($post->media_path) && $post->media_path !== '') {
                    Storage::disk('local')->delete($post->media_path);
                }
                $post->delete();
                $deleted++;
            }
        });

        return $deleted;
    }
}
