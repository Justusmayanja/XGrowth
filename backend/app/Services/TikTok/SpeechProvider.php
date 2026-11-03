<?php

namespace App\Services\TikTok;

use App\Models\User;

interface SpeechProvider
{
    public function key(): string;

    /**
     * Write narration audio for one scene. Returns the file path and its duration in seconds.
     */
    public function speak(User $user, string $text, string $voice, string $path): float;
}
