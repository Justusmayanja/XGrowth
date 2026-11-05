<?php

namespace Tests\Unit;

use App\Services\AICore\HumanVoice;
use PHPUnit\Framework\TestCase;

class HumanVoiceTest extends TestCase
{
    public function test_a_typed_line_is_left_alone(): void
    {
        $voice = new HumanVoice;

        $this->assertFalse($voice->soundsRobotic('Full time, and the table already says it.'));
        $this->assertTrue($voice->applies('tweet'));
        $this->assertFalse($voice->applies('thread'));
    }

    public function test_template_lines_are_caught(): void
    {
        $voice = new HumanVoice;

        $this->assertTrue($voice->soundsRobotic("Here's the thing: the price went up."));
        $this->assertTrue($voice->soundsRobotic("It's not a loss, it's a lesson."));
        $this->assertTrue($voice->soundsRobotic('As a lender, I see this every week.'));
        $this->assertTrue($voice->soundsRobotic('The bill — the rent — the food.'));
    }
}
