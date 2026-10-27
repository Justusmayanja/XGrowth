<?php

namespace App\Support;

class TikTokCatalog
{
    public const DRAFTED = 'drafted';

    public const WAITING = 'waiting';

    public const QUEUED = 'queued';

    public const POSTED = 'posted';

    public const LEARNED = 'learned';

    public const DISCARDED = 'discarded';

    public const OBSERVATION = 'observation';

    public const STORY = 'story';

    public const QUESTION = 'question';

    public const OPINION = 'opinion';

    public const TIP = 'tip';

    public const MYTH_BUST = 'myth_bust';

    public const CREATED = 'created';

    public const SKIPPED = 'skipped';

    public const BUSY = 'busy';

    public const BLOCKED = 'blocked';

    public const NOT_ONBOARDED = 'not_onboarded';

    public const SETUP_INCOMPLETE = 'setup_incomplete';

    public const OPEN_CLIP = 'open_clip';

    public const SOURCE_MODEL = 'model';

    public const SOURCE_FALLBACK = 'fallback';

    public const CAPTION_MAX_CHARS = 400;

    public const HASHTAGS_MIN = 3;

    public const HASHTAGS_MAX = 5;

    public const DEFAULT_SLOT_LOCAL = '19:30';

    public const MIN_LEAD_MINUTES = 60;

    public const FALLBACK_TIMEZONE = 'UTC';

    /**
     * Points added or removed per learned clip, then clamped.
     * One clip must not be able to dominate the ranking.
     */
    public const LEARNING_STEP = 5;

    public const LEARNING_CAP = 15;

    /**
     * Used only when every templated topic is already in the recent list.
     */
    public const REPETITION_PENALTY = 25;

    /**
     * Completion at or above this band means people stayed.
     * A first cut, shared with YouTube. Not a claim about the For You algorithm.
     */
    public const HELD_AT_OR_ABOVE = 50;

    /**
     * Completion below this band means people left early.
     * A first cut, shared with YouTube. Not a claim about the For You algorithm.
     */
    public const LEFT_EARLY_BELOW = 30;

    /**
     * queued is confirmed and still unsent. The label must not say the clip was posted or scheduled.
     */
    public const STATUS_LABELS = [
        self::DRAFTED => 'Draft',
        self::WAITING => 'Waiting for your yes',
        self::QUEUED => 'Confirmed, not posted yet',
        self::POSTED => 'Posted',
        self::LEARNED => 'Results in',
        self::DISCARDED => 'Skipped',
    ];

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::DRAFTED,
            self::WAITING,
            self::QUEUED,
            self::POSTED,
            self::LEARNED,
            self::DISCARDED,
        ];
    }

    public static function label(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? 'In progress';
    }

    /**
     * Allowed next statuses. Terminal statuses map to an empty list.
     * waiting and queued can become learned because results can be saved before any upload exists.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::DRAFTED => [self::WAITING, self::DISCARDED],
            self::WAITING => [self::QUEUED, self::LEARNED, self::DISCARDED],
            self::QUEUED => [self::POSTED, self::LEARNED, self::DISCARDED],
            self::POSTED => [self::LEARNED],
            self::LEARNED => [],
            self::DISCARDED => [],
        ];
    }

    /**
     * First-cut scoring hints for the five TikTok goals.
     * Weights are heuristics for our own ranker. They are not a description of how For You ranks clips.
     *
     * @return array<string, array{label: string, prefers: string, weights: array{hook_speed: float, reason_to_stay: float, link_fit: float}}>
     */
    public static function goalStrategies(): array
    {
        return [
            'for_you' => [
                'label' => 'For You',
                'prefers' => 'fast hook',
                'weights' => ['hook_speed' => 1.0, 'reason_to_stay' => 0.3, 'link_fit' => 0.1],
            ],
            'followers' => [
                'label' => 'Followers',
                'prefers' => 'reason to stay',
                'weights' => ['hook_speed' => 0.4, 'reason_to_stay' => 1.0, 'link_fit' => 0.2],
            ],
            'traffic' => [
                'label' => 'Traffic',
                'prefers' => 'link-carrying clip',
                'weights' => ['hook_speed' => 0.4, 'reason_to_stay' => 0.3, 'link_fit' => 1.0],
            ],
            'promote_business' => [
                'label' => 'Promote the business',
                'prefers' => 'the offer',
                'weights' => ['hook_speed' => 0.5, 'reason_to_stay' => 0.6, 'link_fit' => 0.8],
            ],
            'monetize' => [
                'label' => 'Creator payouts',
                'prefers' => 'a repeatable format',
                'weights' => ['hook_speed' => 0.6, 'reason_to_stay' => 0.8, 'link_fit' => 0.4],
            ],
        ];
    }

    /** @return array{provider: string, ready: bool} */
    public static function defaultRender(): array
    {
        return ['provider' => 'pending', 'ready' => false];
    }

    /** @return array{mode: string, uploaded: bool, note: null} */
    public static function defaultPublish(): array
    {
        return ['mode' => 'confirm', 'uploaded' => false, 'note' => null];
    }

    /** @return list<string> */
    public static function ideaKinds(): array
    {
        return [
            self::OBSERVATION,
            self::STORY,
            self::QUESTION,
            self::OPINION,
            self::TIP,
            self::MYTH_BUST,
        ];
    }

    /**
     * How each idea kind fits the three goal weights.
     * A ranking heuristic, not a claim about the For You algorithm.
     *
     * @return array<string, array{hook_speed: float, reason_to_stay: float, link_fit: float}>
     */
    public static function kindAffinities(): array
    {
        return [
            self::OBSERVATION => ['hook_speed' => 0.9, 'reason_to_stay' => 0.4, 'link_fit' => 0.2],
            self::STORY => ['hook_speed' => 0.5, 'reason_to_stay' => 0.95, 'link_fit' => 0.2],
            self::QUESTION => ['hook_speed' => 0.85, 'reason_to_stay' => 0.55, 'link_fit' => 0.25],
            self::OPINION => ['hook_speed' => 0.95, 'reason_to_stay' => 0.45, 'link_fit' => 0.15],
            self::TIP => ['hook_speed' => 0.4, 'reason_to_stay' => 0.7, 'link_fit' => 0.9],
            self::MYTH_BUST => ['hook_speed' => 0.75, 'reason_to_stay' => 0.35, 'link_fit' => 0.3],
        ];
    }
}
