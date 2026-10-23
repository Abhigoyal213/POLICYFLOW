<?php

namespace App\Http\Controllers;

use App\Enums\Destination;
use App\Enums\PlanCode;
use App\Enums\PolicyState;
use App\Http\Requests\CreatePolicyRequestRequest;
use App\Http\Resources\PolicyRequestResource;
use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use App\Services\PolicyStateMachine;
use App\Services\PremiumCalculator;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PolicyRequestController extends Controller
{
    public function __construct(
        private PremiumCalculator $premiumCalculator,
        private PolicyStateMachine $stateMachine,
        private IssuanceService $issuanceService,
    ) {}

    /**
     * POST /api/policy-requests
     *
     * Create a new policy request or safely return an existing one for the same agent_id + client_request_id.
     */
    public function store(CreatePolicyRequestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Check if a request with this agent_id + client_request_id already exists
        $existing = PolicyRequest::where('agent_id', $validated['agent_id'])
            ->where('client_request_id', $validated['client_request_id'])
            ->first();

        if ($existing) {
            return (new PolicyRequestResource($existing->load('stateHistory')))
                ->response()
                ->setStatusCode(200);
        }

        // Calculate premium
        $premium = $this->premiumCalculator->calculate(
            PlanCode::from($validated['plan_code']),
            Carbon::parse($validated['trip_start']),
            Carbon::parse($validated['trip_end']),
            Carbon::parse($validated['date_of_birth']),
            Destination::from($validated['destination']),
            (int) $validated['sum_insured'],
        );

        try {
            $policyRequest = PolicyRequest::create([
                'uuid' => Str::uuid()->toString(),
                'client_request_id' => $validated['client_request_id'],
                'agent_id' => $validated['agent_id'],
                'customer_name' => $validated['customer_name'],
                'date_of_birth' => $validated['date_of_birth'],
                'destination' => $validated['destination'],
                'trip_start' => $validated['trip_start'],
                'trip_end' => $validated['trip_end'],
                'plan_code' => $validated['plan_code'],
                'sum_insured' => $validated['sum_insured'],
                'premium' => $premium,
                'state' => PolicyState::RECEIVED,
            ]);

            // Record initial state and transition to VALIDATED
            $this->stateMachine->recordInitialState($policyRequest);
            $policyRequest = $this->stateMachine->transition(
                $policyRequest,
                PolicyState::VALIDATED,
                'premium_calculated',
                ['premium' => $premium],
            );

            return (new PolicyRequestResource($policyRequest->load('stateHistory')))
                ->response()
                ->setStatusCode(201);
        } catch (QueryException $e) {
            // Race condition: another request with the same agent_id + client_request_id was inserted
            // between our check and insert. Return the existing record.
            if ($this->isDuplicateKeyError($e)) {
                $existing = PolicyRequest::where('agent_id', $validated['agent_id'])
                    ->where('client_request_id', $validated['client_request_id'])
                    ->firstOrFail();

                return (new PolicyRequestResource($existing->load('stateHistory')))
                    ->response()
                    ->setStatusCode(200);
            }

            throw $e;
        }
    }

    /**
     * GET /api/policy-requests/{id}
     *
     * Retrieve a single policy request with its audit trail.
     */
    public function show(string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::where('uuid', $id)->with('stateHistory')->first();

        if (!$policyRequest) {
            return response()->json(['message' => 'Policy request not found.'], 404);
        }

        return (new PolicyRequestResource($policyRequest))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * GET /api/policy-requests
     *
     * List policy requests with optional status filter and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query();

        if ($request->has('status')) {
            $state = PolicyState::tryFrom($request->input('status'));
            if ($state) {
                $query->where('state', $state);
            }
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginated = $query->orderByDesc('created_at')->paginate($perPage);

        return PolicyRequestResource::collection($paginated)
            ->response()
            ->setStatusCode(200);
    }

    /**
     * POST /api/policy-requests/{id}/submit
     *
     * Start or safely resume issuance for a policy request.
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::where('uuid', $id)->first();

        if (!$policyRequest) {
            return response()->json(['message' => 'Policy request not found.'], 404);
        }

        $scenario = $request->input('scenario', 'SUCCESS');

        $result = $this->issuanceService->submit($policyRequest, $scenario);

        $statusCode = match ($result['action']) {
            'already_issued' => 200,
            'submitted' => 200,
            'invalid_state' => 409,
            default => 200,
        };

        $responseData = (new PolicyRequestResource($result['policy_request']->load('stateHistory')))->resolve();
        $responseData['action'] = $result['action'];

        return response()->json(['data' => $responseData], $statusCode);
    }

    /**
     * Check if a QueryException is a duplicate key violation.
     */
    private function isDuplicateKeyError(QueryException $e): bool
    {
        // MySQL: 23000 (integrity constraint), error code 1062 (duplicate entry)
        // SQLite: 23000, error code 19 (UNIQUE constraint failed)
        return in_array($e->errorInfo[0] ?? '', ['23000', '23505']);
    }
}
