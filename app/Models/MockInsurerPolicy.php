<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MockInsurerPolicy extends Model
{
    protected $fillable = [
        'provider_reference',
        'policy_number',
        'status',
        'request_data',
    ];

    protected function casts(): array
    {
        return [
            'request_data' => 'array',
        ];
    }
}
