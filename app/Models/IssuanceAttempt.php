<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuanceAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'policy_request_id',
        'attempt_number',
        'scenario',
        'request_payload',
        'response_payload',
        'http_status',
        'status',
        'error_message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'http_status' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
