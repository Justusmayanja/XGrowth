<?php

namespace App\Http\Resources;

use App\Models\ConnectedAccount;
use App\Models\TikTokClip;
use App\Services\TikTok\TikTokDraftService;
use App\Support\TikTokCatalog;
use App\Support\TikTokVideoCatalog;
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
        $kind = is_string($package['idea_kind'] ?? null) && $package['idea_kind'] !== ''
            ? $package['idea_kind']
            : null;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'kind' => $kind,
            'kind_label' => $kind ? TikTokCatalog::kindLabel($kind) : null,
            'week' => $this->weekPayload($package),
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
            'render' => $this->renderPayload($package),
            'video' => $this->videoPayload(),
            'upload_error' => $this->upload_error,
            'upload_progress' => $this->upload_progress,
        ];
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array{format: string, job: string, label: string}|null
     */
    private function weekPayload(array $package): ?array
    {
        $week = $package['week'] ?? null;
        if (! is_array($week)) {
            return null;
        }
        $format = (string) ($week['format'] ?? '');
        $job = (string) ($week['job'] ?? '');
        $pictures = [
            'motion' => 'Motion graphics',
            'scene' => 'Scene',
            'card' => 'Invite card',
        ];
        $jobs = [
            'teach' => 'Teaches',
            'show' => 'Shows the situation',
            'correct' => 'Corrects a mistake',
            'invite' => 'Asks for the next step',
        ];
        if (! isset($pictures[$format], $jobs[$job])) {
            return null;
        }

        return [
            'format' => $format,
            'job' => $job,
            'label' => $pictures[$format].' · '.$jobs[$job],
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
        // is_active false means the link needs a fresh sign-in and still blocks posting.
        if (! $connected) {
            $blockers[] = TikTokCatalog::BLOCKER_TIKTOK_CONNECTION;
        } elseif ($this->user && app(TikTokDraftService::class)->needsUploadPermission($this->user)) {
            $blockers[] = TikTokCatalog::BLOCKER_TIKTOK_UPLOAD;
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array{status: string, label: string, note: ?string, ready: bool, provider: string, ai_generated: bool, can_regenerate: bool}
     */
    private function renderPayload(array $package): array
    {
        $render = is_array($package['render'] ?? null) ? $package['render'] : [];
        $status = (string) ($render['status'] ?? TikTokCatalog::RENDER_NONE);
        $regenerations = (int) ($render['regenerations'] ?? 0);

        return [
            'status' => $status,
            'label' => TikTokCatalog::renderLabel($status),
            'note' => TikTokCatalog::renderNote($render),
            'ready' => (bool) ($render['ready'] ?? false),
            'provider' => (string) ($render['provider'] ?? 'pending'),
            'ai_generated' => (bool) ($render['ai_generated'] ?? false),
            'can_regenerate' => in_array($this->status, [TikTokCatalog::WAITING, TikTokCatalog::QUEUED], true)
                && in_array($status, [TikTokCatalog::RENDER_FAILED, TikTokCatalog::RENDER_READY], true)
                && $regenerations < TikTokVideoCatalog::MAX_REGENERATIONS,
        ];
    }

    /**
     * @return array{filename: string, size: int, duration_seconds: int|null, width: int|null, height: int|null, warning: string|null}|null
     */
    private function videoPayload(): ?array
    {
        $video = $this->video;
        if (! $video || ! $video->ready) {
            return null;
        }

        return [
            'filename' => $video->original_filename,
            'size' => (int) $video->size,
            'duration_seconds' => $video->duration_seconds,
            'width' => $video->width,
            'height' => $video->height,
            'warning' => $video->aspect_warning,
        ];
    }
}
