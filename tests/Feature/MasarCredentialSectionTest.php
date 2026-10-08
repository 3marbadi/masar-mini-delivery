<?php

namespace Tests\Feature;

use App\Livewire\MasarCredentialSection;
use App\Models\Representative;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The «حساب مسار» card on the representative page (Masar CONTRACT §13.28.16, D29).
 *
 * The five states, the two mutating actions, the authorization boundary, and —
 * carrying most of the weight — the proof that the one-time password reaches the
 * operator's screen and nowhere else. MasarCredentialSecrecyTest covers the
 * containment sweep; this file covers behaviour.
 *
 * Every Masar call is faked. Nothing here resolves masar.daaya.ly.
 */
class MasarCredentialSectionTest extends TestCase
{
    use RefreshDatabase;

    private const UID = '0199b2c4-8e1a-7f3d-9c2e-5a7b1d3f6e80';

    private const CREDENTIAL_URL = 'https://masar.test/api/v1/integration/representatives/'.self::UID.'/credential';

    private const ROTATION_URL = self::CREDENTIAL_URL.'/rotation';

    private const TOKEN_URL = 'https://masar.test/api/v1/integration/auth/token';

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

    // ------------------------------------------------------------- the states

    /** State A — Masar has never heard of this courier. */
    public function test_an_unmapped_courier_offers_a_first_credential(): void
    {
        $this->fakeStatus($this->statusBody(mapped: false));

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->assertSee('حساب مسار')
            ->assertSee('لم يتم إنشاء حساب في مسار')
            ->assertActionVisible('createMasarCredential')
            ->assertActionHidden('resetMasarCredential')
            ->assertActionHidden('reissueMasarCredential');
    }

    /** State B — mapped by an order event, but never provisioned. */
    public function test_a_mapped_courier_without_a_credential_offers_a_first_credential(): void
    {
        $this->fakeStatus($this->statusBody());

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->assertSee('لا توجد بيانات دخول')
            ->assertActionVisible('createMasarCredential')
            ->assertActionHidden('resetMasarCredential');
    }

    /** State C — a live login. The username shows; no password exists to show. */
    public function test_an_active_credential_shows_its_username_and_offers_a_reset(): void
    {
        $this->fakeStatus($this->statusBody(
            hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
        ));

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->assertSee('اسم المستخدم')
            ->assertSee('omar.badi')
            ->assertSee('نشط')
            ->assertSee('آخر تغيير')
            ->assertActionVisible('resetMasarCredential')
            ->assertActionHidden('createMasarCredential')
            ->assertActionHidden('reissueMasarCredential')
            // Masar keeps only a hash and returns no password on a read, so the
            // one-time credential block never appears in this state.
            ->assertDontSee('data-masar-issued-password')
            ->assertDontSee('تُعرض كلمة المرور هذه مرّة واحدة فقط');
    }

    /** State D — history, nothing live. Reissue, which is a rotation. */
    public function test_a_retired_only_credential_offers_a_reissue(): void
    {
        $this->fakeStatus($this->statusBody(
            hasCredential: false, loginName: 'omar.badi', state: 'retired', updatedAt: '2026-10-07T09:00:00Z',
        ));

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->assertSee('اسم المستخدم السابق')
            ->assertSee('omar.badi')
            ->assertSee('لا توجد بيانات دخول فعّالة')
            ->assertActionVisible('reissueMasarCredential')
            ->assertActionHidden('createMasarCredential')
            ->assertActionHidden('resetMasarCredential');
    }

    public function test_the_reissue_action_rotates_rather_than_creating(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => Http::response($this->mutationBody('rotated', 'REISSUED-PASSWORD-X1')),
            '*/credential' => Http::response($this->statusBody(
                hasCredential: false, loginName: 'omar.badi', state: 'retired', updatedAt: '2026-10-07T09:00:00Z',
            )),
        ]);

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->callAction('reissueMasarCredential');

        // Masar inherits the name from the retired row, so creation would be
        // refused — the rotation leg is the one §13.28.12 routes this to.
        $this->assertSame(1, $this->requestsTo(self::ROTATION_URL));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::CREDENTIAL_URL);
    }

    /** State E — Masar unreachable. Contained, and mutations closed. */
    public function test_an_unreachable_masar_is_contained_in_the_card(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => fn () => throw new ConnectionException('timed out'),
        ]);

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->assertOk()
            ->assertSee('تعذّر الوصول إلى مسار')
            ->assertActionVisible('refreshMasarCredential')
            // Acting on a state nobody could read is how a working courier's
            // password gets replaced by accident.
            ->assertActionHidden('createMasarCredential')
            ->assertActionHidden('resetMasarCredential')
            ->assertActionHidden('reissueMasarCredential');

        // No internals on the operator's screen (§13.28.16). Numeric statuses are
        // not checked here because Tailwind class names legitimately contain them;
        // the contained wording is what the operator reads.
        foreach (['masar.test', 'client-secret', 'ConnectionException', 'Illuminate\\Http'] as $leak) {
            $component->assertDontSee($leak);
        }
    }

    public function test_the_representative_page_itself_renders_when_masar_is_down(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => fn () => throw new ConnectionException('timed out'),
        ]);

        $representative = $this->representative();

        // The card is lazily embedded, so the record renders without waiting for
        // Masar at all — and the page is a page even while Masar is unreachable.
        $this->get('/admin/representatives/'.$representative->getKey())
            ->assertOk()
            ->assertSee('Omar Badi')
            ->assertSee('الاسم')
            // Nothing of the failure reaches the record's own response.
            ->assertDontSee('تعذّر الوصول إلى مسار');
    }

    public function test_the_refresh_action_re_reads_the_state(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => Http::sequence()
                ->push($this->statusBody())
                ->push($this->statusBody(hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z')),
        ]);

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->assertSee('لا توجد بيانات دخول')
            ->callAction('refreshMasarCredential')
            ->assertSee('omar.badi')
            ->assertSee('نشط');
    }

    // ----------------------------------------------------- inactive representative

    public function test_an_inactive_representative_shows_state_but_no_mutation(): void
    {
        $this->fakeStatus($this->statusBody(
            hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
        ));

        $representative = $this->representative();
        $representative->forceFill(['is_active' => false])->save();

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $representative->getKey()])
            ->assertSee('omar.badi')
            ->assertSee('هذا المندوب غير نشط في شركة التوصيل')
            // Mini's activity flag is not propagated (§13.28.15), so the note must
            // not read as a statement about the Masar account.
            ->assertDontSee('حساب مسار معطّل')
            ->assertActionHidden('createMasarCredential')
            ->assertActionHidden('resetMasarCredential')
            ->assertActionHidden('reissueMasarCredential');

        $this->assertSame(0, $this->requestsTo(self::ROTATION_URL));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::CREDENTIAL_URL);
    }

    public function test_an_inactive_representative_is_refused_even_if_the_action_is_invoked_directly(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => Http::response($this->mutationBody('rotated', 'SHOULD-NOT-HAPPEN-X1')),
            '*/credential' => Http::response($this->statusBody(
                hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
            )),
        ]);

        $representative = $this->representative();

        // Mounted while the courier was still active, then invoked after the flag
        // changed — which is what a stale page, or a crafted call, actually looks
        // like. Hiding a button is not a boundary.
        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $representative->getKey()]);

        $representative->forceFill(['is_active' => false])->save();

        $component->mountAction('resetMasarCredential')->callMountedAction();

        // Nothing reached Masar. Two independent refusals stand behind that: the
        // action is no longer offered, and MasarCredentialClient refuses an inactive
        // representative for every caller rather than only for panel sessions
        // (§13.28.15) — proven directly in MasarCredentialClientTest.
        $this->assertSame(0, $this->requestsTo(self::ROTATION_URL));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::CREDENTIAL_URL);
    }

    // ------------------------------------------------------------ authorization

    public function test_an_unauthorized_admin_is_offered_no_credential_actions(): void
    {
        $this->fakeStatus($this->statusBody(
            hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
        ));

        $this->denyCredentialManagement();

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->assertSee('omar.badi')
            ->assertActionHidden('resetMasarCredential')
            ->assertActionHidden('createMasarCredential');
    }

    /**
     * The boundary, not the courtesy.
     *
     * A Livewire endpoint can be called directly, so the action authorizes itself
     * before touching Masar (§13.28.14).
     */
    public function test_an_unauthorized_admin_cannot_invoke_a_mutation_directly(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => Http::response($this->mutationBody('rotated', 'SHOULD-NOT-HAPPEN-X2')),
            '*/credential' => Http::response($this->statusBody(
                hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
            )),
        ]);

        // Mounted while permitted, then invoked after the ability is withdrawn: the
        // visibility rule no longer stands between the caller and the operation, so
        // what refuses is the guard inside the action (§13.28.14).
        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->mountAction('resetMasarCredential');

        $this->denyCredentialManagement();

        $component->callMountedAction();

        // The operation did not happen. Filament declines to run an action that is no
        // longer offered, and the guard inside it refuses independently — see the
        // next test, which exercises that guard on its own.
        $this->assertSame(0, $this->requestsTo(self::ROTATION_URL));
    }

    /**
     * The guard inside the action, exercised on its own.
     *
     * Reached by reflection deliberately. Filament will not run an action whose
     * visibility has become false, so going through the component would only ever
     * prove the visibility rule again — and §13.28.14 asks for the authorization to
     * be real rather than cosmetic. This calls the thing the action calls, with the
     * ability denied, and asserts it refuses.
     */
    public function test_the_in_action_authorization_guard_refuses_when_the_ability_is_denied(): void
    {
        $this->fakeStatus($this->statusBody(
            hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
        ));

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()]);

        $guard = new \ReflectionMethod(MasarCredentialSection::class, 'authorizeCredentialManagement');
        $guard->setAccessible(true);

        // Permitted: it returns quietly.
        $guard->invoke($component->instance());

        $this->denyCredentialManagement();

        $this->expectException(AuthorizationException::class);

        $guard->invoke($component->instance());
    }

    public function test_the_named_ability_is_what_decides(): void
    {
        $representative = $this->representative();

        // The policy's current meaning: any authenticated panel user (§13.28.14).
        $this->assertTrue(Gate::allows('manageMasarCredential', $representative));

        $this->denyCredentialManagement();

        $this->assertFalse(Gate::allows('manageMasarCredential', $representative));
    }

    // ------------------------------------------------------------------ creating

    public function test_creating_a_credential_shows_the_password_once(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response($this->statusBody())
                : Http::response($this->mutationBody('created', 'CREATED-ONE-TIME-PW1')),
        ]);

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->callAction('createMasarCredential', ['login_name' => 'omar.badi']);

        // Filament delivers a newly mounted action's modal as a Livewire partial
        // rather than in the component's main render, so that is where the
        // operator's one-time view is asserted. See the class comment on
        // MasarCredentialSection for why this is also where the password's whole
        // life begins and ends.
        $modal = $this->modalHtml($component);

        foreach ([
            'بيانات الدخول — تُعرض مرّة واحدة',
            'omar.badi',
            'CREATED-ONE-TIME-PW1',
            'تُعرض كلمة المرور هذه مرّة واحدة فقط',
            'نسخ اسم المستخدم',
            'نسخ كلمة المرور',
            'تم — حفظت بيانات الدخول',
        ] as $expected) {
            $this->assertStringContainsString($expected, $modal);
        }
    }

    public function test_the_create_request_carries_only_the_operators_login_name(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response($this->statusBody())
                : Http::response($this->mutationBody('created', 'CREATED-ONE-TIME-PW1')),
        ]);

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->callAction('createMasarCredential', ['login_name' => 'omar.badi']);

        // The identity, the name and the phone come from the model: there is no UI
        // path that could point this at another courier or rename one.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::CREDENTIAL_URL
            && $request->data()['login_name'] === 'omar.badi'
            && $request->data()['courier'] === ['name' => 'Omar Badi', 'phone' => '0911000000']
            && array_keys($request->data()) === ['client_mutation_id', 'login_name', 'courier']);
    }

    public function test_a_padded_login_name_is_rejected_and_not_trimmed(): void
    {
        $this->fakeStatus($this->statusBody());

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->callAction('createMasarCredential', ['login_name' => ' omar.badi '])
            ->assertHasActionErrors(['login_name']);

        // Refused before anything was sent, and emphatically not corrected to
        // "omar.badi" behind the operator's back (§13.28.6).
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::CREDENTIAL_URL);
    }

    public function test_an_over_long_login_name_is_rejected(): void
    {
        $this->fakeStatus($this->statusBody());

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->callAction('createMasarCredential', ['login_name' => str_repeat('a', 101)])
            ->assertHasActionErrors(['login_name']);
    }

    public function test_a_replayed_creation_shows_no_password_and_does_not_rotate(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response($this->statusBody())
                : Http::response($this->mutationBody('already_applied')),
        ]);

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->callAction('createMasarCredential', ['login_name' => 'omar.badi']);

        $component->assertNotified('العملية كانت منفَّذة من قبل');

        // No one-time block anywhere: a replay carries no password to show.
        $this->assertStringNotContainsString('تُعرض كلمة المرور هذه مرّة واحدة فقط', $this->modalHtml($component));

        // And a replay means it already happened; repeating it would invalidate a
        // password that may already be in the courier's hands (§13.28.11).
        $this->assertSame(0, $this->requestsTo(self::ROTATION_URL));
    }

    // ------------------------------------------------------------------ resetting

    public function test_resetting_requires_confirmation_and_states_the_consequences(): void
    {
        $this->fakeStatus($this->statusBody(
            hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
        ));

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->mountAction('resetMasarCredential');

        $modal = $this->modalHtml($component);

        foreach ([
            'هل تريد إعادة تعيين كلمة مرور المندوب؟',
            'ستتوقف جلسات المندوب الحالية',
            'اسم المستخدم سيبقى كما هو',
            'مرّة واحدة فقط',
            'إعادة التعيين',
            'إلغاء',
        ] as $expected) {
            $this->assertStringContainsString($expected, $modal);
        }

        // Mounting alone changes nothing in Masar.
        $this->assertSame(0, $this->requestsTo(self::ROTATION_URL));
    }

    public function test_resetting_shows_the_same_username_with_a_new_password(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => Http::response($this->mutationBody('rotated', 'ROTATED-ONE-TIME-PW2')),
            '*/credential' => Http::response($this->statusBody(
                hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
            )),
        ]);

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->callAction('resetMasarCredential');

        $modal = $this->modalHtml($component);

        $this->assertStringContainsString('بيانات الدخول — تُعرض مرّة واحدة', $modal);
        // The name survives the reset by contract (§13.28.7).
        $this->assertStringContainsString('omar.badi', $modal);
        $this->assertStringContainsString('ROTATED-ONE-TIME-PW2', $modal);
        $this->assertStringContainsString('تُعرض كلمة المرور هذه مرّة واحدة فقط', $modal);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::ROTATION_URL
            && $request->method() === 'POST'
            // §13.28.7: the stable name is not sent.
            && array_keys($request->data()) === ['client_mutation_id']);
    }

    public function test_a_replayed_reset_shows_no_password_and_does_not_reset_again(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => Http::response($this->mutationBody('already_applied')),
            '*/credential' => Http::response($this->statusBody(
                hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
            )),
        ]);

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->callAction('resetMasarCredential');

        $component->assertNotified('العملية كانت منفَّذة من قبل');

        $this->assertStringNotContainsString('تُعرض كلمة المرور هذه مرّة واحدة فقط', $this->modalHtml($component));
        $this->assertSame(1, $this->requestsTo(self::ROTATION_URL));
    }

    // -------------------------------------------------------------- result unknown

    public function test_an_unknown_result_is_reported_as_uncertain_and_never_retried(): void
    {
        $attempts = 0;

        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => function () use (&$attempts) {
                $attempts++;
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
            '*/credential' => Http::response($this->statusBody(
                hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
            )),
        ]);

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->callAction('resetMasarCredential');

        // Not "it failed" — it may well have happened (§13.28.11).
        $component->assertNotified('لم نتمكّن من تأكيد نتيجة العملية');

        $this->assertSame(1, $attempts, 'the mutation was retried');

        // And the refresh that resolves the uncertainty is on offer.
        $component->assertActionVisible('refreshMasarCredential');
    }

    public function test_a_server_fault_on_a_mutation_is_also_reported_as_uncertain(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => Http::response(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'x']], 500),
            '*/credential' => Http::response($this->statusBody(
                hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
            )),
        ]);

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->callAction('resetMasarCredential')
            // Masar's 500 reports a fault and does not report a rollback, so the
            // UI must not claim the reset failed.
            ->assertNotified('لم نتمكّن من تأكيد نتيجة العملية');

        $this->assertSame(1, $this->requestsTo(self::ROTATION_URL));
    }

    // ---------------------------------------------------------- settled refusals

    public function test_a_settled_refusal_is_reported_in_operator_language(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response($this->statusBody())
                : Http::response([
                    'success' => false,
                    'error' => ['code' => 'LOGIN_NAME_TAKEN', 'message' => 'The login name is already in use.'],
                    'request_id' => 'req-409',
                ], 409),
        ]);

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->callAction('createMasarCredential', ['login_name' => 'omar.badi']);

        $component->assertNotified('تعذّر تنفيذ العملية');

        // No machine codes and no request ids reach the operator. Checked against
        // everything the response carries — the render and the partials — rather
        // than the main html alone.
        $rendered = $component->html().$this->modalHtml($component);

        foreach (['LOGIN_NAME_TAKEN', 'req-409'] as $internal) {
            $this->assertStringNotContainsString($internal, $rendered);
        }
    }

    // ------------------------------------------------------------- the identifier

    /**
     * One mounted operation, one identifier (§13.28.11).
     *
     * Minted when the action mounts rather than when it runs, so two rapid
     * submissions — which both hydrate the snapshot taken after the mount — carry
     * the same identifier and Masar answers the second `already_applied` instead of
     * rotating twice.
     */
    public function test_one_mounted_operation_keeps_one_mutation_identifier(): void
    {
        $this->fakeStatus($this->statusBody(
            hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
        ));

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->mountAction('resetMasarCredential');

        $first = $component->get('mutationIds')['reset'] ?? null;

        $this->assertIsString($first);

        // A re-render — the lifecycle event §13.28.11 must not turn into a second
        // mutation — leaves it alone.
        $component->call('$refresh');

        $this->assertSame($first, $component->get('mutationIds')['reset']);
    }

    public function test_a_repeated_submission_of_one_mounted_action_reuses_the_identifier(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/rotation' => Http::response($this->mutationBody('rotated', 'ROTATED-ONE-TIME-PW2')),
            '*/credential' => Http::response($this->statusBody(
                hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
            )),
        ]);

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->mountAction('resetMasarCredential');

        $identifier = $component->get('mutationIds')['reset'];

        // The double submit: the same mounted action called twice, which is what a
        // double-clicked confirm button produces.
        $component->callMountedAction();
        $component->callMountedAction();

        $sent = Http::recorded(fn (Request $request): bool => $request->url() === self::ROTATION_URL)
            ->map(fn ($pair): string => $pair[0]->data()['client_mutation_id'])
            ->unique()
            ->values();

        // Masar sees one intent, whatever the browser did.
        $this->assertCount(1, $sent, 'two different mutation identifiers were sent');
        $this->assertSame($identifier, $sent->first());
    }

    public function test_a_new_deliberate_action_gets_a_new_identifier(): void
    {
        $this->fakeStatus($this->statusBody(
            hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
        ));

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->mountAction('resetMasarCredential');

        $first = $component->get('mutationIds')['reset'];

        $component->unmountAction()->mountAction('resetMasarCredential');

        // A genuinely new operator action is a new intent, and must be able to
        // perform a second reset.
        $this->assertNotSame($first, $component->get('mutationIds')['reset']);
    }

    // ----------------------------------------------------------- request counting

    public function test_one_render_produces_one_status_read(): void
    {
        $this->fakeStatus($this->statusBody(
            hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
        ));

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()]);

        // Several closures ask about the state — three visibility checks and the
        // view — and the memo means Masar is asked once.
        $this->assertSame(1, $this->statusReads());
    }

    public function test_one_create_action_produces_one_masar_post(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response($this->statusBody())
                : Http::response($this->mutationBody('created', 'CREATED-ONE-TIME-PW1')),
        ]);

        Livewire::test(MasarCredentialSection::class, ['representativeId' => $this->representative()->getKey()])
            ->callAction('createMasarCredential', ['login_name' => 'omar.badi']);

        $posts = Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::CREDENTIAL_URL)->count();

        $this->assertSame(1, $posts);
    }

    // --------------------------------------------------------------- the display

    public function test_the_card_exposes_no_internal_identifiers(): void
    {
        $this->fakeStatus($this->statusBody(
            hasCredential: true, loginName: 'omar.badi', state: 'active', updatedAt: '2026-10-07T09:00:00Z',
        ));

        $representative = $this->representative();

        $component = Livewire::test(MasarCredentialSection::class, ['representativeId' => $representative->getKey()]);

        // The uid is how the wire names the courier and is of no use to an operator.
        $component->assertDontSee(self::UID);
        $component->assertDontSee('integration_uid');
        $component->assertDontSee('credential_id');
    }

    public function test_the_existing_representative_fields_are_untouched(): void
    {
        $this->fakeStatus($this->statusBody());

        $representative = $this->representative();

        $this->get('/admin/representatives/'.$representative->getKey())
            ->assertOk()
            ->assertSee('الاسم')
            ->assertSee('الهاتف')
            ->assertSee('نشط')
            ->assertSee('تاريخ الإنشاء')
            ->assertSee('آخر تحديث');
    }

    // --------------------------------------------------------------- helpers

    /**
     * Denies the one named ability, and only it.
     *
     * The project has no unauthorized logged-in admin type to act as — the boundary
     * today is "can sign in" (§13.28.14) — so the ability itself is denied, which is
     * exactly the thing under test: that the actions consult it rather than assuming
     * it.
     *
     * `Gate::before` and not `Gate::define`, because a registered policy wins over a
     * closure ability when the check carries a model instance; `before` runs ahead of
     * both. Scoped to this one ability by returning null for everything else, so the
     * panel's own authorization is untouched.
     */
    private function denyCredentialManagement(): void
    {
        Gate::before(fn ($user, string $ability): ?bool => $ability === 'manageMasarCredential' ? false : null);
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

    /** @param  array<string, mixed>  $body */
    private function fakeStatus(array $body): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->tokenBody()),
            '*/credential' => Http::response($body),
        ]);
    }

    /** @return array<string, mixed> */
    private function tokenBody(): array
    {
        return ['success' => true, 'access_token' => 'access-token', 'token_type' => 'Bearer', 'expires_in' => 3600];
    }

    /** @return array<string, mixed> */
    private function statusBody(
        bool $mapped = true,
        bool $hasCredential = false,
        ?string $loginName = null,
        string $state = 'none',
        ?string $updatedAt = null,
    ): array {
        return [
            'success' => true,
            'external_courier_id' => self::UID,
            'mapped' => $mapped,
            'has_credential' => $hasCredential,
            'login_name' => $loginName,
            'credential_status' => $state,
            'credentials_updated_at' => $updatedAt,
            'request_id' => 'req-status',
        ];
    }

    /** @return array<string, mixed> */
    private function mutationBody(string $status, ?string $password = null): array
    {
        $credential = [
            'login_name' => 'omar.badi',
            'credential_status' => 'active',
            'credentials_updated_at' => '2026-10-07T09:00:00Z',
        ];

        if ($password !== null) {
            $credential['password'] = $password;
        }

        return [
            'success' => true,
            'status' => $status,
            'external_courier_id' => self::UID,
            'mapped' => true,
            'has_credential' => true,
            'credential' => $credential,
            'request_id' => 'req-mutation',
        ];
    }

    /**
     * The modal HTML Filament delivers as a Livewire partial.
     *
     * A newly mounted action's modal is not part of the component's main render in
     * Livewire 4 — Filament emits it through `wire:partial="action-modals"` and the
     * `partials` effect — so assertions about what the operator sees have to read it
     * from there.
     */
    private function modalHtml(Testable $component): string
    {
        $flat = '';

        $partials = $component->effects['partials'] ?? [];

        array_walk_recursive($partials, function ($value) use (&$flat): void {
            $flat .= is_string($value) ? $value : '';
        });

        return $flat;
    }

    private function requestsTo(string $url): int
    {
        return Http::recorded(fn (Request $request): bool => $request->url() === $url)->count();
    }

    private function statusReads(): int
    {
        return Http::recorded(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::CREDENTIAL_URL)->count();
    }
}
