{{--
    The «حساب مسار» card (Masar CONTRACT §13.28.16).

    Five states and no sixth, matching the lexicon Masar reports: no courier yet, a
    courier without a login, a live login, history with nothing live in it, and Masar
    unreachable. The last one closes the mutating actions rather than offering them
    against a state nobody could read.

    **Every class here is either a Filament component's own or one of this feature's
    `masar-credential-*` rules** from `resources/css/masar-credential.css`, which the
    panel loads through Filament's asset registry. No Tailwind utility is used,
    because the panel's compiled stylesheet contains none of them — a utility class
    written here would be a no-op and the card would render as unstyled text.

    Nothing renders an existing password — there is none to render, Masar keeps only a
    hash — and nothing renders `integration_uid`, Masar's internal representative id,
    a credential id or the integration client id. The operator needs a username, a
    state and a date.
--}}
@php
    // One read for the whole render: a Livewire computed property, memoised per
    // request and absent from the snapshot.
    $status = $this->credentialStatus;
    $representative = $this->representative();
@endphp

<x-filament::section>
    <x-slot name="heading">
        حساب مسار
    </x-slot>

    <x-slot name="headerEnd">
        {{ $this->refreshMasarCredentialAction }}
    </x-slot>

    <div wire:key="masar-credential-{{ $representative->getKey() }}-{{ $this->readToken }}">
        @if ($status === null)
            {{-- State E — contained here, so the representative record still renders. --}}
            <div class="masar-credential-unavailable" data-masar-state="unavailable">
                <p class="masar-credential-unavailable-heading">
                    تعذّر الوصول إلى مسار. لا يمكن عرض حالة حساب الدخول الآن.
                </p>

                <p class="masar-credential-unavailable-detail">
                    استخدم «تحديث» بعد قليل. ولا تُنشأ أو تُعاد بيانات الدخول قبل أن تظهر الحالة.
                </p>
            </div>
        @else
            <dl class="masar-credential-grid" data-masar-state="{{ $status->state->value }}">
                @if ($status->state === \App\Enums\MasarCredentialState::Active)
                    <div class="masar-credential-field">
                        <dt class="masar-credential-label">اسم المستخدم</dt>
                        <dd class="masar-credential-value" data-masar-login-name>
                            {{ $status->loginName }}
                        </dd>
                    </div>

                    <div class="masar-credential-field">
                        <dt class="masar-credential-label">الحالة</dt>
                        <dd class="masar-credential-value masar-credential-state masar-credential-state--active">
                            نشط
                        </dd>
                    </div>
                @elseif ($status->state === \App\Enums\MasarCredentialState::Retired)
                    <div class="masar-credential-field">
                        <dt class="masar-credential-label">اسم المستخدم السابق</dt>
                        <dd class="masar-credential-value" data-masar-login-name>
                            {{ $status->loginName }}
                        </dd>
                    </div>

                    <div class="masar-credential-field">
                        <dt class="masar-credential-label">الحالة</dt>
                        <dd class="masar-credential-value masar-credential-state masar-credential-state--retired">
                            لا توجد بيانات دخول فعّالة
                        </dd>
                    </div>
                @else
                    <div class="masar-credential-field masar-credential-field--wide">
                        <dt class="masar-credential-label">الحالة</dt>
                        <dd class="masar-credential-value masar-credential-state masar-credential-state--none">
                            @if ($status->isUnmapped())
                                لم يتم إنشاء حساب في مسار
                            @else
                                لا توجد بيانات دخول
                            @endif
                        </dd>
                    </div>
                @endif

                @if ($status->credentialsUpdatedAt !== null)
                    <div class="masar-credential-field">
                        <dt class="masar-credential-label">آخر تغيير</dt>
                        <dd class="masar-credential-value">
                            {{-- The panel's own display convention; the server value is not mutated. --}}
                            {{ $status->credentialsUpdatedAt->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                        </dd>
                    </div>
                @endif
            </dl>

            @unless ($representative->is_active)
                {{--
                    Mini's own rule, and the wording matters: Mini's activity flag is
                    not propagated to Masar (§13.28.15), so this must not be read as
                    "the Masar account is disabled" — it is not.
                --}}
                <p class="masar-credential-note" data-masar-inactive-note>
                    هذا المندوب غير نشط في شركة التوصيل، لذلك لا يمكن إنشاء أو إعادة تعيين بيانات دخول مسار.
                </p>
            @endunless
        @endif

        <div class="masar-credential-actions">
            {{ $this->createMasarCredentialAction }}
            {{ $this->resetMasarCredentialAction }}
            {{ $this->reissueMasarCredentialAction }}
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament::section>
