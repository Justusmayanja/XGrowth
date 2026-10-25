<?php

namespace App\Support;

class YouTubeCatalog
{
    public const MODES = ['short', 'series', 'footage'];

    public const FORMATS = ['faceless_voiceover', 'text_on_screen', 'scene', 'list_facts'];

    public const MODE_LABELS = [
        'short' => 'YouTube Shorts',
        'series' => 'Episodes',
        'footage' => 'Edit my footage',
    ];

    public const FORMAT_LABELS = [
        'faceless_voiceover' => 'Faceless voiceover',
        'text_on_screen' => 'Text on screen',
        'scene' => 'Scene short',
        'list_facts' => 'List or facts',
    ];

    public const STATUS_LABELS = [
        'waiting' => 'Waiting for you',
        'queued' => 'Queued to post',
        'awaiting_edit' => 'Edit plan ready',
        'learned' => 'Learned',
    ];

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? 'In progress';
    }
}
