<?php

namespace App\Http\Controllers;

use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use App\Enums\PolicyState;
use App\Services\PolicyStateMachine;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class MockInsurerController extends Controller
{
    public function __construct(
        private PolicyStateMachine $stateMachine,
    ) {}

    /**
     * POST /api/mock-insurer/submit
     *
     * Simulates submitting a policy to an external insurer.
     * Behavior is controlled by the 'scenario' field or X-Mock-Scenario header.
     */
    public function submit(Request $request): JsonResponse
    {
        $scenario = $request->header('X-Mock-Scenario', $request->input('scenario', 'SUCCESS'));
        $providerReference = 'PROV-' . Str::upper(Str::random(8));
        $policyNumber = 'POL-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);

        return match ($scenario) {
            'SUCCESS' => $this->handleSuccess($request, $providerReference, $policyNumber),
            'TEMPORARY_FAILURE' => $this->handleTemporaryFailure(),
            'TIMEOUT_AFTER_ISSUE' => $this->handleTimeoutAfterIssue($request, $providerReference, $policyNumber),
            'DELAYED_CALLBACK' => $this->handleDelayedCallback($request, $providerReference),
            'DUPLICATE_CALLBACK' => $this->handleDuplicateCallback($request, $providerReference),
            default => response()->json(['error' => 'Unknown scenario'], 400),
        };
    }

    /**
     * GET /api/mock-insurer/status/{providerReference}
     *
     * Reconciliation endpoint: check if a policy was issued by the mock insurer.
     */
    public function status(string $providerReference): JsonResponse
    {
        $policy = MockInsurerPolicy::where('provider_reference', $providerReference)->first();

        if (!$policy) {
            return response()->json([
                'found' => false,
                'message' => 'No policy found for this reference.',
            ], 404);
        }

        return response()->json([
            'found' => true,
            'provider_reference' => $policy->provider_reference,
            'policy_number' => $policy->policy_number,
            'status' => $policy->status,
        ]);
    }

    /**
     * POST /api/mock-insurer/callback
     *
     * Receives a simulated provider callback event.
     * Uses event_id uniqueness to ensure idempotent processing.
     */
    public function callback(Request $request): JsonResponse
    {
        $request->validate([
            'event_id' => 'required|string|max:100',
            'provider_reference' => 'required|string',
            'event_type' => 'required|string',
        ]);

        $eventId = $request->input('event_id');

        // Try to insert the event — unique constraint on event_id prevents duplicates
        try {
            $policyRequest = PolicyRequest::where('provider_reference', $request->input('provider_reference'))
                ->orWhere('uuid', $request->input('provider_reference'))
                ->first();

            if (!$policyRequest) {
                return response()->json([
                    'message' => 'Policy request not found for the given provider reference.',
                ], 404);
            }

            $event = ProviderEvent::create([
                'event_id' => $eventId,
                'policy_request_id' => $policyRequest->id,
                'event_type' => $request->input('event_type'),
                'payload' => $request->all(),
                'processed' => false,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Duplicate event_id — already processed or being processed
            if (in_array($e->errorInfo[0] ?? '', ['23000', '23505'])) {
                return response()->json([
                    'message' => 'Event already received.',
                    'event_id' => $eventId,
                ], 200);
            }
            throw $e;
        }

        // Process the event
        return DB::transaction(function () use ($policyRequest, $event, $request) {
            $locked = PolicyRequest::lockForUpdate()->find($policyRequest->id);

            // If already ISSUED, skip processing
            if ($locked->state === PolicyState::ISSUED) {
                $event->update(['processed' => true, 'processed_at' => now()]);
                return response()->json([
                    'message' => 'Event received but policy already issued.',
                    'event_id' => $event->event_id,
                ], 200);
            }

            $eventType = $request->input('event_type');

            if ($eventType === 'POLICY_ISSUED') {
                $locked->policy_number = $request->input('policy_number') ?? ('POL-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT));
                $locked->provider_reference = $request->input('provider_reference');
                $locked->save();

                // Transition to ISSUED
                try {
                    $this->stateMachine->transition($locked, PolicyState::ISSUED, 'callback_' . $eventType);
                } catch (\App\Exceptions\InvalidStateTransitionException $e) {
                    $event->update(['processed' => true, 'processed_at' => now()]);
                    return response()->json([
                        'message' => 'Event received but transition not applicable.',
                        'event_id' => $event->event_id,
                    ], 200);
                }
            } elseif ($eventType === 'POLICY_FAILED') {
                $locked->error_details = $request->input('reason', 'Provider indicated failure');
                $locked->save();

                try {
                    $this->stateMachine->transition($locked, PolicyState::FAILED, 'callback_' . $eventType);
                } catch (\App\Exceptions\InvalidStateTransitionException $e) {
                    // Swallow — state was already terminal or transition not valid
                }
            }

            $event->update(['processed' => true, 'processed_at' => now()]);

            return response()->json([
                'message' => 'Event processed successfully.',
                'event_id' => $event->event_id,
            ], 200);
        });
    }

    // --- Private scenario handlers ---

    private function handleSuccess(Request $request, string $providerReference, string $policyNumber): JsonResponse
    {
        // Store in mock insurer's internal records
        MockInsurerPolicy::create([
            'provider_reference' => $providerReference,
            'policy_number' => $policyNumber,
            'status' => 'ISSUED',
            'request_data' => $request->all(),
        ]);

        return response()->json([
            'status' => 'ISSUED',
            'provider_reference' => $providerReference,
            'policy_number' => $policyNumber,
        ], 200);
    }

    private function handleTemporaryFailure(): JsonResponse
    {
        return response()->json([
            'error' => 'Service temporarily unavailable. Please retry later.',
        ], 503);
    }

    private function handleTimeoutAfterIssue(Request $request, string $providerReference, string $policyNumber): JsonResponse
    {
        // The insurer actually issues the policy internally...
        MockInsurerPolicy::create([
            'provider_reference' => $providerReference,
            'policy_number' => $policyNumber,
            'status' => 'ISSUED',
            'request_data' => $request->all(),
        ]);

        // ...but the client gets a timeout/error response
        return response()->json([
            'error' => 'Request timed out.',
            'provider_reference' => $providerReference,
        ], 504);
    }

    private function handleDelayedCallback(Request $request, string $providerReference): JsonResponse
    {
        // Return pending — the callback will be sent separately
        return response()->json([
            'status' => 'PENDING',
            'provider_reference' => $providerReference,
            'message' => 'Request accepted. Confirmation will be sent via callback.',
        ], 202);
    }

    private function handleDuplicateCallback(Request $request, string $providerReference): JsonResponse
    {
        // Same as delayed — the test will manually send the callback multiple times
        return response()->json([
            'status' => 'PENDING',
            'provider_reference' => $providerReference,
            'message' => 'Request accepted. Multiple callbacks will be sent.',
        ], 202);
    }
}
