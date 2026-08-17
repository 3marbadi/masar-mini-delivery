# Iteration 3 Database Report

## 1. Pre-Change Database State

The audit covered every migration, Eloquent model, factory, seeder, feature test, lifecycle/update service, integration service, enum, Filament resource, and the integration contract. The live MySQL 8.4 schema was also inspected before any migration was created. All nine pre-existing migrations had run, and the baseline suite passed: 82 tests, 537 assertions, 0 failures.

### Current Database Inventory (before this change)

| Table | Purpose / kind | Important columns and types | Keys, constraints, indexes | Model / relations | Migration source | Readers / writers |
|---|---|---|---|---|---|---|
| `representatives` | Courier aggregate / Domain | `id bigint`, `name varchar`, nullable `phone`, `is_active bool`, timestamps | PK `id` | `Representative`; has many orders | `2026_08_14_000001` | Assignment, Filament, integration snapshots |
| `customers` | Customer aggregate / Domain | `id`, `name`, unique `phone`, `is_active`, timestamps | PK; unique phone | `Customer`; has many orders | `2026_08_14_000002` | Order creation, customer updates/history, integration snapshots |
| `delivery_orders` | Order lifecycle and raw delivery history / Domain | customer/representative FKs, amount, inline location fields, lifecycle `status`/`result`, completion/cancellation timestamps | PK; indexed FKs; both FKs restrict deletion | `DeliveryOrder`; belongs to customer/representative, has integration state/events | `2026_08_14_000003` | Assignment, updates, reassignment, cancellation, history, Filament, integration |
| `order_integration_states` | Per-order durable version and assignment transmission marker / Infrastructure | order FK, `current_version`, `assigned_transmitted_at`, timestamps | unique order FK; restrict deletion | `OrderIntegrationState`; belongs to order | `2026_08_14_000004` | Event generation, sender, update fan-out |
| `integration_outbox` | Immutable outbound events, retry and audit / Infrastructure | UUID event ID, order FK/version, event enum, JSON payload, send state/attempt timestamps/error | unique event ID; unique order/version; status and retry indexes; version check; restrict deletion | `IntegrationOutbox`; belongs to order | `2026_08_14_000005`, `2026_08_18_000006` | Integration generation/sender/retry |
| `users` | Admin authentication / Infrastructure | identity, unique email, password, timestamps | PK; unique email | `User` | framework migration `000000` | Authentication / Filament |
| `password_reset_tokens` | Password recovery / Infrastructure | email, token, timestamp | PK email | none | framework migration `000000` | Authentication |
| `sessions` | Database sessions / Infrastructure | session ID, optional user/IP/agent, payload/activity | PK; user/activity indexes | none | framework migration `000000` | Session driver |
| `cache`, `cache_locks` | Cache and distributed locks / Infrastructure | string keys, values/owners, expiration | PK; expiration indexes | none | framework migration `000001` | Framework cache |
| `jobs`, `job_batches`, `failed_jobs` | Queue durability / Infrastructure | queue payload/state, batch metadata, failed payload/exception | PKs; queue/failure indexes; unique failed UUID | none | framework migration `000002` | Queue worker |
| `migrations` | Migration ledger / Infrastructure | migration name, batch | PK | none | Laravel migrator | Migration subsystem |

No delivery tour, normalized location, stored route, route stop, order change, readiness, or route-impact structure existed. The existing route engine described by the broader system was therefore not represented in this repository's database layer.

## 2. Documentation Target

The PDF defines eight core entities: Representative, Customer, Order, DeliveryTour, Location, Route, RouteStop, and OrderChange. Iteration 3 adds durable current/proposed routes, normalized ordered stops, incoming changes and impact classification, while extending customer history and order readiness/delivery state.

## 3. Difference Matrix

| Documentation Requirement | Current Implementation (pre-change) | Status | Required Change | Reason |
|---|---|---|---|---|
| Representative and customer identity | Existing tables and relations; extra phone/active/timestamps | MATCH / EXTRA-JUSTIFIED | Preserve | Operational UI and assignment need the extra fields |
| Company-wide `total_orders` / `delivered_orders` | Only locally recorded orders could be aggregated | PARTIAL | Add unsigned counters with `delivered <= total` check | The external company's earlier history is not necessarily present locally |
| Order lifecycle fields from iteration 2 | `status`, `result`, completion/cancellation and assignment exist | EQUIVALENT | Preserve unchanged | Renaming would break the working lifecycle and integration contract |
| Normalized order Location | Coordinates/link stored inline on each order | PARTIAL | Create `locations`, add optional FK, backfill every existing order | Enables reuse by tour and route while preserving wire-contract fields |
| DeliveryTour and order membership | Missing | MISSING | Create tours; add optional `tour_id` | Required ownership and multi-route aggregate |
| Readiness and location validation | Missing | MISSING | Add enums, windows and timestamps | Required by iteration 2/3 route eligibility semantics |
| Delivery status/reason/location completion | Existing `result` is only final local lifecycle outcome | PARTIAL / CONFLICT | Add separate iteration-3 fields and consistency checks | The two vocabularies describe different state machines; replacing `result` would break history/integration |
| Persisted Route | Missing | MISSING | Create routes with status, decision, reason, build time and FKs | Needed to compare current vs proposed routes |
| Normalized RouteStop | Missing | MISSING | Create stops with per-route order/position uniqueness | Preserves 1NF and permits one order in multiple route versions |
| OrderChange | Outbound integration events exist but represent transport/audit, not route impact | PARTIAL | Create domain change table | Do not overload or redesign the outbox |
| One optional proposed route per change | Missing | MISSING | Nullable unique order-change FK on routes | Initial routes have no change; non-affecting changes have no route |
| Delete/update behavior | Existing domain/history FKs use RESTRICT | MATCH | Use RESTRICT for all historical iteration-3 records | Prevent accidental history loss |
| Infrastructure tables | Present but absent from academic ERD | EXTRA-JUSTIFIED | Preserve | Authentication, cache, queue, idempotency, retry, versioning and event audit remain required |
| Completion/deferred/returned/delivered lists | No separate tables | MATCH | None | They remain derived classifications, not stored duplicates |

## 4. Decisions Made

- `delivery_orders` remains the implemented name for the documented Order entity.
- Inside Masar after ingestion, the `locations` row referenced by `delivery_orders.location_id` is the canonical operational location used by tours and routes. The inline `location_link`, `latitude`, and `longitude` fields remain as the legacy/integration-facing snapshot required by the current Mini Delivery contract, Filament forms, and outbound event generator; removing them would be a breaking redesign.
- A location change must update the canonical `locations` row (or create a replacement row and atomically repoint `location_id`) and mirror the same values into the inline snapshot in one transaction. Route evaluation must read through `location_id`; integration serialization continues to read the inline snapshot until that contract is deliberately migrated. The current update service changes only inline fields, so this synchronization is a documented invariant and backend business-logic gap rather than an enforced feature in this database-only change.
- `location_id`, `tour_id`, and `representative_id` remain nullable to support new/unassigned/incomplete external orders. This intentionally differs from parts of the PDF but matches the documented domain flow and current lifecycle.
- Existing `status`/`result` are preserved. `result` is the terminal Mini Delivery lifecycle outcome (`delivered` or `not_delivered`) used for historical reception statistics; `delivery_status` is Masar's current operational route disposition (`with_rep`, `delivered`, `postponed`, or `returned`). The intended invariant is that `result=delivered` requires `delivery_status=delivered`, while `result=not_delivered` must not coexist with `delivery_status=delivered`; mapping `not_delivered` to `postponed` versus `returned` requires lifecycle context. This cross-field invariant is not currently enforced because existing completion services do not maintain `delivery_status` and a safe backfill cannot infer that context.
- Route impact now distinguishes three states: `affects_route=false` requires a null impact; `affects_route=true` may remain null while classification is pending; after classification it accepts `none`, `minor`, `moderate`, or `major`. Classified `none` means reevaluation occurred but found no material difference, so no proposed route needs to be stored.
- `occurred_at` and `built_at` are explicit domain timestamps; framework timestamps remain audit metadata.
- An initial active route may have no courier decision. Accepted proposals become active with `accepted`; rejected proposals require `rejected` and can preserve a rejection reason.

## 5. Migrations Created

| Migration | Tables affected | Backfill / constraints / indexes | Compatibility and rollback |
|---|---|---|---|
| `2026_08_18_000007_add_iteration_three_customer_history_fields` | customers | zero-default counters; delivered cannot exceed total | Additive; rollback removes only new counters |
| `2026_08_18_000008_add_iteration_three_tours_locations_and_order_fields` | locations, delivery_tours, delivery_orders | creates one normalized location for every existing order; readiness window and delivery reason checks; tour/status and representative/readiness indexes | Additive; old inline location data is retained; rollback removes new structures |
| `2026_08_18_000009_create_iteration_three_change_and_route_tables` | order_changes, routes, route_stops | effect/impact and decision checks; unique change route; unique stop number/order per route; lookup indexes | New empty history tables; rollback drops them in dependency order |
| `2026_08_18_000010_refine_order_change_impact_semantics` | order_changes | adds `none` to the impact enum and permits null while an affecting change awaits classification; non-affecting changes still require null | Additive enum change; rollback refuses to discard incompatible classified/pending data |

## 6. Existing Migrations Modified

Zero.

## 7. Models/Relations Updated

Added `Location`, `DeliveryTour`, `OrderChange`, `Route`, and `RouteStop`, plus seven backed enums. Added all requested parent/child relations to Customer, Representative, and DeliveryOrder. Customer history counters and all new order fields have appropriate casts.

## 8. Indexes and Constraints

- Unique: customer phone; integration event ID; order/version; one integration state per order; one route per triggering change; stop number and order within a route.
- Lookup: tour/status, representative/readiness, tour/delivery status, order-change occurrence, unread/affecting changes, and stop order references.
- Checks: delivered counter bounds, readiness interval ordering, delivery status/reason consistency, change impact consistency, and route decision consistency. The change-impact check enforces only that a non-affecting change cannot carry an impact; an affecting change may be pending (null) or classified as `none`, `minor`, `moderate`, or `major`.
- The cross-field `result`/`delivery_status` invariant is documented but intentionally not added as a database check until lifecycle services can maintain it and historical `not_delivered` rows can be classified safely.
- All new historical foreign keys use restricted deletion; no blind cascade was introduced.

## 9. Infrastructure Tables Preserved

Users, sessions, reset tokens, cache, locks, queues, migration ledger, integration state, and outbox remain unchanged. They are intentionally outside the academic core ERD but provide authentication, runtime infrastructure, event identity, monotonic order versions, idempotent retries, payload audit and delivery state.

## 10. Tests Added

`IterationThreeDatabaseTest` verifies clean migration schema, core fields, multiple routes per tour, active/proposed routes, route-specific starting locations, normalized stops, reuse of one order across route versions, multiple changes per order, optional change-to-route behavior, acceptance/rejection, high-impact rejection reason, integrity checks, and restricted historical deletion. It now explicitly covers non-affecting/null, rejection of non-affecting/classified impact, affecting/pending classification, all four classified levels, and affecting/`none` without a saved route.

## 11. Test Results

- Before changes: 82 passed, 0 failed, 537 assertions.
- Updated targeted suite: 12 passed, 0 failed, 33 assertions.
- Full suite after the limited semantic fix: 94 passed, 0 failed, 570 assertions.
- Live incremental migration: 13 orders remained, all 13 retained normalized location links, and all 25 existing outbox events remained.

## 12. Remaining Gaps

- **Database gap:** none for the requested iteration-3 persistence foundation.
- **Backend business-logic gap:** readiness workflows, route construction/re-evaluation, impact classification, and atomic route accept/reject services are not implemented by this database task. Location updates must still be made transactional across the canonical `locations` row and legacy inline snapshot. Lifecycle completion must also synchronize `result` and `delivery_status` once the product mapping for `not_delivered` is defined.
- **Integration gap:** the current repository emits events to Masar; ingestion into the new domain tables belongs to the receiving Masar application and remains outside this task. Existing event generation, versioning and retry tests still pass.
- **Flutter/UI gap:** route comparison, change indicators and courier decisions are not implemented here.
- **Intentionally deferred:** removal of inline location fields, stricter non-null tour/location rules, separate classification-list tables, and derived reception percentages.

## 13. Changed Files

Task-owned changes are the four new migrations, five new models, seven enums, `IterationThreeDatabaseTest`, this report, and relation/cast updates in Customer, Representative, and DeliveryOrder. Pre-existing uncommitted integration retry/auth changes were preserved and not reverted or redesigned.

## 14. Final Verdict

`ITERATION 3 DATABASE: READY`

The schema now safely supports the documented iteration-3 aggregates, history, comparisons and integrity rules while preserving iteration 2 and the established integration/outbox layer. Remaining work is application logic and UI, not a blocking database gap.
