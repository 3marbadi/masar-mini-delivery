<?php

namespace App\Http\Requests\Integration;

use App\Enums\DeliveryStatus;
use App\Enums\OrderResultReason;
use App\Services\Integration\MasarStatusEnvelope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * The shape of an announcement from Masar (CONTRACT §3.21.3, §3.21.5).
 *
 * Everything here is structural: the envelope, the closed vocabulary, and the
 * reason pairing. Whether the order exists, and whether this event has been seen
 * before, are decided against the database inside the processor — putting the
 * order into an `exists:` rule would answer "unknown order" as a `422` where the
 * contract gives it a `404` with its own code.
 *
 * The reason pairing is checked here rather than trusted, because §3.21.5 binds
 * both ends: «القسمةُ ملزمةٌ في الطرفين لا في المرسِل وحده». A postponement
 * reason sent with a return is refused, and so is the reverse — the receiver does
 * not silently store a pairing its own contract calls impossible.
 *
 * `status_reason`'s key is required and its value may be null, the same
 * distinction Masar's own result endpoint draws for `result`: a payload that
 * omitted the field and one that said "no reason" are different statements, and
 * the difference is not left to inference.
 */
class ReceiveMasarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', Rule::in([MasarStatusEnvelope::CONTRACT_VERSION])],
            'event_id' => ['required', 'uuid'],
            'event_type' => ['required', 'string'],
            // The timestamp rule Masar applies to its own inbound events, and
            // for the same reason: an instant with no zone is not an instant.
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
            'data' => ['required', 'array'],
            'data.order_id' => ['required', 'string', 'max:128'],
            // §3.21.11 — the ordering key. `integer` is doing real work here:
            // a JSON number beyond PHP's integer range decodes as a float and
            // is refused, so an oversized version becomes a validation error
            // rather than an overflow that wraps into a low number and is then
            // ignored as stale. The ceiling is stated for the same reason: the
            // column is BIGINT, and a value it cannot hold must be refused at
            // the edge, not truncated at the write.
            'data.status_version' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'data.delivery_status' => ['required', Rule::in([
                // §3.21.3, widened in v4.9 — `with_rep` is how Masar announces
                // a cleared result. It arrives here and is never sent from
                // here: the inbound direction's ownership is unchanged.
                DeliveryStatus::WithRepresentative->value,
                DeliveryStatus::Delivered->value,
                DeliveryStatus::Postponed->value,
                DeliveryStatus::Returned->value,
            ])],
            // Present always, null sometimes (§3.21.3).
            'data.status_reason' => ['present', 'nullable', 'string', Rule::in(OrderResultReason::codes())],
            'data.result_occurred_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // §3.21.3 — one type in this contract version, and an unknown one
            // gets its own code so Masar can tell "you sent nonsense" from "you
            // sent something I have not learned yet".
            if ($this->input('event_type') !== MasarStatusEnvelope::EVENT_TYPE) {
                $validator->errors()->add('event_type', 'Unsupported integration event type.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $status = DeliveryStatus::from((string) $this->input('data.delivery_status'));
            $reason = $this->input('data.status_reason');

            // §3.21.5 — the two states that carry no reason. `with_rep` joins
            // `delivered` here: a state with no result has nothing to explain,
            // and a clear that arrived with a reason would be Masar contradicting
            // its own contract rather than something to store and puzzle over.
            if ($status === DeliveryStatus::Delivered || $status === DeliveryStatus::WithRepresentative) {
                if ($reason !== null) {
                    $validator->errors()->add(
                        'data.status_reason',
                        $status === DeliveryStatus::Delivered
                            ? 'A delivered result carries no reason.'
                            : 'A cleared result carries no reason.',
                    );
                }

                return;
            }

            if ($reason === null) {
                $validator->errors()->add('data.status_reason', 'This result requires a reason.');

                return;
            }

            if (! in_array($reason, OrderResultReason::codesFor($status), true)) {
                $validator->errors()->add('data.status_reason', 'This reason does not belong to this result.');
            }
        });
    }

    /**
     * §3.21.7 — the two refusal codes of a malformed body, kept apart.
     *
     * Nothing is written here. Masar's own inbound endpoint audits its rejected
     * payloads, but that channel needs to because the delivery company's version
     * sequence must not develop a gap. This one has no sequence: `event_id` is
     * the only identity, a rejected event is one Masar will fix and resend, and
     * recording every malformed body would be an audit trail of the sender's
     * bugs rather than of this system's state.
     */
    protected function failedValidation(Validator $validator): void
    {
        $unknownType = is_string($this->input('event_type'))
            && $this->input('event_type') !== MasarStatusEnvelope::EVENT_TYPE;

        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => [
                'code' => $unknownType ? 'UNKNOWN_EVENT_TYPE' : 'VALIDATION_ERROR',
                'message' => $unknownType
                    ? 'Unknown integration event type.'
                    : 'Invalid integration payload.',
                'fields' => $validator->errors()->toArray(),
            ],
            'request_id' => $this->attributes->get('request_id'),
        ], 422));
    }
}
