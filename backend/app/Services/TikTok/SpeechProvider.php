<?php

namespace App\Services\TikTok;

use App\Models\User;

interface SpeechProvider
{
    public function key(): string;

    /**
     * Mills to reserve for this text. Each provider owns its rate.
     */
    public function reserveMills(string $text): int;

    /**
     * Work-file name. The hash covers provider, model, voice, format, and text.
     */
    public function cacheToken(string $text, string $voice): string;

    /**
     * Write narration audio for one scene. Returns the duration in seconds when the provider measured it.
     */
    public function speak(User $user, string $text, string $voice, string $path): float;
}
