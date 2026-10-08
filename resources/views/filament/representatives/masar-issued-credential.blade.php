{{--
    The one-time credential (Masar CONTRACT §13.28.10).

    This body is the only place the plaintext password is ever rendered, and it is
    rendered in the same response that received it from Masar. It is not in the
    Livewire snapshot, not in a session, not in a notification, not in a store —
    MasarCredentialSection's property holding it is protected, so it ceases to exist
    when this request ends.

    `$credential` is therefore null on any later re-render of this mounted action, and
    that case is not an error to hide: it is the guarantee working, and the operator is
    told plainly that the only way forward is a deliberate reset.

    The copy buttons act on the explicit click and keep nothing: no local storage, no
    session storage, no "remember", and nothing is copied automatically.

    Styling comes from `resources/css/masar-credential.css` through Filament's asset
    registry, plus Filament's own button component. No Tailwind utility is used — the
    panel's compiled stylesheet ships none, so a utility class here would be a no-op
    and the warning below would read as ordinary body text, which is the one thing it
    must not do.
--}}
@if ($credential === null)
    <div class="masar-credential-expired" data-masar-credential="expired">
        <p class="masar-credential-expired-heading">
            لم تعد كلمة المرور متاحة للعرض.
        </p>

        <p class="masar-credential-expired-detail">
            تُعرض كلمة المرور مرّة واحدة فقط في اللحظة التي تُنشأ فيها. إن لم تكن قد حفظتها
            فأعد تعيين كلمة المرور من جديد.
        </p>
    </div>
@else
    <div class="masar-credential-issued" data-masar-credential="issued" x-data>
        <div>
            <p class="masar-credential-secret-label">اسم المستخدم</p>

            <div class="masar-credential-secret-row">
                <code class="masar-credential-secret-value" data-masar-issued-login-name>{{ $credential->loginName }}</code>

                <x-filament::button
                    size="sm"
                    color="gray"
                    icon="heroicon-m-clipboard"
                    x-on:click="navigator.clipboard.writeText(@js($credential->loginName))"
                >
                    نسخ اسم المستخدم
                </x-filament::button>
            </div>
        </div>

        <div>
            <p class="masar-credential-secret-label">كلمة المرور المؤقتة</p>

            <div class="masar-credential-secret-row">
                <code
                    class="masar-credential-secret-value masar-credential-secret-value--password"
                    data-masar-issued-password
                >{{ $credential->password }}</code>

                <x-filament::button
                    size="sm"
                    color="gray"
                    icon="heroicon-m-clipboard"
                    x-on:click="navigator.clipboard.writeText(@js($credential->password))"
                >
                    نسخ كلمة المرور
                </x-filament::button>
            </div>
        </div>

        {{--
            Stated at full strength, because the operator's next click is what ends the
            password's existence. Border, tint, icon, bold heading and spacing together
            — colour is never the only signal (WCAG 1.4.1) — and the wording never
            promises a later view: the only remedies are copying it now or a deliberate
            reset (§13.28.10).
        --}}
        <div class="masar-credential-warning" role="alert" data-masar-credential-warning>
            <x-filament::icon
                icon="heroicon-m-exclamation-triangle"
                class="masar-credential-warning-icon"
            />

            <div>
                <p class="masar-credential-warning-heading">تُعرض كلمة المرور هذه مرّة واحدة فقط.</p>

                <p class="masar-credential-warning-detail">
                    انسخها الآن وسلّمها للمندوب بوسيلة آمنة. بعد إغلاق هذه النافذة لا يمكن استرجاعها،
                    ويمكن فقط إعادة تعيينها.
                </p>
            </div>
        </div>
    </div>
@endif
