<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_rules', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('label');
            $table->text('description')->nullable();
            $table->text('rules');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();

        DB::table('ai_rules')->insert([
            [
                'key' => 'global',
                'label' => 'Global platform rules',
                'description' => 'Applied to every AI request before any other instructions.',
                'rules' => implode("\n", [
                    'Read and follow these platform rules before anything else. They override default behavior when in conflict.',
                    'Never generate hate speech, harassment, slurs, or discriminatory content.',
                    'Never invent facts, statistics, quotes, or breaking news that were not provided in the prompt.',
                    'Never impersonate real people, brands, or official accounts.',
                    'Never include spam, engagement bait, or manipulative growth hacks.',
                    'Keep language natural and human — avoid obvious AI phrasing and filler.',
                    'Respect X character limits unless the content type explicitly allows longer output.',
                ]),
                'is_active' => true,
                'sort_order' => 1,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'content_generation',
                'label' => 'Content Studio rules',
                'description' => 'Extra rules for post and thread generation in Content Studio.',
                'rules' => implode("\n", [
                    'Generate exactly 3 distinct draft angles (insight, question, hot take, etc.).',
                    'Match the user niche, tone, and goal from their profile.',
                    'Tweets must stay under 280 characters unless content_type is "thread".',
                    'For threads, use numbered tweets (1/, 2/, etc.) on separate lines.',
                    'For polls, include the question and 2-4 answer options.',
                    'Avoid hashtags unless they feel natural for the niche.',
                    'Return only valid JSON matching the requested schema.',
                ]),
                'is_active' => true,
                'sort_order' => 2,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'reply_drafts',
                'label' => 'Engagement reply rules',
                'description' => 'Rules for AI-generated reply drafts in the Engagement Center.',
                'rules' => implode("\n", [
                    'Replies must stay under 280 characters.',
                    'Add real value: insight, a sharp question, or a respectful counterpoint.',
                    'Sound specific to the conversation — never generic praise like "Great post!"',
                    'Vary the angle across the 3 drafts.',
                    'Do not be sycophantic, salesy, or argumentative for its own sake.',
                    'Return only valid JSON matching the requested schema.',
                ]),
                'is_active' => true,
                'sort_order' => 3,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'event_templates',
                'label' => 'Live event post rules',
                'description' => 'Rules for short live-moment posts (goals, transfers, breaking news).',
                'rules' => implode("\n", [
                    'Write one short, punchy X post under 280 characters.',
                    'Use only the event details provided — do not invent scores, players, or updates.',
                    'Match the urgency of live sports/news without sensationalism.',
                    'Return only the post text — no JSON, quotes, or labels.',
                ]),
                'is_active' => true,
                'sort_order' => 4,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_rules');
    }
};
