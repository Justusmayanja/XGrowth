<?php

namespace App\Models;

use App\Support\YouTubeCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YouTubeVideo extends Model
{
    protected $table = 'youtube_videos';
    protected $fillable = [
        'user_id',
        'youtube_series_id',
        'mode',
        'format',
        'status',
        'topic',
        'title',
        'script',
        'description',
        'package',
        'score',
        'episode_number',
        'source_path',
        'source_name',
        'views',
        'impressions',
        'average_view_percent',
        'learning_note',
        'learned_at',
        'queued_at',
    ];

    protected function casts(): array
    {
        return [
            'package' => 'array',
            'score' => 'float',
            'episode_number' => 'integer',
            'views' => 'integer',
            'impressions' => 'integer',
            'average_view_percent' => 'float',
            'learned_at' => 'datetime',
            'queued_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(YouTubeSeries::class, 'youtube_series_id');
    }

    /** @return array<string, mixed> */
    public function toDesk(): array
    {
        return [
            'id' => $this->id,
            'mode' => $this->mode,
            'format' => $this->format,
            'format_label' => YouTubeCatalog::FORMAT_LABELS[$this->format] ?? null,
            'status' => $this->status,
            'status_label' => YouTubeCatalog::statusLabel($this->status),
            'topic' => $this->topic,
            'title' => $this->title,
            'script' => $this->script,
            'description' => $this->description,
            'score' => $this->score,
            'episode_number' => $this->episode_number,
            'source_name' => $this->source_name,
            'views' => $this->views,
            'average_view_percent' => $this->average_view_percent,
            'learning_note' => $this->learning_note,
            'package' => $this->package,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
