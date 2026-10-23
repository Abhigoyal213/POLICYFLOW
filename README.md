# TripperShield — Travel Insurance Processing System

A robust travel-insurance policy distribution and intake engine built with **PHP 8.2+** and **Laravel 12**.

TripperShield receives policy purchase requests from external agents, calculates deterministic premiums with decimal-safe arithmetic, submits requests to external or simulated insurers, tracks them through explicit state lifecycles, and guarantees idempotency and duplicate prevention under concurrent or unreliable network conditions.

---

## 1. Primary Engineering Objectives & Architecture

When distributing travel insurance through unreliable third-party providers (timeouts, dropped network calls, delayed or duplicated webhooks), naive implementations risk:
1. **Duplicate Issuance**: Charging customers multiple times or issuing duplicate insurance certificates for repeated submissions.
2. **State Regression**: Having a confirmed policy downgraded or overwritten by a delayed failure callback.
3. **Floating-point rounding drifts**: Subtle financial calculation discrepancies.

### Multi-Layer Defense Architecture

```
Agent Requests / Callbacks
           │
           ▼
┌────────────────────────────────────────┐
│  Layer 1: Application-Level Idempotency│
│  - firstOrCreate / pre-flight checks   │
│  - Strict Form Request validation      │
└──────────────────┬─────────────────────┘
                   │
                   ▼
┌────────────────────────────────────────┐
│  Layer 2: Concurrency & Isolation      │
│  - DB Transactions (BEGIN ... COMMIT)  │
│  - Pessimistic Row Locking (FOR UPDATE)│
│  - Terminal state validation           │
└──────────────────┬─────────────────────┘
                   │
                   ▼
┌────────────────────────────────────────┐
│  Layer 3: Database-Level Constraints   │
│  - UNIQUE(agent_id, client_request_id) │
│  - UNIQUE(policy_number)               │
│  - UNIQUE(event_id)                    │
└────────────────────────────────────────┘
```

---

## 2. Technology Stack & Local Setup

- **Language**: PHP 8.2+ (tested on PHP 8.2.12)
- **Framework**: Laravel 12 (latest LTS-compatible skeleton)
- **Database**: SQLite (in-memory for automated tests), MySQL 8+ / PostgreSQL ready
- **Arithmetic Engine**: PHP BCMath extension / `brick/math` (zero floating-point math)
- **Testing**: PHPUnit 11

### Quickstart Setup

1. **Clone & Install Dependencies**:
   ```bash
   git clone <repo-url> TripperShield
   cd TripperShield
   composer install
   ```

2. **Environment Configuration**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Run Database Migrations**:
   ```bash
   # Default uses SQLite database or configure MySQL in .env
   php artisan migrate
   ```

4. **Start the Local Server**:
   ```bash
   php artisan serve
   ```
   The API will be available at `http://localhost:8000`.

5. **Run the Automated Test Suite**:
   ```bash
   php vendor/bin/phpunit
   # or
   php artisan test
   ```

---

## 3. Data Model & Migrations

Four normalized tables enforce relational integrity and auditability:

1. `policy_requests`:
   - `uuid` (CHAR(36) UNIQUE) — Public identifier.
   - `client_request_id` (VARCHAR(100)) & `agent_id` (VARCHAR(100)).
   - **`UNIQUE KEY uq_agent_client (agent_id, client_request_id)`** — Prevents duplicate intake at the database engine level.
   - `policy_number` (VARCHAR(100) UNIQUE NULL) — Set only when transitioning to `ISSUED`.
   - `state` (VARCHAR(30)) — Backed by `PolicyState` PHP Enum.
   - `premium` (DECIMAL(12,2)) — Immutable pricing snapshot.
   - `attempt_count` (UNSIGNED INT) — Tracks provider submission count.
   - `last_provider_response` (JSON) & `error_details` (TEXT).

2. `issuance_attempts`:
   - Tracks every provider submission attempt with request payload, response payload, HTTP status, and attempt timestamp.
   - **`UNIQUE KEY uq_request_attempt (policy_request_id, attempt_number)`**.

3. `provider_events`:
   - Stores incoming insurer callbacks.
   - **`UNIQUE KEY (event_id)`** — Guarantees duplicate callback deliveries are ignored.

4. `policy_state_history`:
   - Complete audit trail of transitions (`from_state`, `to_state`, `trigger`, `metadata`, `created_at`).

5. `mock_insurer_policies`:
   - Stores policies issued internally by the mock insurer to facilitate reconciliation lookups (`GET /api/mock-insurer/status/{providerReference}`).

---

## 4. State Machine & Lifecycle

Allowed states: `RECEIVED`, `VALIDATED`, `SUBMITTED`, `PENDING_CONFIRMATION`, `ISSUED`, `FAILED`, `NEEDS_RECONCILIATION`.

```
                    ┌──────────────┐
                    │   RECEIVED   │
                    └──────┬───────┘
                           │ (calculate premium)
                           ▼
                    ┌──────────────┐
                    │  VALIDATED   │
                    └──────┬───────┘
                           │ (submit)
                           ▼
                    ┌──────────────┐
                    │  SUBMITTED   │
                    └──┬───┬───┬───┘
       ┌───────────────┘   │   └───────────────┐
       │ (pending)         │ (success)         │ (failure)
       ▼                   ▼                   ▼
┌──────────────────┐ ┌──────────┐      ┌────────────┐
│PENDING_CONFIRMAT.│ │  ISSUED  │◄─────┤   FAILED   │
└────────┬─────────┘ └──────────┘      └──────┬─────┘
         │ (callback)      ▲                  │ (retry)
         └─────────────────┘                  └─────────► SUBMITTED
```

- **Terminal State**: `ISSUED` is irreversible. No transition out of `ISSUED` is permitted.
- **Race Condition Protection**: Every state transition runs inside a `DB::transaction()` with a pessimistic row lock (`lockForUpdate()`).

---

## 5. Premium Calculation Logic

The pricing formula is strictly deterministic and uses decimal-safe arithmetic (`bcmul` / `bcadd`):

$$\text{Premium} = \text{base\_daily\_rate} \times \text{travel\_days} \times \text{age\_multiplier} \times \text{destination\_multiplier} \times \text{coverage\_multiplier}$$

| Factor | Lookup Rules |
|---|---|
| **Base daily rate** | `BASIC` = ₹12; `PLUS` = ₹20; `PREMIUM` = ₹30 |
| **Travel days** | Inclusive: $\text{trip\_end} - \text{trip\_start} + 1$; minimum 1 |
| **Age multiplier** | Age at trip start: $0–17 \rightarrow 1.10$; $18–60 \rightarrow 1.00$; $61–70 \rightarrow 1.50$; $71+ \rightarrow 2.00$ |
| **Destination multiplier** | `DOMESTIC` = 1.00; `INTERNATIONAL` = 1.80 |
| **Coverage multiplier** | ₹100,000 = 1.00; ₹300,000 = 1.25; ₹500,000 = 1.50 (Others rejected) |

**Spec Verification**:
- Plan: `PLUS` (₹20)
- Days: 2026-11-10 to 2026-11-14 = 5 days
- Age: Born 1998-04-10 $\rightarrow$ 28 years old (1.00)
- Destination: `INTERNATIONAL` (1.80)
- Sum Insured: ₹300,000 (1.25)
$$\text{Premium} = 20 \times 5 \times 1.00 \times 1.80 \times 1.25 = ₹225.00$$

---

## 6. Implemented Reliability Cases

### Case 1: Duplicate Intake (Near-Simultaneous Submissions)
- **Problem**: An agent's system or browser re-submits the exact same `agent_id` + `client_request_id` rapidly due to double-clicks or retry logic.
- **Solution**:
  1. The controller checks for existing records matching `(agent_id, client_request_id)`.
  2. If a concurrent race condition bypasses the read check, the database engine enforces the **`UNIQUE (agent_id, client_request_id)`** constraint.
  3. The controller intercepts the `QueryException` (SQLState `23000`), fetches the pre-existing record, and returns **HTTP 200** with the existing payload (instead of HTTP 201).
- **Verified by**: `PolicyRequestIntakeTest::test_duplicate_request_returns_existing` and `IssuanceWorkflowTest::test_duplicate_intake_near_simultaneous` (5 concurrent requests return 1x 201 and 4x 200 with the exact same UUID).

### Case 2: Duplicate Callback (Idempotent Event Processing)
- **Problem**: Insurers or payment gateways frequently retry webhook deliveries until an acknowledgment is received, which can lead to double processing.
- **Solution**:
  1. Incoming callback requests must provide an `event_id`.
  2. Before executing business logic, the application attempts to insert the event into `provider_events` with a unique index on `event_id`.
  3. If a duplicate `event_id` is encountered, the database constraint rejects the second insert, and the endpoint immediately returns **HTTP 200** with `{"message": "Event already received."}` without re-executing any transitions.
  4. In addition, if a policy is already `ISSUED`, any late callback (even with a new `event_id`) is prevented from altering the policy.
- **Verified by**: `IssuanceWorkflowTest::test_duplicate_callback_processed_only_once` and `IssuanceWorkflowTest::test_callback_cannot_overwrite_confirmed_issued_state`.

---

## 7. How We Solve the Remaining Reliability Cases

### Case 3: Timeout After Success
- **Problem**: When our system calls `POST /submit` on the external insurer, the network connection times out (or drops) after the insurer has internally issued the policy. Blindly retrying would generate duplicate policies at the provider.
- **Implementation in TripperShield**:
  1. When a timeout occurs (`ConnectionException` or HTTP 504), `IssuanceService` marks the attempt as `TIMEOUT`.
  2. Before setting the state to `FAILED` or attempting any reissue, the service immediately invokes the mock insurer's reconciliation endpoint: `GET /api/mock-insurer/status/{providerReference}`.
  3. If the insurer confirms the policy was issued, TripperShield transitions the state directly to `ISSUED` and records the policy number.
  4. If status lookup is inconclusive, the request transitions to `NEEDS_RECONCILIATION`.
  5. A scheduled background reconciliation job (`php artisan policy:reconcile`) periodically queries the provider for all `NEEDS_RECONCILIATION` or stale `PENDING_CONFIRMATION` requests.
- **Verified by**: `IssuanceWorkflowTest::test_timeout_resolved_via_reconciliation_to_issued` and `IssuanceWorkflowTest::test_timeout_unresolved_transitions_to_needs_reconciliation`.

### Case 4: Retry After Temporary Failure
- **Problem**: The external insurer returns a temporary error (HTTP 503). Retrying must not produce duplicate policies or tamper with the agreed-upon initial premium.
- **Implementation in TripperShield**:
  1. On HTTP 503, the request transitions to `FAILED`.
  2. The calculated `premium` field on `policy_requests` is immutable.
  3. The `POST /api/policy-requests/{id}/submit` endpoint explicitly allows retrying requests that are in the `FAILED` state.
  4. Re-submitting increments `attempt_count` and creates a new row in `issuance_attempts` with a unique `(policy_request_id, attempt_number)` constraint.
  5. In production, this is orchestrated via a queued job with exponential backoff and jitter (`$backoff = [10, 30, 90]`) to protect the downstream provider.
- **Verified by**: `IssuanceWorkflowTest::test_failed_submission_allows_retry`.

---

## 8. API Contract & Response Formats

### 1. Create Policy Request
`POST /api/policy-requests`

**Request Body**:
```json
{
  "client_request_id": "AGT-2026-0001",
  "agent_id": "AGENT-17",
  "customer_name": "Sample Customer",
  "date_of_birth": "1998-04-10",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-10",
  "trip_end": "2026-11-14",
  "plan_code": "PLUS",
  "sum_insured": 300000
}
```

**Response (HTTP 201 Created / HTTP 200 Repeated)**:
```json
{
  "data": {
    "id": "c1a93b4f-83de-4ce0-9884-63c633bb9671",
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000,
    "premium": "225.00",
    "state": "VALIDATED",
    "policy_number": null,
    "provider_reference": null,
    "attempt_count": 0,
    "created_at": "2026-10-23T21:40:00+00:00",
    "updated_at": "2026-10-23T21:40:00+00:00",
    "state_history": [
      {
        "from_state": null,
        "to_state": "RECEIVED",
        "trigger": "api_intake",
        "metadata": null,
        "created_at": "2026-10-23T21:40:00+00:00"
      },
      {
        "from_state": "RECEIVED",
        "to_state": "VALIDATED",
        "trigger": "premium_calculated",
        "metadata": { "premium": "225.00" },
        "created_at": "2026-10-23T21:40:00+00:00"
      }
    ]
  }
}
```

---

### 2. Retrieve Status & Audit Trail
`GET /api/policy-requests/{id}`

- Accepts UUID.
- Returns current state, calculated premium, references, and full transition history.
- Returns HTTP 404 if not found.

---

### 3. Submit for Issuance
`POST /api/policy-requests/{id}/submit`

**Optional Body / Header**:
```json
{
  "scenario": "SUCCESS" // Options: SUCCESS, TEMPORARY_FAILURE, TIMEOUT_AFTER_ISSUE, DELAYED_CALLBACK, DUPLICATE_CALLBACK
}
```

**Response (HTTP 200 OK)**:
```json
{
  "data": {
    "id": "c1a93b4f-83de-4ce0-9884-63c633bb9671",
    "state": "ISSUED",
    "policy_number": "POL-2026-00001",
    "provider_reference": "PROV-REF-001",
    "attempt_count": 1,
    "action": "submitted"
  }
}
```

If the policy is already `ISSUED`, the response returns:
```json
{
  "data": {
    "id": "c1a93b4f-83de-4ce0-9884-63c633bb9671",
    "state": "ISSUED",
    "policy_number": "POL-2026-00001",
    "action": "already_issued"
  }
}
```

---

### 4. Receive Insurer Callback
`POST /api/mock-insurer/callback`

**Request Body**:
```json
{
  "event_id": "EVT-2026-998811",
  "provider_reference": "PROV-REF-001",
  "event_type": "POLICY_ISSUED",
  "policy_number": "POL-2026-00001"
}
```

**Response (HTTP 200 OK)**:
```json
{
  "message": "Event processed successfully.",
  "event_id": "EVT-2026-998811"
}
```
If repeated with the same `event_id`:
```json
{
  "message": "Event already received.",
  "event_id": "EVT-2026-998811"
}
```

---

### 5. List Policy Requests
`GET /api/policy-requests?status=VALIDATED&page=1&per_page=15`

- Filters by state (`RECEIVED`, `VALIDATED`, `ISSUED`, etc.).
- Paginated response with standard Laravel meta and links.

---

## 9. Simplifying Assumptions

1. **Mock Insurer Hosting**: The mock insurer is co-located in the same application for simplicity, accessible via dedicated routes (`/api/mock-insurer/*`). No external microservice deployment is required.
2. **Synchronous Execution**: The core workflow executes synchronously to provide immediate testability without requiring a running queue worker. The architecture is modular and ready for queue dispatching (`dispatch(new SubmitToProviderJob(...))`).
3. **Currency**: All amounts are in Indian Rupees (INR ₹).
4. **Authentication**: Authentication is omitted to prioritize the core idempotency and concurrency problem as permitted by the instructions.
