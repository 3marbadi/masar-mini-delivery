<?php

namespace App\Policies;

use App\Models\Representative;
use App\Models\User;

/**
 * Who may act on a representative's Masar login (Masar CONTRACT §13.28.14).
 *
 * **One ability, and it is honest about how wide it currently is.** D29 says the
 * boundary must be described as it is and not as one would like it —
 * «ويُقال حدُّ التفويض عند شركة التوصيل كما هو بحقيقته: لا نظامَ أدوارٍ في أداتها
 * اليوم، فمن يَدخلها يَملك ما فيها» — so `manageMasarCredential()` returns true for
 * every authenticated panel user, because that is exactly the boundary this
 * application has. There is no role column, no permission table and no package;
 * inventing one here would be inventing an authorization story the deployment
 * cannot back.
 *
 * So why does the class exist at all? Because §13.28.14 asks for the decision to
 * have **one place** — «ويُفرَد للفعل تفويضٌ مسمًّى واحدٌ … ليكون للقرار موضعٌ
 * واحدٌ يُعرَف، فإن استُحدث نظامُ أدوارٍ يوماً ضاقَ الحدُّ في موضعٍ واحدٍ لا في
 * موضعين متفرّقين». Without it the rule would be spelled out twice over — once in
 * the button's visibility and once in the action's own guard — and the day a role
 * system arrives, narrowing it would mean finding both and agreeing with yourself.
 *
 * It is deliberately **not** read as a stronger boundary than exists. Anyone who
 * can sign into `/admin` can mint and reset courier logins today. That is a real
 * exposure, it is recorded in the contract as such, and closing it is a separate
 * decision about Mini Delivery's own authentication rather than part of this
 * feature.
 *
 * The ability is checked in two places and both are load-bearing: the section
 * hides the actions, and each action authorizes itself before touching Masar. The
 * first is courtesy to the operator; the second is the boundary. A hidden button
 * is not an authorization mechanism — a Livewire endpoint can be called directly.
 */
class RepresentativePolicy
{
    /**
     * May this user create or reset the courier's Masar credential?
     *
     * `$representative` is accepted and deliberately not consulted. The
     * representative's own state does constrain the operation — an inactive one
     * may not be mutated — but that is Mini's operational policy and not a
     * question about this user's authority, so it lives in
     * MasarCredentialClient where it applies to every caller rather than only to
     * panel sessions (§13.28.15).
     */
    public function manageMasarCredential(User $user, Representative $representative): bool
    {
        // Every authenticated panel user, which is the whole of the boundary
        // today. Narrow this method, and only this method, when roles arrive.
        return true;
    }
}
