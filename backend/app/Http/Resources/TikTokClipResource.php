<?php

namespace App\Http\Resources;

use App\Models\ConnectedAccount;
use App\Models\TikTokClip;
use App\Support\TikTokCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TikTokClip */
class TikTokClipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $package = $this->package;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'topic' => $this->topic,
            'hook' => $this->hook,
            'script_lines' => $this->scriptLines(),
            'caption' => $this->caption,
            'on_screen_text' => array_values(is_array($package['on_screen_text'] ?? null) ? $package['on_screen_text'] : []),
            'shots' => array_values(is_array($package['shots'] ?? null) ? $package['shots'] : []),
            'sound_note' => $package['sound_note'] ?? null,
            'suggested_for' => $this->suggested_for?->utc()->toIso8601String(),
            'timezone' => $package['timezone'] ?? null,
            'timezone_assumed' => (bool) ($package['timezone_assumed'] ?? false),
            'score' => $this->score,
            'views' => $this->views,
            'completion_percent' => $this->completion_percent === null ? null : (float) $this->completion_percent,
            'follows' => $this->follows,
            'learning_note' => $this->learning_note,
            'confirmed_at' => $this->confirmed_at?->utc()->toIso8601String(),
            'queued_at' => $this->queued_at?->utc()->toIso8601String(),
            'posted_at' => $this->posted_at?->utc()->toIso8601String(),
            'learned_at' => $this->learned_at?->utc()->toIso8601String(),
            'publish_note' => $package['publish']['note'] ?? null,
            'platform_post_id' => $this->platform_post_id,
            'blockers' => $this->blockers($package),
        ];
    }

    /**
     * @return list<string>
     */
    private function scriptLines(): array
    {
        $script = trim((string) $this->script);
        if ($script === '') {
            return [];
        }

        return array_values(array_filter(preg_split("/\r\n|\n|\r/", $script) ?: [], fn ($line) => $line !== ''));
    }

    /**
     * Empty only when a video file is ready and a TikTok account is connected.
     *
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    private function blockers(array $package): array
    {
        $blockers = [];
        $ready = (bool) ($package['render']['ready'] ?? false);
        if (! $ready) {
            $blockers[] = TikTokCatalog::BLOCKER_VIDEO_FILE;
        }

        $attribute = 'tiktok.connected.'.$this->user_id;
        $connected = request()->attributes->has($attribute)
            ? (bool) request()->attributes->get($attribute)
            : ConnectedAccount::query()
                ->where('user_id', $this->user_id)
                ->where('platform', 'tiktok')
                ->where('is_active', true)
                ->exists();
        if (! $connected) {
            $blockers[] = TikTokCatalog::BLOCKER_TIKTOK_CONNECTION;
        }

        return $blockers;
    }
}
