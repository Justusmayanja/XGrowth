<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformPrice extends Model
{
    protected $fillable = [
        'platform',
        'tier',
        'list_price_cents',
        'yearly_price_cents',
    ];

    protected function casts(): array
    {
        return [
            'list_price_cents' => 'integer',
            'yearly_price_cents' => 'integer',
        ];
    }
}
