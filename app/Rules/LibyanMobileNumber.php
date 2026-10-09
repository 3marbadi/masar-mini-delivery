<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Libyan mobile number, as the project owner fixed it: ten digits beginning
 * `091`, `092`, `093` or `094`.
 *
 * ```
 * ^09[1-4][0-9]{7}$
 * ```
 *
 * **Where this rule belongs, and where it emphatically does not.** It guards
 * *human entry* — the customer form, the representative form, and any local
 * path where a person or this company's own code sets a number. It is **not**
 * applied to the integration channel that receives Masar's corrections, and
 * that exclusion is a decision with evidence behind it: a number Masar accepts,
 * commits and versions must not be refused here, or the courier would never
 * learn of it and the two systems would diverge silently on an order nobody was
 * told about. That is exactly the failure the amount-ceiling fix closed by
 * widening this side to the *sender's* domain rather than imposing the
 * receiver's, and the same reasoning applies to a format.
 *
 * The other half of that bargain is now in place on Masar's side: the courier's
 * own edit boundary (`EditOrderDataRequest`) applies this same pattern, so a
 * malformed number is refused where a person types it rather than where it
 * arrives. Masar's *channels* stay format-free by the same reasoning in mirror
 * image — they carry this company's data, whose domain is this company's to
 * define. Neither side validates the other's values; each validates its own
 * keyboards.
 *
 * **The historical exemption, and its single purpose.** Rows written before this
 * rule existed may hold anything. An operator editing a customer's *name* must
 * not be blocked by a number they did not touch, so a value identical to the one
 * already stored passes. The exemption is therefore about *not changing* a
 * value, never about writing one: any different value — including another
 * invalid one — is judged on its merits, and a brand new record gets no
 * exemption at all, because it has no stored value to be unchanged from.
 *
 * Nothing is normalised. `+218…` is refused rather than rewritten to `0…`:
 * `customers.phone` is UNIQUE, so a silent rewrite could collide two existing
 * customers, and whether that can happen is a question for the data audit and
 * the project owner, not for a validation rule.
 */
class LibyanMobileNumber implements ValidationRule
{
    public const PATTERN = '/^09[1-4][0-9]{7}$/';

    /**
     * @param  string|null  $storedValue  the value already on the record, when editing
     *                                    one. A submission equal to it is left alone;
     *                                    anything else is validated. Null for a new
     *                                    record, which is what denies it the exemption.
     */
    public function __construct(private readonly ?string $storedValue = null) {}

    /** The rule as it applies to a record being created. */
    public static function forNewRecord(): self
    {
        return new self();
    }

    /**
     * The rule as it applies to an existing record, exempting only an untouched
     * value.
     */
    public static function forStoredValue(?string $storedValue): self
    {
        return new self($storedValue);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // A nullable field left empty is not a malformed number. The forms
        // decide whether a number is required; this rule decides whether one is
        // well formed, and conflating the two would quietly make the
        // representative's optional phone mandatory.
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('رقم الهاتف يجب أن يكون نصاً من عشرة أرقام.');

            return;
        }

        // Unchanged from what is stored: the historical value stands. Compared
        // strictly, so "0912345678" and " 0912345678" are different values and
        // the second is a change that must pass the pattern.
        if ($this->storedValue !== null && $value === $this->storedValue) {
            return;
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            $fail('رقم الهاتف يجب أن يتكون من عشرة أرقام ويبدأ بأحد المفاتيح 091 أو 092 أو 093 أو 094.');
        }
    }

    /** Whether a value would satisfy the rule outright, ignoring any exemption. */
    public static function matches(?string $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
