<?php

namespace Tests\Feature;

use App\Livewire\MasarCredentialSection;
use App\Models\Representative;
use App\Models\User;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The credential card is actually styled by a stylesheet the panel actually loads.
 *
 * This suite exists because of a defect that every previous test passed straight
 * over: the two credential views were written in Tailwind utility classes
 * (`rounded-lg`, `gap-3`, `text-danger-700` …), and the panel's compiled stylesheet
 * — `public/css/filament/filament/app.css` — contains Filament's semantic `.fi-*`
 * selectors and **not one** of those utilities. Every assertion about the markup
 * passed while the card rendered as unstyled text, because `assertSee` cannot tell a
 * class that styles something from a class that styles nothing.
 *
 * So the chain is asserted end to end rather than at one link:
 *
 *   1. the stylesheet is registered with Filament's asset registry;
 *   2. the published file exists where the registry says it will;
 *   3. every class the two views use is defined in that published file — or is a
 *      Filament class present in the panel's own stylesheet;
 *   4. the panel page emits a `<link>` to it;
 *   5. and no raw Tailwind utility has crept back in.
 *
 * Point 3 is the one that would have caught the original defect, and point 5 is the
 * one that keeps it from returning.
 */
class MasarCredentialStylingTest extends TestCase
{
    use RefreshDatabase;

    private const UID = '0199b2c4-8e1a-7f3d-9c2e-5a7b1d3f6e80';

    private const TOKEN_URL = 'https://masar.test/api/v1/integration/auth/token';

    /**
     * Utility class names that must never reappear in these views.
     *
     * A sample rather than an exhaustive list of Tailwind: these are the ones the
     * original views actually used, so a regression would almost certainly bring one
     * of them back.
     */
    private const FORBIDDEN_UTILITIES = [
        'rounded-lg', 'rounded-md', 'px-3', 'py-2', 'p-3', 'mt-1', 'mt-2', 'mt-4',
        'space-y-4', 'gap-2', 'gap-3', 'grid', 'flex-wrap', 'grow', 'text-sm',
        'text-base', 'font-mono', 'font-medium', 'font-semibold', 'tracking-wider',
        'sm:grid-cols-2', 'sm:col-span-2', 'text-gray-500', 'text-gray-600',
        'text-gray-950', 'dark:text-gray-400', 'bg-gray-50', 'dark:bg-white/5',
        'text-danger-700', 'bg-danger-50', 'border-danger-300', 'text-warning-600',
        'text-success-600', 'items-center', 'border',
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

    // ------------------------------------------------------------- registration

    public function test_the_credential_stylesheet_is_registered_with_filament(): void
    {
        $registered = collect(FilamentAsset::getStyles())
            ->first(fn (Css $asset): bool => $asset->getId() === 'masar-credential');

        $this->assertNotNull($registered, 'the credential stylesheet is not registered with FilamentAsset');
        $this->assertFalse($registered->isRemote(), 'the stylesheet must ship with the application, not be fetched');
        $this->assertFileExists((string) $registered->getPath(), 'the registered source stylesheet is missing');
    }

    public function test_the_registered_stylesheet_is_published_where_the_registry_says(): void
    {
        $published = public_path($this->asset()->getRelativePublicPath());

        // Published by `php artisan filament:assets`, the command this project already
        // runs for Filament's own stylesheet — so this feature adds no build step.
        $this->assertFileExists(
            $published,
            'run `php artisan filament:assets` — the credential stylesheet is not published',
        );

        $this->assertNotSame('', trim((string) file_get_contents($published)));
    }

    public function test_the_published_stylesheet_matches_the_tracked_source(): void
    {
        $source = (string) file_get_contents((string) $this->asset()->getPath());
        $published = (string) file_get_contents(public_path($this->asset()->getRelativePublicPath()));

        // A published copy that has drifted from the tracked source is a deployment
        // that ships something nobody reviewed.
        $this->assertSame(
            md5($source),
            md5($published),
            'the published stylesheet differs from the tracked source — re-run `php artisan filament:assets`',
        );
    }

    // -------------------------------------------------------------- the page link

    public function test_the_representative_page_links_the_credential_stylesheet(): void
    {
        $this->fakeStatus();

        $response = $this->get('/admin/representatives/'.$this->representative()->getKey());

        $response->assertOk();

        // `@filamentStyles` in the panel's base layout renders this. Asserting the
        // href rather than the directive, because the directive could be present and
        // the asset still unregistered.
        $response->assertSee('css/app/masar-credential.css', escape: false);
        $response->assertSee('rel="stylesheet"', escape: false);
    }

    public function test_the_panel_exposes_the_colour_variables_the_stylesheet_uses(): void
    {
        $this->fakeStatus();

        $html = $this->get('/admin/representatives/'.$this->representative()->getKey())
            ->assertOk()
            ->getContent();

        // The stylesheet styles itself from Filament's palette rather than literals,
        // so the card follows the panel's theme. Those variables are emitted inline by
        // `@filamentStyles`; without them every colour here would fall back to
        // nothing.
        foreach (['--danger-50', '--danger-300', '--danger-700', '--warning-600', '--success-600', '--gray-500', '--gray-950'] as $variable) {
            $this->assertStringContainsString($variable.':', $html, "the panel does not emit {$variable}");
        }
    }

    // ---------------------------------------------- every class is actually defined

    public function test_every_class_the_views_use_is_defined_in_a_loaded_stylesheet(): void
    {
        $credentialCss = (string) file_get_contents(public_path($this->asset()->getRelativePublicPath()));
        $panelCss = (string) file_get_contents(public_path('css/filament/filament/app.css'));

        $undefined = [];

        foreach ($this->classesUsedByTheViews() as $class => $view) {
            $selector = '.'.$class;

            $definedHere = str_contains($credentialCss, $selector);
            $definedByFilament = (bool) preg_match('/\.'.preg_quote($class, '/').'[\s,{:>~+]/', $panelCss);

            if (! $definedHere && ! $definedByFilament) {
                $undefined[] = "{$class} (in {$view})";
            }
        }

        $this->assertSame(
            [],
            $undefined,
            "these classes style nothing — they are in no loaded stylesheet:\n  ".implode("\n  ", $undefined),
        );
    }

    public function test_no_raw_tailwind_utility_remains_in_the_credential_views(): void
    {
        $leaks = [];

        foreach ($this->viewFiles() as $label => $file) {
            $classAttributes = $this->classAttributesIn($file);

            foreach (self::FORBIDDEN_UTILITIES as $utility) {
                foreach ($classAttributes as $attribute) {
                    if (in_array($utility, preg_split('/\s+/', trim($attribute)) ?: [], true)) {
                        $leaks[] = "{$utility} (in {$label})";
                    }
                }
            }
        }

        $this->assertSame([], $leaks, 'raw Tailwind utilities are back: '.implode(', ', $leaks));
    }

    // ------------------------------------------- the warning is more than a colour

    public function test_the_one_time_warning_is_emphasised_by_more_than_colour(): void
    {
        $css = (string) file_get_contents(public_path($this->asset()->getRelativePublicPath()));

        $rule = $this->ruleFor($css, '.masar-credential-warning');

        // Colour alone fails WCAG 1.4.1 and fails anyone reading a monochrome screen,
        // so the warning carries a border, a tinted panel and its own padding too.
        $this->assertStringContainsString('border:', $rule);
        $this->assertStringContainsString('background-color:', $rule);
        $this->assertStringContainsString('padding:', $rule);

        // Plus a bold heading and an icon, in the markup.
        $this->assertStringContainsString('font-weight: 700', $this->ruleFor($css, '.masar-credential-warning-heading'));

        $view = (string) file_get_contents($this->viewFiles()['issued credential modal']);
        $this->assertStringContainsString('heroicon-m-exclamation-triangle', $view);
        $this->assertStringContainsString('role="alert"', $view);
    }

    public function test_the_card_remains_readable_in_dark_mode(): void
    {
        $css = (string) file_get_contents(public_path($this->asset()->getRelativePublicPath()));

        // Filament's own compiled stylesheet uses `:where(.dark, .dark *)`; matching it
        // keeps specificity at zero so these rules never outrank anything.
        $this->assertStringContainsString(':where(.dark, .dark *)', $css);

        foreach ([
            '.masar-credential-value',
            '.masar-credential-label',
            '.masar-credential-warning',
            '.masar-credential-secret-value',
            '.masar-credential-state--active',
            '.masar-credential-state--retired',
        ] as $class) {
            $this->assertMatchesRegularExpression(
                '/:where\(\.dark, \.dark \*\) '.preg_quote($class, '/').'\s*\{/',
                $css,
                "{$class} has no dark-mode rule",
            );
        }
    }

    // ------------------------------------------- the styled markup actually renders

    public function test_the_rendered_card_carries_the_feature_classes(): void
    {
        $this->fakeStatus(hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z');

        $html = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->assertOk()
            ->html();

        foreach ([
            'masar-credential-grid',
            'masar-credential-label',
            'masar-credential-value',
            'masar-credential-state--active',
            'masar-credential-actions',
        ] as $class) {
            $this->assertStringContainsString($class, $html);
        }
    }

    public function test_the_rendered_one_time_modal_carries_the_warning_classes(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response($this->statusBody())
                : Http::response($this->issuedBody()),
        ]);

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->callAction('createMasarCredential', ['login_name' => 'omar.badi']);

        $modal = $this->partials($component);

        foreach ([
            'masar-credential-issued',
            'masar-credential-secret-value',
            'masar-credential-secret-value--password',
            'masar-credential-warning',
            'masar-credential-warning-heading',
        ] as $class) {
            $this->assertStringContainsString($class, $modal);
        }
    }

    // --------------------------------------------------------------- helpers

    private function asset(): Css
    {
        $asset = collect(FilamentAsset::getStyles())
            ->first(fn (Css $css): bool => $css->getId() === 'masar-credential');

        $this->assertNotNull($asset, 'the credential stylesheet is not registered');

        return $asset;
    }

    /** @return array<string, string> */
    private function viewFiles(): array
    {
        return [
            'credential section card' => resource_path('views/livewire/masar-credential-section.blade.php'),
            'issued credential modal' => resource_path('views/filament/representatives/masar-issued-credential.blade.php'),
        ];
    }

    /**
     * Every class token the two views put in a `class="…"` attribute.
     *
     * Blade expressions inside an attribute are skipped — there are none in these two
     * views, and a token containing `{{` could not be a class name anyway.
     *
     * @return array<string, string> class => the view it appears in
     */
    private function classesUsedByTheViews(): array
    {
        $classes = [];

        foreach ($this->viewFiles() as $label => $file) {
            foreach ($this->classAttributesIn($file) as $attribute) {
                foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $class) {
                    if ($class === '' || str_contains($class, '{{') || str_contains($class, '$')) {
                        continue;
                    }

                    $classes[$class] ??= $label;
                }
            }
        }

        return $classes;
    }

    /** @return list<string> */
    private function classAttributesIn(string $file): array
    {
        preg_match_all('/class="([^"]*)"/', (string) file_get_contents($file), $matches);

        return $matches[1] ?? [];
    }

    /** The declaration block of one selector, for asserting what carries the emphasis. */
    private function ruleFor(string $css, string $selector): string
    {
        $pattern = '/'.preg_quote($selector, '/').'\s*\{([^}]*)\}/';

        $this->assertMatchesRegularExpression($pattern, $css, "no rule found for {$selector}");

        preg_match($pattern, $css, $matches);

        return $matches[1];
    }

    private function partials(Testable $component): string
    {
        $flat = '';
        $partials = $component->effects['partials'] ?? [];

        array_walk_recursive($partials, function ($value) use (&$flat): void {
            $flat .= is_string($value) ? $value : '';
        });

        return $flat;
    }

    private function representative(): Representative
    {
        $representative = Representative::query()->create([
            'name' => 'Omar Badi',
            'phone' => '0911000000',
            'is_active' => true,
        ]);

        $representative->forceFill(['integration_uid' => self::UID])->save();

        return $representative->refresh();
    }

    private function fakeStatus(
        bool $hasCredential = false,
        ?string $loginName = null,
        string $state = 'none',
        ?string $updatedAt = null,
    ): void {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => Http::response($this->statusBody($hasCredential, $loginName, $state, $updatedAt)),
        ]);
    }

    /** @return array<string, mixed> */
    private function tokenBody(): array
    {
        return ['success' => true, 'access_token' => 'access-token', 'token_type' => 'Bearer', 'expires_in' => 3600];
    }

    /** @return array<string, mixed> */
    private function statusBody(
        bool $hasCredential = false,
        ?string $loginName = null,
        string $state = 'none',
        ?string $updatedAt = null,
    ): array {
        return [
            'success' => true,
            'external_courier_id' => self::UID,
            'mapped' => true,
            'has_credential' => $hasCredential,
            'login_name' => $loginName,
            'credential_status' => $state,
            'credentials_updated_at' => $updatedAt,
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
                'password' => 'STYLING-TEST-PASSWORD',
                'credential_status' => 'active',
                'credentials_updated_at' => '2026-10-07T09:00:00Z',
            ],
            'request_id' => 'req-mutation',
        ];
    }
}
