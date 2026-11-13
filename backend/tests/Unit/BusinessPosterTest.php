<?php

namespace Tests\Unit;

use App\Services\Content\BusinessPoster;
use Tests\TestCase;

class BusinessPosterTest extends TestCase
{
    public function test_a_lender_keeps_its_name_and_the_phone_already_in_the_description(): void
    {
        $poster = BusinessPoster::from('Ace Loans is a lender for small shops. Call 0712 345 678.', 'money lending');

        $this->assertSame('Ace Loans', $poster['name']);
        $this->assertSame('0712 345 678', $poster['contact']);
        $this->assertStringContainsString('lender', $poster['offer']);
        $this->assertStringNotContainsString('0712', $poster['offer']);
    }

    public function test_a_club_name_is_not_cut_into_a_prompt(): void
    {
        $poster = BusinessPoster::from('Man United content to engage the crowd', 'Manchester United');

        $this->assertSame('Manchester United', $poster['name']);
        $this->assertSame('', $poster['contact']);
        $this->assertStringNotContainsString('content to', $poster['name']);
    }

    public function test_an_invented_phone_is_dropped(): void
    {
        $this->assertSame(
            '0712 345 678',
            BusinessPoster::keptContact('0799 000 000', 'Ace Loans. Call 0712 345 678.'),
        );
        $this->assertSame('', BusinessPoster::keptContact('0799 000 000', 'Ace Loans helps small shops.'));
    }
}
