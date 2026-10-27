<?php

namespace Tests\Unit;

use App\Models\AccountProfile;
use App\Services\YouTube\YouTubeChapterWriter;
use App\Services\YouTube\YouTubeHookScorer;
use App\Services\YouTube\YouTubePackageWriter;
use App\Services\YouTube\YouTubeRetentionReader;
use App\Services\YouTube\YouTubeSeriesPlanner;
use App\Services\YouTube\YouTubeTitleLinter;
use Tests\TestCase;

class YouTubeCraftTest extends TestCase
{
    public function test_packaging_rules_reject_a_long_shouted_duplicated_title(): void
    {
        $linter = app(YouTubeTitleLinter::class);
        $long = $linter->check(str_repeat('Budget home cooking ', 6));
        $this->assertGreaterThan(60, $long['chars']);
        $this->assertContains('length', array_column($long['issues'], 0));

        $shout = $linter->check('THIS HUGE AMAZING Budget home cooking secret');
        $this->assertContains('shouting', array_column($shout['issues'], 0));
        $this->assertContains('vague', array_column($shout['issues'], 0));

        $duplicate = $linter->check('Budget home cooking for students', 'home cooking tips', ['Budget home cooking']);
        $this->assertContains('duplicate', array_column($duplicate['issues'], 0));

        $thumb = $linter->check('Budget home cooking for students', 'The finished plate now', ['Budget home cooking']);
        $this->assertContains('thumb-length', array_column($thumb['issues'], 0));

        $named = $linter->check('Budget home cooking for students this week', null, ['Budget home cooking']);
        $this->assertNotContains('no-number', array_column($named['issues'], 0));
        $this->assertTrue($linter->episodeLabel('Episode 3'));
        $this->assertFalse($linter->episodeLabel('The finished plate'));
    }

    public function test_a_composed_package_keeps_titles_inside_sixty_and_shows_a_fault(): void
    {
        $package = $this->package(['search']);

        $this->assertSame(5, $package['titles_written']);
        $this->assertGreaterThanOrEqual(5, count($package['titles']));
        foreach (array_slice($package['titles'], 0, 2) as $title) {
            $this->assertLessThanOrEqual(60, $title['chars']);
            $this->assertNotSame(90, $title['chars']);
            $this->assertNotEmpty($title['issues']);
        }
        $this->assertLessThanOrEqual(3, str_word_count($package['thumbnail']));
        $this->assertNotSame($package['thumbnail'], $package['on_screen'][0]['text']);
        $this->assertFalse(app(YouTubeTitleLinter::class)->episodeLabel($package['thumbnail']));
        $this->assertSame(5, $package['hooks_written']);
        $this->assertCount(2, $package['hooks']);
        $this->assertNotSame('', $package['hooks'][0]['formula']);
        $this->assertArrayHasKey('score', $package['hooks'][0]);
        $this->assertTrue($package['chapters_valid']);
        $this->assertTrue(app(YouTubePackageWriter::class)->passesQueryTest(
            $package['title'],
            $package['description'],
            $package['queries'],
        ));
        $this->assertStringNotContainsString('Made for', $package['description']);
        $this->assertStringNotContainsString('expensive version', strtolower($package['spoken']));
        $this->assertSame('Ship it, or change it?', $package['gate']);
    }

    public function test_a_missing_number_is_omitted_rather_than_invented(): void
    {
        $profile = $this->profile();
        $package = $this->package(['search']);
        $allowed = $profile->resolvedNiche().' '.$profile->description.' '.$profile->audience.' cheap weeknight meals';
        $invented = app(YouTubePackageWriter::class)->inventedDigits($package, $allowed);

        $this->assertSame([], $invented);
        $this->assertDoesNotMatchRegularExpression('/\d/', $package['title'].$package['thumbnail'].$package['hooks'][0]['text']);
    }

    public function test_subscriber_feed_says_so_instead_of_pretending_to_be_search(): void
    {
        $package = $this->package(['subscribers']);

        $this->assertFalse($package['search_facing']);
        $this->assertSame([], $package['queries']);
        $this->assertStringStartsWith('For the people who already watch.', $package['description']);
        $this->assertStringNotContainsString('Made for', $package['description']);
    }

    public function test_chapter_rules_require_a_zero_start_three_entries_and_ten_seconds(): void
    {
        $chapters = app(YouTubeChapterWriter::class);
        $built = $chapters->fromSections([
            ['title' => 'The open question', 'words' => 40],
            ['title' => 'The first real step', 'words' => 80],
            ['title' => 'The promised part', 'words' => 40],
        ]);

        $this->assertTrue($built['valid']);
        $this->assertSame('0:00', $built['chapters'][0]['label']);
        $this->assertGreaterThanOrEqual(3, count($built['chapters']));
        foreach ($built['chapters'] as $chapter) {
            $this->assertGreaterThanOrEqual(10, $chapter['seconds']);
            $this->assertGreaterThanOrEqual(3, str_word_count($chapter['title']));
            $this->assertLessThanOrEqual(5, str_word_count($chapter['title']));
        }

        $this->assertFalse($chapters->valid([
            ['start' => 5, 'seconds' => 20],
            ['start' => 25, 'seconds' => 20],
            ['start' => 45, 'seconds' => 20],
        ]));
        $this->assertFalse($chapters->valid([
            ['start' => 0, 'seconds' => 20],
            ['start' => 20, 'seconds' => 20],
        ]));
        $this->assertFalse($chapters->valid([
            ['start' => 0, 'seconds' => 5],
            ['start' => 5, 'seconds' => 20],
            ['start' => 25, 'seconds' => 20],
        ]));
    }

    public function test_retention_names_one_case_and_only_a_hook_leak_changes_the_opening(): void
    {
        $reader = app(YouTubeRetentionReader::class);

        $hook = $reader->read(implode("\n", [
            '0,100', '5,90', '10,80', '15,75', '20,72', '25,70', '30,60', '45,59', '90,58', '120,57',
        ]));
        $this->assertSame('hook_leak', $hook['case']);
        $this->assertTrue($hook['change_opening']);
        $this->assertGreaterThanOrEqual(25, $hook['hook_leak']);

        $cliff = $reader->read(implode("\n", [
            '0,100', '10,98', '20,96', '30,94', '45,93', '60,92', '90,90', '120,70', '150,69', '180,68',
        ]), null, [[86, 94, 'the long setup starts here']]);
        $this->assertSame('cliff', $cliff['case']);
        $this->assertFalse($cliff['change_opening']);
        $this->assertStringContainsString('the long setup starts here', (string) $cliff['cliff']['said']);

        $slide = $reader->read(implode("\n", [
            '0,100', '15,96', '30,92', '50,86', '70,80', '90,74', '110,68', '130,62', '150,56', '170,50',
        ]));
        $this->assertSame('slide', $slide['case']);
        $this->assertFalse($slide['change_opening']);

        $fine = $reader->read(implode("\n", [
            '0,100', '10,98', '20,97', '30,96', '60,95', '90,94', '120,93', '150,92', '180,91', '210,90',
        ]));
        $this->assertSame('packaging', $fine['case']);
        $this->assertFalse($fine['change_opening']);

        $thin = $reader->read("0,100\n10,90\n20,80");
        $this->assertSame('unreadable', $thin['case']);
        $this->assertFalse($thin['change_opening']);
    }

    public function test_a_series_outline_comes_from_the_premise_and_not_a_shared_template(): void
    {
        $planner = app(YouTubeSeriesPlanner::class);
        $cooking = $planner->outline(
            'I cook cheap weeknight meals and share the shortcuts that actually finish.',
            'Students cooking for one',
            'funny',
        );
        $keys = $planner->outline(
            'I review mechanical keyboards for people who type all day.',
            'People who write for work',
            'direct',
        );

        $cookingTitles = array_column($cooking, 'title');
        $keyTitles = array_column($keys, 'title');
        $this->assertSame([], array_intersect($cookingTitles, $keyTitles));
        $this->assertStringContainsString('weeknight', strtolower(implode(' ', $cookingTitles)));
        $this->assertStringNotContainsString('Starting', implode(' ', $cookingTitles));
        foreach ($cookingTitles as $title) {
            $this->assertDoesNotMatchRegularExpression('/^episode\s+\d+/i', $title);
        }
    }

    public function test_hook_scores_name_a_formula(): void
    {
        $scored = app(YouTubeHookScorer::class)->score(
            'You are probably doing Budget home cooking from the wrong end before the plate exists.',
        );

        $this->assertSame('The Mistake', $scored['formula']);
        $this->assertArrayHasKey('STAKES', $scored['properties']);
    }

    /** @param  list<string>  $goals */
    private function package(array $goals): array
    {
        return app(YouTubePackageWriter::class)->compose(
            $this->profile(),
            $goals,
            [
                'topic' => 'cheap weeknight meals, said in a funny voice with what this channel already uses',
                'query' => 'Budget home cooking',
                'kind' => 'The Question',
                'reason' => 'It beat a channel median.',
            ],
            'text_on_screen',
        );
    }

    private function profile(): AccountProfile
    {
        return new AccountProfile([
            'custom_niche' => 'Budget home cooking',
            'description' => 'I cook cheap weeknight meals and share the shortcuts that actually finish.',
            'audience' => 'Students cooking for one',
            'tone' => 'funny',
        ]);
    }
}
