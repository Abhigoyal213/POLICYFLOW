<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyRequestIntakeTest extends TestCase
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

    /**
     * A valid request should return 201 with the correct premium and VALIDATED state.
     */
    public function test_valid_request_creates_policy_with_correct_premium(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('data.premium', '225.00');
        $response->assertJsonPath('data.state', 'VALIDATED');
        $response->assertJsonPath('data.customer_name', 'Sample Customer');
        $response->assertJsonPath('data.plan_code', 'PLUS');
        $response->assertJsonPath('data.destination', 'INTERNATIONAL');
        $response->assertJsonStructure([
            'data' => [
                'id', 'client_request_id', 'agent_id', 'customer_name',
                'date_of_birth', 'destination', 'trip_start', 'trip_end',
                'plan_code', 'sum_insured', 'premium', 'state',
                'policy_number', 'provider_reference', 'attempt_count',
                'created_at', 'updated_at', 'state_history',
            ],
        ]);
    }

    /**
     * Repeated submission with same agent_id + client_request_id returns 200 with the same UUID.
     */
    public function test_duplicate_request_returns_existing(): void
    {
        $payload = $this->validPayload();

        $first = $this->postJson('/api/policy-requests', $payload);
        $first->assertStatus(201);
        $firstId = $first->json('data.id');

        $second = $this->postJson('/api/policy-requests', $payload);
        $second->assertStatus(200);
        $secondId = $second->json('data.id');

        $this->assertEquals($firstId, $secondId);

        // Should still be only 1 record in the database
        $this->assertDatabaseCount('policy_requests', 1);
    }

    /**
     * Different agent_id with same client_request_id creates a new request.
     */
    public function test_different_agent_creates_separate_request(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload())->assertStatus(201);

        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'agent_id' => 'AGENT-42',
        ]));
        $response->assertStatus(201);

        $this->assertDatabaseCount('policy_requests', 2);
    }

    /**
     * trip_end before trip_start should return 422.
     */
    public function test_invalid_trip_dates_rejected(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'trip_start' => '2026-11-14',
            'trip_end' => '2026-11-10',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['trip_end']);
    }

    /**
     * Unsupported plan_code should return 422.
     */
    public function test_unsupported_plan_rejected(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'plan_code' => 'GOLD',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['plan_code']);
    }

    /**
     * Unsupported sum_insured should return 422.
     */
    public function test_unsupported_sum_insured_rejected(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'sum_insured' => 200000,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sum_insured']);
    }

    /**
     * Missing required fields should return 422.
     */
    public function test_missing_required_fields_rejected(): void
    {
        $response = $this->postJson('/api/policy-requests', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'client_request_id',
            'agent_id',
            'customer_name',
            'date_of_birth',
            'destination',
            'trip_start',
            'trip_end',
            'plan_code',
            'sum_insured',
        ]);
    }

    /**
     * Invalid destination should return 422.
     */
    public function test_invalid_destination_rejected(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'destination' => 'MARS',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['destination']);
    }

    /**
     * GET /api/policy-requests/{id} should return the request with audit trail.
     */
    public function test_show_returns_request_with_state_history(): void
    {
        $create = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $create->json('data.id');

        $response = $this->getJson("/api/policy-requests/{$uuid}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $uuid);
        $response->assertJsonPath('data.state', 'VALIDATED');
        // Should have 2 state history entries: RECEIVED and RECEIVED→VALIDATED
        $this->assertCount(2, $response->json('data.state_history'));
    }

    /**
     * GET /api/policy-requests/{id} with non-existent UUID returns 404.
     */
    public function test_show_nonexistent_returns_404(): void
    {
        $response = $this->getJson('/api/policy-requests/non-existent-uuid');
        $response->assertStatus(404);
    }

    /**
     * GET /api/policy-requests should return paginated results.
     */
    public function test_index_returns_paginated_results(): void
    {
        // Create 3 requests
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/policy-requests', $this->validPayload([
                'client_request_id' => "AGT-2026-000{$i}",
            ]))->assertStatus(201);
        }

        $response = $this->getJson('/api/policy-requests');
        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    /**
     * GET /api/policy-requests?status=VALIDATED should filter by state.
     */
    public function test_index_filters_by_status(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload())->assertStatus(201);

        $response = $this->getJson('/api/policy-requests?status=VALIDATED');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));

        $response = $this->getJson('/api/policy-requests?status=ISSUED');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }
}
