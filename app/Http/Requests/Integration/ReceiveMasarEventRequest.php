<?php

namespace App\Http\Requests\Integration;

use App\Enums\DeliveryStatus;
use App\Enums\OrderResultReason;
use App\Services\Integration\LocationChangeStamp;
use App\Services\Integration\MasarDataEnvelope;
use App\Services\Integration\MasarLocationEnvelope;
use App\Services\Integration\MasarNoteEnvelope;
use App\Services\Integration\MasarStatusEnvelope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * The shape of an announcement from Masar (CONTRACT §3.21.3, §3.21.5, §13.8.1).
 *
 * Everything here is structural: the envelope, the closed vocabularies, and the
 * pairings each event type binds. Whether the order exists, and whether this
 * event has been seen before, are decided against the database inside the
 * processor — putting the order into an `exists:` rule would answer "unknown
 * order" as a `422` where the contract gives it a `404` with its own code.
 *
 * **Four event types now, and the body is read by type rather than by union.**
 * §13.8.1, §13.16.1 and §13.17.1 each fix a `data` block separately and forbid
 * one carrying another's fields, so validating against the sum of all four would
 * accept a status event with a `changed_fields` in it, or a note event carrying
 * a location — envelopes no contract describes. The common envelope is checked
 * once and each type's `data` block is then checked against its own rules and
 * nothing else.
 *
 * The status reason pairing is checked rather than trusted, because §3.21.5
 * binds both ends: «القسمةُ ملزمةٌ في الطرفين لا في المرسِل وحده». A
 * postponement reason sent with a return is refused, and so is the reverse — the
 * receiver does not silently store a pairing its own contract calls impossible.
 *
 * `status_reason`'s key is required and its value may be null, the same
 * distinction Masar's own result endpoint draws for `result`: a payload that
 * omitted the field and one that said "no reason" are different statements, and
 * the difference is not left to inference. The data channel draws it too, for
 * `order.recipient_alternate_phone` alone (§13.14.1): that path may be sent as
 * null to clear the number, and no other may.
 */
class ReceiveMasarEventRequest extends FormRequest
{
    /**
     * `delivery_orders.value` is DECIMAL(12, 2): ten integer digits and two
     * decimals — the same domain as Masar's `orders.order_amount`.
     *
     * The two widths are equal on purpose (§13.8.3). While they differed, an
     * amount Masar accepted, committed and versioned could be refused here for
     * ever, and §13.7 point 8 means the courier would never learn of it: the two
     * systems would diverge silently on an order nobody was told about. So this
     * ceiling is not a policy of this system's own — it is the sender's domain,
     * restated, and it moves only when that one does.
     */
    private const AMOUNT_MAX = 9999999999.99;

    private const TEXT_MAX = 255;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The envelope both types share (§3.21.3, §13.8.1). `contract_version`
        // is one literal because both channels are at 1.0 and neither has ever
        // moved; the day one does, this becomes a per-type rule like the rest.
        $envelope = [
            'contract_version' => ['required', Rule::in([MasarStatusEnvelope::CONTRACT_VERSION])],
            'event_id' => ['required', 'uuid'],
            'event_type' => ['required', 'string'],
            // The timestamp rule Masar applies to its own inbound events, and
            // for the same reason: an instant with no zone is not an instant.
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
            'data' => ['required', 'array'],
            // §3.21.4 — Mini Delivery's own id, as Masar was given it, on both
            // channels.
            'data.order_id' => ['required', 'string', 'max:128'],
        ];

        return $envelope + match ($this->input('event_type')) {
            MasarDataEnvelope::EVENT_TYPE => $this->dataRules(),
            MasarNoteEnvelope::EVENT_TYPE => $this->noteRules(),
            MasarLocationEnvelope::EVENT_TYPE => $this->locationRules(),
            default => $this->statusRules(),
        };
    }

    /**
     * §3.21.3, §3.21.11 — the status channel, unchanged.
     *
     * @return array<string, mixed>
     */
    private function statusRules(): array
    {
        return [
            // The ordering key. `integer` is doing real work here: a JSON number
            // beyond PHP's integer range decodes as a float and is refused, so
            // an oversized version becomes a validation error rather than an
            // overflow that wraps into a low number and is then ignored as
            // stale. The ceiling is stated for the same reason: the column is
            // BIGINT, and a value it cannot hold must be refused at the edge,
            // not truncated at the write.
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

    /**
     * §13.8.1 — the data channel.
     *
     * Three numbers and a map. `data_version` is Masar's own sequence over this
     * order's corrections and is the ordering key; `base_order_version` is the
     * version of *our* outbound sequence the correction was built on and is a
     * precondition, not an ordering key (§13.8.4). It is `present` and nullable
     * rather than required, because Masar sends null for an order it holds
     * without a mapping — a state the processor answers rather than one the
     * edge refuses.
     *
     * @return array<string, mixed>
     */
    private function dataRules(): array
    {
        return [
            'data.data_version' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'data.base_order_version' => ['present', 'nullable', 'integer', 'min:0', 'max:9223372036854775807'],
            // At least one path, or the event says nothing. Masar does not send
            // an empty correction — it writes nothing when nothing moved — so
            // one arriving here is a malformed body rather than a no-op.
            'data.changed_fields' => ['required', 'array', 'min:1'],
        ];
    }

    /**
     * §13.16.1 — the note channel.
     *
     * Four fields and no version, and the absence is the contract rather than an
     * omission (§13.16.2). `note_id` is the identity: `integer` and `min:1`
     * because it is a key at the source, and the ceiling is stated for the same
     * reason the version ceilings are — the column is BIGINT and a value it
     * cannot hold must be refused at the edge rather than truncated at the write.
     *
     * `content` is refused when blank, matching §13.5 at the source: a note is
     * never written empty there, so an empty one arriving here is a transport or
     * contract fault rather than data. The store refuses it too, by CHECK.
     *
     * @return array<string, mixed>
     */
    private function noteRules(): array
    {
        return [
            'data.note_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            // No `max` on the text: §13.5 puts no ceiling on a note's length and
            // the column is TEXT. Refusing at an invented limit would drop a
            // courier's words for a rule nobody agreed to.
            'data.content' => ['required', 'string'],
            'data.representative_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'data.created_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
        ];
    }

    /**
     * §13.17.1 — the location channel.
     *
     * `location_version` is Masar's own sequence, used here for replay and
     * duplicate detection within its stream — not for deciding which side holds
     * the newer location. That is `location_changed_at`, the instant the change
     * committed at its origin (§13.17.5, D13).
     *
     * There is no `base_order_version`. It was this channel's arbiter while the
     * rule was conflict detection, and D13 replaced it: a precondition able to
     * refuse a genuinely newer change — because *our* unrelated sequence had
     * moved — is not last-write-wins. The data channel keeps its own, unchanged.
     *
     * The coordinates are validated as **strings**, not numbers, and that is
     * §13.17.1 rather than a preference. Both ends store DECIMAL(10,7), and a
     * `numeric` rule would let a JSON float through to a column that then rounds
     * it — reintroducing exactly the representation error the string form exists
     * to avoid. The regex fixes the shape: an optional sign, digits, a point,
     * and seven places, which is what Masar normalises to before sending.
     *
     * The ranges are then checked in `withValidator`, on the parsed value, so a
     * well-formed but impossible coordinate is refused too.
     *
     * @return array<string, mixed>
     */
    private function locationRules(): array
    {
        return [
            'data.location_version' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            // §13.17.5, D13 — the arbiter. Required, because without it there is
            // no way to tell a newer change from an older one and the receiver
            // would be reduced to trusting arrival order.
            'data.location_changed_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
            // Always `masar` on this channel — Masar is its only sender — but
            // carried and checked rather than assumed, because it is a term of
            // the tie-break and a receiver that filled it in itself would be one
            // refactor from filling it in wrongly.
            'data.location_change_source' => ['required', Rule::in([LocationChangeStamp::SOURCE_MASAR])],
            'data.latitude' => ['required', 'string', 'regex:/^-?\d{1,3}\.\d{7}$/'],
            'data.longitude' => ['required', 'string', 'regex:/^-?\d{1,3}\.\d{7}$/'],
            'data.location_completed_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // §3.21.3, §13.8.1 — two types in this contract version, and an
            // unknown one gets its own code so Masar can tell "you sent
            // nonsense" from "you sent something I have not learned yet".
            if (! $this->isKnownEventType()) {
                $validator->errors()->add('event_type', 'Unsupported integration event type.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            match ($this->input('event_type')) {
                MasarDataEnvelope::EVENT_TYPE => $this->validateChangedFields($validator),
                MasarNoteEnvelope::EVENT_TYPE => $this->validateNoteContent($validator),
                MasarLocationEnvelope::EVENT_TYPE => $this->validateCoordinateRanges($validator),
                default => $this->validateStatusPairing($validator),
            };
        });
    }

    private function isKnownEventType(): bool
    {
        return in_array(
            $this->input('event_type'),
            [
                MasarStatusEnvelope::EVENT_TYPE,
                MasarDataEnvelope::EVENT_TYPE,
                MasarNoteEnvelope::EVENT_TYPE,
                MasarLocationEnvelope::EVENT_TYPE,
            ],
            true,
        );
    }

    /**
     * §13.5, §13.16.1 — a note is never blank at the source.
     *
     * Checked after the `string` rule has passed, and separately from it,
     * because `required` alone lets `"   "` through: a string of spaces is
     * present and non-empty by PHP's reckoning and is still not a note.
     */
    private function validateNoteContent(Validator $validator): void
    {
        $content = $this->input('data.content');

        if (is_string($content) && trim($content) === '') {
            $validator->errors()->add('data.content', 'A note cannot be blank.');
        }
    }

    /**
     * §3.13, §13.17.1 — the two ranges, on the parsed value.
     *
     * The regex in the rules fixes the *shape*; this fixes the *value*. Both are
     * needed: `100.0000000` is a well-formed seven-place decimal and is not a
     * latitude, and half a location is not a location — but the pairing is
     * already guaranteed here, because both fields are `required`.
     */
    private function validateCoordinateRanges(Validator $validator): void
    {
        foreach ([['data.latitude', 90.0], ['data.longitude', 180.0]] as [$key, $limit]) {
            $value = $this->input($key);

            if (! is_string($value) || ! preg_match('/^-?\d{1,3}\.\d{7}$/', $value)) {
                // The rule above already reported this; saying it twice would
                // put two messages on one field for one fault.
                continue;
            }

            if (abs((float) $value) > $limit) {
                $validator->errors()->add($key, "This coordinate is outside the range -{$limit} to {$limit}.");
            }
        }
    }

    /**
     * §3.21.5 — the reason belongs to the result, on both ends.
     */
    private function validateStatusPairing(Validator $validator): void
    {
        $status = DeliveryStatus::from((string) $this->input('data.delivery_status'));
        $reason = $this->input('data.status_reason');

        // The two states that carry no reason. `with_rep` joins `delivered`
        // here: a state with no result has nothing to explain, and a clear that
        // arrived with a reason would be Masar contradicting its own contract
        // rather than something to store and puzzle over.
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
    }

    /**
     * §13.8.3 — the closed vocabulary, and what each path may carry.
     *
     * Checked here rather than as dotted rule keys because the keys themselves
     * contain dots, and `data.changed_fields.order\.amount` would work and would
     * be unreadable.
     */
    private function validateChangedFields(Validator $validator): void
    {
        $fields = $this->input('data.changed_fields');

        if (! is_array($fields)) {
            return;
        }

        foreach ($fields as $path => $value) {
            if (! is_string($path) || ! array_key_exists($path, MasarDataEnvelope::PATHS)) {
                $validator->errors()->add(
                    'data.changed_fields',
                    'Unknown correction path: '.(is_string($path) ? $path : '?').'.',
                );

                continue;
            }

            $this->validatePathValue($validator, $path, $value);
        }
    }

    private function validatePathValue(Validator $validator, string $path, mixed $value): void
    {
        $key = 'data.changed_fields.'.$path;

        match ($path) {
            // The one clearable path (§13.14.1): null means "this recipient has
            // no second number", which is a correction like any other.
            'order.recipient_alternate_phone' => $this->text($validator, $key, $value, nullable: true),
            'order.recipient_name', 'order.recipient_phone' => $this->text($validator, $key, $value, nullable: false),
            'order.amount' => $this->amount($validator, $key, $value),
            'order.delivery_payer' => $this->payer($validator, $key, $value),
            default => $validator->errors()->add($key, 'Unknown correction path.'),
        };
    }

    private function text(Validator $validator, string $key, mixed $value, bool $nullable): void
    {
        if ($value === null) {
            if (! $nullable) {
                $validator->errors()->add($key, 'This field cannot be cleared.');
            }

            return;
        }

        if (! is_string($value)) {
            $validator->errors()->add($key, 'This field must be a string.');

            return;
        }

        if (trim($value) === '') {
            $validator->errors()->add($key, 'This field cannot be blank.');

            return;
        }

        if (mb_strlen($value) > self::TEXT_MAX) {
            $validator->errors()->add($key, 'This field is longer than the column allows.');
        }
    }

    /**
     * The amount, refused at the edge rather than truncated at the write.
     *
     * The ceiling is now the sender's own, so nothing Masar accepts is refused
     * here for width alone. What is still refused is what neither system can
     * represent — a value past DECIMAL(12, 2), or a third decimal place — and it
     * is refused at the edge rather than rounded at the write, so Masar sees a
     * judgement instead of this system quietly storing a different number.
     *
     * The comparison casts once, to test the magnitude, and the value written is
     * the submitted string: the column is decimal and the round trip must not go
     * through a binary float.
     */
    private function amount(Validator $validator, string $key, mixed $value): void
    {
        if (! is_numeric($value) || is_bool($value)) {
            $validator->errors()->add($key, 'The amount must be a number.');

            return;
        }

        $amount = (float) $value;

        if ($amount < 0) {
            $validator->errors()->add($key, 'The amount cannot be negative.');

            return;
        }

        if ($amount > self::AMOUNT_MAX) {
            $validator->errors()->add($key, 'The amount is larger than this system can hold.');

            return;
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', (string) $value) !== 1) {
            $validator->errors()->add($key, 'The amount carries at most two decimal places.');
        }
    }

    private function payer(Validator $validator, string $key, mixed $value): void
    {
        if (! is_string($value) || ! in_array($value, ['sender', 'recipient'], true)) {
            $validator->errors()->add($key, 'The delivery payer is either "sender" or "recipient".');
        }
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
        $unknownType = is_string($this->input('event_type')) && ! $this->isKnownEventType();

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
