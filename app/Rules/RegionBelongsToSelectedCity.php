<?php

namespace App\Rules;

use App\Models\DeliveryRegion;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A region that belongs to the city chosen beside it.
 *
 * **This is the message, not the guarantee.** The guarantee is in
 * `DeliveryDestinationService`, which runs from the model's `saving` hook and
 * therefore on every path that stores an order; this rule exists so that an
 * operator who picks a mismatched pair sees a sentence under the field instead
 * of an exception page. The two are deliberately redundant, and the redundancy
 * is the point: deleting this rule costs a good error message, while deleting
 * the service's check costs the invariant.
 *
 * It is written against the *submitted* city rather than the stored one. On a
 * create there is no stored city at all, and on an edit the operator may be
 * changing both halves in one submission — judging the new region against the
 * old city would reject a perfectly coherent change.
 *
 * Identity is the internal primary key. Never `source_region_id`, which is the
 * catalog's own identifier and belongs to the integration contract, and never
 * `region_code`, which 60 regions share.
 */
class RegionBelongsToSelectedCity implements ValidationRule
{
    /**
     * @param  int|string|null  $cityId  the city submitted with this region, as form state
     *                                   gives it — a string from a Select, or null when the
     *                                   city was left empty.
     */
    public function __construct(private readonly int|string|null $cityId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            // Whether a region is *required* is a different question, answered by
            // the city's own `is_region_required`. An absent value is not a
            // mismatched one.
            return;
        }

        if ($this->cityId === null || $this->cityId === '') {
            $fail('اختر المدينة أولًا، ثم اختر المنطقة التابعة لها.');

            return;
        }

        $region = DeliveryRegion::query()->find((int) $value);

        if ($region === null) {
            $fail('المنطقة المختارة غير موجودة.');

            return;
        }

        if (! $region->belongsToCity((int) $this->cityId)) {
            $fail('المنطقة المختارة لا تتبع المدينة المحددة.');
        }
    }
}
