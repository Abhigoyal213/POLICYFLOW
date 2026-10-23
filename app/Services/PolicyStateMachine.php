<?php

namespace App\Services;

use App\Enums\PolicyState;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Facades\DB;

class PolicyStateMachine
{
    /**
     * Transition a policy request to a new state.
     *
     * Uses SELECT FOR UPDATE to prevent race conditions on concurrent transitions.
     *
     * @param PolicyRequest $policyRequest  The request to transition
     * @param PolicyState   $targetState    The desired new state
     * @param string|null   $trigger        What caused the transition
     * @param array|null    $metadata       Additional context for audit trail
     *
     * @throws InvalidStateTransitionException if the transition is not allowed
     */
    public function transition(
        PolicyRequest $policyRequest,
        PolicyState $targetState,
        ?string $trigger = null,
        ?array $metadata = null,
    ): PolicyRequest {
        return DB::transaction(function () use ($policyRequest, $targetState, $trigger, $metadata) {
            // Lock the row to prevent concurrent state changes
            $locked = PolicyRequest::lockForUpdate()->find($policyRequest->id);

            $currentState = $locked->state;

            // Validate the transition
            if (!$currentState->canTransitionTo($targetState)) {
                throw new InvalidStateTransitionException(
                    $currentState->value,
                    $targetState->value,
                );
            }

            // Record the transition in audit trail
            PolicyStateHistory::create([
                'policy_request_id' => $locked->id,
                'from_state' => $currentState->value,
                'to_state' => $targetState->value,
                'trigger' => $trigger,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            // Apply the new state
            $locked->state = $targetState;
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * Record the initial state when a request is first created.
     * This does not perform transition validation since there is no "from" state.
     */
    public function recordInitialState(PolicyRequest $policyRequest): void
    {
        PolicyStateHistory::create([
            'policy_request_id' => $policyRequest->id,
            'from_state' => null,
            'to_state' => PolicyState::RECEIVED->value,
            'trigger' => 'api_intake',
            'metadata' => null,
            'created_at' => now(),
        ]);
    }
}
