<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\ConnectedAccount;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\Content\PostVisualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class PostVisualTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_picture_waits_until_four_text_posts(): void
    {
        $user = User::factory()->create();
        $visuals = app(PostVisualService::class);

        $this->assertFalse($visuals->due($user, null));

        foreach (range(1, 4) as $n) {
            $user->posts()->create([
                'content' => "Text post {$n}",
                'content_type' => 'tweet',
                'status' => 'posted',
            ]);
        }

        $this->assertTrue($visuals->due($user, null));

        $user->posts()->create([
            'content' => 'With a picture',
            'content_type' => 'tweet',
            'status' => 'posted',
            'media_path' => 'post-visuals/1/already.png',
        ]);

        $this->assertFalse($visuals->due($user, null));
    }

    public function test_the_picture_line_is_a_brief_about_the_image_not_the_post(): void
    {
        $user = User::factory()->create();
        $profile = AccountProfile::create([
            'user_id' => $user->id,
            'description' => 'Cheap weeknight meals for one, with honest shortcuts.',
            'custom_niche' => 'Budget home cooking',
        ]);

        $tweet = 'The meal deal is the expensive option.';
        $line = app(PostVisualService::class)->overlayLine($tweet, $profile);

        $this->assertSame('Cheap weeknight meals', $line);
        $this->assertNotSame($tweet, $line);
        $this->assertLessThanOrEqual(4, count(preg_split('/\s+/', $line) ?: []));

        $nicheOnly = AccountProfile::create([
            'user_id' => User::factory()->create()->id,
            'description' => $tweet,
            'custom_niche' => 'Budget home cooking',
        ]);
        $this->assertSame(
            'Budget home cooking',
            app(PostVisualService::class)->overlayLine($tweet, $nicheOnly),
        );
    }

    public function test_a_flyer_is_a_png_drawn_from_the_account(): void
    {
        $user = User::factory()->create();
        $profile = AccountProfile::create([
            'user_id' => $user->id,
            'description' => 'Cheap weeknight meals for one',
            'audience' => 'Students cooking on a budget',
            'custom_niche' => 'home cooking',
        ]);

        $made = app(PostVisualService::class)->make(
            $user,
            $profile,
            'The meal deal is the expensive option.',
        );

        $this->assertNotNull($made);
        $this->assertSame('flyer', $made['kind']);
        $this->assertTrue(Storage::disk('local')->exists($made['path']));
        $bytes = Storage::disk('local')->get($made['path']);
        $this->assertIsString($bytes);
        $this->assertStringStartsWith("\x89PNG", $bytes);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/content/drafts', [
                'content' => 'Rice, an egg, and soy sauce.',
                'content_type' => 'tweet',
                'with_visual' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('media_attached', true);

        $post = $user->posts()->first();
        $this->assertNotNull($post?->media_path);

        $this->actingAs($user, 'sanctum')
            ->get("/api/content/posts/{$post->id}/media")
            ->assertOk();

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')
            ->get("/api/content/posts/{$post->id}/media")
            ->assertForbidden();

        Storage::disk('local')->deleteDirectory('post-visuals/'.$user->id);
    }

    public function test_text_only_does_not_attach_a_picture(): void
    {
        $user = User::factory()->create();
        AccountProfile::create([
            'user_id' => $user->id,
            'description' => 'Cheap weeknight meals for one',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/content/drafts', [
                'content' => 'Rice, an egg, and soy sauce.',
                'content_type' => 'tweet',
                'with_visual' => true,
                'visual_kind' => 'text',
            ])
            ->assertCreated()
            ->assertJsonPath('media_attached', false);

        $this->assertNull($user->posts()->first()?->media_path);
    }

    public function test_a_chosen_flyer_is_not_swapped_for_an_illustration(): void
    {
        $user = User::factory()->create();
        $profile = AccountProfile::create([
            'user_id' => $user->id,
            'description' => 'Cheap weeknight meals for one',
        ]);

        $made = app(PostVisualService::class)->make($user, $profile, 'Dinner for 40p.', 'flyer');

        $this->assertNotNull($made);
        $this->assertSame('flyer', $made['kind']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/content/drafts', [
                'content' => 'Dinner for 40p.',
                'content_type' => 'tweet',
                'visual_kind' => 'flyer',
            ])
            ->assertCreated()
            ->assertJsonPath('media_attached', true);

        Storage::disk('local')->deleteDirectory('post-visuals/'.$user->id);
    }

    public function test_an_illustration_is_not_replaced_with_a_flyer_when_none_is_made(): void
    {
        $user = User::factory()->create();
        $profile = AccountProfile::create([
            'user_id' => $user->id,
            'description' => 'Cheap weeknight meals for one',
        ]);

        $this->assertNull(app(PostVisualService::class)->make($user, $profile, 'Dinner for 40p.', 'illustration'));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/content/drafts', [
                'content' => 'Dinner for 40p.',
                'content_type' => 'tweet',
                'visual_kind' => 'illustration',
            ])
            ->assertCreated()
            ->assertJsonPath('media_attached', false);
    }

    public function test_a_picture_is_attached_when_x_accepts_it_and_the_words_still_post_when_it_does_not(): void
    {
        config([
            'services.x.client_id' => 'client',
            'services.x.client_secret' => 'secret',
        ]);

        $user = User::factory()->create();
        AccountProfile::create([
            'user_id' => $user->id,
            'description' => 'Cheap weeknight meals for one',
        ]);
        ConnectedAccount::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'platform_account_id' => '42',
            'username' => 'mackie',
            'access_token' => 'user-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $made = app(PostVisualService::class)->make($user, $user->accountProfile, 'Dinner for 40p.');
        $this->assertNotNull($made);

        $draft = $user->posts()->create([
            'content' => 'Dinner for 40p.',
            'content_type' => 'tweet',
            'status' => 'draft',
            'media_path' => $made['path'],
        ]);

        $uploads = 0;
        Http::fake(function ($request) use (&$uploads) {
            if (str_contains($request->url(), '/2/media/upload')) {
                $uploads++;

                return $uploads === 1
                    ? Http::response(['data' => ['id' => '555']], 200)
                    : Http::response(['detail' => 'no'], 403);
            }

            if (str_contains($request->url(), '/2/tweets')) {
                $id = isset($request['media']) ? '99' : '100';

                return Http::response(['data' => ['id' => $id]], 201);
            }

            return Http::response(['detail' => 'unexpected'], 404);
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/content/drafts/{$draft->id}/publish")
            ->assertOk()
            ->assertJsonPath('post.platform_post_id', '99')
            ->assertJsonMissingPath('media_warning');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/2/tweets')
                && ($request['media']['media_ids'][0] ?? null) === '555';
        });

        $second = $user->posts()->create([
            'content' => 'Still the words.',
            'content_type' => 'tweet',
            'status' => 'draft',
            'media_path' => $made['path'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/content/drafts/{$second->id}/publish")
            ->assertOk()
            ->assertJsonPath('post.platform_post_id', '100')
            ->assertJsonPath('media_warning', 'Posted the words. Reconnect X so the picture can go with them.');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/2/tweets')
                && $request['text'] === 'Still the words.'
                && ! isset($request['media']);
        });

        Storage::disk('local')->deleteDirectory('post-visuals/'.$user->id);
    }

    public function test_a_flyer_is_returned_with_the_drafts_and_saved_with_that_picture(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->disableWebSearch();
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['variants' => [[
                        'content' => 'Rice, eggs, and soy sauce. Dinner for 40p.',
                        'angle' => 'Cheapest meal of the week',
                        'virality_score' => 80,
                        'estimated_reach' => 'high',
                    ]]])],
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        AccountProfile::create([
            'user_id' => $user->id,
            'purpose' => 'side_hustle',
            'platforms' => 'x',
            'goal' => 'monetize,grow_followers',
            'custom_niche' => 'Budget home cooking',
            'description' => 'Cheap weeknight meals for one, with honest shortcuts.',
            'audience' => 'Students cooking on a budget',
            'tone' => 'funny',
            'onboarding_completed' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/content/generate', [
                'mode' => 'describe',
                'topic' => 'A cheap dinner',
                'content_type' => 'tweet',
                'visual_kind' => 'flyer',
            ])
            ->assertOk()
            ->assertJsonPath('visual.kind', 'flyer');

        $id = $response->json('visual.id');
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);

        $preview = $this->actingAs($user, 'sanctum')
            ->get('/api/content/visual-previews/'.$id)
            ->assertOk();
        $this->assertStringStartsWith("\x89PNG", $preview->streamedContent());

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->get('/api/content/visual-previews/'.$id)
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/content/drafts', [
                'content' => $response->json('variants.0.content'),
                'content_type' => 'tweet',
                'visual_kind' => 'flyer',
                'visual_preview' => $id,
            ])
            ->assertCreated()
            ->assertJsonPath('media_attached', true);

        $post = $user->posts()->first();
        $this->assertNotNull($post?->media_path);
        $saved = $this->actingAs($user, 'sanctum')
            ->get('/api/content/posts/'.$post->id.'/media')
            ->assertOk();
        $this->assertStringStartsWith("\x89PNG", $saved->streamedContent());

        $this->actingAs($user, 'sanctum')
            ->get('/api/content/visual-previews/'.$id)
            ->assertNotFound();

        Storage::disk('local')->deleteDirectory('post-visuals/'.$user->id);
    }

    public function test_a_photo_that_cannot_be_made_still_returns_the_words(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->disableWebSearch();
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['content' => json_encode(['variants' => [[
                        'content' => 'Rice, eggs, and soy sauce. Dinner for 40p.',
                        'angle' => 'Cheapest meal of the week',
                        'virality_score' => 80,
                        'estimated_reach' => 'high',
                    ]]])],
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        AccountProfile::create([
            'user_id' => $user->id,
            'description' => 'Cheap weeknight meals for one',
            'custom_niche' => 'home cooking',
            'onboarding_completed' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/content/generate', [
                'mode' => 'describe',
                'topic' => 'A cheap dinner',
                'content_type' => 'tweet',
                'visual_kind' => 'photo',
            ])
            ->assertOk()
            ->assertJsonPath('variants.0.content', 'Rice, eggs, and soy sauce. Dinner for 40p.')
            ->assertJsonPath('visual_error', 'A picture could not be made.')
            ->assertJsonMissingPath('visual');
    }

    private function disableWebSearch(): void
    {
        PlatformSetting::query()->where('key', 'web_search_enabled')->update(['value' => '0']);
        app(PlatformSettingsService::class)->clearCache();
    }
}
