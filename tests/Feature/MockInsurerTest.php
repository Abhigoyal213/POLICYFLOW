<?php

namespace Tests\Feature;

use App\Models\MockInsurerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MockInsurerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SUCCESS scenario returns 200, policy_number, and provider_reference.
     */
    public function test_mock_insurer_success_scenario(): void
    {
        $response = $this->postJson('/api/mock-insurer/submit', [
            'scenario' => 'SUCCESS',
            'customer_name' => 'Alice Test',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'ISSUED');
        $this->assertNotNull($response->json('provider_reference'));
        $this->assertNotNull($response->json('policy_number'));

        // Policy is stored in mock_insurer_policies table
        $this->assertDatabaseHas('mock_insurer_policies', [
            'provider_reference' => $response->json('provider_reference'),
            'policy_number' => $response->json('policy_number'),
            'status' => 'ISSUED',
        ]);
    }

    /**
     * TEMPORARY_FAILURE scenario returns 503.
     */
    public function test_mock_insurer_temporary_failure_scenario(): void
    {
        $response = $this->postJson('/api/mock-insurer/submit', [
            'scenario' => 'TEMPORARY_FAILURE',
        ]);

        $response->assertStatus(503);
        $response->assertJsonStructure(['error']);
    }

    /**
     * TIMEOUT_AFTER_ISSUE scenario creates policy internally but returns 504.
     */
    public function test_mock_insurer_timeout_after_issue_scenario(): void
    {
        $response = $this->postJson('/api/mock-insurer/submit', [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $response->assertStatus(504);
        $ref = $response->json('provider_reference');
        $this->assertNotNull($ref);

        // Verify policy was created internally despite caller receiving timeout
        $this->assertDatabaseHas('mock_insurer_policies', [
            'provider_reference' => $ref,
            'status' => 'ISSUED',
        ]);

        // Status lookup can find the policy
        $statusResponse = $this->getJson("/api/mock-insurer/status/{$ref}");
        $statusResponse->assertStatus(200);
        $statusResponse->assertJsonPath('found', true);
        $statusResponse->assertJsonPath('status', 'ISSUED');
    }

    /**
     * DELAYED_CALLBACK scenario returns 202 accepted with provider_reference.
     */
    public function test_mock_insurer_delayed_callback_scenario(): void
    {
        $response = $this->postJson('/api/mock-insurer/submit', [
            'scenario' => 'DELAYED_CALLBACK',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('status', 'PENDING');
        $this->assertNotNull($response->json('provider_reference'));
    }

    /**
     * Status endpoint returns 404 for unknown provider reference.
     */
    public function test_status_endpoint_returns_404_for_unknown_reference(): void
    {
        $response = $this->getJson('/api/mock-insurer/status/UNKNOWN-REF');

        $response->assertStatus(404);
        $response->assertJsonPath('found', false);
    }
}
