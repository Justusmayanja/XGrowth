<?php

use App\Support\ElevenLabsCatalog;
use App\Support\GeminiCatalog;

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'frontend' => [
        'url' => env('FRONTEND_URL', 'http://localhost:3000'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', 'http://127.0.0.1:8000/api/auth/google/callback'),
    ],

    'x' => [
        'client_id' => env('X_CLIENT_ID'),
        'client_secret' => env('X_CLIENT_SECRET'),
        'redirect' => env('X_REDIRECT_URI', 'http://127.0.0.1:8000/api/auth/x/callback'),
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect' => env('TIKTOK_REDIRECT_URI'),
        'scopes' => env('TIKTOK_SCOPES', 'user.info.basic'),
        'ffprobe' => env('TIKTOK_FFPROBE', 'ffprobe'),
        'ffmpeg' => env('TIKTOK_FFMPEG', 'ffmpeg'),
    ],

    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect' => env('YOUTUBE_REDIRECT_URI'),
        'scopes' => env(
            'YOUTUBE_SCOPES',
            'https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/yt-analytics.readonly'
        ),
        'read_quota_daily' => (int) env('YOUTUBE_READ_QUOTA_DAILY', 500),
        'research_cache_minutes' => (int) env('YOUTUBE_RESEARCH_CACHE_MINUTES', 15),
        'transcription_driver' => env('YOUTUBE_TRANSCRIPTION_DRIVER', 'none'),
        'transcription_model' => env('YOUTUBE_TRANSCRIPTION_MODEL', 'whisper-1'),
        'tts_driver' => env('YOUTUBE_TTS_DRIVER', 'local'),
        'fake_transcript' => env('YOUTUBE_FAKE_TRANSCRIPT', ''),
        'upload_audit_approved' => filter_var(env('YOUTUBE_UPLOAD_AUDIT_APPROVED', false), FILTER_VALIDATE_BOOLEAN),
        'upload_daily_budget' => (int) env('YOUTUBE_UPLOAD_DAILY_BUDGET', 100),
    ],

    'elevenlabs' => [
        'key' => env('ELEVENLABS_API_KEY'),
        'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io'),
        'model' => env('ELEVENLABS_MODEL', ElevenLabsCatalog::DEFAULT_MODEL),
        'voices' => [
            'female' => env('ELEVENLABS_VOICE_FEMALE'),
            'male' => env('ELEVENLABS_VOICE_MALE'),
        ],
        'output_format' => env('ELEVENLABS_OUTPUT_FORMAT', ElevenLabsCatalog::DEFAULT_OUTPUT_FORMAT),
        'timeout' => (int) env('ELEVENLABS_TIMEOUT', 60),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
        'video_model' => env('GEMINI_VIDEO_MODEL', GeminiCatalog::MODEL),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
