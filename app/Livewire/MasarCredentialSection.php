<?php

namespace App\Livewire;

use App\Enums\MasarCredentialFailure;
use App\Enums\MasarCredentialState;
use App\Exceptions\MasarCredentialException;
use App\Models\Representative;
use App\Services\Integration\MasarCredentialClient;
use App\Services\Integration\MasarCredentialStatus;
use App\Services\Integration\MasarIssuedCredential;
use App\Services\Integration\MasarReplayedCredential;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The «حساب مسار» section of the representative page (Masar CONTRACT §13.28.16).
 *
 * A component of its own rather than a few entries on the representative
 * infolist, for three reasons that all turn out to be the same reason:
 *
 *   - **the page must survive Masar being down.** It is embedded lazily, so the
 *     representative's own details render immediately and this section resolves
 *     afterwards. A read that times out therefore degrades one card to its
 *     unavailable state instead of stalling — or failing — the whole record
 *     (§13.28.16: «تُعرَض حالُ تعذُّرٍ، وتُقفَل أفعالُ التبديل»);
 *   - **one render, one read.** The status is resolved once per request and
 *     memoised, so the several closures that ask about it do not each produce an
 *     HTTP call to Masar;
 *   - **the smallest possible public surface around a secret.** Everything
 *     Livewire serialises on this component is listed in one short block below,
 *     and the issued password is deliberately not in it.
 *
 * ## How the one-time password is shown without surviving the request
 *
 * Livewire synthesises **public** properties into the snapshot that travels to the
 * browser and back — this project runs Livewire 4.4. Protected and private
 * properties are not part of that snapshot at all: they are reconstructed as their
 * declared defaults on every hydration. So `$issuedCredential` below is protected,
 * and it exists only for the tail of the request that created it.
 *
 * That is not a version-specific quirk to be re-checked on upgrade — a component's
 * serialized state has only ever been its public surface — but the version is named
 * because the *delivery* of the modal below is version-specific, and a reader
 * checking one will want to check the other.
 *
 * The sequence inside one Livewire request is:
 *
 *   1. the operator submits the create (or confirms the reset) action;
 *   2. `MasarCredentialClient` returns a MasarIssuedCredential;
 *   3. it is held in `$this->issuedCredential` — protected, so not snapshot state;
 *   4. `replaceMountedAction('masarIssuedCredential')` swaps the mounted action,
 *      which Filament renders in **this same response** (`mountAction()` pushes
 *      onto `$mountedActions` and the component re-renders at the end of the
 *      request — verified against filament/actions v5.7.6,
 *      `InteractsWithActions::replaceMountedAction()`);
 *   5. that action's `modalContent()` closure reads the protected property and
 *      renders the password into the HTML the operator sees.
 *
 * What travels back to the browser is the *rendered HTML* of that modal — which is
 * the point, the operator has to read it — and a snapshot whose `mountedActions`
 * entry carries the action **name** and nothing else. The password is not in
 * `arguments` (public), not in a property (protected), and not in any store.
 *
 * On the next Livewire request the protected property is gone, so if the modal is
 * re-rendered for any reason it says the password is no longer available rather
 * than reproducing it. That is not a degradation; it is §13.28.10 holding:
 * «فالنصُّ يعيش في موضعين لا ثالثَ لهما … وينعدم بانعدام ذلك الطلب».
 *
 * The one-time result is also never a Notification. Notifications are flashed
 * through the session to survive a redirect, and this application is configured
 * for database-backed sessions — a password in a notification body would be a
 * password in MySQL.
 */
class MasarCredentialSection extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /**
     * The courier this section is about.
     *
     * `#[Locked]` because it is the whole authorization subject: without it, a
     * crafted Livewire payload could point a legitimately-mounted action at
     * somebody else's representative.
     */
    #[Locked]
    public int $representativeId;

    /**
     * One mutation identifier per mounted operation, keyed by operation
     * (Masar CONTRACT §13.28.11).
     *
     * **Public on purpose, and it is not a secret.** It has to survive from the
     * moment an action is mounted to the moment it is submitted, which means it
     * has to be in the snapshot — and that is exactly what makes it do its job: a
     * double-submitted form sends the *same* snapshot twice, so both copies carry
     * the same identifier, and Masar answers the second one `already_applied`
     * instead of rotating a second time.
     *
     * Generated in each action's `mountUsing()` rather than when the action runs.
     * Generating it at run time would hand two rapid submits two different
     * identifiers, which is precisely the «سرٌّ أُنشئ ولم يَعلم به أحد» failure the
     * identifier exists to prevent.
     *
     * @var array<string, string>
     */
    public array $mutationIds = [];

    /** Set by the refresh action so a re-read is not served from the memo. */
    public int $readToken = 0;

    /**
     * The one copy of a freshly issued password — protected, so never snapshot
     * state, and therefore gone by the next request.
     */
    protected ?MasarIssuedCredential $issuedCredential = null;

    /** Resolved once per request: see the note on one render, one read. */
    protected ?MasarCredentialStatus $status = null;

    protected ?MasarCredentialException $statusFailure = null;

    protected bool $statusResolved = false;

    protected ?Representative $representative = null;

    public function mount(int $representativeId): void
    {
        $this->representativeId = $representativeId;
    }

    public function render(): View
    {
        return view('livewire.masar-credential-section');
    }

    // ------------------------------------------------------------------ reading

    /**
     * The courier's credential state, read at most once per request.
     *
     * Masar is the authority and Mini stores nothing (§13.28.3), so this is a live
     * read every time the section renders — but only one, however many callers ask
     * for it. The three visibility closures and the view all come through here.
     *
     * `#[Computed]` and not a plain public method, for two reasons beyond the
     * memoisation: a computed property is not part of the Livewire snapshot, and
     * Livewire refuses to invoke one as an action from the browser
     * (`CannotCallComputedDirectlyException`), so exposing the read does not widen
     * this component's callable surface.
     *
     * Null means "Masar did not answer" — the state the card renders as unavailable
     * and the reason the mutating actions close.
     */
    #[Computed]
    public function credentialStatus(): ?MasarCredentialStatus
    {
        $this->resolveStatus();

        return $this->status;
    }

    private function resolveStatus(): void
    {
        if ($this->statusResolved) {
            return;
        }

        $this->statusResolved = true;

        try {
            $this->status = $this->client()->status($this->representative(), auth()->id());
        } catch (MasarCredentialException $failure) {
            // Contained here and nowhere else: the representative record itself
            // renders regardless of what Masar answers (§13.28.16).
            $this->statusFailure = $failure;
        }
    }

    /** Re-reads the state. The safe leg to repeat (§13.28.11). */
    public function refreshMasarCredentialAction(): Action
    {
        return Action::make('refreshMasarCredential')
            ->label('تحديث')
            ->icon('heroicon-m-arrow-path')
            ->color('gray')
            ->link()
            ->action(function (): void {
                // Drops the memo for this request and bumps a token so the
                // re-render genuinely asks Masar again.
                $this->forgetStatus();
                $this->readToken++;
            });
    }

    // ----------------------------------------------------------------- creating

    /**
     * Issues a courier's first credential (§13.28.6).
     *
     * The only operator input is the login name. The identity, the name and the
     * phone are read off the model by the client, so there is nothing here that
     * could point the operation at a different courier.
     */
    public function createMasarCredentialAction(): Action
    {
        return Action::make('createMasarCredential')
            ->label('إنشاء حساب دخول في مسار')
            ->icon('heroicon-m-key')
            ->modalHeading('إنشاء حساب دخول في مسار')
            ->modalSubmitActionLabel('إنشاء')
            ->modalCancelActionLabel('إلغاء')
            ->visible(fn (): bool => $this->canMutate() && $this->needsFirstCredential())
            ->mountUsing(fn (?Schema $schema = null) => $this->beginOperation('create', $schema))
            ->schema([
                TextInput::make('login_name')
                    ->label('اسم المستخدم')
                    ->helperText('سيستخدم المندوب اسم المستخدم هذا مع كلمة المرور للدخول إلى تطبيق مسار.')
                    ->required()
                    ->maxLength(100)
                    // Refused, never trimmed into shape: a name quietly corrected
                    // is a name the courier will be given and will fail to type
                    // (§13.28.6). The rule mirrors Masar's own.
                    ->rule('regex:/^\S(?:.*\S)?$/u')
                    ->validationMessages([
                        'regex' => 'اسم المستخدم لا يجوز أن يبدأ أو ينتهي بمسافة.',
                    ])
                    ->autocomplete(false),
            ])
            ->action(function (array $data): void {
                $this->authorizeCredentialManagement();

                $this->perform(
                    fn (): MasarIssuedCredential|MasarReplayedCredential => $this->client()->create(
                        $this->representative(),
                        (string) $data['login_name'],
                        $this->mutationId('create'),
                        auth()->id(),
                    ),
                    replayMessage: 'تم تنفيذ هذه العملية سابقًا، لذلك لا يمكن عرض كلمة المرور مرة أخرى. حدّث حالة الحساب، وإذا لم تعد كلمة المرور متوفرة فأعد تعيينها.',
                );
            });
    }

    // ----------------------------------------------------------------- resetting

    /**
     * Replaces the password and keeps the login name (§13.28.7).
     *
     * No name field and no password field: the name survives the reset, so there
     * is nothing for the operator to choose, and Masar generates the secret.
     */
    public function resetMasarCredentialAction(): Action
    {
        return Action::make('resetMasarCredential')
            ->label('إعادة تعيين كلمة المرور')
            ->icon('heroicon-m-arrow-path-rounded-square')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('هل تريد إعادة تعيين كلمة مرور المندوب؟')
            ->modalDescription(
                'ستتوقف جلسات المندوب الحالية على تطبيق مسار، وسيحتاج إلى تسجيل الدخول من جديد '
                .'باستخدام كلمة المرور الجديدة. اسم المستخدم سيبقى كما هو. '
                .'وستُعرض كلمة المرور الجديدة مرّة واحدة فقط.'
            )
            ->modalSubmitActionLabel('إعادة التعيين')
            ->modalCancelActionLabel('إلغاء')
            ->visible(fn (): bool => $this->canMutate() && $this->hasActiveCredential())
            ->mountUsing(fn (?Schema $schema = null) => $this->beginOperation('reset', $schema))
            ->action(fn () => $this->rotate());
    }

    /**
     * State D: history exists, nothing live in it (§13.28.12).
     *
     * A different label and a different confirmation, because to the operator this
     * is issuing a login rather than replacing one — but it is the same `rotate()`
     * leg, since Masar inherits the name from the retired row and creation would be
     * refused with `CREDENTIAL_RETIRED_EXISTS`.
     */
    public function reissueMasarCredentialAction(): Action
    {
        return Action::make('reissueMasarCredential')
            ->label('إصدار بيانات دخول جديدة')
            ->icon('heroicon-m-key')
            ->requiresConfirmation()
            ->modalHeading('إصدار بيانات دخول جديدة لهذا المندوب؟')
            ->modalDescription(
                'سيتم إصدار كلمة مرور جديدة باستخدام اسم المستخدم السابق. '
                .'وستظهر كلمة المرور مرة واحدة فقط.'
            )
            ->modalSubmitActionLabel('إصدار')
            ->modalCancelActionLabel('إلغاء')
            ->visible(fn (): bool => $this->canMutate() && $this->hasRetiredCredentialOnly())
            ->mountUsing(fn (?Schema $schema = null) => $this->beginOperation('reset', $schema))
            ->action(fn () => $this->rotate());
    }

    private function rotate(): void
    {
        $this->authorizeCredentialManagement();

        $this->perform(
            fn (): MasarIssuedCredential|MasarReplayedCredential => $this->client()->rotate(
                $this->representative(),
                $this->mutationId('reset'),
                auth()->id(),
            ),
            replayMessage: 'تم تنفيذ إعادة التعيين سابقًا، ولا يمكن إعادة عرض كلمة المرور الناتجة. حدّث حالة الحساب، وإذا لم تكن كلمة المرور محفوظة لديك فأعد التعيين مرة أخرى يدويًا.',
        );
    }

    // ------------------------------------------------------- the one-time result

    /**
     * The modal that shows a password once.
     *
     * Its content is a closure, so it is evaluated while the response is being
     * rendered — which is the only moment the protected property holds anything.
     * Mounted through `replaceMountedAction()` from inside the operation that
     * produced the credential, so no second round trip stands between the secret
     * and the screen.
     *
     * When the property is empty — any later request that re-renders this mounted
     * action — it says so instead of reproducing anything. There is nothing to
     * reproduce from.
     */
    public function masarIssuedCredentialAction(): Action
    {
        return Action::make('masarIssuedCredential')
            ->modalHeading('بيانات الدخول — تُعرض مرّة واحدة')
            ->modalWidth(Width::Large)
            ->modalContent(fn (): View => view('filament.representatives.masar-issued-credential', [
                'credential' => $this->issuedCredential,
            ]))
            // No submit: there is nothing to submit. One button, and closing is
            // what ends the password's life.
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('تم — حفظت بيانات الدخول')
            ->action(function (): void {
                // Reached only if the close button is treated as a submit by a
                // future Filament change; deliberately a no-op.
            });
    }

    // --------------------------------------------------------------- the plumbing

    /**
     * Runs one credential mutation and turns its three outcomes into UI.
     *
     * Applied → the one-time modal. Replayed → a safe sentence and a re-read, and
     * emphatically **not** another rotation (§13.28.11: a replay means the
     * operation already happened; repeating it would invalidate a password that may
     * already be in the courier's hands). Failed → a message whose severity depends
     * on whether the outcome is known.
     *
     * @param  callable(): (MasarIssuedCredential|MasarReplayedCredential)  $operation
     */
    private function perform(callable $operation, string $replayMessage): void
    {
        try {
            $outcome = $operation();
        } catch (MasarCredentialException $failure) {
            $this->reportFailure($failure);

            return;
        }

        if ($outcome instanceof MasarIssuedCredential) {
            $this->issuedCredential = $outcome;
            $this->forgetStatus();

            // Same request, same response: see the class comment.
            $this->replaceMountedAction('masarIssuedCredential');

            return;
        }

        $this->forgetStatus();

        Notification::make()
            ->warning()
            ->title('العملية كانت منفَّذة من قبل')
            ->body($replayMessage)
            ->persistent()
            ->send();
    }

    /**
     * An operation that produced no credential, said in operator language.
     *
     * The unknown case is the one that must not read like a failure
     * (§13.28.11) — it gets its own wording, its own severity, and a pointer at the
     * refresh action rather than an invitation to try again. Nothing here exposes a
     * status code, an error code, a URL, a client id or an exception class
     * (§13.28.16).
     */
    private function reportFailure(MasarCredentialException $failure): void
    {
        $this->forgetStatus();

        if ($failure->failure === MasarCredentialFailure::ResultUnknown) {
            Notification::make()
                ->warning()
                ->title('لم نتمكّن من تأكيد نتيجة العملية')
                ->body('قد تكون العملية نُفِّذت في مسار. حدّث حالة حساب مسار قبل المحاولة من جديد.')
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->danger()
            ->title('تعذّر تنفيذ العملية')
            ->body(self::failureMessage($failure->failure))
            ->persistent()
            ->send();
    }

    /** One operator-safe sentence per classification (§13.28.16). */
    public static function failureMessage(MasarCredentialFailure $failure): string
    {
        return match ($failure) {
            MasarCredentialFailure::CredentialAlreadyExists => 'يوجد حساب دخول فعّال لهذا المندوب. استخدم إعادة تعيين كلمة المرور.',
            MasarCredentialFailure::CredentialRetiredExists => 'لهذا المندوب حساب سابق غير فعّال. استخدم إصدار بيانات دخول جديدة.',
            MasarCredentialFailure::NoCredentialToReset => 'لا توجد بيانات دخول لإعادة تعيينها. أنشئ الحساب أولًا.',
            MasarCredentialFailure::LoginNameTaken => 'اسم المستخدم مستخدم بالفعل. اختر اسمًا آخر.',
            MasarCredentialFailure::IdempotencyKeyReused => 'حدث تعارض في تنفيذ العملية. حدّث الحالة قبل المحاولة من جديد.',
            MasarCredentialFailure::ValidationError => 'البيانات المدخلة غير صالحة.',
            MasarCredentialFailure::AuthenticationFailure,
            MasarCredentialFailure::ForbiddenClient => 'تعذّر التحقق من اتصال مسار. راجع إعدادات التكامل.',
            MasarCredentialFailure::RateLimited => 'محاولات كثيرة. انتظر قليلًا ثم أعد المحاولة.',
            MasarCredentialFailure::RepresentativeInactive => 'المندوب غير نشط ولا يمكن تعديل بيانات دخول مسار له.',
            MasarCredentialFailure::MissingExternalIdentity => 'تعذّر تحديد هوية المندوب الخاصة بالتكامل.',
            MasarCredentialFailure::ContractViolation => 'تعذّر قراءة استجابة مسار بصورة صحيحة.',
            MasarCredentialFailure::ResultUnknown => 'لم نتمكّن من تأكيد نتيجة العملية. حدّث حالة حساب مسار قبل المحاولة من جديد.',
            MasarCredentialFailure::Unavailable,
            MasarCredentialFailure::ServerError => 'تعذّر الوصول إلى مسار. لا يمكن عرض حالة حساب الدخول الآن.',
        };
    }

    /**
     * Begins one deliberate operator action.
     *
     * The identifier is minted here — at mount — so that every submission of this
     * mounted action carries the same one. See the note on `$mutationIds`.
     */
    private function beginOperation(string $operation, ?Schema $schema): void
    {
        $this->mutationIds[$operation] = (string) Str::uuid();

        // Null for the confirmation-only actions, which carry no form. Overriding
        // `mountUsing` replaces Filament's default fill, so it is restored here for
        // the one action that does have a field.
        $schema?->fill();
    }

    private function mutationId(string $operation): string
    {
        // Minted at mount; the fallback exists so a programmatically-called action
        // still has one rather than failing validation downstream.
        return $this->mutationIds[$operation] ??= (string) Str::uuid();
    }

    /**
     * The server-side half of authorization (§13.28.14).
     *
     * Checked inside the action and not only in `visible()`, because a Livewire
     * endpoint can be called directly: hiding a button is courtesy to the operator,
     * and this is the boundary.
     */
    private function authorizeCredentialManagement(): void
    {
        Gate::authorize('manageMasarCredential', $this->representative());
    }

    private function canManage(): bool
    {
        return Gate::allows('manageMasarCredential', $this->representative());
    }

    /**
     * Whether a mutating action may be offered at all.
     *
     * Three conditions, and each is enforced again deeper down: authorization by
     * the action itself, the activity rule by MasarCredentialClient, and the
     * requirement for an authoritative state by there being no state to branch on.
     * The last is why an unreachable Masar closes the buttons — acting on a state
     * nobody could read is how a working courier's password gets replaced by
     * accident.
     */
    private function canMutate(): bool
    {
        return $this->canManage()
            && $this->representative()->is_active
            && $this->credentialStatus !== null;
    }

    private function needsFirstCredential(): bool
    {
        return $this->credentialStatus?->state === MasarCredentialState::None;
    }

    private function hasActiveCredential(): bool
    {
        return $this->credentialStatus?->state === MasarCredentialState::Active;
    }

    private function hasRetiredCredentialOnly(): bool
    {
        return $this->credentialStatus?->state === MasarCredentialState::Retired;
    }

    /**
     * Drops both memos — mine and Livewire's.
     *
     * Livewire caches a computed property for the whole request, so clearing only
     * the inner flag would leave a re-render after a mutation showing the state as
     * it was before it.
     */
    private function forgetStatus(): void
    {
        $this->statusResolved = false;
        $this->status = null;
        $this->statusFailure = null;

        unset($this->credentialStatus);
    }

    public function representative(): Representative
    {
        return $this->representative ??= Representative::query()->findOrFail($this->representativeId);
    }

    private function client(): MasarCredentialClient
    {
        return app(MasarCredentialClient::class);
    }
}
