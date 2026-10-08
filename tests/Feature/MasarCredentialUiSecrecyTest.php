<?php

namespace Tests\Feature;

use App\Livewire\MasarCredentialSection;
use App\Models\Representative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Where the one-time password is allowed to be, on the admin side
 * (Masar CONTRACT §13.28.10, §13.28.16).
 *
 * The backend's half of this is MasarCredentialContainmentTest. This file is about
 * the one place the plaintext is *supposed* to appear — the operator's screen — and
 * about proving it appears nowhere else.
 *
 * ## Why the Livewire snapshot is the assertion that matters
 *
 * A modal that renders the password beautifully and also writes it into the
 * component's serialized state is not a success: that snapshot travels to the
 * browser in `wire:snapshot`, is sent back with every later Livewire request, and is
 * the one artefact of a Livewire page that genuinely outlives the response. A
 * password in it is a password that keeps being transmitted, long after the operator
 * closed the modal.
 *
 * Livewire (4.4 here) synthesises **public** properties into that snapshot.
 * MasarCredentialSection holds the credential in a **protected** property, so it is
 * reconstructed as null on every hydration and cannot be in the snapshot — and the
 * mounted action's public `arguments` are deliberately left empty, since those are
 * snapshot state too.
 *
 * What the browser does receive is the rendered modal, which Filament delivers as a
 * Livewire partial. That is the intended one-time output, and the first test below
 * asserts it is there — because a containment test that only proved absence would
 * pass just as happily if the feature were broken.
 */
class MasarCredentialUiSecrecyTest extends TestCase
{
    use RefreshDatabase;

    private const UID = '0199b2c4-8e1a-7f3d-9c2e-5a7b1d3f6e80';

    private const CREDENTIAL_URL = 'https://masar.test/api/v1/integration/representatives/'.self::UID.'/credential';

    private const TOKEN_URL = 'https://masar.test/api/v1/integration/auth/token';

    /** The value that must reach the screen and nothing else. */
    private const MARKER = 'MASAR-UI-SECRET-ONLY-ONCE-7J4X';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set([
            'services.masar.base_url' => 'https://masar.test',
            'services.masar.client_id' => 'mini-delivery',
            'services.masar.client_secret' => 'client-secret',
            'services.masar.token_path' => '/api/v1/integration/auth/token',
            'services.masar.representatives_path' => '/api/v1/integration/representatives',
        ]);

        $this->actingAs(User::factory()->create());
    }

    /** It must be on the operator's screen — otherwise the rest proves nothing. */
    public function test_the_password_is_rendered_in_the_one_time_modal(): void
    {
        $component = $this->createCredential();

        $this->assertStringContainsString(self::MARKER, $this->partials($component));
        $this->assertStringContainsString('تُعرض كلمة المرور هذه مرّة واحدة فقط', $this->partials($component));
    }

    /** The assertion the whole design turns on. */
    public function test_the_password_is_absent_from_the_livewire_snapshot(): void
    {
        $component = $this->createCredential();

        $snapshot = json_encode($component->snapshot, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::MARKER, (string) $snapshot);

        // Nor in the mounted action's arguments, which are snapshot state as well.
        $this->assertStringNotContainsString(
            self::MARKER,
            (string) json_encode($component->get('mountedActions'), JSON_PARTIAL_OUTPUT_ON_ERROR),
        );
    }

    public function test_the_password_is_absent_from_every_subsequent_livewire_payload(): void
    {
        $component = $this->createCredential();

        // A plain re-render, a status refresh, and closing the modal — the three
        // things an operator does next.
        // In this order deliberately: a re-render while the modal is still mounted,
        // then closing it, then the status refresh — which Filament would otherwise
        // treat as a nested action of the open modal.
        foreach ([
            fn () => $component->call('$refresh'),
            fn () => $component->call('unmountAction'),
            fn () => $component->callAction('refreshMasarCredential'),
        ] as $next) {
            $next();

            $this->assertStringNotContainsString(self::MARKER, $component->html());
            $this->assertStringNotContainsString(self::MARKER, $this->partials($component));
            $this->assertStringNotContainsString(
                self::MARKER,
                (string) json_encode($component->snapshot, JSON_PARTIAL_OUTPUT_ON_ERROR),
            );
        }
    }

    /**
     * Re-rendering the still-mounted modal does not reproduce it.
     *
     * The protected property is gone by the next request, so the modal says the
     * password is no longer available rather than showing it again. That is the
     * guarantee working, not a defect (§13.28.10).
     */
    public function test_the_modal_cannot_reproduce_the_password_on_a_later_request(): void
    {
        $component = $this->createCredential();

        $component->call('$refresh');

        $redisplayed = $this->partials($component).$component->html();

        $this->assertStringNotContainsString(self::MARKER, $redisplayed);
    }

    public function test_the_password_is_absent_from_a_fresh_page_load(): void
    {
        $component = $this->createCredential();
        $representative = Representative::query()->sole();

        // The operator closes the modal and reloads the record.
        $component->call('unmountAction');

        $this->get('/admin/representatives/'.$representative->getKey())
            ->assertOk()
            ->assertDontSee(self::MARKER);

        // And the card itself, mounted fresh.
        $reloaded = Livewire::test(MasarCredentialSection::class, ['representativeId' => $representative->getKey()]);

        $this->assertStringNotContainsString(self::MARKER, $reloaded->html());
        $this->assertStringNotContainsString(self::MARKER, $this->partials($reloaded));
    }

    public function test_the_password_reaches_no_store_the_server_keeps(): void
    {
        $records = $this->captureLog();

        $this->createCredential();

        // Every table in the schema, every column, read raw.
        $leaks = [];

        foreach ($this->tables() as $table) {
            foreach (DB::table($table)->get() as $row) {
                foreach ((array) $row as $column => $value) {
                    if (is_string($value) && str_contains($value, self::MARKER)) {
                        $leaks[] = "{$table}.{$column}";
                    }
                }
            }
        }

        $this->assertSame([], $leaks, 'the one-time password reached: '.implode(', ', $leaks));

        // The session, which is where a Filament notification would have put it.
        $this->assertStringNotContainsString(
            self::MARKER,
            (string) json_encode(session()->all(), JSON_PARTIAL_OUTPUT_ON_ERROR),
        );

        // The cache, which holds the integration bearer token and must hold nothing
        // else from this workflow.
        $store = Cache::getStore();
        $cached = method_exists($store, 'all') ? $store->all() : [];

        foreach ($cached as $key => $value) {
            $this->assertStringNotContainsString(
                self::MARKER,
                (string) (is_scalar($value) ? $value : json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR)),
                "the one-time password reached the cache under [{$key}]",
            );
        }

        // And the log, including the audit lines this very action emits.
        $this->assertStringNotContainsString(self::MARKER, $this->flatten($records));
    }

    /**
     * No notification ever carries it.
     *
     * Filament flashes notifications through the session so they survive a redirect,
     * and this application is configured for database-backed sessions — a password in
     * a notification body would be a password in MySQL.
     */
    public function test_no_notification_carries_the_password(): void
    {
        $component = $this->createCredential();

        $dispatched = json_encode($component->effects['dispatches'] ?? [], JSON_PARTIAL_OUTPUT_ON_ERROR);

        $this->assertStringNotContainsString(self::MARKER, (string) $dispatched);

        // The replay path is the one that does notify, and it has no password at all.
        $this->assertStringNotContainsString(
            self::MARKER,
            (string) json_encode(session('filament.notifications') ?? [], JSON_PARTIAL_OUTPUT_ON_ERROR),
        );
    }

    /** The audit line names the actor and the login, and no secret (§13.28.18). */
    public function test_the_audit_line_records_the_acting_admin_and_no_secret(): void
    {
        $records = $this->captureLog();

        $admin = auth()->user();

        $this->createCredential();

        $created = null;

        foreach ($records as $record) {
            if ($record['message'] === 'masar.credential.created') {
                $created = $record;
            }
        }

        $this->assertNotNull($created, 'no creation audit line was written');
        $this->assertSame($admin->getKey(), $created['context']['admin_user_id']);
        $this->assertSame('omar.badi', $created['context']['login_name']);
        $this->assertSame('applied', $created['context']['outcome']);
        $this->assertArrayNotHasKey('password', $created['context']);

        $this->assertStringNotContainsString(self::MARKER, $this->flatten($records));
    }

    // --------------------------------------------------------------- helpers

    /** Performs one create and returns the component that did it. */
    private function createCredential(): Testable
    {
        Http::fake([
            self::TOKEN_URL => Http::response([
                'success' => true, 'access_token' => 'access-token', 'token_type' => 'Bearer', 'expires_in' => 3600,
            ]),
            '*/credential' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response($this->statusBody())
                : Http::response($this->issuedBody()),
        ]);

        $representative = Representative::query()->create([
            'name' => 'Omar Badi',
            'phone' => '0911000000',
            'is_active' => true,
        ]);
        $representative->forceFill(['integration_uid' => self::UID])->save();

        return Livewire::test(MasarCredentialSection::class, ['representativeId' => $representative->getKey()])
            ->callAction('createMasarCredential', ['login_name' => 'omar.badi']);
    }

    /** @return array<string, mixed> */
    private function statusBody(): array
    {
        return [
            'success' => true,
            'external_courier_id' => self::UID,
            'mapped' => true,
            'has_credential' => false,
            'login_name' => null,
            'credential_status' => 'none',
            'credentials_updated_at' => null,
            'request_id' => 'req-status',
        ];
    }

    /** @return array<string, mixed> */
    private function issuedBody(): array
    {
        return [
            'success' => true,
            'status' => 'created',
            'external_courier_id' => self::UID,
            'mapped' => true,
            'has_credential' => true,
            'credential' => [
                'login_name' => 'omar.badi',
                'password' => self::MARKER,
                'credential_status' => 'active',
                'credentials_updated_at' => '2026-10-07T09:00:00Z',
            ],
            'request_id' => 'req-mutation',
        ];
    }

    /** The modal HTML Filament delivers as a Livewire partial. */
    private function partials(Testable $component): string
    {
        $flat = '';
        $partials = $component->effects['partials'] ?? [];

        array_walk_recursive($partials, function ($value) use (&$flat): void {
            $flat .= is_string($value) ? $value : '';
        });

        return $flat;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return collect(DB::select('SHOW TABLES'))
            ->map(fn (object $row): string => (string) array_values((array) $row)[0])
            ->reject(fn (string $table): bool => $table === 'migrations')
            ->values()
            ->all();
    }

    /** @return \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}> */
    private function captureLog(): \ArrayObject
    {
        $records = new \ArrayObject;

        Log::listen(function ($message) use ($records): void {
            $records[] = ['level' => $message->level, 'message' => $message->message, 'context' => $message->context];
        });

        return $records;
    }

    /** @param  \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}>  $records */
    private function flatten(\ArrayObject $records): string
    {
        return collect($records->getArrayCopy())
            ->map(fn (array $record): string => $record['level'].'|'.$record['message'].'|'
                .json_encode($record['context'], JSON_PARTIAL_OUTPUT_ON_ERROR))
            ->implode("\n");
    }
}
