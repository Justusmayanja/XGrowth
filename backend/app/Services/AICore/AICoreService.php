<?php

namespace App\Services\AICore;

class AICoreService
{
    /**
     * Generates content from trend + account context.
     * OpenAI integration deferred to final stage.
     */
    public function generateContent(array $context): array
    {
        return [];
    }

    public function generateFromTemplate(string $eventType, array $variables): string
    {
        return match ($eventType) {
            'goal' => 'GOAL! '.($variables['team'] ?? 'Team').' lead '.($variables['score'] ?? '').'.',
            'transfer' => 'BREAKING: '.($variables['player'] ?? '').' — '.($variables['update'] ?? ''),
            default => '',
        };
    }
}
