<?php

namespace App\Models;

use App\Support\TikTokContentPosting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TikTokClipVideo extends Model
{
    protected $table = 'tiktok_clip_videos';

    protected $fillable = [
        'clip_id',
        'source',
        'disk',
        'path',
        'original_filename',
        'size',
        'duration_seconds',
        'width',
        'height',
        'codec',
        'checksum',
        'ready',
        'aspect_warning',
        'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'duration_seconds' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'ready' => 'boolean',
            'purged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (TikTokClipVideo $video) {
            if (! is_string($video->path) || $video->path === '' || $video->purged_at !== null) {
                return;
            }

            $disk = $video->disk ?: TikTokContentPosting::DISK;
            Storage::disk($disk)->delete($video->path);
        });
    }

    public function clip(): BelongsTo
    {
        return $this->belongsTo(TikTokClip::class, 'clip_id');
    }
}
