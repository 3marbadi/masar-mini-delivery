<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Enums\IntegrationEventType;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\IntegrationOutbox;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use App\Services\DeliveryOrderUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * This company owns the location *link*. Masar owns turning it into a position.
 *
 * The division matters because it was previously blurred by the order form, which
 * asked the operator for «خط العرض» and «خط الطول» by hand. That made a courier's
 * usable location depend on somebody looking up coordinates in another tab, and it
 * put a second, manual source of truth beside the link.
 *
 * So the rule asserted here has two halves, and the second is the one that keeps the
 * first honest:
 *
 *   1. the link is sent, exactly as stored, on both events that carry a location;
 *   2. nothing on this side derives coordinates from it — no parsing, no redirect
 *      following, no outbound lookup.
 *
 * `latitude` and `longitude` remain on the table and in `$fillable`, and that is not
 * an oversight. They are values this side *receives*: `MasarLocationWriter` writes
 * both when Masar announces a completed location (§3.7, D13), and the view page shows
 * them. Removing the two inputs removed a data-entry task, not a field.
 */
class OrderLocationUrlOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://maps.app.goo.gl/ZtQ9vK3mN7pX1aB2';

    /**
     * Ways this side could try to resolve a link itself. None may appear in `app/`.
     *
     * The three regex fragments are the capture patterns a Google Maps link is parsed
     * with; the rest are the mechanics of following a shortened one.
     */
    private const FORBIDDEN_RESOLUTION = [
        '!3d', '!4d', 'maps.google', 'google.com/maps', 'goo.gl',
        'allow_redirects', 'LocationLinkParser', 'geocode',
    ];

    private DeliveryOrderLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->lifecycle = app(DeliveryOrderLifecycleService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------- the link is sent

    /** The link travels on assignment, byte for byte, with no coordinates present. */
    public function test_order_assigned_carries_the_location_url_with_no_coordinates(): void
    {
        Http::preventStrayRequests();

        $order = $this->order(['location_link' => self::URL]);

        $this->lifecycle->assignRepresentative($order, $this->representative());

        $location = $this->payload($order, IntegrationEventType::OrderAssigned)['data']['location'];

        $this->assertSame(self::URL, $location['location_link'], 'the link was altered or dropped on the way out');

        // The key is present and null rather than absent: Masar's contract has the
        // two coordinates optional but sent together, and a missing key and a null
        // one are different statements (§3.7).
        $this->assertArrayHasKey('latitude', $location);
        $this->assertArrayHasKey('longitude', $location);
        $this->assertNull($location['latitude']);
        $this->assertNull($location['longitude']);
    }

    /** An order with a link and no coordinates is announceable — nothing requires them. */
    public function test_an_order_with_only_a_url_is_announced_without_error(): void
    {
        Http::preventStrayRequests();

        $order = $this->order(['location_link' => self::URL]);

        $this->lifecycle->assignRepresentative($order, $this->representative());

        $this->assertSame(
            1,
            IntegrationOutbox::query()->whereBelongsTo($order)->count(),
            'an order carrying only a link produced no event',
        );
    }

    /** A later edit to the link is announced as a location change. */
    public function test_order_updated_carries_the_changed_location_url(): void
    {
        Http::preventStrayRequests();

        $order = $this->order(['location_link' => 'https://maps.example/old']);
        $this->lifecycle->assignRepresentative($order, $this->representative());

        app(DeliveryOrderUpdateService::class)->update($order, ['location_link' => self::URL]);

        $payload = $this->payload($order->refresh(), IntegrationEventType::OrderUpdated);

        // Named as a changed path, so Masar knows to act on it rather than treating
        // the snapshot as incidental.
        $this->assertArrayHasKey('location.location_link', $payload['data']['changed_fields']);
        $this->assertSame(self::URL, $payload['data']['changed_fields']['location.location_link']['new']);

        // And carried in the snapshot, which is what Masar persists from.
        $this->assertSame(self::URL, $payload['data']['current_snapshot']['location']['location_link']);
    }

    /** Adding a link to an order that had none is a location change too. */
    public function test_adding_a_url_to_an_order_that_had_none_is_announced(): void
    {
        Http::preventStrayRequests();

        $order = $this->order(['location_link' => null]);
        $this->lifecycle->assignRepresentative($order, $this->representative());

        app(DeliveryOrderUpdateService::class)->update($order, ['location_link' => self::URL]);

        $payload = $this->payload($order->refresh(), IntegrationEventType::OrderUpdated);

        $this->assertArrayHasKey('location.location_link', $payload['data']['changed_fields']);
        $this->assertNull($payload['data']['changed_fields']['location.location_link']['old']);
        $this->assertSame(self::URL, $payload['data']['changed_fields']['location.location_link']['new']);
        $this->assertSame(self::URL, $payload['data']['current_snapshot']['location']['location_link']);
    }

    // ------------------------------------------- and nothing is resolved here

    /**
     * No coordinate extraction exists on this side.
     *
     * A scan rather than a behavioural assertion, because the thing being forbidden
     * is a *capability*: a behavioural test can only prove that the code present
     * today does not parse, while this fails the moment someone adds a parser — which
     * is the regression worth catching, since it would look like a helpful fix.
     */
    public function test_this_side_contains_no_coordinate_resolution(): void
    {
        $found = [];

        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);

            foreach (self::FORBIDDEN_RESOLUTION as $needle) {
                if (str_contains($contents, $needle)) {
                    $found[] = basename($file).' → '.$needle;
                }
            }
        }

        $this->assertSame([], $found, 'coordinate resolution appeared in Mini Delivery: '.implode(', ', $found));
    }

    /** Announcing a location makes no outbound call of its own. */
    public function test_announcing_a_location_makes_no_http_call(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $order = $this->order(['location_link' => self::URL]);
        $this->lifecycle->assignRepresentative($order, $this->representative());

        // The outbox sender is a separate, scheduled leg; generating the event must
        // not reach the network at all, least of all to expand a short link.
        Http::assertNothingSent();
    }

    // --------------------------------------------------------------- helpers

    /** @return list<string> every PHP file under app/ */
    private function sourceFiles(): array
    {
        $files = [];
        $directory = new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS);

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private function payload(DeliveryOrder $order, IntegrationEventType $type): array
    {
        return IntegrationOutbox::query()
            ->whereBelongsTo($order)
            ->where('event_type', $type)
            ->latest('id')
            ->firstOrFail()
            ->payload;
    }

    /** @param  array<string, mixed>  $attributes */
    private function order(array $attributes = []): DeliveryOrder
    {
        $customer = Customer::create([
            'name' => 'Customer '.uniqid(),
            'phone' => uniqid('+218', true),
        ]);

        return DeliveryOrder::create(array_merge([
            'customer_id' => $customer->id,
            'value' => '20.00',
            'status' => DeliveryOrderStatus::NewOrder,
        ], $attributes));
    }

    private function representative(): Representative
    {
        return Representative::create([
            'name' => 'Courier '.uniqid(),
            'phone' => uniqid('+218', true),
            'is_active' => true,
        ]);
    }
}
