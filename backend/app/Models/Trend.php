<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trend extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'score',
        'velocity',
        'growth_rate',
        'competition',
        'niche_tags',
        'metadata',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'velocity' => 'decimal:2',
            'growth_rate' => 'decimal:2',
            'niche_tags' => 'array',
            'metadata' => 'array',
            'detected_at' => 'datetime',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
