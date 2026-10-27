<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\YouTubeSeries;
use App\Models\YouTubeVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class YouTubeEngineTest extends TestCase
{
    use RefreshDatabase;

    private function about(): array
    {
        return [
            'purpose' => 'creator',
            'custom_niche' => 'Budget home cooking',
            'description' => 'I cook cheap weeknight meals and share the shortcuts that actually finish.',
            'audience' => 'Students cooking for one',
            'tone' => ['funny'],
        ];
    }

    private function start(array $youtube): User
    {
        config(['openai.api_key' => '']);
        $user = User::factory()->create();
        $api = $this->actingAs($user, 'sanctum');
        $api->postJson('/api/account/setup/platforms', ['platforms' => ['youtube']])->assertOk();
        $api->postJson('/api/account/setup/about', $this->about())->assertOk();
        $api->postJson('/api/account/setup/platforms/youtube', $youtube)->assertOk();

        return $user;
    }

    private function listings(User $user): void
    {
        $this->actingAs($user, 'sanctum')->postJson('/api/youtube/listings', [
            'videos' => [
                ['channel' => 'Weeknight', 'title' => 'Cheap weeknight soup', 'views' => 1000],
                ['channel' => 'Weeknight', 'title' => 'Another cheap weeknight', 'views' => 1200],
                ['channel' => 'Weeknight', 'title' => 'Weeknight notes', 'views' => 800],
                ['channel' => 'Weeknight', 'title' => 'How do cheap weeknight meals finish?', 'views' => 9000],
                ['channel' => 'Coins', 'title' => 'Bitcoin morning note', 'views' => 100],
                ['channel' => 'Coins', 'title' => 'Bitcoin noon note', 'views' => 100],
                ['channel' => 'Coins', 'title' => 'Bitcoin night note', 'views' => 100],
                ['channel' => 'Coins', 'title' => 'Bitcoin doubles overnight', 'views' => 5000],
                ['channel' => 'Thin', 'title' => 'Cheap weeknight once', 'views' => 50],
                ['channel' => 'Thin', 'title' => 'Cheap weeknight twice', 'views' => 40],
            ],
        ])->assertOk();
    }

    public function test_public_listings_replace_template_ideas_and_the_piece_is_fully_packaged(): void
    {
        $user = $this->start([
            'goals' => ['subscribers', 'search'],
            'publish' => 'auto',
            'video_mode' => 'short',
            'format' => 'text_on_screen',
        ]);

        $this->assertSame(0, YouTubeVideo::query()->where('user_id', $user->id)->count());

        $empty = $this->actingAs($user, 'sanctum')->getJson('/api/dashboard/platform/youtube')->assertOk();
        $empty->assertJsonCount(0, 'niche.working');
        $empty->assertJsonCount(0, 'week');
        $this->assertStringContainsString('Public listings', (string) $empty->json('niche.note'));

        $this->listings($user);

        $video = YouTubeVideo::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($video);
        $this->assertSame('queued', $video->status);
        $this->assertSame('source', $video->package['role']);
        $this->assertLessThanOrEqual(60, mb_strlen((string) $video->title));
        $this->assertSame(5, $video->package['titles_written']);
        $this->assertSame(5, $video->package['hooks_written']);
        $this->assertCount(2, $video->package['hooks']);
        $this->assertNotEmpty($video->package['hooks'][0]['score']);
        $this->assertNotSame($video->package['thumbnail'], $video->package['on_screen'][0]['text']);
        $this->assertLessThanOrEqual(3, str_word_count($video->package['thumbnail']));
        $this->assertTrue($video->package['chapters_valid']);
        $this->assertSame('0:00', $video->package['chapters'][0]['label']);
        $this->assertTrue($video->package['search_facing']);
        $this->assertSame([], $video->package['shots']);
        $this->assertFalse($video->package['render']['ready']);
        $this->assertStringContainsString('[ON SCREEN:', (string) $video->script);
        $this->assertStringContainsString('This video is delivering', (string) $video->script);
        $this->assertStringNotContainsString('before we get started', strtolower((string) $video->script));
        $this->assertStringNotContainsString('expensive version', strtolower((string) $video->script));
        $this->assertStringNotContainsString('Made for', (string) $video->description);
        $this->assertSame('Ship it, or change it?', $video->package['gate']);

        $desk = $this->actingAs($user, 'sanctum')->getJson('/api/dashboard/platform/youtube')->assertOk();
        $working = collect($desk->json('niche.working'))->pluck('title');
        $this->assertTrue($working->contains(fn ($title) => str_contains((string) $title, 'Bitcoin')));
        $this->assertTrue($working->contains(fn ($title) => str_contains((string) $title, 'weeknight')));
        foreach ($desk->json('week') as $idea) {
            $this->assertStringNotContainsString('Bitcoin', (string) $idea['topic']);
        }
        $this->assertNotEmpty($desk->json('niche.shared'));
        $this->assertNotEmpty($desk->json('audit.fix'));
    }

    public function test_average_view_percent_does_not_choose_the_opening_and_a_hook_leak_does(): void
    {
        $percentUser = $this->start([
            'goals' => ['subscribers', 'search'],
            'publish' => 'auto',
            'video_mode' => 'short',
            'format' => 'text_on_screen',
        ]);
        $this->listings($percentUser);
        $first = YouTubeVideo::query()->where('user_id', $percentUser->id)->first();

        $saved = $this->actingAs($percentUser, 'sanctum')
            ->postJson('/api/youtube/videos/'.$first->id.'/results', [
                'views' => 1200,
                'average_view_percent' => 22,
            ])
            ->assertOk();

        $first->refresh();
        $this->assertSame('unshaped', $first->package['model_learning']['case']);
        $this->assertFalse($first->package['model_learning']['change_opening']);
        $this->assertNull($first->learning_note);
        $this->assertStringNotContainsString('hook_leak', $saved->getContent());
        $this->assertStringNotContainsString('left early', $saved->getContent());
        $this->assertStringNotContainsString('change_opening', $saved->getContent());
        $this->assertStringNotContainsString('model_learning', $saved->getContent());

        $second = YouTubeVideo::query()->where('user_id', $percentUser->id)->where('id', '!=', $first->id)->first();
        $this->assertNotNull($second);
        $this->assertStringNotContainsString('Start on the result.', (string) $second->script);

        $kept = $this->start([
            'goals' => ['watch_time'],
            'publish' => 'auto',
            'video_mode' => 'short',
            'format' => 'faceless_voiceover',
        ]);
        $this->listings($kept);
        $held = YouTubeVideo::query()->where('user_id', $kept->id)->first();
        $this->actingAs($kept, 'sanctum')
            ->postJson('/api/youtube/videos/'.$held->id.'/results', [
                'views' => 800,
                'average_view_percent' => 61,
            ])
            ->assertOk();
        $held->refresh();
        $this->assertSame('unshaped', $held->package['model_learning']['case']);
        $this->assertFalse($held->package['model_learning']['change_opening']);
        $follow = YouTubeVideo::query()->where('user_id', $kept->id)->where('id', '!=', $held->id)->first();
        $this->assertStringNotContainsString('Start on the result.', (string) $follow->script);

        $leakUser = $this->start([
            'goals' => ['search'],
            'publish' => 'auto',
            'video_mode' => 'short',
            'format' => 'text_on_screen',
        ]);
        $this->listings($leakUser);
        $source = YouTubeVideo::query()->where('user_id', $leakUser->id)->first();
        $csv = implode("\n", ['0,100', '5,90', '10,80', '15,75', '20,72', '25,70', '30,60', '45,59', '90,58', '120,57']);
        $hidden = $this->actingAs($leakUser, 'sanctum')
            ->postJson('/api/youtube/videos/'.$source->id.'/retention', ['csv' => $csv])
            ->assertOk();

        $source->refresh();
        $this->assertSame('hook_leak', $source->package['model_learning']['case']);
        $this->assertTrue($source->package['model_learning']['change_opening']);
        $this->assertStringNotContainsString('hook_leak', $hidden->getContent());
        $this->assertStringNotContainsString('model_learning', $hidden->getContent());

        $next = YouTubeVideo::query()->where('user_id', $leakUser->id)->where('id', '!=', $source->id)->first();
        $this->assertStringContainsString('Start on the result.', (string) $next->script);
    }

    public function test_waiting_videos_post_only_after_a_yes(): void
    {
        $user = $this->start([
            'goals' => ['watch_time'],
            'publish' => 'wait',
            'video_mode' => 'short',
            'format' => 'faceless_voiceover',
        ]);
        $this->listings($user);

        $video = YouTubeVideo::query()->where('user_id', $user->id)->first();
        $this->assertSame('waiting', $video->status);
        $this->assertCount(2, $video->package['shots']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/confirm')
            ->assertOk();

        $this->assertSame('queued', $video->fresh()->status);
    }

    public function test_a_series_uses_the_premise_and_holds_the_next_episode_for_a_week(): void
    {
        $user = $this->start([
            'goals' => ['watch_time', 'subscribers'],
            'publish' => 'auto',
            'video_mode' => 'series',
            'format' => 'faceless_voiceover',
        ]);

        $episode = YouTubeVideo::query()->where('user_id', $user->id)->first();
        $series = YouTubeSeries::query()->where('user_id', $user->id)->first();
        $this->assertSame(1, $episode->episode_number);
        $this->assertStringContainsString('weeknight', strtolower((string) $episode->script));
        $this->assertStringNotContainsString('EPISODE 1', (string) $episode->script);
        $this->assertDoesNotMatchRegularExpression('/^episode\s+\d+$/i', (string) $episode->package['thumbnail']);
        $this->assertCount(2, $episode->package['hooks']);
        $this->assertLessThanOrEqual(60, mb_strlen((string) $episode->title));
        $this->assertStringStartsWith('For the people who already watch.', (string) $episode->description);
        $this->assertStringContainsString('weeknight', strtolower((string) $series->premise));
        $this->assertStringNotContainsString('one episode at a time', (string) $series->title);

        $this->actingAs($user, 'sanctum')->postJson('/api/youtube/run')->assertOk()->assertJsonCount(1, 'pipeline');

        $saved = $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$episode->id.'/results', [
                'views' => 800,
                'average_view_percent' => 61,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'pipeline');

        $this->assertStringNotContainsString('keeps this shape', $saved->getContent());
        $this->assertSame(1, YouTubeVideo::query()->where('user_id', $user->id)->count());

        try {
            $this->travel(7)->days();
            $this->actingAs($user, 'sanctum')->postJson('/api/youtube/run')->assertOk();
        } finally {
            $this->travelBack();
        }

        $this->assertSame(2, YouTubeVideo::query()->where('user_id', $user->id)->count());
        $this->assertSame(2, YouTubeVideo::query()->where('user_id', $user->id)->max('episode_number'));
    }

    public function test_shorts_are_cut_from_a_transcript_and_comments_feed_the_next_week(): void
    {
        $user = $this->start([
            'goals' => ['search'],
            'publish' => 'wait',
            'video_mode' => 'short',
            'format' => 'text_on_screen',
        ]);
        $this->listings($user);
        $video = YouTubeVideo::query()->where('user_id', $user->id)->first();

        $transcript = <<<'TXT'
0:00 Cheap weeknight meals finish when the shortcut is the meal itself.
0:08 The shop is the part that feels like work.
0:16 But the pan you already own is the part that finishes.
0:28 That is the shortcut, and the plate is the proof.
0:40 A second sitting is the same pan, not a new plan.
0:52 The line to remember is the meal finishes in the pan you have.
1:00 The leftovers are the part people skip.
1:10 But a second night uses the same pan.
1:22 That second night is the proof the shortcut holds.
1:36 The meal finishes again.
TXT;

        $withShorts = $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/transcript', ['transcript' => $transcript])
            ->assertOk();

        $shorts = $withShorts->json('pipeline.0.package.shorts');
        $this->assertNotEmpty($shorts);
        $this->assertArrayHasKey('start', $shorts[0]);
        $this->assertArrayHasKey('first_line', $shorts[0]);
        $this->assertNotSame($shorts[0]['first_line'], $shorts[0]['opening']['text']);
        $this->assertArrayHasKey('score', $shorts[0]['opening']);
        $this->assertNotSame('', $shorts[0]['on_screen']);
        $this->assertStringNotContainsString(strtolower($shorts[0]['on_screen']), strtolower($shorts[0]['opening']['text']));
        $this->assertStringContainsString('loops', $shorts[0]['loop']);
        $this->assertStringContainsString('vertical', strtolower($shorts[0]['crop']));

        $comments = <<<'TXT'
How do cheap weeknight meals finish?
How do cheap weeknight meals actually finish?
Actually you said that is the shortcut this channel already claims.
Actually the oven should be four hundred degrees.
This was helpful, the shortcut is clear.
This is stupid and nobody cares.
TXT;

        $replied = $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/comments', ['comments' => $comments])
            ->assertOk();

        $pile = $replied->json('pipeline.0.package.comments');
        $this->assertSame(2, $pile['counts']['questions']);
        $this->assertSame(2, $pile['counts']['corrections']);
        $this->assertSame(1, $pile['counts']['praise']);
        $this->assertSame(1, $pile['counts']['bait']);
        $replies = collect($pile['replies']);
        $this->assertTrue($replies->contains(fn (array $reply) => $reply['pile'] === 'corrections' && str_contains($reply['reply'], 'right')));
        $this->assertFalse($replies->contains(fn (array $reply) => str_contains(strtolower($reply['comment']), 'oven')));
        $this->assertFalse($replies->contains(fn (array $reply) => str_contains(strtolower($reply['comment']), 'stupid')));
        $this->assertNotNull($pile['pin']);
        foreach ($replies as $reply) {
            $this->assertLessThanOrEqual(30, str_word_count($reply['reply']));
            $this->assertStringNotContainsString('next video', strtolower($reply['reply']));
        }

        $week = collect($replied->json('week'));
        $this->assertTrue($week->contains(fn (array $idea) => ($idea['source'] ?? '') === 'comment'));
    }

    public function test_uploaded_footage_stays_hidden_until_a_transcript_is_supplied(): void
    {
        Storage::fake('local');
        $user = $this->start([
            'goals' => ['subscribers'],
            'publish' => 'wait',
            'video_mode' => 'footage',
        ]);

        $this->assertSame(0, YouTubeVideo::query()->where('user_id', $user->id)->count());

        $uploaded = $this->actingAs($user, 'sanctum')
            ->post('/api/youtube/footage', [
                'footage' => UploadedFile::fake()->create('dinner.mp4', 200, 'video/mp4'),
                'note' => 'Start with the onion. Then the cheap cut of chicken. Stop when it simmers.',
            ])
            ->assertOk()
            ->assertJsonPath('pipeline.0.status', 'awaiting_edit');

        $this->assertArrayNotHasKey('edit', $uploaded->json('pipeline.0.package'));
        $this->assertFalse($uploaded->json('pipeline.0.package.has_transcript'));

        $video = YouTubeVideo::query()->where('user_id', $user->id)->first();
        $this->assertCount(3, $video->package['edit']['zooms']);
        $this->assertLessThanOrEqual(60, mb_strlen((string) $video->title));
        Storage::disk('local')->assertExists($video->source_path);

        $transcript = <<<'TXT'
0:00 Cheap weeknight meals finish when the shortcut is the meal itself.
0:08 The shop is the part that feels like work.
0:16 But the pan you already own is the part that finishes.
0:28 That is the shortcut, and the plate is the proof.
0:40 A second sitting is the same pan, not a new plan.
TXT;

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/transcript', ['transcript' => $transcript])
            ->assertOk()
            ->assertJsonPath('pipeline.0.package.has_transcript', true)
            ->assertJsonPath('pipeline.0.package.edit.color.preset', 'clean')
            ->assertJsonPath('pipeline.0.package.edit.silence.trim_over_seconds', 0.6);

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/results', [
                'views' => 1,
                'average_view_percent' => 10,
            ])
            ->assertNotFound();
    }

    public function test_footage_upload_is_refused_when_the_channel_is_set_to_shorts(): void
    {
        Storage::fake('local');
        $user = $this->start([
            'goals' => ['subscribers'],
            'publish' => 'wait',
            'video_mode' => 'short',
            'format' => 'scene',
        ]);

        $this->actingAs($user, 'sanctum')
            ->post('/api/youtube/footage', [
                'footage' => UploadedFile::fake()->create('dinner.mp4', 200, 'video/mp4'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('footage');
    }
}
