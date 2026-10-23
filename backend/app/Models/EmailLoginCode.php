<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLoginCode extends Model
{
    protected $fillable = [
        'email',
        'code_hash',
        'purpose',
        'name',
        'username',
        'expires_at',
        'consumed_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
