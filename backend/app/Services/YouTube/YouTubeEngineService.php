<?php

namespace App\Services\YouTube;

use App\Models\AccountProfile;
use App\Models\PlatformSetup;
use App\Models\User;
use App\Models\YouTubeSeries;
use App\Models\YouTubeVideo;
use App\Support\YouTubeCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class YouTubeEngineService
{
    public function __construct(
        private readonly YouTubeDemandService $demand,
        private readonly YouTubeScriptWriter $scripts,
        private readonly YouTubeEditPlanner $edits,
    ) {}

    public function runAll(): int
    {
        $count = 0;

        User::query()
            ->where('is_blocked', false)
            ->whereHas('accountProfile', fn ($query) => $query->where('onboarding_completed', true))
            ->whereHas('platformSetups', fn ($query) => $query
                ->where('platform', 'youtube')
                ->whereNotNull('completed_at'))
            ->each(function (User $user) use (&$count) {
                $this->run($user);
                $count++;
            });

        return $count;
    }

    public function run(User $user): ?YouTubeVideo
    {
        $lock = 'youtube-run-'.$user->id;
        if (! Cache::add($lock, 1, now()->addSeconds(30))) {
            return YouTubeVideo::query()->where('user_id', $user->id)->latest('id')->first();
        }

        try {
            return $this->runUnlocked($user);
        } finally {
            Cache::forget($lock);
        }
    }

    public function confirm(YouTubeVideo $video): YouTubeVideo
    {
        if ($video->status !== 'waiting') {
            throw ValidationException::withMessages([
                'status' => ['This video is not waiting for a yes.'],
            ]);
        }

        $package = $video->package ?? [];
        $package['publish'] = [
            'mode' => 'auto',
            'note' => 'You said yes. The upload waits for the video file and the YouTube connection.',
        ];
        $video->update([
            'status' => 'queued',
            'queued_at' => now(),
            'package' => $package,
        ]);

        return $video->fresh();
    }

    public function recordResults(YouTubeVideo $video, int $views, float $percent): YouTubeVideo
    {
        if (! in_array($video->status, ['waiting', 'queued'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Results can be saved once the video is ready to post.'],
            ]);
        }

        $note = match (true) {
            $percent >= 50 => 'People stayed. The next piece keeps this shape.',
            $percent < 30 => 'People left early. The next piece uses a different opening.',
            default => 'Recorded. The next search takes this into account.',
        };

        $package = $video->package ?? [];
        $package['learning'] = [
            'views' => $views,
            'average_view_percent' => $percent,
            'note' => $note,
        ];

        $video->update([
            'views' => $views,
            'average_view_percent' => $percent,
            'learning_note' => $note,
            'learned_at' => now(),
            'status' => 'learned',
            'package' => $package,
        ]);

        $this->run($video->user);

        return $video->fresh();
    }

    public function storeFootage(User $user, UploadedFile $file, ?string $note): YouTubeVideo
    {
        $setup = $this->setup($user);
        $profile = $user->accountProfile;
        if (! $setup?->completed_at || ! $profile) {
            throw ValidationException::withMessages([
                'footage' => ['Finish the YouTube setup before uploading footage.'],
            ]);
        }

        $mode = $setup->settings['video_mode'] ?? null;
        if ($mode !== 'footage') {
            $label = is_string($mode) ? (YouTubeCatalog::MODE_LABELS[$mode] ?? 'another format') : 'another format';
            throw ValidationException::withMessages([
                'footage' => ["This channel is set up for {$label}. Change YouTube to edit footage first."],
            ]);
        }

        $open = YouTubeVideo::query()
            ->where('user_id', $user->id)
            ->where('mode', 'footage')
            ->where('status', 'awaiting_edit')
            ->count();
        if ($open >= 5) {
            throw ValidationException::withMessages([
                'footage' => ['Five edits are already waiting. Finish those before uploading another.'],
            ]);
        }

        $path = $file->store('youtube/'.$user->id, 'local');
        $plan = $this->edits->plan($profile, $file->getClientOriginalName(), $note);

        return YouTubeVideo::query()->create([
            'user_id' => $user->id,
            'mode' => 'footage',
            'format' => null,
            'status' => 'awaiting_edit',
            'topic' => $plan['title'],
            'title' => $plan['title'],
            'script' => $note,
            'description' => $plan['description'],
            'package' => [
                'edit' => $plan,
                'render' => $plan['render'],
                'publish' => [
                    'mode' => $setup->settings['publish'] ?? 'wait',
                    'note' => 'The upload waits for the edited file and the YouTube connection.',
                ],
            ],
            'source_path' => $path,
            'source_name' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * @return array{ideas: list<array{name: string, suggestion: string}>, pipeline: list<array<string, mixed>>, series: array<string, mixed>|null}
     */
    public function desk(User $user): array
    {
        $setup = $this->setup($user);
        if (! $setup) {
            return ['ideas' => [], 'pipeline' => [], 'series' => null];
        }

        $profile = $user->accountProfile;
        $settings = is_array($setup->settings) ? $setup->settings : [];
        $mode = $settings['video_mode'] ?? 'short';
        $format = $settings['format'] ?? 'text_on_screen';

        $ideas = [];
        if ($profile && $mode !== 'footage') {
            $ideas = array_map(fn (array $idea) => [
                'name' => $idea['topic'],
                'suggestion' => $idea['reason'],
            ], $this->demand->ranked($profile, is_string($format) ? $format : 'text_on_screen', $user, 3));
        }

        $pipeline = YouTubeVideo::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (YouTubeVideo $video) => $video->toDesk())
            ->all();

        $series = YouTubeSeries::query()->where('user_id', $user->id)->latest('id')->first();
        $holdsUntil = null;
        if ($series) {
            $latest = YouTubeVideo::query()
                ->where('user_id', $user->id)
                ->where('mode', 'series')
                ->latest('id')
                ->first();
            if ($latest?->learned_at && $latest->learned_at->gt(now()->subDays(6))) {
                $holdsUntil = $latest->learned_at->copy()->addDays(6)->toDateString();
            }
        }

        return [
            'ideas' => $ideas,
            'pipeline' => $pipeline,
            'series' => $series?->toDesk($holdsUntil),
        ];
    }

    private function runUnlocked(User $user): ?YouTubeVideo
    {
        $user->loadMissing('accountProfile');
        $setup = $this->setup($user);
        $profile = $user->accountProfile;
        if (! $setup?->completed_at || ! $profile) {
            return null;
        }

        $mode = $setup->settings['video_mode'] ?? null;
        if (! in_array($mode, ['short', 'series'], true)) {
            return null;
        }

        $open = YouTubeVideo::query()
            ->where('user_id', $user->id)
            ->where('mode', $mode)
            ->whereIn('status', ['waiting', 'queued', 'awaiting_edit'])
            ->latest('id')
            ->first();
        if ($open) {
            return $open;
        }

        $last = YouTubeVideo::query()
            ->where('user_id', $user->id)
            ->where('mode', $mode)
            ->latest('id')
            ->first();

        if ($last && $last->learned_at === null) {
            return $last;
        }

        if ($mode === 'series' && $last?->learned_at && $last->learned_at->gt(now()->subDays(6))) {
            return $last;
        }

        return $mode === 'series'
            ? $this->makeEpisode($user, $profile, $setup, $last)
            : $this->makeShort($user, $profile, $setup, $last);
    }

    private function makeShort(User $user, AccountProfile $profile, PlatformSetup $setup, ?YouTubeVideo $last): ?YouTubeVideo
    {
        $format = $setup->settings['format'] ?? 'text_on_screen';
        if (! in_array($format, YouTubeCatalog::FORMATS, true)) {
            return null;
        }

        $ranked = $this->demand->ranked($profile, $format, $user, 6);
        $idea = $ranked[0] ?? null;
        if ($idea === null) {
            return null;
        }

        $script = $this->scripts->short($user, $profile, $idea, $format, $last?->learning_note);
        $publish = ($setup->settings['publish'] ?? 'wait') === 'auto' ? 'auto' : 'wait';

        return $this->storePackage($user, null, 'short', $format, $idea['topic'], $idea['score'], null, $script, $publish, $format === 'text_on_screen' ? 'text' : 'clips');
    }

    private function makeEpisode(User $user, AccountProfile $profile, PlatformSetup $setup, ?YouTubeVideo $last): YouTubeVideo
    {
        $series = YouTubeSeries::query()->where('user_id', $user->id)->where('status', 'active')->latest('id')->first()
            ?? $this->createSeries($user, $profile);

        $outline = $series->outline ?? [];
        $number = max(1, (int) $series->next_episode);
        $episode = $outline[$number - 1] ?? null;
        if (! is_array($episode)) {
            $episode = [
                'number' => $number,
                'title' => 'What happens after episode '.($number - 1),
                'beat' => 'The last episode left a problem. This one has to answer it.',
            ];
            $outline[] = $episode;
            $series->outline = $outline;
        }

        $format = $setup->settings['format'] ?? 'faceless_voiceover';
        if (! in_array($format, YouTubeCatalog::FORMATS, true)) {
            $format = 'faceless_voiceover';
        }

        $script = $this->scripts->episode(
            $user,
            $profile,
            $series->premise,
            [
                'number' => $number,
                'title' => (string) ($episode['title'] ?? 'Episode '.$number),
                'beat' => (string) ($episode['beat'] ?? ''),
            ],
            $series->characters ?? [],
            $last?->learning_note,
        );

        $series->update([
            'next_episode' => $number + 1,
            'outline' => $series->outline,
        ]);

        $publish = ($setup->settings['publish'] ?? 'wait') === 'auto' ? 'auto' : 'wait';

        return $this->storePackage($user, $series, 'series', $format, (string) $episode['title'], 70, $number, $script, $publish, 'narration');
    }

    /**
     * @param  array{title: string, hook: string, spoken: string, description: string, thumbnail: string, on_screen: list<array{text: string, at: string}>, shots: list<array{prompt: string, seconds: int, kind: string}>, kind: string}  $script
     */
    private function storePackage(
        User $user,
        ?YouTubeSeries $series,
        string $mode,
        string $format,
        string $topic,
        float $score,
        ?int $episodeNumber,
        array $script,
        string $publish,
        string $renderKind,
    ): YouTubeVideo {
        $status = $publish === 'auto' ? 'queued' : 'waiting';
        $renderNote = match ($renderKind) {
            'text' => 'The lines and timings are set. The video file is made when the render step is connected.',
            'narration' => 'The episode is scripted. Voice and stills are made when the render step is connected.',
            default => 'The shots are written. The video file is made when the video model is connected.',
        };

        return YouTubeVideo::query()->create([
            'user_id' => $user->id,
            'youtube_series_id' => $series?->id,
            'mode' => $mode,
            'format' => $format,
            'status' => $status,
            'topic' => Str::limit($topic, 180, ''),
            'title' => $script['title'],
            'script' => $script['spoken'],
            'description' => $script['description'],
            'score' => $score,
            'episode_number' => $episodeNumber,
            'queued_at' => $status === 'queued' ? now() : null,
            'package' => [
                'kind' => $script['kind'],
                'hook' => $script['hook'],
                'thumbnail' => $script['thumbnail'],
                'on_screen' => $script['on_screen'],
                'shots' => $script['shots'],
                'render' => [
                    'provider' => 'pending',
                    'ready' => false,
                    'kind' => $renderKind,
                    'note' => $renderNote,
                ],
                'publish' => [
                    'mode' => $publish,
                    'note' => $status === 'queued'
                        ? 'Set to post on its own. The upload waits for the video file and the YouTube connection.'
                        : 'Waiting for your yes. The upload still needs the video file and the YouTube connection.',
                ],
            ],
        ]);
    }

    private function createSeries(User $user, AccountProfile $profile): YouTubeSeries
    {
        $niche = $profile->resolvedNiche();
        $audience = trim((string) $profile->audience) ?: 'the people this account is for';

        return YouTubeSeries::query()->create([
            'user_id' => $user->id,
            'title' => Str::limit($niche.', one episode at a time', 120, ''),
            'premise' => trim((string) $profile->description) ?: "A series about {$niche} for {$audience}.",
            'characters' => [
                ['name' => 'The host', 'role' => "Speaks in this account's voice about {$niche}."],
                ['name' => 'The viewer', 'role' => "Stands in for {$audience}."],
            ],
            'outline' => [
                ['number' => 1, 'title' => "Starting {$niche} in the open", 'beat' => 'The host begins with almost nothing and one clear aim.'],
                ['number' => 2, 'title' => 'The first thing that breaks', 'beat' => 'The easy version fails, and the reason is specific.'],
                ['number' => 3, 'title' => 'A smaller way through', 'beat' => 'They cut the plan down until it can be finished.'],
                ['number' => 4, 'title' => 'Someone else tries it', 'beat' => 'The viewer copies the step and hits a different wall.'],
                ['number' => 5, 'title' => 'What they keep', 'beat' => 'One habit stays. The rest is dropped on purpose.'],
                ['number' => 6, 'title' => 'The result, and the next problem', 'beat' => 'The aim from episode one is met, and a larger aim appears.'],
            ],
            'next_episode' => 1,
            'status' => 'active',
        ]);
    }

    private function setup(User $user): ?PlatformSetup
    {
        return PlatformSetup::query()
            ->where('user_id', $user->id)
            ->where('platform', 'youtube')
            ->first();
    }
}
