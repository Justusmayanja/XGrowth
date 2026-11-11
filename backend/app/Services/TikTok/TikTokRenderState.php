<?php

namespace App\Services\TikTok;

use App\Models\TikTokClip;
use App\Support\TikTokCatalog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Owns package.render transitions. succeed() is the only generated-path writer of render.ready.
 */
class TikTokRenderState
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        TikTokCatalog::RENDER_NONE => [TikTokCatalog::RENDER_QUEUED, TikTokCatalog::RENDER_SKIPPED, TikTokCatalog::RENDER_FAILED],
        TikTokCatalog::RENDER_SKIPPED => [TikTokCatalog::RENDER_QUEUED],
        TikTokCatalog::RENDER_FAILED => [TikTokCatalog::RENDER_QUEUED],
        TikTokCatalog::RENDER_READY => [TikTokCatalog::RENDER_QUEUED],
        TikTokCatalog::RENDER_QUEUED => [TikTokCatalog::RENDER_RENDERING, TikTokCatalog::RENDER_FAILED, TikTokCatalog::RENDER_SKIPPED],
        TikTokCatalog::RENDER_RENDERING => [TikTokCatalog::RENDER_READY, TikTokCatalog::RENDER_FAILED],
    ];

    public function queue(TikTokClip $clip, bool $regeneration = false): TikTokClip
    {
        return $this->move($clip, TikTokCatalog::RENDER_QUEUED, function (array $render) use ($regeneration) {
            $render['attempts'] = (int) ($render['attempts'] ?? 0) + 1;
            if ($regeneration) {
                $render['regenerations'] = (int) ($render['regenerations'] ?? 0) + 1;
            }
            $render['queued_at'] = now()->utc()->toIso8601String();
            $render['note'] = null;
            $render['ready'] = false;

            return $render;
        });
    }

    public function start(TikTokClip $clip): TikTokClip
    {
        return $this->move($clip, TikTokCatalog::RENDER_RENDERING, function (array $render) {
            $render['started_at'] = now()->utc()->toIso8601String();
            $render['ready'] = false;

            return $render;
        });
    }

    public function fail(TikTokClip $clip, string $reason): TikTokClip
    {
        return $this->move($clip, TikTokCatalog::RENDER_FAILED, function (array $render) use ($reason) {
            $render['note'] = $reason;
            $render['ready'] = false;
            $render['finished_at'] = now()->utc()->toIso8601String();

            return $render;
        });
    }

    public function skip(TikTokClip $clip, string $reason): TikTokClip
    {
        return $this->move($clip, TikTokCatalog::RENDER_SKIPPED, function (array $render) use ($reason) {
            $render['note'] = $reason;
            $render['ready'] = false;
            $render['finished_at'] = now()->utc()->toIso8601String();

            return $render;
        });
    }

    public function succeed(TikTokClip $clip): TikTokClip
    {
        return $this->move($clip, TikTokCatalog::RENDER_READY, function (array $render) {
            $render['ready'] = true;
            $render['provider'] = 'ffmpeg';
            $render['ai_generated'] = true;
            $render['note'] = null;
            $render['finished_at'] = now()->utc()->toIso8601String();

            return $render;
        }, function (array $package) {
            $format = (string) ($package['week']['format'] ?? 'motion');
            $package['render']['provider'] = $format === 'scene' ? 'kling' : 'ffmpeg';
            $publish = is_array($package['publish'] ?? null) ? $package['publish'] : [];
            $publish['ai_generated'] = true;
            $package['publish'] = $publish;

            return $package;
        });
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $packageMutate
     */
    private function move(TikTokClip $clip, string $to, callable $mutate, ?callable $packageMutate = null): TikTokClip
    {
        return DB::transaction(function () use ($clip, $to, $mutate, $packageMutate) {
            $fresh = TikTokClip::query()->whereKey($clip->id)->lockForUpdate()->firstOrFail();
            $package = $fresh->package;
            $render = is_array($package['render'] ?? null) ? $package['render'] : [];
            $render = array_merge(TikTokCatalog::defaultRender(), $render);
            $from = (string) ($render['status'] ?? TikTokCatalog::RENDER_NONE);
            if (! in_array($to, self::ALLOWED[$from] ?? [], true)) {
                throw new InvalidArgumentException('That render transition is not allowed.');
            }
            $render['status'] = $to;
            $render = $mutate($render);
            $package['render'] = $render;
            if ($packageMutate) {
                $package = $packageMutate($package);
            }
            $fresh->package = $package;
            $fresh->save();

            return $fresh;
        });
    }
}
