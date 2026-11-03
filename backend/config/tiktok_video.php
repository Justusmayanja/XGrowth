<?php

return [

    // gpt-4o-mini-tts is OpenAI's current speech model. Confirm the id if OpenAI renames it.
    'tts_model' => env('TIKTOK_TTS_MODEL', 'gpt-4o-mini-tts'),

    'tts_driver' => env('TIKTOK_TTS_DRIVER', 'fake'),

    'voices' => [
        'female' => env('TIKTOK_TTS_VOICE_FEMALE', 'nova'),
        'male' => env('TIKTOK_TTS_VOICE_MALE', 'onyx'),
    ],

    'fonts' => [
        'inter' => 'Inter-Regular.ttf',
        'source_sans' => 'SourceSans3-Regular.ttf',
    ],

];
