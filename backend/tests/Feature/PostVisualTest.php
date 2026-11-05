<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\Content\PostVisualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
}
