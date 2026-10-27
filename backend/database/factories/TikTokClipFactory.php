<?php

namespace Database\Factories;

use App\Models\TikTokClip;
use App\Models\User;
use App\Support\TikTokCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TikTokClip>
 */
class TikTokClipFactory extends Factory
{
    protected $model = TikTokClip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->waitingAttributes();
    }

    public function drafted(): static
    {
        return $this->state(fn () => [
            'status' => TikTokCatalog::DRAFTED,
            'suggested_for' => null,
            'confirmed_at' => null,
            'queued_at' => null,
            'posted_at' => null,
            'learned_at' => null,
            'discarded_at' => null,
            'learning_note' => null,
        ]);
    }

    public function waiting(): static
    {
        return $this->state(fn () => $this->waitingAttributes());
    }

    public function queued(): static
    {
        return $this->state(fn () => [
            'status' => TikTokCatalog::QUEUED,
            'suggested_for' => now()->addHours(6),
            'confirmed_at' => now()->subMinute(),
            'queued_at' => now()->subMinute(),
            'posted_at' => null,
            'learned_at' => null,
            'discarded_at' => null,
            'learning_note' => null,
        ]);
    }

    public function posted(): static
    {
        return $this->state(fn () => [
            'status' => TikTokCatalog::POSTED,
            'suggested_for' => now()->subHour(),
            'confirmed_at' => now()->subHours(2),
            'queued_at' => now()->subHours(2),
            'posted_at' => now()->subHour(),
            'learned_at' => null,
            'discarded_at' => null,
            'learning_note' => null,
            'platform_post_id' => 'tt_'.fake()->numerify('########'),
        ]);
    }

    public function learned(): static
    {
        return $this->state(fn () => [
            'status' => TikTokCatalog::LEARNED,
            'learned_at' => now()->subMinute(),
            'learning_note' => 'Recorded. The next idea takes this into account.',
            'views' => 400,
            'completion_percent' => 42.5,
            'follows' => 3,
            'confirmed_at' => null,
            'queued_at' => null,
            'posted_at' => null,
            'discarded_at' => null,
        ]);
    }

    public function discarded(): static
    {
        return $this->state(fn () => [
            'status' => TikTokCatalog::DISCARDED,
            'discarded_at' => now()->subMinute(),
            'confirmed_at' => null,
            'queued_at' => null,
            'posted_at' => null,
            'learned_at' => null,
            'learning_note' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function waitingAttributes(): array
    {
        $hook = 'You are salting the water too late.';

        return [
            'user_id' => User::factory(),
            'status' => TikTokCatalog::WAITING,
            'topic' => 'Salt the pasta water',
            'hook' => $hook,
            'script' => $hook."\nThe water should taste like the sea before the pasta goes in.",
            'caption' => $hook."\nTaste it before the pasta goes in.\n#budgetcooking #weeknights #pasta",
            'suggested_for' => now()->addHours(6),
            'package' => [
                'on_screen' => [
                    ['text' => 'Salt it first', 'at' => '0:00'],
                ],
                'shots' => [
                    ['prompt' => 'A pot of water coming to a boil', 'seconds' => 3, 'kind' => 'clip'],
                ],
                'sound_note' => 'A quiet kitchen',
                'render' => TikTokCatalog::defaultRender(),
                'publish' => TikTokCatalog::defaultPublish(),
            ],
            'score' => 72,
            'views' => null,
            'completion_percent' => null,
            'follows' => null,
            'learning_note' => null,
            'learned_at' => null,
            'confirmed_at' => null,
            'queued_at' => null,
            'posted_at' => null,
            'discarded_at' => null,
            'platform_post_id' => null,
        ];
    }
}
