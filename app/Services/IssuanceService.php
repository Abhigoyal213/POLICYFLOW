<?php

namespace App\Services;

use App\Enums\PolicyState;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use Illuminate\Support\Facades\DB;

class IssuanceService
{
    public function __construct(
        private PolicyStateMachine $stateMachine,
        private MockInsurerClient $insurerClient,
    ) {}

    /**
     * Submit a policy request to the insurer.
     *
     * Handles idempotency: if already ISSUED, returns the existing policy without re-submitting.
     * Uses row-level locking to prevent concurrent submissions.
     *
     * @return array{policy_request: PolicyRequest, action: string}
     */
    public function submit(PolicyRequest $policyRequest, string $scenario = 'SUCCESS'): array
    {
        // If already issued, return immediately — don't re-submit
        if ($policyRequest->state === PolicyState::ISSUED) {
            return [
                'policy_request' => $policyRequest,
                'action' => 'already_issued',
            ];
        }

        // Only VALIDATED and FAILED states can be submitted
        if (!in_array($policyRequest->state, [PolicyState::VALIDATED, PolicyState::FAILED])) {
            return [
                'policy_request' => $policyRequest,
                'action' => 'invalid_state',
            ];
        }

        return DB::transaction(function () use ($policyRequest, $scenario) {
            // Lock and re-check state to prevent race conditions
            $locked = PolicyRequest::lockForUpdate()->find($policyRequest->id);

            if ($locked->state === PolicyState::ISSUED) {
                return [
                    'policy_request' => $locked,
                    'action' => 'already_issued',
                ];
            }

            if (!in_array($locked->state, [PolicyState::VALIDATED, PolicyState::FAILED])) {
                return [
                    'policy_request' => $locked,
                    'action' => 'invalid_state',
                ];
            }

            // Transition to SUBMITTED
            $locked = $this->stateMachine->transition($locked, PolicyState::SUBMITTED, 'issuance_submit');

            // Increment attempt count
            $attemptNumber = $locked->attempt_count + 1;
            $locked->attempt_count = $attemptNumber;
            $locked->save();

            // Prepare request payload for the insurer
            $requestPayload = [
                'uuid' => $locked->uuid,
                'customer_name' => $locked->customer_name,
                'date_of_birth' => $locked->date_of_birth->toDateString(),
                'destination' => $locked->destination->value,
                'trip_start' => $locked->trip_start->toDateString(),
                'trip_end' => $locked->trip_end->toDateString(),
                'plan_code' => $locked->plan_code->value,
                'sum_insured' => $locked->sum_insured,
                'premium' => $locked->premium,
                'scenario' => $scenario,
            ];

            // Call the mock insurer
            $response = $this->insurerClient->submit($requestPayload, $scenario);

            // Record the attempt
            IssuanceAttempt::create([
                'policy_request_id' => $locked->id,
                'attempt_number' => $attemptNumber,
                'scenario' => $scenario,
                'request_payload' => $requestPayload,
                'response_payload' => $response['data'],
                'http_status' => $response['status'],
                'status' => $this->mapResponseToAttemptStatus($response),
                'error_message' => $response['error'],
                'created_at' => now(),
            ]);

            // Update the request based on the response
            $locked->last_provider_response = $response['data'];

            if ($response['timed_out']) {
                // On timeout, check if the provider actually issued
                $providerRef = $response['data']['provider_reference'] ?? $locked->provider_reference ?? $locked->uuid;
                $statusCheck = $this->insurerClient->checkStatus($providerRef);

                if ($statusCheck['found'] && $statusCheck['status'] === 'ISSUED') {
                    // Provider did issue — mark as ISSUED
                    $locked->policy_number = $statusCheck['policy_number'];
                    $locked->provider_reference = $providerRef;
                    $locked->save();
                    $locked = $this->stateMachine->transition($locked, PolicyState::ISSUED, 'timeout_reconciliation');
                } else {
                    $locked->error_details = 'Submission timed out';
                    $locked->provider_reference = $providerRef;
                    $locked->save();
                    $locked = $this->stateMachine->transition($locked, PolicyState::NEEDS_RECONCILIATION, 'timeout');
                }
            } elseif ($response['success']) {
                $data = $response['data'];
                $status = $data['status'] ?? 'ISSUED';

                if ($status === 'PENDING') {
                    // Provider accepted but hasn't confirmed yet
                    $locked->provider_reference = $data['provider_reference'] ?? null;
                    $locked->save();
                    $locked = $this->stateMachine->transition($locked, PolicyState::PENDING_CONFIRMATION, 'provider_pending');
                } else {
                    // Provider issued the policy
                    $locked->policy_number = $data['policy_number'] ?? null;
                    $locked->provider_reference = $data['provider_reference'] ?? null;
                    $locked->save();
                    $locked = $this->stateMachine->transition($locked, PolicyState::ISSUED, 'provider_success');
                }
            } else {
                // Provider returned an error
                $locked->error_details = $response['error'];
                $locked->save();
                $locked = $this->stateMachine->transition($locked, PolicyState::FAILED, 'provider_failure');
            }

            return [
                'policy_request' => $locked->fresh(),
                'action' => 'submitted',
            ];
        });
    }

    private function mapResponseToAttemptStatus(array $response): string
    {
        if ($response['timed_out']) {
            return 'TIMEOUT';
        }

        return $response['success'] ? 'SUCCESS' : 'FAILED';
    }
}
