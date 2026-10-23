<?php

namespace Tests\Feature;

use App\Enums\PolicyState;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use App\Services\IssuanceService;
use App\Services\MockInsurerClient;
use App\Services\PolicyStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ], $overrides);
    }

    private function createValidatedRequest(array $overrides = []): string
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload($overrides));
        $response->assertStatus(201);
        return $response->json('data.id');
    }

    /**
     * Successful submission should produce ISSUED state with a policy number.
     */
    public function test_successful_submission_produces_issued_state(): void
    {
        // Mock the insurer client to return SUCCESS without making real HTTP calls
        $mock = $this->mock(MockInsurerClient::class);
        $mock->shouldReceive('submit')->once()->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'status' => 'ISSUED',
                'policy_number' => 'POL-2026-00001',
                'provider_reference' => 'PROV-REF-001',
            ],
            'error' => null,
            'timed_out' => false,
        ]);

        $uuid = $this->createValidatedRequest();

        $response = $this->postJson("/api/policy-requests/{$uuid}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.state', 'ISSUED');
        $response->assertJsonPath('data.policy_number', 'POL-2026-00001');
        $response->assertJsonPath('data.action', 'submitted');

        // Verify in database
        $policyRequest = PolicyRequest::where('uuid', $uuid)->first();
        $this->assertEquals(PolicyState::ISSUED, $policyRequest->state);
        $this->assertNotNull($policyRequest->policy_number);
        $this->assertEquals(1, $policyRequest->attempt_count);
    }

    /**
     * Re-submitting an already ISSUED request should not re-issue — returns existing.
     */
    public function test_submit_already_issued_returns_existing(): void
    {
        $mock = $this->mock(MockInsurerClient::class);
        $mock->shouldReceive('submit')->once()->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'status' => 'ISSUED',
                'policy_number' => 'POL-2026-00001',
                'provider_reference' => 'PROV-REF-001',
            ],
            'error' => null,
            'timed_out' => false,
        ]);

        $uuid = $this->createValidatedRequest();

        // First submit — issues the policy
        $this->postJson("/api/policy-requests/{$uuid}/submit")->assertStatus(200);

        // Second submit — should NOT call the insurer again
        $response = $this->postJson("/api/policy-requests/{$uuid}/submit");
        $response->assertStatus(200);
        $response->assertJsonPath('data.action', 'already_issued');
        $response->assertJsonPath('data.state', 'ISSUED');

        // Only 1 issuance attempt should exist
        $policyRequest = PolicyRequest::where('uuid', $uuid)->first();
        $this->assertEquals(1, $policyRequest->issuanceAttempts()->count());
    }

    /**
     * Failed submission should allow retry.
     */
    public function test_failed_submission_allows_retry(): void
    {
        $mock = $this->mock(MockInsurerClient::class);

        $mock->shouldReceive('submit')
            ->andReturn(
                [
                    'success' => false,
                    'status' => 503,
                    'data' => ['error' => 'Service temporarily unavailable'],
                    'error' => 'Service temporarily unavailable',
                    'timed_out' => false,
                ],
                [
                    'success' => true,
                    'status' => 200,
                    'data' => [
                        'status' => 'ISSUED',
                        'policy_number' => 'POL-2026-00002',
                        'provider_reference' => 'PROV-REF-002',
                    ],
                    'error' => null,
                    'timed_out' => false,
                ]
            );

        $uuid = $this->createValidatedRequest();

        // Submit — should fail
        $response = $this->postJson("/api/policy-requests/{$uuid}/submit");
        $response->assertStatus(200);
        $response->assertJsonPath('data.state', 'FAILED');

        // Retry — should succeed
        $response = $this->postJson("/api/policy-requests/{$uuid}/submit");
        $response->assertStatus(200);
        $response->assertJsonPath('data.state', 'ISSUED');

        // Should have 2 attempts
        $policyRequest = PolicyRequest::where('uuid', $uuid)->first();
        $this->assertEquals(2, $policyRequest->attempt_count);
    }

    /**
     * ISSUED state cannot be overwritten — it's terminal.
     */
    public function test_issued_state_cannot_regress(): void
    {
        $mock = $this->mock(MockInsurerClient::class);
        $mock->shouldReceive('submit')->once()->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'status' => 'ISSUED',
                'policy_number' => 'POL-2026-00001',
                'provider_reference' => 'PROV-REF-001',
            ],
            'error' => null,
            'timed_out' => false,
        ]);

        $uuid = $this->createValidatedRequest();
        $this->postJson("/api/policy-requests/{$uuid}/submit")->assertStatus(200);

        // Verify we can't use the state machine to go backward
        $policyRequest = PolicyRequest::where('uuid', $uuid)->first();
        $this->assertTrue($policyRequest->state->isTerminal());
        $this->assertFalse($policyRequest->state->canTransitionTo(PolicyState::SUBMITTED));
        $this->assertFalse($policyRequest->state->canTransitionTo(PolicyState::FAILED));
    }

    /**
     * Duplicate callback: sending the same event_id twice should process only once.
     * This is one of the two mandatory reliability cases.
     */
    public function test_duplicate_callback_processed_only_once(): void
    {
        $mock = $this->mock(MockInsurerClient::class);
        $mock->shouldReceive('submit')->once()->andReturn([
            'success' => true,
            'status' => 202,
            'data' => [
                'status' => 'PENDING',
                'provider_reference' => 'PROV-REF-PENDING',
            ],
            'error' => null,
            'timed_out' => false,
        ]);

        $uuid = $this->createValidatedRequest();

        // Submit — goes to PENDING_CONFIRMATION
        $this->postJson("/api/policy-requests/{$uuid}/submit")->assertStatus(200);

        $callbackPayload = [
            'event_id' => 'EVT-UNIQUE-001',
            'provider_reference' => 'PROV-REF-PENDING',
            'event_type' => 'POLICY_ISSUED',
            'policy_number' => 'POL-2026-CALLBACK-001',
        ];

        // First callback — should process and transition to ISSUED
        $response1 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $response1->assertStatus(200);
        $response1->assertJsonPath('message', 'Event processed successfully.');

        // Second identical callback — should be detected as duplicate
        $response2 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $response2->assertStatus(200);
        $response2->assertJsonPath('message', 'Event already received.');

        // Verify: only 1 provider_event in the database
        $this->assertEquals(1, ProviderEvent::where('event_id', 'EVT-UNIQUE-001')->count());

        // Verify: policy is ISSUED with the correct policy number
        $policyRequest = PolicyRequest::where('uuid', $uuid)->first();
        $this->assertEquals(PolicyState::ISSUED, $policyRequest->state);
        $this->assertEquals('POL-2026-CALLBACK-001', $policyRequest->policy_number);

        // Verify: only ONE transition to ISSUED in audit trail
        $issuedTransitions = $policyRequest->stateHistory()
            ->where('to_state', PolicyState::ISSUED->value)
            ->count();
        $this->assertEquals(1, $issuedTransitions);
    }

    /**
     * Duplicate intake under near-simultaneous conditions.
     * This is one of the two mandatory reliability cases.
     */
    public function test_duplicate_intake_near_simultaneous(): void
    {
        $payload = $this->validPayload();

        // Send the same request multiple times rapidly
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->postJson('/api/policy-requests', $payload);
        }

        // Only the first should be 201, rest should be 200
        $this->assertEquals(201, $responses[0]->status());
        for ($i = 1; $i < 5; $i++) {
            $this->assertEquals(200, $responses[$i]->status());
        }

        // All should return the same UUID
        $uuid = $responses[0]->json('data.id');
        for ($i = 1; $i < 5; $i++) {
            $this->assertEquals($uuid, $responses[$i]->json('data.id'));
        }

        // Only 1 record in the database
        $this->assertDatabaseCount('policy_requests', 1);
    }

    /**
     * Submitting a request with RECEIVED state (not VALIDATED) should fail.
     */
    public function test_submit_invalid_state_rejected(): void
    {
        // Manually create a request in RECEIVED state
        $policyRequest = PolicyRequest::create([
            'uuid' => 'test-uuid-001',
            'client_request_id' => 'TEST-001',
            'agent_id' => 'AGENT-TEST',
            'customer_name' => 'Test User',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'premium' => '60.00',
            'state' => PolicyState::RECEIVED,
        ]);

        $response = $this->postJson('/api/policy-requests/test-uuid-001/submit');
        $response->assertStatus(409);
        $response->assertJsonPath('data.action', 'invalid_state');
    }

    /**
     * Timeout after success: insurer timed out, but reconciliation finds policy was issued.
     */
    public function test_timeout_resolved_via_reconciliation_to_issued(): void
    {
        $mock = $this->mock(MockInsurerClient::class);
        $mock->shouldReceive('submit')->once()->andReturn([
            'success' => false,
            'status' => 0,
            'data' => [],
            'error' => 'Connection timeout',
            'timed_out' => true,
        ]);

        $mock->shouldReceive('checkStatus')->once()->andReturn([
            'found' => true,
            'status' => 'ISSUED',
            'policy_number' => 'POL-RECONCILED-001',
        ]);

        $uuid = $this->createValidatedRequest();

        $response = $this->postJson("/api/policy-requests/{$uuid}/submit");
        $response->assertStatus(200);
        $response->assertJsonPath('data.state', 'ISSUED');
        $response->assertJsonPath('data.policy_number', 'POL-RECONCILED-001');

        $policyRequest = PolicyRequest::where('uuid', $uuid)->first();
        $this->assertEquals(PolicyState::ISSUED, $policyRequest->state);
    }

    /**
     * Timeout unresolved: status check cannot find policy, transitions to NEEDS_RECONCILIATION.
     */
    public function test_timeout_unresolved_transitions_to_needs_reconciliation(): void
    {
        $mock = $this->mock(MockInsurerClient::class);
        $mock->shouldReceive('submit')->once()->andReturn([
            'success' => false,
            'status' => 0,
            'data' => [],
            'error' => 'Connection timeout',
            'timed_out' => true,
        ]);

        $mock->shouldReceive('checkStatus')->once()->andReturn([
            'found' => false,
            'status' => null,
            'policy_number' => null,
        ]);

        $uuid = $this->createValidatedRequest();

        $response = $this->postJson("/api/policy-requests/{$uuid}/submit");
        $response->assertStatus(200);
        $response->assertJsonPath('data.state', 'NEEDS_RECONCILIATION');

        $policyRequest = PolicyRequest::where('uuid', $uuid)->first();
        $this->assertEquals(PolicyState::NEEDS_RECONCILIATION, $policyRequest->state);
    }

    /**
     * Repeated callback cannot overwrite a confirmed ISSUED state.
     */
    public function test_callback_cannot_overwrite_confirmed_issued_state(): void
    {
        $mock = $this->mock(MockInsurerClient::class);
        $mock->shouldReceive('submit')->once()->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'status' => 'ISSUED',
                'policy_number' => 'POL-ORIGINAL-999',
                'provider_reference' => 'PROV-REF-LOCKED',
            ],
            'error' => null,
            'timed_out' => false,
        ]);

        $uuid = $this->createValidatedRequest();
        $this->postJson("/api/policy-requests/{$uuid}/submit")->assertStatus(200);

        // An external event arrives attempting to say POLICY_FAILED with a new event ID
        $response = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'EVT-LATE-FAILURE-001',
            'provider_reference' => 'PROV-REF-LOCKED',
            'event_type' => 'POLICY_FAILED',
            'reason' => 'Late cancellation from insurer',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Event received but policy already issued.');

        // Verify policy request state is still strictly ISSUED with original policy number
        $policyRequest = PolicyRequest::where('uuid', $uuid)->first();
        $this->assertEquals(PolicyState::ISSUED, $policyRequest->state);
        $this->assertEquals('POL-ORIGINAL-999', $policyRequest->policy_number);
    }
}
