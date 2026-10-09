# Masar × Mini Delivery Integration Contract V1

Status: Approved design baseline  
Contract version: `1.0`  
Direction: Mini Delivery → Masar only  
Last additive revision: D3 — the administrative destination and the approved delivery fee (Masar CONTRACT.md v5.19)

> **D3 is additive and `contract_version` stays `1.0`.** Two keys were added to
> the `order` section of every snapshot — `delivery_cost` and `destination` — and
> five paths were added to `changed_fields`. No event type, existing path or
> envelope key changed, and an event that carries none of the new keys is still
> valid. A receiver that predates D3 is not broken by this document; a producer
> that predates it is not refused by Masar.
>
> **The rollout is ordered and enforced by a flag.** Masar's receiver deploys
> first. Mini Delivery only sends the new fields once
> `MASAR_DESTINATION_SYNC_ENABLED` is on, and while it is off the payloads are
> byte-for-byte the pre-D3 ones. The reason is section 21's: Masar answers an
> event it cannot validate with `422`, which this contract makes terminal and
> never retried — so sending a field too early loses the order rather than
> delaying it.

## 1. Architecture decision summary

Mini Delivery is the source of external operational input and previous delivery-history facts. Masar consumes those facts and owns the current delivery state during its tour, readiness analysis, route generation, scoring, route re-evaluation, and change-impact classification.

V1 uses one authenticated event-ingestion endpoint with four explicit order event types. Events contain external identifiers, a per-order version, a current snapshot, and explicit old/new field changes where applicable. The customer's reception rate is computed by Mini Delivery and transmitted as a finished value; Masar never recomputes it. Raw completed delivery history is NOT transmitted in V1.

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
| Customer reception rate | Mini Delivery |
| Current order status during a Masar tour | Masar |
| Current delivery completion during a Masar tour | Masar |
| Customer readiness | Masar |
| Proposed and active route | Masar |
| Route score | Masar |
| Route re-evaluation | Masar |
| Route-impact classification | Masar |
| Proposed-route acceptance/rejection | Masar |

The phrase “historical completed-order result” means a previous order fact that Mini Delivery uses — inside its own system — to compute the customer's reception rate. Those raw facts stay in Mini Delivery; only the finished rate crosses the wire (section 10). It does not authorize Mini Delivery to update the current order's completion inside Masar. The current order status and delivery result during route execution are entered and owned in Masar by its representative workflow.

## 5. External identity strategy

Every externally referenced entity is identified by the pair `source_system` plus its external ID.

**`external_*` IDs are opaque, immutable, source-owned integration identities. They MUST NOT depend on a rebuildable database primary key.**

```json
{
  "source_system": "mini_delivery",
  "external_order_id": "01998f4c-7a10-7c3e-9b52-6d1f0a4e8c71",
  "external_customer_id": "01998f4c-7a10-7a21-b4d8-2c9e5f107a33",
  "external_courier_id": "01998f4c-7a10-78e4-9a16-4b7c2d905e18"
}
```

### What Mini Delivery sends

Each of the three is a UUIDv7 held in a column of its own, minted once when the row is created and never changed afterwards:

| Wire field | Source column |
|---|---|
| `external_order_id` | `delivery_orders.integration_uid` |
| `external_customer_id` | `customers.integration_uid` |
| `external_courier_id` | `representatives.integration_uid` |

Each column is `CHAR(36) NOT NULL UNIQUE`, generated on the model's `creating` event so that every creation path produces one, and absent from every mass-assignment list so no inbound payload can set it. None is derived from another, and none is derived from a name, a phone number, a timestamp, an environment prefix or operator input — all of those change, and an identity that moves is not an identity.

### Why not the primary key

V1 of this document permitted "existing Mini Delivery integer IDs serialized as strings". That rule was withdrawn, because an auto-increment key is unique inside one incarnation of one database and nowhere else.

Rebuild the Mini Delivery database, restore it into a fresh schema, or replace it, and the counter starts again: the next courier is handed the integer the previous one had. Masar keys its mapping on `(integration_client_id, external_courier_id)`, so a reused integer does not fail to resolve — it resolves, successfully and silently, to the earlier courier. The new person then inherits that representative's orders, tours and login. The same reasoning applies to customers and orders.

This was not hypothetical. Two Mini Delivery databases each held a `representatives.id = 5` belonging to a different person, and Masar held one courier mapping for `external_courier_id = "5"` under a single integration client. Nothing in either system could tell which of the two it meant.

A UUID fixes both halves of the problem, and the second half matters as much as the first:

- A **rebuilt** database mints new UUIDs, so its rows arrive as new couriers, customers and orders rather than silently claiming existing ones. This is intended behaviour, not a regression: a rebuilt database genuinely holds different rows.
- A **restored backup** carries the UUID in the row, so identity is preserved and existing Masar mappings keep resolving to the same entities.

### Local keys stay local

The primary key keeps every relationship it already owns. `integration_outbox.delivery_order_id`, `order_integration_states.delivery_order_id` and every `representative_id` / `customer_id` foreign key remain integer keys against integer keys. The rule is positional, not global:

- inside the Mini Delivery database — local primary and foreign keys;
- across the Mini Delivery → Masar wire — `integration_uid`.

### Round trip

Masar's outbound channels (status, data, note and location sync) return the same value in `data.order_id`. Mini Delivery resolves it by `delivery_orders.integration_uid`, which is the column it sent, so the identity makes the round trip unchanged. An `order_id` naming no row — unknown, or minted by a different incarnation of this database — is answered `404 ORDER_NOT_FOUND`.

### On the Masar side

Masar must keep its internal primary keys independent. It must never assume that its `orders.id`, `customers.id`, or `representatives.id` equals a Mini Delivery ID. Recommended uniqueness constraints on the Masar side use `(source_system, external_*_id)`.

No change is required of Masar to accept these values: every `external_*` field was already specified as a string of at most 128 characters, and a 36-character UUID satisfies that unchanged. `contract_version` stays `"1.0"` — the value domain narrowed, the wire shape did not move.

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
    "external_order_id": "01998f4c-7a10-7c3e-9b52-6d1f0a4e8c71",
    "status": "assigned",
    "value": "120.00",
    "delivery_cost": "15.00",
    "destination": {
      "city_id": "2",
      "city_name": "طرابلس",
      "region_id": "27",
      "region_name": "السراج"
    },
    "created_at": "2026-08-14T09:50:00Z",
    "assigned_at": "2026-08-14T10:00:00Z"
  },
  "customer": {
    "external_customer_id": "01998f4c-7a10-7a21-b4d8-2c9e5f107a33",
    "name": "Ahmed Salem",
    "phone": "+218910000001",
    "reception_rate": 66.67
  },
  "courier": {
    "external_courier_id": "01998f4c-7a10-78e4-9a16-4b7c2d905e18",
    "name": "Omar Ali",
    "phone": "+218920000007"
  },
  "location": {
    "location_link": "https://maps.example/loc/125",
    "latitude": "32.8872000",
    "longitude": "13.1913000"
  }
}
```

Money and coordinates are JSON strings to preserve database decimal precision. Masar validates and converts them to its chosen exact numeric representation.

### The destination and the delivery fee (D3)

`value` is what the customer's goods are worth. `delivery_cost` beside it is what
the delivery itself was charged. They are two amounts that happen to both be
money, and nothing on either side may conflate them: Masar stores the first
through its own reconciliation path and the second in `orders.delivery_cost`.

| Field | Type | Required | Rule |
|---|---|---|---|
| `order.delivery_cost` | string, 2dp, or `null` | Optional | The fee approved for this order, fixed when it was registered. `null` means no price has been decided — the state of the four cities the catalog prices at nothing — and is **not** a fee of zero. `0.00` is a decided price |
| `order.destination` | object or `null` | Optional | `null` for an order with no administrative destination, which is every order placed before the catalog existed |
| `order.destination.city_id` | string | Required in the object | The **catalog source id** of the city — `delivery_cities.source_city_id` |
| `order.destination.city_name` | string | Required in the object | The city's name **as this order was registered under it** |
| `order.destination.region_id` | string or `null` | Required key | The region's catalog source id. `null` for the 86 cities that demand no region |
| `order.destination.region_name` | string or `null` | Required key | The region's name as registered, or `null` |

**The identifiers are the catalog's, never Mini Delivery's primary keys.** The
internal key an order references is this database's own business and means
nothing on the far side; the source id is the one both companies can name. This
is the same distinction `external_order_id` already draws, for the same reason —
see section 8's "Why not the primary key".

**The names are the order's snapshot, never the catalog's current spelling.**
Masar is told what this order was sent to under the name it was agreed under, so
a later correction in the catalog cannot rewrite the description of a delivery
that has already happened — and could not be noticed across a system boundary if
it did.

**Absent is not `null`.** An absent key means the producer stated nothing and the
receiver leaves whatever it holds alone. An explicit `null` is a statement that
there is no value, and clears it. This is the same rule `customer.reception_rate`
already follows (section 10).

**The destination is administrative, not geographic.** It is what the order is
billed and described under. The position is `location.latitude` / `longitude`,
and that is what routing reads. Choosing a city never moves a pin, never stamps a
location change, and never alters the coordinates — on either side.

## 10. Customer reception rate contract

**Mini Delivery is the single source of truth for the customer's reception rate. Masar never computes it.**

This inverts the V1 draft's original position. That draft sent raw `customer_history` entries so that Masar could derive the ratios itself, and stated that "precomputed percentages are intentionally omitted to avoid conflicting sources of truth." The opposite is now true, for the same underlying reason: two systems deriving the same figure from two different record sets *is* the conflicting-sources problem. One system computes it; everyone else relays it.

**`customer_history` is not part of any V1 payload.** No raw delivery history is transmitted to Masar — not on `order.assigned`, not on `order.updated`, not on any other event. Masar has no inbound path for it and none is planned in V1.

### The transmitted field

| Event | Path |
|---|---|
| `order.assigned` | `data.customer.reception_rate` |
| `order.updated` | `data.current_snapshot.customer.reception_rate` |

| Property | Value |
|---|---|
| Type | JSON `number` or `null` |
| Range | `0` – `100` inclusive |
| Precision | at most two decimal places |
| Presence | the key is always present in the current producer |

Valid values: `82.5`, `66.67`, `0`, `100`, `null`.

It is a JSON **number**, not a string — unlike money and coordinates, which are strings here to preserve decimal precision. A percentage is neither, and the receiving side declares the field numeric.

### How Mini Delivery computes it

`App\Services\CustomerHistoryService::getCompanyReceptionSummary()` is the only place this figure is produced:

```text
completed  = orders with status = completed AND result IN (delivered, not_delivered)
delivered  = those of them with result = delivered

reception_rate = completed == 0
    ? null
    : round(delivered / completed * 100, 2)
```

Rules that follow from it:

- Only `status = completed` with `result = delivered` or `not_delivered` counts.
- `new`, `assigned` and `cancelled` are excluded. **A cancellation never counts as `not_delivered`** and cannot lower the rate.
- The scope is **company-wide** — the customer's record across all representatives. The per-representative summary (`getRepresentativeReceptionSummary`) exists in Mini Delivery for its own admin screens and is **not transmitted**; there is no per-representative reception rate in this contract.
- The current order cannot count toward its own rate: it is not `completed` at the moment an event is produced for it.
- The value is computed live when the outbound event is built. It is a snapshot of that instant, not a live query Masar can re-run.

### `null` versus `0`

```text
null  =  the customer has no completed delivery history, so no rate exists
0     =  the customer has completed history, and none of it was received
```

**`null` is not `0%`.** Neither side may convert one into the other — not by a default value, not by `COALESCE`, not by `?? 0`, and not by omitting the key.

### What the receiver must do

Masar's obligations are exhaustively: validate the bounds, store the value as it arrived, and return it unchanged. Masar must **not** derive this rate from its own order records, from `customers.total_orders`, or from `customers.delivered_orders`. Presentation formatting at the client (rendering `66.67` as `66.67%`) is display, not computation.

### Deployment order

**Deploy the Masar receiver first, then this producer.** Never the reverse.

1. Deploy the Masar receiver that validates the optional `reception_rate`.
2. Verify it accepts an event carrying the field, and still accepts one without it.
3. Deploy this producer.

In the steady state the field is backward compatible in both directions — an older producer that never sends it is accepted unchanged, and an older receiver ignores it — and that is precisely what makes the ordering easy to get wrong. The constraint below is about the moment of crossing between those two states, not about either of them.

Masar computes its idempotency `payload_hash` over the **validated** payload rather than over the bytes that arrived. A receiver with no rule for `reception_rate` drops the key before hashing; a receiver with the rule keeps it. So one and the same event hashes differently on either side of the receiver upgrade:

| Event | Hash before vs. after the receiver upgrade |
|---|---|
| does not carry `reception_rate` | identical — unaffected |
| carries `reception_rate` | different |

This matters only for a retry that spans the upgrade. If the old receiver accepted and recorded an event carrying the field, and the `200` was lost before it reached this producer, then re-sending that same event after the upgrade meets Masar's duplicate guard with a hash that no longer matches, and is answered `409 VERSION_CONFLICT` — "the event ID was reused with a different payload". Four conditions at once, and deploying in the order above removes all of them.

### Recovery, if the transitional case occurs

`409` is terminal for this producer: the outbox row becomes `failed`, and the older-unresolved-version guard then holds back every later event for that order until it is settled.

- **First, read the code correctly.** A `409` here is not a rejection. It says Masar already holds that event ID under a different hash — which means the original event *was* applied. Confirm that against Masar's own record before doing anything else.
- **What does not work:** `php artisan integration:retry {event_id}` returns the row to `pending` with the same stored payload, which meets the same hash and the same `409`. It is the correct recovery for an ordinary transient failure, and the wrong one for this case.
- **What settles it:** marking that outbox row `sent` — a true statement about what actually happened — together with `order_integration_states.assigned_transmitted_at` when the event is an `order.assigned`. The queue then drains normally from the next version onward.

None of this introduces a new retry mechanism, changes the HTTP retry policy, or changes how the hash is computed. It is an ordering rule, and a one-time one.

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
    "courier": {},
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

# Added by D3 — the administrative destination and its fee.
order.delivery_cost
order.destination.city_id
order.destination.city_name
order.destination.region_id
order.destination.region_name
```

**Only what actually moved is declared.** A city change declares the city paths,
the region paths where the region moved with it, and `order.delivery_cost` where
the fee moved — and a region-only change declares the two region paths and
nothing else, because the catalog prices cities and the fee did not move. A path
whose `old` and `new` would be equal is never declared.

**A declared destination or fee path must be stated in the snapshot.** The
`order` section being present is not enough — `amount` alone would satisfy that —
so `current_snapshot.order` must carry the `destination` key for any declared
`order.destination.*` path, and the `delivery_cost` key for a declared
`order.delivery_cost`, even when the value is `null`. Masar refuses `422`
otherwise, because a missing key would otherwise read as a null new value and
clear something the producer never meant to clear.

**Masar raises no route re-evaluation for these five paths on their own**, and
that is a receiver-side guarantee recorded here because the producer relies on
it: a destination is administrative, so a fee or a city-name change costs nothing
in routing. An update that also moves a location or a value keeps that path's
existing trigger exactly.

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

Cancellation uses `order.cancelled`, whose `data` carries one key and nothing else — the order's identity. The fact of cancellation is the event type; there is no field left to state it.

```json
{
  "data": {
    "external_order_id": "01998f4c-7a10-7c3e-9b52-6d1f0a4e8c71"
  }
}
```

No `changed_fields` and no `current_snapshot`: Masar already holds the order, its customer and its courier from earlier events, and the cancellation changes none of them. `occurred_at` on the envelope is the cancellation time.

Masar's receiver additionally accepts an optional nullable `data.reason`; Mini Delivery does not currently send one.

Cancellation never encodes a delivery result. It is never sent as `not_delivered` and never lowers reception-rate history.

## 13. Reassignment contract

`order.reassigned` names both couriers: the one being replaced by identity alone, and the one taking over as a full snapshot.

```json
{
  "external_order_id": "01998f4c-7a10-7c3e-9b52-6d1f0a4e8c71",
  "previous_external_courier_id": "01998f4c-7a10-78e4-9a16-4b7c2d905e18",
  "courier": {
    "external_courier_id": "01998f52-0c33-7b90-8f44-9ae1b6d2730c",
    "name": "Sami Noor",
    "phone": null
  }
}
```

`previous_external_courier_id` is the identity Masar already holds for the outgoing courier, so it resolves against the mapping it created on an earlier event. It is a bare identity and not an object: Masar is being told which courier is leaving, not being given a fresh description of them.

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
| `status` | enum | R only for assigned/cancel event | `assigned` is the initial admission fact; `cancelled` is an explicit external business change. It is omitted from ordinary update snapshots, and `order.reassigned` carries no snapshot at all. |
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
| `customer.reception_rate` | Mini Delivery owned, computed there | Stored and relayed verbatim by Masar; never recomputed, and never applied to the current order's result |

### Customer

| Field | Type | Rule | Notes |
|---|---|---|---|
| `external_customer_id` | string | R | External identity |
| `name` | string | R | Max 255 |
| `phone` | string | R | Operational contact value; no cross-system format assumption |
| `reception_rate` | number \| null | O, N | Company-wide reception rate, 0–100, at most two decimals. Computed by Mini Delivery and relayed unchanged; `null` means no completed history and is not `0` (section 10) |

### Courier

Carried as `data.courier` (and as `data.current_snapshot.courier` on an update).

| Field | Type | Rule | Notes |
|---|---|---|---|
| `external_courier_id` | string | R | Stable external identity — `representatives.integration_uid`, never the local primary key (section 5) |
| `name` | string | R | Current Mini Delivery name. Descriptive only; changing it does not change the identity above |
| `phone` | string/null | O, N | Contact detail, not required for routing identity, and never an identity itself |

The courier object is required for assigned, reassigned, and cancellation of a previously assigned order. It may be nullable only where a cancellation legitimately occurred before assignment.

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
| `external_courier_id` | string | R | Historical representative, active or inactive |
| `result` | enum | R | `delivered` or `not_delivered` only |
| `completed_at` | datetime | R | UTC; historical completion time |

### Change entry

| Field | Type | Rule | Notes |
|---|---|---|---|
| `changed_fields` | object | R for `order.updated` only | At least one supported path. Not carried by `order.reassigned` or `order.cancelled` (sections 12, 13) |
| `changed_fields.<path>.old` | matching field type/null | R, N | Previous value |
| `changed_fields.<path>.new` | matching field type/null | R, N | Current value |
| `current_snapshot` | object | R for `order.updated` only | Complete current source-owned external snapshot; it excludes Masar-owned tour status/result/completion. Not carried by `order.reassigned` or `order.cancelled` |
| `previous_external_courier_id` | string | R for reassignment | Stable identity of the outgoing courier, resolved against the mapping an earlier event created |

`customer.reception_rate` is optional and nullable on every event that carries a customer section. Absent means the producer stated nothing about the rate and the receiver must leave any stored value untouched; an explicit `null` is an authoritative statement that no rate exists and clears it; `0` is a real rate. It is never declared in `changed_fields` — it rides the snapshot, because it describes the customer rather than a field of this order.

`customer_history` is not sent in V1 and has no place in any payload (section 10).

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
      "external_order_id": "01998f4c-7a10-7c3e-9b52-6d1f0a4e8c71",
      "status": "assigned",
      "value": "120.00",
      "created_at": "2026-08-14T09:50:00Z",
      "assigned_at": "2026-08-14T10:00:00Z"
    },
    "customer": {
      "external_customer_id": "01998f4c-7a10-7a21-b4d8-2c9e5f107a33",
      "name": "Ahmed Salem",
      "phone": "+218910000001",
      "reception_rate": 66.67
    },
    "courier": {
      "external_courier_id": "01998f4c-7a10-78e4-9a16-4b7c2d905e18",
      "name": "Omar Ali",
      "phone": "+218920000007"
    },
    "location": {
      "location_link": "https://maps.example/loc/125",
      "latitude": "32.8872000",
      "longitude": "13.1913000"
    }
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
      "order": { "external_order_id": "01998f4c-7a10-7c3e-9b52-6d1f0a4e8c71", "value": "120.00", "created_at": "2026-08-14T09:50:00Z" },
      "customer": { "external_customer_id": "01998f4c-7a10-7a21-b4d8-2c9e5f107a33", "name": "Ahmed M. Salem", "phone": "+218910000001" },
      "courier": { "external_courier_id": "01998f4c-7a10-78e4-9a16-4b7c2d905e18", "name": "Omar Ali", "phone": "+218920000007" },
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
      "order": { "external_order_id": "01998f4c-7a10-7d02-8c61-3f5a9b1e4720", "value": "75.00", "created_at": "2026-08-14T08:00:00Z" },
      "customer": { "external_customer_id": "01998f4c-7a10-7a21-b4d8-2c9e5f107a33", "name": "Ahmed M. Salem", "phone": "+218910000099" },
      "courier": { "external_courier_id": "01998f4c-7a10-78e4-9a16-4b7c2d905e18", "name": "Omar Ali", "phone": "+218920000007" },
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
      "order": { "external_order_id": "01998f4c-7a10-7e55-9d73-8a2c6f30b941", "value": "95.00", "created_at": "2026-08-14T08:30:00Z" },
      "customer": { "external_customer_id": "01998f4c-7a10-7a21-b4d8-2c9e5f107a33", "name": "Ahmed M. Salem", "phone": "+218910000099" },
      "courier": { "external_courier_id": "01998f52-0c33-7b90-8f44-9ae1b6d2730c", "name": "Sami Noor", "phone": null },
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
      "order": { "external_order_id": "01998f4c-7a10-7c3e-9b52-6d1f0a4e8c71", "value": "135.50", "created_at": "2026-08-14T09:50:00Z" },
      "customer": { "external_customer_id": "01998f4c-7a10-7a21-b4d8-2c9e5f107a33", "name": "Ahmed M. Salem", "phone": "+218910000099" },
      "courier": { "external_courier_id": "01998f4c-7a10-78e4-9a16-4b7c2d905e18", "name": "Omar Ali", "phone": "+218920000007" },
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
      "order": { "external_order_id": "01998f4c-7a10-7c3e-9b52-6d1f0a4e8c71", "value": "135.50", "created_at": "2026-08-14T09:50:00Z" },
      "customer": { "external_customer_id": "01998f4c-7a10-7a21-b4d8-2c9e5f107a33", "name": "Ahmed M. Salem", "phone": "+218910000099" },
      "courier": { "external_courier_id": "01998f4c-7a10-78e4-9a16-4b7c2d905e18", "name": "Omar Ali", "phone": "+218920000007" },
      "location": { "location_link": "https://maps.example/loc/125b", "latitude": "32.8890000", "longitude": "13.1950000" }
    }
  }
}
```

### Example 6 — Courier reassignment

`order.reassigned` carries exactly three keys in `data`, and this example is the whole payload — there is no `changed_fields` and no `current_snapshot` on this event type (section 13). Earlier revisions of this document showed `previous_representative` and `new_representative` objects; the serializer has never emitted them.

```json
{
  "contract_version": "1.0",
  "event_id": "0198a14a-1000-7a01-b200-000000000005",
  "event_type": "order.reassigned",
  "occurred_at": "2026-08-14T10:50:00Z",
  "order_version": 5,
  "data": {
    "external_order_id": "01998f4c-7a10-7c3e-9b52-6d1f0a4e8c71",
    "previous_external_courier_id": "01998f4c-7a10-78e4-9a16-4b7c2d905e18",
    "courier": {
      "external_courier_id": "01998f52-0c33-7b90-8f44-9ae1b6d2730c",
      "name": "Sami Noor",
      "phone": null
    }
  }
}
```

The outgoing courier is named by identity alone — Masar already holds a mapping for `01998f4c-7a10-78e4-9a16-4b7c2d905e18` from an earlier event, so there is nothing left to describe. The incoming courier arrives as a full snapshot because this may be the first event that names them.

> This example omits `source_system`, because the envelope builder does not emit it. The other examples in this section still show it; that is a separate documentation drift predating the identity change and is not corrected here.

### Example 7 — Cancellation

One key in `data`, as section 12 states. This is the whole payload.

```json
{
  "contract_version": "1.0",
  "event_id": "0198a14a-1000-7a01-b200-000000000006",
  "event_type": "order.cancelled",
  "occurred_at": "2026-08-14T11:00:00Z",
  "order_version": 6,
  "data": {
    "external_order_id": "01998f4c-7a10-7c3e-9b52-6d1f0a4e8c71"
  }
}
```

> As with Example 6, `source_system` is omitted because the envelope builder does not emit it.

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
| `customer.reception_rate` محسوبةً عند شركة التوصيل | تمرير نسبة الاستلام المعتمدة دون إعادة حسابها في مَسار |
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
- Reassignment identifies both couriers: the outgoing one by stable identity, the incoming one by full snapshot.
- Updates state old and new values and provide a recoverable current snapshot.
- The company-wide reception rate has exactly one producer, and every other layer relays it unchanged.
- `null` (no completed history) stays distinguishable from `0` (history with nothing received) end to end.
- No bidirectional synchronization is introduced.
