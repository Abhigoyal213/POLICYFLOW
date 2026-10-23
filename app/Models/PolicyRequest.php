<?php

namespace App\Models;

use App\Enums\Destination;
use App\Enums\PlanCode;
use App\Enums\PolicyState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicyRequest extends Model
{
    protected $fillable = [
        'uuid',
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'premium',
        'state',
        'policy_number',
        'provider_reference',
        'attempt_count',
        'last_provider_response',
        'error_details',
    ];

    protected function casts(): array
    {
        return [
            'state' => PolicyState::class,
            'destination' => Destination::class,
            'plan_code' => PlanCode::class,
            'date_of_birth' => 'date',
            'trip_start' => 'date',
            'trip_end' => 'date',
            'premium' => 'decimal:2',
            'sum_insured' => 'integer',
            'attempt_count' => 'integer',
            'last_provider_response' => 'array',
        ];
    }

    public function issuanceAttempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class);
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->orderBy('created_at');
    }
}
