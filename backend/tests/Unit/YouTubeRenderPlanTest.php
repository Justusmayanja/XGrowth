<?php

namespace Tests\Unit;

use App\Models\YouTubeVideo;
use App\Services\YouTube\OpenAiYouTubeTranscriber;
use App\Services\YouTube\YouTubeFfmpegRenderer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeRenderPlanTest extends TestCase
{
    public function test_each_format_becomes_cues_from_its_own_package_data(): void
    {
        $renderer = app(YouTubeFfmpegRenderer::class);

        $text = $this->video('short', 'text_on_screen', 'text', [
            'on_screen' => [
                ['text' => 'The open question', 'at' => '0:00'],
                ['text' => 'The first real step', 'at' => '0:12'],
            ],
            'shots' => [],
        ]);
        $this->assertSame('text', $renderer->kind($text));
        $textCues = $renderer->cues($text);
        $this->assertSame('The open question', $textCues[0]['text']);
        $this->assertSame(12.0, $textCues[0]['end']);

        $narration = $this->video('series', 'faceless_voiceover', 'narration', [], "[ON SCREEN: The open question]\nCheap weeknight meals finish. But the pan is the shortcut.");
        $this->assertSame('narration', $renderer->kind($narration));
        $spoken = $renderer->cues($narration);
        $this->assertNotEmpty($spoken);
        foreach ($spoken as $cue) {
            $this->assertStringNotContainsString('[ON SCREEN:', $cue['text']);
            $this->assertStringNotContainsString('The open question', $cue['text']);
        }
        $this->assertStringContainsString('But the pan', implode(' ', array_column($spoken, 'text')));

        $facts = $this->video('short', 'list_facts', 'clips', [
            'shots' => [
                ['prompt' => 'Open on the finished plate.', 'seconds' => 8, 'kind' => 'clip'],
                ['prompt' => 'The one step the description already names.', 'seconds' => 6, 'kind' => 'clip'],
            ],
        ]);
        $this->assertSame('clips', $renderer->kind($facts));
        $shotCues = $renderer->cues($facts);
        $this->assertSame(0.0, $shotCues[0]['start']);
        $this->assertSame(8.0, $shotCues[1]['start']);
        $this->assertSame('clips', $renderer->kind($this->video('short', 'scene', 'clips', [
            'shots' => [['prompt' => 'A plain scene.', 'seconds' => 5, 'kind' => 'clip']],
        ])));
    }

    public function test_edit_filters_use_the_plan_and_leave_caption_text_out_of_the_command(): void
    {
        $renderer = app(YouTubeFfmpegRenderer::class);
        $plan = [
            'color' => ['contrast' => 1.08, 'saturation' => 1.04, 'brightness' => 0.02, 'warmth' => 0.03],
            'zooms' => [
                ['at_ratio' => 0.08, 'scale' => 1.08, 'hold_seconds' => 1.2],
                ['at_ratio' => 0.42, 'scale' => 1.14, 'hold_seconds' => 1.4],
                ['at_ratio' => 0.72, 'scale' => 1.10, 'hold_seconds' => 1.2],
            ],
            'audio' => ['loudness_lufs' => -14],
            'silence' => ['trim_over_seconds' => 0.6],
        ];

        $filter = $renderer->editFilter($plan, 20, [[0.0, 20.0]], true, null);
        $this->assertStringContainsString('contrast=1.08', $filter);
        $this->assertStringContainsString('saturation=1.04', $filter);
        $this->assertStringContainsString('loudnorm=I=-14', $filter);
        $this->assertStringContainsString('1.08', $filter);
        $this->assertStringContainsString('1.14', $filter);
        $this->assertStringContainsString('1.10', $filter);
        $this->assertStringNotContainsString('Secret onion line', $filter);

        $silence = $renderer->silenceArguments('footage.mp4', 0.6);
        $this->assertContains('silencedetect=n=-40dB:d=0.6', $silence);

        $keep = $renderer->keepRanges(10, [['start' => 2.0, 'end' => 4.5]]);
        $this->assertSame([[0.0, 2.0], [4.5, 10.0]], $keep);

        $args = $renderer->cardArguments('out.mp4', 'audio.m4a', 'captions.srt', 12, true);
        $joined = implode("\n", $args);
        $this->assertStringContainsString('captions.srt', $joined);
        $this->assertStringNotContainsString('Cheap weeknight meals finish', $joined);
    }

    public function test_openai_transcription_posts_to_whisper_and_not_the_caption_api(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response("1\n00:00:00,000 --> 00:00:04,000\nHello there.\n"),
        ]);
        config(['openai.api_key' => 'test-key']);
        $path = tempnam(sys_get_temp_dir(), 'yt');
        $this->assertNotFalse($path);
        file_put_contents($path, 'video-bytes');

        $text = app(OpenAiYouTubeTranscriber::class)->transcribe($path);

        $this->assertStringContainsString('-->', $text);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/audio/transcriptions');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'googleapis.com') || str_contains($request->url(), 'captions'));
        unlink($path);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function video(string $mode, string $format, string $kind, array $package, string $script = 'Spoken line.'): YouTubeVideo
    {
        return new YouTubeVideo([
            'mode' => $mode,
            'format' => $format,
            'script' => $script,
            'package' => array_merge([
                'runtime_seconds' => 30,
                'render' => ['kind' => $kind],
                'on_screen' => [],
                'shots' => [],
            ], $package),
        ]);
    }
}
