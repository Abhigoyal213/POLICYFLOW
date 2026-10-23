<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PolicyRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'client_request_id' => $this->client_request_id,
            'agent_id' => $this->agent_id,
            'customer_name' => $this->customer_name,
            'date_of_birth' => $this->date_of_birth->toDateString(),
            'destination' => $this->destination->value,
            'trip_start' => $this->trip_start->toDateString(),
            'trip_end' => $this->trip_end->toDateString(),
            'plan_code' => $this->plan_code->value,
            'sum_insured' => $this->sum_insured,
            'premium' => $this->premium,
            'state' => $this->state->value,
            'policy_number' => $this->policy_number,
            'provider_reference' => $this->provider_reference,
            'attempt_count' => $this->attempt_count,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'state_history' => $this->whenLoaded('stateHistory', function () {
                return $this->stateHistory->map(fn ($h) => [
                    'from_state' => $h->from_state,
                    'to_state' => $h->to_state,
                    'trigger' => $h->trigger,
                    'metadata' => $h->metadata,
                    'created_at' => $h->created_at?->toIso8601String(),
                ]);
            }),
        ];
    }
}
