<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'slug';

    protected $keyType = 'string';

    protected $fillable = [
        'slug',
        'name',
        'price_display',
        'period',
        'yearly_price_display',
        'yearly_period',
        'description',
        'features',
        'cta',
        'highlighted',
        'sort_order',
        'daily_ai_generations',
        'daily_web_searches',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'highlighted' => 'boolean',
            'sort_order' => 'integer',
            'daily_ai_generations' => 'integer',
            'daily_web_searches' => 'integer',
        ];
    }

    public static function allowedSlugs(): array
    {
        return ['creator', 'pro', 'agency'];
    }

    public function toPublicArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'price_display' => $this->price_display,
            'period' => $this->period,
            'yearly_price_display' => $this->yearly_price_display,
            'yearly_period' => $this->yearly_period,
            'description' => $this->description,
            'features' => $this->features ?? [],
            'cta' => $this->cta,
            'highlighted' => $this->highlighted,
            'sort_order' => $this->sort_order,
            'daily_ai_generations' => $this->daily_ai_generations,
            'daily_web_searches' => $this->daily_web_searches,
        ];
    }
}
