<?php

namespace Tests\Unit;

use App\Services\TikTok\OpenAiSpeechProvider;
use App\Services\TikTok\SceneText;
use App\Support\ElevenLabsCatalog;
use Tests\TestCase;

class ElevenLabsCatalogTest extends TestCase
{
    public function test_mills_per_thousand_characters_match_the_published_prices(): void
    {
        $this->assertSame(80, ElevenLabsCatalog::reserveMills('eleven_multilingual_v2', 1000));
        $this->assertSame(80, ElevenLabsCatalog::reserveMills('eleven_v3', 1000));
        $this->assertSame(80, ElevenLabsCatalog::reserveMills('eleven_v4', 1000));
        $this->assertSame(40, ElevenLabsCatalog::reserveMills('eleven_flash_v2', 1000));
        $this->assertSame(40, ElevenLabsCatalog::reserveMills('eleven_flash_v2_5', 1000));
        $this->assertSame(40, ElevenLabsCatalog::reserveMills('eleven_turbo_v2_5', 1000));
        $this->assertSame(80, ElevenLabsCatalog::reserveMills('eleven_unknown', 1000));
        $this->assertSame(40, ElevenLabsCatalog::reserveMills('eleven_multilingual_v2', 500));
        $this->assertSame(20, ElevenLabsCatalog::reserveMills('eleven_flash_v2_5', 500));
        $this->assertSame(10, ElevenLabsCatalog::reserveMills('eleven_multilingual_v2', 1));
        $this->assertSame(80, (int) round(0.08 * 1000));
        $this->assertSame(40, (int) round(0.04 * 1000));
    }

    public function test_the_openai_reserve_formula_is_unchanged(): void
    {
        $text = 'Salt the water first.';
        $plain = SceneText::plain($text, 500);
        $expected = max(10, (int) ceil(mb_strlen($plain) * 0.015));

        $this->assertSame($expected, app(OpenAiSpeechProvider::class)->reserveMills($text));
    }
}
