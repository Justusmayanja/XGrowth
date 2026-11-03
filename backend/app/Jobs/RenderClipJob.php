<?php

namespace App\Jobs;

use App\Models\TikTokClip;
use App\Services\TikTok\TikTokRenderService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RenderClipJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(public int $clipId)
    {
        $this->onConnection('renders');
        $this->onQueue('renders');
    }

    public function uniqueId(): string
    {
        return (string) $this->clipId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(TikTokRenderService $renders): void
    {
        $clip = TikTokClip::query()->find($this->clipId);
        if (! $clip) {
            return;
        }
        $renders->render($clip);
    }
}
