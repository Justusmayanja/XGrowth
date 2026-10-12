<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'interval',
        'currency',
        'subtotal_cents',
        'discount_cents',
        'total_cents',
        'first_charge_cents',
        'credit_cents',
        'replaces_subscription_id',
        'gateway',
        'gateway_reference',
        'gateway_subscription_id',
        'gateway_event_id',
        'last_renewal_ref',
        'checkout_url',
        'trial_days',
        'trial_ends_at',
        'activated_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'renews',
        'next_plan',
        'canceled_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'first_charge_cents' => 'integer',
            'credit_cents' => 'integer',
            'trial_days' => 'integer',
            'trial_ends_at' => 'datetime',
            'activated_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'renews' => 'boolean',
            'next_plan' => 'array',
            'canceled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillingSubscriptionLine::class);
    }

    /**
     * The amount the first payment must cover. Plan changes reduce it by the credit for unused time.
     */
    public function firstChargeCents(): int
    {
        return $this->first_charge_cents ?? $this->total_cents;
    }

    /**
     * @return array<int, array{platform: string, tier: string}>
     */
    public function selection(): array
    {
        return $this->lines
            ->map(fn (BillingSubscriptionLine $line) => ['platform' => $line->platform, 'tier' => $line->tier])
            ->sortBy('platform')
            ->values()
            ->all();
    }
}
