# Masar × Mini Delivery Integration Contract V1

Status: Approved design baseline  
Contract version: `1.0`  
Direction: Mini Delivery → Masar only

## 1. Architecture decision summary

Mini Delivery is the source of external operational input and previous delivery-history facts. Masar consumes those facts and owns the current delivery state during its tour, readiness analysis, route generation, scoring, route re-evaluation, and change-impact classification.

V1 uses one authenticated event-ingestion endpoint with four explicit order event types. Events contain external identifiers, a per-order version, a current snapshot, and explicit old/new field changes where applicable. Raw completed delivery history is authoritative; calculated reception percentages are not transmitted in V1.

Mini Delivery sends every supported relevant change without deciding whether it affects a route and without assigning an impact classification.

## 2. Integration direction

```text
Mini Delivery
      |
      | assigned orders, customer facts, raw history, updates,
      | reassignment, cancellation
      v
    Masar
      |
      | readiness, route generation, route re-evaluation,
      | impact classification and route decisions
```

V1 does not define Masar-to-Mini-Delivery state synchronization.

## 3. System ownership

| Data / capability | Mini Delivery | Masar |
|---|---|---|
| Representative creation and active state | Owner | Consumer copy/reference |
| Customer creation and operational identity | Owner | Consumer copy/reference |
| Customer name and phone | Owner | External snapshot indexed by source and external ID |
| Delivery order creation | Owner | Consumer copy/reference |
| Order value and delivery location | Owner | Consumer input |
| Order assignment/reassignment | Owner | Consumer input for workload and route evaluation |
| Order cancellation | Owner | Consumer input for route evaluation |
| Historical completed-order result | Owner | Consumer analytical input |
| Current order status during a Masar tour | Does not synchronize it in V1 | Owner |
| Current delivery completion during a Masar tour | Does not synchronize it in V1 | Owner |
| Customer historical orders | Owner of raw facts | Consumer and independent calculator |
| Customer readiness | Input provider only | Owner |
| Route generation and scoring | No decision | Owner |
| Route re-evaluation | Sends unclassified changes | Owner |
| Change-impact classification | Must not classify | Owner |
| Proposed-route acceptance/rejection | No decision | Owner |

## 4. Source-of-truth matrix

| Data | Source of truth |
|---|---|
| Customer name | Mini Delivery |
| Customer phone | Mini Delivery |
| Representative identity/name/phone | Mini Delivery |
| Order value | Mini Delivery |
| Order location | Mini Delivery |
| Order assignment | Mini Delivery |
| External cancellation | Mini Delivery |
| Historical completed-order result | Mini Delivery |
| Current order status during a Masar tour | Masar |
| Current delivery completion during a Masar tour | Masar |
| Customer readiness | Masar |
| Proposed and active route | Masar |
| Route score | Masar |
| Route re-evaluation | Masar |
| Route-impact classification | Masar |
| Proposed-route acceptance/rejection | Masar |

The phrase “historical completed-order result” means a previous order fact used for customer reception analysis. It does not authorize Mini Delivery to update the current order's completion inside Masar. The current order status and delivery result during route execution are entered and owned in Masar by its representative workflow.

## 5. External identity strategy

Every externally referenced entity is identified by the pair `source_system` plus its external ID. Existing Mini Delivery integer IDs may be serialized as strings in V1.

```json
{
  "source_system": "mini_delivery",
  "external_order_id": "125",
  "external_customer_id": "42",
  "external_representative_id": "7"
}
```

Masar must keep its internal primary keys independent. It must never assume that its `orders.id`, `customers.id`, or `representatives.id` equals a Mini Delivery ID. Recommended uniqueness constraints on the Masar side use `(source_system, external_*_id)`.

## 6. Endpoint strategy

### Selected: generic event endpoint

```http
POST /api/integration/events
Authorization: Bearer <integration-token>
Content-Type: application/json
```

This endpoint is generic only at the transport envelope. Payload schemas remain explicit and validated by `event_type` and `contract_version`.

### Rejected alternative: separate endpoints

Examples considered:

```text
POST /api/integration/orders/assigned
POST /api/integration/orders/{externalOrderId}/updates
POST /api/integration/orders/{externalOrderId}/cancel
```

Separate endpoints are initially readable, but duplicate authentication, idempotency, version checks, persistence, and error handling. One endpoint better matches the event/outbox requirements while retaining only four well-defined schemas. It is also simpler to test end-to-end for this project.

## 7. Event types

| Event type | Meaning |
|---|---|
| `order.assigned` | First transmission after a new order is assigned |
| `order.updated` | Source-owned customer, value, or location change after initial transmission |
| `order.reassigned` | Representative changed on an already assigned order |
| `order.cancelled` | Explicit cancellation of a previously transmitted order |

Reassignment remains separate from `order.updated`. This keeps the previous and new workload owner unambiguous without creating many event types.

V1 has no `order.completed` event and does not transmit current-order completion through `order.updated`. Mini Delivery may retain `completed`, `delivered`, and `not_delivered` locally for previous customer history, but Masar owns completion and delivery result for the current order during its tour.

When `order.assigned` reaches Masar, Masar adds it to its assigned-order facts. If a route already exists, Masar—not Mini Delivery—decides whether re-evaluation is needed.

## 8. Common event envelope

```json
{
  "contract_version": "1.0",
  "source_system": "mini_delivery",
  "event_id": "0198a12e-2530-7d5a-a032-11a7e10778a9",
  "event_type": "order.assigned",
  "occurred_at": "2026-08-14T10:00:00Z",
  "order_version": 1,
  "data": {}
}
```

- `event_id` is a UUID, preferably UUIDv7, unique per logical event.
- `order_version` starts at `1` when the order is first transmitted and increments for every later transmitted order change.
- `occurred_at` records when the business change occurred, in UTC RFC 3339 format.
- `contract_version` versions the JSON contract. It is distinct from `order_version`.

## 9. Initial assigned-order contract

`order.assigned` carries one atomic snapshot:

```json
{
  "order": {
    "external_order_id": "125",
    "status": "assigned",
    "value": "120.00",
    "created_at": "2026-08-14T09:50:00Z",
    "assigned_at": "2026-08-14T10:00:00Z"
  },
  "customer": {
    "external_customer_id": "42",
    "name": "Ahmed Salem",
    "phone": "+218910000001"
  },
  "representative": {
    "external_representative_id": "7",
    "name": "Omar Ali",
    "phone": "+218920000007"
  },
  "location": {
    "location_link": "https://maps.example/loc/125",
    "latitude": "32.8872000",
    "longitude": "13.1913000"
  },
  "customer_history": []
}
```

Money and coordinates are JSON strings to preserve database decimal precision. Masar validates and converts them to its chosen exact numeric representation.

## 10. Customer history contract

V1 sends raw history only. Raw facts remain authoritative and let Masar independently compute company and representative-specific reception ratios. Precomputed percentages are intentionally omitted to avoid conflicting sources of truth.

Each entry contains only a completed order with an actual delivery result:

```json
{
  "external_order_id": "101",
  "external_representative_id": "7",
  "result": "delivered",
  "completed_at": "2026-08-10T13:20:00Z"
}
```

Rules:

- Include only `status = completed` with `result = delivered` or `not_delivered`.
- Exclude `new`, `assigned`, and `cancelled`.
- Cancellation never counts as `not_delivered`.
- Exclude the current `external_order_id` from its own history. V1 never uses the current order's completion as an inbound update to Masar.
- History is ordered by `completed_at` ascending, then `external_order_id`, for deterministic transfer.
- An empty array means no previous reception history.
- Inactive customers and representatives remain valid historical facts.

Masar derives:

```text
company denominator = all received history entries
company numerator   = entries where result = delivered
representative denominator = entries for the selected representative
representative numerator   = delivered entries for that representative
```

## 11. Order update contract

`order.updated` contains both:

1. `changed_fields`, for auditability and impact analysis.
2. `current_snapshot`, containing the current Mini-Delivery-owned external fields for recovery, reconciliation, and processing a newer event even if an intermediate version is delayed.

```json
{
  "changed_fields": {
    "value": {
      "old": "100.00",
      "new": "120.00"
    }
  },
  "current_snapshot": {
    "order": {},
    "customer": {},
    "representative": {},
    "location": {}
  }
}
```

Supported V1 paths in `changed_fields`:

```text
customer.name
customer.phone
order.value
location.location_link
location.latitude
location.longitude
```

Mini Delivery sends all supported relevant changes and does not include `affects_route`, `impact`, `minor`, `medium`, or `high`. Masar compares and classifies the change.

`current_snapshot` is not a full authoritative Masar order state. It contains source-owned external data only: external identity, customer data, value, location, and current external assignment. Masar must merge fields according to ownership and must never blindly replace its current tour status, delivery completion, result, or completion timestamp. For example, if Mini Delivery still regards an order as externally assigned after Masar's representative has delivered it, an update to the phone or value must not return the Masar order to `assigned`.

### Customer update fan-out

Customer identity (`external_customer_id`) is immutable for an order. V1 does not define `customer.updated`. A customer name or phone change creates a separate `order.updated` event for every order that:

1. previously completed a successful `order.assigned` transmission to Masar; and
2. remains active for integration.

“Active for integration” in V1 means the Mini Delivery order is still `assigned`, its initial assignment event was successfully transmitted, and no external cancellation event has closed it. Orders that are `new`, never transmitted, locally `completed` historical records, or `cancelled` are excluded. This is an intentionally simple local eligibility rule; it does not claim ownership of Masar's current tour status.

Each fan-out event increments that order's own `order_version`. V1 has no `customer_version`.

```text
Customer #8 phone changes

Order #100: sent, active, version 2 → order.updated version 3
Order #101: sent, active, version 5 → order.updated version 6
Order #102: not assigned/sent          → no event
```

Since Masar keys customers by `(source_system, external_customer_id)`, each processed event refreshes the same canonical external customer snapshot while retaining independent per-order ordering.

## 12. Cancellation contract

Cancellation uses `order.cancelled` with:

```json
{
  "changed_fields": {
    "order.status": { "old": "assigned", "new": "cancelled" },
    "order.cancelled_at": { "old": null, "new": "2026-08-14T11:00:00Z" }
  },
  "current_snapshot": {
    "order": {
      "external_order_id": "125",
      "status": "cancelled",
      "result": null,
      "cancelled_at": "2026-08-14T11:00:00Z"
    }
  }
}
```

The last assigned representative remains in the full snapshot. Cancellation always has `result = null`; it is never encoded as `not_delivered` and never lowers reception-rate history.

## 13. Reassignment contract

`order.reassigned` explicitly contains both representatives:

```json
{
  "previous_representative": {
    "external_representative_id": "7",
    "name": "Omar Ali",
    "phone": "+218920000007"
  },
  "new_representative": {
    "external_representative_id": "9",
    "name": "Sami Noor",
    "phone": null
  },
  "changed_fields": {
    "representative.external_representative_id": {
      "old": "7",
      "new": "9"
    }
  },
  "current_snapshot": {}
}
```

This event does not tell Masar how to modify a route. It only states the operational reassignment fact.

## 14. Idempotency strategy

Masar persists each `(source_system, event_id)` and a hash of the canonical payload.

- First valid receipt: process atomically and return `processed`.
- Same `event_id` and identical payload: do not process again; return HTTP 200 `already_processed`.
- Same `event_id` with a different payload: return HTTP 409 `idempotency_mismatch`.

Mini Delivery must reuse the same `event_id` for retries of the same logical event. A corrected or genuinely new event gets a new `event_id` and incremented `order_version`.

## 15. Version and ordering strategy

Per-order ordering is required in V1.

```text
order.assigned   → order_version 1
order.updated    → order_version 2
order.reassigned → order_version 3
order.cancelled  → order_version 4
```

Masar stores the highest processed version for `(source_system, external_order_id)`.

- Version greater than stored: process the full current snapshot and store the new version. Gaps are allowed because the snapshot is self-contained.
- Version lower than stored: do not apply; return HTTP 200 `ignored_stale`.
- Same version and same `event_id`: idempotent duplicate.
- Same version with another `event_id`: return HTTP 409 `version_conflict`.
- Any event other than `order.assigned` for an unknown external order: return HTTP 404 `external_order_not_found`.

This prevents an older event from overwriting a newer state while allowing recovery when version 3 arrives before version 2.

The current Mini Delivery schema does not persist `event_id` or `order_version`. Implementation must add persistent outbox/version support after this contract is approved; no schema is changed by this document.

## 16. Atomicity

Masar processes one event—including order, customer, representative, location, history, idempotency record, and stored order version—in one local database transaction. It either commits the whole event or none of it.

This is not a distributed transaction. Mini Delivery retains the event in its outbox and retries if it does not receive a successful response.

## 17. Authentication and transport security

V1 uses a static, high-entropy Bearer integration token over HTTPS.

```http
Authorization: Bearer <token>
```

This is smaller and sufficient for one trusted producer/consumer pair. Sanctum adds token-management machinery without a user context, HMAC adds canonicalization and clock complexity, and OAuth is disproportionate for V1.

Requirements:

- Store credentials in environment variables/secrets, never source code.
- Compare tokens safely on Masar.
- Use HTTPS outside local development.
- Never log the Authorization header or token.
- Log only necessary external IDs and event metadata; avoid unnecessary customer payload logging.
- Token rotation is an operational procedure; supporting two tokens briefly during rotation is recommended.

## 18. Health endpoint

```http
GET /api/integration/health
Authorization: Bearer <integration-token>
```

Response:

```json
{
  "success": true,
  "status": "ok"
}
```

It verifies authenticated reachability only and exposes no database, framework, host, route, or secret information.

## 19. Response contract

Processed event, HTTP 200:

```json
{
  "success": true,
  "event_id": "0198a12e-2530-7d5a-a032-11a7e10778a9",
  "status": "processed"
}
```

Duplicate, HTTP 200:

```json
{
  "success": true,
  "event_id": "0198a12e-2530-7d5a-a032-11a7e10778a9",
  "status": "already_processed"
}
```

Stale event, HTTP 200:

```json
{
  "success": true,
  "event_id": "0198a12e-2530-7d5a-a032-11a7e1077900",
  "status": "ignored_stale",
  "latest_order_version": 3
}
```

## 20. Error contract

Error envelope:

```json
{
  "success": false,
  "error": {
    "code": "validation_failed",
    "message": "The event payload is invalid.",
    "details": {
      "data.order.value": ["The value must be a decimal string."]
    }
  }
}
```

| HTTP | Code / behavior | Meaning |
|---|---|---|
| 400 | `malformed_json` | Request body is not valid JSON/envelope |
| 401 | `unauthenticated` | Missing or invalid integration token |
| 404 | `external_order_not_found` | Non-assignment event references unknown external order |
| 409 | `idempotency_mismatch` or `version_conflict` | Same identity/version conflicts with stored event |
| 422 | `validation_failed` | Valid JSON but invalid fields, enum values, or cross-field contract |
| 500 | `internal_error` | Unexpected Masar failure; no sensitive details returned |
| 503 | `temporarily_unavailable` | Masar cannot temporarily process events |

## 21. Retry policy

Mini Delivery retries:

- Network/connection errors.
- Timeouts where no definitive response was received.
- HTTP 500 and 503.
- Optionally HTTP 429 if Masar later implements throttling and returns `Retry-After`.

Mini Delivery normally does not retry 400, 401, 404, 409, or 422 automatically. These require configuration, contract, identity, or data correction. A 401 may be retried only after credentials are refreshed.

Recommended backoff: exponential delays with jitter, for example 5 seconds, 30 seconds, 2 minutes, 10 minutes, then periodic retries. The outbox retains the event until delivered or explicitly marked as requiring intervention. Idempotency makes uncertain retries safe.

## 22. Data dictionary

Legend: R = required, O = optional, N = nullable.

### Envelope

| Field | Type | Rule | Notes |
|---|---|---|---|
| `contract_version` | string | R | Exactly `1.0` for this contract |
| `source_system` | string | R | Exactly `mini_delivery` in V1 |
| `event_id` | UUID string | R | Unique logical event; reused for retries |
| `event_type` | enum string | R | One of the four defined event types |
| `occurred_at` | RFC 3339 UTC datetime | R | Business-event time |
| `order_version` | integer | R | Positive, monotonic per external order |
| `data` | object | R | Schema depends on event type |

### Order data by ownership

| Field | Type | Rule | Notes |
|---|---|---|---|
| `external_order_id` | string | R | Mini Delivery order ID as external identity |
| `value` | decimal string | R | Non-negative, two decimal places |
| `created_at` | datetime | R | UTC |
| `status` | enum | R only for assigned/cancel event | `assigned` is the initial admission fact; `cancelled` is an explicit external business change. It is omitted from ordinary update/reassignment snapshots. |
| `assigned_at` | datetime | R for assigned | Event occurrence time in V1 until a dedicated field exists |
| `result` | null | R for cancellation | Must be null. Current delivery results are Masar-owned and are never sent as updates. |
| `cancelled_at` | datetime | R for cancellation | External cancellation time |

Ownership rules for applying payloads:

| Field group | Ownership | Receiver rule |
|---|---|---|
| External IDs, customer name/phone, order value, location | Mini Delivery owned | Masar may update its external snapshot from the event |
| External assignment and reassignment | Mini Delivery owned change | Masar records the fact, then independently evaluates route/workload impact |
| External cancellation | Mini Delivery owned change | Masar records the cancellation fact, then independently applies Iteration 3 logic |
| Current tour status, current delivery result, current completion time | Masar owned | Never overwritten from an ordinary Mini Delivery snapshot |
| Previous completed order result and `completed_at` inside `customer_history` | Historical only, sourced from Mini Delivery | Used for historical analysis; never applied to the current order |

### Customer

| Field | Type | Rule | Notes |
|---|---|---|---|
| `external_customer_id` | string | R | External identity |
| `name` | string | R | Max 255 |
| `phone` | string | R | Operational contact value; no cross-system format assumption |

### Representative

| Field | Type | Rule | Notes |
|---|---|---|---|
| `external_representative_id` | string | R | External identity |
| `name` | string | R | Current Mini Delivery name |
| `phone` | string/null | O, N | Contact detail, not required for routing identity |

The representative object is required for assigned, reassigned, and cancellation of a previously assigned order. It may be nullable only where a cancellation legitimately occurred before assignment.

### Location

| Field | Type | Rule | Notes |
|---|---|---|---|
| `location_link` | string/null | R, N | Opaque location link; Masar must not require a strict URL shape |
| `latitude` | decimal string/null | R, N | Range -90 to 90 |
| `longitude` | decimal string/null | R, N | Range -180 to 180 |

At least one usable location representation is expected operationally, but V1 accepts all three as null because the current Mini Delivery schema permits it. Masar may mark such an order not ready; it must not invent coordinates.

### Customer history entry

| Field | Type | Rule | Notes |
|---|---|---|---|
| `external_order_id` | string | R | Must differ from current order ID |
| `external_representative_id` | string | R | Historical representative, active or inactive |
| `result` | enum | R | `delivered` or `not_delivered` only |
| `completed_at` | datetime | R | UTC; historical completion time |

### Change entry

| Field | Type | Rule | Notes |
|---|---|---|---|
| `changed_fields` | object | R for update/reassign/cancel | At least one supported path |
| `changed_fields.<path>.old` | matching field type/null | R, N | Previous value |
| `changed_fields.<path>.new` | matching field type/null | R, N | Current value |
| `current_snapshot` | object | R | Complete current source-owned external snapshot; it excludes Masar-owned tour status/result/completion |
| `previous_representative` | object | R for reassignment | Previous workload owner |
| `new_representative` | object | R for reassignment | New workload owner |

`customer_history` is required for `order.assigned`. It is optional on later events; if present it must obey the same raw-history rules and is treated as a replacement snapshot of previous history, not an append command.

## 23. Complete JSON examples

### Example 1 — Initial assigned order

```json
{
  "contract_version": "1.0",
  "source_system": "mini_delivery",
  "event_id": "0198a12e-2530-7d5a-a032-11a7e10778a9",
  "event_type": "order.assigned",
  "occurred_at": "2026-08-14T10:00:00Z",
  "order_version": 1,
  "data": {
    "order": {
      "external_order_id": "125",
      "status": "assigned",
      "value": "120.00",
      "created_at": "2026-08-14T09:50:00Z",
      "assigned_at": "2026-08-14T10:00:00Z"
    },
    "customer": {
      "external_customer_id": "42",
      "name": "Ahmed Salem",
      "phone": "+218910000001"
    },
    "representative": {
      "external_representative_id": "7",
      "name": "Omar Ali",
      "phone": "+218920000007"
    },
    "location": {
      "location_link": "https://maps.example/loc/125",
      "latitude": "32.8872000",
      "longitude": "13.1913000"
    },
    "customer_history": [
      {
        "external_order_id": "101",
        "external_representative_id": "7",
        "result": "delivered",
        "completed_at": "2026-08-10T13:20:00Z"
      },
      {
        "external_order_id": "108",
        "external_representative_id": "9",
        "result": "not_delivered",
        "completed_at": "2026-08-12T15:10:00Z"
      }
    ]
  }
}
```

### Example 2 — Customer name modification

```json
{
  "contract_version": "1.0",
  "source_system": "mini_delivery",
  "event_id": "0198a14a-1000-7a01-b200-000000000002",
  "event_type": "order.updated",
  "occurred_at": "2026-08-14T10:20:00Z",
  "order_version": 2,
  "data": {
    "changed_fields": {
      "customer.name": { "old": "Ahmed Salem", "new": "Ahmed M. Salem" }
    },
    "current_snapshot": {
      "order": { "external_order_id": "125", "value": "120.00", "created_at": "2026-08-14T09:50:00Z" },
      "customer": { "external_customer_id": "42", "name": "Ahmed M. Salem", "phone": "+218910000001" },
      "representative": { "external_representative_id": "7", "name": "Omar Ali", "phone": "+218920000007" },
      "location": { "location_link": "https://maps.example/loc/125", "latitude": "32.8872000", "longitude": "13.1913000" }
    }
  }
}
```

### Example 3 — Customer phone modification fan-out

The same customer change produces two independent events because orders `100` and `101` were transmitted and remain active. Order `102` was never sent, so it produces no event.

```json
{
  "contract_version": "1.0",
  "source_system": "mini_delivery",
  "event_id": "0198a14a-1000-7a01-b200-000000000103",
  "event_type": "order.updated",
  "occurred_at": "2026-08-14T10:25:00Z",
  "order_version": 3,
  "data": {
    "changed_fields": {
      "customer.phone": { "old": "+218910000001", "new": "+218910000099" }
    },
    "current_snapshot": {
      "order": { "external_order_id": "100", "value": "75.00", "created_at": "2026-08-14T08:00:00Z" },
      "customer": { "external_customer_id": "42", "name": "Ahmed M. Salem", "phone": "+218910000099" },
      "representative": { "external_representative_id": "7", "name": "Omar Ali", "phone": "+218920000007" },
      "location": { "location_link": "maps.example/100", "latitude": "32.8800000", "longitude": "13.1800000" }
    }
  }
}
```

```json
{
  "contract_version": "1.0",
  "source_system": "mini_delivery",
  "event_id": "0198a14a-1000-7a01-b200-000000000106",
  "event_type": "order.updated",
  "occurred_at": "2026-08-14T10:25:00Z",
  "order_version": 6,
  "data": {
    "changed_fields": {
      "customer.phone": { "old": "+218910000001", "new": "+218910000099" }
    },
    "current_snapshot": {
      "order": { "external_order_id": "101", "value": "95.00", "created_at": "2026-08-14T08:30:00Z" },
      "customer": { "external_customer_id": "42", "name": "Ahmed M. Salem", "phone": "+218910000099" },
      "representative": { "external_representative_id": "9", "name": "Sami Noor", "phone": null },
      "location": { "location_link": "maps.example/101", "latitude": "32.8900000", "longitude": "13.1900000" }
    }
  }
}
```

```text
Order #102: not assigned/sent → no integration event
```

### Example 4 — Order value modification

```json
{
  "contract_version": "1.0",
  "source_system": "mini_delivery",
  "event_id": "0198a14a-1000-7a01-b200-000000000003",
  "event_type": "order.updated",
  "occurred_at": "2026-08-14T10:30:00Z",
  "order_version": 3,
  "data": {
    "changed_fields": {
      "order.value": { "old": "120.00", "new": "135.50" }
    },
    "current_snapshot": {
      "order": { "external_order_id": "125", "value": "135.50", "created_at": "2026-08-14T09:50:00Z" },
      "customer": { "external_customer_id": "42", "name": "Ahmed M. Salem", "phone": "+218910000099" },
      "representative": { "external_representative_id": "7", "name": "Omar Ali", "phone": "+218920000007" },
      "location": { "location_link": "https://maps.example/loc/125", "latitude": "32.8872000", "longitude": "13.1913000" }
    }
  }
}
```

### Example 5 — Location modification

```json
{
  "contract_version": "1.0",
  "source_system": "mini_delivery",
  "event_id": "0198a14a-1000-7a01-b200-000000000004",
  "event_type": "order.updated",
  "occurred_at": "2026-08-14T10:40:00Z",
  "order_version": 4,
  "data": {
    "changed_fields": {
      "location.location_link": { "old": "https://maps.example/loc/125", "new": "https://maps.example/loc/125b" },
      "location.latitude": { "old": "32.8872000", "new": "32.8890000" },
      "location.longitude": { "old": "13.1913000", "new": "13.1950000" }
    },
    "current_snapshot": {
      "order": { "external_order_id": "125", "value": "135.50", "created_at": "2026-08-14T09:50:00Z" },
      "customer": { "external_customer_id": "42", "name": "Ahmed M. Salem", "phone": "+218910000099" },
      "representative": { "external_representative_id": "7", "name": "Omar Ali", "phone": "+218920000007" },
      "location": { "location_link": "https://maps.example/loc/125b", "latitude": "32.8890000", "longitude": "13.1950000" }
    }
  }
}
```

### Example 6 — Representative reassignment

```json
{
  "contract_version": "1.0",
  "source_system": "mini_delivery",
  "event_id": "0198a14a-1000-7a01-b200-000000000005",
  "event_type": "order.reassigned",
  "occurred_at": "2026-08-14T10:50:00Z",
  "order_version": 5,
  "data": {
    "previous_representative": { "external_representative_id": "7", "name": "Omar Ali", "phone": "+218920000007" },
    "new_representative": { "external_representative_id": "9", "name": "Sami Noor", "phone": null },
    "changed_fields": {
      "representative.external_representative_id": { "old": "7", "new": "9" }
    },
    "current_snapshot": {
      "order": { "external_order_id": "125", "value": "135.50", "created_at": "2026-08-14T09:50:00Z" },
      "customer": { "external_customer_id": "42", "name": "Ahmed M. Salem", "phone": "+218910000099" },
      "representative": { "external_representative_id": "9", "name": "Sami Noor", "phone": null },
      "location": { "location_link": "https://maps.example/loc/125b", "latitude": "32.8890000", "longitude": "13.1950000" }
    }
  }
}
```

### Example 7 — Cancellation

```json
{
  "contract_version": "1.0",
  "source_system": "mini_delivery",
  "event_id": "0198a14a-1000-7a01-b200-000000000006",
  "event_type": "order.cancelled",
  "occurred_at": "2026-08-14T11:00:00Z",
  "order_version": 6,
  "data": {
    "changed_fields": {
      "order.status": { "old": "assigned", "new": "cancelled" },
      "order.cancelled_at": { "old": null, "new": "2026-08-14T11:00:00Z" }
    },
    "current_snapshot": {
      "order": { "external_order_id": "125", "status": "cancelled", "result": null, "value": "135.50", "created_at": "2026-08-14T09:50:00Z", "cancelled_at": "2026-08-14T11:00:00Z" },
      "customer": { "external_customer_id": "42", "name": "Ahmed M. Salem", "phone": "+218910000099" },
      "representative": { "external_representative_id": "9", "name": "Sami Noor", "phone": null },
      "location": { "location_link": "https://maps.example/loc/125b", "latitude": "32.8890000", "longitude": "13.1950000" }
    }
  }
}
```

### Example 8 — Duplicate retry response

The exact Example 7 request is retried with the same event ID and payload:

```json
{
  "success": true,
  "event_id": "0198a14a-1000-7a01-b200-000000000006",
  "status": "already_processed"
}
```

### Example 9 — Stale/out-of-order response

After Masar has processed order version 6, delayed version 4 arrives:

```json
{
  "success": true,
  "event_id": "0198a14a-1000-7a01-b200-000000000004",
  "status": "ignored_stale",
  "latest_order_version": 6
}
```

## 24. Integration persistence requirements

No persistence is added in this design phase. Implementation will require:

### Mini Delivery — necessary

An `integration_outbox` or equivalent durable event table containing at least:

- `event_id` unique.
- `source_system`.
- `external_order_id`.
- `order_version`.
- `event_type`.
- canonical payload or reproducible immutable snapshot.
- delivery status.
- attempt count and next-attempt time.
- last error and delivered timestamp.

A durable per-order integration version is also necessary. It may be an `order_version` column on `delivery_orders` or a version maintained transactionally in the outbox/aggregate metadata. The business update and outbox event/version allocation must commit in the same Mini Delivery database transaction.

Customer-update fan-out additionally requires durable knowledge of which orders successfully transmitted `order.assigned` and remain active for integration. The future implementation may derive this from outbox delivery state plus the local order lifecycle or persist equivalent integration metadata. It must not fan out customer changes to orders that were never received by Masar. This requirement does not introduce `customer_version`; each generated event uses and increments its own order's version.

### Masar — necessary

A `received_integration_events` or equivalent table containing:

- `(source_system, event_id)` unique.
- payload hash.
- external order ID and order version.
- event type.
- processing status and timestamps.

Masar also needs an external-identity mapping and the highest applied order version, with unique `(source_system, external_order_id)` semantics.

These future schema changes are required for reliable idempotency, ordering, auditability, and retry. Exact table names and retention policy remain implementation details, not wire-contract fields.

## 25. Academic traceability

| Integration capability | Iteration 3 requirement proven |
|---|---|
| `order.assigned` reception | استقبال الطلبات المسندة |
| Customer snapshot in assigned/update events | استقبال بيانات العملاء |
| Raw completed `customer_history` | استقبال سجل العميل وتمكين حساب النسب مستقلًا |
| `order.updated` with old/new values and snapshot | استقبال تعديل الطلب |
| `order.reassigned` | وصول تغير الإسناد بوضوح |
| `order.cancelled` with null result | استقبال الإلغاء دون اعتباره فشل تسليم |
| No route-impact field in producer payload | التغيير يصل إلى مَسار غير مصنف |
| Masar-owned readiness, scoring, and impact | بقاء قرار أثر التغيير وإعادة التقييم لدى مَسار |
| Customer change fan-out through per-order `order.updated` | تحديث بيانات العميل لكل workload نشط وصل إلى مَسار |
| Current delivery status remains Masar-owned | تحديث حالة الطلب أثناء الجولة داخل مَسار |
| Event ID and order version | إثبات تحمل إعادة المحاولة والترتيب غير المتزامن |
| Authenticated health endpoint | إثبات الاتصال بين النظامين |

## 26. Explicitly out of scope

- API routes, controllers, request validators, or HTTP clients.
- Database migrations or integration tables.
- Jobs, queues, events, listeners, or webhooks.
- Masar implementation.
- Bidirectional synchronization.
- GPS tracking, notifications, or delivery telemetry.
- Route decisions or impact classification inside Mini Delivery.
- Changes to lifecycle, history, Filament, Models, or Enums.
- `customer.updated` or `order.completed` event types.
- Current-order completion/result synchronization from Mini Delivery to Masar.
- Sending Masar delivery status back to Mini Delivery.

## 27. Open decisions

None. Table names, retention periods, operational retry limits, and token-rotation procedures are implementation details that can be selected during implementation without changing Contract V1.

## 28. Contract quality review

- Mini Delivery makes no route decision.
- Masar never depends on Mini Delivery internal IDs as its own primary keys.
- Duplicate delivery is safe through persisted event IDs.
- Older events cannot overwrite newer snapshots.
- Cancellation never becomes `not_delivered` and cannot lower reception history.
- The current order is explicitly excluded from previous history.
- Reassignment identifies previous and new representatives.
- Updates state old and new values and provide a recoverable current snapshot.
- Raw history independently supports company and representative reception ratios.
- No bidirectional synchronization is introduced.
