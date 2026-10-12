<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingSubscriptionLine extends Model
{
    protected $fillable = [
        'billing_subscription_id',
        'platform',
        'tier',
        'list_cents',
        'charged_cents',
        'discount_cents',
        'is_anchor',
        'discount_limited',
        'accounts_included',
        'monthly_allowance',
        'allowance_unit',
    ];

    protected function casts(): array
    {
        return [
            'list_cents' => 'integer',
            'charged_cents' => 'integer',
            'discount_cents' => 'integer',
            'is_anchor' => 'boolean',
            'discount_limited' => 'boolean',
            'accounts_included' => 'integer',
            'monthly_allowance' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(BillingSubscription::class, 'billing_subscription_id');
    }
}
