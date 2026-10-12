<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSetup extends Model
{
    protected $fillable = [
        'user_id',
        'platform',
        'goals',
        'settings',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<string> */
    public function goalList(): array
    {
        if (! $this->goals) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $this->goals))));
    }
}
