<?php

namespace Tests\Feature;

use App\Livewire\MasarCredentialSection;
use App\Models\Representative;
use App\Models\User;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The copy buttons copy, on an explicit tap, and still keep nothing.
 *
 * Reported from a real mobile browser: the modal rendered, and «نسخ اسم المستخدم» and
 * «نسخ كلمة المرور» did nothing. The implementation was a bare
 * `x-on:click="navigator.clipboard.writeText(@js(...))"`, which has three defects that
 * together produce exactly that symptom:
 *
 *   1. the promise's result was never observed, so success and failure looked
 *      identical — there was no feedback of any kind;
 *   2. a rejection (`NotAllowedError` when the document is not focused, or a refused
 *      permission) was swallowed as an unhandled rejection;
 *   3. there was no second path, so anywhere `navigator.clipboard` is missing or
 *      blocked — an insecure origin, a Permissions-Policy or CSP restriction, an
 *      embedded webview — the copy had nowhere to go.
 *
 * Only a real device proves which of the three fired on Omar's phone. What a test can
 * hold is the shape of the replacement, and that is what this suite does: the tap is
 * the only trigger, a fallback exists, the temporary node is removed, and none of it
 * introduces a place the secret can rest.
 *
 * **The assertion that carries the most weight is the count one.** The old handler
 * embedded the secret a second time, inside an attribute: the password appeared in the
 * markup twice, once as content and once as a JS argument. The component now reads the
 * value from the node already displaying it, so each secret appears exactly once —
 * fewer copies in the response than before, not merely the same number.
 */
class MasarCredentialCopyTest extends TestCase
{
    use RefreshDatabase;

    private const UID = '0199b2c4-8e1a-7f3d-9c2e-5a7b1d3f6e80';

    private const TOKEN_URL = 'https://masar.test/api/v1/integration/auth/token';

    private const PASSWORD = 'MASAR-COPY-SECRET-ONCE-Q2M9';

    private const LOGIN_NAME = 'omar.badi';

    /** Browser-side persistence that must appear nowhere in the copy implementation. */
    private const FORBIDDEN_STORAGE = [
        'localStorage', 'sessionStorage', 'indexedDB', 'IndexedDB',
        'document.cookie', 'caches.open', 'navigator.storage',
    ];

    /** Anything that would turn a copy into a server round trip. */
    private const FORBIDDEN_TRANSPORT = [
        'fetch(', 'XMLHttpRequest', '$wire', 'wire:', 'Livewire.', 'axios',
        'navigator.sendBeacon',
    ];

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

    // ------------------------------------------------- the rendered modal

    /** Both buttons are wired, and to the component rather than to an inline API call. */
    public function test_each_button_copies_through_an_explicit_tap_handler(): void
    {
        $modal = $this->partials($this->createCredential());

        $this->assertStringContainsString("x-on:click=\"copyFrom('loginName', 'login')\"", $modal);
        $this->assertStringContainsString("x-on:click=\"copyFrom('password', 'password')\"", $modal);

        // The values the handlers read, exposed as refs on the nodes that show them.
        $this->assertStringContainsString('x-ref="loginName"', $modal);
        $this->assertStringContainsString('x-ref="password"', $modal);
        $this->assertStringContainsString('x-data="masarCredentialCopy"', $modal);
    }

    /** The defect itself: no inline clipboard call survives in the view. */
    public function test_the_view_holds_no_inline_clipboard_handler(): void
    {
        $modal = $this->partials($this->createCredential());
        $source = file_get_contents(resource_path('views/filament/representatives/masar-issued-credential.blade.php'));

        $this->assertStringNotContainsString('navigator.clipboard', $modal);
        $this->assertStringNotContainsString('x-on:click="navigator', $source);
    }

    /**
     * Each secret appears once, as content — never again as a handler argument.
     *
     * This is the count the old implementation failed: it rendered the password both
     * inside the `<code>` element and inside that element's sibling button's
     * `x-on:click`, so the one-time secret was in the response twice.
     */
    public function test_each_secret_appears_exactly_once_in_the_modal(): void
    {
        $modal = $this->partials($this->createCredential());

        $this->assertSame(1, substr_count($modal, self::PASSWORD), 'the password is rendered more than once');
        $this->assertSame(1, substr_count($modal, self::LOGIN_NAME), 'the login name is rendered more than once');
    }

    /** Nothing copies until the operator taps: no x-init, no autofocus-driven copy. */
    public function test_nothing_is_copied_automatically(): void
    {
        $modal = $this->partials($this->createCredential());

        $this->assertStringNotContainsString('x-init', $modal);
        $this->assertStringNotContainsString('writeText', $modal);

        // The component is only ever entered through the two tap handlers.
        $this->assertSame(2, substr_count($modal, 'copyFrom('));
    }

    /** The confirmation is a local label swap, carrying no value with it. */
    public function test_the_confirmation_is_local_and_carries_no_value(): void
    {
        $modal = $this->partials($this->createCredential());

        $this->assertStringContainsString('تم النسخ', $modal);

        // Swapped by x-text with a server-rendered default, because this panel ships
        // no `[x-cloak]` rule and x-show would flash both labels until Alpine booted.
        //
        // Asserted against this view's own source and not the rendered modal:
        // Filament's modal wrapper legitimately uses `x-cloak` itself, and it ships
        // the rule for its own markup. The point here is that *this* view does not
        // depend on one.
        $this->assertStringContainsString('x-text="copied ===', $modal);
        $this->assertStringNotContainsString('x-cloak', $this->viewSource());

        // The flag names the field, never the secret.
        $this->assertStringNotContainsString("'".self::PASSWORD."'", $modal);
    }

    // ------------------------------------------------- the implementation

    /** Registered and published the same way the stylesheet is. */
    public function test_the_copy_component_is_registered_and_published(): void
    {
        $registered = collect(FilamentAsset::getScripts())
            ->filter(fn ($asset): bool => $asset instanceof Js && $asset->getId() === 'masar-credential');

        $this->assertCount(1, $registered, 'the copy component is not registered with Filament');

        $this->assertFileExists(resource_path('js/masar-credential.js'));
        $this->assertFileExists(public_path('js/app/masar-credential.js'));

        $this->assertSame(
            file_get_contents(resource_path('js/masar-credential.js')),
            file_get_contents(public_path('js/app/masar-credential.js')),
            'the published copy component differs from its source — run `php artisan filament:assets`',
        );
    }

    /** It keeps nothing anywhere a browser can persist. */
    public function test_the_copy_implementation_persists_nothing(): void
    {
        $js = $this->copyComponentSource();

        foreach (self::FORBIDDEN_STORAGE as $api) {
            $this->assertStringNotContainsString($api, $js, "the copy implementation touches {$api}");
        }
    }

    /** Copying is never a server round trip. */
    public function test_the_copy_implementation_sends_nothing(): void
    {
        $js = $this->copyComponentSource();

        foreach (self::FORBIDDEN_TRANSPORT as $call) {
            $this->assertStringNotContainsString($call, $js, "the copy implementation reaches the server via {$call}");
        }
    }

    /**
     * A fallback exists, and the node it needs cannot outlive the attempt.
     *
     * The `finally` is the part worth pinning: without it a throw between appending
     * and removing would leave a textarea holding the password in the document.
     */
    public function test_the_fallback_exists_and_removes_its_temporary_node(): void
    {
        $js = $this->copyComponentSource();

        $this->assertStringContainsString('execCommand(\'copy\')', $js, 'there is no fallback copy path');
        $this->assertStringContainsString('createElement(\'textarea\')', $js);
        $this->assertStringContainsString('.remove()', $js, 'the temporary node is never removed');
        $this->assertStringContainsString('} finally {', $js, 'removal is not guaranteed against a throw');

        // The modern path is still tried first, and its refusal is caught rather
        // than left as an unhandled rejection.
        $this->assertStringContainsString('navigator.clipboard.writeText', $js);
        $this->assertStringContainsString('} catch {', $js);
    }

    /** The failure of a copy must never print the secret. */
    public function test_the_copy_implementation_logs_nothing(): void
    {
        $js = $this->copyComponentSource();

        foreach (['console.log', 'console.error', 'console.warn', 'console.debug', 'alert('] as $call) {
            $this->assertStringNotContainsString($call, $js, "the copy implementation calls {$call}");
        }
    }

    // --------------------------------------------------------------- helpers

    /**
     * The component's executable source, with comments stripped.
     *
     * Stripping matters: the file's own docblock states in prose that it touches no
     * `localStorage` and no IndexedDB, and a naive scan would match those words and
     * fail on the documentation rather than on the code. These tests are about what
     * runs.
     */
    private function copyComponentSource(): string
    {
        $js = (string) file_get_contents(resource_path('js/masar-credential.js'));

        $js = (string) preg_replace('#/\*.*?\*/#s', '', $js);
        $js = (string) preg_replace('#^\s*//.*$#m', '', $js);

        return $js;
    }

    /**
     * The view's markup, with Blade comments stripped.
     *
     * Stripped for the same reason the JS is: the view documents *why* it avoids
     * `x-cloak`, and a naive scan would match that explanation and fail on the
     * reasoning rather than on the markup.
     */
    private function viewSource(): string
    {
        $view = (string) file_get_contents(
            resource_path('views/filament/representatives/masar-issued-credential.blade.php'),
        );

        return (string) preg_replace('#\{\{--.*?--\}\}#s', '', $view);
    }

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
            ->callAction('createMasarCredential', ['login_name' => self::LOGIN_NAME]);
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
                'login_name' => self::LOGIN_NAME,
                'password' => self::PASSWORD,
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
            if (is_string($value)) {
                $flat .= $value;
            }
        });

        return $flat;
    }
}
