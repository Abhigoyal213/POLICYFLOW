# TripperShield — Technical Interview Preparation Guide

This guide will help you confidently explain your project, defend your architectural choices, and answer technical questions during your interview.

---

## 1. The 60-Second Elevator Pitch

> *"The business problem is building a distributor platform for travel insurance that interacts with an unreliable external insurer. In real-world insurance distribution, the greatest risk is **duplicate issuance** or **data corruption** caused by network timeouts, double-clicks, and duplicate callbacks.*
> 
> *To solve this, I designed TripperShield with a **3-layer defense architecture**:*
> 1. *Strict input validation and decimal-safe financial math.*
> 2. *An explicit, forward-only finite state machine where `ISSUED` is terminal.*
> 3. *Database-level idempotency constraints paired with row-level pessimistic locking (`lockForUpdate()`) to handle concurrent race conditions.*
> 
> *The entire solution is verified with **37 automated tests across 170 assertions** covering all pricing formulas, transition paths, and edge cases."*

---

## 2. File Tour (What to Show When Screen Sharing)

If they ask: *"Walk us through your code,"* open these files in this exact order:

```
1. app/Enums/PolicyState.php          ──► Show the state machine rules & terminal state
2. database/migrations/               ──► Show the unique keys for concurrency defense
3. app/Services/PremiumCalculator.php ──► Show decimal-safe bcmath calculation
4. app/Services/PolicyStateMachine.php──► Show DB transaction + row-level locking
5. app/Http/Controllers/PolicyRequestController.php ──► Show intake idempotency (201 vs 200)
6. app/Services/IssuanceService.php   ──► Show timeout handling & reconciliation
7. tests/Feature/IssuanceWorkflowTest.php ──► Run tests live to prove reliability
```

---

## 3. Deep Dive into Key Components

### A. The Concurrency & Idempotency Strategy
**Why it matters:** Interviewers want to know you didn't just write simple CRUD, but considered distributed systems failure modes.

| Threat | How We Defend Against It | File Reference |
|---|---|---|
| **Duplicate Intake** (near-simultaneous submissions) | **App Level**: Checks existing `(agent_id, client_request_id)`.<br>**DB Level**: `UNIQUE KEY uq_agent_client(agent_id, client_request_id)`.<br>**Handling**: Intercepts SQL error code `23000` during race conditions and returns **HTTP 200** with the existing UUID. | `PolicyRequestController.php:38-100` |
| **Duplicate Callback** (provider sends webhook 3 times) | `provider_events` table enforces **`UNIQUE(event_id)`**.<br>Duplicate events are rejected at the DB insert level and return HTTP 200 immediately without re-executing state changes. | `MockInsurerController.php:84-113` |
| **Double Issuance on Retry** | `IssuanceService` checks state before calling the insurer. If already `ISSUED`, it returns the existing policy without hitting the provider. | `IssuanceService.php:28-59` |
| **Concurrent State Race Conditions** | State transitions execute inside `DB::transaction()` using `PolicyRequest::lockForUpdate()`. | `PolicyStateMachine.php:36-64` |

---

### B. Premium Calculation
**The Formula:**
$$\text{Premium} = \text{base\_daily\_rate} \times \text{travel\_days} \times \text{age\_multiplier} \times \text{destination\_multiplier} \times \text{coverage\_multiplier}$$

**Key Interview Question:** *"Why didn't you just use standard multiplication `$a * $b`?"*
> **Answer:** *"In financial and insurance systems, native IEEE 754 floating-point arithmetic introduces precision drift (e.g., `0.1 + 0.2 = 0.30000000000000004`). I used PHP's `bcmath` extension (`bcmul`, `bcdiv`, `bcadd`) with arbitrary precision and decimal-safe half-up rounding to guarantee exact financial accuracy."*

---

### C. The 4 Reliability Cases

The prompt required implementing at least two cases and explaining the others. TripperShield implements and tests all four:

#### Case 1: Duplicate Intake (Implemented & Tested)
- **Problem**: Agent submits `AGT-2026-0001` twice in rapid succession.
- **Handling**: First request returns HTTP 201 with UUID. Second request returns HTTP 200 with the *same* UUID. No duplicate rows in `policy_requests`.
- **Test**: `IssuanceWorkflowTest::test_duplicate_intake_near_simultaneous` (spawns 5 rapid requests, verifies 1x 201 and 4x 200).

#### Case 2: Duplicate Callback (Implemented & Tested)
- **Problem**: Insurer delivers identical webhook multiple times.
- **Handling**: Unique index on `event_id` in `provider_events`. Only the first event transitions the policy; subsequent events are ignored.
- **Test**: `IssuanceWorkflowTest::test_duplicate_callback_processed_only_once`.

#### Case 3: Timeout After Success (Implemented & Tested)
- **Problem**: Insurer issues the policy internally, but the HTTP connection drops or times out before the client gets the response. Blindly retrying would cause duplicate issuance at the insurer.
- **Handling**: When a timeout occurs, `IssuanceService` queries the provider's reconciliation endpoint `GET /api/mock-insurer/status/{providerReference}`. If the provider confirms the policy was created, we transition to `ISSUED`. If status is unknown, we transition to `NEEDS_RECONCILIATION`.
- **Test**: `IssuanceWorkflowTest::test_timeout_resolved_via_reconciliation_to_issued`.

#### Case 4: Retry After Temporary Failure (Implemented & Tested)
- **Problem**: Insurer returns HTTP 503 (temporary failure).
- **Handling**: The request transitions to `FAILED`. The submit endpoint allows re-submitting from `FAILED`. The original premium is immutable. Attempt count increments to track every attempt.
- **Test**: `IssuanceWorkflowTest::test_failed_submission_allows_retry`.

---

## 4. Expected Interview Questions & High-Score Answers

### Q1: "What happens if two agents submit requests with the exact same `client_request_id` at the exact same millisecond?"
> **Answer:**
> *"The unique constraint is `(agent_id, client_request_id)`. If it's the **same** agent, the database unique key prevents the second insert. The second thread catches the `QueryException` with SQLState 23000 and gracefully loads the first record, returning HTTP 200.*
> 
> *If they are **different** agents, both requests are legitimately created because each agent has their own client request ID namespace. This is explicitly covered in my test suite (`test_different_agent_creates_separate_request`)."*

---

### Q2: "Can a delayed callback saying `POLICY_FAILED` overwrite an already `ISSUED` policy?"
> **Answer:**
> *"No. In `PolicyState.php`, `ISSUED` is defined as a terminal state with an empty `allowedTransitions()` list. Furthermore, in `MockInsurerController.php`, the callback handler locks the row and checks if `state === ISSUED`. If so, it immediately short-circuits and ignores the failure event. I wrote a dedicated test `test_callback_cannot_overwrite_confirmed_issued_state` to prove this safeguard."*

---

### Q3: "How would you scale this system to handle 10,000 requests per minute?"
> **Answer:**
> *"1. **Asynchronous Queues**: Decouple intake from issuance. The intake API immediately validates, stores, and pushes a `SubmitPolicyToProviderJob` onto a Redis queue (returns 202 Accepted).*
> *2. **Rate Limiting & Backoff**: Add exponential backoff with jitter on retries so we don't overwhelm the insurer during outages.*
> *3. **Reconciliation Cron**: A scheduled worker running `php artisan policy:reconcile` every 5 minutes to sweep any requests stuck in `PENDING_CONFIRMATION` or `NEEDS_RECONCILIATION`.*
> *4. **Read Replicas**: Route `GET /api/policy-requests` queries to read replicas while writes use the primary database."*

---

### Q4: "Why did you use SQLite for tests and MySQL for production?"
> **Answer:**
> *"In `phpunit.xml`, SQLite in-memory (`:memory:`) provides blazing fast test execution—all 37 tests run in under 5 seconds. The migrations and queries use standard ANSI SQL and Laravel Eloquent constructs that run seamlessly on MySQL 8+ or PostgreSQL without dialect-specific dependencies."*

---

## 5. Live Commands Checklist (Have These Ready)

Keep a terminal open in your project directory during the interview:

```bash
# 1. Run all tests live to impress them:
php vendor/bin/phpunit

# 2. Show test output with descriptive names:
php vendor/bin/phpunit --testdox

# 3. Show route list:
php artisan route:list --path=api

# 4. Start local server:
php artisan serve
```
