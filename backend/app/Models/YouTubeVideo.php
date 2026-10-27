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
            'package' => $this->deskPackage(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function deskPackage(): ?array
    {
        $package = $this->package;
        if (! is_array($package)) {
            return null;
        }

        $transcript = trim((string) ($package['transcript'] ?? ''));
        unset($package['transcript'], $package['model_learning']);
        $package['has_transcript'] = $transcript !== '';
        if ($transcript === '') {
            unset($package['edit'], $package['shorts']);
        }

        return $package;
    }
}
