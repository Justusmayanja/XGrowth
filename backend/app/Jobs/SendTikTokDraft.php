<?php

namespace App\Jobs;

use App\Models\TikTokClip;
use App\Services\TikTok\TikTokDraftService;
use App\Support\TikTokCatalog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendTikTokDraft implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(public int $clipId) {}

    public function uniqueId(): string
    {
        return (string) $this->clipId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 30, 60];
    }

    public function handle(TikTokDraftService $drafts): void
    {
        $drafts->send($this->clipId);
    }

    public function failed(?Throwable $exception): void
    {
        $clip = TikTokClip::query()->find($this->clipId);
        if (! $clip || $clip->status !== TikTokCatalog::UPLOADING) {
            return;
        }

        $clip->transitionTo(TikTokCatalog::UPLOAD_FAILED);
        $clip->upload_error = 'The send stopped. You can try again.';
        $clip->save();
    }
}
