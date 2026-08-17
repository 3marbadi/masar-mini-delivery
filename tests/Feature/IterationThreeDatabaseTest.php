<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\OrderChangeType;
use App\Enums\RouteDecision;
use App\Enums\RouteImpactLevel;
use App\Enums\RouteStatus;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DeliveryTour;
use App\Models\Location;
use App\Models\OrderChange;
use App\Models\Representative;
use App\Models\Route;
use App\Models\RouteStop;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IterationThreeDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_iteration_three_schema_and_order_fields_exist(): void
    {
        foreach (['locations', 'delivery_tours', 'order_changes', 'routes', 'route_stops'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertTrue(Schema::hasColumns('customers', ['total_orders', 'delivered_orders']));
        $this->assertTrue(Schema::hasColumns('delivery_orders', [
            'location_id', 'tour_id', 'location_validation_status', 'readiness_status',
            'available_from', 'available_until', 'confirmed_at', 'delivery_status',
            'status_reason', 'location_completed_at',
        ]));
    }

    public function test_a_tour_can_hold_active_and_proposed_routes_with_independent_start_locations(): void
    {
        [$tour, $order, $start] = $this->domain();
        $change = $this->change($order, true, RouteImpactLevel::Moderate);

        $active = Route::create([
            'tour_id' => $tour->id,
            'start_location_id' => $start->id,
            'status' => RouteStatus::Active,
            'ordering_reason' => 'initial route',
            'built_at' => now(),
        ]);
        $currentCourierLocation = Location::create(['latitude' => '33.1000000', 'longitude' => '36.1000000']);
        $proposed = Route::create([
            'tour_id' => $tour->id,
            'start_location_id' => $currentCourierLocation->id,
            'order_change_id' => $change->id,
            'status' => RouteStatus::Proposed,
            'ordering_reason' => 'location changed',
            'built_at' => now(),
        ]);

        $this->assertCount(2, $tour->routes);
        $this->assertSame(RouteStatus::Active, $active->status);
        $this->assertSame(RouteStatus::Proposed, $proposed->status);
        $this->assertTrue($proposed->triggeringChange->is($change));
        $this->assertFalse($proposed->startLocation->is($tour->startLocation));
    }

    public function test_routes_have_normalized_stops_and_an_order_can_appear_in_different_routes(): void
    {
        [$tour, $order, $start] = $this->domain();
        $secondOrder = DeliveryOrder::create([
            'customer_id' => $order->customer_id,
            'representative_id' => $tour->representative_id,
            'location_id' => $start->id,
            'tour_id' => $tour->id,
            'value' => '20.00',
        ]);
        $firstRoute = $this->route($tour, $start, RouteStatus::Active);
        $secondRoute = $this->route($tour, $start, RouteStatus::Proposed);

        RouteStop::create(['route_id' => $firstRoute->id, 'delivery_order_id' => $order->id, 'stop_number' => 1, 'expected_arrival' => now()->addHour()]);
        RouteStop::create(['route_id' => $firstRoute->id, 'delivery_order_id' => $secondOrder->id, 'stop_number' => 2, 'expected_arrival' => now()->addHours(2)]);
        RouteStop::create(['route_id' => $secondRoute->id, 'delivery_order_id' => $order->id, 'stop_number' => 1, 'expected_arrival' => now()->addMinutes(30)]);

        $this->assertCount(2, $firstRoute->stops);
        $this->assertCount(2, $order->routeStops);
    }

    public function test_an_order_can_have_multiple_changes_and_only_affecting_changes_need_routes(): void
    {
        [$tour, $order, $start] = $this->domain();
        $nonAffecting = $this->change($order, false, null);
        $affecting = $this->change($order, true, RouteImpactLevel::Major);
        $route = $this->route($tour, $start, RouteStatus::Proposed, $affecting);

        $this->assertCount(2, $order->orderChanges);
        $this->assertNull($nonAffecting->proposedRoute);
        $this->assertTrue($affecting->proposedRoute->is($route));
    }

    public function test_proposed_routes_can_be_accepted_or_rejected_with_a_reason(): void
    {
        [$tour, $order, $start] = $this->domain();
        $accepted = $this->route($tour, $start, RouteStatus::Proposed, $this->change($order, true, RouteImpactLevel::Moderate));
        $accepted->update(['status' => RouteStatus::Active, 'decision' => RouteDecision::Accepted]);

        $otherOrder = DeliveryOrder::create(['customer_id' => $order->customer_id, 'representative_id' => $tour->representative_id, 'value' => '12.00']);
        $rejected = $this->route($tour, $start, RouteStatus::Proposed, $this->change($otherOrder, true, RouteImpactLevel::Major));
        $rejected->update(['status' => RouteStatus::Rejected, 'decision' => RouteDecision::Rejected, 'rejection_reason' => 'customer window at risk']);

        $this->assertSame(RouteStatus::Active, $accepted->fresh()->status);
        $this->assertSame(RouteDecision::Accepted, $accepted->fresh()->decision);
        $this->assertSame('customer window at risk', $rejected->fresh()->rejection_reason);
    }

    public function test_non_affecting_change_with_null_impact_is_valid(): void
    {
        [, $order] = $this->domain();

        $change = $this->change($order, false, null);

        $this->assertFalse($change->affects_route);
        $this->assertNull($change->impact_level);
    }

    public function test_database_rejects_non_affecting_change_with_a_classified_impact(): void
    {
        [, $order] = $this->domain();
        $this->expectException(QueryException::class);

        $this->change($order, false, RouteImpactLevel::Minor);
    }

    public function test_affecting_change_can_remain_unclassified(): void
    {
        [, $order] = $this->domain();

        $change = $this->change($order, true, null);

        $this->assertTrue($change->affects_route);
        $this->assertNull($change->impact_level);
    }

    public function test_affecting_change_accepts_every_classified_impact_level(): void
    {
        [, $order] = $this->domain();

        foreach (RouteImpactLevel::cases() as $impactLevel) {
            $change = $this->change($order, true, $impactLevel);

            $this->assertSame($impactLevel, $change->impact_level);
        }
    }

    public function test_affecting_change_classified_as_none_does_not_require_a_saved_route(): void
    {
        [, $order] = $this->domain();

        $change = $this->change($order, true, RouteImpactLevel::None);

        $this->assertSame(RouteImpactLevel::None, $change->impact_level);
        $this->assertNull($change->proposedRoute);
    }

    public function test_database_rejects_inconsistent_delivery_status_reason(): void
    {
        [, $order] = $this->domain();
        $this->expectException(QueryException::class);

        $order->update(['delivery_status' => DeliveryStatus::Returned, 'status_reason' => null]);
    }

    public function test_historical_entities_are_restricted_from_parent_deletion(): void
    {
        [$tour, $order, $start] = $this->domain();
        $route = $this->route($tour, $start, RouteStatus::Active);
        RouteStop::create(['route_id' => $route->id, 'delivery_order_id' => $order->id, 'stop_number' => 1, 'expected_arrival' => now()]);

        $this->expectException(QueryException::class);
        $order->delete();
    }

    /** @return array{DeliveryTour, DeliveryOrder, Location} */
    private function domain(): array
    {
        $representative = Representative::create(['name' => 'Courier']);
        $customer = Customer::create(['name' => 'Customer', 'phone' => fake()->unique()->numerify('09########')]);
        $start = Location::create(['latitude' => '33.0000000', 'longitude' => '36.0000000']);
        $tour = DeliveryTour::create(['representative_id' => $representative->id, 'start_location_id' => $start->id, 'departure_time' => now()]);
        $order = DeliveryOrder::create([
            'customer_id' => $customer->id,
            'representative_id' => $representative->id,
            'location_id' => $start->id,
            'tour_id' => $tour->id,
            'value' => '10.00',
        ]);

        return [$tour, $order, $start];
    }

    private function change(DeliveryOrder $order, bool $affectsRoute, ?RouteImpactLevel $impact): OrderChange
    {
        return OrderChange::create([
            'delivery_order_id' => $order->id,
            'change_type' => OrderChangeType::Location,
            'affects_route' => $affectsRoute,
            'impact_level' => $impact,
            'occurred_at' => now(),
        ]);
    }

    private function route(DeliveryTour $tour, Location $start, RouteStatus $status, ?OrderChange $change = null): Route
    {
        return Route::create([
            'tour_id' => $tour->id,
            'start_location_id' => $start->id,
            'order_change_id' => $change?->id,
            'status' => $status,
            'ordering_reason' => 'test ordering',
            'built_at' => now(),
        ]);
    }
}
