<?php

namespace App\Models;

use App\Exceptions\InvalidTikTokTransition;
use App\Support\TikTokCatalog;
use Database\Factories\TikTokClipFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class TikTokClip extends Model
{
    /** @use HasFactory<TikTokClipFactory> */
    use HasFactory;

    protected $table = 'tiktok_clips';

    protected $fillable = [
        'user_id',
        'status',
        'topic',
        'hook',
        'script',
        'caption',
        'suggested_for',
        'package',
        'score',
        'views',
        'completion_percent',
        'follows',
        'learning_note',
        'learned_at',
        'confirmed_at',
        'queued_at',
        'posted_at',
        'discarded_at',
        'platform_post_id',
    ];

    protected function casts(): array
    {
        return [
            'suggested_for' => 'datetime',
            'learned_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'queued_at' => 'datetime',
            'posted_at' => 'datetime',
            'discarded_at' => 'datetime',
            'completion_percent' => 'decimal:2',
            'views' => 'integer',
            'follows' => 'integer',
            'score' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, User|int $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where($this->qualifyColumn('user_id'), $userId);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [TikTokCatalog::WAITING, TikTokCatalog::QUEUED]);
    }

    /**
     * Clips that still block a new one: anything not learned and not discarded.
     */
    public function scopeBlocking($query)
    {
        return $query->whereNotIn('status', [TikTokCatalog::LEARNED, TikTokCatalog::DISCARDED]);
    }

    public function scopeWithStatus($query, string ...$statuses)
    {
        return $query->whereIn('status', $statuses);
    }

    public function canTransitionTo(string $to): bool
    {
        $allowed = TikTokCatalog::transitions()[$this->status] ?? [];

        return in_array($to, $allowed, true);
    }

    /**
     * Move to $to under a row lock. Validates the status in the database, not the copy in memory.
     */
    public function transitionTo(string $to): static
    {
        if (! $this->exists) {
            throw new InvalidTikTokTransition('Save the clip before changing its status.');
        }

        return DB::transaction(function () use ($to) {
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                throw new InvalidTikTokTransition('This clip no longer exists.');
            }

            if (! $fresh->canTransitionTo($to)) {
                throw new InvalidTikTokTransition("Cannot move a TikTok clip from {$fresh->status} to {$to}.");
            }

            $fresh->status = $to;
            $fresh->stampTransition($to);
            $fresh->save();

            $this->setRawAttributes($fresh->getAttributes(), true);
            $this->syncOriginal();

            return $this;
        });
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [TikTokCatalog::WAITING, TikTokCatalog::QUEUED], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [TikTokCatalog::LEARNED, TikTokCatalog::DISCARDED], true);
    }

    public function statusLabel(): string
    {
        return TikTokCatalog::label($this->status);
    }

    /**
     * Stored package with render and publish defaults filled in.
     * A missing package still reports ready and uploaded as false.
     *
     * @return array<string, mixed>
     */
    public function packageWithDefaults(): array
    {
        return $this->package;
    }

    /**
     * @return Attribute<array<string, mixed>, array<string, mixed>|null>
     */
    protected function package(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): array {
                if (is_array($value)) {
                    $stored = $value;
                } else {
                    $decoded = json_decode(is_string($value) ? $value : 'null', true);
                    $stored = is_array($decoded) ? $decoded : [];
                }

                $render = is_array($stored['render'] ?? null) ? $stored['render'] : [];
                $publish = is_array($stored['publish'] ?? null) ? $stored['publish'] : [];
                $stored['render'] = array_merge(TikTokCatalog::defaultRender(), $render);
                $stored['publish'] = array_merge(TikTokCatalog::defaultPublish(), $publish);

                return $stored;
            },
            set: function (mixed $value): ?string {
                if ($value === null) {
                    return null;
                }

                return json_encode($value);
            },
        );
    }

    private function stampTransition(string $to): void
    {
        $now = now();

        if ($to === TikTokCatalog::QUEUED) {
            $this->confirmed_at ??= $now;
            $this->queued_at ??= $now;
        } elseif ($to === TikTokCatalog::POSTED) {
            $this->posted_at ??= $now;
        } elseif ($to === TikTokCatalog::LEARNED) {
            $this->learned_at ??= $now;
        } elseif ($to === TikTokCatalog::DISCARDED) {
            $this->discarded_at ??= $now;
        }
    }
}
